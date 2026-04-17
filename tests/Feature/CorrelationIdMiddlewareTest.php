<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Contract tests for CorrelationIdMiddleware — V1 OBS_HEALTH_CORR task.
 *
 * @see app/Http/Middleware/CorrelationIdMiddleware.php
 * @see docs/OBSERVABILITY.md
 */
class CorrelationIdMiddlewareTest extends TestCase
{
    public function test_generates_correlation_id_if_absent(): void
    {
        $response = $this->getJson('/api/health/live');

        $cid = $response->headers->get('X-Correlation-ID');
        $this->assertNotEmpty($cid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            (string) $cid,
            'Auto-generated correlation id must be a UUID v4'
        );
    }

    public function test_propagates_correlation_id_from_request(): void
    {
        $cid = 'test-cid-'.uniqid();

        $response = $this->withHeaders(['X-Correlation-ID' => $cid])
            ->getJson('/api/health/live');

        $this->assertSame($cid, $response->headers->get('X-Correlation-ID'));
    }

    public function test_correlation_id_is_stable_within_request(): void
    {
        $cid = 'stable-'.uniqid();

        $response = $this->withHeaders(['X-Correlation-ID' => $cid])
            ->getJson('/api/health/live');

        // Must appear once in response headers, identical to the request value.
        $this->assertSame($cid, $response->headers->get('X-Correlation-ID'));
        $this->assertNotEmpty($response->headers->all('X-Correlation-ID'));
    }

    public function test_correlation_id_is_attached_to_subsequent_log_entries(): void
    {
        // Exercise the middleware in-process and assert that a log line written
        // AFTER handle() carries the correlation id in its context, which is the
        // observable contract we care about (operators grep logs by cid).
        $middleware = new \App\Http\Middleware\CorrelationIdMiddleware();
        $request = \Illuminate\Http\Request::create('/api/health/live', 'GET');
        $cid = 'log-ctx-'.uniqid();
        $request->headers->set('X-Correlation-ID', $cid);

        $captured = [];
        Log::listen(function ($event) use (&$captured) {
            $captured[] = $event;
        });

        $middleware->handle($request, function ($req) {
            Log::info('probe-line');
            return response('ok');
        });

        $this->assertNotEmpty($captured, 'Log listener must fire for the probe-line');
        $probe = end($captured);
        $this->assertSame('probe-line', $probe->message);
        $this->assertArrayHasKey('correlation_id', $probe->context);
        $this->assertSame($cid, $probe->context['correlation_id']);
    }

    public function test_each_request_without_header_gets_a_unique_id(): void
    {
        $first = $this->getJson('/api/health/live');
        $second = $this->getJson('/api/health/live');

        $this->assertNotEmpty($first->headers->get('X-Correlation-ID'));
        $this->assertNotEmpty($second->headers->get('X-Correlation-ID'));
        $this->assertNotSame(
            $first->headers->get('X-Correlation-ID'),
            $second->headers->get('X-Correlation-ID'),
            'Each correlation id must be unique per request when auto-generated'
        );
    }
}
