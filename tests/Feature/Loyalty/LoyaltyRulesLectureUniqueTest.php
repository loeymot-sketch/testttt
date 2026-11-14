<?php

namespace Tests\Feature\Loyalty;

use App\Services\Loyalty\LoyaltyRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [W7-D D-005 2026-11-04] `GET /api/frontend/loyalty/config` lisait cinq fois le même réglage : `effectiveFloor()`
 * relit le taux, `euroValue()` aussi… Une instance de `LoyaltyRules` vit le temps d'une requête : chaque clé est lue
 * une fois, et la valeur reste celle de la base (pas de cache inter-requêtes qui survivrait à un changement admin).
 */
class LoyaltyRulesLectureUniqueTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function chaque_reglage_est_lu_une_seule_fois_par_instance(): void
    {
        config(['settings.cache.enabled' => false]);
        Settings::group('loyalty_setup')->set(['loyalty_points_for_1_euro_discount' => 100, 'loyalty_min_redeem_points' => 1000, 'loyalty_points_per_euro' => 10]);

        $regles = new LoyaltyRules();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $regles->rate();
        $regles->effectiveFloor();
        $regles->euroValue(300);
        $regles->usablePoints(1200);
        $regles->pointsMissingBeforeUse(50);
        $regles->pointsPerEuro();

        $lectures = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'settings'))->count();
        DB::disableQueryLog();

        $this->assertSame(3, $lectures, 'trois clés distinctes = trois lectures, pas une par appel');
        $this->assertSame(1000, $regles->effectiveFloor());
        $this->assertSame(1200, $regles->usablePoints(1200));
    }

    /** @test */
    public function une_nouvelle_instance_relit_la_base(): void
    {
        config(['settings.cache.enabled' => false]);
        Settings::group('loyalty_setup')->set(['loyalty_points_for_1_euro_discount' => 100]);
        $avant = new LoyaltyRules();
        $this->assertSame(100, $avant->rate());

        Settings::group('loyalty_setup')->set(['loyalty_points_for_1_euro_discount' => 50]);

        $this->assertSame(100, $avant->rate(), 'l’instance en cours garde sa lecture (cohérence d’une requête)');
        $this->assertSame(50, (new LoyaltyRules())->rate(), 'la requête suivante voit le nouveau réglage');
    }
}
