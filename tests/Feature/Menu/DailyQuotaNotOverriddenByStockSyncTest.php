<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Services\Menu\AvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [S5-01 / P2-q 2026-07-18] Collision de la raison `'out_of_stock'`.
 *
 * AvailabilityService écrit `unavailable_reason='out_of_stock'` pour signifier
 * « quota journalier (max_daily_qty) atteint ». StockService recensait cette même
 * raison dans `isAutoStockRuptureReason()` → sur un item combinant max_daily_qty ET
 * stock_levels, la chaîne de listeners OrderCreated (DecrementItemAvailabilityOnOrder
 * PUIS DecrementStockOnOrderCreated) réactivait l'item DANS LA MÊME REQUÊTE dès que
 * on_hand>0, cassant le plafond jour + émettant un faux event « restocké ».
 *
 * Fix : StockService exclut la raison quota de son set d'auto-ruptures. Il ne
 * réactive/écrase donc JAMAIS un item 86'd pour quota ; la réactivation quota reste
 * pilotée par la machinerie quota (setMaxDailyQty / releaseForOrderItems /
 * ResetStaleDailyQuotaCommand).
 */
class DailyQuotaNotOverriddenByStockSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * (a) Quota ATTEINT + on_hand>0 : après le cycle décrément quota puis
     * décrément/sync stock (ordre réel des listeners OrderCreated), l'item reste
     * indisponible et StockService n'émet AUCUNE réactivation fantôme.
     */
    public function test_daily_quota_86_survives_stock_decrement_when_on_hand_positive(): void
    {
        // Quota = 2, déjà 1 consommé, stock large (5) : la commande de qty 1 fait
        // atteindre le plafond jour tout en laissant on_hand > 0.
        [$branch, $order, $item] = $this->orderWithTrackedStock(onHand: 5, orderQty: 1, maxDailyQty: 2, consumed: 1);

        Event::fake([ItemAvailabilityChanged::class]);

        // Ordre EXACT des listeners OrderCreated (EventServiceProvider:175 → :176).
        app(AvailabilityService::class)->decrementForOrder($order);   // quota → 86 'out_of_stock'
        app(StockService::class)->decrementForOrder($order);          // on_hand 5→4, sync

        // Le plafond jour tient malgré on_hand>0.
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
        ]);

        // Aucune réactivation / faux « stock_restocked » émis par StockService.
        Event::assertNotDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event): bool {
            return $event->isAvailable === true || $event->reason === 'stock_restocked';
        });
    }

    /**
     * (b) Rupture PHYSIQUE (on_hand<=0) : StockService 86 l'item normalement,
     * raison 'stock_rupture' — comportement inchangé.
     */
    public function test_physical_rupture_still_marks_item_unavailable(): void
    {
        // Pas de quota (max_daily_qty=null), stock = 1 : la commande vide le stock.
        [$branch, $order, $item] = $this->orderWithTrackedStock(onHand: 1, orderQty: 1, maxDailyQty: null, consumed: 0);

        Event::fake([ItemAvailabilityChanged::class]);

        app(StockService::class)->decrementForOrder($order);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($branch, $item): bool {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branch->id
                && $event->isAvailable === false
                && $event->reason === 'stock_rupture';
        });
    }

    /**
     * (c) Reset jour : un item 86'd pour quota est réactivé par
     * ResetStaleDailyQuotaCommand (indépendant de StockService).
     */
    public function test_daily_reset_reactivates_quota_86_item(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['channels' => ['pos']]);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now()->subDay(),
            'max_daily_qty' => 2,
            'daily_consumed_qty' => 2,
            'daily_reset_at' => now()->subDay()->toDateString(),
        ]);

        Artisan::call('foodking:availability:reset-stale-quota');

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);
    }

    /**
     * @return array{0: Branch, 1: Order, 2: Item}
     */
    private function orderWithTrackedStock(int $onHand, int $orderQty, ?int $maxDailyQty, int $consumed): array
    {
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'max_daily_qty' => $maxDailyQty,
            'daily_consumed_qty' => $consumed,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $order = Order::factory()->create(['branch_id' => $branch->id]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => $orderQty,
            'price' => 10,
            'discount' => 0,
            'total_price' => 10 * $orderQty,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => $onHand,
            'reserved' => 0,
        ]);

        return [$branch, $order, $item];
    }
}
