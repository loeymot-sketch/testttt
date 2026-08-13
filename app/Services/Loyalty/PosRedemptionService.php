<?php

namespace App\Services\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;

/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 POS cashier loyalty redemption.
 *
 * Mirrors the kiosk path (FrontendOrderService::applyLoyaltyRedemption +
 * DiscountCalculator::kioskLoyaltyRedemption) but operates POST-creation
 * on an existing POS order before payment finalization.
 *
 * Discount mechanism (SSOT mirror of kiosk option-a) :
 *   - Increment order.discount by computed euros.
 *   - Recompute order.total = subtotal - discount + total_tax + delivery_charge.
 *   - Set order.loyalty_customer_code so refund-on-cancel (LoyaltyService::refundPoints)
 *     can locate the customer.
 *
 * Anti-fraud safeguards (LOCK §6) :
 *   §6.1 cashier permission   → enforced in PosLoyaltyRedeemRequest::authorize()
 *   §6.2 single redemption    → enforced by UNIQUE(user_id, order_id, type)
 *                               in 2026_03_26_075919 migration + try/catch on insert
 *   §6.3 balance check        → enforced inside the lockForUpdate transaction below
 *   §6.4 idempotency          → enforced by route-level `idempotency` middleware
 *   §6.5 audit log            → loyalty_transactions row + Log::info channel
 *   §6.6 anti-replay          → pos_session_id captured from CashDrawerService
 *   §6.7 pre-payment only     → order.payment_status NOT PAID + order.status NOT IN
 *                               [DELIVERED, CANCELED, REJECTED, RETURNED] — note
 *                               OrderStatus has no "COMPLETED" enum value (LOCK §6.7
 *                               wording is non-canonical), DELIVERED is the
 *                               equivalent terminal-OK state.
 */
final class PosRedemptionService
{
    public function __construct(
        private readonly CashDrawerService $cashDrawerService,
        private readonly LoyaltyRules $regles,
    ) {
    }

    /**
     * @param Order $order        the POS order to apply redemption to
     * @param int   $points       number of points the cashier wants to redeem
     * @param string $loyaltyCode loyalty code (or phone) provided by the customer
     * @param int|null $cashierId user id of the acting cashier (for audit trail)
     *
     * @return array{order: Order, transaction: LoyaltyTransaction, discount_eur: float, balance_after: int}
     *
     * @throws PosRedemptionException on any anti-fraud violation. The exception
     *         exposes a stable HTTP status + machine-readable error code so the
     *         controller can render a structured JSON response without leaking
     *         internals.
     */
    public function applyToOrder(Order $order, int $points, string $loyaltyCode, ?int $cashierId = null): array
    {
        // [DÉCOUPLAGE FIDÉLITÉ 2026-07-18] Loyalty redeem applies a discount
        // POST-create (order.discount + order.total, NOT order.total_tax) — the F1
        // order shape. F1 (netting TVA du Z sur base remisée) est FIXÉ + prouvé
        // (ZReportDiscountNettingTest, incl. close()+sign()+verifyChain() sur un Z
        // remisé) → le ZReportService frozen nette la TVA sur la base NET, donc un
        // ordre remisé fidélité signe un Z fiscalement CORRECT → redeem sûr. Gaté
        // désormais par le flag DÉDIÉ `pos.loyalty_enabled` (défaut true), DÉCOUPLÉ
        // du kill-switch remises manuelles `pos.manual_discount_enabled` : couper
        // les remises manuelles ne coupe plus la fidélité (owner « garde la fidélité »).
        if (config('pos.loyalty_enabled') !== true) {
            throw new PosRedemptionException(
                'LOYALTY_DISABLED',
                "La fidélité est temporairement désactivée.",
                422
            );
        }

        // [LOCK §6.7] Pre-payment guard — block redemption on orders that
        // already reached a terminal state or are paid. We do this OUTSIDE
        // the transaction so the customer row lock is not acquired uselessly.
        $this->assertOrderRedeemable($order);

        if ($points <= 0) {
            throw new PosRedemptionException('INVALID_POINTS', 'Points doit etre positif', 422);
        }

        $loyaltyCode = strtoupper(trim($loyaltyCode));
        if ($loyaltyCode === '') {
            throw new PosRedemptionException('INVALID_LOYALTY_CODE', 'Code fidelite requis', 422);
        }

        // [LOCK §6.2 + AUDIT-P49-BUG5] Validate points multiple-of-rate so we
        // never compute a fractional euro discount. Mirror kiosk LoyaltyController::redeem.
        $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
        if ($rate <= 0) {
            $rate = 100;
        }
        if ($points % $rate !== 0) {
            throw new PosRedemptionException(
                'POINTS_NOT_MULTIPLE',
                "Les points doivent etre un multiple de {$rate}",
                422
            );
        }

        /*
         * [P1 2026-08-10 · propriétaire : « limite utilisable à partir de 1000 points »] LE PLANCHER
         * N'ÉTAIT APPLIQUÉ NULLE PART À LA CAISSE.
         *
         * `loyalty_min_redeem_points` est respecté sur le site et à la borne
         * (`DiscountCalculator:58`, `LoyaltyController:402` et `:526`) — et `grep min_redeem` dans ce
         * fichier ne donnait AUCUNE occurrence. Le défaut restait masqué tant que le seuil valait son
         * défaut de 50, puisque la règle du multiple impose déjà 100 points. Il mord dès que
         * l'exploitant règle 1000, ce qu'il demande : la borne et le site refuseraient sous 1000 et la
         * caisse accepterait encore 100 points pour 1 €. Le client apprendrait vite à venir dépenser
         * ses points au comptoir — un seuil qui ne tient que sur deux surfaces sur trois n'est pas un
         * seuil.
         *
         * Le refus NOMME le chiffre : un caissier doit pouvoir l'expliquer au client, pas subir un
         * rejet muet. Et il porte un code stable, parce que la fenêtre de caisse s'appuie sur le code,
         * jamais sur le texte.
         *
         * Le défaut lu ici est le MÊME que partout ailleurs (50). Deux défauts différents pour un
         * même réglage, c'est un jour où les surfaces divergent sans que personne le voie.
         *
         * ── [AUDIT 2026-08-13] POURQUOI ON LIT `LoyaltyRules`, ET PAS LE RÉGLAGE BRUT ────────
         * J'avais d'abord écrit ici `Settings::…->get('loyalty_min_redeem_points', 50)`, alors que
         * la fenêtre de caisse ANNONCE au client `LoyaltyRules::effectiveFloor()` (le premier
         * multiple du taux au-dessus du réglage). Deux définitions du même seuil : le comptoir
         * pouvait annoncer 1000 pendant que l'encaissement acceptait 950.
         *
         * Vérifié avant de changer : les deux ne peuvent PAS diverger aujourd'hui, parce que la
         * garde du multiple (ci-dessus) élimine toute valeur entre le réglage et le plancher
         * effectif — un réglage à 950 refuse 950 avec `POINTS_NOT_MULTIPLE`, jamais avec
         * `BELOW_MIN_REDEEM`. La divergence était donc NOMINALE, pas visible du client.
         *
         * On unifie quand même sur la définition unique : le jour où l'ordre des gardes change,
         * ou où le multiple est assoupli, la divergence deviendrait réelle et silencieuse. Une
         * définition dupliquée est une divergence programmée.
         *
         * Sentinelles : tests/Feature/Pos/PosLoyaltyRedeemFloorTest.php
         *               tests/Feature/Pos/PosLoyaltyFloorAnnonceEgaleAppliqueTest.php
         */
        $plancher = $this->regles->effectiveFloor();
        if ($plancher > 0 && $points < $plancher) {
            throw new PosRedemptionException(
                'BELOW_MIN_REDEEM',
                "Il faut au moins {$plancher} points pour les utiliser (il en propose {$points}).",
                422
            );
        }

        $discountEur = round($points / $rate, 2);

        // Atomic block — customer row locked + balance check + ledger append +
        // order discount/total recomputation.
        return DB::transaction(function () use ($order, $points, $loyaltyCode, $cashierId, $discountEur) {
            // [LOCK §6.3] Customer lookup + lockForUpdate (sqlite tolerates no-op).
            $query = User::where('loyalty_code', $loyaltyCode)
                ->where('status', Status::ACTIVE);
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }
            $customer = $query->first();

            // Fallback : status=1 legacy (matches LoyaltyController::isCustomerActive).
            if (!$customer) {
                $q2 = User::where('loyalty_code', $loyaltyCode)->where('status', 1);
                if (DB::connection()->getDriverName() !== 'sqlite') {
                    $q2->lockForUpdate();
                }
                $customer = $q2->first();
            }

            if (!$customer) {
                throw new PosRedemptionException('CUSTOMER_NOT_FOUND', 'Code fidelite introuvable', 404);
            }

            $available = (int) ($customer->loyalty_points ?? 0);
            if ($available < $points) {
                throw new PosRedemptionException(
                    'INSUFFICIENT_BALANCE',
                    "Solde insuffisant : {$available} pts disponibles, {$points} demandes",
                    422
                );
            }

            // [LOCK §6.7] Discount cannot exceed subtotal.
            // [CAISSE-LOGIC-HEAL 2026-07-11] Le garde doit tester la remise CUMULÉE
            // (remise préexistante + ce rachat), pas le rachat seul : la remise est
            // appliquée en cumul plus bas (`$newDiscount = $currentDiscount + $discountEur`,
            // l.206). Tester `$discountEur` seul laissait un rachat déborder une remise
            // déjà posée (ex sous-total 6,90 avec remise 6,00 → rachat 2,00 « passait »
            // mais 0,90 de valeur livrée, total clampé à 0), débitant TOUS les points
            // pour une valeur nulle. On rejette désormais le sur-rachat (le caissier en
            // rachète moins) — aligné sur l'intention §6.7 (remise TOTALE ≤ sous-total).
            $subtotal        = (float) ($order->subtotal ?? 0);
            $existingDiscount = (float) ($order->discount ?? 0);
            $cumulativeDiscount = round($existingDiscount + $discountEur, 2);
            if ($cumulativeDiscount > $subtotal) {
                throw new PosRedemptionException(
                    'DISCOUNT_EXCEEDS_SUBTOTAL',
                    "Reduction cumulee {$cumulativeDiscount} EUR > sous-total {$subtotal} EUR",
                    422
                );
            }

            // [LOCK §6.6] Anti-replay : capture the open session id of the
            // cashier on the order's branch. Null when no session is open
            // (e.g. simulation_hardware mode) — the route is still protected
            // by permission gate + idempotency, so we accept this gracefully
            // rather than block. Auditability degrades but mutation stays safe.
            $posSessionId = null;
            if ($cashierId !== null && (int) $order->branch_id > 0) {
                $session = $this->cashDrawerService->findOpenSessionForUser(
                    (int) $order->branch_id,
                    (int) $cashierId,
                );
                $posSessionId = $session?->id;
            }

            // [LOCK §6.3] Decrement balance.
            $balanceAfter = $available - $points;
            DB::table('users')
                ->where('id', $customer->id)
                ->update([
                    'loyalty_points' => $balanceAfter,
                    'updated_at'     => now(),
                ]);

            // [LOCK §6.2 + §6.5] Append ledger row — UNIQUE(user_id, order_id, type)
            // catches double-redemption attempts at DB level. We surface a 409 so
            // the cashier UI can show "Déjà racheté" cleanly.
            try {
                $txn = LoyaltyTransaction::create([
                    'user_id'        => $customer->id,
                    'loyalty_code'   => $customer->loyalty_code,
                    'order_id'       => $order->id,
                    'type'           => 'redeem',
                    'points'         => -$points,
                    'balance_after'  => $balanceAfter,
                    'source_surface' => 'pos',
                    'pos_session_id' => $posSessionId,
                    'description'    => "Reduction fidelite POS appliquee par cashier #{$cashierId}",
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23000') {
                    throw new PosRedemptionException(
                        'ALREADY_REDEEMED',
                        'Une reduction fidelite existe deja pour cette commande',
                        409
                    );
                }
                throw $e;
            }

            // [LOCK §3 step 2] Update order totals + link loyalty code.
            $currentDiscount = (float) ($order->discount ?? 0);
            $currentTax      = (float) ($order->total_tax ?? 0);
            $currentDelivery = (float) ($order->delivery_charge ?? 0);
            $newDiscount     = round($currentDiscount + $discountEur, 2);

            // [H.5 EDGE-3 P1 2026-05-24] Mirror PricingService::computePricing
            // lines 350-354 — the discount post-recompute MUST branch on TTC/HT
            // because Order.subtotal semantics differ between modes:
            //   TTC mode (V1 default): subtotal already contains tax INSIDE the
            //     TTC line totals. total = subtotal − discount + delivery.
            //     Adding $currentTax here was DOUBLE-COUNTING and OVERCHARGED
            //     loyalty-redeeming customers (e.g. 50€ free-order redeem
            //     landed at total=4.55€ instead of 0€).
            //   HT mode (legacy/tests): subtotal is tax-EXCLUSIVE, tax is
            //     SEPARATE and must be re-added.
            // The kiosk mirror (FrontendOrderService → DiscountCalculator →
            // PricingService) gets this right because it applies the discount
            // INSIDE the create-transaction via PricingService. The POS path
            // here operates POST-create and re-derives total inline, so the
            // TTC/HT branch must be duplicated here.
            if ((bool) config('pricing.tax_inclusive_prices', true)) {
                $newTotal = round(max(0, $subtotal - $newDiscount + $currentDelivery), 2);
            } else {
                $newTotal = round(max(0, $subtotal - $newDiscount + $currentTax + $currentDelivery), 2);
            }

            // [Z6-P1-WGS 2026-05-19] singular form — Order HAS SoftDeletes;
            // a soft-deleted Order must NOT receive a loyalty redeem update,
            // so we keep SoftDeletingScope active (preserves NF525 audit
            // surface). The caller (PosLoyaltyController:45) already asserts
            // post-fetch branch ownership, so dropping BranchScope only is
            // safe here.
            Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('id', $order->id)
                ->update([
                    'discount'              => $newDiscount,
                    'total'                 => $newTotal,
                    'loyalty_customer_code' => $customer->loyalty_code,
                    'updated_at'            => now(),
                ]);

            // Refresh for caller. Same intent — singular bypass, SoftDeletes ON.
            $freshOrder = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->findOrFail($order->id);

            Log::info('[Loyalty] POS redeem applied', [
                'order_id'       => $order->id,
                'customer_id'    => $customer->id,
                'cashier_id'     => $cashierId,
                'pos_session_id' => $posSessionId,
                'points'         => $points,
                'discount_eur'   => $discountEur,
                'balance_after'  => $balanceAfter,
            ]);

            return [
                'order'         => $freshOrder,
                'transaction'   => $txn,
                'discount_eur'  => $discountEur,
                'balance_after' => $balanceAfter,
            ];
        });
    }

    /**
     * [LOCK §6.7] Pre-payment guard.
     * Reject if the order is already PAID or in a terminal state.
     */
    private function assertOrderRedeemable(Order $order): void
    {
        if ((int) $order->payment_status === PaymentStatus::PAID) {
            throw new PosRedemptionException(
                'ORDER_ALREADY_FINALIZED',
                'Cette commande est deja payee — reduction impossible',
                409
            );
        }

        // OrderStatus has no "COMPLETED" enum constant (cf LOCK §6.7) ;
        // the closest terminal-OK state is DELIVERED. Any of these blocks
        // redemption to preserve fiscal/audit invariants.
        $terminal = [
            OrderStatus::DELIVERED,
            OrderStatus::CANCELED,
            OrderStatus::REJECTED,
            OrderStatus::RETURNED,
        ];
        if (in_array((int) $order->status, $terminal, true)) {
            throw new PosRedemptionException(
                'ORDER_ALREADY_FINALIZED',
                'Cette commande est dans un etat terminal — reduction impossible',
                409
            );
        }
    }
}
