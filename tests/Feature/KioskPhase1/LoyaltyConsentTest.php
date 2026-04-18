<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\LoyaltyConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.2 : LoyaltyConsent + hash identifier RGPD.
 */
class LoyaltyConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_hash_identifier_returns_64_char_hex_sha256(): void
    {
        $hash = LoyaltyConsent::hashIdentifier('192.168.1.1');

        $this->assertSame(64, strlen($hash), 'sha256 hex = 64 caractères');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_hash_identifier_is_deterministic_with_app_key(): void
    {
        config(['app.key' => 'base64:Zm9vYmFyYmF6'.str_repeat('a', 32)]);

        $a = LoyaltyConsent::hashIdentifier('192.168.1.1');
        $b = LoyaltyConsent::hashIdentifier('192.168.1.1');

        $this->assertSame($a, $b, 'Déterministe avec la même clé');
    }

    public function test_hash_identifier_differs_with_different_inputs(): void
    {
        $a = LoyaltyConsent::hashIdentifier('192.168.1.1');
        $b = LoyaltyConsent::hashIdentifier('192.168.1.2');

        $this->assertNotSame($a, $b);
    }

    public function test_hash_identifier_empty_input_returns_placeholder(): void
    {
        $this->assertSame(str_repeat('0', 64), LoyaltyConsent::hashIdentifier(''));
        $this->assertSame(str_repeat('0', 64), LoyaltyConsent::hashIdentifier(null));
        $this->assertSame(str_repeat('0', 64), LoyaltyConsent::hashIdentifier('   '));
    }

    public function test_hash_identifier_is_not_reversible_to_ip(): void
    {
        $rawIp = '203.0.113.42';
        $hash = LoyaltyConsent::hashIdentifier($rawIp);

        $this->assertStringNotContainsString($rawIp, $hash, 'IP ne doit pas apparaître dans le hash');
        $this->assertStringNotContainsString('203.0.113', $hash);
    }
}
