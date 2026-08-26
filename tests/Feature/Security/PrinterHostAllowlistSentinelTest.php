<?php

namespace Tests\Feature\Security;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL-L2-HEAL-03 2026-05-24] Phase L7.2 L7-2-F-01 P1.
 *
 * Sentinel for the SafeRemoteHost rule wired into PrinterRequest. Guards
 * against regression of the SSRF / internal-VPC port-scan primitive that
 * was reachable through admin (and POS-Operator via testPrint) before this
 * heal. The transport target is fsockopen($host, $port) at
 * app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php:24 — admin
 * could previously set $host to 169.254.169.254 (cloud metadata),
 * 127.0.0.1 (cross-service probe), or any RFC1918 IP and the controller's
 * testPrint endpoint would happily open a TCP socket to it.
 *
 * Cases (canonical from the heal brief):
 *   1. 169.254.169.254 (AWS metadata)               → 422
 *   2. 192.168.1.5 (RFC1918, no allowlist)          → 422
 *   3. 192.168.1.5 WITH 192.168.1.0/24 allowlist    → 201/200 (created)
 *   4. 8.8.8.8 (public IP)                          → 201 (created)
 *   5. evil.com (hostname, public DNS)              → 201 (created; hostname
 *      pass-through is intentional, DNS-rebind risk deferred to V1.0.2 per
 *      SafeRemoteHost docblock)
 *
 * Suite path: tests/Feature/Security/ + filename suffix Test.php so the
 * PHPUnit Feature testsuite picks it up (phpunit.xml line 12 discovery glob).
 */
class PrinterHostAllowlistSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Sentinel = explicitly EMPTY allowlist. The PrinterControllerTest
        // class overrides this for happy-path tests, but here we verify the
        // blocklist with zero escape-hatch in play.
        config(['security.safe_remote_host_allowlist' => []]);

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->user->assignRole('Admin');
    }

    /**
     * @testdox Case 1 — AWS / GCE / Azure cloud metadata IP (169.254.169.254)
     * is rejected with 422. This is the canonical SSRF target that would
     * leak instance credentials in any cloud deployment.
     */
    public function test_rejects_cloud_metadata_ip(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerPayload(['host' => '169.254.169.254'])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['host']);
    }

    /**
     * @testdox Case 2 — RFC1918 192.168.1.5 is rejected with 422 when no
     * allowlist entry covers it. Default deny is the V1 LOCAL shipping
     * posture.
     */
    public function test_rejects_rfc1918_without_allowlist(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerPayload(['host' => '192.168.1.5'])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['host']);
    }

    /**
     * @testdox Case 3 — Same RFC1918 192.168.1.5 is accepted when the
     * 192.168.1.0/24 LAN subnet is in the allowlist. Verifies the escape
     * hatch owner uses for a legit LAN-hosted ESC/POS printer.
     */
    public function test_accepts_rfc1918_with_allowlist(): void
    {
        // [FIX 2026-08-25] Format host+port exigé depuis le durcissement de `SafeRemoteHost` :
        // un CIDR nu ouvrirait les 65535 ports d'un sous-réseau privé. Ce cas VÉRIFIE justement
        // qu'une entrée d'allowlist bien formée autorise l'imprimante — il doit donc utiliser le
        // format que le produit attend, pas celui d'avant.
        config(['security.safe_remote_host_allowlist' => ['192.168.1.0/24:9100-9103']]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerPayload(['host' => '192.168.1.5', 'name' => 'LAN Printer'])
        );

        $response->assertCreated()
            ->assertJsonPath('data.host', '192.168.1.5');
    }

    /**
     * @testdox Case 4 — Public IPv4 (8.8.8.8) is accepted. The rule only
     * blocks dangerous ranges; public space is fine.
     */
    public function test_accepts_public_ipv4(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerPayload(['host' => '8.8.8.8', 'name' => 'Public IP Printer'])
        );

        $response->assertCreated()
            ->assertJsonPath('data.host', '8.8.8.8');
    }

    /**
     * @testdox Case 5 — Public hostname (evil.com) is accepted. The rule
     * intentionally passes hostnames through; DNS-rebind into RFC1918 is a
     * known residual risk documented for V1.0.2 in the SafeRemoteHost
     * docblock. This case locks in the current contract.
     */
    public function test_accepts_public_hostname(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerPayload(['host' => 'evil.com', 'name' => 'Hostname Printer'])
        );

        $response->assertCreated()
            ->assertJsonPath('data.host', 'evil.com');
    }

    /**
     * @testdox Case 6 — Loopback 127.0.0.1 is rejected with 422 (no
     * allowlist). Closes the local-service probe primitive.
     */
    public function test_rejects_loopback(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            '/api/admin/printers',
            $this->printerPayload(['host' => '127.0.0.1'])
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['host']);
    }

    private function printerPayload(array $overrides = []): array
    {
        return array_merge([
            'name'        => 'Sentinel Printer ' . uniqid(),
            'type'        => 'escpos_tcp',
            'host'        => '8.8.8.8',
            'port'        => 9100,
            'station'     => 'receipt',
            'width_chars' => 48,
            'status'      => 1,
            'options'     => ['cut' => true],
        ], $overrides);
    }
}
