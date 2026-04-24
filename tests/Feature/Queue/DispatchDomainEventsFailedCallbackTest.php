<?php

namespace Tests\Feature\Queue;

use App\Enums\EventType;
use App\Exceptions\PayloadMismatchException;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * [F-NEW03 / Audit T G3+G5 / T-MISS-A,B,C] Verifies the structured
 * observability payload of DispatchDomainEventsJob::failed():
 *
 *   T-MISS-A — null DomainEvent path is crash-free and still pages.
 *   T-MISS-B — `contract_violation:` prefix is mirrored from DB last_error
 *              into the Log::error context (G3 fix).
 *   T-MISS-C — Sentry SDK absence does not raise a warning/notice (G4
 *              defense-in-depth — codebase ships without sentry/sentry-laravel).
 *
 * The failed() callback is the LAST observability hook before the job is
 * declared terminal; it MUST be both pager-grade (Log::error level) AND
 * crash-free regardless of the database state.
 */
class DispatchDomainEventsFailedCallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * [T-MISS-A / Audit T G5] When the DomainEvent row was deleted between
     * the last attempt and the failed() callback, the job MUST NOT crash
     * AND the structured log MUST still carry the raw domain_event_id from
     * the job state so on-call can correlate.
     */
    public function test_failed_callback_does_not_crash_when_domain_event_row_is_missing(): void
    {
        $missingId = 999_999_999;
        $job = new DispatchDomainEventsJob($missingId);
        $logSpy = Log::spy();

        $job->failed(new RuntimeException('boom'));

        $captured = $this->captureFinalFailureContext($logSpy);
        $this->assertSame($missingId, $captured['domain_event_id']);
        $this->assertNull($captured['event_type']);
        $this->assertNull($captured['delay_since_creation_seconds']);
        $this->assertSame('boom', $captured['error']);
        $this->assertSame(RuntimeException::class, $captured['error_class']);
        $this->assertSame('runtime_failure', $captured['error_category']);
        $this->assertSame('boom', $captured['last_error']);

        // Defense-in-depth: no row was created/updated for the missing id.
        $this->assertDatabaseMissing('domain_events', ['id' => $missingId]);
    }

    /**
     * [T-MISS-B / Audit T G3] PayloadMismatchException MUST surface the
     * `contract_violation:` prefix in BOTH the database `last_error` field
     * AND the Log::error context (`last_error` mirror + `error_category`).
     * This is the core G3 remediation: previously the prefix was only in the
     * DB, so log scanners filtering on this prefix lost terminal failures.
     */
    public function test_failed_callback_mirrors_contract_violation_prefix_in_log_context(): void
    {
        $event = $this->makeDomainEvent();
        $exception = new PayloadMismatchException(
            'envelope missing required key',
            ['missing:order_id'],
            EventType::ORDER_CREATED
        );
        $job = new DispatchDomainEventsJob($event->id);
        $logSpy = Log::spy();

        $job->failed($exception);
        $captured = $this->captureFinalFailureContext($logSpy);

        // DB invariant — NEW-01 G1 contract preserved.
        $event->refresh();
        $this->assertSame(
            'contract_violation: envelope missing required key',
            (string) $event->last_error
        );

        // [Audit T G3] Log context MUST carry the same prefixed payload.
        $this->assertSame(
            'contract_violation: envelope missing required key',
            $captured['last_error']
        );
        $this->assertSame('contract_violation', $captured['error_category']);
        $this->assertSame('envelope missing required key', $captured['error']);
        $this->assertSame(PayloadMismatchException::class, $captured['error_class']);

        // Sanity: NO double-prefixing (would be 'contract_violation: contract_violation: ...').
        $this->assertStringNotContainsString(
            'contract_violation: contract_violation:',
            (string) $event->last_error
        );
    }

    /**
     * [T-MISS-B sibling] Generic exceptions (RuntimeException etc.) MUST NOT
     * receive a synthetic prefix and MUST be categorized as `runtime_failure`.
     * This guards against accidental over-classification of non-contract errors
     * as contract violations.
     */
    public function test_failed_callback_categorizes_generic_exceptions_as_runtime_failure(): void
    {
        $event = $this->makeDomainEvent();
        $job = new DispatchDomainEventsJob($event->id);
        $logSpy = Log::spy();

        $job->failed(new RuntimeException('pusher_unreachable'));
        $captured = $this->captureFinalFailureContext($logSpy);

        // [Audit Claude T-CLA-1] delay_since_creation_seconds must always be
        // non-negative (Carbon 2 returns absolute, Carbon 3 — Laravel 11+ —
        // may return signed). Cast int + clamp guard tracked here so a future
        // Carbon upgrade does not silently feed negative durations to dashboards.
        $this->assertGreaterThanOrEqual(
            0,
            $captured['delay_since_creation_seconds'],
            'delay_since_creation_seconds MUST be >= 0 even under clock skew (Carbon 3 forward-compat).'
        );

        // [Audit Claude T-CLA-2] attempts is int|null — never string or array.
        $this->assertTrue(
            is_int($captured['attempts']) || is_null($captured['attempts']),
            'attempts MUST be int|null in the structured log context.'
        );

        $event->refresh();
        $this->assertSame('pusher_unreachable', (string) $event->last_error);
        $this->assertSame('runtime_failure', $captured['error_category']);
        $this->assertSame('pusher_unreachable', $captured['last_error']);
        $this->assertStringNotContainsString('contract_violation:', $captured['last_error']);
    }

    /**
     * [T-MISS-C / Audit T G4] The optional Sentry breadcrumb path MUST be a
     * silent no-op when sentry/sentry-laravel is NOT installed. We assert
     * that no PHP warning/notice is raised AND that Log::error is still
     * emitted normally — the structured log is the primary signal; Sentry
     * is purely additive.
     *
     * Strategy: we cannot un-install Sentry mid-test, so we trip the same
     * code path under PHP's strict error reporting (E_ALL via `set_error_handler`)
     * and verify no errors are surfaced. In CI without the SDK installed
     * this also exercises the class_exists() guard for real.
     */
    public function test_failed_callback_does_not_emit_php_warnings_when_sentry_sdk_absent(): void
    {
        // [Audit Claude NEW-03 B4] If sentry/sentry-laravel is installed in
        // the test environment (rare but possible), the conditional branch is
        // taken and we exercise a *different* code path. Skip the test rather
        // than asserting on broad PHP error capture, to keep the assertion
        // tight to its intended invariant: the no-SDK branch is a silent no-op.
        if (class_exists(\Sentry\State\Scope::class)) {
            $this->markTestSkipped(
                'Sentry SDK is installed in this environment; this test specifically guards the absent-SDK branch.'
            );
        }

        $event = $this->makeDomainEvent();
        $job = new DispatchDomainEventsJob($event->id);
        $logSpy = Log::spy();

        // [Audit Claude NEW-03 B4] Narrow scope: only capture errors thrown
        // *during* failed(). Mockery / Log::spy / DB layer outside this
        // window cannot leak into the assertion. This is fragile-by-design:
        // any regression that adds an unguarded Sentry call would surface
        // here as "Class \Sentry\... not found" within the trapped window.
        $errors = [];
        $previousHandler = set_error_handler(function (int $errno, string $errstr) use (&$errors) {
            $errors[] = compact('errno', 'errstr');
            return true;
        });

        try {
            $job->failed(new RuntimeException('boom'));
        } finally {
            restore_error_handler();
            if ($previousHandler !== null) {
                set_error_handler($previousHandler);
            }
        }

        $this->assertSame([], $errors, 'Sentry-guard branch leaked a PHP warning/notice — guard regressed.');

        // Sanity: Log::error WAS still emitted (primary signal must not depend on Sentry).
        $captured = $this->captureFinalFailureContext($logSpy);
        $this->assertSame('boom', $captured['error']);
    }

    /**
     * Pulls the structured payload from the most recent Log::error call to
     * `[DispatchDomainEventsJob] Final failure ...` on a Mockery spy. Returns
     * the context array for assertion.
     */
    private function captureFinalFailureContext(\Mockery\MockInterface $logSpy): array
    {
        $captured = null;

        $logSpy->shouldHaveReceived('error')
            ->withArgs(function ($message, $context = null) use (&$captured) {
                if ($message === '[DispatchDomainEventsJob] Final failure dispatching domain event'
                    && is_array($context)
                ) {
                    $captured = $context;
                    return true;
                }
                return false;
            });

        $this->assertIsArray($captured, 'Expected structured Log::error call from failed() callback.');
        return $captured;
    }

    private function makeDomainEvent(array $overrides = []): DomainEvent
    {
        $defaults = [
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 123,
            'branch_id' => 1,
            'payload' => ['order_id' => 123],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'corr-' . uniqid('', true),
            'occurred_at' => now(),
            'attempts' => 0,
            'last_error' => null,
            'dispatched_at' => null,
        ];

        return DomainEvent::query()->create(array_merge($defaults, $overrides));
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
