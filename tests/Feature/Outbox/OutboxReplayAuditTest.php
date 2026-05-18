<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Models\AuditLog;
use App\Models\DomainEvent;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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
}
