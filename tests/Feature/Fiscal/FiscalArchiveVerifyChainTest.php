<?php

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * [W9.A / G2 finding] Defense-in-depth: the fiscal archive command MUST
 * verify the Z-chain integrity BEFORE producing the bundle. NF525 archives
 * are evidence; shipping a bundle whose chain is broken would propagate
 * corruption into a tamper-evident long-term store and weaken the
 * protection model.
 */
class FiscalArchiveVerifyChainTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        Config::set('fiscal.audit_secret', 'unit-test-audit-padding-48-chars-required-by-prod-guard');
        Config::set('fiscal.z_report_secret', 'unit-test-z-padding-48-chars-required-by-prod-guard');
        Config::set('fiscal.archive_disk', 'local');
        Config::set('fiscal.archive_path', 'fiscal');
        Config::set('fiscal.verify_chain_strict', false); // service-level non-strict
        Config::set('fiscal.verify_chain_before_archive', true);

        $this->branch = Branch::factory()->create();
    }

    public function test_archive_succeeds_when_z_chain_is_intact(): void
    {
        $this->seedClosedZ();

        $exit = Artisan::call('foodking:fiscal:archive', [
            'branch_id' => $this->branch->id,
            '--from'    => '2026-04-22',
            '--to'      => '2026-04-22',
        ]);

        $this->assertSame(0, $exit, 'Healthy chain should yield SUCCESS exit code.');

        $manifest = $this->readManifest();
        $this->assertTrue($manifest['z_chain_verified']);
        $this->assertNotNull($manifest['z_chain_verify_meta']);
        $this->assertSame(1, $manifest['z_chain_verify_meta']['count']);
        $this->assertNotNull($manifest['z_chain_verify_meta']['verified_at']);
    }

    public function test_archive_aborts_when_z_chain_signature_is_corrupted(): void
    {
        $z = $this->seedClosedZ();

        // Corrupt: tamper a signed totals field after close (saveQuietly so
        // observers do not re-sign). signature_mismatch on next verify.
        $z->total_ttc = 999999.99;
        $z->saveQuietly();

        // Spy fiscal log channel to assert structured CRITICAL emission
        // (evidence trail for ops; testing through Artisan::output() is
        // brittle because Symfony's BufferedOutput strips ANSI styles
        // from `$this->error()` calls in some configurations).
        $logSpy = \Mockery::spy('log');
        Log::shouldReceive('channel')->with('fiscal')->andReturn($logSpy);

        $exit = Artisan::call('foodking:fiscal:archive', [
            'branch_id' => $this->branch->id,
            '--from'    => '2026-04-22',
            '--to'      => '2026-04-22',
        ]);

        $this->assertSame(1, $exit, 'Broken chain MUST yield FAILURE exit code (no bundle produced).');

        $disk = Storage::disk('local');
        $this->assertFalse(
            $disk->exists("fiscal/{$this->branch->id}/20260422-20260422.zip"),
            'Bundle MUST NOT be written when chain integrity check fails.'
        );

        $logSpy->shouldHaveReceived('critical')
            ->withArgs(function ($message, $context) {
                return is_string($message)
                    && str_contains($message, 'Z-chain integrity violated')
                    && ($context['event'] ?? null) === 'fiscal.archive.verify_chain.failed';
            })
            ->once();
    }

    public function test_no_verify_flag_bypasses_check_and_marks_manifest_unverified(): void
    {
        $z = $this->seedClosedZ();
        $z->total_ttc = 1.0;
        $z->saveQuietly(); // chain corrupt

        $exit = Artisan::call('foodking:fiscal:archive', [
            'branch_id'   => $this->branch->id,
            '--from'      => '2026-04-22',
            '--to'        => '2026-04-22',
            '--no-verify' => true,
        ]);

        $this->assertSame(0, $exit, 'With --no-verify, archive proceeds even on broken chain (ops recovery).');

        $manifest = $this->readManifest();
        $this->assertFalse(
            $manifest['z_chain_verified'],
            'Manifest MUST mark unverified bundles to preserve evidence semantics.'
        );
        $this->assertNull($manifest['z_chain_verify_meta']);
    }

    public function test_verify_disabled_via_config_skips_check_silently(): void
    {
        Config::set('fiscal.verify_chain_before_archive', false);

        $z = $this->seedClosedZ();
        $z->total_ttc = 1.0;
        $z->saveQuietly();

        $exit = Artisan::call('foodking:fiscal:archive', [
            'branch_id' => $this->branch->id,
            '--from'    => '2026-04-22',
            '--to'      => '2026-04-22',
        ]);

        $this->assertSame(0, $exit);

        $manifest = $this->readManifest();
        $this->assertFalse($manifest['z_chain_verified']);
    }

    public function test_archive_succeeds_for_branch_with_no_z_reports_yet(): void
    {
        // verifyChain returns valid=true with count=0 when no closed Z exist.
        // Archive must not abort: empty bundle is legitimate.
        $exit = Artisan::call('foodking:fiscal:archive', [
            'branch_id' => $this->branch->id,
            '--from'    => '2026-04-22',
            '--to'      => '2026-04-22',
        ]);

        $this->assertSame(0, $exit);

        $manifest = $this->readManifest();
        $this->assertTrue($manifest['z_chain_verified']);
        $this->assertSame(0, $manifest['z_chain_verify_meta']['count']);
    }

    private function seedClosedZ(): ZReport
    {
        $zs = app(ZReportService::class);
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

        return $z->fresh();
    }

    private function readManifest(): array
    {
        $disk = Storage::disk('local');
        $path = $disk->path("fiscal/{$this->branch->id}/20260422-20260422.zip");
        $this->assertFileExists($path);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        return $manifest;
    }
}
