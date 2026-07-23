<?php

namespace Tests\Feature\Stock;

use App\Enums\Status;
use App\Enums\TaxType;
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
use Tests\TestCase;

/**
 * [panel-manual-86-reason-collision — DÉCISION OWNER « A » 2026-07-23]
 *
 * Un 86 MANUEL (owner, panel caisse/KDS/dashboard//m) DOIT survivre à un restock
 * physique : seul un humain le rallume. Un 86 AUTO (rupture stock détectée) DOIT au
 * contraire se rallumer tout seul au restock, comme avant.
 *
 * Le restock est simulé par le SEUL chemin qui recrédite on_hand en V1 :
 * StockService::releaseForOrder (annulation/remboursement d'une commande qui avait
 * consommé le stock). C'est le même point de jonction
 * (syncItemAvailabilityForStockLevel) qu'emprunterait un futur endpoint de réception
 * de marchandise.
 *
 * Racine du bug (avant fix) : le 86 manuel ET la rupture auto écrivaient tous les
 * deux unavailable_reason='stock_rupture', slug que StockService possède comme sa
 * raison « réactivable au restock ». Fix = marqueur de provenance
 * item_branch_availability.manual_unavailable_since (le slug reste identique).
 */
class ManualEightySixStickyThroughRestockTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_86_survives_physical_restock(): void
    {
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);

        // Owner coupe l'item À LA MAIN (panel / /m) — provenance manuelle stampée.
        app(AvailabilityService::class)->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture');
        $this->assertNotNull(
            ItemBranchAvailability::query()
                ->where('item_id', $item->id)->where('branch_id', $branch->id)
                ->value('manual_unavailable_since'),
            'Le toggle manuel doit stamper manual_unavailable_since (provenance humaine).'
        );

        // Une commande consomme le dernier (on_hand 1→0) puis est annulée (restock 0→1).
        app(StockService::class)->decrementForOrder($order);
        app(StockService::class)->releaseForOrder($order);

        // Le restock NE DOIT PAS rallumer un 86 manuel.
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);
        $this->assertFalse(
            app(AvailabilityService::class)->isAvailable((int) $item->id, (int) $branch->id),
            'Un 86 manuel doit rester coupé après un restock physique — seul un humain le rallume.'
        );
    }

    public function test_auto_86_still_reactivates_on_physical_restock(): void
    {
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);

        // 86 AUTO : la commande draine le stock (on_hand 1→0) → StockService 86.
        app(StockService::class)->decrementForOrder($order);
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ]);
        $this->assertNull(
            ItemBranchAvailability::query()
                ->where('item_id', $item->id)->where('branch_id', $branch->id)
                ->value('manual_unavailable_since'),
            'Un 86 auto ne doit PAS porter de provenance manuelle.'
        );

        // Restock (annulation) → réactivation auto : comportement historique préservé.
        app(StockService::class)->releaseForOrder($order);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'unavailable_reason' => null,
        ]);
    }

    public function test_manual_86_stamped_over_a_prior_auto_86_becomes_sticky(): void
    {
        // Ordonnancement défensif : rupture AUTO d'abord (marker null), PUIS l'owner
        // confirme le 86 à la main → provenance « upgradée » manuelle → sticky au restock.
        [$branch, $order, $item] = $this->orderWithTrackedStock(1);

        app(StockService::class)->decrementForOrder($order); // auto-86, marker null
        app(AvailabilityService::class)->toggle((int) $item->id, (int) $branch->id, false, 'stock_rupture'); // manuel

        $this->assertNotNull(
            ItemBranchAvailability::query()
                ->where('item_id', $item->id)->where('branch_id', $branch->id)
                ->value('manual_unavailable_since'),
            'La confirmation manuelle par-dessus un auto-86 doit upgrader la provenance.'
        );

        app(StockService::class)->releaseForOrder($order); // restock

        $this->assertFalse(
            app(AvailabilityService::class)->isAvailable((int) $item->id, (int) $branch->id),
            'Un 86 confirmé manuellement après un auto-86 doit devenir sticky.'
        );
    }

    /**
     * @return array{0: Branch, 1: Order, 2: Item}
     */
    private function orderWithTrackedStock(int $onHand): array
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
        $order = Order::factory()->create(['branch_id' => $branch->id]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 10,
            'discount' => 0,
            'total_price' => 10,
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
