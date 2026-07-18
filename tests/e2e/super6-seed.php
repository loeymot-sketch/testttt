<?php
/**
 * SUPER6 chef-rush seeder — single function library for tinker.
 *
 * Usage from tinker:
 *   require __DIR__.'/tests/e2e/super6-seed.php';
 *   super6_seed_orders(5);                          // seed 5 generic orders
 *   super6_seed_long_order(15);                     // 1 long order (15 items)
 *   super6_seed_allergen_order(['Gluten','Lait','Fruits à coque']);
 *   super6_status();                                // print counts
 *   super6_cleanup();                               // sweep all SUPER6-* tokens
 */

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (!function_exists('super6_seed_one_order')) {

    /**
     * @param int    $itemCount   number of order_items lines
     * @param array  $instructions custom instructions per line (looped if shorter)
     * @param array  $allergens   allergens_snapshot to apply to EVERY line (or [])
     * @return Order
     */
    function super6_seed_one_order(int $itemCount = 4, array $instructions = [], array $allergens = []): Order
    {
        $branchId = 1;
        $itemId   = 1;
        $itemName = 'Sandwich Cayenne';
        $unitPrice = 8.50;

        $token = 'SUPER6-'.Str::uuid()->toString();
        // [SUPER6] backdate created_at by 0-30s so the FIFO order across the
        // batch is deterministic but cards still register as "fresh".
        $createdAt = now()->subSeconds(rand(0, 30));

        /** @var Order $order */
        $order = Order::factory()->create([
            'branch_id'         => $branchId,
            'user_id'           => 1,
            'status'            => 7,          // PREPARING (active in KDS grid)
            'order_type'        => 10,         // TAKEAWAY (not DELIVERY=15)
            'source_surface'    => 'kiosk',
            'payment_status'    => 5,          // PaymentStatus::PAID=5
            'payment_method'    => 1,          // cash
            'is_advance_order'  => 10,         // Ask::NO=10 (standard, non-advance)
            'token'             => $token,
            'queue_number'      => rand(100, 999),
            'total'             => $itemCount * $unitPrice,
            'subtotal'          => $itemCount * $unitPrice,
            'created_at'        => $createdAt,
            'updated_at'        => $createdAt,
            'order_datetime'    => $createdAt,
        ]);

        $defaultInstr = ['', 'Sauce blanche', 'Sans oignon', 'Bien cuit', 'À emporter'];
        $instructions = $instructions ?: $defaultInstr;

        for ($i = 0; $i < $itemCount; $i++) {
            OrderItem::withoutEvents(function () use ($order, $itemId, $itemName, $unitPrice, $i, $instructions, $allergens, $branchId) {
                OrderItem::create([
                    'order_id'      => $order->id,
                    'branch_id'     => $branchId,
                    'item_id'       => $itemId,
                    'quantity'      => rand(1, 3),
                    'discount'      => 0,
                    'tax_name'      => 'TVA',
                    'tax_rate'      => 10,
                    'tax_amount'    => 0,
                    'price'         => $unitPrice,
                    'item_variation_total' => 0,
                    'item_extra_total'     => 0,
                    'total_price'   => $unitPrice * rand(1, 3),
                    'item_variations' => json_encode([]),
                    'item_extras'   => json_encode([]),
                    'composition_snapshot' => json_encode([
                        'item_id'   => $itemId,
                        'item_name' => $itemName.' '.($i + 1),
                        'qty'       => 1,
                    ]),
                    'allergens_snapshot' => json_encode($allergens),
                    'instruction'   => $instructions[$i % count($instructions)] ?? '',
                ]);
            });
        }

        return $order;
    }

    function super6_seed_orders(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            // Vary item count 3-12 to mimic real rush variety.
            $order = super6_seed_one_order(rand(3, 12), [], []);
            $ids[] = $order->id;
        }
        return $ids;
    }

    function super6_seed_long_order(int $itemCount = 15): int
    {
        $instructions = [
            'Sauce blanche, sans oignon, bien cuit',
            'Pas de sel, sans gluten',
            'Cuisson medium, ajout cornichons',
            'Avec piment frais, sans tomate',
        ];
        $order = super6_seed_one_order($itemCount, $instructions, []);
        return $order->id;
    }

    function super6_seed_allergen_order(array $allergens): int
    {
        $order = super6_seed_one_order(4, ['Allergie patient connu'], $allergens);
        return $order->id;
    }

    function super6_status(): void
    {
        $branchId = 1;
        $byStatus = [];
        foreach ([4, 7, 8] as $s) {
            $byStatus[$s] = Order::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)->where('status', $s)
                ->where('token', 'like', 'SUPER6-%')->count();
        }
        $total = array_sum($byStatus);
        $allPile = Order::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)->whereIn('status', [4, 7, 8])->count();
        echo "SUPER6 tokens active: $total  (ACCEPT=".$byStatus[4]." PREPARING=".$byStatus[7]." PREPARED=".$byStatus[8].")\n";
        echo "TOTAL active pile branch=1 (all sources): $allPile\n";
    }

    function super6_cleanup(): int
    {
        $ids = Order::withoutGlobalScope(BranchScope::class)
            ->where('token', 'like', 'SUPER6-%')->pluck('id');
        if ($ids->isEmpty()) {
            echo "Nothing to clean.\n";
            return 0;
        }
        DB::table('order_status_transitions')->whereIn('order_id', $ids)->delete();
        DB::table('transactions')->whereIn('order_id', $ids)->delete();
        DB::table('order_items')->whereIn('order_id', $ids)->delete();
        DB::table('orders')->whereIn('id', $ids)->update(['fiscal_sequence_no' => null]);
        $del = DB::table('orders')->whereIn('id', $ids)->delete();
        echo "Cleaned $del SUPER6 orders + cascades.\n";
        return $del;
    }
}
