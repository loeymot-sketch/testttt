<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [FLYER PROMO 2026-08-07 — correction de ma propre migration du même jour]
 *
 * `2026_08_07_120000_create_promo_flyers_table` ajoutait un index UNIQUE sur
 * `coupons.code` pour garantir qu'aucun code ne puisse être distribué deux
 * fois. L'intention était bonne, l'effet de bord ne l'était pas : le projet
 * autorise DÉLIBÉRÉMENT de recréer un coupon avec le code d'un coupon
 * supprimé — la règle de formulaire le dit explicitement
 * (`Rule::unique('coupons','code')->whereNull('deleted_at')`) et un test le
 * verrouille (`CouponSoftDeleteHistoryTest::test_can_recreate_coupon_with_same_code_after_delete`).
 *
 * Un index UNIQUE sur `code` seul ne sait pas faire cette distinction : MySQL
 * traite les NULL de `deleted_at` comme distincts, donc un index composite
 * (code, deleted_at) n'apporterait rien non plus.
 *
 * On retire donc cet index. La garantie qui compte réellement pour les tickets
 * — deux clients ne doivent jamais recevoir le même code — reste assurée par
 * l'index UNIQUE sur `promo_flyers.code`, qui n'a lui aucune notion de
 * suppression logique, complété par la vérification préalable du générateur et
 * sa reprise sur collision.
 *
 * La leçon, la même que d'habitude sur ce projet : une garantie ajoutée sur
 * une table partagée doit être confrontée aux comportements que cette table
 * porte déjà, pas seulement au besoin du jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('coupons')) {
            return;
        }

        try {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropUnique('coupons_code_unique');
            });
        } catch (\Throwable $e) {
            // L'index n'a jamais été créé (base qui contenait des doublons au
            // moment de la migration précédente) — rien à faire.
        }
    }

    public function down(): void
    {
        // Volontairement vide : recréer l'index rejouerait le défaut.
    }
};
