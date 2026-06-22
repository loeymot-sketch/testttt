<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Http\Resources\ItemAttributeResource;
use App\Http\Resources\ItemResource;
use App\Http\Resources\NormalItemResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ItemAttributeComposerResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_attribute_resource_exposes_composer_constraints(): void
    {
        $attribute = ItemAttribute::factory()->create([
            'min_select' => 2,
            'max_select' => 4,
            'allow_repeat' => true,
        ]);

        $payload = (new ItemAttributeResource($attribute))->toArray(Request::create('/'));

        $this->assertSame(2, $payload['min_select']);
        $this->assertSame(4, $payload['max_select']);
        $this->assertTrue($payload['allow_repeat']);
    }

    public function test_catalog_resources_keep_composer_constraints_for_pos_and_kiosk(): void
    {
        $item = Item::factory()->create();
        $attribute = ItemAttribute::factory()->create([
            'name' => 'Viande',
            'min_select' => 1,
            'max_select' => 3,
            'allow_repeat' => true,
            'status' => Status::ACTIVE,
        ]);

        ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Steak',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $item = $item->fresh()->load([
            'category',
            'tax',
            'variations.itemAttribute',
            'extras',
            'addons.addonItem',
            'offer',
            'allergens',
        ]);

        $adminPayload = (new ItemResource($item))->toArray(Request::create('/admin/items'));
        $kioskPayload = (new NormalItemResource($item))->toArray(
            Request::create('/api/frontend/items', 'GET', ['surface' => 'kiosk'])
        );

        $this->assertComposerConstraintPayload($adminPayload['itemAttributes']->resolve(Request::create('/admin/items')));
        $this->assertComposerConstraintPayload($kioskPayload['itemAttributes']->resolve(Request::create('/api/frontend/items')));
    }

    public function test_item_resources_prefer_branch_scoped_composer_profile_over_global_profile(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $item = Item::factory()->create();

        $globalProfile = $this->publishedProfile($item, null, 'global_profile');
        $branchProfile = $this->publishedProfile($item, $branchA->id, 'branch_profile');
        $branchAUser = User::factory()->create(['branch_id' => $branchA->id]);
        $branchBUser = User::factory()->create(['branch_id' => $branchB->id]);

        $item = $item->fresh()->load([
            'category',
            'tax',
            'variations.itemAttribute',
            'extras',
            'addons.addonItem',
            'offer',
            'allergens',
        ]);

        $adminBranchPayload = (new ItemResource($item))->toArray($this->requestWithUser(
            '/admin/items',
            ['surface' => 'pos', 'branch_id' => $branchA->id],
            $branchAUser
        ));
        $kioskBranchPayload = (new NormalItemResource($item))->toArray($this->requestWithUser(
            '/api/frontend/items',
            ['surface' => 'kiosk', 'branch_id' => $branchA->id],
            $branchAUser
        ));
        $otherBranchPayload = (new NormalItemResource($item))->toArray($this->requestWithUser(
            '/api/frontend/items',
            ['surface' => 'kiosk', 'branch_id' => $branchA->id],
            $branchBUser
        ));

        $this->assertSame((int) $branchProfile->id, $adminBranchPayload['composer_profile']['id']);
        $this->assertSame((int) $branchA->id, $adminBranchPayload['composer_profile']['branch_id_scope']);
        $this->assertSame((int) $branchProfile->id, $kioskBranchPayload['composer_profile']['id']);
        $this->assertSame((int) $globalProfile->id, $otherBranchPayload['composer_profile']['id']);
        $this->assertNull($otherBranchPayload['composer_profile']['branch_id_scope']);
    }

    public function test_public_item_resource_ignores_client_forged_branch_id_for_composer_profile(): void
    {
        $branchA = Branch::factory()->create();
        $item = Item::factory()->create();
        $globalProfile = $this->publishedProfile($item, null, 'global_profile');
        $this->publishedProfile($item, $branchA->id, 'branch_profile');

        $item = $item->fresh()->load([
            'category',
            'tax',
            'variations.itemAttribute',
            'extras',
            'addons.addonItem',
            'offer',
            'allergens',
        ]);

        $payload = (new NormalItemResource($item))->toArray(
            Request::create('/api/frontend/items', 'GET', ['surface' => 'kiosk', 'branch_id' => $branchA->id])
        );

        $this->assertSame((int) $globalProfile->id, $payload['composer_profile']['id']);
        $this->assertNull($payload['composer_profile']['branch_id_scope']);
    }

    public function test_item_resources_project_composer_choices_for_generic_wizard_steps(): void
    {
        $branch = Branch::factory()->create();
        $branchUser = User::factory()->create(['branch_id' => $branch->id]);
        $item = Item::factory()->create();
        $attribute = ItemAttribute::factory()->create([
            'name' => 'Accompagnement',
            'status' => Status::ACTIVE,
        ]);
        $variation = ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Riz',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
        ]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 1.50,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
            'group_label' => 'Options',
        ]);

        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'branch_id_scope' => null,
            'is_published' => true,
            'published_at' => now(),
            'version' => 1,
        ]);
        $profile->steps()->create([
            'step_key' => 'accompagnement',
            'label' => 'Accompagnement',
            'source_type' => 'item_attribute',
            'source_ref' => (string) $attribute->id,
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => true,
            'position' => 0,
            'is_active' => true,
        ]);
        $profile->steps()->create([
            'step_key' => 'options',
            'label' => 'Options',
            'source_type' => 'extra_group',
            'source_ref' => 'Options',
            'min_select' => 0,
            'max_select' => 3,
            'allow_repeat' => true,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => true,
            'position' => 1,
            'is_active' => true,
        ]);
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        $item = $item->fresh()->load([
            'category',
            'tax',
            'variations.itemAttribute',
            'extras',
            'addons.addonItem',
            'offer',
            'allergens',
        ]);

        $adminPayload = (new ItemResource($item))->toArray(
            $this->requestWithUser('/admin/items', ['surface' => 'pos', 'branch_id' => $branch->id], $branchUser)
        );
        $kioskPayload = (new NormalItemResource($item))->toArray(
            $this->requestWithUser('/api/frontend/items', ['surface' => 'kiosk', 'branch_id' => $branch->id], $branchUser)
        );

        foreach ([$adminPayload, $kioskPayload] as $payload) {
            $steps = collect($payload['composer_profile']['steps']);
            $attributeStep = $steps->firstWhere('step_key', 'accompagnement');
            $extraStep = $steps->firstWhere('step_key', 'options');

            $this->assertSame([(int) $variation->id], collect($attributeStep['choices'])->pluck('id')->all());
            $this->assertSame(['variation'], collect($attributeStep['choices'])->pluck('source_type')->all());
            $this->assertSame([(int) $extra->id], collect($extraStep['choices'])->pluck('id')->all());
            $this->assertSame(['extra'], collect($extraStep['choices'])->pluck('source_type')->all());
            $this->assertFalse($attributeStep['choices'][0]['is_available']);
            $this->assertSame('stock_rupture', $attributeStep['choices'][0]['unavailable_reason']);
            $this->assertFalse($extraStep['choices'][0]['is_available']);
            $this->assertSame('stock_rupture', $extraStep['choices'][0]['unavailable_reason']);
            $this->assertArrayNotHasKey('price', $attributeStep['choices'][0]);
            $this->assertArrayNotHasKey('price', $extraStep['choices'][0]);
            $this->assertFalse((bool) collect($payload['variations'])->flatten(1)->firstWhere('id', $variation->id)['is_available']);
            $this->assertFalse((bool) collect($payload['extras'])->firstWhere('id', $extra->id)['is_available']);
        }
    }

    private function assertComposerConstraintPayload(array $attributes): void
    {
        $this->assertCount(1, $attributes);
        $this->assertSame('Viande', $attributes[0]['name']);
        $this->assertSame(1, $attributes[0]['min_select']);
        $this->assertSame(3, $attributes[0]['max_select']);
        $this->assertTrue($attributes[0]['allow_repeat']);
    }

    private function publishedProfile(Item $item, ?int $branchId, string $stepKey): ItemWizardProfile
    {
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'branch_id_scope' => $branchId,
            'is_published' => true,
            'published_at' => now(),
            'version' => 1,
        ]);

        $profile->steps()->create([
            'step_key' => $stepKey,
            'label' => $stepKey,
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 0,
            'max_select' => 1,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => false,
            'position' => 0,
            'is_active' => true,
        ]);

        return $profile;
    }

    private function requestWithUser(string $uri, array $query, User $user): Request
    {
        $request = Request::create($uri, 'GET', $query);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
