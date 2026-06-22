<?php

namespace Tests\Feature\Sentinels;

use App\Enums\EventType;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WAVE5-DATA-004] Sentinel — when F-016a-BIS extras/variations rupture events
 * fire, they must produce TWO outbox rows per branch:
 *  1) the dedicated MENU_EXTRA_AVAILABILITY_CHANGED / MENU_VARIATION_AVAILABILITY_CHANGED
 *  2) a CATALOG_CHANGED bridge row so the generic SPA Echo subscribers
 *     (kiosk menu cache, POS catalog) refresh without waiting for the F-016b
 *     dedicated frontend handlers.
 *
 * Regression risk this sentinel locks: someone reverts the dual-listener wiring
 * in EventServiceProvider and the kiosk silently keeps stale availability until
 * the 60s TTL expiry — degrading UX during rush with no error surfaced.
 */
class ItemExtraVariationBridgeCatalogChangedSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_item_extra_availability_changed_fires_catalog_changed_bridge(): void
    {
        $branch = Branch::factory()->create();

        ItemExtraAvailabilityChanged::dispatch(
            extraId: 42,
            branchId: (int) $branch->id,
            isAvailable: false,
            reason: 'seasonal',
        );

        // Dedicated row (F-016a-BIS contract)
        $this->assertDatabaseHas('domain_events', [
            'event_type' => EventType::MENU_EXTRA_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item_extra',
            'aggregate_id' => 42,
            'branch_id' => $branch->id,
        ]);

        // [WAVE5-DATA-004] Bridge row — generic catalog stream
        $this->assertDatabaseHas('domain_events', [
            'event_type' => EventType::CATALOG_CHANGED,
            'aggregate_type' => 'item_extra',
            'aggregate_id' => 42,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_item_variation_availability_changed_fires_catalog_changed_bridge(): void
    {
        $branch = Branch::factory()->create();

        ItemVariationAvailabilityChanged::dispatch(
            variationId: 7,
            branchId: (int) $branch->id,
            isAvailable: false,
            reason: 'recipe_change',
        );

        $this->assertDatabaseHas('domain_events', [
            'event_type' => EventType::MENU_VARIATION_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item_variation',
            'aggregate_id' => 7,
            'branch_id' => $branch->id,
        ]);

        $this->assertDatabaseHas('domain_events', [
            'event_type' => EventType::CATALOG_CHANGED,
            'aggregate_type' => 'item_variation',
            'aggregate_id' => 7,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_event_service_provider_wires_both_listeners(): void
    {
        $listeners = (new \App\Providers\EventServiceProvider(app()))->listens();

        $this->assertContains(
            \App\Listeners\PersistItemExtraAvailabilityChangedToOutbox::class,
            $listeners[ItemExtraAvailabilityChanged::class] ?? [],
            'ItemExtraAvailabilityChanged must have the dedicated outbox listener wired.'
        );
        $this->assertContains(
            \App\Listeners\PersistCatalogChangedToOutbox::class,
            $listeners[ItemExtraAvailabilityChanged::class] ?? [],
            '[WAVE5-DATA-004] ItemExtraAvailabilityChanged must also bridge to CatalogChanged outbox.'
        );

        $this->assertContains(
            \App\Listeners\PersistItemVariationAvailabilityChangedToOutbox::class,
            $listeners[ItemVariationAvailabilityChanged::class] ?? [],
            'ItemVariationAvailabilityChanged must have the dedicated outbox listener wired.'
        );
        $this->assertContains(
            \App\Listeners\PersistCatalogChangedToOutbox::class,
            $listeners[ItemVariationAvailabilityChanged::class] ?? [],
            '[WAVE5-DATA-004] ItemVariationAvailabilityChanged must also bridge to CatalogChanged outbox.'
        );
    }
}
