<?php

namespace Tests\Feature\Coupon;

use App\Models\Branch;
use App\Models\Coupon;
use App\Models\StorefrontBanner;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Delivery\DeliveryAvailability;
use App\Services\Storefront\StorefrontService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL LIVRAISON-TICKETS 2026-11-10 · T-3.3.2] Fin d'offre à l'heure dite : à la minute où le code expire, la vitrine ne le
 * promet plus (bandeau sans code, relecture programmée exactement à la fin) et le paiement le refuse en français lisible,
 * sans paramètre brut. Juste avant, les deux l'acceptent. Aucun prix n'est figé côté client : c'est le serveur qui tranche.
 */
class CouponExpireALaMinuteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456', 'pos.coupon_codes_enabled' => true]);
        Branch::factory()->create(['id' => 1]);
        app(DeliveryAvailability::class)->set(true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->setLocale(config('app.locale'));
        parent::tearDown();
    }

    private function coupon(): Coupon
    {
        return Coupon::query()->forceCreate(['name' => 'Soirée', 'code' => 'SOIREE', 'discount' => 10, 'discount_type' => 10, 'minimum_order' => 0,
            'maximum_discount' => 0, 'limit_per_user' => 5, 'status' => 5, 'start_date' => '2026-12-01 00:00:00', 'end_date' => '2026-12-24 18:00:00']);
    }

    /**
     * Contrat relu dans le code (CouponService:492, Coupon::isUsableNow:112) : la fin est INCLUSE à la seconde (`now > end`
     * refuse) — à 18:00:00 pile le code passe encore, à 18:00:01 plus. La vitrine suit la même règle (`end < now`) et
     * programme la relecture à 18:00:00 + 1,5 s : les deux côtés basculent au même instant.
     */
    public function test_le_paiement_accepte_jusqu_a_la_fin_incluse_et_refuse_la_seconde_suivante_en_francais(): void
    {
        $this->coupon();
        $user = User::factory()->create();
        app()->setLocale('fr');

        Carbon::setTestNow(Carbon::parse('2026-12-24 18:00:00', config('app.timezone')));
        $c = app(CouponService::class)->resolveCouponByCode('SOIREE', 20.0, $user->id);
        $this->assertSame('SOIREE', strtoupper((string) $c->code));

        Carbon::setTestNow(Carbon::parse('2026-12-24 18:00:01', config('app.timezone')));
        try {
            app(CouponService::class)->resolveCouponByCode('SOIREE', 20.0, $user->id);
            $this->fail('un code terminé doit être refusé à la minute de sa fin');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertNotSame('', trim($e->getMessage()));
            $this->assertStringNotContainsString(':date', $e->getMessage());
            $this->assertStringNotContainsString('coupon.', $e->getMessage(), 'clé de traduction brute');
        }
    }

    public function test_la_vitrine_cesse_de_promettre_le_code_a_la_minute_et_relit_a_cette_heure(): void
    {
        $this->coupon();
        StorefrontSetting::query()->create(['branch_id' => 1, 'enabled' => true, 'theme' => null]);
        StorefrontBanner::query()->create(['branch_id' => 1, 'title' => 'Soirée −10 %', 'type' => 'flash', 'priority' => 1, 'active' => true,
            'coupon_code' => 'SOIREE', 'ends_at' => Carbon::parse('2026-12-24 23:00', 'Europe/Paris')]);
        app(StorefrontService::class)->publish(1, null, 0);

        Carbon::setTestNow(Carbon::parse('2026-12-24 18:00:00', 'Europe/Paris'));
        $this->withHeaders(['x-api-key' => '123456'])->getJson('/api/frontend/storefront')->assertOk()
            ->assertJsonPath('banners.0.coupon_code', 'SOIREE')
            ->assertJsonPath('next_change_at', '2026-12-24T18:00:00+01:00');

        Carbon::setTestNow(Carbon::parse('2026-12-24 18:00:01', 'Europe/Paris'));
        $this->withHeaders(['x-api-key' => '123456'])->getJson('/api/frontend/storefront')->assertOk()
            ->assertJsonPath('banners.0.title', 'Soirée −10 %')
            ->assertJsonPath('banners.0.coupon_code', null)
            ->assertJsonPath('next_change_at', '2026-12-24T23:00:00+01:00');
    }
}
