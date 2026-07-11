<?php

namespace Tests\Feature\Menu;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_creates_row_when_missing_and_dispatches_event(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $item = Item::factory()->create();
        $branch = Branch::factory()->create();

        $row = app(AvailabilityService::class)->toggle(
            itemId: (int) $item->id,
            branchId: (int) $branch->id,
            available: false,
            reason: 'out_of_stock'
        );

        $this->assertFalse((bool) $row->is_available);
        $this->assertSame('out_of_stock', $row->unavailable_reason);
        $this->assertNotNull($row->unavailable_since);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id'            => $item->id,
            'branch_id'          => $branch->id,
            'is_available'       => 0,
            'unavailable_reason' => 'out_of_stock',
        ]);

        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $e) use ($item, $branch) {
            return $e->itemId === (int) $item->id
                && $e->branchId === (int) $branch->id
                && $e->isAvailable === false
                && $e->reason === 'out_of_stock'
                && $e->type === 'branch_availability';
        });
    }

    public function test_toggle_back_to_available_clears_reason_and_since(): void
    {
        $item = Item::factory()->create();
        $branch = Branch::factory()->create();

        $service = app(AvailabilityService::class);
        $service->toggle((int) $item->id, (int) $branch->id, false, 'out_of_stock');
        $row = $service->toggle((int) $item->id, (int) $branch->id, true, null);

        $this->assertTrue((bool) $row->is_available);
        $this->assertNull($row->unavailable_reason);
        $this->assertNull($row->unavailable_since);
    }

    public function test_toggle_is_idempotent_when_state_unchanged(): void
    {
        $item = Item::factory()->create();
        $branch = Branch::factory()->create();
        $service = app(AvailabilityService::class);

        $service->toggle((int) $item->id, (int) $branch->id, false, 'out_of_stock');

        Event::fake([ItemAvailabilityChanged::class]);
        $service->toggle((int) $item->id, (int) $branch->id, false, 'out_of_stock');

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    public function test_is_available_defaults_to_true_when_no_row(): void
    {
        $item = Item::factory()->create();
        $branch = Branch::factory()->create();

        $this->assertTrue(
            app(AvailabilityService::class)->isAvailable((int) $item->id, (int) $branch->id)
        );
    }

    public function test_is_available_returns_stored_value(): void
    {
        $item = Item::factory()->create();
        $branch = Branch::factory()->create();
        $service = app(AvailabilityService::class);

        $service->toggle((int) $item->id, (int) $branch->id, false, 'seasonal');
        $this->assertFalse($service->isAvailable((int) $item->id, (int) $branch->id));

        $service->toggle((int) $item->id, (int) $branch->id, true, null);
        $this->assertTrue($service->isAvailable((int) $item->id, (int) $branch->id));
    }

    public function test_toggle_for_all_branches_touches_every_branch(): void
    {
        $item = Item::factory()->create();
        Branch::factory()->count(3)->create();

        $count = app(AvailabilityService::class)->toggleForAllBranches(
            itemId: (int) $item->id,
            available: false,
            reason: 'closed_today'
        );

        $this->assertGreaterThanOrEqual(3, $count);
        $this->assertSame(
            3,
            ItemBranchAvailability::query()
                ->where('item_id', $item->id)
                ->where('is_available', false)
                ->count()
        );
    }

    public function test_listener_persists_branch_scoped_event_to_outbox(): void
    {
        $item = Item::factory()->create();
        $branch = Branch::factory()->create();

        app(AvailabilityService::class)->toggle(
            itemId: (int) $item->id,
            branchId: (int) $branch->id,
            available: false,
            reason: 'out_of_stock'
        );

        $this->assertDatabaseHas('domain_events', [
            'event_type'   => \App\Enums\EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_id' => $item->id,
            'branch_id'    => $branch->id,
            'broadcast_as' => 'ItemAvailabilityChanged',
        ]);

        $event = \App\Models\DomainEvent::query()
            ->where('aggregate_id', $item->id)
            ->where('branch_id', $branch->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($event);

        $channels = json_decode($event->channel, true);
        $this->assertSame(['private-branch.' . $branch->id], $channels);

        $payload = is_array($event->payload) ? $event->payload : json_decode($event->payload, true);
        $this->assertSame((int) $item->id, (int) $payload['item_id']);
        $this->assertSame($branch->id, (int) $payload['branch_id']);
        $this->assertFalse((bool) $payload['is_available']);
        $this->assertSame('out_of_stock', $payload['reason']);
    }

    /**
     * [CAISSE-LOGIC-HEAL SYNC-P1 2026-07-11] Un composant de menu (addon) en rupture doit
     * rendre la commande du menu inordonnable : `componentItemIdsFor` résout l'addon_item_id
     * du composant, et la garde le rejette. Avant, seul l'item de 1er niveau était testé
     * → survente du composant 86 (asymétrie lecture/écriture).
     */
    public function test_menu_component_out_of_stock_blocks_order(): void
    {
        $menu      = Item::factory()->create();  // le menu (1er niveau, disponible)
        $component = Item::factory()->create();  // la boisson composant (en rupture)
        $branch    = Branch::factory()->create();

        // Lien menu → composant via ItemAddon.
        $addon = \App\Models\ItemAddon::create([
            'item_id'       => $menu->id,
            'addon_item_id' => $component->id,
            'status'        => 1,
        ]);

        // Composant marqué en rupture pour la branche.
        app(AvailabilityService::class)->toggle((int) $component->id, (int) $branch->id, false, 'out_of_stock');

        $service = app(AvailabilityService::class);
        $requestItems = [ (object) ['item_id' => $menu->id, 'item_addons' => [ (object) ['id' => $addon->id, 'quantity' => 1] ]] ];

        // Le helper résout bien le composant.
        $this->assertContains((int) $component->id, $service->componentItemIdsFor($requestItems));

        // La garde, alimentée du menu + du composant, doit REJETER (composant en rupture).
        $this->expectException(\InvalidArgumentException::class);
        $service->assertItemsOrderableForBranch(
            (int) $branch->id,
            array_merge([(int) $menu->id], $service->componentItemIdsFor($requestItems)),
            false
        );
    }

    /**
     * Contre-preuve : composant DISPONIBLE → la commande passe (pas de faux blocage).
     */
    public function test_menu_component_in_stock_allows_order(): void
    {
        $menu      = Item::factory()->create();
        $component = Item::factory()->create();
        $branch    = Branch::factory()->create();

        $addon = \App\Models\ItemAddon::create([
            'item_id'       => $menu->id,
            'addon_item_id' => $component->id,
            'status'        => 1,
        ]);

        $service = app(AvailabilityService::class);
        $requestItems = [ (object) ['item_id' => $menu->id, 'item_addons' => [ (object) ['id' => $addon->id, 'quantity' => 1] ]] ];

        // Aucune rupture → ne throw pas.
        $service->assertItemsOrderableForBranch(
            (int) $branch->id,
            array_merge([(int) $menu->id], $service->componentItemIdsFor($requestItems)),
            false
        );
        $this->assertTrue(true);
    }
}
