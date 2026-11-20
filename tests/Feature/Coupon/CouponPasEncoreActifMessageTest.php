<?php

namespace Tests\Feature\Coupon;

use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W6 cycle G · G1 2026-11-03] Un code qui commence plus tard (désormais préparable la veille dans la vitrine, F1) renvoyait
 * au client « Le coupon n'est pas encore actif (valide à partir du :date) » : le paramètre :date n'était jamais passé.
 */
class CouponPasEncoreActifMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->setLocale(config('app.locale'));
        parent::tearDown();
    }

    public function test_le_message_dit_la_date_de_debut_jamais_le_parametre_brut(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00', config('app.timezone')));
        $user = User::factory()->create();
        Coupon::query()->forceCreate(['name' => 'Demain', 'code' => 'DEMAIN', 'discount' => 5, 'discount_type' => 10, 'minimum_order' => 0,
            'maximum_discount' => 0, 'limit_per_user' => 1, 'status' => 5, 'start_date' => '2026-12-02 00:00:00', 'end_date' => '2026-12-31 23:59:59']);

        foreach (['fr', 'en'] as $langue) {
            app()->setLocale($langue);
            try {
                app(CouponService::class)->resolveCouponByCode('DEMAIN', 20.0, $user->id);
                $this->fail('un code qui commence demain doit être refusé');
            } catch (\Exception $e) {
                $this->assertSame(422, $e->getCode());
                $this->assertStringNotContainsString(':date', $e->getMessage(), $langue);
                $this->assertStringContainsString('02/12/2026', $e->getMessage(), $langue);
            }
        }
    }
}
