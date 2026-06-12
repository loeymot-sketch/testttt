<?php

namespace Tests\Feature\Loyalty;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [GOAL LOYALTY_UNIFIED_SYNC L1 2026-06-11 — F-LOY-1/D11]
 *
 * Barème UNIQUE vivant en DB (settings group loyalty_setup), seedé par la
 * commande `foodking:set-loyalty-rates`. Les 3 chemins de lecture historiques
 * portaient des fallbacks hard-codés divergents (10/10/10 + min 50) — alignés
 * sur le canon owner D11 : 1 pt/€ (round), 100 pts = 1 €, min 100.
 */
class LoyaltyRateSsotTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_seeds_the_three_rates(): void
    {
        Artisan::call('foodking:set-loyalty-rates', [
            'per_euro' => 2, 'per_discount' => 200, 'min' => 150,
        ]);

        $this->assertSame(2, (int) Settings::group('loyalty_setup')->get('loyalty_points_per_euro'));
        $this->assertSame(200, (int) Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount'));
        $this->assertSame(150, (int) Settings::group('loyalty_setup')->get('loyalty_min_redeem_points'));
    }

    public function test_config_api_reads_seeded_value_and_canonical_fallback(): void
    {
        // Fallback (no seed): canonical D11 = 1 pt/€, min 100.
        $response = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->getJson('/api/frontend/loyalty/config');
        $response->assertSuccessful();
        $this->assertSame(1, (int) $response->json('data.points_per_euro'));
        $this->assertSame(100, (int) $response->json('data.min_redeem_points'));

        // Seeded value wins.
        Artisan::call('foodking:set-loyalty-rates', ['per_euro' => 3, 'per_discount' => 100, 'min' => 100]);
        $seeded = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->getJson('/api/frontend/loyalty/config');
        $this->assertSame(3, (int) $seeded->json('data.points_per_euro'));
    }

    public function test_earn_listener_uses_canonical_rate_and_round(): void
    {
        // No seed → canonical 1 pt/€ with ROUND (0.90 € must earn 1 pt,
        // matching the mobile/web clients — divergence floor/round killed).
        $listener = new \ReflectionClass(\App\Listeners\AwardLoyaltyPointsOnDelivery::class);
        $src = file_get_contents($listener->getFileName());

        $this->assertStringContainsString("get('loyalty_points_per_euro', 1)", $src, 'listener fallback must be canonical 1');
        $this->assertStringContainsString('(int) round($orderTotal * $rate)', $src, 'earn rounding must be round (client parity)');
        $this->assertStringNotContainsString('floor($orderTotal * $rate)', $src);
    }

    public function test_welcome_bonus_awarded_once_at_registration(): void
    {
        $this->seedMinimalSettings();

        $payload = ['phone' => '0699887766', 'name' => 'E2E Welcome'];
        $first = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson('/api/frontend/loyalty/register', $payload);
        $first->assertSuccessful();
        $this->assertSame(25, (int) $first->json('data.points'), 'welcome bonus (default 25) at creation');

        $user = User::where('phone', '0699887766')->first();
        $this->assertSame(25, (int) $user->loyalty_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $user->id, 'type' => 'earn', 'points' => 25,
        ]);

        // Re-register same phone: NO second bonus (idempotent by construction).
        $second = $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson('/api/frontend/loyalty/register', $payload);
        $second->assertSuccessful();
        $this->assertSame(25, (int) $user->fresh()->loyalty_points);
    }
}
