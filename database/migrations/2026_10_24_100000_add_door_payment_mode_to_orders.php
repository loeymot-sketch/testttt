<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [CARTE À LA LIVRAISON 2026-10-24] Mode de paiement À LA PORTE d'une livraison du site.
 *
 * `orders.door_payment_mode` = 'card' quand le client a choisi « payer par carte à la livraison » (le livreur encaisse
 * au terminal de paiement qu'il porte, hors de notre système). NULL partout ailleurs : espèces à la porte (le défaut
 * de payment_method=1), carte en ligne, titre-restaurant, caisse, borne. Posée uniquement par FrontendOrderService
 * depuis `door_payment=card` de la requête validée (OrderRequest : livraison + payment_method=1 uniquement).
 *
 * ADDITIVE : aucune donnée existante n'est modifiée ; garde hasColumn (rejouable) ; down() retire la colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'door_payment_mode')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('door_payment_mode', 12)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'door_payment_mode')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('door_payment_mode');
        });
    }
};
