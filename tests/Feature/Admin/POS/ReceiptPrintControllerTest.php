<?php

namespace Tests\Feature\Admin\POS;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ReceiptPrintControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // [W9.B] AuditLogService validates HMAC secrets at runtime; in
        // production these come from env. Tests need an explicit value
        // long enough to satisfy the prod-guard length check.
        config([
            'fiscal.audit_secret' => 'unit-test-audit-padding-48-chars-required-by-prod-guard',
        ]);

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->operator->assignRole('POS Operator');
    }

    public function test_increment_first_call_returns_count_1_not_duplicata(): void
    {
        $order = $this->makeOrder(0);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('receipt_print_count', 1)
            ->assertJsonPath('is_duplicata', false)
            ->assertJsonPath('audit_emitted', true);
    }

    public function test_increment_second_call_returns_count_2_is_duplicata_true(): void
    {
        $order = $this->makeOrder(1);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk()
            ->assertJsonPath('receipt_print_count', 2)
            ->assertJsonPath('is_duplicata', true)
            ->assertJsonPath('audit_emitted', true);
    }

    public function test_increment_persists_in_db(): void
    {
        $order = $this->makeOrder(0);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'branch_id' => $this->branch->id,
            'receipt_print_count' => 1,
        ]);
    }

    public function test_cross_branch_order_returns_404(): void
    {
        $foreignBranch = Branch::factory()->create();
        $foreignUser = User::factory()->create(['branch_id' => $foreignBranch->id]);
        $foreignOrder = Order::factory()->create([
            'branch_id' => $foreignBranch->id,
            'user_id' => $foreignUser->id,
            'receipt_print_count' => 0,
        ]);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$foreignOrder->id}/print-receipt")
            ->assertNotFound();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $order = $this->makeOrder(0);

        $this->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertStatus(401);
    }

    /**
     * [W9.B / G3] First print emits a `pos.receipt.print` audit row,
     * scoped to the operator's branch chain (NF525 evidence).
     */
    public function test_first_print_writes_pos_receipt_print_audit_row(): void
    {
        $order = $this->makeOrder(0);

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk();

        $row = AuditLog::query()
            ->where('action', 'pos.receipt.print')
            ->where('resource', 'order')
            ->where('resource_id', $order->id)
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->assertNotNull($row, 'Expected a pos.receipt.print audit row.');
        $this->assertSame($this->operator->id, (int) $row->user_id);
        $this->assertNotEmpty($row->current_hash, 'Audit row MUST be hash-chained.');
        $this->assertSame(1, (int) ($row->payload['print_count_after'] ?? 0));
        $this->assertSame(0, (int) ($row->payload['print_count_before'] ?? -1));
        $this->assertFalse((bool) $row->payload['is_duplicata']);
    }

    /**
     * [W9.B / G3] Reprint (count >= 2) emits the `pos.receipt.reprint`
     * action, distinct from the first print, so SIEM can alert on
     * unusual reprint volume independently of normal closing prints.
     */
    public function test_reprint_writes_pos_receipt_reprint_audit_row(): void
    {
        $order = $this->makeOrder(1); // already printed once

        $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk();

        $row = AuditLog::query()
            ->where('action', 'pos.receipt.reprint')
            ->where('resource_id', $order->id)
            ->first();

        $this->assertNotNull($row, 'Expected a pos.receipt.reprint audit row on second print.');
        $this->assertSame(2, (int) $row->payload['print_count_after']);
        $this->assertSame(1, (int) $row->payload['print_count_before']);
        $this->assertTrue((bool) $row->payload['is_duplicata']);
    }

    /**
     * [W9.B] Two reprints on the same order produce two distinct audit
     * rows. We do NOT deduplicate — every paper handed to a customer
     * leaves its own evidence trail.
     */
    public function test_consecutive_reprints_each_produce_distinct_audit_rows(): void
    {
        $order = $this->makeOrder(0);

        for ($i = 0; $i < 3; $i++) {
            // [prod-finale 2026-06-17] DISTINCT idempotency key per iteration (fresh Str::uuid() inside the
            // loop): the 3 POSTs to the SAME order must each reach the controller to increment the print
            // counter (print → reprint → reprint). A reused key would replay the cached 2xx and produce only
            // ONE audit row, breaking the "1 print + 2 reprints" assertions below. Frozen middleware; live UI
            // sends a fresh key per print.
            $this->actingAs($this->operator, 'sanctum')
                ->withHeader('X-Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
                ->assertOk();
        }

        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'pos.receipt.print')->where('resource_id', $order->id)->count(),
            'Exactly one initial print expected.'
        );
        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'pos.receipt.reprint')->where('resource_id', $order->id)->count(),
            'Two reprints expected.'
        );
    }

    /**
     * [W9-AUDIT TEST-1] Fail-soft contract on audit failure.
     * If the audit chain write throws (lock starvation, DB hiccup, secret
     * misconfig), the print operation MUST proceed: paper has been (or will
     * be) handed to the customer, the counter MUST be incremented for the
     * DUPLICATA semantic to remain coherent on subsequent prints, and the
     * JSON response MUST signal `audit_emitted=false` so the UI can warn
     * the operator and ops can replay/reconcile from logs.
     *
     * Anti-pattern under test: rolling back the increment on audit failure
     * would silently turn a 3rd reprint into a "first print" with no badge.
     */
    public function test_audit_write_failure_returns_audit_emitted_false_and_does_not_rollback_counter(): void
    {
        $order = $this->makeOrder(0);

        $this->mock(AuditLogService::class, function ($mock) {
            $mock->shouldReceive('write')->andThrow(new RuntimeException('audit chain unavailable'));
        });

        $response = $this->actingAs($this->operator, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt");

        $response->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('receipt_print_count', 1)
            ->assertJsonPath('is_duplicata', false)
            ->assertJsonPath('audit_emitted', false);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'receipt_print_count' => 1,
        ]);

        $this->assertSame(
            0,
            AuditLog::query()->where('resource_id', $order->id)->count(),
            'No audit row should have been written when the service threw.'
        );
    }

    /**
     * [2026-06-04 final-validation P1-B] Admin (branch_id=0) is the owner /
     * super-user and MUST be able to (re)print ANY branch's receipt — the
     * same bypass the global BranchScope grants admin everywhere. Before this
     * fix the controller scoped the update to the user's branch_id (=0), so
     * admin hit `abort(404)` on every real (branch>0) order. The NF525 print
     * audit must land on the ORDER's branch, not on branch 0.
     */
    public function test_admin_branch_zero_can_print_branch_order_and_audits_on_order_branch(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $order = $this->makeOrder(0); // belongs to $this->branch (branch > 0)

        $this->actingAs($admin, 'sanctum')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/orders/{$order->id}/print-receipt")
            ->assertOk()
            ->assertJsonPath('order_id', $order->id)
            ->assertJsonPath('receipt_print_count', 1)
            ->assertJsonPath('audit_emitted', true);

        $row = AuditLog::query()
            ->where('action', 'pos.receipt.print')
            ->where('resource_id', $order->id)
            ->first();

        $this->assertNotNull($row, 'Admin print must still emit a chained NF525 audit row.');
        $this->assertSame(
            $this->branch->id,
            (int) $row->branch_id,
            'Print audit must be attributed to the order branch, not branch 0.'
        );
    }

    private function makeOrder(int $printCount): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'receipt_print_count' => $printCount,
        ]);
    }
}
