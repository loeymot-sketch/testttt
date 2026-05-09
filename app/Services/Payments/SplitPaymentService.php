<?php

namespace App\Services\Payments;

use App\Enums\PosPaymentMethod;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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

            $totalCents += (int) round($amount * 100);
        }

        $serverTotalCents = (int) round($orderTotal * 100);
        $toleranceCents = (int) round(self::TOLERANCE_OVERPAY * 100);

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
                    self::TOLERANCE_OVERPAY,
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

        return DB::transaction(function () use ($order, $tranches): EloquentCollection {
            $persisted = new EloquentCollection();

            foreach ($tranches as $idx => $t) {
                $mode = (int) $t['mode'];
                $amount = (float) $t['amount'];
                $tenderedRaw = $t['tendered'] ?? null;
                $tendered = $tenderedRaw !== null && $tenderedRaw !== '' ? (float) $tenderedRaw : null;
                $changeRaw = $t['change'] ?? ($t['change_amount'] ?? 0);
                $change = (float) ($changeRaw ?? 0);
                $reference = $t['reference'] ?? $t['note'] ?? null;
                if (is_string($reference)) {
                    $reference = substr($reference, 0, 64);
                }

                $row = OrderPayment::create([
                    'order_id'      => (int) $order->id,
                    'branch_id'     => (int) $order->branch_id,
                    'mode'          => $mode,
                    'amount'        => $amount,
                    'tendered'      => $tendered,
                    'change_amount' => $change,
                    'reference'     => $reference,
                    'paid_at'       => now(),
                ]);

                $this->auditLog->write([
                    'branch_id'   => (int) $order->branch_id,
                    'user_id'     => auth()->check() ? (int) auth()->id() : null,
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
