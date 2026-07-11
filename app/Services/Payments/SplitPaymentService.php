<?php

namespace App\Services\Payments;

use App\Enums\PosPaymentMethod;
use App\Exceptions\CashDrawerSessionNotOpenException;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Services\Cash\CashDrawerService;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SplitPaymentService — multi-tender persistence (F-SPLIT-PAYMENT-001).
 *
 * Ce service WRAPPE — ne MODIFIE PAS — `PaymentService`. Il est appelé depuis
 * `OrderService::posOrderStore` une fois la ligne `Order` créée et le total
 * recalculé côté serveur. Il :
 *
 *  - persiste 1..N rows `order_payments` dans la même transaction parente
 *    que la création de la commande (atomicité garantie par Laravel) ;
 *  - audit-logue chaque tranche (NF525 chain-hash) ;
 *  - revalide somme >= total serveur (defense in depth — `PosOrderRequest`
 *    valide déjà côté HTTP, mais le total SSOT peut avoir évolué pendant
 *    la transaction de création).
 *
 * Le refund partiel d'une tranche est HORS scope V1 (cycle 7 backlog).
 */
final class SplitPaymentService
{
    public const TOLERANCE_OVERPAY = 1.00; // 1€ de marge sur l'overpay

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly CashDrawerService $cashDrawerService,
    ) {
    }

    /**
     * Valide une liste de tranches sans persister.
     *
     * @param array<int, array<string, mixed>> $tranches
     * @throws ValidationException quand la somme < total serveur ou qu'une
     *                              tranche est malformée.
     */
    public function validateBreakdown(array $tranches, float $orderTotal, int $branchId): void
    {
        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'payment_breakdown' => 'branch_id requis pour la validation des tranches.',
            ]);
        }

        $maxTranches = (int) config('split_payment.max_tranches', 12);
        if (count($tranches) > $maxTranches) {
            throw ValidationException::withMessages([
                'payment_breakdown' => sprintf('Trop de tranches (max %d).', $maxTranches),
            ]);
        }

        $allowedModes = [
            PosPaymentMethod::CASH,
            PosPaymentMethod::CARD,
            PosPaymentMethod::MOBILE_BANKING,
            PosPaymentMethod::OTHER,
            PosPaymentMethod::TICKET_RESTAURANT,
        ];

        $totalCents = 0;
        $hasCashTranche = false;
        foreach ($tranches as $idx => $t) {
            if (! is_array($t)) {
                throw ValidationException::withMessages([
                    "payment_breakdown.{$idx}" => 'Tranche invalide.',
                ]);
            }

            $mode = (int) ($t['mode'] ?? 0);
            if (! in_array($mode, $allowedModes, true)) {
                throw ValidationException::withMessages([
                    "payment_breakdown.{$idx}.mode" => 'Mode de paiement non autorisé.',
                ]);
            }

            $amount = (float) ($t['amount'] ?? 0);
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    "payment_breakdown.{$idx}.amount" => 'Montant tranche requis (>0).',
                ]);
            }

            if ($mode === PosPaymentMethod::CASH) {
                $hasCashTranche = true;
                $tenderedRaw = $t['tendered'] ?? null;
                $tendered = $tenderedRaw !== null && $tenderedRaw !== '' ? (float) $tenderedRaw : null;
                if ($tendered === null || $tendered <= 0) {
                    throw ValidationException::withMessages([
                        "payment_breakdown.{$idx}.tendered" => 'Montant reçu requis pour la tranche cash.',
                    ]);
                }
                if ((int) round($tendered * 100) < (int) round($amount * 100)) {
                    throw ValidationException::withMessages([
                        "payment_breakdown.{$idx}.tendered" => 'Montant reçu inférieur au montant cash.',
                    ]);
                }
            }

            // [F-SPLIT-PHANTOM-CARD-001 2026-05-17] Defense-in-depth — CARD
            // tranches MUST carry a valid terminal_id scoped to the order's
            // branch + ACTIVE status. Mirrors PosOrderRequest withValidator;
            // catches non-HTTP callers (queue jobs, direct service use).
            // BranchScope is bypassed for the lookup, branch_id is checked
            // explicitly to prevent cross-branch terminal leakage.
            if ($mode === PosPaymentMethod::CARD) {
                $terminalIdRaw = $t['terminal_id'] ?? null;
                $terminalId = ($terminalIdRaw !== null && $terminalIdRaw !== '' && (int) $terminalIdRaw > 0)
                    ? (int) $terminalIdRaw
                    : 0;
                if ($terminalId <= 0) {
                    throw ValidationException::withMessages([
                        "payment_breakdown.{$idx}.terminal_id" => 'CARD tranche requires a valid terminal_id.',
                    ]);
                }
                // [Z6-P1-WGS 2026-05-19] singular form — PaymentTerminal has no
                // SoftDeletes; explicit BranchScope::class arg documents intent.
                $terminalOk = PaymentTerminal::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('id', $terminalId)
                    ->where('branch_id', $branchId)
                    ->where('status', PaymentTerminal::STATUS_ACTIVE)
                    ->exists();
                if (! $terminalOk) {
                    throw ValidationException::withMessages([
                        "payment_breakdown.{$idx}.terminal_id" => 'CARD terminal_id is not active on this branch.',
                    ]);
                }
            }

            $totalCents += (int) round($amount * 100);
        }

        $serverTotalCents = (int) round($orderTotal * 100);
        // [F-SPLIT-OVERPAY-CASH-ONLY 2026-07-11] La tolérance d'overpay ne
        // s'applique QUE si au moins une tranche est en ESPÈCES : c'est le
        // tiroir qui rend la monnaie. Sans tranche cash (carte seule, ou
        // carte+carte…), il n'y a AUCUN rendu possible — le montant de la
        // tranche EST débité tel quel → la somme doit égaler EXACTEMENT le
        // total (tolérance effective = 0), sinon surfacture ≤1€/vente et
        // SUM(order_payments)/Z dépassent le total encaissable.
        $toleranceCents = $hasCashTranche ? (int) round(self::TOLERANCE_OVERPAY * 100) : 0;

        if ($totalCents < $serverTotalCents) {
            throw ValidationException::withMessages([
                'payment_breakdown' => sprintf(
                    'Somme des tranches (%.2f €) < total (%.2f €).',
                    $totalCents / 100,
                    $serverTotalCents / 100,
                ),
            ]);
        }

        if ($totalCents > $serverTotalCents + $toleranceCents) {
            throw ValidationException::withMessages([
                'payment_breakdown' => sprintf(
                    'Somme des tranches (%.2f €) excède le total (%.2f €) de plus de %.2f €.',
                    $totalCents / 100,
                    $serverTotalCents / 100,
                    $toleranceCents / 100,
                ),
            ]);
        }
    }

    /**
     * Persiste les tranches dans `order_payments` et écrit un audit-log
     * (action `order.payment_tranche_persisted`) par tranche.
     *
     * Si le feature flag est désactivé, retourne une collection vide
     * (no-op silencieux — le path legacy single-tender reste actif).
     *
     * @param array<int, array<string, mixed>> $tranches
     * @throws ValidationException
     */
    public function persistTranches(Order $order, array $tranches): EloquentCollection
    {
        if (! config('split_payment.enabled', false)) {
            return new EloquentCollection();
        }

        if (empty($tranches)) {
            return new EloquentCollection();
        }

        $this->validateBreakdown($tranches, (float) $order->total, (int) $order->branch_id);

        // [Sprint 1B 2026-05-16] NF525 cash trail — fail-fast guard si au
        // moins une tranche est CASH. Une CashDrawerSession OPEN doit exister
        // pour le caissier sur la branche de l'order, sinon on bloque la
        // vente (transaction parente rollback). Cohérent avec le path single-
        // tender dans OrderService::posOrderStore.
        $hasCashTranche = false;
        foreach ($tranches as $t) {
            if (is_array($t) && (int) ($t['mode'] ?? 0) === PosPaymentMethod::CASH) {
                $hasCashTranche = true;
                break;
            }
        }

        $cashSession = null;
        // [2026-05-18] Hardware simulation: when no physical drawer is wired,
        // CASH tranches are still recorded (OrderPayment row + audit log) but
        // no cash_movement is written (handled below via $cashSession===null).
        $simulating = config('pos.simulation_hardware') === true;
        if ($hasCashTranche && ! $simulating) {
            if (! Auth::check()) {
                throw new CashDrawerSessionNotOpenException();
            }
            $cashSession = $this->cashDrawerService->findOpenSessionForUser(
                (int) $order->branch_id,
                (int) Auth::id(),
            );
            if (! $cashSession) {
                throw new CashDrawerSessionNotOpenException();
            }
        }

        return DB::transaction(function () use ($order, $tranches, $cashSession): EloquentCollection {
            $persisted = new EloquentCollection();

            foreach ($tranches as $idx => $t) {
                $mode = (int) $t['mode'];
                $amount = (float) $t['amount'];
                $tenderedRaw = $t['tendered'] ?? null;
                $tendered = $tenderedRaw !== null && $tenderedRaw !== '' ? (float) $tenderedRaw : null;
                // [CAISSE-LOGIC-HEAL 2026-07-11 F2] Le rendu est RECALCULÉ côté serveur
                // (tendered − amount, clampé ≥ 0), JAMAIS pris du client : un change_amount
                // forgé (ex 99 € pour un rendu réel de 1,50 €) était auparavant persisté tel
                // quel puis affiché sur le reçu écran (OrderDetailsResource). Le tiroir et le
                // fiscal s'appuient sur `amount` (déjà corrects) ; seul l'affichage du rendu
                // dérivait. tendered ≥ amount est déjà garanti (validateBreakdown l.104).
                $change = ($tendered !== null && $tendered > $amount)
                    ? round($tendered - $amount, 2)
                    : 0.0;
                $reference = $t['reference'] ?? $t['note'] ?? null;
                if (is_string($reference)) {
                    $reference = substr($reference, 0, 64);
                }

                // [Sprint H2 P1-Z7-01 2026-05-17] Forward terminal_id from tranche
                // payload so the Z-report TPE breakdown (Sprint 1C) gets per-terminal
                // aggregation. Pre-fix the column was always NULL → ZReportCashEnrichment
                // ::aggregateByTerminal returned a single "Sans TPE" bucket with
                // fees_total=0. Nullable for legacy callers / UI Stage B not yet shipped.
                $terminalIdRaw = $t['terminal_id'] ?? null;
                $terminalId = ($terminalIdRaw !== null && $terminalIdRaw !== '' && (int) $terminalIdRaw > 0)
                    ? (int) $terminalIdRaw
                    : null;

                $row = OrderPayment::create([
                    'order_id'      => (int) $order->id,
                    'branch_id'     => (int) $order->branch_id,
                    'mode'          => $mode,
                    'terminal_id'   => $terminalId,
                    'amount'        => $amount,
                    'tendered'      => $tendered,
                    'change_amount' => $change,
                    'reference'     => $reference,
                    'paid_at'       => now(),
                ]);

                $this->auditLog->write([
                    'branch_id'   => (int) $order->branch_id,
                    'user_id'     => Auth::check() ? (int) Auth::id() : null,
                    'action'      => 'order.payment_tranche_persisted',
                    'resource'    => 'order_payment',
                    'resource_id' => (int) $row->id,
                    'payload'     => [
                        'order_id'      => (int) $order->id,
                        'tranche_index' => (int) $idx,
                        'mode'          => $mode,
                        'amount'        => round($amount, 2),
                        'tendered'      => $tendered !== null ? round($tendered, 2) : null,
                        'change'        => round($change, 2),
                    ],
                ]);

                // [Sprint 1B 2026-05-16] Write cash_movement IN for each CASH
                // tranche (amount = tranche amount, not order total). strict=
                // true → throw HttpException if session race-closed between
                // guard above and movement write (defense-in-depth).
                if ($mode === PosPaymentMethod::CASH && $cashSession !== null) {
                    $this->cashDrawerService->recordMovement(
                        sessionId: (int) $cashSession->id,
                        type: CashMovement::TYPE_ORDER_PAYMENT,
                        amount: round($amount, 2),
                        direction: CashMovement::DIRECTION_IN,
                        orderId: (int) $order->id,
                        notes: 'split_tranche_' . (int) $idx,
                        strict: true,
                    );
                }

                $persisted->push($row);
            }

            return $persisted;
        });
    }

    /**
     * Alias permissif pour aligner sur la nomenclature courante.
     *
     * @param array<int, array<string, mixed>> $tranches
     */
    public function persist(Order $order, array $tranches): EloquentCollection
    {
        return $this->persistTranches($order, $tranches);
    }
}
