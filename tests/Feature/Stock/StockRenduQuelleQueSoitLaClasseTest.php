<?php

namespace Tests\Feature\Stock;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Audit abusif stock P1 · 2026-11-06] Une commande du SITE ou de la BORNE retire son stock en tant que `FrontendOrder` ;
 * la CAISSE l'annule ou la rembourse en tant que `Order` (même ligne de `orders`). La clé du mouvement portait la classe :
 * la remise ne retrouvait pas le retrait et le stock n'était JAMAIS rendu (alors que released_qty le croyait rendu).
 * Reproduit en réel : commandes 9930 / 9931, on_hand bloqué à 1.
 */
class StockRenduQuelleQueSoitLaClasseTest extends TestCase
{
    use RefreshDatabase;

    private function commandeAvecStock(): array
    {
        $order = Order::factory()->create(['status' => OrderStatus::ACCEPT]);
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        StockLevel::query()->create(['branch_id' => (int) $order->branch_id, 'stockable_type' => Item::class,
            'stockable_id' => $item->id, 'on_hand' => 10, 'reserved' => 0]);
        OrderItem::query()->create(['order_id' => $order->id, 'branch_id' => (int) $order->branch_id, 'item_id' => $item->id,
            'quantity' => 3, 'price' => 5, 'discount' => 0, 'total_price' => 15,
            'item_variations' => json_encode([]), 'item_extras' => json_encode([])]);

        return [$order, $item];
    }

    private function stock(Item $item): int
    {
        return (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand');
    }

    public function test_retrait_par_le_site_annulation_par_la_caisse_rend_le_stock_une_seule_fois(): void
    {
        [$order, $item] = $this->commandeAvecStock();

        app(StockService::class)->decrementForOrder(FrontendOrder::withoutGlobalScopes()->findOrFail($order->id));
        $this->assertSame(7, $this->stock($item), 'retrait enregistré en FrontendOrder (site/borne)');

        $enCaisse = Order::withoutGlobalScopes()->findOrFail($order->id);
        $enCaisse->forceFill(['status' => OrderStatus::CANCELED])->saveQuietly();
        app(StockService::class)->releaseForOrder($enCaisse->fresh(), 'order_canceled');

        $this->assertSame(10, $this->stock($item), 'annulée en caisse (Order) : le stock revient');
        $this->assertSame(1, StockMovement::query()->where('delta', '>', 0)->count());

        app(StockService::class)->releaseForOrder($enCaisse->fresh(), 'order_canceled');
        $this->assertSame(10, $this->stock($item), 'rejouée : aucune seconde remise');
    }

    public function test_le_chemin_d_origine_reste_intact(): void
    {
        [$order, $item] = $this->commandeAvecStock();
        $f = FrontendOrder::withoutGlobalScopes()->findOrFail($order->id);

        app(StockService::class)->decrementForOrder($f);
        $f->forceFill(['status' => OrderStatus::CANCELED])->saveQuietly();
        app(StockService::class)->releaseForOrder($f->fresh(), 'order_canceled');

        $this->assertSame(10, $this->stock($item));
    }

    /** Audit abusif stock P2 — remboursement d'une commande déjà PRÉPARÉE : aucune restitution (la perte est inscrite ailleurs). */
    public function test_remboursement_apres_preparation_ne_rend_pas_le_stock(): void
    {
        [$order, $item] = $this->commandeAvecStock();
        app(StockService::class)->decrementForOrder($order->fresh());
        $order->forceFill(['status' => OrderStatus::DELIVERED, 'prepared_at' => now()])->saveQuietly();

        (new \App\Listeners\ReleaseStockOnRefundCreated())->handle(new \App\Events\RefundCreated($order->fresh()));

        $this->assertSame(7, $this->stock($item), 'plat préparé et remis puis remboursé : le stock ne remonte pas');
    }

    /** …mais un remboursement AVANT préparation rend toujours le stock (comportement historique). */
    public function test_remboursement_avant_preparation_rend_le_stock(): void
    {
        [$order, $item] = $this->commandeAvecStock();
        app(StockService::class)->decrementForOrder($order->fresh());

        (new \App\Listeners\ReleaseStockOnRefundCreated())->handle(new \App\Events\RefundCreated($order->fresh()));

        $this->assertSame(10, $this->stock($item));
    }
}
