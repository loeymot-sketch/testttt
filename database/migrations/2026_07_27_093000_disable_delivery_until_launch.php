<?php

use Illuminate\Database\Migrations\Migration;
use Smartisan\Settings\Facades\Settings;

/**
 * [GOAL owner 2026-07-27 · heals adversaire P1-1 + P1-2] La livraison n'est PAS lancée
 * (web « Ça arrive bientôt ») mais le gate n'était qu'UI :
 *  1. order_setup_delivery valait ENABLE → un appel API direct pouvait créer une
 *     commande DELIVERY (OrderRequest ne rejette que si == Activity::DISABLE).
 *     → DISABLE (10) : le serveur refuse orderType=5 partout (le bypass token borne
 *     ne couvre que KIOSK/TAKEAWAY — une livraison tombe dans la garde).
 *  2. free_delivery_above (défaut codé 30 €) zéroait les frais ≥30 € dans les 3 moteurs
 *     (FrontendOrderService/OrderService/OrderQuoteService) alors que le nouveau barème
 *     owner ne comporte PAS d'offerte → un client API omettant expected_total obtenait
 *     la livraison gratuite, et le web (qui n'affiche plus d'offerte) aurait pris un 422
 *     systématique ≥30 € à l'activation. → 0 = règle coupée (gardes `$freeAbove > 0`).
 * AU LANCEMENT livraison : remettre order_setup_delivery=ENABLE (admin ou down()) et
 * décider de l'offerte (free_delivery_above) explicitement avec l'owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Settings::group('order_setup')->set(['order_setup_delivery' => \App\Enums\Activity::DISABLE]);
        Settings::group('delivery')->set(['free_delivery_above' => 0]);
    }

    public function down(): void
    {
        Settings::group('order_setup')->set(['order_setup_delivery' => \App\Enums\Activity::ENABLE]);
        Settings::group('delivery')->set(['free_delivery_above' => 30]);
    }
};
