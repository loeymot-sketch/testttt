<?php

namespace Tests\Feature\Catalog;

use App\Models\Branch;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProductPhotoAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_product_photo_mutation_is_reserved_to_global_catalog_roles(): void
    {
        $item = Item::factory()->create();
        $branchEditor = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $branchEditor->givePermissionTo('items_edit');
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $this->actingAs($branchEditor, 'sanctum')
            ->postJson("/api/admin/item/change-image/{$item->id}", [
                'image' => UploadedFile::fake()->image('branch-photo.jpg', 320, 240),
            ])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/item/change-image/{$item->id}", [
                'image' => UploadedFile::fake()->image('admin-photo.jpg', 320, 240),
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $item->id);
    }
}
