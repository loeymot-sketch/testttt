<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalOpenAllActiveBranchesCommand;
use App\Models\Branch;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * [GOAL-L2-HEAL-07 2026-05-24] Sentinel for the NF525 Z-OPEN safety-net cron.
 *
 * Phase L11.1 P0-03 + K.7 FIND-1 (cron miss recovery audit) caught the
 * missing companion to G2-HEAL-06: the 23:55 Z-close safety-net has no
 * Z-open partner. If a cashier never manually opens the next Z after the
 * midnight close, every business day after = silent skip = the chain stops
 * extending and any transaction recorded post-midnight has nowhere to land
 * except via the degraded `fiscal_alloc_error_at` retry path.
 *
 * This sentinel pins:
 *
 *  (1) Kernel schedule registers `fiscal:open-all-active-branches` daily
 *      at 00:01 Europe/Paris with onOneServer + withoutOverlapping defenses
 *      (paired with lane #17's 23:59 close so the close+open pair stays
 *      symmetric). NOTE [GAP-HUNT 2026-05-25 PROPOSAL-Z-LOOP-GAP Path A]:
 *      open was tightened 00:05 → 00:01 (and close 23:55 → 23:59) to shrink
 *      the cross-midnight orphan window (where a fiscal_sequence_no could
 *      land in no Z) from ~10 min to ~2 min. This sentinel predated that
 *      change (authored 2026-05-24, never CI-collected) so it kept asserting
 *      the stale 00:05; aligned to the shipped Kernel here 2026-06-06.
 *  (2) The artisan command class exists, is registered and invokable.
 *  (3) On a "dark" branch (no Z reports yet), the command OPENS a new Z
 *      and exits SUCCESS — this is the core behaviour the loop depends on.
 *  (4) Idempotency: when a branch already has an OPEN Z, the command
 *      SKIPS silently (no second open, no double-open RuntimeException,
 *      no false-alarm pager from the `fiscal` channel).
 *  (5) Unknown branch override (`--branch=<non-existent>`) fails loudly
 *      so ops typos surface in stderr instead of silently no-op'ing —
 *      mirrors the close-sentinel contract.
 *  (6) Dry-run leaves state untouched.
 *
 * NOTE: The full `ZReportService::open()` happy-path (lock + chain verify
 * + sequence_no allocation + audit log) is covered by ZReportOpenTest.
 * This sentinel only locks the cron wiring + idempotency + dark-branch
 * open semantic so the cron stays safe at V2 multi-branch scale.
 */
class ZOpenSafetyNetCronSentinel extends TestCase
{
    use RefreshDatabase;

    public function test_kernel_registers_z_open_safety_net_lane_at_00_01_paris(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);
        $events   = collect($schedule->events());

        $entry = $events->first(function ($event): bool {
            $description = property_exists($event, 'description')
                ? (string) ($event->description ?? '')
                : '';

            return str_contains((string) $event->command, 'fiscal:open-all-active-branches')
                || str_contains($description, 'NF525 Z-open safety-net');
        });

        $this->assertNotNull(
            $entry,
            'fiscal:open-all-active-branches must be scheduled daily (GOAL-L2-HEAL-07). '
            . 'Without this lane, the V1 LOCAL Le Cayenne loses every morning Z-open '
            . 'unless the cashier manually triggers it, freezing the Z chain extension.'
        );

        $this->assertSame(
            '1 0 * * *',
            (string) $entry->expression,
            "Z-open safety-net must run daily at 00:01 (Paris) — just after the 23:59 close "
            . '(GAP-HUNT 2026-05-25 tightened 00:05→00:01 to shrink the cross-midnight orphan '
            . 'window to ~2 min), so the new business_date starts with an OPEN Z ready for the morning shift.'
        );

        $this->assertSame(
            'Europe/Paris',
            (string) $entry->timezone,
            'Timezone must be Europe/Paris to keep operational/audit semantics aligned with the '
            . 'close lane (lane #17).'
        );

        $this->assertNotEmpty(
            $entry->mutex,
            'withoutOverlapping required so a long-running open cannot race a 00:05 re-fire.'
        );

        $this->assertTrue(
            (bool) $entry->onOneServer,
            'onOneServer required so a horizontally-scaled deployment never double-opens.'
        );
    }

    public function test_artisan_command_is_registered_and_invokable(): void
    {
        $this->assertTrue(
            class_exists(FiscalOpenAllActiveBranchesCommand::class),
            'FiscalOpenAllActiveBranchesCommand must exist (GOAL-L2-HEAL-07).'
        );

        $signatures = collect(Artisan::all())->keys();

        $this->assertContains(
            'fiscal:open-all-active-branches',
            $signatures->all(),
            'fiscal:open-all-active-branches must be registered in Artisan command list.'
        );
    }

    public function test_dark_branch_opens_new_z_and_exits_success(): void
    {
        // Branch with NO Z reports (the post-midnight steady state when the
        // 23:55 close ran cleanly — old Z is CLOSED, no OPEN row exists yet).
        // This is the EXACT scenario L11.1 P0-03 is built to cover: without
        // this cron, the chain would stop here until a cashier ran open()
        // manually.
        $branch = Branch::factory()->create();

        $this->assertSame(
            0,
            ZReport::query()->where('branch_id', $branch->id)->count(),
            'Test fixture sanity: branch must start with NO Z report rows.'
        );

        $exit = Artisan::call('fiscal:open-all-active-branches', [
            '--branch' => $branch->id,
        ]);

        $this->assertSame(
            0,
            $exit,
            'Safety-net cron MUST exit SUCCESS when it opens a new Z on a dark branch. '
            . 'A non-zero exit here would page the on-call every morning during the '
            . 'common happy-path.'
        );

        $row = ZReport::query()->where('branch_id', $branch->id)->first();
        $this->assertNotNull(
            $row,
            'A new Z report row MUST be created on a dark branch — this is the whole '
            . 'point of the Z-OPEN safety-net (chain extension).'
        );
        $this->assertSame(
            ZReport::STATUS_OPEN,
            $row->status,
            'The newly-created Z report must be OPEN (ready to absorb the morning shift).'
        );
        $this->assertSame(
            1,
            (int) $row->sequence_no,
            'First Z on a branch must start at sequence_no=1.'
        );
    }

    public function test_branch_with_existing_open_z_is_skipped_silently_idempotent(): void
    {
        // Seed an OPEN Z report directly (cashier opened manually early-shift,
        // OR this cron already ran today). The safety-net MUST NOT call
        // ZReportService::open() again — that would throw the RuntimeException
        // at ZReportService.php:106-109 ("branch X already has an OPEN Z
        // report") and page the on-call every morning.
        $branch = Branch::factory()->create();

        $existingZ = ZReport::query()->create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => Carbon::now()->subHours(2),
            'opened_by'   => null,
            'status'      => ZReport::STATUS_OPEN,
        ]);

        $exit = Artisan::call('fiscal:open-all-active-branches', [
            '--branch' => $branch->id,
        ]);

        $this->assertSame(
            0,
            $exit,
            'Idempotent: when an OPEN Z already exists for the branch, the command MUST '
            . 'skip silently and exit SUCCESS. A non-zero here = false-alarm pager every '
            . 'morning for every branch where the cashier opened early.'
        );

        $rows = ZReport::query()->where('branch_id', $branch->id)->get();
        $this->assertCount(
            1,
            $rows,
            'No second Z report must be created when one is already OPEN — the cron is '
            . 'idempotent. Double-open would violate ZReportService::open() invariants.'
        );
        $this->assertSame(
            $existingZ->id,
            $rows->first()->id,
            'The pre-existing OPEN Z must be the ONLY Z (unchanged identity).'
        );
        $this->assertSame(
            ZReport::STATUS_OPEN,
            $rows->first()->status,
            'The pre-existing OPEN Z must REMAIN open (untouched by the skip path).'
        );
    }

    public function test_dry_run_does_not_open_new_z_on_dark_branch(): void
    {
        // Dry-run on a dark branch must NOT create a Z row — it must only
        // log "would_open" on the `fiscal` channel.
        $branch = Branch::factory()->create();

        $exit = Artisan::call('fiscal:open-all-active-branches', [
            '--branch'  => $branch->id,
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit, 'Dry-run must exit SUCCESS even when an open would happen.');

        $this->assertSame(
            0,
            ZReport::query()->where('branch_id', $branch->id)->count(),
            'Dry-run must leave a dark branch dark. No Z report row may be created.'
        );
    }

    public function test_unknown_branch_override_fails_loudly(): void
    {
        // An ops typo (--branch=99999) must NOT silently no-op — it must fail
        // with FAILURE exit so the operator notices. Mirrors the close-sentinel
        // contract so the close + open ops UX stays symmetric.
        $exit = Artisan::call('fiscal:open-all-active-branches', [
            '--branch' => 99999,
        ]);

        $this->assertSame(
            1,
            $exit,
            'Unknown / inactive branch override MUST fail loudly so ops see the typo.'
        );
    }
}
