<?php

namespace Tests\Feature\Catalog;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\CategoryUpdated;
use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Item;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogChangedDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_catalog_mutation_persists_catalog_changed_per_active_branch(): void
    {
        Queue::fake();

        $branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);
        Branch::factory()->create(['status' => 0]);
        $snapshot = app(MenuSnapshot::class);
        $beforeA = $snapshot->current($branchA->id);
        $beforeB = $snapshot->current($branchB->id);

        event(new CategoryUpdated(987));

        $rows = DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->orderBy('branch_id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame([$branchA->id, $branchB->id], $rows->pluck('branch_id')->all());

        foreach ($rows as $row) {
            $this->assertSame('category', $row->aggregate_type);
            $this->assertSame(987, (int) $row->aggregate_id);
            $this->assertSame('CatalogChanged', $row->broadcast_as);
            $this->assertSame(['private-branch.' . $row->branch_id], json_decode($row->channel, true));
            $this->assertSame('category', $row->payload['entity_type']);
            $this->assertSame(987, (int) $row->payload['entity_id']);
            $this->assertSame('updated', $row->payload['change_type']);
            $this->assertGreaterThanOrEqual(2, (int) $row->payload['snapshot_version']);
        }

        $this->assertGreaterThan($beforeA, $snapshot->current($branchA->id));
        $this->assertGreaterThan($beforeB, $snapshot->current($branchB->id));
    }

    public function test_branch_availability_catalog_mutation_stays_branch_scoped(): void
    {
        Queue::fake();

        $branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create();
        $snapshot = app(MenuSnapshot::class);
        $beforeB = $snapshot->current($branchB->id);

        event(ItemAvailabilityChanged::forBranch(
            itemId: (int) $item->id,
            branchId: (int) $branchA->id,
            isAvailable: false,
            reason: 'stock_rupture',
            price: (float) $item->price,
        ));

        $row = DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->firstOrFail();

        $this->assertSame($branchA->id, (int) $row->branch_id);
        $this->assertSame('item', $row->payload['entity_type']);
        $this->assertSame((int) $item->id, (int) $row->payload['entity_id']);
        $this->assertSame('availability_changed', $row->payload['change_type']);
        $this->assertSame('branch_availability', $row->payload['payload_diff']['type']);
        $this->assertFalse($row->payload['payload_diff']['is_available']);
        $this->assertSame($beforeB, $snapshot->current($branchB->id));
    }
}
