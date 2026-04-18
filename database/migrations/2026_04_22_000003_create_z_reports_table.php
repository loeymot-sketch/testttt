<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [POS-9.4.6 / POS-GA-F-01]
 *
 * Fiscal Z report ("clôture journalière"). One row per (branch, day-ish)
 * — actually per open/close cycle, because a branch may manually Z a
 * second time if the first was triggered too early. Sequence numbers
 * are per-branch, gap-free (enforced by ZReportService::close via a
 * cache lock + unique constraint).
 *
 * Aggregates are stored denormalised at close time (NF525 requires the
 * report to remain valid even if underlying orders are later archived
 * or their format changes).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('z_reports')) {
            return;
        }

        Schema::create('z_reports', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('sequence_no');

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();

            // All amounts stored as DECIMAL(15,2) in MySQL / REAL in SQLite —
            // we standardise on decimal to keep rounding deterministic.
            $table->decimal('total_ht',  15, 2)->default(0);
            $table->decimal('total_ttc', 15, 2)->default(0);
            $table->decimal('total_tva', 15, 2)->default(0);

            // JSON aggregates: {method: amount}, {rate: amount}, {user_id: amount}.
            $table->json('total_by_method')->nullable();
            $table->json('total_by_tax_rate')->nullable();

            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedInteger('cancel_count')->default(0);
            $table->unsignedInteger('refund_count')->default(0);

            // HMAC chain between Z reports of the same branch.
            $table->char('prev_hash', 64)->nullable();
            $table->char('signature', 64)->nullable();

            // Lifecycle.
            $table->string('status', 16)->default('open'); // open|closed
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->unique(['branch_id', 'sequence_no'], 'z_reports_branch_sequence_unique');
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('z_reports');
    }
};
