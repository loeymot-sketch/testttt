<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Coupon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [FLYER PROMO 2026-08-07 · P0 trouvé en test réel de production]
 *
 * `CouponCheckResource::amount()` — le SEUL calcul que le client voit quand il
 * saisit son code sur le site — plafonnait la remise par `maximum_discount`
 * SANS vérifier que ce plafond est renseigné :
 *
 *     if ($amount > $this->maximum_discount) return $this->maximum_discount;
 *
 * Or `maximum_discount = 0` signifie « pas de plafond » partout ailleurs dans
 * le projet (`CouponService::calculateDiscountAmount` et
 * `KioskPromoService` testent tous deux `> 0`). Résultat mesuré sur la
 * production réelle : un coupon −10 % sur un panier de 25 € renvoyait
 * `discount: 0.000000`.
 *
 * Conséquence concrète : le client scanne le QR du ticket, saisit son code,
 * lit « −0,00 € », en conclut que le code ne marche pas et abandonne — alors
 * que la COMMANDE, elle, aurait bien appliqué 2,50 € (le chemin de commit
 * passe par le service, qui est correct). Le ticket promettait −10 %, l'écran
 * disait 0.
 *
 * C'est le motif récurrent du projet : plusieurs implémentations de la même
 * règle, dont l'une diverge — ici la divergence est précisément sur la surface
 * regardée par le client. La correction supprime la duplication au lieu de la
 * rafistoler : la ressource délègue désormais au service, seul détenteur de la
 * règle.
 */
class CouponCheckDiscountAmountTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        if (!file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
        }

        config([
            'app.api_key'                 => 'test-api-key',
            'pos.coupon_codes_enabled'    => true,
            'pos.manual_discount_enabled' => false,
        ]);

        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);

        $this->branch = Branch::factory()->create();
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::withoutGlobalScopes()->create(array_merge([
            'name'             => 'Test',
            'code'             => 'CAMILLE-7K2P',
            'discount'         => 10,
            'discount_type'    => DiscountType::PERCENTAGE,
            'start_date'       => now()->subDay(),
            'end_date'         => now()->addDays(30),
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'surfaces'         => ['web'],
            'status'           => Status::ACTIVE,
        ], $overrides));
    }

    private function check(string $code = 'CAMILLE-7K2P', float $total = 25.00)
    {
        return $this->postJson('/api/frontend/coupon/coupon-checking', [
            'code'      => $code,
            'total'     => $total,
            'branch_id' => $this->branch->id,
            'surface'   => 'web',
        ]);
    }

    /**
     * LE DÉFAUT. Sans plafond renseigné, la remise doit être la remise pleine.
     */
    /** @test */
    public function test_percentage_coupon_without_cap_returns_the_full_discount(): void
    {
        $this->makeCoupon();

        $response = $this->check();
        $response->assertStatus(200);

        $this->assertEqualsWithDelta(
            2.50,
            (float) $response->json('data.discount'),
            0.01,
            'Un coupon -10% sur 25 EUR doit afficher 2,50 EUR, pas 0 — sinon le client croit que son code ne marche pas.'
        );
    }

    /**
     * Un plafond RÉELLEMENT renseigné doit toujours s'appliquer.
     */
    /** @test */
    public function test_cap_is_still_enforced_when_it_is_set(): void
    {
        $this->makeCoupon(['discount' => 50, 'maximum_discount' => 5]);

        $response = $this->check('CAMILLE-7K2P', 100.00);
        $response->assertStatus(200);

        $this->assertEqualsWithDelta(
            5.00,
            (float) $response->json('data.discount'),
            0.01,
            'Le plafond doit rester actif quand il est renseigné.'
        );
    }

    /**
     * Coupon à montant fixe sans plafond.
     */
    /** @test */
    public function test_fixed_coupon_without_cap_returns_its_amount(): void
    {
        $this->makeCoupon(['discount_type' => DiscountType::FIXED, 'discount' => 3]);

        $response = $this->check();
        $response->assertStatus(200);

        $this->assertEqualsWithDelta(3.00, (float) $response->json('data.discount'), 0.01);
    }

    /**
     * Une remise ne peut jamais dépasser le panier — sinon un total négatif.
     */
    /** @test */
    public function test_discount_never_exceeds_the_cart(): void
    {
        $this->makeCoupon(['discount_type' => DiscountType::FIXED, 'discount' => 999]);

        $response = $this->check('CAMILLE-7K2P', 12.00);
        $response->assertStatus(200);

        $this->assertLessThanOrEqual(12.00, (float) $response->json('data.discount'));
    }

    /**
     * L'écran et la commande doivent annoncer LE MÊME montant : c'est tout
     * l'enjeu de la suppression de la duplication.
     */
    /** @test */
    public function test_displayed_amount_matches_the_service_used_at_order_time(): void
    {
        $coupon = $this->makeCoupon();

        $service = app(\App\Services\CouponService::class);
        $atOrderTime = $service->calculateDiscountAmount($coupon, 25.00);

        $displayed = (float) $this->check()->json('data.discount');

        $this->assertEqualsWithDelta(
            $atOrderTime,
            $displayed,
            0.01,
            "L'écran et la commande doivent annoncer le même montant."
        );
    }
}
