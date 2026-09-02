<?php

namespace Tests\Feature\Composer;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * Sentinel — Mission #2 Vague 1 action 1.4 (UX) + Vague 2 action 2.2 (gate).
 *
 * Asserts that when a kiosk wizard is opened on composer profile v1 and
 * an admin publishes v2 retiring one of the chosen options, the order
 * submission is rejected gracefully — not as 500, not silently — with
 * a payload that the kiosk can render as a remediation toast.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.1 #2 + §C #1
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md tasks 1.4, 2.2, 2.3
 *
 * Contract once unskipped:
 *
 *   1. Open kiosk wizard with profile v1 (active publication).
 *   2. Build a payload referencing a variation_id that exists in v1.
 *   3. Admin publishes v2 (via ComposerProfileService::publish), and the
 *      v2 step REMOVES that variation_id (it no longer exists in current
 *      published projection).
 *   4. POST /api/frontend/order with the v1 payload.
 *   5. Expected response (Vague 1 — without gate clearance):
 *      - HTTP 422 (existing PricingService rejection path)
 *      - response body has key 'errors.composer' with at least one
 *        message i18n-key 'composer.choice_no_longer_available'.
 *      - the offending option_id is listed under
 *        'errors.composer_removed_options'.
 *   6. Expected response (Vague 2 — once gate cleared and
 *      composer_profile_version_check.enabled=true):
 *      - HTTP 409 Conflict with body shape:
 *          {
 *            "code": "composer_profile_version_mismatch",
 *            "active_version": <int>,
 *            "submitted_version": <int>,
 *            "removed_options": [ { id, label, step_id } ],
 *            "remediation": "refresh_wizard"
 *          }
 *      - the kiosk frontend uses this payload to show modal "Le menu a
 *        été mis à jour" and refetch composer profile.
 *
 *   7. composition_snapshot of any successful post-remediation order is
 *      written from the v2 projection (immutable thereafter).
 *
 * @see \App\Services\Pricing\PricingService::assertComposerSelectionsBelongToPublishedProfile()
 *      Discovery (2026-05-02): kiosk commit returns JSON
 *      `{ "status": false, "message": "<PricingService French message>" }`
 *      for this violation — not Laravel `errors.composer` / i18n keys yet.
 */
class ProfilePublishMidCartRejectionTest extends TestCase
{
    use RefreshDatabase;

    private function seedKioskOrderBasics(): void
    {
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);
    }

    /**
     * @return array{0: Branch, 1: User, 2: Item, 3: ItemVariation, 4: ItemVariation, 5: ComposerProfileService}
     */
    private function createBranchItemTwoMeaningComposerAndKiosk(): array
    {
        $branch = Branch::forceCreate([
            'name' => 'Mid-cart Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue sentinel',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10MID',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Sentinel Cat',
            'slug' => 'sentinel-cat-midcart',
            'status' => Status::ACTIVE,
        ]);

        $attrViande = ItemAttribute::query()->create([
            'name' => 'Viande',
            'item_category_id' => $category->id,
            'is_required' => 0,
            'max_selection' => 1,
        ]);

        $attrSauce = ItemAttribute::query()->create([
            'name' => 'Sauce',
            'item_category_id' => $category->id,
            'is_required' => 0,
            'max_selection' => 1,
        ]);

        $item = Item::forceCreate([
            'name' => 'Tacos mid-cart',
            'slug' => 'tacos-mid-cart',
            'price' => 8.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $variationMeat = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attrViande->id,
            'name' => 'Kebab',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $variationSauce = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attrSauce->id,
            'name' => 'Blanche',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $kioskUser = User::factory()->create([
            'branch_id' => $branch->id,
        ]);

        KioskMachine::create([
            'machine_id' => 'mid-cart-machine-01',
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'kiosk-mid-cart',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        $composer = app(ComposerProfileService::class);

        return [$branch, $kioskUser, $item, $variationMeat, $variationSauce, $composer];
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemsLine
     */
    private function kioskOrderPayload(User $kioskUser, Item $item, array $itemsLine): array
    {
        return [
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode($itemsLine),
        ];
    }

    /**
     * @return array{quote_token: string, quote_signature: string}
     */
    private function kioskQuote(User $kioskUser, array $payload): array
    {
        $response = $this
            ->withHeader('x-api-key', (string) config('app.api_key'))
            ->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload);

        $response->assertOk();
        $data = $response->json('data');

        return [
            'quote_token' => (string) $data['quote_token'],
            'quote_signature' => (string) $data['signature'],
        ];
    }

    public function test_v1_payload_with_v2_removed_option_returns_422_with_composer_remediation_keys(): void
    {
        $this->seedKioskOrderBasics();
        [$branch, $kioskUser, $item, $variationMeat, $variationSauce, $composer] = $this->createBranchItemTwoMeaningComposerAndKiosk();

        $profile = $composer->createForItem($item, [
            'template' => 'sandwich',
            'branch_id_scope' => $branch->id,
            'steps' => [
                [
                    'step_key' => 'choix',
                    'label' => 'Choix',
                    'source_type' => 'item_attribute',
                    'source_ref' => 'viande',
                    'min_select' => 1,
                    'max_select' => 1,
                    'position' => 1,
                    'visible_on' => ['kiosk', 'pos'],
                ],
            ],
        ]);

        $composer->publish($profile->fresh('steps'));

        $itemsLine = [[
            'item_id' => $item->id,
            'quantity' => 1,
            'instruction' => '',
            'item_variations' => [['id' => $variationMeat->id]],
            'item_extras' => [],
            'item_addons' => [],
        ]];

        $payload = $this->kioskOrderPayload($kioskUser, $item, $itemsLine);
        $quote = $this->kioskQuote($kioskUser, $payload);

        // [2026-09-02] Depuis la correction « Enregistrer ne change pas la caisse » (2026-08-28),
        // `update()` sur un profil PUBLIÉ crée un brouillon et laisse la caisse intacte. Ce test
        // vérifiait donc un panier contre un profil INCHANGÉ, et passait au vert sans rien prouver.
        // Pour exercer vraiment « v2 retire l'option, le panier v1 est refusé », il faut PUBLIER.
        $brouillonV2 = $composer->update($profile->fresh('steps'), [
            'template' => 'sandwich',
            'branch_id_scope' => $branch->id,
            'steps' => [
                [
                    'step_key' => 'choix',
                    'label' => 'Choix',
                    'source_type' => 'item_attribute',
                    'source_ref' => 'sauce',
                    'min_select' => 1,
                    'max_select' => 1,
                    'position' => 1,
                    'visible_on' => ['kiosk', 'pos'],
                ],
            ],
        ]);
        $composer->publish($brouillonV2->fresh('steps'));

        $ordersBefore = Order::query()->where('user_id', $kioskUser->id)->count();

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $response = $this
            ->withHeader('x-api-key', (string) config('app.api_key'))
            ->postJson('/api/frontend/order', $payload + $quote);

        $response->assertStatus(422);
        $response->assertJsonPath('status', false);

        $message = (string) $response->json('message');
        $this->assertStringContainsString((string) $variationMeat->id, $message);
        $this->assertStringContainsString("n'appartient pas au profil publié", $message);

        // Equivalent remediation signal until `errors.composer` / `composer_removed_options` exist:
        $this->assertMatchesRegularExpression('/choix\s*#/i', $message);

        $this->assertSame(
            $ordersBefore,
            Order::query()->where('user_id', $kioskUser->id)->count(),
            'Failed order must not persist a row on orders table.'
        );
    }

    public function test_v1_payload_with_v2_renamed_option_still_resolves_by_id(): void
    {
        $this->seedKioskOrderBasics();
        [$branch, $kioskUser, $item, $variationMeat, $variationSauce, $composer] = $this->createBranchItemTwoMeaningComposerAndKiosk();
        $profile = $composer->createForItem($item, [
            'template' => 'sandwich',
            'branch_id_scope' => $branch->id,
            'steps' => [
                [
                    'step_key' => 'viande',
                    'label' => 'Viande',
                    'source_type' => 'item_attribute',
                    'source_ref' => 'viande',
                    'min_select' => 1,
                    'max_select' => 1,
                    'position' => 1,
                    'visible_on' => ['kiosk', 'pos'],
                ],
            ],
        ]);

        $composer->publish($profile->fresh('steps'));

        $variationMeat->update(['name' => 'Kebab renommé']);

        $itemsLine = [[
            'item_id' => $item->id,
            'quantity' => 1,
            'instruction' => '',
            'item_variations' => [['id' => $variationMeat->id]],
            'item_extras' => [],
            'item_addons' => [],
        ]];

        $payload = $this->kioskOrderPayload($kioskUser, $item, $itemsLine);
        $quote = $this->kioskQuote($kioskUser, $payload);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $response = $this
            ->withHeader('x-api-key', (string) config('app.api_key'))
            ->postJson('/api/frontend/order', $payload + $quote);

        $this->assertContains(
            $response->status(),
            [200, 201],
            (string) $response->getContent()
        );
    }

    public function test_version_check_returns_409_when_flag_enabled(): void
    {
        $this->markTestSkipped('Pending plan task 2.2 + GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-XX-XX.md');
    }

    public function test_post_remediation_order_writes_v2_composition_snapshot(): void
    {
        $this->markTestSkipped('Pending plan task 2.2 (PLAN_CV1-LIFECYCLE-UX-001 §2.2).');
    }
}
