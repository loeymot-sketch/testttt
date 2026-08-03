<?php

namespace Tests\Unit;

use App\Support\KioskAutoLoginGate;
use Tests\TestCase;

/**
 * [BORNE-CLOUD 2026-06-27] Le gate auto-login borne (master.blade.php) faisait
 * un exact-match `in_array` sur KIOSK_AUTO_LOGIN_TRUSTED_IPS. Pour une borne
 * CLOUD (serveur OVH, borne distante) l'IP cliente est l'IPv6 publique de la
 * borne — qui TOURNE (privacy extensions : ...952f... puis ...6931...) tout en
 * gardant un /64 stable (2a04:cec0:11f0:aaa7::/64, 16517 hits prouvés en logs).
 * On passe le matching en CIDR (IpUtils) pour faire confiance au /64 → la borne
 * s'auto-connecte de façon robuste malgré la rotation d'adresse. Logique
 * extraite en helper pur = testable sans DB (DB-safe).
 */
class KioskAutoLoginGateResolverTest extends TestCase
{
    private array $payload = ['username' => 'kiosk-lecayenne', 'password' => 'kiosk123'];

    public function test_non_kiosk_path_never_emits_payload(): void
    {
        $this->assertNull(KioskAutoLoginGate::resolvePayload($this->payload, false, true, ['127.0.0.1'], '127.0.0.1'));
    }

    public function test_null_payload_means_no_creds_even_on_trusted_ip(): void
    {
        $this->assertNull(KioskAutoLoginGate::resolvePayload(null, true, false, ['127.0.0.1'], '127.0.0.1'));
    }

    public function test_local_bypass_emits_payload(): void
    {
        $this->assertSame($this->payload, KioskAutoLoginGate::resolvePayload($this->payload, true, true, [], '203.0.113.9'));
    }

    public function test_exact_ipv4_in_allowlist_emits_payload(): void
    {
        $this->assertSame($this->payload, KioskAutoLoginGate::resolvePayload($this->payload, true, false, ['192.168.1.10', '192.168.1.11'], '192.168.1.10'));
    }

    public function test_ipv4_outside_allowlist_blocked(): void
    {
        $this->assertNull(KioskAutoLoginGate::resolvePayload($this->payload, true, false, ['192.168.1.10'], '203.0.113.42'));
    }

    public function test_empty_allowlist_without_bypass_blocked(): void
    {
        $this->assertNull(KioskAutoLoginGate::resolvePayload($this->payload, true, false, [], '203.0.113.42'));
    }

    /** Le cas réel borne Le Cayenne : IPv6 rotatif dans le /64 de confiance. */
    public function test_ipv6_inside_trusted_cidr_prefix_emits_payload(): void
    {
        $cidr = ['2a04:cec0:11f0:aaa7::/64'];
        // Deux adresses rotatées DIFFÉRENTES, toutes deux dans le /64 → confiance.
        $this->assertSame($this->payload, KioskAutoLoginGate::resolvePayload($this->payload, true, false, $cidr, '2a04:cec0:11f0:aaa7:6931:22d6:c78c:7e5a'));
        $this->assertSame($this->payload, KioskAutoLoginGate::resolvePayload($this->payload, true, false, $cidr, '2a04:cec0:11f0:aaa7:952f:2e8b:7c51:14fc'));
    }

    public function test_ipv6_outside_trusted_cidr_prefix_blocked(): void
    {
        $cidr = ['2a04:cec0:11f0:aaa7::/64'];
        // /64 différent (préfixe étranger) → refusé.
        $this->assertNull(KioskAutoLoginGate::resolvePayload($this->payload, true, false, $cidr, '2a04:dead:beef:0001::1'));
    }

    public function test_blank_and_whitespace_entries_are_ignored(): void
    {
        // Entrées vides/espaces (CSV mal formé) ne doivent pas faire matcher.
        $this->assertNull(KioskAutoLoginGate::resolvePayload($this->payload, true, false, ['', '  '], '203.0.113.42'));
    }

    public function test_null_client_ip_blocked(): void
    {
        $this->assertNull(KioskAutoLoginGate::resolvePayload($this->payload, true, false, ['192.168.1.10'], null));
    }

    // --- Lien secret (réseau-indépendant : survit au changement d'IP/box/fibre) ---

    /** Le secret marche depuis N'IMPORTE QUELLE IP, sans allowlist. */
    public function test_matching_url_secret_emits_payload_regardless_of_ip(): void
    {
        $this->assertSame(
            $this->payload,
            KioskAutoLoginGate::resolvePayload($this->payload, true, false, [], '203.0.113.99', 'S3CR3T-borne', 'S3CR3T-borne')
        );
    }

    public function test_wrong_url_secret_blocked(): void
    {
        $this->assertNull(
            KioskAutoLoginGate::resolvePayload($this->payload, true, false, [], '203.0.113.99', 'mauvais', 'S3CR3T-borne')
        );
    }

    /** Aucun secret configuré ⇒ fournir un secret ne débloque PAS (pas de bypass vide). */
    public function test_empty_configured_secret_keeps_secret_path_inactive(): void
    {
        $this->assertNull(
            KioskAutoLoginGate::resolvePayload($this->payload, true, false, [], '203.0.113.99', 'whatever', '')
        );
        // garde anti-régression : secret fourni vide vs configuré non-vide ⇒ refus
        $this->assertNull(
            KioskAutoLoginGate::resolvePayload($this->payload, true, false, [], '203.0.113.99', '', 'S3CR3T-borne')
        );
    }

    public function test_secret_still_requires_kiosk_path(): void
    {
        $this->assertNull(
            KioskAutoLoginGate::resolvePayload($this->payload, false, false, [], '203.0.113.99', 'S3CR3T-borne', 'S3CR3T-borne')
        );
    }

    public function test_secret_still_blocked_when_payload_null(): void
    {
        $this->assertNull(
            KioskAutoLoginGate::resolvePayload(null, true, false, [], '203.0.113.99', 'S3CR3T-borne', 'S3CR3T-borne')
        );
    }
}
