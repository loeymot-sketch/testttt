<?php

namespace App\Services\Loyalty;

use App\Enums\Status;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Services\Identity\CustomerAccount;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;

/**
 * CRÉDITER MANUELLEMENT LE COMPTE D'UN CLIENT — un montant en euros, sans commande.
 *
 * ── LA DEMANDE DU PROPRIÉTAIRE (2026-08-14) ──────────────────────────────────────────────────
 * « Quand je lui ajoute un montant équivalent fidélité, par exemple sept euros, je veux
 *   directement les rajouter dans son compte. » Distinct du crédit AUTOMATIQUE d'une vente
 *   (`AwardLoyaltyPointsOnDelivery`, proportionnel au total) : ici le caissier choisit lui-même
 *   la somme — geste commercial, oubli d'une vente passée, dédommagement.
 *
 * ── CE QUI EXISTAIT DÉJÀ, ET CE QUI MANQUAIT ─────────────────────────────────────────────────
 * `Frontend\LoyaltyController::addPoints()` fait déjà exactement ce mouvement de solde (increment
 * atomique + ligne `manual_add`), mais prend des POINTS, pas des euros, et n'est appelé par AUCUNE
 * surface caisse — recopier cette logique aurait été le jumeau habituel. On la reproduit ici en
 * euros (la seule unité qu'un caissier manipule au comptoir) et gardée derrière `permission:pos`
 * comme `attach-loyalty`, avec le même style de transaction que `PosRedemptionService` (verrou +
 * grand-livre + garde staff) plutôt qu'un simple `increment()` sans verrou.
 *
 * Sentinelle : tests/Feature/Pos/PosLoyaltyManualCreditTest.php
 */
final class PosManualCreditService
{
    public function __construct(
        private readonly CustomerAccount $comptes,
        private readonly LoyaltyRules $regles,
    ) {
    }

    /**
     * @return array{customer_id: int, points_added: int, balance_after: int, transaction: LoyaltyTransaction}
     *
     * @throws PosRedemptionException
     */
    public function credit(
        string $loyaltyCode,
        float $euros,
        ?int $cashierId = null,
        ?int $orderId = null,
        ?string $reason = null,
    ): array {
        if (config('pos.loyalty_enabled') !== true) {
            throw new PosRedemptionException('LOYALTY_DISABLED', 'La fidélité est temporairement désactivée.', 422);
        }

        $loyaltyCode = strtoupper(trim($loyaltyCode));
        if ($loyaltyCode === '') {
            throw new PosRedemptionException('INVALID_LOYALTY_CODE', 'Code fidélité requis', 422);
        }

        if ($euros <= 0) {
            throw new PosRedemptionException('INVALID_AMOUNT', 'Le montant doit être positif', 422);
        }

        // Même barème que le débit — un euro crédité manuellement vaut ce qu'un euro dépensé
        // vaudrait à l'envers. Deux taux différents pour le même mot « euro » serait le jumeau
        // que ce projet répare sans cesse ailleurs.
        $points = (int) round($euros * $this->regles->rate());
        if ($points <= 0) {
            throw new PosRedemptionException('INVALID_AMOUNT', 'Montant trop faible pour valoir un point', 422);
        }

        return DB::transaction(function () use ($loyaltyCode, $points, $euros, $cashierId, $orderId, $reason) {
            $query = User::where('loyalty_code', $loyaltyCode)->where('status', Status::ACTIVE);
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }
            $customer = $query->first();

            if (!$customer) {
                $q2 = User::where('loyalty_code', $loyaltyCode)->where('status', 1);
                if (DB::connection()->getDriverName() !== 'sqlite') {
                    $q2->lockForUpdate();
                }
                $customer = $q2->first();
            }

            if (!$customer) {
                throw new PosRedemptionException('CUSTOMER_NOT_FOUND', 'Code fidélité introuvable', 404);
            }

            // Un caissier ne se crédite pas lui-même, ni un collègue — même garde que la
            // recherche comptoir (`PosCustomerLookupService::eligible`).
            if ($this->comptes->isStaff($customer)) {
                throw new PosRedemptionException('STAFF_ACCOUNT', 'Ce compte appartient à l\'équipe.', 422);
            }

            $balanceAfter = (int) ($customer->loyalty_points ?? 0) + $points;

            DB::table('users')->where('id', $customer->id)->update([
                'loyalty_points' => $balanceAfter,
                'updated_at'     => now(),
            ]);

            try {
                $txn = LoyaltyTransaction::create([
                    'user_id'        => $customer->id,
                    'loyalty_code'   => $customer->loyalty_code,
                    'order_id'       => $orderId,
                    'type'           => 'manual_add',
                    'points'         => $points,
                    'balance_after'  => $balanceAfter,
                    'source_surface' => 'pos',
                    'description'    => trim(
                        sprintf('Crédit manuel de %s€ par caissier #%s', number_format($euros, 2, ',', ''), $cashierId ?? '?')
                        . ($reason ? " — {$reason}" : '')
                    ),
                ]);
            } catch (QueryException $e) {
                // Le grand-livre porte une UNIQUE(user_id, order_id, type) : un même order_id
                // rejoué (double appui) ne recrédite pas deux fois — on le lit comme un succès
                // déjà acquis plutôt qu'une panne, à condition qu'un order_id ait été fourni.
                if (($e->errorInfo[0] ?? null) === '23000' && $orderId !== null) {
                    throw new PosRedemptionException(
                        'ALREADY_CREDITED',
                        'Un crédit manuel existe déjà pour cette commande.',
                        409
                    );
                }
                throw $e;
            }

            Log::info('[Loyalty] POS manual credit applied', [
                'customer_id'   => $customer->id,
                'cashier_id'    => $cashierId,
                'order_id'      => $orderId,
                'euros'         => $euros,
                'points'        => $points,
                'balance_after' => $balanceAfter,
            ]);

            return [
                'customer_id'   => (int) $customer->id,
                'points_added'  => $points,
                'balance_after' => $balanceAfter,
                'transaction'   => $txn,
            ];
        });
    }
}
