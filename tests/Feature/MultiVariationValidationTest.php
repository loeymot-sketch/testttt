<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use App\Rules\MultiVariationConstraint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * V14 T06 — Form Request + MultiVariationConstraint (min/max/allow_repeat).
 */
class MultiVariationValidationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $kioskUser;

    private string $kioskToken;

    private Item $item;

    private Tax $tax;

    private ItemCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $this->category = ItemCategory::factory()->create([
            'name' => 'Tacos MV',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $this->category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Item MV',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'channels' => ['kiosk'],
        ]);

        $this->kioskUser = User::factory()->create([
            'username' => 'kiosk_mv_'.uniqid(),
            'branch_id' => $this->branch->id,
        ]);
        KioskMachine::create([
            'machine_id' => 'mv-'.uniqid(),
            'branch_id' => $this->branch->id,
            'user_id' => $this->kioskUser->id,
            'username' => 'kiosk-mv',
            'password' => bcrypt('x'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
        $this->kioskToken = $this->kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;
    }

    private function authedKiosk(): self
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$this->kioskToken}",
            'x-localization' => 'fr',
        ]);
    }

    /** @return list<string> */
    private function variationErrorMessages(TestResponse $response): array
    {
        $errors = $response->json('errors');
        $key = 'items.0.item_variations';
        if (! is_array($errors) || ! array_key_exists($key, $errors)) {
            return [];
        }
        $bag = $errors[$key];

        return is_array($bag) ? $bag : [$bag];
    }

    private function makeAttribute(string $name, int $minSelect, int $maxSelect, bool $allowRepeat): ItemAttribute
    {
        return ItemAttribute::query()->create([
            'name' => $name.' '.uniqid(),
            'status' => Status::ACTIVE,
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'allow_repeat' => $allowRepeat,
        ]);
    }

    private function makeVariation(Item $item, ItemAttribute $attr, string $name, float $price): ItemVariation
    {
        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attr->id,
            'name' => $name,
            'price' => $price,
            'new_price' => $price,
            'status' => Status::ACTIVE,
        ]);
    }

    public function test_preview_accepts_legacy_single_select_without_quantity(): void
    {
        $attr = $this->makeAttribute('Sauce', 0, 2, false);
        $v = $this->makeVariation($this->item, $attr, 'Algérienne', 1.50);

        $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [['id' => $v->id]],
            ]],
        ])->assertStatus(200);
    }

    public function test_preview_accepts_multi_qty_when_allow_repeat_and_within_max(): void
    {
        $attr = $this->makeAttribute('Viande', 1, 4, true);
        $v1 = $this->makeVariation($this->item, $attr, 'Poulet', 1.00);
        $v2 = $this->makeVariation($this->item, $attr, 'Bœuf', 1.00);

        $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [
                    ['id' => $v1->id, 'quantity' => 3],
                    ['id' => $v2->id, 'quantity' => 1],
                ],
            ]],
        ])->assertStatus(200);
    }

    public function test_preview_rejects_total_below_min_select_with_structured_errors(): void
    {
        $attr = $this->makeAttribute('Garniture', 2, 4, true);
        $attrName = $attr->name;
        $v = $this->makeVariation($this->item, $attr, 'Salade', 0.50);

        $r = $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [['id' => $v->id, 'quantity' => 1]],
            ]],
        ]);

        $r->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.item_variations']);

        $messages = $this->variationErrorMessages($r);
        $this->assertNotEmpty($messages);
        $msg = $messages[0];
        $this->assertStringContainsString('2', $msg);
        $this->assertStringContainsString('1', $msg);
        $this->assertStringContainsString($attrName, $msg);
        $this->assertStringContainsString('Sélectionnez au moins', $msg);
    }

    public function test_preview_rejects_total_above_max_select(): void
    {
        $attr = $this->makeAttribute('Sauce', 0, 2, true);
        $v1 = $this->makeVariation($this->item, $attr, 'Blanche', 0.50);
        $v2 = $this->makeVariation($this->item, $attr, 'Harissa', 0.50);

        $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [
                    ['id' => $v1->id, 'quantity' => 2],
                    ['id' => $v2->id, 'quantity' => 1],
                ],
            ]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.item_variations']);
    }

    public function test_preview_rejects_repeat_when_not_allowed(): void
    {
        $attr = $this->makeAttribute('Taille', 0, 2, false);
        $v = $this->makeVariation($this->item, $attr, 'XL', 1.00);

        $r = $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [['id' => $v->id, 'quantity' => 2]],
            ]],
        ]);

        $r->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.item_variations']);

        $msg = implode(' ', $this->variationErrorMessages($r));
        $this->assertStringContainsString('répétition', $msg);
    }

    public function test_kiosk_commit_rejects_repeat_when_not_allowed_before_quote_replay(): void
    {
        $attr = $this->makeAttribute('Taille', 0, 2, false);
        $v = $this->makeVariation($this->item, $attr, 'XL', 1.00);

        $this->authedKiosk()->postJson('/api/frontend/order', [
            'branch_id' => $this->branch->id,
            'order_type' => \App\Enums\OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => \App\Enums\Source::WEB,
            'payment_method' => \App\Enums\PaymentGateway::CASH_ON_DELIVERY,
            'quote_token' => '00000000-0000-4000-8000-000000000123',
            'quote_signature' => str_repeat('a', 64),
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [['id' => $v->id, 'quantity' => 2]],
            ]]),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.item_variations']);
    }

    public function test_preview_accepts_valid_multi_attribute_mix(): void
    {
        $sauce = $this->makeAttribute('Sauce', 0, 1, false);
        $viande = $this->makeAttribute('Viande', 1, 3, true);
        $sv = $this->makeVariation($this->item, $sauce, 'Mayo', 0.50);
        $v1 = $this->makeVariation($this->item, $viande, 'Agneau', 1.00);
        $v2 = $this->makeVariation($this->item, $viande, 'Poulet', 1.00);

        $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [
                    ['id' => $sv->id],
                    ['id' => $v1->id, 'quantity' => 2],
                    ['id' => $v2->id],
                ],
            ]],
        ])->assertStatus(200);
    }

    public function test_preview_accepts_missing_or_empty_item_variations(): void
    {
        $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
            ]],
        ])->assertStatus(200);

        $this->authedKiosk()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
            ]],
        ])->assertStatus(200);
    }

    public function test_rule_ignores_unknown_variation_id_for_constraint(): void
    {
        $failures = [];
        MultiVariationConstraint::validateCollectionKeyedByItemIndex(
            [['item_variations' => [['id' => 999_999_999, 'quantity' => 99]]]],
            function (int $index, string $message) use (&$failures): void {
                $failures[] = "{$index}:{$message}";
            }
        );

        $this->assertSame([], $failures);
    }
}
