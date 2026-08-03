<?php

namespace Tests\Feature\DailyBook;

use App\Http\Middleware\EnsureDailyBookPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W4] Auth PIN du Carnet : bon PIN déverrouille,
 * mauvais PIN 401, throttle 5/min, routes protégées verrouillées sans session.
 */
class DailyBookPinAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['daily_book.pin' => '2468']);
    }

    public function test_wrong_pin_is_rejected(): void
    {
        $this->postJson('/carnet/api/pin', ['pin' => '0000'])
            ->assertStatus(401);
    }

    public function test_correct_pin_unlocks_session(): void
    {
        $this->postJson('/carnet/api/pin', ['pin' => '2468'])
            ->assertOk()
            ->assertJson(['unlocked' => true]);

        $this->getJson('/carnet/api/entries')->assertOk();
    }

    public function test_protected_routes_locked_without_pin(): void
    {
        $this->getJson('/carnet/api/entries')->assertStatus(401);
        $this->postJson('/carnet/api/entries', [])->assertStatus(401);
        $this->getJson('/carnet/api/summary/month?month=2026-07')->assertStatus(401);
    }

    public function test_pin_route_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/carnet/api/pin', ['pin' => '0000'])->assertStatus(401);
        }

        $this->postJson('/carnet/api/pin', ['pin' => '0000'])->assertStatus(429);
    }

    /**
     * [S2 2026-07-29] Fail-closed : PIN non configuré (DAILY_BOOK_PIN absent)
     * = aucun déverrouillage possible — miroir de MobileStockAuthController.
     */
    public function test_unconfigured_pin_is_fail_closed(): void
    {
        config(['daily_book.pin' => '']);

        $this->postJson('/carnet/api/pin', ['pin' => ''])->assertStatus(403);
        $this->postJson('/carnet/api/pin', ['pin' => '2468'])->assertStatus(403);
        $this->getJson('/carnet/api/status')->assertOk()->assertJson(['unlocked' => false]);
        // 403 « non configuré » et non 401 « PIN requis » : le middleware distingue
        // désormais l'absence de configuration de l'absence de session.
        $this->getJson('/carnet/api/entries')->assertStatus(403);
    }

    /**
     * [S2 auto-RED cycle 2 2026-07-29] Le fail-closed doit couper AUSSI les
     * sessions déjà ouvertes. L'ancien PIN étant le défaut commité '2468'
     * (public dans le dépôt), une session ouverte avec lui survivrait sinon
     * indéfiniment au correctif — la session est glissante.
     */
    public function test_unconfigured_pin_kills_already_open_sessions(): void
    {
        // Session légitimement ouverte avec le PIN d'alors.
        $this->postJson('/carnet/api/pin', ['pin' => '2468'])->assertOk();
        $this->getJson('/carnet/api/entries')->assertOk();

        // Le PIN est retiré de la configuration : tout se referme, séance tenante.
        config(['daily_book.pin' => '']);

        $this->getJson('/carnet/api/entries')->assertStatus(403);
        $this->postJson('/carnet/api/entries', [])->assertStatus(403);
        $this->getJson('/carnet/api/summary/month?month=2026-07')->assertStatus(403);
    }

    public function test_expired_session_relocks(): void
    {
        $this->postJson('/carnet/api/pin', ['pin' => '2468'])->assertOk();

        // Simule une session plus vieille que la fenêtre autorisée.
        $expired = time() - (((int) config('daily_book.session_minutes', 240)) * 60 + 61);
        $this->withSession([EnsureDailyBookPin::SESSION_KEY => $expired])
            ->getJson('/carnet/api/entries')
            ->assertStatus(401);
    }
}
