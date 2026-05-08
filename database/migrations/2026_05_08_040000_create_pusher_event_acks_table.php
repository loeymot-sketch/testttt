<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [PRE-PROD HARDENING / SYN-2 / P0-7]
 *
 * Pusher heartbeat client→server ack table.
 *
 * Track 3 already writes a `ws:heartbeat` cache key whenever
 * DispatchDomainEventsJob successfully calls `Pusher::trigger()`. That
 * proves a worker drained an event from the outbox — but it does NOT
 * prove a single client actually received the broadcast (Pusher rate
 * limit silently drops, multi-instance Soketi non-sticky, network
 * conditions, etc.). SYN-2 in PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md
 * flags this as a P0 partial-failure risk.
 *
 * Each row records "client X received envelope with correlation_id Y on
 * channel Z at timestamp T". A scheduled monitor compares the count of
 * distinct ack'd correlation_ids vs. the count of dispatched events on
 * a sliding 5-minute window; ratio < 90% → alert.
 *
 * Design notes:
 *
 *  - No FK on `domain_event_id` — the table can grow large and we
 *    prefer to keep retention/cleanup independent from the outbox.
 *    The id is a hint for forensics, not a constraint.
 *
 *  - Indexes are sized for the read patterns:
 *      `idx_correlation` → join back to domain_events on lookup.
 *      `idx_branch_received` → per-branch ratio dashboards.
 *      `idx_received` → global rolling-window ratio.
 *
 *  - `received_at` carries millisecond precision (TIMESTAMP(3) on MySQL,
 *    standard timestamp on SQLite via the dateTime+precision helper) —
 *    debugging multi-client races below the second mark needs sub-second
 *    granularity.
 *
 *  - We intentionally use `dateTime` (not `timestamp`) for `received_at`
 *    so MySQL stores raw clock, not a UTC-shifted value, mirroring the
 *    convention used in `domain_events.occurred_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pusher_event_acks')) {
            return;
        }

        Schema::create('pusher_event_acks', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Optional pointer back to domain_events.id — NOT an FK.
            $table->unsignedBigInteger('domain_event_id')->nullable();

            // The end-to-end correlation tag carried in the envelope.
            // Required: an envelope without correlation cannot be acked,
            // so the client never sends it.
            $table->string('correlation_id', 100);

            // Branch scope (nullable: some envelopes are global, e.g.
            // menu projection updates that fan out to every branch).
            $table->unsignedInteger('branch_id')->nullable();

            // Pusher channel (e.g. private-branch.7).
            $table->string('channel', 100);

            // Event short-name (e.g. order.created).
            $table->string('event_name', 100);

            // Sub-second precision: multi-kiosk races below the second.
            $table->dateTime('received_at', 3);

            // Auth context — auth()->id() resolved on the controller.
            $table->unsignedBigInteger('client_user_id')->nullable();

            // Forensics — IPv4/IPv6 supported, no need for INET6 type.
            $table->string('client_ip', 45)->nullable();

            // Operator log — clipped to 500 chars at the controller.
            $table->string('user_agent', 500)->nullable();

            // We only need created_at (insert-only table, no updates).
            // Eloquent's default uses both timestamps; PusherEventAck
            // model overrides with `public $timestamps = false` and
            // we fill created_at manually in the controller.
            $table->timestamp('created_at')->nullable();

            $table->index('correlation_id', 'idx_correlation');
            $table->index(['branch_id', 'received_at'], 'idx_branch_received');
            $table->index('received_at', 'idx_received');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pusher_event_acks');
    }
};
