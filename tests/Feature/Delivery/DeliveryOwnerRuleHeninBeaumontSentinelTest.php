<?php

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Exceptions\Delivery\OutsideDeliveryZoneException;
use App\Services\Delivery\DeliveryFeeService;
use Database\Seeders\BranchTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — S9 DEL-ORIGIN-01 + DEL-FEE-01 heal]
 * [Owner rule update 2026-07-27 — remplace 2026-06-27]
 *
 * [Owner rule update 2026-10-20 — barème commune + rayon, config/delivery.php] La grille « par km
 * entamé » du 2026-07-27 est remplacée : Hénin-Beaumont 3,90 · ≤5 km 4,90 · ≤8 km 5,90 ·
 * ≤10 km 6,90 · >10 km refusé (Uber Eats). Les colonnes `branches.delivery_fee_*` ne pilotent plus le prix.
 *
 * Owner business rules (verbatim, 2026-07-27 — grille remplacée, localisation/offerte/OFF toujours valables) :
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
 *   2026-10-20 : Hénin-Beaumont 3,90 · ≤5 km 4,90 · ≤8 km 5,90 · ≤10 km 6,90 · >10 km Uber Eats (config/delivery.php)
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
     * Barème owner 2026-10-20 à travers le VRAI service : Hénin-Beaumont 3,90 quelle que soit la
     * distance ; autres communes 4,90 (≤5 km) / 5,90 (≤8 km) / 6,90 (≤10 km), bornes inclusives ;
     * au-delà de 10 km → refus 422 + proposition Uber Eats.
     */
    public function test_owner_delivery_rule_2026_10_20_grid(): void
    {
        $branch = Branch::factory()->create();
        $service = new DeliveryFeeService();

        // Hénin-Beaumont (par ville OU par code postal) : forfait unique.
        $this->assertSame(3.9, $service->fromDistanceKm(0, $branch, 'Hénin-Beaumont', '62110'));
        $this->assertSame(3.9, $service->fromDistanceKm(4, $branch, 'Hénin-Beaumont', null));
        $this->assertSame(3.9, $service->fromDistanceKm(7.5, $branch, null, '62110'));

        // Autres communes : paliers par rayon, bornes inclusives.
        $this->assertSame(4.9, $service->fromDistanceKm(0, $branch, 'Oignies', '62590'));
        $this->assertSame(4.9, $service->fromDistanceKm(5, $branch, 'Oignies', '62590'));
        $this->assertSame(5.9, $service->fromDistanceKm(5.01, $branch, 'Oignies', '62590'));
        $this->assertSame(5.9, $service->fromDistanceKm(8, $branch, 'Oignies', '62590'));
        $this->assertSame(6.9, $service->fromDistanceKm(8.01, $branch, 'Oignies', '62590'));
        $this->assertSame(6.9, $service->fromDistanceKm(10, $branch, 'Oignies', '62590'));

        // Au-delà de 10 km : refusé, Uber Eats suggéré.
        try {
            $service->fromDistanceKm(10.01, $branch, 'Lens', '62300');
            $this->fail('Au-delà de 10 km la livraison doit être refusée.');
        } catch (OutsideDeliveryZoneException $e) {
            $this->assertTrue($e->suggestUberEats);
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /**
     * La branche principale SEEDÉE doit être à Hénin-Beaumont — garde DEL-ORIGIN-01 + drift seeder.
     * (Le prix ne dépend plus des colonnes delivery_fee_* de la branche : barème config/delivery.php.)
     */
    public function test_seeded_principal_branch_is_henin_beaumont(): void
    {
        $this->seed(BranchTableSeeder::class);

        $branch = Branch::query()->where('name', 'Le Cayenne (principal)')->firstOrFail();

        // Origine : Hénin-Beaumont (62110), PAS Paris (48.85/2.35).
        $this->assertEqualsWithDelta(50.4215667, (float) $branch->latitude, 0.01,
            'Seeded branch latitude must be Hénin-Beaumont, not Paris.');
        $this->assertEqualsWithDelta(2.9549060, (float) $branch->longitude, 0.01,
            'Seeded branch longitude must be Hénin-Beaumont, not Paris.');
        $this->assertSame('62110', (string) $branch->zip_code);

        // Bout-en-bout : la branche seedée tarife selon le barème 2026-10-20.
        $service = new DeliveryFeeService();
        $this->assertSame(3.9, $service->fromDistanceKm(2, $branch, 'Hénin-Beaumont', '62110'));
        $this->assertSame(4.9, $service->fromDistanceKm(3, $branch, 'Oignies', '62590'));
        $this->assertSame(5.9, $service->fromDistanceKm(6, $branch, 'Oignies', '62590'));
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
