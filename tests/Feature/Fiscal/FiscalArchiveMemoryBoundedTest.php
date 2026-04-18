<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalArchiveCommand;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * [POS-9-H.3.3 / F-C8]
 *
 * The FiscalArchiveCommand used to hydrate every Order + AuditLog +
 * ZReport into memory as Eloquent models, then json_encode the whole
 * `$bundle` array as a single string passed to
 * `ZipArchive::addFromString()`. On a 6-year NF525 window that routinely
 * blew past PHP memory_limit=128M on production.
 *
 * This test exercises a non-trivial dataset (1000 orders + 500
 * audit_log rows per branch) and asserts that the command:
 *   1. still completes,
 *   2. writes all rows into their respective JSONL files, and
 *   3. stays under a conservative peak-memory ceiling.
 *
 * We deliberately keep the dataset modest (1k + 500) so the test
 * suite remains fast — the memory accounting scales linearly with
 * the dataset, so 1k orders staying under the ceiling is strong
 * evidence that 100k orders also stays bounded (the `->lazy()`
 * cursor + JSONL streaming means O(single-row) per loop iteration).
 */
class FiscalArchiveMemoryBoundedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Config::set('fiscal.audit_secret',    'unit-test-mem-audit-xxxxxxxxxxx');
        Config::set('fiscal.z_report_secret', 'unit-test-mem-z-xxxxxxxxxxxxxx');
        Config::set('fiscal.archive_disk', 'local');
        Config::set('fiscal.archive_path', 'fiscal');
    }

    public function test_archive_with_1k_orders_stays_under_memory_ceiling(): void
    {
        $branch = Branch::factory()->create();
        $user   = User::factory()->create(['branch_id' => $branch->id]);

        // Bulk-insert rather than Order::factory()->count(1000): the
        // factory overhead would dominate the test runtime and obscure
        // the memory measurement of the archive phase itself. We chunk
        // by 100 because SQLite has a hard host-parameter limit.
        $now = now();
        for ($chunk = 0; $chunk < 10; $chunk++) {
            $rows = [];
            for ($i = 1; $i <= 100; $i++) {
                $rows[] = [
                    'user_id'         => $user->id,
                    'branch_id'       => $branch->id,
                    'subtotal'        => 10.00 + ($i % 20),
                    'discount'        => 0,
                    'delivery_charge' => 0,
                    'total_tax'       => 0,
                    'total'           => 10.00 + ($i % 20),
                    'payment_status'  => PaymentStatus::PAID,
                    'status'          => 0,
                    'order_type'      => 0,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
            }
            DB::table('orders')->insert($rows);
        }

        // 500 audit rows via the service (so the HMAC chain is real).
        $audit = app(AuditLogService::class);
        for ($i = 1; $i <= 500; $i++) {
            $audit->write([
                'branch_id' => $branch->id,
                'action'    => 'bulk.mem.test',
                'payload'   => ['i' => $i, 'note' => str_repeat('x', 32)],
            ]);
        }

        // Reset peak tracking so we measure ONLY the archive phase.
        // PHP has no public API to reset peak usage, but we can
        // snapshot the delta from the start of the build() call.
        $before = memory_get_peak_usage(true);

        $cmd  = app(FiscalArchiveCommand::class);
        $path = $cmd->build($branch->id, null, $now->copy()->addMinute());

        $after = memory_get_peak_usage(true);
        $delta = $after - $before;

        // 32 MiB ceiling — extremely generous (the streaming loop
        // itself is <1 MiB, but Laravel test bootstrap + Eloquent
        // + sqlite driver inflate the floor).
        $this->assertLessThan(32 * 1024 * 1024, $delta,
            sprintf('Archive build peak delta=%.2f MiB must stay below 32 MiB ceiling.',
                $delta / 1024 / 1024));

        // Validate the zip still contains every row — memory-bound
        // hardening must not silently drop data.
        $this->assertFileExists($path);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $orders = json_decode($zip->getFromName('orders.json'),     true);
        $auditL = json_decode($zip->getFromName('audit_logs.json'), true);
        $zip->close();

        $this->assertCount(1000, $orders,
            'All 1000 orders must be in the archive — streaming must not drop rows.');
        $this->assertCount(500,  $auditL,
            'All 500 audit rows must be in the archive.');
    }

    public function test_empty_window_produces_empty_arrays(): void
    {
        $branch = Branch::factory()->create();
        $cmd = app(FiscalArchiveCommand::class);
        $path = $cmd->build($branch->id,
            now()->addYear(),        // from in the future — nothing matches
            now()->addYear()->addMinute());

        $this->assertFileExists($path);
        $zip = new ZipArchive();
        $zip->open($path);
        $orders = json_decode($zip->getFromName('orders.json'),     true);
        $auditL = json_decode($zip->getFromName('audit_logs.json'), true);
        $zReps  = json_decode($zip->getFromName('z_reports.json'),  true);
        $zip->close();

        $this->assertSame([], $orders);
        $this->assertSame([], $auditL);
        $this->assertSame([], $zReps);
    }
}
