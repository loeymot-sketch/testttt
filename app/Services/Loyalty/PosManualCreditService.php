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
 * CRÉDITER — OU RETIRER — MANUELLEMENT DES POINTS SUR LE COMPTE D'UN CLIENT, sans commande.
 *
 * ── LA DEMANDE DU PROPRIÉTAIRE (2026-08-14) ──────────────────────────────────────────────────
 * « Quand je lui ajoute un montant équivalent fidélité, par exemple sept euros, je veux
 *   directement les rajouter dans son compte. » Distinct du crédit AUTOMATIQUE d'une vente
 *   (`AwardLoyaltyPointsOnDelivery`, proportionnel au total) : ici le caissier choisit lui-même
 *   la somme — geste commercial, oubli d'une vente passée, dédommagement.
 *
 * ── LE BARÈME (CORRIGÉ 2026-08-14, ERREUR RÉELLE EN PRODUCTION) ──────────────────────────────
 * Version d'origine : convertissait au taux de REDEMPTION (`LoyaltyRules::rate()`,
 * `loyalty_points_for_1_euro_discount`, 100 pts/€ en prod). FAUX — un crédit manuel émule ce
 * qu'un client aurait GAGNÉ pour un achat de ce montant, pas ce qu'un euro de remise COÛTE en
 * points. Le bon taux est `LoyaltyRules::pointsPerEuro()` (`loyalty_points_per_euro`, 10 pts/€
 * en prod). Écart mesuré en prod le jour même : 17,30€ crédités à 1730 pts au lieu de 173 —
 * *exactement* un facteur 10, le propriétaire l'a repéré au comptoir et corrigé manuellement.
 * Transaction fautive #14 (2026-08-14 22:55) laissée intacte (ledger append-only, « ne jamais
 * annuler ce qui est déjà posé ») ; une ligne `manual_deduct` corrige le solde vers l'avant —
 * voir migration `2026_08_14_...correction...` / commande de correction ponctuelle exécutée
 * après ce fix.
 *
 * ── POURQUOI UN RETRAIT MANUEL EXISTE MAINTENANT ─────────────────────────────────────────────
 * Sans lui, la SEULE façon de corriger un sur-crédit était d'éditer la base à la main — un geste
 * qu'aucun caissier ne peut faire, et qu'un patron ne devrait pas avoir à demander à un
 * développeur à chaque erreur de frappe. `deduct()` est le miroir symétrique de `credit()` :
 * même verrou, même garde staff, plancher à zéro (un solde ne devient jamais négatif), ligne
 * `manual_deduct` déjà prévue dans le libellé du grand-livre (`PosCustomerLookupService::
 * LIBELLES_TYPE`) mais jamais câblée jusqu'ici.
 *
 * ── CE QUI EXISTAIT DÉJÀ, ET CE QUI MANQUAIT ─────────────────────────────────────────────────
 * `Frontend\LoyaltyController::addPoints()` fait déjà un mouvement de solde proche (increment
 * atomique + ligne `manual_add`), mais prend des POINTS, pas des euros, et n'est appelé par
 * AUCUNE surface caisse — recopier cette logique aurait été le jumeau habituel. On la reproduit
 * ici en euros pour le crédit (la seule unité qu'un caissier manipule au comptoir) et en POINTS
 * pour le retrait (une correction se raisonne en points exacts, pas en euros arrondis), gardée
 * derrière `permission:pos` comme `attach-loyalty`, avec le même style de transaction que
 * `PosRedemptionService` (verrou + grand-livre + garde staff) plutôt qu'un simple `increment()`
 * sans verrou.
 *
 * Sentinelle : tests/Feature/Pos/PosLoyaltyManualCreditTest.php,
 *              tests/Feature/Pos/PosLoyaltyManualDeductTest.php
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

        // Taux de GAIN normal (pointsPerEuro), PAS le taux de remise (rate) — cf. commentaire de
        // classe : c'était le bug du 14 août, mesuré en production, facteur 10 exact.
        $points = (int) round($euros * $this->regles->pointsPerEuro());
        if ($points <= 0) {
            throw new PosRedemptionException('INVALID_AMOUNT', 'Montant trop faible pour valoir un point', 422);
        }

        return DB::transaction(function () use ($loyaltyCode, $points, $euros, $cashierId, $orderId, $reason) {
            $customer = $this->verrouillerClient($loyaltyCode);
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
                    'description'    => $this->description(
                        sprintf('Crédit manuel de %s€ par caissier #%s', number_format($euros, 2, ',', ''), $cashierId ?? '?'),
                        $reason
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

    /**
     * RETIRER MANUELLEMENT DES POINTS — correction d'un sur-crédit, geste commercial inverse, etc.
     *
     * Raisonné en POINTS (pas en euros) : une correction vise un chiffre exact, pas un montant
     * arrondi qui dépend d'un taux qu'on est justement en train de corriger. Le solde est
     * PLANCHÉ À ZÉRO — jamais négatif, jamais promesse d'une dette au client.
     *
     * @return array{customer_id: int, points_removed: int, balance_after: int, transaction: LoyaltyTransaction}
     *
     * @throws PosRedemptionException
     */
    public function deduct(
        string $loyaltyCode,
        int $points,
        ?int $cashierId = null,
        ?int $orderId = null,
        ?string $reason = null,
    ): array {
        $loyaltyCode = strtoupper(trim($loyaltyCode));
        if ($loyaltyCode === '') {
            throw new PosRedemptionException('INVALID_LOYALTY_CODE', 'Code fidélité requis', 422);
        }

        if ($points <= 0) {
            throw new PosRedemptionException('INVALID_AMOUNT', 'Points doit etre positif', 422);
        }

        return DB::transaction(function () use ($loyaltyCode, $points, $cashierId, $orderId, $reason) {
            $customer = $this->verrouillerClient($loyaltyCode);

            $avant = (int) ($customer->loyalty_points ?? 0);
            $retires = min($points, max(0, $avant));
            $balanceAfter = $avant - $retires;

            DB::table('users')->where('id', $customer->id)->update([
                'loyalty_points' => $balanceAfter,
                'updated_at'     => now(),
            ]);

            try {
                $txn = LoyaltyTransaction::create([
                    'user_id'        => $customer->id,
                    'loyalty_code'   => $customer->loyalty_code,
                    'order_id'       => $orderId,
                    'type'           => 'manual_deduct',
                    'points'         => -$retires,
                    'balance_after'  => $balanceAfter,
                    'source_surface' => 'pos',
                    'description'    => $this->description(
                        sprintf('Retrait manuel de %d points par caissier #%s', $retires, $cashierId ?? '?'),
                        $reason
                    ),
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23000' && $orderId !== null) {
                    throw new PosRedemptionException(
                        'ALREADY_DEDUCTED',
                        'Un retrait manuel existe déjà pour cette commande.',
                        409
                    );
                }
                throw $e;
            }

            Log::info('[Loyalty] POS manual deduct applied', [
                'customer_id'   => $customer->id,
                'cashier_id'    => $cashierId,
                'order_id'      => $orderId,
                'points_asked'  => $points,
                'points_removed' => $retires,
                'balance_after' => $balanceAfter,
            ]);

            return [
                'customer_id'    => (int) $customer->id,
                'points_removed' => $retires,
                'balance_after'  => $balanceAfter,
                'transaction'    => $txn,
            ];
        });
    }

    /** Verrou + garde staff, partagés par crédit et retrait. */
    /**
     * Compose la description du grand-livre — colonne VARCHAR(255) (migration
     * `2026_03_26_075918_create_loyalty_transactions_table.php:29`). `reason` est déjà
     * borné à 255 par la FormRequest, mais CE bornage-là ignore le préfixe (« Crédit
     * manuel de X€ par caissier #Y — ») : un motif de 255 caractères + préfixe dépasse la
     * colonne et fait échouer l'INSERT en pleine transaction (constaté en tinker le 14 août
     * en appliquant une correction — le motif seul faisait 106 caractères, le total 156,
     * sous la limite ce jour-là, mais un motif au plafond validé par la FormRequest ne
     * l'aurait pas été). `mb_substr` : les caractères accentués ne doivent pas être coupés
     * au milieu d'un octet.
     */
    private function description(string $base, ?string $reason): string
    {
        $texte = trim($base . ($reason ? " — {$reason}" : ''));

        return mb_substr($texte, 0, 255);
    }

    private function verrouillerClient(string $loyaltyCode): User
    {
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

        // Un caissier ne se crédite ni ne se débite lui-même, ni un collègue — même garde que la
        // recherche comptoir (`PosCustomerLookupService::eligible`).
        if ($this->comptes->isStaff($customer)) {
            throw new PosRedemptionException('STAFF_ACCOUNT', 'Ce compte appartient à l\'équipe.', 422);
        }

        return $customer;
    }
}
