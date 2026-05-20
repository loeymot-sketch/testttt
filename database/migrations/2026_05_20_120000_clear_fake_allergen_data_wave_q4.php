<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V1 Le Cayenne — Wave Q-4 (2026-05-20) durable retraction of Wave P R2-B
 * fake-seeded allergen data.
 *
 * ## Why this migration exists
 *
 * Wave P Round 2-B (2026-05-19) ran `LeCayenneAllergenSeeder` which populated
 * `items.allergen_flags` + `item_allergen` pivot with GUESSED mappings (not
 * chef-confirmed). On 2026-05-20 the owner identified the regression — see
 * `database/seeders/LeCayenneAllergenSeeder.php` docblock for the audit trail.
 *
 * The seeder is now a NOOP. However, any database snapshot/dump taken between
 * Wave P R2-B (2026-05-19) and Wave Q-4 commit `c28f7a452` still carries the
 * fake data. Restoring such a dump on a fresh environment (CI staging,
 * developer workstation, post-restore production) would re-introduce the
 * regression because the seeder no longer auto-cleans.
 *
 * This migration is the **idempotent clear** that runs once on any
 * environment via `php artisan migrate` and locks the state to "no fake
 * allergen data". It is safe to re-run (idempotent: empty UPDATEs).
 *
 * ## Scope (what gets cleared)
 *
 * - `items.allergen_flags` → `[]` for every item where currently non-empty.
 *   (`[]` is the FIC "checked-none" disclosure pattern, consistent with the
 *   model's `cast => 'array'`. NULL would be ambiguous per FIC.)
 * - `item_allergen` pivot → all rows deleted.
 * - `order_items.allergens_snapshot` → `[]` ONLY for orders in KDS-visible
 *   statuses (ACCEPT(4), PREPARING(7), PREPARED(8)). Historical
 *   DELIVERED/CANCELED/REJECTED/RETURNED orders are left untouched —
 *   their snapshots are part of the immutable audit trail per NF525 §8
 *   invariants and `KitchenDisplaySystemOrderService` line-dedupe hashing.
 *
 * ## Scope (what is NOT touched)
 *
 * - `allergens` lookup table — preserved (it's the 14 FIC code dictionary).
 * - The Vue components (`KitchenDisplaySystemComponent.vue`, `KdsOrderCard.vue`,
 *   `KsAllergenBadge.vue`) — they ALREADY guard on empty, no change needed.
 * - Closed orders' `allergens_snapshot` — fiscal-audit immutability.
 *
 * ## Rollback
 *
 * `down()` is a NOOP. We refuse to re-introduce fake data; restoration of
 * legitimate per-item allergens must come from chef-confirmed sources via
 * the (future) admin UI or a content-corrected seeder.
 */
return new class extends Migration {
    /** KDS-visible order statuses per app/Enums/OrderStatus.php */
    private const KDS_VISIBLE_STATUSES = [
        1,  // PENDING
        4,  // ACCEPT
        7,  // PREPARING
        8,  // PREPARED
    ];

    public function up(): void
    {
        $emptyJson = json_encode([]);

        // 1) items.allergen_flags → [] for any non-empty row.
        if (Schema::hasColumn('items', 'allergen_flags')) {
            DB::table('items')
                ->whereNotNull('allergen_flags')
                ->where(function ($q) {
                    // Match any non-`[]` JSON. Cross-driver safe (works on
                    // MySQL JSON + on sqlite which stores as text).
                    $q->where('allergen_flags', '!=', '[]')
                      ->where('allergen_flags', '!=', '"[]"'); // edge: double-encoded
                })
                ->update(['allergen_flags' => $emptyJson]);
        }

        // 2) item_allergen pivot → empty.
        if (Schema::hasTable('item_allergen')) {
            DB::table('item_allergen')->delete();
        }

        // 3) order_items.allergens_snapshot → [] for KDS-visible orders only.
        //    Closed orders' snapshots are part of NF525 audit trail and must
        //    not be mutated.
        if (Schema::hasColumn('order_items', 'allergens_snapshot') && Schema::hasTable('orders')) {
            $openOrderIds = DB::table('orders')
                ->whereIn('status', self::KDS_VISIBLE_STATUSES)
                ->pluck('id');

            if ($openOrderIds->isNotEmpty()) {
                DB::table('order_items')
                    ->whereIn('order_id', $openOrderIds)
                    ->whereNotNull('allergens_snapshot')
                    ->where(function ($q) {
                        $q->where('allergens_snapshot', '!=', '[]')
                          ->where('allergens_snapshot', '!=', '"[]"');
                    })
                    ->update(['allergens_snapshot' => json_encode([])]);
            }
        }
    }

    public function down(): void
    {
        // NOOP — see class docblock.
    }
};
