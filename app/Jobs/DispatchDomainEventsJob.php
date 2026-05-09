<?php

namespace App\Jobs;

use App\Domain\Events\EventContract;
use App\Exceptions\PayloadMismatchException;
use App\Models\DomainEvent;
use App\Services\Observability\SyncMetricsRecorder;
use App\Traits\HasCorrelationId;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchDomainEventsJob implements ShouldQueue
{
    use Dispatchable, HasCorrelationId, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * [NEW-03 / Audit T G2] Backoff curve: 1s → 5s → 15s → 60s → 5min.
     *
     * Laravel applies $backoff[i] BETWEEN attempts (i.e. (tries - 1) delays
     * are actually used; the last $backoff entry is only consumed if tries >
     * count(backoff)). With $tries = 6 and $backoff = [1, 5, 15, 60, 300]:
     *   - Delays applied: 1, 5, 15, 60, 300 (Laravel reuses the last value
     *     beyond the array length, so attempt #5 → #6 also waits 300s).
     *   - Total worst-case retry window ≈ 1 + 5 + 15 + 60 + 300 = 381s (~6.4 min)
     *     → outlasts a typical Pusher/Soketi restart (1-3 min) while keeping
     *     the SLO `outbox_dispatch_latency_p95 < 2s` healthy in steady state.
     *
     * Audit T G2 (2026-04-23) caught that previous `tries=5` made the 300s
     * trailing entry unreachable; bumping to 6 makes the curve match the
     * documented intent.
     */
    public array $backoff = [1, 5, 15, 60, 300];

    public int $tries = 6;

    public function __construct(public int $domainEventId)
    {
        $this->onQueue('high');
        $this->initializeCorrelationId();
    }

    public function handle(): void
    {
        $this->restoreCorrelationContext();

        // [NEW-01] Phase 1 — atomic claim under row lock.
        // The lockForUpdate + dispatched_at guard + claim mutation form a single
        // committed unit. The losing worker observes dispatched_at != null after
        // acquiring the lock and returns silently — broadcaster never fires twice.
        // Broadcast happens AFTER this transaction commits to honour the
        // commit_before_dispatch invariant.
        $claimed = false;
        $skip = false;
        /** @var DomainEvent|null $domainEvent */
        $domainEvent = null;

        DB::transaction(function () use (&$claimed, &$skip, &$domainEvent): void {
            $domainEvent = DomainEvent::query()
                ->lockForUpdate()
                ->find($this->domainEventId);

            if (! $domainEvent) {
                $skip = true;
                return;
            }

            if ($domainEvent->dispatched_at !== null) {
                $skip = true;
                return;
            }

            $domainEvent->forceFill([
                'dispatched_at' => now(),
                'attempts' => ((int) $domainEvent->attempts) + 1,
            ])->save();

            $claimed = true;
        });

        if ($skip || ! $claimed || $domainEvent === null) {
            Log::info('[DispatchDomainEventsJob] Skipped (already dispatched by concurrent worker)', [
                'domain_event_id' => $this->domainEventId,
            ]);

            return;
        }

        try {
            // [NEW-01] Phase 2 — validation + broadcast OUTSIDE any DB transaction.
            $domainEvent->refresh();

            if ($domainEvent->channel !== null && $domainEvent->broadcast_as !== null) {
                $channels = json_decode($domainEvent->channel, true);

                if (! is_array($channels)) {
                    $channels = [$domainEvent->channel];
                }

                $envelope = EventContract::buildEnvelope($domainEvent);

                // Validate the envelope against the V1 contract before broadcast.
                EventContract::assertEnvelopeValid($envelope, $domainEvent->event_type);

                // [T09b / A5 convergence] Respect `config('broadcasting.default')`
                // (e.g. `log` in PHPUnit, `pusher` in prod). DO NOT hard-code
                // `connection('pusher')` — breaks CI/tests when PUSHER_* env is unset.
                $broadcaster = app(BroadcastManager::class)->connection();
                $broadcaster->broadcast($channels, $domainEvent->broadcast_as, $envelope);
            }

            // [NEW-04] Non-blocking telemetry: record dispatch latency on the
            // success path BEFORE clearing last_error so the recorder failure
            // (best-effort, internally swallowed) cannot leave a stale error
            // visible. Wrapped in defensive try/catch as belt-and-suspenders;
            // the recorder ALSO has its own internal try/catch.
            try {
                app(SyncMetricsRecorder::class)->recordEventDispatched(
                    (string) $domainEvent->event_type,
                    $domainEvent->branch_id !== null ? (int) $domainEvent->branch_id : null,
                    (int) now()->diffInMilliseconds($domainEvent->occurred_at),
                    $domainEvent->correlation_id
                );
            } catch (Throwable $observabilityException) {
                // Observability MUST NEVER break outbox dispatch.
            }

            // [NEW-01] Phase 3a — success: keep dispatched_at (set during claim),
            // clear last_error if any previous attempt left one behind.
            $domainEvent->forceFill([
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            // [NEW-01] Phase 3b — release the claim so the queue retry
            // ($tries / $backoff curve documented at top of class) can re-attempt
            // cleanly. Preserves PayloadMismatchException semantics:
            // last_error is populated AND the original exception is rethrown so the
            // job lands in failed_jobs after all attempts exhausted.
            $domainEvent->forceFill([
                'dispatched_at' => null,
                'last_error' => $e instanceof PayloadMismatchException
                    ? 'contract_violation: ' . $e->getMessage()
                    : $e->getMessage(),
            ])->save();

            if ($e instanceof PayloadMismatchException) {
                Log::error('[DispatchDomainEventsJob] Envelope contract violation', [
                    'domain_event_id' => $domainEvent->id,
                    'event_type' => $domainEvent->event_type,
                    'errors' => $e->errors,
                ]);
            }

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        // [Audit T G3] Compute the persisted error string ONCE so log context
        // and DB last_error stay in sync. PayloadMismatchException is surfaced
        // with the `contract_violation:` prefix in BOTH places so monitoring
        // pipelines that grep on this prefix do not lose terminal failures.
        $isContractViolation = $exception instanceof PayloadMismatchException;
        $persistedErrorMessage = $isContractViolation
            ? 'contract_violation: ' . $exception->getMessage()
            : $exception->getMessage();
        $errorCategory = $isContractViolation ? 'contract_violation' : 'runtime_failure';

        $domainEvent = DomainEvent::query()->find($this->domainEventId);

        if ($domainEvent !== null) {
            // [NEW-01 / Audit G1] Preserve the `contract_violation:` prefix
            // written by handle() so monitoring filters keying on this prefix
            // do NOT lose terminal failures after `tries` exhaustion.
            $domainEvent->forceFill([
                'last_error' => $persistedErrorMessage,
            ])->save();
        }

        // [NEW-03] Structured log + optional Sentry breadcrumb for pager-grade
        // signaling. Log::error (was warning) because this is the *terminal*
        // failure after $tries exhausted — by definition pager-worthy.
        $createdAt = $domainEvent?->created_at;

        $context = [
            'domain_event_id' => $this->domainEventId,
            'event_type' => $domainEvent?->event_type,
            'queue' => $this->queue ?? 'high',
            'connection' => $this->connection ?? config('queue.default'),
            'attempts' => method_exists($this, 'attempts') ? $this->attempts() : null,
            'delay_since_creation_seconds' => $createdAt ? (int) now()->diffInSeconds($createdAt) : null,
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
            // [Audit T G3] Mirror DB last_error so log scanners filtering on the
            // `contract_violation:` prefix see the same payload as the database.
            'last_error' => $persistedErrorMessage,
            'error_category' => $errorCategory,
        ];

        Log::error('[DispatchDomainEventsJob] Final failure dispatching domain event', $context);

        // Sentry integration is OPTIONAL — codebase does not require sentry/sentry-laravel.
        if (class_exists(\Sentry\State\Scope::class)
            && function_exists('Sentry\\addBreadcrumb')
            && class_exists(\Sentry\Breadcrumb::class)
        ) {
            \Sentry\addBreadcrumb(\Sentry\Breadcrumb::fromArray([
                'category' => 'queue.dispatch_domain_events.failed',
                'level' => 'error',
                'message' => 'Final failure dispatching domain event',
                'data' => $context,
            ]));
        }
    }
}
