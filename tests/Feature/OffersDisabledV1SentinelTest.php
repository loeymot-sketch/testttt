<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-GOLIVE-VAT10 / S1 2026-05-30] Offers module disabled for V1.
 *
 * The admin "Offres" feature displays a discount PricingService (frozen SSOT)
 * never applies → "shows X, charges Y". Until offers are wired into pricing,
 * the create/update/item-assign routes must 403 so no displayed-but-not-charged
 * promo can be created. Read routes stay open. This sentinel fails CI if the
 * guard is removed or the flag silently flips on.
 */
class OffersDisabledV1SentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function adminActor(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        return $admin;
    }

    public function test_offers_disabled_by_default_for_v1(): void
    {
        $this->assertFalse((bool) config('features.offers_enabled'), 'Offers must be OFF by default for V1');
    }

    public function test_offer_create_is_forbidden_when_disabled(): void
    {
        Config::set('features.offers_enabled', false);
        $this->actingAs($this->adminActor(), 'sanctum')
            ->postJson('/api/admin/offer', [
                'name' => 'Promo Test',
                'amount' => 20,
            ])
            ->assertForbidden();

        $this->assertSame(0, Offer::count(), 'No offer may be created while the module is disabled');
    }

    public function test_offer_item_assign_is_forbidden_when_disabled(): void
    {
        Config::set('features.offers_enabled', false);
        // Seed an offer row directly (bypassing the disabled create route) so the
        // {offer} route-model binding resolves and the feature guard — not a 404 —
        // is what blocks the item-assign (the actual mischarge trigger).
        $offer = new Offer();
        $offer->forceFill([
            'name' => 'Seeded Offer',
            'slug' => 'seeded-offer',
            'amount' => 20,
            'status' => 5,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])->save();
        $this->actingAs($this->adminActor(), 'sanctum')
            ->postJson("/api/admin/offer/item/{$offer->id}", ['item_id' => 1])
            ->assertForbidden();
    }

    public function test_offer_update_is_forbidden_when_disabled(): void
    {
        // [abuse-e2e r2 P3] OfferService::update can flip status→ACTIVE / change
        // amount — re-arming the display-vs-charge mischarge. Lock the 'update'
        // verb so a refactor dropping it from ->only() is caught by CI.
        Config::set('features.offers_enabled', false);
        $offer = new \App\Models\Offer();
        $offer->forceFill([
            'name' => 'Seeded Offer', 'slug' => 'seeded-offer-upd', 'amount' => 20,
            'status' => 5, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->save();
        $this->actingAs($this->adminActor(), 'sanctum')
            ->postJson("/api/admin/offer/{$offer->id}", ['name' => 'X', 'amount' => 30])
            ->assertForbidden();
    }

    public function test_offer_change_image_is_forbidden_when_disabled(): void
    {
        // [abuse-e2e r2 P3] changeImage is in the same guard ->only() list.
        Config::set('features.offers_enabled', false);
        $offer = new \App\Models\Offer();
        $offer->forceFill([
            'name' => 'Seeded Offer', 'slug' => 'seeded-offer-img', 'amount' => 20,
            'status' => 5, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->save();
        $this->actingAs($this->adminActor(), 'sanctum')
            ->postJson("/api/admin/offer/change-image/{$offer->id}", [])
            ->assertForbidden();
    }

    public function test_offer_index_read_still_allowed(): void
    {
        Config::set('features.offers_enabled', false);
        // Read path stays open so existing data is never orphaned.
        $this->actingAs($this->adminActor(), 'sanctum')
            ->getJson('/api/admin/offer')
            ->assertOk();
    }
}
