<?php

namespace Tests\Feature\Routes;

use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P9.2.8 — Rate limit `throttle:kiosk-menu` (60 req/min).
 *
 * Couvre deux niveaux d'assertion :
 *  - déclaration du middleware sur la route (`test_menu_uses_kiosk_menu_rate_limit`)
 *  - exécution runtime : 61e requête doit retourner 429
 *    (`test_limit_exceeded_returns_429`)
 */
class MenuControllerRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset du RateLimiter entre tests pour éviter les fuites d'état
        // (Illuminate\Cache\RateLimiter utilise le cache par défaut).
        Cache::flush();
        app(RateLimiter::class)->clear('kiosk-menu');
    }

    public function test_menu_uses_kiosk_menu_rate_limit(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($candidate) => $candidate->uri() === 'api/frontend/menu'
                && in_array('GET', $candidate->methods(), true));

        $this->assertNotNull($route);
        $this->assertContains('throttle:kiosk-menu', $route->gatherMiddleware());
    }

    public function test_limit_exceeded_returns_429(): void
    {
        [$user] = $this->makeKioskUser();
        Sanctum::actingAs($user, ['kiosk:order']);

        $headers = [
            'x-api-key' => config('app.api_key'),
            'Accept'    => 'application/json',
        ];

        // 60 requêtes autorisées (limit inclusif par minute, par user_id).
        // On ne vérifie pas les codes de succès (la route peut renvoyer 200
        // ou une erreur applicative selon les seeds MySQL-only) ; seul compte
        // le fait qu'elles ne soient PAS throttlées.
        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeaders($headers)->getJson('/api/frontend/menu');
            $this->assertNotSame(
                429,
                $response->status(),
                "Requête #" . ($i + 1) . " a été throttlée prématurément (limite 60/min)."
            );
        }

        // 61e requête : doit être throttlée.
        $throttled = $this->withHeaders($headers)->getJson('/api/frontend/menu');
        $this->assertSame(429, $throttled->status(), 'La 61e requête devrait déclencher le throttle:kiosk-menu.');

        // En-têtes standard Laravel pour les limites de taux.
        $this->assertNotNull(
            $throttled->headers->get('Retry-After'),
            'Retry-After doit être défini sur une réponse 429.'
        );
    }

    /**
     * Crée branch + user + kiosk machine (pattern utilisé dans les tests
     * KioskScopeIsolation/Security).
     *
     * @return array{0: User, 1: Branch, 2: KioskMachine}
     */
    private function makeKioskUser(): array
    {
        $branch = Branch::forceCreate([
            'name'     => 'Throttle Branch',
            'city'     => 'Paris',
            'state'    => 'IDF',
            'zip_code' => '75000',
            'address'  => '1 rue throttle',
            'status'   => 1,
        ]);

        $user = User::forceCreate([
            'name'      => 'Throttle Kiosk User',
            'email'     => 'throttle-kiosk@test.com',
            'username'  => 'throttle_kiosk',
            'password'  => bcrypt('password123'),
            'branch_id' => $branch->id,
        ]);

        $kiosk = KioskMachine::forceCreate([
            'branch_id'  => $branch->id,
            'user_id'    => $user->id,
            'machine_id' => 'MAC-THROTTLE-001',
            'username'   => 'throttle_kiosk',
            'password'   => bcrypt('password123'),
            'status'     => 5,
        ]);

        return [$user, $branch, $kiosk];
    }
}
