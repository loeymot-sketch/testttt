<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [TICKET PROMO 2026-11-03 · décision propriétaire] Deuxième offre du ticket promo client Uber :
 * « LIVRAISON OFFERTE », au choix du caissier à côté du « −10 % » existant.
 *
 * Additif uniquement, valeurs par défaut neutres — aucun coupon existant ne change de sens :
 *
 *  · coupons.free_delivery ........ le coupon n'enlève rien aux articles, il annule les frais de
 *                                   livraison (appliqué par FrontendOrderService, HORS PricingService
 *                                   — zone gelée — exactement comme le seuil `free_delivery_above`) ;
 *  · coupons.free_delivery_max_km . distance maximale couverte (7 km, propriétaire : au-delà, ce n'est
 *                                   plus notre livraison) ; null = repli sur la config ;
 *  · order_coupons.delivery_waived  frais de livraison réellement offerts : la mesure de ce que le
 *                                   ticket a coûté, sans jamais passer par la colonne `discount`
 *                                   (qui alimente les remises fiscales et les rapports) ;
 *  · promo_flyers.offer ........... 'percent' (−10 %, historique) ou 'free_delivery'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('coupons', 'free_delivery')) {
                $table->boolean('free_delivery')->default(false);
            }
            if (! Schema::hasColumn('coupons', 'free_delivery_max_km')) {
                $table->decimal('free_delivery_max_km', 6, 2)->nullable();
            }
        });

        Schema::table('order_coupons', function (Blueprint $table) {
            if (! Schema::hasColumn('order_coupons', 'delivery_waived')) {
                $table->decimal('delivery_waived', 13, 6)->nullable();
            }
        });

        Schema::table('promo_flyers', function (Blueprint $table) {
            if (! Schema::hasColumn('promo_flyers', 'offer')) {
                $table->string('offer', 20)->default('percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('promo_flyers', function (Blueprint $table) {
            if (Schema::hasColumn('promo_flyers', 'offer')) {
                $table->dropColumn('offer');
            }
        });
        Schema::table('order_coupons', function (Blueprint $table) {
            if (Schema::hasColumn('order_coupons', 'delivery_waived')) {
                $table->dropColumn('delivery_waived');
            }
        });
        Schema::table('coupons', function (Blueprint $table) {
            foreach (['free_delivery_max_km', 'free_delivery'] as $col) {
                if (Schema::hasColumn('coupons', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
