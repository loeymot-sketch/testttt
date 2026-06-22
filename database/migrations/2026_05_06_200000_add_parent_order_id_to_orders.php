<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * [P11-FZH / F-VERIFY-08-02] — refund-with-counter-entry mirror order link.
 *
 * Self-ref FK on orders. The mirror order created by
 * RefundWithCounterEntryService::execute() points to its parent order via
 * parent_order_id. ON DELETE SET NULL so soft-deleting a parent does not
 * cascade-orphan the mirror's audit chain (the audit_logs row keeps the
 * payload.parent_order_id reference for forensic recovery).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'parent_order_id')) {
                $table->unsignedBigInteger('parent_order_id')->nullable()->after('id');
                $table->foreign('parent_order_id')
                    ->references('id')->on('orders')
                    ->nullOnDelete();
                $table->index('parent_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'parent_order_id')) {
                try { $table->dropForeign(['parent_order_id']); } catch (\Throwable) {}
                try { $table->dropIndex(['parent_order_id']); } catch (\Throwable) {}
                $table->dropColumn('parent_order_id');
            }
        });
    }
};
