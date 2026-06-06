<?php

namespace Tests\Feature\Cash;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Printer;
use App\Models\User;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * CASH-03 — no-sale hardware drawer pop with NO open session must leave a
 * durable forensic trace, not only a Log::warning.
 *
 * On real hardware a cashier (or manager pre-shift) can pop the cash drawer
 * with no order and no open session. The i18n promise to the operator is
 * "Action tracée" — but CashDrawerController::open previously only wrote a
 * Log::warning in the no-session branch (the with-session branch records a
 * TYPE_DRAWER_OPEN movement → audit_logs via CashDrawerService). The fix
 * writes a session-less audit_logs row capturing the drawer-pop attempt + actor
 * so the forensic gap is closed even with no open session.
 */
class NoSaleDrawerPopAuditTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // AuditLogService::write refuses to sign with an unset secret.
        Config::set('fiscal.audit_secret', str_repeat('c', 48));

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos');

        // Configure a receipt printer so EscPosPrinterService::openDrawer succeeds
        // → the controller reaches the no-session forensic branch.
        Printer::query()->create([
            'branch_id'   => $this->branch->id,
            'name'        => 'Receipt',
            'type'        => 'escpos_tcp',
            'host'        => '127.0.0.1',
            'port'        => 9100,
            'station'     => 'receipt',
            'width_chars' => 48,
            'status'      => 1,
            'options'     => null,
        ]);

        // Bind the printer transport to the no-op transport so the bytes go
        // nowhere but openDrawer still reports success.
        $this->app->bind(EscPosPrinterService::class, fn () => new EscPosPrinterService(new NullPrinterTransport()));
    }

    public function test_no_sale_drawer_pop_without_session_writes_durable_audit_row(): void
    {
        // Precondition: cashier has NO open cash session.
        $this->assertSame(0, CashDrawerSession::query()->count());

        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos/cash-drawer/open', []);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        // With no session, NO TYPE_DRAWER_OPEN cash_movement is written…
        $this->assertSame(
            0,
            CashMovement::where('type', CashMovement::TYPE_DRAWER_OPEN)->count(),
            'no open session → no cash_movement (that path is unavailable)'
        );

        // …but a durable, session-less audit_logs row MUST capture the pop + actor.
        $row = AuditLog::query()
            ->where('action', 'pos.cash_drawer.no_sale_pop')
            ->where('branch_id', $this->branch->id)
            ->first();

        $this->assertNotNull(
            $row,
            'a no-sale drawer pop with no open session must write a durable audit_logs row (CASH-03)'
        );
        $this->assertSame($this->cashier->id, (int) $row->user_id, 'audit row must capture the acting cashier');
        $this->assertSame('cash_drawer', $row->resource);
    }

    public function test_audit_chain_stays_verifiable_after_session_less_pop(): void
    {
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos/cash-drawer/open', [])
            ->assertOk();

        // The session-less write must chain-link (verifyChain returns null = intact).
        $this->assertNull(
            app(\App\Services\Fiscal\AuditLogService::class)->verifyChain($this->branch->id),
            'the session-less forensic row must be a valid HMAC chain link'
        );
    }
}
