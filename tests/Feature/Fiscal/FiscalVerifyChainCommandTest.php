<?php

namespace Tests\Feature\Fiscal;

use App\Models\AuditLog;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fiscal\Concerns\InstallsAuditLogImmutabilityTriggers;
use Tests\TestCase;

/**
 * [NF525 Wave 1 W-1 heal]
 *
 * The `fiscal:verify-chain` artisan command exposes
 * AuditLogService::verifyChain() on the CLI so operators can validate
 * the audit chain on-demand. CLAUDE.md §8 referenced this command, but
 * it did not exist until this sprint.
 *
 *  - Clean chain  -> exit 0, output "CHAIN OK".
 *  - Tampered row -> exit != 0, output contains the tampered row id.
 *  - --branch=N   -> only the specified branch's chain is verified.
 */
class FiscalVerifyChainCommandTest extends TestCase
{
    use InstallsAuditLogImmutabilityTriggers;
    use RefreshDatabase;

    // Isolated per-test branch ids — same convention as AuditLogHashChainTest.
    private const BR_CLEAN = 920_601;

    private const BR_TAMPER = 920_602;

    private const BR_SCOPED_OK = 920_603;

    private const BR_SCOPED_BAD = 920_604;

    private AuditLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret', 'unit-test-secret-verify-chain-cmd');
        $this->service = app(AuditLogService::class);
    }

    public function test_clean_chain_returns_success_and_prints_chain_ok(): void
    {
        $this->service->write([
            'branch_id' => self::BR_CLEAN, 'action' => 'order.create', 'payload' => ['id' => 1],
        ]);
        $this->service->write([
            'branch_id' => self::BR_CLEAN, 'action' => 'order.pay', 'payload' => ['id' => 1, 'method' => 'cash'],
        ]);

        $this->artisan('fiscal:verify-chain', ['--branch' => self::BR_CLEAN])
            ->expectsOutputToContain('CHAIN OK (branch='.self::BR_CLEAN.')')
            ->assertExitCode(0);
    }

    public function test_tampered_chain_returns_failure_and_prints_tamper_id(): void
    {
        $this->service->write(['branch_id' => self::BR_TAMPER, 'action' => 'order.create', 'payload' => ['id' => 1]]);
        $mid = $this->service->write(['branch_id' => self::BR_TAMPER, 'action' => 'order.pay', 'payload' => ['id' => 1, 'method' => 'cash']]);
        $this->service->write(['branch_id' => self::BR_TAMPER, 'action' => 'order.cancel', 'payload' => ['id' => 1]]);

        // Simulate raw-DB tampering (the chain is designed to detect this even
        // if an attacker bypasses the INSERT-only trigger).
        $this->dropImmutabilityTriggers();
        try {
            DB::table('audit_logs')->where('id', $mid->id)->update([
                'payload' => json_encode(['id' => 1, 'method' => 'card']),
            ]);
        } finally {
            $this->reinstallImmutabilityTriggers();
        }

        $this->artisan('fiscal:verify-chain', ['--branch' => self::BR_TAMPER])
            ->expectsOutputToContain('TAMPER detected at audit_logs.id='.$mid->id)
            ->assertExitCode(1);
    }

    public function test_branch_filter_isolates_verification_scope(): void
    {
        // Clean chain on BR_SCOPED_OK.
        $this->service->write(['branch_id' => self::BR_SCOPED_OK, 'action' => 'order.create', 'payload' => ['id' => 1]]);
        $this->service->write(['branch_id' => self::BR_SCOPED_OK, 'action' => 'order.pay', 'payload' => ['id' => 1]]);

        // Forge a broken row on BR_SCOPED_BAD (wrong prev_hash).
        $this->service->write(['branch_id' => self::BR_SCOPED_BAD, 'action' => 'order.create', 'payload' => ['id' => 99]]);
        AuditLog::unguard();
        try {
            AuditLog::create([
                'branch_id' => self::BR_SCOPED_BAD,
                'action' => 'order.admin_override',
                'payload' => ['id' => 99, 'new_total' => 0.01],
                'prev_hash' => str_repeat('0', 64), // wrong parent
                'current_hash' => str_repeat('f', 64),
            ]);
        } finally {
            AuditLog::reguard();
        }

        // The clean branch verifies independently of the broken branch.
        $this->artisan('fiscal:verify-chain', ['--branch' => self::BR_SCOPED_OK])
            ->expectsOutputToContain('CHAIN OK')
            ->assertExitCode(0);

        // The broken branch fails.
        $this->artisan('fiscal:verify-chain', ['--branch' => self::BR_SCOPED_BAD])
            ->expectsOutputToContain('TAMPER')
            ->assertExitCode(1);
    }
}
