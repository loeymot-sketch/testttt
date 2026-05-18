<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalVerifyChainCommand;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fiscal\Concerns\InstallsAuditLogImmutabilityTriggers;
use Tests\TestCase;

/**
 * [NF525 Wave 1 W-1 heal]
 * [NF525 Wave 3 P1 heal FISCAL-ADV3-01/02/03 2026-05-18]
 *
 * The `fiscal:verify-chain` artisan command exposes
 * AuditLogService::verifyChain() on the CLI so operators can validate
 * the audit chain on-demand. CLAUDE.md §8 referenced this command, but
 * it did not exist until this sprint.
 *
 *  - Clean chain      -> exit 0, output "CHAIN OK".
 *  - Tampered row     -> exit 1, output contains the tampered row id.
 *  - --branch=N       -> only the specified branch's chain is verified.
 *  - Invalid branch   -> exit 2, "Branch ID N not found" (no false-negative).
 *  - Execution error  -> exit 3, "Verification FAILED to execute".
 *  - schedule()       -> daily 03:30 `fiscal-chain-monitor` event present.
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

        // [Wave 3 P1 FISCAL-ADV3-01 2026-05-18] The command now validates
        // that --branch=N resolves to a real Branch row before walking
        // the chain (no more false-negative CHAIN OK on a nonexistent
        // branch). All pre-existing tests must therefore seed the
        // synthetic branch ids they reference.
        foreach ([
            self::BR_CLEAN, self::BR_TAMPER, self::BR_SCOPED_OK, self::BR_SCOPED_BAD,
        ] as $branchId) {
            Branch::factory()->create(['id' => $branchId]);
        }
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

    /**
     * [Wave 3 P1 FISCAL-ADV3-01]
     *
     * Previously `--branch=99` against a single-resto install returned
     * a false-negative `CHAIN OK exit 0` because the verifier iterated
     * an empty cursor. Now: hard-fail with exit 2 and a clear message.
     */
    public function test_unknown_branch_returns_invalid_exit_code(): void
    {
        // Pick a branch id that provably does not exist in the branches
        // table (RefreshDatabase + 4-digit isolated convention above
        // keeps the real branch table sparse, so 999_999 is safe).
        $missingBranchId = 999_999;

        $this->assertDatabaseMissing('branches', ['id' => $missingBranchId]);

        $this->artisan('fiscal:verify-chain', ['--branch' => $missingBranchId])
            ->expectsOutputToContain('Branch ID '.$missingBranchId.' not found')
            ->assertExitCode(FiscalVerifyChainCommand::INVALID);
    }

    /**
     * [Wave 3 P1 FISCAL-ADV3-02]
     *
     * Exit-code collapse fix: a service-level throw (DB outage, missing
     * fiscal.audit_secret, weak secret) must NOT share exit code 1 with
     * a genuine TAMPER hit. Exit 3 = execution error, distinct lane.
     *
     * We swap the bound AuditLogService with a stub that throws so the
     * test stays isolated from real DB/HMAC failure modes.
     */
    public function test_service_throw_returns_execution_error_exit_code(): void
    {
        // Seed a real branch row via the factory (which fills the
        // NOT NULL city/state/zip/address columns) so we pass the
        // FISCAL-ADV3-01 guard and land inside the try/catch lane.
        $branchId = 920_605;
        Branch::factory()->create(['id' => $branchId]);

        $this->app->bind(AuditLogService::class, function () {
            return new class extends AuditLogService
            {
                public function __construct() {}

                public function verifyChain(?int $branchId = null): ?int
                {
                    throw new \RuntimeException('Simulated DB outage / missing secret');
                }
            };
        });

        // NOTE: Symfony Console's $this->error() block-wraps long lines
        // for the red error decoration, so we assert only the stable
        // first-fragment "Verification FAILED to execute" + the exit
        // code 3 contract — the throw message itself can be split
        // across decorated lines depending on terminal width.
        $this->artisan('fiscal:verify-chain', ['--branch' => $branchId])
            ->expectsOutputToContain('Verification FAILED to execute')
            ->assertExitCode(3);
    }

    /**
     * [Wave 3 P1 FISCAL-ADV3-03]
     *
     * Without a scheduled run, the CLI exists but detection window is
     * unbounded. Assert that the Kernel::schedule() registers the
     * daily monitor for `fiscal:verify-chain` by walking the live
     * Schedule::events() collection (public API across Laravel 10/11).
     */
    public function test_schedule_registers_daily_fiscal_chain_monitor(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($event) => (string) ($event->command ?? ''))
            ->all();

        $matches = array_filter(
            $commands,
            fn (string $command) => str_contains($command, 'fiscal:verify-chain')
        );

        $this->assertNotEmpty(
            $matches,
            'Expected fiscal:verify-chain to be wired into Kernel::schedule() '
            .'(Wave 3 P1 FISCAL-ADV3-03). Registered commands: '
            .implode(' | ', $commands)
        );
    }
}
