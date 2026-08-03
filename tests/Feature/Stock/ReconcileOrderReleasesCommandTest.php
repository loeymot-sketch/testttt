<?php

namespace Tests\Feature\Stock;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Events\OrderCanceled;
use App\Events\RefundCreated;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [DEEP-R2 2026-07-15 / P1] Un crash entre le COMMIT d'un cancel/destroy et ses
 * listeners synchrones perd la libération (released_qty < quantity, on_hand
 * sous-évalué) sans trace. La commande foodking:reconcile-releases rattrape.
 */
class ReconcileOrderReleasesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciles_lost_release_after_simulated_crash(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::ACCEPT]);
        $branchId = (int) $order->branch_id;
        $item = Item::factory()->create(['status' => Status::ACTIVE]);

        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branchId,
            'item_id' => $item->id,
            'quantity' => 3,
            'price' => 5,
            'discount' => 0,
            'total_price' => 15,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);

        // Décrément réel à la création : 10 → 7 (pose le movement order_created).
        app(StockService::class)->decrementForOrder($order->fresh());
        $this->assertSame(7, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));

        // Crash simulé : la commande passe CANCELED sans qu'AUCUN listener n'ait
        // tourné (released_qty reste 0) — état exact de la fenêtre post-commit.
        $order->forceFill(['status' => OrderStatus::CANCELED])->saveQuietly();

        $this->artisan('foodking:reconcile-releases')->assertSuccessful();

        $this->assertSame(10, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
        $oi = OrderItem::query()->where('order_id', $order->id)->first();
        $this->assertSame(3, (int) $oi->released_qty);

        // Idempotence : un second passage ne sur-libère pas.
        $this->artisan('foodking:reconcile-releases')->assertSuccessful();
        $this->assertSame(10, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
    }
    /**
     * [DEEP-R2b 2026-07-15 / P1] Régression du garde isToday : une commande d'HIER
     * annulée aujourd'hui (refund + cancel dans le même flux) doit libérer le stock
     * UNE seule fois — le retour anticipé du listener availability sautait le SEUL
     * écrivain du ledger released_qty → double crédit on_hand (clés d'idempotence
     * distinctes par reason).
     */
    public function test_yesterday_order_canceled_today_releases_stock_exactly_once(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::ACCEPT]);
        $order->forceFill(['created_at' => now()->subDay()->setTime(23, 50)])->saveQuietly();
        $branchId = (int) $order->branch_id;
        $item = Item::factory()->create(['status' => Status::ACTIVE]);

        StockLevel::query()->create([
            'branch_id' => $branchId,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branchId,
            'item_id' => $item->id,
            'quantity' => 3,
            'price' => 5,
            'discount' => 0,
            'total_price' => 15,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
        ]);

        app(StockService::class)->decrementForOrder($order->fresh());
        $this->assertSame(7, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));

        // Flux réel changeStatus d'une commande PAYÉE cash : RefundCreated PUIS OrderCanceled.
        RefundCreated::dispatch($order->fresh());
        OrderCanceled::dispatch($order->fresh());

        // UNE seule libération : 7 → 10, pas 13.
        $this->assertSame(10, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
        $oi = OrderItem::query()->where('order_id', $order->id)->first();
        $this->assertSame(3, (int) $oi->released_qty);
    }
}
