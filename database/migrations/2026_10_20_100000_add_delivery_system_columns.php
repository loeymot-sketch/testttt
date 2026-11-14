<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [LIVRAISON 2026-10-20] Socle de la livraison propre du Cayenne (barème par commune + rayon,
 * départs groupés toutes les 30 minutes). Additif uniquement, aucun statut de commande nouveau
 * (OrderStateMachine est gelée) : le parcours du livreur vit dans `delivery_stops`.
 *
 *  · addresses / order_addresses : commune + code postal STRUCTURÉS, posés par le géocodeur
 *    serveur — la décision « Hénin-Beaumont = 3,90 € » ne repose plus sur du texte libre client.
 *  · orders : distance retenue, palier appliqué (piste d'audit du prix), créneau de départ.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['addresses', 'order_addresses'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'city')) {
                    $table->string('city', 120)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'postal_code')) {
                    $table->string('postal_code', 12)->nullable();
                }
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'delivery_distance_km')) {
                $table->decimal('delivery_distance_km', 7, 3)->nullable();
            }
            if (! Schema::hasColumn('orders', 'delivery_tier')) {
                $table->string('delivery_tier', 24)->nullable();
            }
            if (! Schema::hasColumn('orders', 'delivery_slot_at')) {
                $table->timestamp('delivery_slot_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['delivery_slot_at', 'delivery_tier', 'delivery_distance_km'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        foreach (['addresses', 'order_addresses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['postal_code', 'city'] as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
