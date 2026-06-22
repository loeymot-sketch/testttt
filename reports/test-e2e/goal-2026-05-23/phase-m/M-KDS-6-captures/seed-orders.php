<?php
/**
 * M-KDS-6 — seed N orders for branch=1 in ACCEPT/PREPARING + PAID state,
 * so the KDS controller (KitchenReleaseRule::visibleStatuses + PAID gate)
 * picks them up. Also creates per-order items with controlled depth so we
 * can test "short" vs "realistic" card body content.
 *
 * Usage:
 *   php artisan tinker --execute="require 'reports/test-e2e/goal-2026-05-23/phase-m/M-KDS-6-captures/seed-orders.php'; seed_kds(N=8, content_depth='realistic');"
 *
 * Depth modes:
 *   'short'       => 1 item per order, 0 customization lines (best-case)
 *   'realistic'   => 2 items per order with ~4-6 customization lines each
 *   'long'        => 3 items per order with ~10+ customization lines each (worst-case)
 */

function clear_kds_orders(int $branchId = 1): int {
    // Wipe today's ACCEPT/PREPARING/PREPARED orders for branch so we start clean.
    $cnt = \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
        ->where('branch_id', $branchId)
        ->whereIn('status', [
            \App\Enums\OrderStatus::ACCEPT,
            \App\Enums\OrderStatus::PREPARING,
            \App\Enums\OrderStatus::PREPARED,
        ])
        ->whereDate('order_datetime', today())
        ->count();
    \App\Models\OrderItem::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
        ->whereHas('order', function ($q) use ($branchId) {
            $q->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
              ->where('branch_id', $branchId)
              ->whereIn('status', [
                \App\Enums\OrderStatus::ACCEPT,
                \App\Enums\OrderStatus::PREPARING,
                \App\Enums\OrderStatus::PREPARED,
              ])
              ->whereDate('order_datetime', today());
        })
        ->forceDelete();
    \App\Models\Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
        ->where('branch_id', $branchId)
        ->whereIn('status', [
            \App\Enums\OrderStatus::ACCEPT,
            \App\Enums\OrderStatus::PREPARING,
            \App\Enums\OrderStatus::PREPARED,
        ])
        ->whereDate('order_datetime', today())
        ->forceDelete();
    return $cnt;
}

function seed_kds(int $n, string $depth = 'short', int $branchId = 1): array {
    clear_kds_orders($branchId);

    // Ensure 1 item exists so we can hang OrderItem on something legitimate.
    $itemId = \App\Models\Item::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->value('id');
    if (!$itemId) {
        // Bare-minimum: create one tax + one category + one item
        $tax = \App\Models\Tax::firstOrCreate(
            ['name' => 'TVA 10'],
            ['code' => 'TVA10', 'tax_rate' => '10', 'type' => 1, 'status' => 1]
        );
        $cat = \App\Models\ItemCategory::firstOrCreate(
            ['name' => 'KDS Test'],
            ['slug' => 'kds-test', 'status' => 1, 'sort' => 0]
        );
        $item = \App\Models\Item::create([
            'name' => 'Sandwich Cayenne',
            'slug' => 'sandwich-cayenne-kds',
            'description' => 'KDS test item',
            'price' => 8.50,
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'item_type' => 1,
            'status' => 1,
            'is_available' => 1,
        ]);
        $itemId = $item->id;
    }

    $orders = [];
    for ($i = 0; $i < $n; $i++) {
        // Spread created_at backwards so they sort FIFO (oldest first) naturally.
        $createdAt = now()->copy()->subSeconds(($n - $i) * 30);

        $order = \App\Models\Order::create([
            'order_serial_no' => 'KDS-TEST-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT) . '-' . substr(uniqid(), -4),
            'user_id' => 2, // admin@lecayenne.fr — the chef will be acting as this user anyway
            'branch_id' => $branchId,
            'order_type' => \App\Enums\OrderType::POS,
            'subtotal' => 8.50,
            'total' => 8.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => $createdAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'payment_method' => 1,
            'payment_status' => \App\Enums\PaymentStatus::PAID,
            'pos_payment_method' => \App\Enums\PosPaymentMethod::CARD,
            'status' => \App\Enums\OrderStatus::PREPARING,
            'is_advance_order' => \App\Enums\Ask::NO,
            'queue_number' => 800 + $i,
            'source_surface' => 'pos',
        ]);

        // Add items based on depth
        $itemsPerOrder = match($depth) {
            'short' => 1,
            'realistic' => 2,
            'long' => 3,
            default => 1,
        };

        $instructionLines = match($depth) {
            'short' => '',
            'realistic' => "Pain blanc\nCheddar\nSauce algérienne\nFrites bien cuites",
            'long' => "Pain spécial brioché\nCheddar + Emmental\nViande agneau saignant\nSalade, tomate, oignon, cornichon\nSauce algérienne + harissa\nSupplément bacon\nFrites maison\nAttention allergie GLUTEN",
            default => '',
        };

        for ($j = 0; $j < $itemsPerOrder; $j++) {
            \App\Models\OrderItem::create([
                'order_id' => $order->id,
                'branch_id' => $branchId,
                'item_id' => $itemId,
                'quantity' => 1,
                'discount' => 0,
                'tax_name' => 'TVA 10',
                'tax_rate' => '10',
                'tax_type' => 1,
                'tax_amount' => 0.85,
                'price' => 8.50,
                'item_variations' => '[]',
                'item_extras' => '[]',
                'item_variation_total' => 0,
                'item_extra_total' => 0,
                'total_price' => 8.50,
                'instruction' => $instructionLines,
                'creator_type' => 'pos',
            ]);
        }

        $orders[] = $order->id;
    }

    return [
        'seeded' => $n,
        'depth' => $depth,
        'order_ids' => $orders,
        'first_id' => $orders[0] ?? null,
        'last_id' => end($orders) ?: null,
    ];
}
