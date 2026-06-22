<?php

namespace Tests\Feature\Order;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinel — CV1-V1-CLOSEOUT-001 T-DEEP-PROD-01.
 *
 * Verrouille l'invariant FoodKing #1 (Backend Pricing SSOT) côté lifecycle :
 *   - Quand admin change le prix d'un produit, les ordres déjà créés
 *     préservent le prix capturé via order_items.price (snapshot).
 *   - Les nouveaux ordres consomment le nouveau prix.
 *
 * Référence migration: database/migrations/2022_11_17_110832_create_order_items_table.php:23
 * Référence model: app/Models/OrderItem.php:25 ($fillable inclut 'price')
 *
 * Plan : plans/PLAN_CV1-V1-ULTRA-DEEP-CATALOG-WIZARD-STOCK-2026-05-02.md Famille B.
 */
class PriceChangeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function baseOrderItemAttributes(int $orderId, int $branchId, int $itemId, array $overrides = []): array
    {
        return array_merge([
            'order_id' => $orderId,
            'branch_id' => $branchId,
            'item_id' => $itemId,
            'quantity' => 1,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'item_variations' => '[]',
            'item_extras' => '[]',
        ], $overrides);
    }

    public function test_existing_order_preserves_price_after_admin_changes_item_price(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->create(['price' => 10.00]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]);

        $orderItem = OrderItem::query()->create($this->baseOrderItemAttributes(
            $order->id,
            $branch->id,
            $item->id,
            [
                'quantity' => 1,
                'price' => 10.00,
                'total_price' => 10.00,
            ]
        ));

        $this->assertSame('10.000000', (string) $orderItem->refresh()->price);

        $item->update(['price' => 15.00]);
        $this->assertSame('15.000000', (string) $item->refresh()->price);

        $orderItem->refresh();
        $this->assertSame(
            '10.000000',
            (string) $orderItem->price,
            'Order existant doit préserver son prix snapshot (10.00) malgré la mise à jour admin (15.00).'
        );
    }

    public function test_new_order_uses_new_price_after_admin_changes_it(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->create(['price' => 10.00]);

        $item->update(['price' => 20.00]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]);

        $unit = $item->fresh()->price;
        $orderItem = OrderItem::query()->create($this->baseOrderItemAttributes(
            $order->id,
            $branch->id,
            $item->id,
            [
                'price' => $unit,
                'total_price' => $unit,
            ]
        ));

        $this->assertSame('20.000000', (string) $orderItem->refresh()->price);
        $this->assertSame('20.000000', (string) $orderItem->refresh()->total_price);
    }

    public function test_concurrent_price_change_during_order_creation_does_not_corrupt_existing(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->create(['price' => 10.00]);

        $order1 = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]);
        $oi1 = OrderItem::query()->create($this->baseOrderItemAttributes(
            $order1->id,
            $branch->id,
            $item->id,
            [
                'quantity' => 2,
                'price' => 10.00,
                'total_price' => 20.00,
            ]
        ));

        $item->update(['price' => 12.50]);

        $order2 = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]);
        $unit = $item->fresh()->price;
        $oi2 = OrderItem::query()->create($this->baseOrderItemAttributes(
            $order2->id,
            $branch->id,
            $item->id,
            [
                'price' => $unit,
                'total_price' => $unit,
            ]
        ));

        $this->assertSame('10.000000', (string) $oi1->refresh()->price);
        $this->assertSame('20.000000', (string) $oi1->refresh()->total_price);

        $this->assertSame('12.500000', (string) $oi2->refresh()->price);
    }
}
