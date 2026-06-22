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

        // [POS-9-H.3.3] Layout bumped to schema_version 2: each
        // collection is now its own JSON file inside the zip, so memory
        // stays bounded when building a 6-year archive.
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zReports = json_decode($zip->getFromName('z_reports.json'),  true);
        $orders   = json_decode($zip->getFromName('orders.json'),     true);
        $auditLog = json_decode($zip->getFromName('audit_logs.json'), true);
        $zip->close();

        $this->assertSame($this->branch->id, $manifest['branch_id']);
        // [W9.A] schema_version bumped to 3: manifest now records the
        // pre-archive Z-chain verification result (defense-in-depth).
        $this->assertSame(3,                  $manifest['schema_version']);
        $this->assertSame(6,                  $manifest['retention_years']);
        $this->assertArrayHasKey('layout', $manifest);
        $this->assertArrayHasKey('z_chain_verified', $manifest);
        $this->assertArrayHasKey('z_chain_verify_meta', $manifest);

        $this->assertCount(1, $zReports,
            'Archive must contain the Z closed during the window.');
        $this->assertSame($z->id, $zReports[0]['id']);

        $this->assertCount(1, $orders);
        $this->assertSame($order->id, $orders[0]['id']);

        // [GOAL-K2-HEAL-06 2026-05-24] Phase K.7 K7-FIND-2 P2:
        // ZReport::updated hook in AppServiceProvider now writes a
        // 'z_report.closed' audit_logs anchor row on every Z-close so the
        // audit_logs chain carries a cross-chain anchor to z_reports.
        // The archive bundle must therefore contain BOTH the test's
        // explicit 'order.create' write AND the implicit 'z_report.closed'
        // anchor produced by the close above. Order is insertion-order
        // (autoincrement id): the close happens AFTER the 'order.create'
        // write, so the anchor row is the second entry.
        $this->assertCount(2, $auditLog);
        $actions = array_column($auditLog, 'action');
        sort($actions);
        $this->assertSame(['order.create', 'z_report.closed'], $actions);
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
        $zReports = json_decode($zip->getFromName('z_reports.json'), true);
        $zip->close();

        $ids = array_map(static fn ($z) => $z['id'], $zReports);
        $this->assertSame([$zInside->id], $ids);
    }
}
