<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [iter14 SPECIALIST-2] Add idempotency_key to domain_events.
 *
 * Backstory (audit iter13 SYNC-EVENTS finding):
 *   Listeners persist outbox rows with `DomainEvent::create([...])`. If the same
 *   listener fires twice for a single domain event (e.g. queue retry after a
 *   transient failure between create() and the post-commit dispatch, or a
 *   duplicate event registration), two rows are inserted and the broadcaster
 *   broadcasts twice. The dispatcher-side dedupe (`OutboxConcurrentWorkerDedupeTest`)
 *   protects against duplicate broadcasts of the SAME row id, but does not
 *   protect against duplicate persistence.
 *
 * Strategy:
 *   - Nullable + UNIQUE column so legacy rows (and listeners that have not yet
 *     adopted the pattern) keep working unchanged.
 *   - Listener computes a deterministic key
 *       sha1(event_type|aggregate_id|discriminator)
 *     where discriminator is empty for one-shot events (OrderCreated,
 *     OrderPaidAtCounter) and combines status transition tuple +
 *     correlation_id for transition events. correlation_id scopes the dedupe
 *     to the originating request — a legitimate later revert (different
 *     request, different correlation) gets a fresh row.
 *   - Listener uses firstOrCreate keyed on `idempotency_key`. The UNIQUE index
 *     makes the dedupe race-safe at the DB layer (firstOrCreate without a
 *     unique index is theatre under contention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_events', function (Blueprint $table): void {
            // sha1 hex = 40 chars; reserve 64 for future hash-algo upgrade.
            $table->string('idempotency_key', 64)->nullable()->after('correlation_id');
            $table->unique('idempotency_key', 'uniq_domain_events_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('domain_events', function (Blueprint $table): void {
            $table->dropUnique('uniq_domain_events_idempotency_key');
            $table->dropColumn('idempotency_key');
        });
    }
};
