<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalVerifyChainCommand;
use App\Console\Kernel as AppConsoleKernel;
use App\Enums\Status;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
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
            ->expectsOutputToContain('CHAIN OK (audit_logs + z_reports) (branch='.self::BR_CLEAN.')')
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

        // [Wave 2d FISCAL-ADV3C-02 2026-05-18] Output format now multi-line:
        // header + one fragment per row. Operator parsing (`grep audit_logs.id=`)
        // unaffected; substring assertions split into header + fragment row.
        $this->artisan('fiscal:verify-chain', ['--branch' => self::BR_TAMPER])
            ->expectsOutputToContain('TAMPER detected (branch='.self::BR_TAMPER)
            ->expectsOutputToContain('audit_logs.id='.$mid->id)
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
     * [Wave 3 P1 FISCAL-ADV3-03 / Wave 3b FISCAL-ADV3B-01]
     *
     * Without a scheduled run, the CLI exists but detection window is
     * unbounded. Assert that Kernel::schedule() registers the daily
     * dual-chain monitor.
     *
     * Wave 3b: the cron switched from `$schedule->command(...)` to
     * `$schedule->call(closure)` so it can iterate every active branch
     * (FISCAL-ADV3B-01). CallbackEvent has a null `$command` property,
     * so we now match by the `name()` / description label, which is
     * how named callback events surface in Schedule::events().
     */
    public function test_schedule_registers_daily_fiscal_chain_monitor_for_all_branches(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $events = collect($schedule->events());

        $descriptions = $events
            ->map(fn ($event) => (string) ($event->description ?? ''))
            ->all();

        $matches = array_filter(
            $descriptions,
            fn (string $desc) => str_contains($desc, 'fiscal-chain-monitor-all-branches')
        );

        $this->assertNotEmpty(
            $matches,
            'Expected fiscal-chain-monitor-all-branches to be wired into Kernel::schedule() '
            .'(Wave 3b FISCAL-ADV3B-01). Registered descriptions: '
            .implode(' | ', array_filter($descriptions))
        );
    }

    /**
     * [Wave 2d FISCAL-ADV3C-01 2026-05-18]
     *
     * Both fiscal cron lanes (fiscal-chain-monitor + fiscal-archive)
     * pluck their iteration list from Kernel::activeBranchIds(). It MUST
     * accept BOTH `status=1` (legacy) and `status=Status::ACTIVE` (=5,
     * canonical post-migration). Previously the closures used
     * `where('status', 1)` directly, so an owner-flagged migration
     * `UPDATE branches SET status=5 WHERE status=1` would have silently
     * dropped every branch from the iteration → cron no-ops forever.
     */
    public function test_active_branch_ids_includes_both_legacy_and_canonical_status(): void
    {
        // Three seeded branches: one legacy (status=1), one canonical
        // (Status::ACTIVE=5), one inactive (Status::INACTIVE=10). Plus
        // a soft-deleted row that must be excluded regardless of status.
        $legacy = Branch::factory()->create(['id' => 930_001, 'status' => 1]);
        $canonical = Branch::factory()->create(['id' => 930_002, 'status' => Status::ACTIVE]);
        $inactive = Branch::factory()->create(['id' => 930_003, 'status' => Status::INACTIVE]);
        $deleted = Branch::factory()->create(['id' => 930_004, 'status' => Status::ACTIVE]);
        $deleted->delete(); // soft-delete

        $ids = AppConsoleKernel::activeBranchIds()->all();

        $this->assertContains($legacy->id, $ids, 'Legacy status=1 branch must be included.');
        $this->assertContains($canonical->id, $ids, 'Canonical Status::ACTIVE branch must be included.');
        $this->assertNotContains($inactive->id, $ids, 'Status::INACTIVE branch must be excluded.');
        $this->assertNotContains($deleted->id, $ids, 'Soft-deleted branch must be excluded.');

        foreach ($ids as $id) {
            $this->assertIsInt($id, 'IDs must be cast to int for Artisan::call --branch=<int>.');
        }
    }

    /**
     * [Wave 3b FISCAL-ADV3B-02]
     *
     * Verifier MUST walk the z_reports HMAC chain in addition to
     * audit_logs. Tamper a Z-row signature post-write; expect exit 1
     * + output naming z_reports.id.
     */
    public function test_tampered_z_report_chain_returns_failure_and_prints_z_report_id(): void
    {
        $branchId = 920_606;
        Branch::factory()->create(['id' => $branchId]);
        Config::set('fiscal.verify_chain_strict', false);

        $zService = app(ZReportService::class);

        // Seed a clean audit chain on this branch so the audit-side
        // verifier short-circuits to null and the tamper signal is
        // unambiguously from z_reports.
        $this->service->write([
            'branch_id' => $branchId, 'action' => 'order.create', 'payload' => ['id' => 1],
        ]);

        // Build two genuinely-signed Z-reports via the public service
        // path so signatures + prev_hash chain are valid, then tamper
        // the second row to force a signature_mismatch (one of the
        // three ZReportService::verifyChain error kinds).
        //
        // Columns mirror database/migrations/2026_04_22_000003_create_z_reports_table.php
        // (total_ht/total_ttc/total_tva + order/cancel/refund counts +
        // JSON method/rate aggregates). `total_ttc` is part of the HMAC
        // input (ZReportService::computeSignature lines 676-685), so
        // mutating it post-sign forces a signature_mismatch.
        ZReport::unguard();
        try {
            $genesis = (string) config('fiscal.genesis_prev_hash', str_repeat('0', 64));

            $z1 = new ZReport([
                'branch_id'         => $branchId,
                'sequence_no'       => 1,
                'opened_at'         => now()->subDays(2),
                'closed_at'         => now()->subDays(2),
                'total_ht'          => 0,
                'total_ttc'         => 0,
                'total_tva'         => 0,
                'total_by_method'   => [],
                'total_by_tax_rate' => [],
                'order_count'       => 0,
                'cancel_count'      => 0,
                'refund_count'      => 0,
                'status'            => ZReport::STATUS_CLOSED,
                'prev_hash'         => $genesis,
            ]);
            $z1->signature = $this->callServiceSignature($zService, $z1, $genesis);
            $z1->save();

            $z2 = new ZReport([
                'branch_id'         => $branchId,
                'sequence_no'       => 2,
                'opened_at'         => now()->subDay(),
                'closed_at'         => now()->subDay(),
                'total_ht'          => 0,
                'total_ttc'         => 0,
                'total_tva'         => 0,
                'total_by_method'   => [],
                'total_by_tax_rate' => [],
                'order_count'       => 0,
                'cancel_count'      => 0,
                'refund_count'      => 0,
                'status'            => ZReport::STATUS_CLOSED,
                'prev_hash'         => $z1->signature,
            ]);
            $z2->signature = $this->callServiceSignature($zService, $z2, $z1->signature);
            $z2->save();

            // Tamper: rewrite total_ttc on z2 (HMAC input) without
            // re-signing. Raw DB update bypasses any observer that
            // would otherwise re-sign on save.
            DB::table('z_reports')->where('id', $z2->id)->update([
                'total_ttc' => 999.99,
            ]);
        } finally {
            ZReport::reguard();
        }

        $this->artisan('fiscal:verify-chain', ['--branch' => $branchId])
            ->expectsOutputToContain('z_reports.id='.$z2->id)
            ->assertExitCode(1);
    }

    /**
     * [Wave 2d FISCAL-ADV3C-02 2026-05-18]
     *
     * Verifier MUST report ALL z_reports breaches in one pass — not just
     * errors[0]. ZReportService::verifyChain accumulates three breach
     * kinds (chain_break, sequence_gap, signature_mismatch) into errors[].
     * Previously this command extracted only errors[0]['z_id'], forcing
     * operators to run the cron N-1 times to enumerate a coordinated tamper
     * and opening a re-signing window over still-corrupted prev_hash.
     */
    public function test_multiple_z_report_tampers_reported_in_single_pass(): void
    {
        $branchId = 920_607;
        Branch::factory()->create(['id' => $branchId]);
        Config::set('fiscal.verify_chain_strict', false);

        $zService = app(ZReportService::class);

        ZReport::unguard();
        try {
            $genesis = (string) config('fiscal.genesis_prev_hash', str_repeat('0', 64));

            // Three chain-valid Z reports.
            $z1 = $this->buildValidZ($branchId, 1, $genesis, $zService);
            $z2 = $this->buildValidZ($branchId, 2, $z1->signature, $zService);
            $z3 = $this->buildValidZ($branchId, 3, $z2->signature, $zService);

            // Tamper #1: rewrite total_ttc on z2 → signature_mismatch.
            DB::table('z_reports')->where('id', $z2->id)->update([
                'total_ttc' => 999.99,
            ]);
            // Tamper #2: rewrite total_ttc on z3 → signature_mismatch as well.
            DB::table('z_reports')->where('id', $z3->id)->update([
                'total_ttc' => 1234.56,
            ]);
        } finally {
            ZReport::reguard();
        }

        // Both ids must appear in the stdout — not just z2.
        $this->artisan('fiscal:verify-chain', ['--branch' => $branchId])
            ->expectsOutputToContain('z_reports.id='.$z2->id)
            ->expectsOutputToContain('z_reports.id='.$z3->id)
            ->assertExitCode(1);
    }

    private function buildValidZ(int $branchId, int $seq, string $prevHash, ZReportService $zService): ZReport
    {
        $z = new ZReport([
            'branch_id'         => $branchId,
            'sequence_no'       => $seq,
            'opened_at'         => now()->subDays(4 - $seq),
            'closed_at'         => now()->subDays(4 - $seq),
            'total_ht'          => 0,
            'total_ttc'         => 0,
            'total_tva'         => 0,
            'total_by_method'   => [],
            'total_by_tax_rate' => [],
            'order_count'       => 0,
            'cancel_count'      => 0,
            'refund_count'      => 0,
            'status'            => ZReport::STATUS_CLOSED,
            'prev_hash'         => $prevHash,
        ]);
        $z->signature = $this->callServiceSignature($zService, $z, $prevHash);
        $z->save();

        return $z;
    }

    /**
     * Helper: call the protected computeSignature via reflection so
     * the test can build chain-valid Z-report fixtures without
     * duplicating signature logic (frozen-zone service — read-only
     * access to its private signing routine).
     */
    private function callServiceSignature(ZReportService $service, ZReport $zReport, string $prevHash): string
    {
        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('computeSignature');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $zReport, $prevHash);
    }
}
