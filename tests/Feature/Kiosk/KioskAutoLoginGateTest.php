<?php

namespace Tests\Feature\Kiosk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [2026-05-18 PR-B P0 kiosk-creds-leak heal]
 *
 * Before the gate, `master.blade.php` injected the machine credentials
 * (`config('kiosk.spa_payload')`) into `window.foodkingConfig.kioskAutoLogin`
 * whenever the request matched `/kiosk*`, regardless of the requester's IP
 * or APP_ENV. Result: any public unauthenticated HTTP caller could harvest
 * the credentials with a single `curl https://host/kiosk/idle`.
 *
 * The gate (in `master.blade.php` @php block + `config/kiosk.php`) now
 * requires EITHER `APP_ENV=local` OR `request()->ip()` in the
 * `KIOSK_AUTO_LOGIN_TRUSTED_IPS` allowlist. Production deployment with
 * neither set returns null → SPA shows the manual login form.
 */
class KioskAutoLoginGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Force a known payload (in tests APP_ENV=testing so the local-only
        // fallback in config/kiosk.php DOES NOT auto-fill kiosk-lecayenne /
        // kiosk123 — set them explicitly via Config to control the scenarios).
        Config::set('kiosk.spa_payload', [
            'username' => 'kiosk-test-machine',
            'password' => 'test-secret-456',
        ]);
    }

    private function fetchKioskAutoLoginPayload(string $clientIp = '127.0.0.1'): ?array
    {
        // Render the master layout via a kiosk-prefixed path. The blade
        // catchall returns 200 HTML for any /kiosk/* URL.
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => $clientIp])
            ->get('/kiosk/idle');

        $response->assertStatus(200);
        $html = (string) $response->getContent();

        // Extract `kioskAutoLogin: <json>,` block (json can be `null` or `{...}`).
        if (! preg_match('/kioskAutoLogin\s*:\s*(null|\{[^}]*\})/u', $html, $m)) {
            return null;
        }
        $decoded = json_decode($m[1], true);
        return is_array($decoded) ? $decoded : null;
    }

    public function test_unauthenticated_public_ip_receives_null_payload_in_non_local_env(): void
    {
        Config::set('kiosk.auto_login_local_bypass', false);
        Config::set('kiosk.auto_login_trusted_ips', []);

        $payload = $this->fetchKioskAutoLoginPayload('203.0.113.42');
        $this->assertNull(
            $payload,
            'Public IP without local-bypass and empty allowlist must NOT receive machine creds.',
        );
    }

    public function test_local_env_bypass_serves_payload_for_dev_convenience(): void
    {
        Config::set('kiosk.auto_login_local_bypass', true);
        Config::set('kiosk.auto_login_trusted_ips', []);

        $payload = $this->fetchKioskAutoLoginPayload('127.0.0.1');
        $this->assertNotNull($payload);
        $this->assertSame('kiosk-test-machine', $payload['username'] ?? null);
        $this->assertSame('test-secret-456', $payload['password'] ?? null);
    }

    public function test_ip_in_allowlist_receives_payload_in_non_local_env(): void
    {
        Config::set('kiosk.auto_login_local_bypass', false);
        Config::set('kiosk.auto_login_trusted_ips', ['192.168.1.10', '192.168.1.11']);

        $payload = $this->fetchKioskAutoLoginPayload('192.168.1.10');
        $this->assertNotNull($payload);
        $this->assertSame('kiosk-test-machine', $payload['username'] ?? null);
    }

    public function test_ip_not_in_allowlist_blocked_in_non_local_env(): void
    {
        Config::set('kiosk.auto_login_local_bypass', false);
        Config::set('kiosk.auto_login_trusted_ips', ['192.168.1.10']);

        $payload = $this->fetchKioskAutoLoginPayload('203.0.113.42');
        $this->assertNull($payload, 'IP outside the allowlist must not receive machine creds.');
    }

    public function test_non_kiosk_path_never_receives_payload(): void
    {
        Config::set('kiosk.auto_login_local_bypass', true);
        Config::set('kiosk.auto_login_trusted_ips', []);

        // /login is a non-kiosk path — must not carry the payload even in dev.
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/login');
        $response->assertStatus(200);
        $html = (string) $response->getContent();
        preg_match('/kioskAutoLogin\s*:\s*(null|\{[^}]*\})/u', $html, $m);
        $payload = isset($m[1]) ? json_decode($m[1], true) : null;
        $this->assertSame(
            null,
            is_array($payload) ? $payload : null,
            'Non-/kiosk* paths must never inject machine credentials.',
        );
    }

    public function test_spa_payload_null_means_gate_never_emits_creds(): void
    {
        Config::set('kiosk.spa_payload', null);
        Config::set('kiosk.auto_login_local_bypass', true);
        Config::set('kiosk.auto_login_trusted_ips', ['127.0.0.1']);

        $payload = $this->fetchKioskAutoLoginPayload('127.0.0.1');
        $this->assertNull(
            $payload,
            'When operator opts out by setting KIOSK_REQUIRE_MACHINE_LOGIN=true, gate must emit null even on trusted IP.',
        );
    }

    /**
     * [BORNE-CLOUD 2026-06-27] Lien secret : ?machine_key=<KIOSK_AUTO_LOGIN_SECRET>
     * débloque l'auto-login depuis N'IMPORTE QUELLE IP (borne distante dont le
     * réseau change). Indépendant de l'allowlist IP.
     */
    public function test_matching_url_secret_serves_payload_from_any_ip(): void
    {
        Config::set('kiosk.auto_login_local_bypass', false);
        Config::set('kiosk.auto_login_trusted_ips', []);
        Config::set('kiosk.auto_login_secret', 'feat-secret-borne');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->get('/kiosk/idle?machine_key=feat-secret-borne');
        $response->assertStatus(200);
        preg_match('/kioskAutoLogin\s*:\s*(null|\{[^}]*\})/u', (string) $response->getContent(), $m);
        $payload = isset($m[1]) ? json_decode($m[1], true) : null;

        $this->assertIsArray($payload, 'URL secret valide doit servir les identifiants depuis toute IP.');
        $this->assertSame('kiosk-test-machine', $payload['username'] ?? null);
    }

    public function test_wrong_url_secret_blocked_from_untrusted_ip(): void
    {
        Config::set('kiosk.auto_login_local_bypass', false);
        Config::set('kiosk.auto_login_trusted_ips', []);
        Config::set('kiosk.auto_login_secret', 'feat-secret-borne');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.42'])
            ->get('/kiosk/idle?machine_key=MAUVAIS');
        $response->assertStatus(200);
        preg_match('/kioskAutoLogin\s*:\s*(null|\{[^}]*\})/u', (string) $response->getContent(), $m);
        $payload = isset($m[1]) ? json_decode($m[1], true) : null;

        $this->assertNull(is_array($payload) ? $payload : null, 'Mauvais secret = pas d\'identifiants.');
    }
}
