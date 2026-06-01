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
 * Owner business rules (verbatim, 2026-06-01):
 *   - Restaurant is at 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont
 *     (geocoded rooftop: lat 50.4215667, lng 2.9549060) — NOT Paris.
 *   - Delivery fee: 5 € for distance ≤ 5 km (straight-line / à vol d'oiseau),
 *     +1 € per additional km. Continuous reading ≡ max(5, distance_km).
 *
 * The existing DeliveryFeeService formula `max(minimum, base + per_km * distance)`
 * reproduces the owner rule EXACTLY with base=0 / per_km=1 / minimum=5 — no code
 * or migration change. This sentinel locks (a) the formula↔rule mapping and
 * (b) the seeded branch origin + fee config so a future migrate:fresh keeps it.
 *
 * Pre-heal defects this guards against:
 *   - DEL-ORIGIN-01: seeded branch was Paris (48.8566/2.3522) → every delivery
 *     distance/fee computed from the wrong city.
 *   - DEL-FEE-01: seeded branch fee config was NULL (legacy ceil(d/5)*5 fallback,
 *     giving fee(8km)=10 €) — not the owner's +1€/km (fee(8km)=8 €).
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
     */
    public function test_owner_delivery_rule_is_5eur_base_plus_1eur_per_km(): void
    {
        $branch = Branch::factory()->create([
            'delivery_fee_base'    => 0.00,
            'delivery_fee_per_km'  => 1.00,
            'delivery_fee_minimum' => 5.00,
        ]);
        $service = new DeliveryFeeService();

        // ≤ 5 km → flat 5 €
        $this->assertSame(5.0, $service->fromDistanceKm(0, $branch));
        $this->assertSame(5.0, $service->fromDistanceKm(3, $branch));
        $this->assertSame(5.0, $service->fromDistanceKm(5, $branch));
        // > 5 km → +1 €/km (continuous): fee = distance
        $this->assertSame(8.0, $service->fromDistanceKm(8, $branch));
        $this->assertSame(10.0, $service->fromDistanceKm(10, $branch));
        $this->assertSame(12.5, $service->fromDistanceKm(12.5, $branch));
        // just over the free radius
        $this->assertSame(6.0, $service->fromDistanceKm(6, $branch));
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

        // Fee config encodes the owner rule: base=0, per_km=1, minimum=5.
        $this->assertSame(0.0, (float) $branch->delivery_fee_base);
        $this->assertSame(1.0, (float) $branch->delivery_fee_per_km);
        $this->assertSame(5.0, (float) $branch->delivery_fee_minimum);

        // End-to-end: the seeded branch yields the owner schedule.
        $service = new DeliveryFeeService();
        $this->assertSame(5.0, $service->fromDistanceKm(4, $branch));
        $this->assertSame(8.0, $service->fromDistanceKm(8, $branch));
    }
}
