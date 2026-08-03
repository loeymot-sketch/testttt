<?php

namespace Tests\Feature\Catalog;

use App\Models\Order;
use App\Models\OrderItem;
use Database\Factories\BranchFactory;
use Database\Factories\ItemFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Sentinel — CV1-LIFECYCLE-UX-001 task 1.8 (force-delete guard).
 *
 * Plan: plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md §1.8
 */
class ItemDeletionWithOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function actingAdmin(): object
    {
        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        return $admin;
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /**
     * Soft-delete without ?force=true — unchanged behavior, success 202.
     */
    public function test_soft_delete_works_without_force_param(): void
    {
        $admin = $this->actingAdmin();
        $item = ItemFactory::new()->create([]);

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/item/{$item->id}");

        $response->assertStatus(202);
        $this->assertSoftDeleted('items', ['id' => $item->id]);
    }

    /**
     * @param \App\Models\Item $item
     */
    private function createOrderItemForItem($item): void
    {
        $branch = BranchFactory::new()->create();
        $user = UserFactory::new()->create([]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'discount' => 0,
            'price' => 10,
            'total_price' => 10,
        ]);
    }

    /**
     * Hard-delete blocked when order history exists and protection flag is on — 409 and message contains count.
     */
    public function test_force_delete_blocked_when_order_history_exists_and_flag_on(): void
    {
        Config::set('catalog_v15.item_deletion.protect_force_delete_when_referenced', true);

        $admin = $this->actingAdmin();
        $item = ItemFactory::new()->create([]);
        $this->createOrderItemForItem($item);

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/item/{$item->id}?force=true");

        $response->assertStatus(409);
        $response->assertJsonPath('error', 'errors.item.cannot_force_delete_with_history');
        $payload = $response->json();
        $this->assertArrayHasKey('message', $payload);
        $this->assertStringContainsString('1', (string) $payload['message']);
        $this->assertDatabaseHas('items', ['id' => $item->id, 'deleted_at' => null]);
    }

    /**
     * Hard-delete allowed when no OrderItem reference — 202, row removed from items.
     */
    public function test_force_delete_allowed_when_no_order_history(): void
    {
        Config::set('catalog_v15.item_deletion.protect_force_delete_when_referenced', true);

        $admin = $this->actingAdmin();
        $item = ItemFactory::new()->create([]);

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/item/{$item->id}?force=true");

        $response->assertStatus(202);
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }

    /**
     * Protection off — ?force=true is not blocked by application guard (no 409) even with OrderItem.
     */
    public function test_force_delete_allowed_when_flag_off(): void
    {
        Config::set('catalog_v15.item_deletion.protect_force_delete_when_referenced', false);

        $admin = $this->actingAdmin();
        $item = ItemFactory::new()->create([]);
        $this->createOrderItemForItem($item);

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->deleteJson("/api/admin/item/{$item->id}?force=true");

        $response->assertStatus(202);
        $this->assertDatabaseMissing('items', ['id' => $item->id]);
    }
}
