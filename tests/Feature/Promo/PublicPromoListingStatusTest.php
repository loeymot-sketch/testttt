<?php

/**
 * [TERRAIN-HEAL 2026-07-16 · OFFER-PUBLIC-STATUS / COUPON-PUBLIC-STATUS] Les listings PUBLICS d'offres et
 * de coupons doivent respecter le `status` de la gestion : une offre/coupon DÉSACTIVÉ(E) (status=INACTIVE),
 * même dans une fenêtre de dates valide, NE DOIT PLUS apparaître côté vitrine (le toggle admin était
 * inopérant avant le fix). Une offre PROGRAMMÉE (start_date future) ne doit pas fuiter en avance.
 *
 * @group promo
 */

namespace Tests\Feature\Promo;

use App\Enums\Status;
use App\Models\Coupon;
use App\Models\Offer;
use App\Services\CouponService;
use App\Http\Requests\PaginateRequest;
use App\Services\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPromoListingStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_coupon_date_wise_excludes_deactivated_coupons(): void
    {
        Coupon::factory()->create(['code' => 'ACTIVE-OK', 'status' => Status::ACTIVE, 'start_date' => now()->subDay(), 'end_date' => now()->addDays(10)]);
        Coupon::factory()->create(['code' => 'DEACT-KO', 'status' => Status::INACTIVE, 'start_date' => now()->subDay(), 'end_date' => now()->addDays(10)]);

        $codes = app(CouponService::class)->couponDateWise()->pluck('code')->all();

        $this->assertContains('ACTIVE-OK', $codes);
        $this->assertNotContains('DEACT-KO', $codes, 'Un coupon désactivé ne doit pas être listé publiquement.');
    }

    public function test_active_wise_excludes_deactivated_and_future_offers(): void
    {
        $mk = fn (string $name, int $status, $start, $end) => Offer::create([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'amount' => 10,
            'status' => $status, 'start_date' => $start, 'end_date' => $end,
        ]);

        $mk('offer-active', Status::ACTIVE, now()->subDay(), now()->addDays(10));
        $mk('offer-deact', Status::INACTIVE, now()->subDay(), now()->addDays(10));
        $mk('offer-future', Status::ACTIVE, now()->addDays(2), now()->addDays(10));

        $names = app(OfferService::class)->activeWise(PaginateRequest::create('/', 'GET'))->pluck('name')->all();

        $this->assertContains('offer-active', $names);
        $this->assertNotContains('offer-deact', $names, 'Une offre désactivée ne doit pas être listée publiquement.');
        $this->assertNotContains('offer-future', $names, 'Une offre programmée (start_date future) ne doit pas fuiter en avance.');
    }
}
