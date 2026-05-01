<?php

namespace Tests\Feature\Catalog;

use App\Enums\Ask;
use App\Enums\EventType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhotoEndToEndKioskInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_product_photo_upload_invalidates_kiosk_menu_and_returns_new_snapshot(): void
    {
        Queue::fake();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        [$branch, $admin, $kioskUser, $item] = $this->catalogFixture();
        $snapshot = app(MenuSnapshot::class);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $initial = $this->getJson('/api/frontend/menu')
            ->assertOk()
            ->assertJsonPath('data.snapshot_version', 1)
            ->json('data');

        $initialRow = collect($initial['items'])->firstWhere('id', $item->id);
        $this->assertIsArray($initialRow);
        $initialImage = $initialRow['image'];
        $this->assertNotEmpty($initialImage);
        $this->assertTrue(Cache::has("kiosk.menu.branch.{$branch->id}"));
        $beforeSnapshot = $snapshot->current($branch->id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/item/change-image/{$item->id}", [
                'image' => UploadedFile::fake()->image('new-kiosk-photo.jpg', 640, 480),
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $item->id);

        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));
        $this->assertGreaterThan($beforeSnapshot, $snapshot->current($branch->id));

        $catalogRow = DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->where('aggregate_type', 'item')
            ->where('aggregate_id', $item->id)
            ->where('branch_id', $branch->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('CatalogChanged', $catalogRow->broadcast_as);
        $this->assertSame('updated', $catalogRow->payload['change_type']);
        $this->assertSame('full', $catalogRow->payload['payload_diff']['type']);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $after = $this->getJson('/api/frontend/menu')
            ->assertOk()
            ->assertJsonPath('data.snapshot_version', $snapshot->current($branch->id))
            ->json('data');

        $afterRow = collect($after['items'])->firstWhere('id', $item->id);
        $this->assertIsArray($afterRow);
        $this->assertNotEmpty($afterRow['image']);
        $this->assertNotSame($initialImage, $afterRow['image']);
        $this->assertStringContainsString('new-kiosk-photo', $afterRow['image']);
    }

    private function catalogFixture(): array
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');

        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::query()->create([
            'machine_id' => 'photo-e2e-' . uniqid(),
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'photo-e2e-kiosk',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'is_available' => true,
            'channels' => null,
            'name' => 'Photo E2E Burger',
            'slug' => 'photo-e2e-burger',
            'price' => 11.50,
        ]);

        return [$branch, $admin, $kioskUser, $item];
    }
}
