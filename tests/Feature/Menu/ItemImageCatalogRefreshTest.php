<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Http\Requests\ChangeImageRequest;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ItemImageCatalogRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_image_change_dispatches_full_catalog_refresh_event(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $item = Item::factory()->create([
            'price' => 9.50,
            'status' => Status::ACTIVE,
        ]);

        $request = ChangeImageRequest::create(
            '/api/admin/item/change-image/' . $item->id,
            'POST',
            [],
            [],
            ['image' => UploadedFile::fake()->image('borne-product.jpg', 600, 400)]
        );

        app(ItemService::class)->changeImage($request, $item);

        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($item): bool {
            return $event->itemId === (int) $item->id
                && $event->branchId === null
                && $event->type === 'full';
        });
    }
}
