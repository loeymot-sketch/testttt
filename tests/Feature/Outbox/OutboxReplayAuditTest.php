<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\WebhookEvent;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * @FK-ID         FK-CV1-WAVE1-SYNC-RED-03-2026-05-18
 * @source        Wave 1 sync-adversarial.md — SYNC-RED-03 (P1 NF525-adjacent)
 *
 * NF525-adjacent invariant : manual DLQ replay of an outbox event re-processes
 * fiscal-adjacent payloads (Stripe payment → fiscal sequence allocation, KDS
 * status broadcast, OSS status update). The chain-of-custody requires a
 * tamper-evident `audit_logs` row each time a human (or cron) re-drives a
 * failed event so the financial trail can be reconstructed during audit.
 *
 * Before this guard, both `foodking:outbox:retry-failed` and
 * `foodking:webhook:retry-failed` re-dispatched silently — only the
 * application log carried the trace, and application logs are NOT
 * tamper-evident (no HMAC chain).
 *
 * Both commands now MUST append exactly one audit_logs row per replayed
 * event with action='outbox.replay' and a payload containing the command
 * name + event id + provider/event_type so auditors can correlate.
 */
class OutboxReplayAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SYNC-RED-03 — `foodking:webhook:retry-failed` must append an
     * `audit_logs` row for each replayed webhook event.
     */
    public function test_webhook_retry_failed_writes_audit_log_per_replayed_event(): void
    {
        Bus::fake();

        $event = WebhookEvent::create([
            'provider' => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_test_replay_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['id' => 'evt_test'],
            'signature' => 'sig-test',
            'received_at' => now()->subMinutes(5),
            'processed_at' => null,
            'status' => WebhookEvent::STATUS_FAILED,
            'error_message' => 'Network timeout',
            'attempts' => 2,
            'order_id' => null,
        ]);

        $auditBefore = AuditLog::count();

        $exit = Artisan::call('foodking:webhook:retry-failed', ['--since' => '24h']);

        $this->assertSame(0, $exit, 'Command must exit success.');
        $this->assertSame(
            $auditBefore + 1,
            AuditLog::count(),
            'Exactly one audit_logs row must be appended per replayed webhook event.'
        );

        $row = AuditLog::latest('id')->first();
        $this->assertSame('outbox.replay', (string) $row->action);
        $payload = is_array($row->payload) ? $row->payload : (array) $row->payload;
        $this->assertSame('foodking:webhook:retry-failed', $payload['command'] ?? null);
        $this->assertSame((int) $event->id, (int) ($payload['event_id'] ?? -1));
        $this->assertSame((string) $event->webhook_id, (string) ($payload['webhook_id'] ?? ''));
        $this->assertSame(WebhookEvent::PROVIDER_STRIPE, (string) ($payload['provider'] ?? ''));
    }

    /**
     * SYNC-RED-03 — `foodking:outbox:retry-failed` must append an
     * `audit_logs` row for each replayed domain event.
     */
    public function test_domain_outbox_retry_failed_writes_audit_log_per_replayed_event(): void
    {
        Bus::fake();

        // failed() scope on DomainEvent = pending (dispatched_at NULL)
        // AND attempts >= maxAttempts (default 5).
        $event = DomainEvent::create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => 'Order',
            'aggregate_id' => 999,
            'branch_id' => 1,
            'payload' => ['order_id' => 999],
            'channel' => 'private-branch.1',
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'corr-replay-test',
            'occurred_at' => now()->subMinutes(10),
            'dispatched_at' => null,
            'attempts' => 6,
            'last_error' => 'Pusher unreachable',
        ]);

        $auditBefore = AuditLog::count();

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);

        $this->assertSame(0, $exit, 'Command must exit success.');
        $this->assertSame(
            $auditBefore + 1,
            AuditLog::count(),
            'Exactly one audit_logs row must be appended per replayed domain event.'
        );

        $row = AuditLog::latest('id')->first();
        $this->assertSame('outbox.replay', (string) $row->action);
        $payload = is_array($row->payload) ? $row->payload : (array) $row->payload;
        $this->assertSame('foodking:outbox:retry-failed', $payload['command'] ?? null);
        $this->assertSame((int) $event->id, (int) ($payload['event_id'] ?? -1));
        $this->assertSame((int) $event->branch_id, (int) ($row->branch_id ?? -1));
    }

    /**
     * SYNC-ADV3-04 (Wave 3) — when AuditLogService::write throws on event N,
     * the dispatch MUST be skipped for event N (no orphan side-effect) AND
     * the loop MUST CONTINUE to event N+1 (batch continuity).
     */
    public function test_webhook_retry_skips_dispatch_and_continues_when_audit_write_throws(): void
    {
        Bus::fake();

        // Two failed events: audit will throw on first, succeed on second.
        $event1 = WebhookEvent::create([
            'provider' => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_adv3_fail_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['id' => 'evt_fail'],
            'signature' => 'sig-fail',
            'received_at' => now()->subMinutes(5),
            'processed_at' => null,
            'status' => WebhookEvent::STATUS_FAILED,
            'error_message' => 'Network timeout',
            'attempts' => 2,
            'order_id' => null,
        ]);

        $event2 = WebhookEvent::create([
            'provider' => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id' => 'evt_adv3_ok_'.uniqid(),
            'event_type' => 'payment_intent.succeeded',
            'payload' => ['id' => 'evt_ok'],
            'signature' => 'sig-ok',
            'received_at' => now()->subMinutes(4),
            'processed_at' => null,
            'status' => WebhookEvent::STATUS_FAILED,
            'error_message' => 'Network timeout',
            'attempts' => 2,
            'order_id' => null,
        ]);

        // Throw on the FIRST audit write, succeed (no-op AuditLog row) on subsequent calls.
        // Loop must continue past the throw and dispatch event2.
        $callCount = 0;
        $this->mock(AuditLogService::class, function ($m) use (&$callCount) {
            $m->shouldReceive('write')
                ->andReturnUsing(function (array $data) use (&$callCount) {
                    $callCount++;
                    if ($callCount === 1) {
                        throw new \RuntimeException('Simulated chain-lock timeout');
                    }
                    // For the second event, persist a minimal stub row so DB-count
                    // assertions in other tests still reflect intent. Branch-aware.
                    return AuditLog::create([
                        'branch_id' => (int) ($data['branch_id'] ?? 0),
                        'user_id' => $data['user_id'] ?? null,
                        'action' => (string) $data['action'],
                        'resource' => $data['resource'] ?? null,
                        'resource_id' => $data['resource_id'] ?? null,
                        'payload' => $data['payload'] ?? [],
                        'prev_hash' => null,
                        'current_hash' => str_repeat('a', 64),
                        'ip' => null,
                        'user_agent' => null,
                        'session_id' => null,
                    ]);
                });
        });

        $exit = Artisan::call('foodking:webhook:retry-failed', ['--since' => '24h']);
        $this->assertSame(0, $exit, 'Command must still exit success even with one failed audit write.');

        // Loop must have continued past event1's audit failure (callCount == 2)
        $this->assertSame(2, $callCount, 'Loop must continue past audit-write failure on event 1.');

        // Exactly ONE dispatched job (event2 only — event1 was skipped because
        // its audit row never landed). Bus::fake records all dispatches.
        Bus::assertDispatchedTimes(\App\Jobs\ProcessWebhookEventJob::class, 1);

        // event1 must remain in FAILED status (forceFill was skipped after audit throw).
        $event1Refreshed = WebhookEvent::find($event1->id);
        $this->assertSame(
            WebhookEvent::STATUS_FAILED,
            (string) $event1Refreshed->status,
            'event1 must NOT be flipped to pending — dispatch was skipped after audit failure.'
        );

        // event2 must have been flipped to PENDING (audit ok → dispatch ok).
        $event2Refreshed = WebhookEvent::find($event2->id);
        $this->assertSame(
            WebhookEvent::STATUS_PENDING,
            (string) $event2Refreshed->status,
            'event2 must be flipped to pending — full write-then-dispatch path completed.'
        );
    }

    /**
     * SYNC-ADV3-04 (Wave 3) — when dispatch throws after a successful audit
     * write, the audit row MUST persist (NF525 chain-of-custody preserved)
     * AND the loop MUST CONTINUE to the next event.
     */
    public function test_domain_outbox_retry_keeps_audit_row_and_continues_when_dispatch_throws(): void
    {
        // Install a custom Bus dispatcher that throws on the FIRST dispatch
        // call and succeeds on subsequent ones. The static `Dispatchable::dispatch`
        // trait resolves the dispatcher via the IoC container, so binding here
        // intercepts both events.
        $callCount = 0;
        $tracker = new class {
            public int $count = 0;
        };
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new class($tracker) implements \Illuminate\Contracts\Bus\Dispatcher {
            private $tracker;

            public function __construct($tracker)
            {
                $this->tracker = $tracker;
            }

            public function dispatch($command)
            {
                $this->tracker->count++;
                if ($this->tracker->count === 1) {
                    throw new \RuntimeException('Simulated queue connection failure');
                }
                return null;
            }

            public function dispatchSync($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function dispatchNow($command, $handler = null)
            {
                return $this->dispatch($command);
            }

            public function findBatch(string $batchId)
            {
                return null;
            }

            public function batch($jobs)
            {
                throw new \LogicException('not implemented');
            }

            public function chain($jobs)
            {
                throw new \LogicException('not implemented');
            }

            public function hasCommandHandler($command)
            {
                return false;
            }

            public function getCommandHandler($command)
            {
                return false;
            }

            public function pipeThrough(array $pipes)
            {
                return $this;
            }

            public function map(array $map)
            {
                return $this;
            }
        });

        $event1 = DomainEvent::create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => 'Order',
            'aggregate_id' => 1001,
            'branch_id' => 1,
            'payload' => ['order_id' => 1001],
            'channel' => 'private-branch.1',
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'corr-adv3-fail',
            'occurred_at' => now()->subMinutes(10),
            'dispatched_at' => null,
            'attempts' => 6,
            'last_error' => 'Pusher unreachable',
        ]);

        $event2 = DomainEvent::create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => 'Order',
            'aggregate_id' => 1002,
            'branch_id' => 1,
            'payload' => ['order_id' => 1002],
            'channel' => 'private-branch.1',
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'corr-adv3-ok',
            'occurred_at' => now()->subMinutes(9),
            'dispatched_at' => null,
            'attempts' => 6,
            'last_error' => 'Pusher unreachable',
        ]);

        $auditBefore = AuditLog::count();

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);
        $this->assertSame(0, $exit);

        // Both events should have audit rows even though event1's dispatch threw.
        $this->assertSame(
            $auditBefore + 2,
            AuditLog::count(),
            'Audit rows must persist for BOTH events: write-then-dispatch ordering + batch continuity.'
        );

        // Verify the two rows reference both event ids (no orphan).
        $rows = AuditLog::orderBy('id', 'desc')->take(2)->get();
        $eventIdsInAudit = $rows->map(function ($r) {
            $payload = is_array($r->payload) ? $r->payload : (array) $r->payload;
            return (int) ($payload['event_id'] ?? 0);
        })->all();

        $this->assertContains((int) $event1->id, $eventIdsInAudit, 'event1 audit row must persist (dispatch failure does not undo audit).');
        $this->assertContains((int) $event2->id, $eventIdsInAudit, 'event2 audit row must persist (loop continued past event1 dispatch failure).');

        // Dispatcher was called twice (loop continued past event1 throw).
        $this->assertSame(2, $tracker->count, 'Loop must continue past dispatch failure on event 1.');
    }
}
