<?php

namespace Tests\Feature\Outbox;

use App\Events\OutboxBroadcastSwallowedEvent;
use App\Listeners\EscalateOutboxBroadcastSwallowed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * @FK-ID         FK-CV1-RED-Z3-B-3-HEAL-B-2-2026-05-19
 * @source        reports/audit/v1-sync-deep-audit-2026-05-19/HEAL-PLAN-B-outbox-sync.md §Heal-2
 *
 * RED-Z3 finding B-3 P1 — `OutboxBroadcastSwallowedEvent` (added by commit
 * c82c912ec WJ-4 P1) was intentionally unwired in `EventServiceProvider`,
 * leaving the only signal as a `Log::error`/`Log::warning` line in the
 * originating listeners. V1 LOCAL needs a pager-grade typed alarm on the
 * `fiscal` channel (the operator's nightly log-grep cron greps `fiscal.log`
 * for critical signals).
 *
 * Invariants guarded:
 *   1. Listener IS registered in EventServiceProvider for the event.
 *   2. Dispatching the event triggers a `Log::critical` call on the `fiscal`
 *      channel with the full structured payload (event id, type, aggregate,
 *      branch, listener, error, timestamp). All forensic fields preserved.
 *   3. The listener is NOT queued — synchronous escalation inside the
 *      DB::afterCommit hook (cannot itself enter an outbox-dependency loop).
 */
class OutboxBroadcastSwallowedListenerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Heal B.2 — listener fires Log::critical on the `fiscal` channel
     * with the full structured payload when the event dispatches.
     */
    public function test_listener_emits_critical_log_on_fiscal_channel(): void
    {
        // Spy on the channel/critical call chain. We capture both the
        // channel name AND the critical-call args so the assertions
        // catch any drift on channel routing OR payload shape.
        $criticalArgs = null;
        $channelCalledWith = null;

        Log::shouldReceive('channel')
            ->once()
            ->with('fiscal')
            ->andReturnUsing(function (string $name) use (&$channelCalledWith) {
                $channelCalledWith = $name;
                // Return a tap-able mock that captures the critical() call.
                $tap = Mockery::mock();
                $tap->shouldReceive('critical')->once()->andReturnUsing(
                    function (string $message, array $context = []) use (&$GLOBALS_capture) {
                        $GLOBALS['__outbox_swallow_capture'] = [
                            'message' => $message,
                            'context' => $context,
                        ];
                    }
                );
                return $tap;
            });

        $listener = new EscalateOutboxBroadcastSwallowed();

        $listener->handle(new OutboxBroadcastSwallowedEvent(
            domainEventId: 4242,
            eventType:     'order.created',
            aggregateId:   999,
            branchId:      7,
            listener:      \App\Listeners\PersistOrderCreatedToOutbox::class,
            errorMessage:  'Pusher unreachable: connection timed out after 30s',
            failedAt:      new \DateTimeImmutable('2026-05-19T12:34:56+00:00'),
        ));

        $this->assertSame('fiscal', $channelCalledWith, 'Log channel MUST be `fiscal` (NF525 pager-grade stream, 400d retention).');

        $captured = $GLOBALS['__outbox_swallow_capture'] ?? null;
        $this->assertNotNull($captured, 'Listener MUST emit Log::critical().');

        $context = $captured['context'];

        // Full forensic payload — every readonly property from the event MUST
        // round-trip into the structured context so SIEM search by any field
        // works without re-parsing the message string.
        $this->assertSame('outbox.broadcast.swallowed', $context['event'] ?? null);
        $this->assertSame(4242, $context['domain_event_id'] ?? null);
        $this->assertSame('order.created', $context['event_type'] ?? null);
        $this->assertSame(999, $context['aggregate_id'] ?? null);
        $this->assertSame(7, $context['branch_id'] ?? null);
        $this->assertSame(\App\Listeners\PersistOrderCreatedToOutbox::class, $context['listener'] ?? null);
        $this->assertStringContainsString('Pusher unreachable', (string) ($context['error_message'] ?? ''));
        $this->assertSame('2026-05-19T12:34:56+00:00', $context['failed_at'] ?? null);

        // Clean up the global capture so it does not bleed into other tests.
        unset($GLOBALS['__outbox_swallow_capture']);
    }

    /**
     * Heal B.2 — listener MUST be wired in the EventServiceProvider.
     * Without this assertion, B.2 would regress silently: the listener
     * file could exist on disk yet the event would still be unwired.
     */
    public function test_listener_is_registered_in_event_service_provider(): void
    {
        // Event::fake replaces the dispatcher; we use the real dispatcher
        // and ask the kernel which listener classes are wired for the
        // event. This is the same lookup the framework uses at runtime.
        $listeners = Event::getRawListeners()[OutboxBroadcastSwallowedEvent::class] ?? [];

        $this->assertNotEmpty(
            $listeners,
            'EventServiceProvider MUST register at least one listener for OutboxBroadcastSwallowedEvent (closes RED-Z3 B-3).'
        );

        $hasEscalator = false;
        foreach ($listeners as $listener) {
            // Listener entries can be class strings or [class, method] tuples.
            $candidate = is_array($listener) ? ($listener[0] ?? null) : $listener;
            if ($candidate === EscalateOutboxBroadcastSwallowed::class) {
                $hasEscalator = true;
                break;
            }
        }

        $this->assertTrue(
            $hasEscalator,
            'EscalateOutboxBroadcastSwallowed MUST be among the registered listeners.'
        );
    }

    /**
     * Heal B.2 — listener MUST NOT implement ShouldQueue. V1 LOCAL: the
     * escalation fires inside `DB::afterCommit` of the original outbox
     * dispatch site; making it async would re-introduce the same
     * outbox-dependency loop the typed event was created to break.
     */
    public function test_listener_is_not_queued(): void
    {
        $this->assertFalse(
            is_subclass_of(EscalateOutboxBroadcastSwallowed::class, \Illuminate\Contracts\Queue\ShouldQueue::class),
            'Listener MUST be synchronous — NO ShouldQueue (defer to V1.0.2 with provider-specific bridge).'
        );
    }
}
