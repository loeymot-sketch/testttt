<?php

namespace Tests\Feature\Items;

use App\Enums\Ask;
use App\Enums\ItemType;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * CENTRAL-C1-P3-03 — POST /api/admin/item créait l'item avec creator_id NULL.
 *
 * ItemService::store() faisait Item::create($request->validated() + ['slug'])
 * sans renseigner les colonnes d'audit creator_id / creator_type (présentes en
 * base : items.creator_type varchar nullable, items.creator_id bigint nullable).
 * Conséquence : aucune traçabilité « qui a créé ce produit » — incohérent avec
 * Order::creator_id (= Auth::id(), cf. OrderService:808) utilisé pour la
 * traçabilité NF525 opérateur.
 *
 * Heal : stamper creator_id = Auth::id() et creator_type = User::class à la
 * création, depuis l'auth (jamais depuis le payload — non mass-assignable).
 */
class ItemCreatorStampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        foreach (['items', 'items_create', 'items_edit', 'items_show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    private function adminWithItemPerms(): User
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);

        return $admin;
    }

    private function makeCategory(): ItemCategory
    {
        return ItemCategory::create([
            'name'   => 'Cat ' . uniqid(),
            'slug'   => 'cat-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);
    }

    public function test_created_item_stamps_creator_from_authenticated_user(): void
    {
        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'name'             => 'Stamped Item ' . uniqid('', true),
            'price'            => 9.90,
            'is_featured'      => Ask::NO,
            'item_type'        => ItemType::VEG,
            'item_category_id' => $this->makeCategory()->id,
            'status'           => Status::ACTIVE,
            'order'            => 7,
        ];

        $response = $this->postJson('/api/admin/item', $payload)->assertCreated();

        $item = Item::query()->find((int) $response->json('data.id'));
        self::assertNotNull($item);

        // RED pre-heal: creator_id NULL.
        self::assertSame((int) $admin->id, (int) $item->creator_id);
        self::assertSame(User::class, $item->creator_type);
    }

    public function test_creator_is_not_overridable_from_request_payload(): void
    {
        // Security: the creator must come from auth, never from a spoofed payload.
        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $payload = [
            'name'             => 'NoSpoof Item ' . uniqid('', true),
            'price'            => 5.00,
            'is_featured'      => Ask::NO,
            'item_type'        => ItemType::VEG,
            'item_category_id' => $this->makeCategory()->id,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            // Spoof attempt — must be ignored.
            'creator_id'       => 999999,
            'creator_type'     => 'App\\Models\\Spoof',
        ];

        $response = $this->postJson('/api/admin/item', $payload)->assertCreated();

        $item = Item::query()->find((int) $response->json('data.id'));
        self::assertNotNull($item);
        self::assertSame((int) $admin->id, (int) $item->creator_id);
        self::assertSame(User::class, $item->creator_type);
    }
}
