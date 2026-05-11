<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix orders.loyalty_points_awarded UNSIGNED constraint that rejects the -1 sentinel.
 *
 * Root cause:
 *   app/Listeners/AwardLoyaltyPointsOnDelivery.php uses -1 as a "claim in progress"
 *   sentinel value in the atomic-update idiom:
 *     UPDATE orders SET loyalty_points_awarded = -1
 *     WHERE id = ? AND loyalty_points_awarded IS NULL
 *
 *   The column was created UNSIGNED, so the -1 write triggers
 *   SQLSTATE[22003] Numeric value out of range → 422 cascade on /payment-confirm
 *   → kiosk customer never reaches the confirmation page.
 *
 *   Discovered via Wave-E E2E audit 2026-05-11.
 *
 * Fix:
 *   ALTER the column to SIGNED INT (still nullable, default null preserved).
 *   Existing rows are not migrated — values 0..PHP_INT_MAX remain valid; future
 *   -1 sentinel writes now succeed.
 *
 * Rollback path (down): revert to INT UNSIGNED. Note: if any in-flight rows
 * carry -1 at rollback time the migration will fail — rollback should be
 * preceded by a one-off cleanup setting -1 → NULL.
 */
return new class extends Migration {
    public function up(): void
    {
        // MySQL ALTER COLUMN with explicit nullability + default preservation.
        // Schema::table() can't drop the UNSIGNED modifier in one shot via
        // Doctrine; raw DDL is cleaner.
        //
        // [WAVE6-KIOSK-A] SQLite test guard : sqlite has no UNSIGNED concept and
        // does not support `MODIFY`. Skip the no-op DDL on sqlite so test
        // suites can boot. Production (MySQL) path unchanged.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        DB::statement(
            'ALTER TABLE `orders` MODIFY `loyalty_points_awarded` INT NULL DEFAULT NULL'
        );
    }

    public function down(): void
    {
        // Defensive: revert any -1 sentinels to NULL so the UNSIGNED constraint
        // doesn't reject the ALTER. Idempotent.
        DB::table('orders')
            ->where('loyalty_points_awarded', '<', 0)
            ->update(['loyalty_points_awarded' => null]);

        // [WAVE6-KIOSK-A] SQLite guard symmetric to up().
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }
        DB::statement(
            'ALTER TABLE `orders` MODIFY `loyalty_points_awarded` INT UNSIGNED NULL DEFAULT NULL'
        );
    }
};
