<?php

namespace Tests\Feature\Catalog;

use App\Models\Branch;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN 2026-06-01 — CAT-AUTHZ-01 heal] Photo-route authz parity.
 *
 * The adversarial audit (wf6dhhn09) found POST /api/admin/items/{item}/photo
 * (ItemPhotoController::store) does the same destructive media-replace as
 * /item/change-image but was gated only by permission:items_edit — missing the
 * Admin/Tenant-Admin reservation that ProductPhotoAuthzTest locks on change-image.
 * A branch user granted items_edit could replace catalog photos here while being
 * 403'd on change-image (authz drift). Heal mirrors change-image's role gate.
 *
 * @group sentinel
 * @group security
 */
class ItemPhotoRouteAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_photo_route_is_reserved_to_global_catalog_roles(): void
    {
        $item = Item::factory()->create();

        $branchEditor = User::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $branchEditor->givePermissionTo('items_edit');
        $this->actingAs($branchEditor, 'sanctum')
            ->postJson("/api/admin/items/{$item->id}/photo", [
                'photo' => UploadedFile::fake()->image('branch.jpg', 320, 240),
            ])
            ->assertForbidden();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/items/{$item->id}/photo", [
                'photo' => UploadedFile::fake()->image('admin.jpg', 320, 240),
            ])
            ->assertOk();
    }
}
