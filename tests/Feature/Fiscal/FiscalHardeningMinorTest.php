<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\Order;
use App\Models\ZReport;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Consolidated coverage for the three small hardening items:
 *   - [POS-9-H.2.7 / F-B4]  Z report signature is timezone-stable.
 *   - [POS-9-H.2.9 / F-B7]  audit_logs rollback blocked in production.
 *   - [POS-9-H.2.10 / F-B10] FiscalSequenceService::next() uses a
 *                            row-level DB lock in addition to the cache
 *                            lock (smoke test — SQLite ignores FOR
 *                            UPDATE, so we check the path still returns
 *                            a monotonic sequence under serial calls).
 */
class FiscalHardeningMinorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-hardening-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-hardening-z');
    }

    // ---- H.2.7 — timezone ------------------------------------------------

    public function test_z_signature_canonicalises_closed_at_in_utc(): void
    {
        // Directly exercise the private sign() method with closed_at in
        // two different timezones but representing the SAME absolute
        // moment. The signature must be identical — that is the whole
        // point of the UTC canonicalisation.
        $svc = app(ZReportService::class);
        $r   = new \ReflectionClass($svc);
        $m   = $r->getMethod('sign');
        $m->setAccessible(true);

        $tParis = Carbon::parse('2026-04-22 18:00:00', 'Europe/Paris');
        $tUtc   = $tParis->copy()->utc();
        $tNyc   = $tParis->copy()->setTimezone('America/New_York');
        $this->assertSame($tParis->getTimestamp(), $tUtc->getTimestamp());
        $this->assertSame($tParis->getTimestamp(), $tNyc->getTimestamp());

        $aggregates = ['total_ttc' => 100.0];

        $sigParis = $m->invoke($svc, 1, 'prev', 1, $aggregates, $tParis);
        $sigUtc   = $m->invoke($svc, 1, 'prev', 1, $aggregates, $tUtc);
        $sigNyc   = $m->invoke($svc, 1, 'prev', 1, $aggregates, $tNyc);

        $this->assertSame($sigParis, $sigUtc,
            'Same absolute moment in Paris vs UTC must produce identical signature.');
        $this->assertSame($sigParis, $sigNyc,
            'Same absolute moment in Paris vs New York must produce identical signature.');
    }

    public function test_z_signature_differs_when_absolute_moment_differs(): void
    {
        // Negative control: if the absolute moment changes, the
        // signature must change too (otherwise the UTC canonicalisation
        // would have flattened everything to a fixed value).
        $svc = app(ZReportService::class);
        $r   = new \ReflectionClass($svc);
        $m   = $r->getMethod('sign');
        $m->setAccessible(true);

        $a = Carbon::parse('2026-04-22 18:00:00 UTC');
        $b = Carbon::parse('2026-04-22 18:00:01 UTC');   // 1 second later

        $sigA = $m->invoke($svc, 1, 'prev', 1, ['total_ttc' => 100.0], $a);
        $sigB = $m->invoke($svc, 1, 'prev', 1, ['total_ttc' => 100.0], $b);

        $this->assertNotSame($sigA, $sigB);
    }

    // ---- H.2.9 — audit_logs rollback guard -------------------------------

    public function test_audit_logs_down_is_blocked_in_production(): void
    {
        $migration = include base_path('database/migrations/2026_04_22_000002_create_audit_logs_table.php');

        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to drop audit_logs/');

        $migration->down();
    }

    public function test_audit_logs_unique_index_down_is_blocked_in_production(): void
    {
        $migration = include base_path('database/migrations/2026_04_22_100000_add_unique_chain_index_to_audit_logs.php');

        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to drop audit_logs_branch_prev_unique/');

        $migration->down();
    }

    // ---- H.2.10 — sequence monotonicity under serial calls ---------------

    public function test_fiscal_sequence_is_monotonic_under_serial_calls(): void
    {
        $branch = Branch::factory()->create();
        $svc    = app(FiscalSequenceService::class);

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $n = $svc->next($branch->id);
            Order::factory()->create([
                'branch_id'          => $branch->id,
                'fiscal_sequence_no' => $n,
            ]);
            $ids[] = $n;
        }

        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }
}
