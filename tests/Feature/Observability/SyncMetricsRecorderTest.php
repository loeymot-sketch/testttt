<?php

namespace Tests\Feature\Observability;

use App\Services\Observability\SyncMetricsRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SyncMetricsRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_event_dispatched_inserts_outbox_dispatch_latency_metric(): void
    {
        app(SyncMetricsRecorder::class)->recordEventDispatched('OrderUpdated', 10, 123, '11111111-1111-1111-1111-111111111111');

        $this->assertDatabaseHas('sync_metrics', [
            'metric_type' => 'outbox.dispatch_latency_ms',
            'branch_id' => 10,
            'value' => 123,
            'correlation_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $row = DB::table('sync_metrics')->first();
        $this->assertSame('OrderUpdated', json_decode($row->labels, true)['event_type']);
    }

    public function test_record_websocket_auth_failure_inserts_count_one_metric(): void
    {
        app(SyncMetricsRecorder::class)->recordWebSocketAuthFailure(2, '22222222-2222-2222-2222-222222222222');

        $this->assertDatabaseHas('sync_metrics', [
            'metric_type' => 'ws.auth_failure',
            'branch_id' => 2,
            'value' => 1,
            'correlation_id' => '22222222-2222-2222-2222-222222222222',
        ]);
    }

    public function test_record_kds_sync_fallback_includes_reason_label(): void
    {
        app(SyncMetricsRecorder::class)->recordKdsSyncFallback(3, 5000, 'WS_DISCONNECTED');

        $row = DB::table('sync_metrics')->first();

        $this->assertSame('kds.sync_fallback_interval_ms', $row->metric_type);
        $this->assertSame('WS_DISCONNECTED', json_decode($row->labels, true)['reason']);
    }

    public function test_record_client_metric_whitelist_rejects_unknown_type(): void
    {
        Log::spy();

        app(SyncMetricsRecorder::class)->recordClientMetric('unknown.metric', 1, 1, []);

        $this->assertDatabaseCount('sync_metrics', 0);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_record_silently_swallows_db_failure_to_protect_business_flow(): void
    {
        Log::spy();
        DB::shouldReceive('table')->with('sync_metrics')->andThrow(new \RuntimeException('db down'));

        app(SyncMetricsRecorder::class)->recordWebSocketAuthFailure(1);

        Log::shouldHaveReceived('warning')->once();
        $this->assertTrue(true);
    }

    public function test_recorder_is_singleton_in_container(): void
    {
        $first = app(SyncMetricsRecorder::class);
        $second = app(SyncMetricsRecorder::class);

        $this->assertSame($first, $second);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
