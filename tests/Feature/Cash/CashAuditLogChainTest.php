<?php

namespace Tests\Feature\Cash;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [Sprint 1D / F-8 — 2026-05-16] Cash → audit_logs HMAC chain binding.
 *
 * Verrouille :
 *  - open  → audit_logs row action=cash.session.opened
 *  - movement → action=cash.movement.recorded
 *  - close → action=cash.session.closed
 *  - reconcile → action=cash.session.reconciled
 *  - chain HMAC intact end-to-end (verifyChain() returns null)
 *  - branch_id scoping correct (cash audits only on the right chain)
 */
class CashAuditLogChainTest extends TestCase
{
    use RefreshDatabase;

    private CashDrawerService $service;
    private Branch $branch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('Branch Manager'); // can override variance gate
        $this->actingAs($this->cashier);

        $this->service = new CashDrawerService();

        // Required for AuditLogService to sign.
        Config::set('fiscal.audit_secret', str_repeat('a', 32));

        // Neutralise variance gate so we can audit a full lifecycle in one test.
        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
    }

    public function test_open_session_writes_audit_log_row_cash_session_opened(): void
    {
        $before = AuditLog::query()->where('action', 'cash.session.opened')->count();

        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);

        $row = AuditLog::query()
            ->where('action', 'cash.session.opened')
            ->where('resource', 'cash_drawer_session')
            ->where('resource_id', $session->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'cash.session.opened audit log row missing');
        $this->assertSame((int) $this->branch->id, (int) $row->branch_id);
        $this->assertSame((int) $this->cashier->id, (int) $row->user_id);
        $this->assertNotEmpty($row->current_hash);
        $this->assertSame($before + 1, AuditLog::query()->where('action', 'cash.session.opened')->count());
    }

    public function test_movement_writes_audit_log_row_cash_movement_recorded(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);

        $movement = $this->service->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            42.50,
            CashMovement::DIRECTION_IN,
            orderId: 17,
        );

        $row = AuditLog::query()
            ->where('action', 'cash.movement.recorded')
            ->where('resource_id', $session->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $payload = is_array($row->payload) ? $row->payload : json_decode($row->payload, true);
        $this->assertSame($movement->id, (int) ($payload['movement_id'] ?? 0));
        $this->assertSame('order_payment', $payload['type'] ?? null);
        $this->assertSame('in', $payload['direction'] ?? null);
        $this->assertSame(42.5, (float) ($payload['amount'] ?? 0));
    }

    public function test_close_writes_audit_log_row_only_on_transition(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->service->closeSession($session->id, 100.00);
        $firstCount = AuditLog::query()->where('action', 'cash.session.closed')->count();

        // Idempotent: second close call must NOT add another audit row.
        $this->service->closeSession($session->id, 999.00);
        $secondCount = AuditLog::query()->where('action', 'cash.session.closed')->count();

        $this->assertSame($firstCount, $secondCount, 'Close idempotent should not duplicate audit rows');
        $this->assertGreaterThanOrEqual(1, $firstCount);
    }

    public function test_reconcile_writes_audit_log_row_with_variance_payload(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 20.00, CashMovement::DIRECTION_IN);
        $this->service->closeSession($session->id, 125.00); // variance +5
        $this->service->reconcileSession($session->id, 'minor surplus', $this->cashier);

        $row = AuditLog::query()
            ->where('action', 'cash.session.reconciled')
            ->where('resource_id', $session->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row);
        $payload = is_array($row->payload) ? $row->payload : json_decode($row->payload, true);
        $this->assertSame(5.00, (float) ($payload['variance'] ?? 0));
        $this->assertSame(120.0, (float) ($payload['expected'] ?? 0));
        $this->assertSame('minor surplus', $payload['variance_reason'] ?? null);
    }

    public function test_full_lifecycle_chain_remains_intact(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, 100.00);
        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 20.00, CashMovement::DIRECTION_IN);
        $this->service->recordMovement($session->id, CashMovement::TYPE_CASHBACK, 5.00, CashMovement::DIRECTION_OUT);
        $this->service->closeSession($session->id, 116.00); // expected 115, variance +1 (under threshold)
        $this->service->reconcileSession($session->id);

        // 4 events expected on cash chain: open / 2 movements / close / reconcile = 5 rows
        $rows = AuditLog::query()
            ->where('branch_id', $this->branch->id)
            ->where('resource', 'cash_drawer_session')
            ->orderBy('id')
            ->get();

        $this->assertGreaterThanOrEqual(5, $rows->count(), 'Expected at least 5 cash audit rows');

        // Chain HMAC integrity end-to-end.
        $service = app(AuditLogService::class);
        $tampered = $service->verifyChain((int) $this->branch->id);
        $this->assertNull($tampered, 'Cash audit chain HMAC broken at row '.$tampered);
    }
}
