<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

class ZOpenChainVerifiedTest extends TestCase
{
    use RefreshDatabase;

    private ZReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');
        Config::set('fiscal.genesis_prev_hash', str_repeat('0', 64));
        Config::set('fiscal.verify_chain_strict', false);

        $this->service = app(ZReportService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_no_z_reports_chain_trivially_valid(): void
    {
        $branch = Branch::factory()->create();

        $result = $this->service->verifyChain($branch->id);

        $this->assertTrue($result['valid']);
        $this->assertSame(0, $result['count']);
        $this->assertNull($result['first_z_id']);
        $this->assertNull($result['last_z_id']);
        $this->assertSame([], $result['errors']);
    }

    public function test_single_z_with_legacy_genesis_shape_is_valid(): void
    {
        $branch = Branch::factory()->create();
        $report = $this->createClosedZReport($branch);

        $result = $this->service->verifyChain($branch->id);

        $this->assertTrue($result['valid']);
        $this->assertSame(1, $result['count']);
        $this->assertSame($report->id, $result['first_z_id']);
        $this->assertSame($report->id, $result['last_z_id']);
        $this->assertSame([], $result['errors']);
    }

    public function test_chain_of_three_consistent_z_reports_is_valid(): void
    {
        $branch = Branch::factory()->create();
        $reports = $this->createClosedZReports($branch, 3);

        $result = $this->service->verifyChain($branch->id);

        $this->assertTrue($result['valid']);
        $this->assertSame(3, $result['count']);
        $this->assertSame($reports[0]->id, $result['first_z_id']);
        $this->assertSame($reports[2]->id, $result['last_z_id']);
        $this->assertSame([], $result['errors']);
    }

    public function test_signature_tampering_is_detected(): void
    {
        $branch = Branch::factory()->create();
        $report = $this->createClosedZReport($branch);

        $report->forceFill([
            'total_ttc' => 999999.99,
        ])->saveQuietly();

        $result = $this->service->verifyChain($branch->id);

        $this->assertFalse($result['valid']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('signature_mismatch', $result['errors'][0]['kind']);
        $this->assertSame((string) $report->id, (string) $result['errors'][0]['z_id']);
    }

    public function test_chain_break_prev_hash_mismatch_is_detected(): void
    {
        $branch = Branch::factory()->create();
        $reports = $this->createClosedZReports($branch, 2);

        $reports[1]->forceFill([
            'prev_hash' => 'forged-prev-hash',
        ])->saveQuietly();

        $result = $this->service->verifyChain($branch->id);

        $this->assertFalse($result['valid']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('chain_break', $result['errors'][0]['kind']);
        $this->assertSame((string) $reports[1]->id, (string) $result['errors'][0]['z_id']);
    }

    public function test_strict_mode_throws_runtime_exception(): void
    {
        $branch = Branch::factory()->create();
        $report = $this->createClosedZReport($branch);

        $report->forceFill([
            'signature' => 'tampered-signature',
        ])->saveQuietly();

        $this->expectException(RuntimeException::class);

        $this->service->verifyChain($branch->id, true);
    }

    public function test_open_and_close_enforce_pre_checks_when_strict_mode_is_enabled(): void
    {
        Config::set('fiscal.verify_chain_strict', true);

        $branchBlockingOpen = Branch::factory()->create();
        $tamperedClosed = $this->createClosedZReport($branchBlockingOpen);
        $tamperedClosed->forceFill([
            'signature' => 'tampered-signature',
        ])->saveQuietly();

        try {
            $this->service->open($branchBlockingOpen->id);
            $this->fail('open() must fail when verifyChain detects an invalid history in strict mode.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NF525 Z-chain verification failed', $e->getMessage());
        }

        $this->assertSame(
            1,
            ZReport::query()->where('branch_id', $branchBlockingOpen->id)->count()
        );
        $this->assertSame(
            0,
            ZReport::query()
                ->where('branch_id', $branchBlockingOpen->id)
                ->where('status', ZReport::STATUS_OPEN)
                ->count()
        );

        $branchBlockingClose = Branch::factory()->create();
        $firstClosed = $this->createClosedZReport($branchBlockingClose);
        $open = $this->service->open($branchBlockingClose->id);

        $firstClosed->forceFill([
            'prev_hash' => 'forged-prev-hash',
        ])->saveQuietly();

        try {
            $this->service->close($branchBlockingClose->id);
            $this->fail('close() must fail when verifyChain detects an invalid history in strict mode.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('NF525 Z-chain verification failed', $e->getMessage());
        }

        $this->assertSame(ZReport::STATUS_OPEN, $open->fresh()->status);
        $this->assertSame(
            2,
            ZReport::query()->where('branch_id', $branchBlockingClose->id)->count()
        );
    }

    /**
     * @return array<int, ZReport>
     */
    private function createClosedZReports(Branch $branch, int $count): array
    {
        $reports = [];
        $base = Carbon::create(2026, 4, 22, 8, 0, 0);

        for ($index = 0; $index < $count; $index++) {
            Carbon::setTestNow($base->copy()->addHours($index * 2));
            $this->service->open($branch->id);

            Carbon::setTestNow($base->copy()->addHours($index * 2 + 1));
            $reports[] = $this->service->close($branch->id);
        }

        return $reports;
    }

    private function createClosedZReport(Branch $branch): ZReport
    {
        return $this->createClosedZReports($branch, 1)[0];
    }
}
