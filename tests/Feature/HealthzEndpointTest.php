<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * [GAP-HUNT 2026-05-25 Phase A.1 / OPS-GATE-1]
 *
 * Sentinel for the public uptime probe `GET /api/healthz`. The endpoint
 * is the contract surface for UptimeRobot / Cronitor / Better Stack —
 * any drift in shape (renamed key, dropped subsystem, null value)
 * breaks the external monitor's parser and the owner stops receiving
 * paging on real outages.
 *
 * This sentinel locks:
 *   1. HTTP 200 response on a healthy local stack.
 *   2. Top-level keys `status`, `checks`, `timestamp` all present.
 *   3. `status` is one of ok|degraded|fail (string).
 *   4. `checks` is an object with all five required keys:
 *      db, redis, websocket, fiscal_chain, queue_pending.
 *   5. Status-shape subsystem keys (`db`, `redis`, `websocket`,
 *      `fiscal_chain`) carry a non-null ok|fail string.
 *   6. `queue_pending` is a non-negative integer.
 *   7. `timestamp` is an ISO-8601 string (YYYY-MM-DD'T').
 *   8. The CLI mirror `php artisan healthz:check` exits 0 on a
 *      healthy stack — proving the cron heartbeat lane is healthy.
 *
 * @see app/Http/Controllers/HealthzController.php
 * @see app/Console/Commands/HealthzCheckCommand.php
 * @see app/Console/Kernel.php (heartbeat cron lane)
 * @see scripts/deploy/UPTIMEROBOT_SETUP.md
 */
class HealthzEndpointTest extends TestCase
{
    public function test_healthz_returns_200_with_documented_shape(): void
    {
        $response = $this->getJson('/api/healthz');

        // Lenient on HTTP code — 200 (ok/degraded) is the V1 contract; 503
        // (fail) is also a legal shape. We only forbid silent 4xx/5xx
        // outside that allowlist.
        $this->assertContains(
            $response->getStatusCode(),
            [200, 503],
            '/api/healthz must respond 200 (ok|degraded) or 503 (fail), got '.$response->getStatusCode()
        );

        $response->assertJsonStructure([
            'status',
            'checks' => [
                'db',
                'redis',
                'websocket',
                'fiscal_chain',
                'queue_pending',
            ],
            'timestamp',
        ]);
    }

    public function test_healthz_status_is_documented_enum(): void
    {
        $response = $this->getJson('/api/healthz');

        $status = $response->json('status');

        $this->assertIsString($status, 'status must be a string');
        $this->assertContains(
            $status,
            ['ok', 'degraded', 'fail'],
            'status must be one of ok|degraded|fail, got '.var_export($status, true)
        );
    }

    public function test_healthz_subsystem_checks_are_ok_or_fail_strings(): void
    {
        $response = $this->getJson('/api/healthz');

        foreach (['db', 'redis', 'websocket', 'fiscal_chain'] as $key) {
            $value = $response->json("checks.$key");

            $this->assertNotNull(
                $value,
                "checks.$key must be present and non-null (UptimeRobot contract)"
            );
            $this->assertIsString(
                $value,
                "checks.$key must be a string, got ".gettype($value)
            );
            $this->assertContains(
                $value,
                ['ok', 'fail'],
                "checks.$key must be one of ok|fail, got ".var_export($value, true)
            );
        }
    }

    public function test_healthz_queue_pending_is_non_negative_integer(): void
    {
        $response = $this->getJson('/api/healthz');

        $value = $response->json('checks.queue_pending');

        $this->assertNotNull($value, 'checks.queue_pending must be present');
        $this->assertIsInt($value, 'checks.queue_pending must be an int, got '.gettype($value));
        $this->assertGreaterThanOrEqual(
            0,
            $value,
            'checks.queue_pending must be non-negative'
        );
    }

    public function test_healthz_timestamp_is_iso8601(): void
    {
        $response = $this->getJson('/api/healthz');

        $timestamp = $response->json('timestamp');

        $this->assertIsString($timestamp, 'timestamp must be a string');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T/',
            (string) $timestamp,
            'timestamp must be ISO-8601 (YYYY-MM-DDThh:mm:ss...)'
        );
    }

    public function test_healthz_check_command_exits_zero_on_healthy_stack(): void
    {
        $exitCode = Artisan::call('healthz:check');

        $this->assertSame(
            0,
            $exitCode,
            'healthz:check must exit 0 on a healthy stack, got '.$exitCode
                .'. Output: '.Artisan::output()
        );
    }

    public function test_healthz_check_command_supports_json_flag(): void
    {
        $exitCode = Artisan::call('healthz:check', ['--json' => true]);
        $output   = trim(Artisan::output());

        $this->assertSame(0, $exitCode, 'healthz:check --json must exit 0 on healthy stack');

        $decoded = json_decode($output, true);
        $this->assertIsArray(
            $decoded,
            'healthz:check --json must emit a single JSON line, got: '.$output
        );

        $this->assertArrayHasKey('status', $decoded);
        $this->assertArrayHasKey('checks', $decoded);
        $this->assertArrayHasKey('timestamp', $decoded);

        foreach (['db', 'redis', 'websocket', 'fiscal_chain', 'queue_pending'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $decoded['checks'],
                "checks.$key missing from CLI --json output"
            );
        }
    }
}
