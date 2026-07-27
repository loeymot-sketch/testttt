<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;
use Smartisan\Settings\Facades\Settings;

/**
 * Delivery config 2026-06-27 — règle de livraison Le Cayenne (owner spec).
 *
 * Source = owner (2026-06-27) :
 *   - Origine = 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont (la branche).
 *   - 4 € jusqu'à 5 km, puis +1 € par km entamé au-delà.
 *   - Livraison OFFERTE dès 30 € de sous-total (panier).
 *
 * Mécanique back (NON-frozen, 100 % data + 1 hook service) :
 *   - Colonnes par-branche `delivery_fee_base / per_km / minimum / free_km`
 *     consommées par App\Services\Delivery\DeliveryFeeService::fromDistanceKm
 *     → fee = base + per_km * ceil(max(0, dist - free_km)), borné par minimum.
 *   - Origine = colonnes latitude/longitude de la branche (DeliveryQuoteService
 *     recalcule la distance depuis l'adresse client = SSOT, le fee envoyé par
 *     le web/borne n'est qu'indicatif).
 *   - `free_delivery_above` (Settings group "delivery") appliqué dans
 *     FrontendOrderService : sous-total >= seuil → delivery_charge forcé à 0.
 *
 * Le site web standalone miroite ces valeurs dans web/api.js (DELIVERY) +
 * les <meta> de web/index.html (delivery-*), documentées dans
 * web/reports/DELIVERY_PRICING_2026-06-27.md.
 *
 * Frozen-zone : aucune (PricingService intouché — la livraison est un poste
 * distinct du calcul article). Idempotent : ré-exécutable sans effet de bord.
 */
class DeliveryConfigSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::withoutGlobalScopes()->find(1);
        if ($branch === null) {
            $this->command?->warn('DeliveryConfigSeeder: branch #1 introuvable — skip.');
            return;
        }

        // Origine de livraison = adresse de la branche (Le Cayenne).
        $branch->latitude  = '50.4215667';
        $branch->longitude = '2.9549060';
        if (blank($branch->address)) {
            $branch->address = '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont';
        }

        // Barème owner 2026-07-27 (remplace 2026-06-27) : 4 € fixe ≤ 3 km, puis +2 €/km
        // entamé — grille 3→4€, 4→5€, 5→7€, 6→9€ = max(4, 3 + 2·ceil(d−3)).
        $branch->delivery_fee_base    = 3.00;
        $branch->delivery_fee_per_km  = 2.00;
        $branch->delivery_fee_minimum = 4.00;
        $branch->delivery_fee_free_km = 3.00;
        $branch->save();

        // Offerte ≥ seuil : COUPÉE (0) tant que la livraison n'est pas lancée — le barème
        // owner 2026-07-27 n'en comporte pas. À re-décider explicitement au lancement :
        //   Settings::group('delivery')->set('free_delivery_above', 30);
        Settings::group('delivery')->set('free_delivery_above', 0);

        $this->command?->info('DeliveryConfigSeeder: branche #1 = 437 Rue Élie Gruyelle · 4€/≤3km +2€/km (grille 4/5/7/9) · offerte coupée.');
    }
}
