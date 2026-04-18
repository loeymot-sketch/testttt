<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\FiscalArchiveCommand;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * [POS-9.4.11 / POS-GA-F-01]
 *
 * Proves that the archive bundle contains every fiscal artifact over
 * the requested period and that the bundle is deterministic (round-trip
 * stable) — the minimum NF525 requires to prove a 6-year history.
 */
class FiscalArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Config::set('fiscal.audit_secret',   'unit-test-audit');
        Config::set('fiscal.z_report_secret', 'unit-test-z');
        Config::set('fiscal.archive_disk',   'local');
        Config::set('fiscal.archive_path',   'fiscal');

        $this->branch = Branch::factory()->create();
    }

    public function test_zip_contains_all_z_reports_and_orders_and_audit_logs(): void
    {
        $zs    = app(ZReportService::class);
        $audit = app(AuditLogService::class);

        Carbon::setTestNow(Carbon::create(2026, 4, 22, 8, 0, 0));
        $zs->open($this->branch->id);

        Carbon::setTestNow(Carbon::create(2026, 4, 22, 9, 30, 0));
        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'total'          => 42.00,
            'payment_status' => PaymentStatus::PAID,
        ]);
        $audit->write([
            'branch_id' => $this->branch->id,
            'action'    => 'order.create',
            'payload'   => ['id' => $order->id, 'total' => 42.00],
        ]);

        Carbon::setTestNow(Carbon::create(2026, 4, 22, 23, 0, 0));
        $z = $zs->close($this->branch->id);

        Carbon::setTestNow(null);

        $cmd = app(FiscalArchiveCommand::class);
        $path = $cmd->build(
            $this->branch->id,
            Carbon::create(2026, 4, 22)->startOfDay(),
            Carbon::create(2026, 4, 22)->endOfDay()
        );

        $this->assertFileExists($path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $manifest = $zip->getFromName('manifest.json');
        $zip->close();
        $this->assertNotFalse($manifest);

        $data = json_decode($manifest, true);
        $this->assertSame($this->branch->id, $data['meta']['branch_id']);
        $this->assertSame(1, $data['meta']['schema_version']);
        $this->assertSame(6, $data['meta']['retention_years']);

        $this->assertCount(1, $data['z_reports'],
            'Archive must contain the Z closed during the window.');
        $this->assertSame($z->id, $data['z_reports'][0]['id']);

        $this->assertCount(1, $data['orders']);
        $this->assertSame($order->id, $data['orders'][0]['id']);

        $this->assertCount(1, $data['audit_logs']);
        $this->assertSame('order.create', $data['audit_logs'][0]['action']);
    }

    public function test_round_trip_deterministic(): void
    {
        $zs    = app(ZReportService::class);
        $audit = app(AuditLogService::class);

        $zs->open($this->branch->id);
        Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'total'          => 10.00,
            'payment_status' => PaymentStatus::PAID,
        ]);
        $audit->write([
            'branch_id' => $this->branch->id,
            'action'    => 'order.create',
            'payload'   => ['id' => 1, 'total' => 10.00],
        ]);
        $zs->close($this->branch->id);

        $cmd  = app(FiscalArchiveCommand::class);
        $from = null; // default — all history for the branch
        $to   = Carbon::now()->addMinute();

        $p1 = $cmd->build($this->branch->id, $from, $to);
        $h1 = hash_file('sha256', $p1);

        $p2 = $cmd->build($this->branch->id, $from, $to);
        $h2 = hash_file('sha256', $p2);

        $this->assertSame($h1, $h2,
            'Rebuilding the archive with identical inputs must produce an identical zip (hash stable).');
    }

    public function test_archive_respects_window_boundaries(): void
    {
        $zs = app(ZReportService::class);

        // Z closed inside the window.
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 8, 0, 0));
        $zs->open($this->branch->id);
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 18, 0, 0));
        $zInside = $zs->close($this->branch->id);

        // Z closed BEFORE the window — must NOT appear in the archive.
        Carbon::setTestNow(Carbon::create(2026, 4, 10, 8, 0, 0));
        $zs->open($this->branch->id);
        Carbon::setTestNow(Carbon::create(2026, 4, 10, 18, 0, 0));
        $zs->close($this->branch->id);

        Carbon::setTestNow(null);

        $cmd = app(FiscalArchiveCommand::class);
        $path = $cmd->build(
            $this->branch->id,
            Carbon::create(2026, 4, 20)->startOfDay(),
            Carbon::create(2026, 4, 20)->endOfDay()
        );

        $zip = new ZipArchive();
        $zip->open($path);
        $data = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $ids = array_map(static fn ($z) => $z['id'], $data['z_reports']);
        $this->assertSame([$zInside->id], $ids);
    }
}
