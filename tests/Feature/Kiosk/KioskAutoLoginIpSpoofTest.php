<?php

namespace Tests\Feature\Kiosk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [2026-06-07 HEAL-H2 SEC-XFF-01]
 *
 * Companion to KioskAutoLoginGateTest. The original gate compared
 * `request()->ip()` against KIOSK_AUTO_LOGIN_TRUSTED_IPS. With
 * `TrustProxies::$proxies = '*'` (Wave 3 SYNC-ADV3-01), `request()->ip()`
 * honoured the client-supplied `X-Forwarded-For` header — so a remote
 * attacker could send `X-Forwarded-For: <trusted kiosk LAN IP>` and harvest
 * the machine `spa_payload` (kiosk username/password) injected into
 * `window.foodkingConfig.kioskAutoLogin`, then mint a `kiosk:order` token.
 *
 * The fix narrows the trusted-proxy list to loopback only
 * (`['127.0.0.1','::1']`). Symfony then ignores forwarded headers whenever
 * the real connection (REMOTE_ADDR) is NOT a trusted proxy, so
 * `request()->ip()` resolves to the authentic REMOTE_ADDR for every direct
 * (fastcgi) client. A genuine on-box / loopback reverse proxy still resolves
 * the real client IP from XFF.
 *
 * These scenarios were NOT covered by KioskAutoLoginGateTest (which only
 * sets REMOTE_ADDR directly, never an attacker XFF).
 */
class KioskAutoLoginIpSpoofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('kiosk.spa_payload', [
            'username' => 'kiosk-test-machine',
            'password' => 'test-secret-456',
        ]);
        // Production-like: no local bypass, a real LAN allowlist.
        Config::set('kiosk.auto_login_local_bypass', false);
        Config::set('kiosk.auto_login_trusted_ips', ['192.168.1.10']);
    }

    /**
     * @param  array<string,string>  $server
     */
    private function fetchKioskAutoLoginPayload(array $server): ?array
    {
        $response = $this->withServerVariables($server)->get('/kiosk/idle');
        $response->assertStatus(200);
        $html = (string) $response->getContent();

        if (! preg_match('/kioskAutoLogin\s*:\s*(null|\{[^}]*\})/u', $html, $m)) {
            return null;
        }
        $decoded = json_decode($m[1], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * THE FIX: a remote attacker spoofing a trusted LAN IP via X-Forwarded-For
     * must NOT receive the machine credentials. REMOTE_ADDR is an untrusted
     * external host, so the forwarded header must be ignored.
     */
    public function test_xff_spoof_from_untrusted_remote_addr_is_blocked(): void
    {
        $payload = $this->fetchKioskAutoLoginPayload([
            'REMOTE_ADDR'         => '203.0.113.66',   // real attacker peer (untrusted)
            'HTTP_X_FORWARDED_FOR' => '192.168.1.10',  // spoofed trusted kiosk IP
        ]);

        $this->assertNull(
            $payload,
            'X-Forwarded-For spoof from an untrusted REMOTE_ADDR must not leak machine creds (SEC-XFF-01).',
        );
    }

    /**
     * A legitimate kiosk hitting fastcgi directly (its real LAN IP in
     * REMOTE_ADDR) must still receive the payload, and a junk XFF must not be
     * able to downgrade/break that legitimate hit.
     */
    public function test_legit_kiosk_real_remote_addr_still_served_even_with_junk_xff(): void
    {
        $payload = $this->fetchKioskAutoLoginPayload([
            'REMOTE_ADDR'         => '192.168.1.10',  // genuine kiosk peer
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',     // noise / attacker header
        ]);

        $this->assertNotNull($payload, 'Legitimate kiosk (real REMOTE_ADDR) must still receive creds.');
        $this->assertSame('kiosk-test-machine', $payload['username'] ?? null);
    }

    /**
     * Forward-compat: a genuine on-box loopback reverse proxy (REMOTE_ADDR =
     * 127.0.0.1, trusted) must resolve the real client IP from XFF so a real
     * kiosk behind it still matches the allowlist.
     */
    public function test_trusted_loopback_proxy_resolves_real_client_ip_from_xff(): void
    {
        $payload = $this->fetchKioskAutoLoginPayload([
            'REMOTE_ADDR'         => '127.0.0.1',      // trusted local reverse proxy
            'HTTP_X_FORWARDED_FOR' => '192.168.1.10',  // real kiosk client IP forwarded by proxy
        ]);

        $this->assertNotNull(
            $payload,
            'A trusted loopback proxy must resolve the real client IP from XFF (forward-compat).',
        );
        $this->assertSame('kiosk-test-machine', $payload['username'] ?? null);
    }
}
