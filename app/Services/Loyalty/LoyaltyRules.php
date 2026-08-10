<?php

namespace App\Services\Loyalty;

use Smartisan\Settings\Facades\Settings;

/**
 * LE BARÈME DE FIDÉLITÉ — une seule définition, pour toutes les surfaces.
 *
 * ── POURQUOI CETTE CLASSE EXISTE ─────────────────────────────────────────────────────────────
 * Le barème était exprimé en QUATRE endroits qui devaient s'accorder sans jamais se parler :
 *   - `LoyaltyController::config()`   — ce qu'on ANNONCE au client (plancher effectif)
 *   - `LoyaltyController::redeem()`   — ce que le SITE débite
 *   - `DiscountCalculator:58`         — ce que la BORNE débite
 *   - `PosRedemptionService`          — ce que la CAISSE débite
 *
 * Le 10 août, la caisse ne lisait pas du tout le plancher : trois surfaces sur quatre le
 * respectaient. C'est le motif du « jumeau oublié » — une définition dupliquée est une divergence
 * programmée. Toute nouvelle surface (écran d'identification au comptoir, lecture de QR à la
 * tablette) passe désormais par ici, et n'a plus l'occasion de recopier la règle de travers.
 *
 * ── LE PLANCHER EFFECTIF ─────────────────────────────────────────────────────────────────────
 * Deux gardes se superposent au débit : le plancher (`loyalty_min_redeem_points`) et le multiple
 * du taux. Annoncer le réglage brut est donc mentir : avec un plancher à 50 et un taux à 100, un
 * client à 60 points lit « utilisables dès 50 » et se fait refuser au comptoir. Le seuil VRAI est
 * le premier multiple du taux ≥ réglage. C'est cette valeur, et elle seule, qu'on montre.
 *
 * Sentinelles : tests/Feature/Loyalty/LoyaltyRulesTest.php
 *               tests/Feature/Frontend/LoyaltyConfigEffectiveFloorTest.php (l'annonce au client)
 *               tests/Feature/Pos/PosLoyaltyRedeemFloorTest.php            (le débit en caisse)
 */
final class LoyaltyRules
{
    /** Points gagnés par euro dépensé. */
    public function pointsPerEuro(): int
    {
        return $this->reglage('loyalty_points_per_euro', 10);
    }

    /** Points nécessaires pour 1 € de remise (« le taux »). */
    public function rate(): int
    {
        $taux = $this->reglage('loyalty_points_for_1_euro_discount', 100);

        // Un taux nul ou négatif rendrait la conversion absurde (division par zéro, remise
        // infinie). Les surfaces qui débitent retombent déjà sur 100 dans ce cas — on fait pareil,
        // ici et une seule fois.
        return $taux > 0 ? $taux : 100;
    }

    /** Le plancher tel que l'exploitant l'a réglé, sans interprétation. */
    public function floorSetting(): int
    {
        return $this->reglage('loyalty_min_redeem_points', 50);
    }

    /**
     * LE SEUIL QU'ON MONTRE ET QU'ON OPPOSE : premier multiple du taux ≥ réglage.
     *
     * Jamais zéro : « utilisable dès 0 point » n'a aucun sens, rien n'est débitable sous le taux.
     */
    public function effectiveFloor(): int
    {
        $taux     = $this->rate();
        $plancher = $this->floorSetting();

        return (int) (max(1, (int) ceil($plancher / $taux)) * $taux);
    }

    /** Valeur en euros d'un nombre de points, au taux de la maison. */
    public function euroValue(int $points): float
    {
        return round($points / $this->rate(), 2);
    }

    /**
     * Ce que ce client peut réellement utiliser MAINTENANT, en points.
     *
     * Trois contraintes se cumulent, et les oublier c'est promettre une remise impossible :
     *   1. son solde,
     *   2. le multiple du taux (on arrondit vers le BAS — jamais offrir plus que le solde),
     *   3. le plancher effectif (sous lui, rien n'est utilisable : on renvoie 0, pas un reste).
     *
     * Le plafond de la commande est volontairement absent : il appartient au calcul de la remise
     * (`PosRedemptionService`), qui connaît le total, la TVA et les articles. Cette classe dit ce
     * que vaut un solde, pas ce que vaut une commande.
     */
    public function usablePoints(int $balance): int
    {
        if ($balance <= 0) {
            return 0;
        }

        $taux      = $this->rate();
        $utilisable = intdiv($balance, $taux) * $taux;

        return $utilisable >= $this->effectiveFloor() ? $utilisable : 0;
    }

    /**
     * Ce qu'il manque à ce client pour atteindre le seuil, en points. 0 s'il y est déjà.
     *
     * Sert à dire au comptoir « encore 300 points » au lieu d'un refus sec — un client qui
     * comprend ce qui lui manque revient, un client qui subit un « non » s'en va.
     */
    public function pointsMissingBeforeUse(int $balance): int
    {
        $manque = $this->effectiveFloor() - max(0, $balance);

        return $manque > 0 ? $manque : 0;
    }

    private function reglage(string $cle, int $defaut): int
    {
        $valeur = Settings::group('loyalty_setup')->get($cle, $defaut);

        // `get()` rend `null` si la ligne existe avec une charge nulle — un état que l'écran admin
        // ne peut pas produire (`LoyaltySetupRequest` impose `required|integer`), mais qu'une
        // migration ou une main sur la base peut laisser. On retombe alors sur le défaut du
        // logiciel plutôt que sur 0, qui désactiverait la règle en silence.
        return $valeur === null || $valeur === '' ? $defaut : (int) $valeur;
    }
}
