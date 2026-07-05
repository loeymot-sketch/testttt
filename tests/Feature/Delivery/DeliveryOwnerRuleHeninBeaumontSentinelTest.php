<?php

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Services\Delivery\DeliveryFeeService;
use Database\Seeders\BranchTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — S9 DEL-ORIGIN-01 + DEL-FEE-01 heal]
 *
 * Owner business rules (verbatim, updated 2026-06-27):
 *   - Restaurant is at 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont
 *     (geocoded rooftop: lat 50.4215667, lng 2.9549060) — NOT Paris.
 *   - Delivery fee: 4 € for distance ≤ 5 km (straight-line / à vol d'oiseau),
 *     +1 € per additional km, ROUNDED UP per started km. (Base lowered 5€→4€
 *     on 2026-06-27: owner "5 km payé quatre euros, puis un euro chaque km".)
 *   - Livraison OFFERTE dès 30 € de sous-total (free-above threshold).
 *
 * DeliveryFeeService computes the distance fee with the whole-km path:
 *     max(minimum, base + per_km * ceil(max(0, distance - free_km)))
 * configured base=4 / per_km=1 / minimum=4 / free_km=5. So 5km→4€, 6km→5€,
 * 8km→7€, 8.3km→8€ (started km rounds up). The ≥30€ free-delivery gate is a
 * separate subtotal rule enforced in FrontendOrderService.
 *
 * Pre-heal defects this guards against:
 *   - DEL-ORIGIN-01: seeded branch was Paris (48.8566/2.3522) → every delivery
 *     distance/fee computed from the wrong city.
 *   - DEL-FEE-01: seeded branch fee config gave the wrong schedule (NULL → legacy
 *     ceil(d/5)*5 → fee(8km)=10 €) — not the owner's +1€/km (fee(8km)=8 €).
 *
 * @group sentinel
 * @group delivery
 */
class DeliveryOwnerRuleHeninBeaumontSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The owner's fee schedule, computed through the REAL service with the
     * owner-rule config. distance => expected euros.
     *
     * [Owner rule update 2026-06-27] Base lowered 5€ → 4€ (owner: "5 km payé
     * quatre euros, puis un euro chaque kilomètre"). Free-radius + per-km
     * unchanged. The ≥30€ free-delivery threshold is a separate subtotal gate
     * (see test_free_delivery_above_threshold_is_30_euros).
     */
    public function test_owner_delivery_rule_is_4eur_base_plus_1eur_per_started_km(): void
    {
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => 4.00,
            'delivery_fee_per_km'  => 1.00,
            'delivery_fee_minimum' => 4.00,
            'delivery_fee_free_km' => 5.00,
        ]);
        $service = new DeliveryFeeService();

        // ≤ 5 km → flat 4 €
        $this->assertSame(4.0, $service->fromDistanceKm(0, $branch));
        $this->assertSame(4.0, $service->fromDistanceKm(3, $branch));
        $this->assertSame(4.0, $service->fromDistanceKm(5, $branch));
        // > 5 km → +1 € per STARTED km (rounded up)
        $this->assertSame(5.0, $service->fromDistanceKm(6, $branch));   // 4 + ceil(1)
        $this->assertSame(7.0, $service->fromDistanceKm(8, $branch));   // 4 + ceil(3)
        $this->assertSame(9.0, $service->fromDistanceKm(10, $branch));  // 4 + ceil(5)
        // partial km rounds UP
        $this->assertSame(5.0, $service->fromDistanceKm(5.01, $branch)); // 4 + ceil(0.01)=1
        $this->assertSame(8.0, $service->fromDistanceKm(8.3, $branch));  // 4 + ceil(3.3)=4
        $this->assertSame(12.0, $service->fromDistanceKm(12.5, $branch)); // 4 + ceil(7.5)=8
    }

    /**
     * The seeded principal branch must be in Hénin-Beaumont with the owner-rule
     * fee config — guards DEL-ORIGIN-01 + DEL-FEE-01 against seeder regression.
     */
    public function test_seeded_principal_branch_is_henin_beaumont_with_owner_rule_config(): void
    {
        $this->seed(BranchTableSeeder::class);

        $branch = Branch::query()->where('name', 'Le Cayenne (principal)')->firstOrFail();

        // Origin: Hénin-Beaumont (62110), NOT Paris (48.85/2.35).
        $this->assertEqualsWithDelta(50.4215667, (float) $branch->latitude, 0.01,
            'Seeded branch latitude must be Hénin-Beaumont, not Paris.');
        $this->assertEqualsWithDelta(2.9549060, (float) $branch->longitude, 0.01,
            'Seeded branch longitude must be Hénin-Beaumont, not Paris.');
        $this->assertSame('62110', (string) $branch->zip_code);

        // Fee config encodes the owner whole-km rule: base=4, per_km=1, min=4, free_km=5.
        $this->assertSame(4.0, (float) $branch->delivery_fee_base);
        $this->assertSame(1.0, (float) $branch->delivery_fee_per_km);
        $this->assertSame(4.0, (float) $branch->delivery_fee_minimum);
        $this->assertSame(5.0, (float) $branch->delivery_fee_free_km);

        // End-to-end: the seeded branch yields the owner schedule.
        $service = new DeliveryFeeService();
        $this->assertSame(4.0, $service->fromDistanceKm(4, $branch));
        $this->assertSame(7.0, $service->fromDistanceKm(8, $branch));
        $this->assertSame(7.0, $service->fromDistanceKm(7.2, $branch)); // 4 + ceil(2.2)=3 → 7
    }

    /**
     * [Owner rule 2026-06-27] Livraison OFFERTE dès 30€ de sous-total. The
     * threshold lives in Settings delivery.free_delivery_above (DeliveryConfigSeeder)
     * and is enforced by FrontendOrderService (subtotal ≥ threshold → delivery_charge=0).
     * This sentinel guards the canonical value against drift.
     */
    public function test_free_delivery_above_threshold_is_30_euros(): void
    {
        $this->seed(\Database\Seeders\DeliveryConfigSeeder::class);

        $threshold = (float) (\Smartisan\Settings\Facades\Settings::group('delivery')
            ->get('free_delivery_above', 30) ?? 30);

        $this->assertSame(30.0, $threshold,
            'Le seuil de livraison offerte doit rester 30€ (règle owner 2026-06-27).');
    }
}
