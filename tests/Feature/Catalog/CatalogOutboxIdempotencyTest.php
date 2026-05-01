<?php

namespace Tests\Feature\Catalog;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\CategoryUpdated;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CatalogOutboxIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_catalog_event_correlation_is_persisted_once_per_branch(): void
    {
        Queue::fake();

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $correlationId = '11111111-1111-4111-8111-111111111111';

        Log::shareContext(['correlation_id' => $correlationId]);

        event(new CategoryUpdated(1234));
        event(new CategoryUpdated(1234));

        $this->assertSame(1, DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->where('aggregate_type', 'category')
            ->where('aggregate_id', 1234)
            ->where('branch_id', $branch->id)
            ->where('correlation_id', $correlationId)
            ->count());
    }
}
