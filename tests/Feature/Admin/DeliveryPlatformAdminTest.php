<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.4 / Phase 4]
 *
 * Verifies the admin surface for the per-branch delivery platform
 * configuration: list, update (with credentials merge), toggle,
 * webhook URL exposure, and the dedicated reveal endpoint with its
 * mandatory audit row.
 *
 * The tests deliberately use a head-office Admin (branch_id = 0)
 * to confirm the controller bypasses BranchScope on every per-row
 * action — a non-zero branch_id Admin would otherwise 404 cross-
 * branch rows.
 */
class DeliveryPlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $this->branch = Branch::factory()->create();

        // Head-office Admin: branch_id=0 → BranchScope no-ops, but
        // we still rely on `withoutGlobalScopes()` to confirm the
        // route handler resolves any branch's row.
        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
    }

    public function test_admin_can_list_delivery_platforms(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_xyz',
            'credentials'       => [
                'client_id'      => 'CID-1234567890',
                'client_secret'  => 'SECRET-PUBLICVISIBLE_LAST4=AAAA',
                'webhook_secret' => 'wh_secret_ZZZZ',
            ],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson('/api/admin/delivery-platforms');

        $response->assertOk();
        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload);

        $row = $payload[0];
        $this->assertSame($platform->id, (int) $row['id']);
        $this->assertSame('uber_eats', $row['platform']);
        $this->assertTrue($row['enabled']);

        // Credentials must be masked, never plaintext.
        $this->assertArrayHasKey('credentials_masked', $row);
        $this->assertNotSame('CID-1234567890', $row['credentials_masked']['client_id']);
        $this->assertStringEndsWith('7890', $row['credentials_masked']['client_id']);
        $this->assertTrue($row['credentials_masked']['client_id_set']);
    }

    public function test_admin_can_update_credentials_audit_logged(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'deliveroo',
            'enabled'           => false,
            'external_store_id' => 'rest_old',
            'credentials'       => [
                'client_id'      => 'OLD_CID',
                'client_secret'  => 'OLD_SECRET',
                'webhook_secret' => 'OLD_WH',
            ],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->putJson("/api/admin/delivery-platforms/{$platform->id}", [
            'enabled'           => true,
            'external_store_id' => 'rest_new',
            'credentials'       => [
                'webhook_secret' => 'NEW_WH_SECRET',
            ],
        ]);

        $response->assertOk();

        $platform->refresh();
        $this->assertTrue((bool) $platform->enabled);
        $this->assertSame('rest_new', $platform->external_store_id);

        // Other credentials must remain (merge, not overwrite).
        $creds = $platform->credentials;
        $this->assertSame('OLD_CID', $creds['client_id']);
        $this->assertSame('OLD_SECRET', $creds['client_secret']);
        $this->assertSame('NEW_WH_SECRET', $creds['webhook_secret']);

        // Rotation timestamp updated when webhook_secret changed.
        $this->assertNotNull($platform->webhook_secret_rotated_at);

        // Audit row must exist with the redacted diff (key names only).
        $audit = AuditLog::where('action', 'delivery.platform.updated')
            ->where('resource', 'delivery_platform')
            ->where('resource_id', $platform->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit, 'expected delivery.platform.updated audit row');

        $payload = $audit->payload;
        $this->assertSame('deliveroo', $payload['platform']);
        $this->assertArrayHasKey('changed', $payload);
        $this->assertSame(
            ['from' => false, 'to' => true],
            $payload['changed']['enabled']
        );
        $this->assertContains('webhook_secret', $payload['changed']['credentials_keys']);
        // Values MUST NOT appear in the audit payload.
        $audit_json = json_encode($payload);
        $this->assertStringNotContainsString('NEW_WH_SECRET', $audit_json);
        $this->assertStringNotContainsString('OLD_SECRET', $audit_json);
    }

    public function test_staff_cannot_view_credentials_endpoint(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'delicity',
            'enabled'           => true,
            'external_store_id' => 'store_d',
            'credentials'       => ['webhook_secret' => 's'],
        ]);

        // POS Operator has no `settings` permission → 403 from the
        // permission middleware before the controller body runs.
        $operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $operator->assignRole('POS Operator');
        Sanctum::actingAs($operator, ['*']);

        $response = $this->postJson("/api/admin/delivery-platforms/{$platform->id}/reveal");

        // Spatie permission middleware returns 403 (sometimes 401 in
        // older Spatie versions) — accept both, just not 200.
        $this->assertContains($response->status(), [401, 403]);

        $audit = AuditLog::where('action', 'delivery.platform.credentials_revealed')->first();
        $this->assertNull($audit, 'unauthorised reveal must not write audit row');
    }

    public function test_reveal_endpoint_writes_audit_row(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_42',
            'credentials'       => [
                'client_id'      => 'CID',
                'client_secret'  => 'SECRET',
                'webhook_secret' => 'WH',
            ],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/delivery-platforms/{$platform->id}/reveal");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('CID', $data['credentials']['client_id']);
        $this->assertSame('SECRET', $data['credentials']['client_secret']);
        $this->assertSame('WH', $data['credentials']['webhook_secret']);

        $audit = AuditLog::where('action', 'delivery.platform.credentials_revealed')
            ->where('resource_id', $platform->id)
            ->first();
        $this->assertNotNull($audit, 'reveal must emit credentials_revealed audit row');
        $this->assertSame((int) $this->admin->id, (int) $audit->user_id);

        // The audit payload itself must NOT contain the plaintext.
        $audit_json = json_encode($audit->payload);
        $this->assertStringNotContainsString('SECRET', $audit_json);
        $this->assertStringNotContainsString('WH', $audit_json);
    }

    public function test_toggle_enabled_writes_audit_row(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => false,
            'external_store_id' => 'store_toggle',
            'credentials'       => [],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/delivery-platforms/{$platform->id}/toggle");
        $response->assertOk();

        $platform->refresh();
        $this->assertTrue((bool) $platform->enabled);

        $audit = AuditLog::where('action', 'delivery.platform.toggled')
            ->where('resource_id', $platform->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $payload = $audit->payload;
        $this->assertSame('uber_eats', $payload['platform']);
        $this->assertFalse((bool) $payload['from']);
        $this->assertTrue((bool) $payload['to']);
    }

    public function test_webhook_url_returns_correct_pattern(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'deliveroo',
            'enabled'           => true,
            'external_store_id' => 'rd_42',
            'credentials'       => [],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson("/api/admin/delivery-platforms/{$platform->id}/webhook-url");
        $response->assertOk();

        $url = $response->json('data.url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/api/webhooks/delivery/deliveroo/', $url);
        $this->assertStringEndsWith('/{event}', $url);
    }

    public function test_health_returns_24h_window(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'delicity',
            'enabled'           => true,
            'external_store_id' => 'd_42',
            'credentials'       => [
                'client_id'      => 'a',
                'client_secret'  => 'b',
                'webhook_secret' => 'c',
            ],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson("/api/admin/delivery-platforms/{$platform->id}/health");
        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame('delicity', $data['platform']);
        $this->assertArrayHasKey('last_24h', $data);
        $this->assertArrayHasKey('webhooks_received', $data['last_24h']);
        $this->assertArrayHasKey('oauth_status', $data);
        $this->assertTrue((bool) $data['oauth_status']['configured']);
    }

    public function test_test_signature_endpoint_returns_ok_when_secret_set(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_sig',
            'credentials'       => ['webhook_secret' => 'super_secret_value'],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/delivery-platforms/{$platform->id}/test-signature");
        $response->assertOk();
        $this->assertTrue((bool) $response->json('data.ok'));
    }

    /**
     * Regression guard for the BranchScope bypass.
     *
     * A non-zero branch_id Admin must still resolve a row whose
     * branch_id differs — without the explicit
     * `withoutGlobalScopes()` in the controller helpers, this 404s
     * silently (BranchScope is applied through route-model binding).
     */
    public function test_admin_with_different_branch_can_resolve_cross_branch_row(): void
    {
        $platformBranch = $this->branch;
        $otherBranch    = Branch::factory()->create();

        $platform = DeliveryPlatform::create([
            'branch_id'         => $platformBranch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'cross_branch',
            'credentials'       => ['client_id' => 'X'],
        ]);

        $crossBranchAdmin = User::factory()->create(['branch_id' => $otherBranch->id]);
        $crossBranchAdmin->assignRole('Admin');
        Sanctum::actingAs($crossBranchAdmin, ['*']);

        // show / health / webhook-url must all 200 OK for any branch.
        $this->getJson("/api/admin/delivery-platforms/{$platform->id}")->assertOk();
        $this->getJson("/api/admin/delivery-platforms/{$platform->id}/health")->assertOk();
        $this->getJson("/api/admin/delivery-platforms/{$platform->id}/webhook-url")->assertOk();
    }

    public function test_test_signature_endpoint_reports_missing_secret(): void
    {
        $platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_no_secret',
            'credentials'       => [],
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson("/api/admin/delivery-platforms/{$platform->id}/test-signature");
        $response->assertOk();
        $this->assertFalse((bool) $response->json('data.ok'));
    }
}
