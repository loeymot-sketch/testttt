<?php

namespace Tests\Feature\Http\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WIZARD-STUDIO W1 2026-06-14] HTTP contract for the read-only DRAFT preview-projection
 * endpoint that powers the visual Wizard Studio live preview.
 *
 * NF525/frozen invariants asserted: GET only, no price field anywhere in the response,
 * draft (is_published=false) is projected through the SAME ComposerProfileProjection as the
 * live kiosk and surfaced with is_published forced TRUE (so the frozen kiosk consumer gate
 * accepts it). Branch isolation + catalog.compose enforced.
 *
 * @see app/Http/Controllers/Admin/ComposerProfileController::previewProjection
 */
class ComposerPreviewProjectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /** @return array{0:\App\Models\User,1:Branch,2:Item} */
    private function bootstrap(): array
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');

        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE, 'name' => 'Bols', 'channels' => ['pos', 'kiosk']]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'name' => 'Bowl Test',
            'channels' => ['pos', 'kiosk'],
        ]);

        return [$admin, $branch, $item];
    }

    private function makeDraftProfile(Item $item, ?int $branchScope = null): ItemWizardProfile
    {
        $profile = ItemWizardProfile::create([
            'item_id' => $item->id,
            'template' => 'custom',
            'is_published' => false, // DRAFT
            'version' => 1,
            'branch_id_scope' => $branchScope,
        ]);
        ItemWizardStep::create([
            'profile_id' => $profile->id,
            'step_key' => 'sauce',
            'label' => 'Quelle sauce ?',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 1,
            'max_select' => 2,
            'allow_repeat' => false,
            'position' => 0,
            'is_active' => true,
        ]);

        return $profile;
    }

    private function url(ItemWizardProfile $profile): string
    {
        return "/api/admin/composer/profiles/{$profile->id}/preview-projection";
    }

    public function test_requires_authentication(): void
    {
        [, , $item] = $this->bootstrap();
        $profile = $this->makeDraftProfile($item);

        $this->withHeader('x-api-key', $this->apiKey())
            ->getJson($this->url($profile))
            ->assertStatus(401);
    }

    public function test_requires_catalog_compose_permission(): void
    {
        [, $branch, $item] = $this->bootstrap();
        $profile = $this->makeDraftProfile($item);

        $noPerm = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        // No role / no catalog.compose permission.

        $this->actingAs($noPerm, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson($this->url($profile))
            ->assertStatus(403);
    }

    public function test_projects_draft_with_is_published_forced_true(): void
    {
        [$admin, , $item] = $this->bootstrap();
        $profile = $this->makeDraftProfile($item);
        $this->assertFalse((bool) $profile->fresh()->is_published, 'precondition: profile is a draft');

        $res = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson($this->url($profile))
            ->assertOk();

        $res->assertJsonPath('data.item.id', $item->id);
        // Sole deviation from the live shape: the draft is surfaced as published so the
        // frozen kiosk consumer gate (is_published === false -> null) accepts it.
        $res->assertJsonPath('data.item.composer_profile.is_published', true);
        $this->assertNotEmpty($res->json('data.item.composer_profile.steps'));
    }

    public function test_response_contains_no_price_field_anywhere_nf525(): void
    {
        [$admin, , $item] = $this->bootstrap();
        $profile = $this->makeDraftProfile($item);

        $body = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson($this->url($profile))
            ->assertOk()
            ->json('data.item.composer_profile');

        $encoded = json_encode($body);
        // No price/amount/unit_price key may appear anywhere under the projected profile/steps.
        $this->assertDoesNotMatchRegularExpression('/"(price|amount|unit_price)"\s*:/', $encoded);
    }

    public function test_branch_user_cannot_preview_foreign_branch_draft(): void
    {
        [, , $item] = $this->bootstrap();
        $foreign = Branch::factory()->create();
        $profile = $this->makeDraftProfile($item, $foreign->id);

        $otherBranch = Branch::factory()->create();
        $branchUser = \Database\Factories\UserFactory::new()->create(['branch_id' => $otherBranch->id]);
        $branchUser->assignRole('Branch Manager');

        $this->actingAs($branchUser, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson($this->url($profile))
            ->assertStatus(403);
    }
}
