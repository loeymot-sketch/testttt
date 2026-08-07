<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [FLYER PROMO 2026-08-07] Interrupteur DÉDIÉ aux codes promo.
 *
 * Le problème résolu : `pos.manual_discount_enabled` (défaut false) gatait
 * ensemble deux choses de nature très différentes —
 *
 *   1. la REMISE MANUELLE en caisse (un caissier saisit un montant arbitraire) ;
 *   2. le CODE PROMO (un coupon créé à l'avance, avec montant, dates, surfaces
 *      et plafond d'utilisations déjà décidés).
 *
 * Les confondre obligeait à ouvrir (1) pour obtenir (2). L'exploitant veut
 * distribuer des codes nominatifs sur ticket SANS autoriser pour autant les
 * remises libres au comptoir.
 *
 * Ces tests verrouillent les trois états qui comptent, et surtout le MIROIR
 * entre le pré-contrôle du site et la garde de commande : promettre au client
 * une remise que la commande refusera au dernier clic est le pire des deux
 * mondes (défaut déjà rencontré, cf. CouponCheckRespectsDiscountKillSwitchTest).
 */
class CouponCodesDedicatedSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);

        $this->branch = Branch::factory()->create();

        Coupon::withoutGlobalScopes()->create([
            'name'             => 'Flyer test',
            'code'             => 'CAMILLE-7K2P',
            'discount'         => 10,
            'discount_type'    => DiscountType::PERCENTAGE,
            'start_date'       => now()->subDay(),
            'end_date'         => now()->addDays(30),
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'limit_per_user'   => 1,
            'max_uses_global'  => 1,
            'surfaces'         => ['web'],
            'status'           => Status::ACTIVE,
        ]);
    }

    private function check(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/frontend/coupon/coupon-checking', [
            'code'      => 'CAMILLE-7K2P',
            'total'     => 25.00,
            'branch_id' => $this->branch->id,
            'surface'   => 'web',
        ]);
    }

    /**
     * L'état visé par l'exploitant : codes promo utilisables, remises libres
     * en caisse toujours fermées.
     */
    /** @test */
    public function test_promo_codes_work_while_manual_discounts_stay_closed(): void
    {
        config([
            'pos.coupon_codes_enabled'    => true,
            'pos.manual_discount_enabled' => false,
        ]);

        $this->check()->assertStatus(200);
    }

    /**
     * Les deux fermés : comportement historique strictement préservé.
     */
    /** @test */
    public function test_both_switches_closed_still_refuses(): void
    {
        config([
            'pos.coupon_codes_enabled'    => false,
            'pos.manual_discount_enabled' => false,
        ]);

        $this->check()
            ->assertStatus(422)
            ->assertJsonPath('status', false);
    }

    /**
     * Une installation qui ne connaît pas la nouvelle variable ne doit rien
     * voir changer : l'ancien interrupteur reste une porte d'entrée valable.
     */
    /** @test */
    public function test_legacy_switch_alone_still_opens_coupons(): void
    {
        config([
            'pos.coupon_codes_enabled'    => false,
            'pos.manual_discount_enabled' => true,
        ]);

        $this->check()->assertStatus(200);
    }

    /**
     * Le défaut d'usine reste FERMÉ : activer une remise ne doit jamais être
     * un effet de bord d'une mise à jour.
     */
    /** @test */
    public function test_new_switch_defaults_to_closed(): void
    {
        $this->assertFalse(
            (bool) config('pos.coupon_codes_enabled'),
            'Le nouvel interrupteur doit être fermé par défaut.'
        );
    }
}
