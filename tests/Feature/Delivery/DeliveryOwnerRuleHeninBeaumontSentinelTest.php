<?php

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Services\Delivery\DeliveryFeeService;
use Database\Seeders\BranchTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — S9 DEL-ORIGIN-01 + DEL-FEE-01 heal]
 * [Owner rule update 2026-07-27 — remplace 2026-06-27]
 *
 * Owner business rules (verbatim, 2026-07-27) :
 *   - Restaurant : 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont
 *     (geocoded rooftop lat 50.4215667, lng 2.9549060) — PAS Paris.
 *   - Frais livraison : « 3 km c'est toujours fixe à quatre euros et ensuite par
 *     kilomètre de plus… 4 km ça va être cinq euros, 6 km ça doit être neuf euros ».
 *     Grille : ≤3 km → 4 € · 4 km → 5 € · 5 km → 7 € · 6 km → 9 € (km ENTAMÉ).
 *     Encodé avec la formule existante max(minimum, base + per_km·ceil(max(0, d−free_km)))
 *     via base=3 / per_km=2 / minimum=4 / free_km=3 (= max(4, 3+2·ceil(d−3))).
 *   - PAS d'« offerte ≥ 30 € » dans le barème 2026-07-27 : free_delivery_above=0
 *     (la règle 2026-06-27 est REMPLACÉE ; à re-décider explicitement au lancement).
 *   - Livraison NON LANCÉE (web « Ça arrive bientôt ») : order_setup_delivery=DISABLE
 *     par défaut runtime (migration 2026_07_27_093000) — gate SERVEUR, pas juste UI.
 *
 * Historique des règles remplacées (drift-log) :
 *   2026-06-01 : base 5 € ≤5 km +1 €/km.
 *   2026-06-27 : base 4 € ≤5 km +1 €/km + offerte ≥30 €.
 *   2026-07-27 : 4 € ≤3 km puis +2 €/km (grille 4/5/7/9), offerte coupée, delivery OFF.
 *
 * Pre-heal defects toujours gardés :
 *   - DEL-ORIGIN-01 : branche seedée = Paris → distances fausses.
 *   - DEL-FEE-01 : config NULL → fallback legacy ceil(d/5)*5.
 *
 * @group sentinel
 * @group delivery
 */
class DeliveryOwnerRuleHeninBeaumontSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Grille owner 2026-07-27 à travers le VRAI service : ≤3 km → 4 € fixe,
     * puis +2 € par km ENTAMÉ sur base 3 (plancher 4) — 4 km → 5 €, 6 km → 9 €.
     */
    public function test_owner_delivery_rule_2026_07_27_grid(): void
    {
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => 3.00,
            'delivery_fee_per_km'  => 2.00,
            'delivery_fee_minimum' => 4.00,
            'delivery_fee_free_km' => 3.00,
        ]);
        $service = new DeliveryFeeService();

        // ≤ 3 km → forfait 4 € (plancher `minimum`)
        $this->assertSame(4.0, $service->fromDistanceKm(0, $branch));
        $this->assertSame(4.0, $service->fromDistanceKm(1, $branch));
        $this->assertSame(4.0, $service->fromDistanceKm(3, $branch));
        // Grille owner exacte (exemples verbatim : 4 km → 5 €, 6 km → 9 €)
        $this->assertSame(5.0, $service->fromDistanceKm(4, $branch));    // 3 + 2·ceil(1)
        $this->assertSame(7.0, $service->fromDistanceKm(5, $branch));    // 3 + 2·ceil(2)
        $this->assertSame(9.0, $service->fromDistanceKm(6, $branch));    // 3 + 2·ceil(3)
        $this->assertSame(13.0, $service->fromDistanceKm(8, $branch));   // 3 + 2·ceil(5)
        $this->assertSame(17.0, $service->fromDistanceKm(10, $branch));  // 3 + 2·ceil(7)
        // km ENTAMÉ arrondi vers le haut
        $this->assertSame(5.0, $service->fromDistanceKm(3.01, $branch)); // 3 + 2·ceil(0.01)=1
        $this->assertSame(7.0, $service->fromDistanceKm(4.5, $branch));  // 3 + 2·ceil(1.5)=2
        $this->assertSame(13.0, $service->fromDistanceKm(7.2, $branch)); // 3 + 2·ceil(4.2)=5 → 13
    }

    /**
     * La branche principale SEEDÉE doit être à Hénin-Beaumont avec la config
     * owner-rule 2026-07-27 — garde DEL-ORIGIN-01 + DEL-FEE-01 + drift seeder.
     */
    public function test_seeded_principal_branch_is_henin_beaumont_with_owner_rule_config(): void
    {
        $this->seed(BranchTableSeeder::class);

        $branch = Branch::query()->where('name', 'Le Cayenne (principal)')->firstOrFail();

        // Origine : Hénin-Beaumont (62110), PAS Paris (48.85/2.35).
        $this->assertEqualsWithDelta(50.4215667, (float) $branch->latitude, 0.01,
            'Seeded branch latitude must be Hénin-Beaumont, not Paris.');
        $this->assertEqualsWithDelta(2.9549060, (float) $branch->longitude, 0.01,
            'Seeded branch longitude must be Hénin-Beaumont, not Paris.');
        $this->assertSame('62110', (string) $branch->zip_code);

        // Config barème owner 2026-07-27 : base=3, per_km=2, min=4, free_km=3.
        $this->assertSame(3.0, (float) $branch->delivery_fee_base);
        $this->assertSame(2.0, (float) $branch->delivery_fee_per_km);
        $this->assertSame(4.0, (float) $branch->delivery_fee_minimum);
        $this->assertSame(3.0, (float) $branch->delivery_fee_free_km);

        // Bout-en-bout : la branche seedée produit la grille owner.
        $service = new DeliveryFeeService();
        $this->assertSame(4.0, $service->fromDistanceKm(3, $branch));
        $this->assertSame(5.0, $service->fromDistanceKm(4, $branch));
        $this->assertSame(9.0, $service->fromDistanceKm(6, $branch));
    }

    /**
     * [Owner rule 2026-07-27] PAS d'offerte dans le barème : le seeder canonique
     * (DeliveryConfigSeeder) doit poser free_delivery_above = 0 — sinon les 3
     * moteurs (FrontendOrderService/OrderService/OrderQuoteService, défaut codé 30)
     * zéroraient les frais ≥ 30 € alors que le web n'affiche plus d'offerte
     * (= 422 systématique à l'activation + fee perdu en appel API direct).
     * À re-décider explicitement avec l'owner au lancement de la livraison.
     */
    public function test_free_delivery_above_is_disabled_in_2026_07_27_bareme(): void
    {
        $this->seed(\Database\Seeders\DeliveryConfigSeeder::class);

        $threshold = (float) (\Smartisan\Settings\Facades\Settings::group('delivery')
            ->get('free_delivery_above', 30) ?? 30);

        $this->assertSame(0.0, $threshold,
            'Barème owner 2026-07-27 : pas d\'offerte — free_delivery_above doit être 0 '
            . '(la règle « offerte ≥30 € » de 2026-06-27 est remplacée).');
    }
}
