<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalCloseAllActiveBranchesCommand;
use App\Models\Branch;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-G2-HEAL-06 2026-05-23] Sentinel for the NF525 Z-close safety-net cron.
 *
 * Phase G.6 audit caught a P1 operational gap: Z-close had NO production
 * trigger (no UI button, no cron, no documented runbook). F.10 verified the
 * service-layer invariants (13/13 GREEN) but the operational layer was
 * missing. This sentinel pins:
 *
 *  (1) Kernel schedule registers `fiscal:close-all-active-branches` daily
 *      at 23:59 Europe/Paris with onOneServer + withoutOverlapping defenses.
 *      NOTE [GAP-HUNT 2026-05-25 PROPOSAL-Z-LOOP-GAP Path A]: close was
 *      tightened 23:55 → 23:59 (and the Z-open partner 00:05 → 00:01) to
 *      shrink the cross-midnight orphan window (where a fiscal_sequence_no
 *      could land in no Z) from ~10 min to ~2 min. This sentinel predated
 *      that change (authored 2026-05-23, never CI-collected) so it kept
 *      asserting the stale 23:55; aligned to the shipped Kernel here 2026-06-06.
 *  (2) The artisan command class exists, is registered and invokable.
 *  (3) On a "dark" branch (no open Z), the command SKIPS silently and
 *      exits SUCCESS — does NOT throw. This is the critical behavior:
 *      without the pre-check, `ZReportService::close()` raises a
 *      RuntimeException (verified at app/Services/Fiscal/ZReportService.php:211)
 *      and would flood the `fiscal` channel every night for every branch
 *      with no transactions, generating false-alarm pager noise.
 *  (4) Per-branch isolation: if a branch crashes, OTHER active branches
 *      still get their attempt — mirrors fiscal-chain-monitor pattern.
 *
 * NOTE: The full `ZReportService::close()` happy-path is covered by
 * ZReportCloseTest. This sentinel only locks the cron wiring + the
 * "no open Z" skip semantic so the cron stays safe when raised to
 * V2 multi-branch scale.
 */
class ZCloseSafetyNetCronSentinel extends TestCase
{
    use RefreshDatabase;

    public function test_kernel_registers_z_close_safety_net_lane_at_23_59_paris(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events   = collect($schedule->events());

        $entry = $events->first(function ($event): bool {
            $description = property_exists($event, 'description')
                ? (string) ($event->description ?? '')
                : '';

            return str_contains((string) $event->command, 'fiscal:close-all-active-branches')
                || str_contains($description, 'NF525 Z-close safety-net');
        });

        $this->assertNotNull(
            $entry,
            'fiscal:close-all-active-branches must be scheduled daily (GOAL-G2-HEAL-06). '
            . 'Without this lane, the V1 LOCAL Le Cayenne loses every night-end Z close '
            . 'unless the cashier manually triggers it.'
        );

        $this->assertSame(
            '59 23 * * *',
            (string) $entry->expression,
            "Z-close safety-net must run daily at 23:59 (Paris) — same business_date as the transactions "
            . '(GAP-HUNT 2026-05-25 tightened 23:55→23:59 to shrink the cross-midnight orphan window to ~2 min).'
        );

        $this->assertSame(
            'Europe/Paris',
            (string) $entry->timezone,
            'Timezone must be Europe/Paris to land on the operational business_date.'
        );

        $this->assertNotEmpty(
            $entry->mutex,
            'withoutOverlapping required so a long-running close cannot race a 23:55 re-fire.'
        );

        $this->assertTrue(
            (bool) $entry->onOneServer,
            'onOneServer required so a horizontally-scaled deployment never double-closes.'
        );
    }

    public function test_artisan_command_is_registered_and_invokable(): void
    {
        $this->assertTrue(
            class_exists(FiscalCloseAllActiveBranchesCommand::class),
            'FiscalCloseAllActiveBranchesCommand must exist (GOAL-G2-HEAL-06).'
        );

        $signatures = collect(Artisan::all())->keys();

        $this->assertContains(
            'fiscal:close-all-active-branches',
            $signatures->all(),
            'fiscal:close-all-active-branches must be registered in Artisan command list.'
        );
    }

    public function test_dark_branch_skips_silently_and_exits_success(): void
    {
        // Branch with NO open Z report (the common "dark branch" case post-V1).
        // ZReportService::close() raises RuntimeException("no open Z report to
        // close for branch X") at line 211 if invoked on this branch.
        // The safety-net cron MUST NOT crash — it pre-checks STATUS_OPEN and
        // skips silently.
        $branch = Branch::factory()->create();

        $this->assertSame(
            0,
            ZReport::query()->where('branch_id', $branch->id)->count(),
            'Test fixture sanity: branch must have NO Z report rows.'
        );

        $exit = Artisan::call('fiscal:close-all-active-branches', [
            '--branch' => $branch->id,
        ]);

        $this->assertSame(
            0,
            $exit,
            'Safety-net cron MUST exit SUCCESS on a dark branch (no open Z). '
            . 'A non-zero exit here would page the on-call every night.'
        );

        // Confirm no Z report was created/closed for the dark branch.
        $this->assertSame(
            0,
            ZReport::query()->where('branch_id', $branch->id)->count(),
            'No Z report must be created when the branch is dark — the safety-net does NOT open a Z, only closes existing OPEN rows.'
        );
    }

    public function test_dry_run_does_not_close_open_z_report(): void
    {
        // Seed an OPEN Z report directly (bypassing the service to keep the
        // sentinel hermetic — we are not testing close(), we are testing
        // the dry-run gate of the command).
        $branch = Branch::factory()->create();

        ZReport::query()->create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => Carbon::now()->subHours(8),
            'opened_by'   => null,
            'status'      => ZReport::STATUS_OPEN,
        ]);

        $exit = Artisan::call('fiscal:close-all-active-branches', [
            '--branch'  => $branch->id,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit, 'Dry-run must exit SUCCESS even when a close would happen.');

        // The seeded row must STILL be OPEN — dry-run only logs "would_close".
        $row = ZReport::query()->where('branch_id', $branch->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(
            ZReport::STATUS_OPEN,
            $row->status,
            'Dry-run must leave OPEN Z reports unchanged. Status must remain OPEN.'
        );
    }

    public function test_unknown_branch_override_fails_loudly(): void
    {
        // An ops typo (--branch=99999) must NOT silently no-op — it must fail
        // with FAILURE exit so the operator notices. This is the only path
        // that returns non-zero on a no-work scenario.
        $exit = Artisan::call('fiscal:close-all-active-branches', [
            '--branch' => 99999,
        ]);

        $this->assertSame(
            1,
            $exit,
            'Unknown / inactive branch override MUST fail loudly so ops see the typo.'
        );
    }
}
