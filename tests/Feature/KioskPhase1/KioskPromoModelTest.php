<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\Branch;
use App\Models\KioskPromo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.2 : modèle KioskPromo.
 */
class KioskPromoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_valid_matches_active_in_window(): void
    {
        $branch = $this->makeBranch();
        KioskPromo::create([
            'branch_id' => $branch->id,
            'code' => 'BIENVENUE10',
            'type' => 'percent',
            'value' => 10.0,
            'min_cart' => 0,
            'valid_from' => now()->subDay(),
            'valid_to'   => now()->addDay(),
            'active' => true,
        ]);

        $promo = KioskPromo::findValid($branch->id, 'BIENVENUE10', 15.0);
        $this->assertNotNull($promo);
        $this->assertSame('BIENVENUE10', $promo->code);
    }

    public function test_find_valid_rejects_inactive(): void
    {
        $branch = $this->makeBranch();
        KioskPromo::create([
            'branch_id' => $branch->id,
            'code' => 'X', 'type' => 'amount', 'value' => 5,
            'active' => false,
        ]);

        $this->assertNull(KioskPromo::findValid($branch->id, 'X', 100));
    }

    public function test_find_valid_rejects_below_min_cart(): void
    {
        $branch = $this->makeBranch();
        KioskPromo::create([
            'branch_id' => $branch->id,
            'code' => 'BIG', 'type' => 'percent', 'value' => 20,
            'min_cart' => 30, 'active' => true,
        ]);

        $this->assertNull(KioskPromo::findValid($branch->id, 'BIG', 20));
        $this->assertNotNull(KioskPromo::findValid($branch->id, 'BIG', 50));
    }

    public function test_find_valid_rejects_when_max_uses_reached(): void
    {
        $branch = $this->makeBranch();
        KioskPromo::create([
            'branch_id' => $branch->id,
            'code' => 'LIMITED', 'type' => 'amount', 'value' => 2,
            'max_uses' => 5, 'uses_count' => 5,
            'active' => true,
        ]);

        $this->assertNull(KioskPromo::findValid($branch->id, 'LIMITED', 20));
    }

    public function test_compute_discount_percent(): void
    {
        $promo = new KioskPromo(['type' => 'percent', 'value' => 10.0]);
        $this->assertEquals(2.50, $promo->computeDiscount(25.0));
    }

    public function test_compute_discount_amount_capped_at_cart_total(): void
    {
        $promo = new KioskPromo(['type' => 'amount', 'value' => 100.0]);
        $this->assertEquals(25.0, $promo->computeDiscount(25.0), 'Discount ne doit jamais dépasser le cart total.');
    }

    public function test_compute_discount_zero_for_empty_cart(): void
    {
        $promo = new KioskPromo(['type' => 'percent', 'value' => 50.0]);
        $this->assertEquals(0.0, $promo->computeDiscount(0.0));
    }

    public function test_branch_isolation_unique_constraint(): void
    {
        $b1 = $this->makeBranch('A');
        $b2 = $this->makeBranch('B');

        KioskPromo::create(['branch_id' => $b1->id, 'code' => 'SAME', 'type' => 'amount', 'value' => 1, 'active' => true]);
        KioskPromo::create(['branch_id' => $b2->id, 'code' => 'SAME', 'type' => 'amount', 'value' => 2, 'active' => true]);

        $this->assertSame(2, KioskPromo::where('code', 'SAME')->count(), 'Même code autorisé sur 2 branches différentes.');
    }

    private function makeBranch(string $name = 'Main'): Branch
    {
        return Branch::forceCreate([
            'name' => $name, 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '1 rue test', 'status' => 1,
        ]);
    }
}
