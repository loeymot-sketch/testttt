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
