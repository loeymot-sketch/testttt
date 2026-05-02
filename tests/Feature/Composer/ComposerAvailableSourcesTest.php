<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [CV1-WIZARD-COMPOSABLE-001 T-WC-SOURCE-PICKER-01]
 * Sentinels for GET /api/admin/composer/items/{item}/available-sources:
 * always returns the {item_attribute, extra_group, addon} shape (empty arrays
 * are valid) and is gated by `catalog.compose`.
 */
class ComposerAvailableSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        $this->seed(ComposerPermissionsMinimalSeeder::class);

        $this->item = Item::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_returns_attribute_extra_addon_shape_for_bare_item(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/items/{$this->item->id}/available-sources");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.item_id', $this->item->id)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'item_id',
                    'item_attribute',
                    'extra_group',
                    'addon',
                ],
            ]);

        $this->assertSame([], $response->json('data.item_attribute'));
        $this->assertSame([], $response->json('data.extra_group'));
        $this->assertSame([], $response->json('data.addon'));
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson("/api/admin/composer/items/{$this->item->id}/available-sources");

        $this->assertContains($response->status(), [401, 403, 419]);
    }
}
