<?php

namespace Tests\Feature\Observability;

use Tests\TestCase;

/**
 * [GOAL-CMS-2026-05-18 S-P0-A sentinel — Round 1 SRE-001]
 *
 * `ws:heartbeat` cache key is READ by `SyncOverviewController::wsHeartbeatStatus`
 * (line 531) but the original codebase NEVER WROTE it anywhere — admin
 * observability dashboard would stay green-emerald-50 while Pusher died
 * silently (Round 1 SRE classified this as P0 silent-failure-of-the-monitor).
 *
 * Heal: DispatchDomainEventsJob writes the heartbeat key with a 120s TTL
 * on every successful broadcast. Successful broadcast proves Pusher is
 * responsive — direct positive signal, not a heuristic.
 *
 * This sentinel asserts the heal stays in place: a future regression that
 * removes the Cache::put line would silently re-open the blind-ops window.
 *
 * @group observability
 * @group sentinel
 */
class WsHeartbeatWriteSentinelTest extends TestCase
{
    public function test_dispatch_domain_events_job_writes_ws_heartbeat_on_success(): void
    {
        $source = file_get_contents(base_path('app/Jobs/DispatchDomainEventsJob.php'));
        $this->assertNotFalse($source);

        $this->assertMatchesRegularExpression(
            '/Cache::put\(\s*[\'"]ws:heartbeat[\'"]/',
            $source,
            'S-P0-A: DispatchDomainEventsJob MUST call Cache::put(\'ws:heartbeat\', ...) on '
            . 'successful broadcast — closes Round 1 SRE-001 (cache key READ never WRITTEN). '
            . 'SyncOverviewController:531 reads this key to surface Pusher health on the '
            . 'admin observability dashboard.'
        );

        // The write must be inside the broadcast-success path (after the
        // $broadcaster->broadcast call) — not at the top of handle()
        // which would write even on broadcast failure.
        $this->assertMatchesRegularExpression(
            '/broadcaster->broadcast\([^)]+\)\s*;.*Cache::put\(\s*[\'"]ws:heartbeat[\'"]/s',
            $source,
            'S-P0-A: ws:heartbeat write must FOLLOW $broadcaster->broadcast() so it only '
            . 'fires when Pusher actually accepted the broadcast.'
        );
    }
}
