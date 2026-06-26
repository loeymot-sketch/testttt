<?php

namespace Tests\Feature\Pos;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [HEAL e2e all-systems 2026-06-26 / caisse r1] The QUOTE endpoints
 * (POST /api/admin/pos/quote AND POST /api/frontend/order/quote) must reject a
 * REQUIRED variation attribute (min_select>=1) that is wholly OMITTED, exactly
 * as the STORE FormRequest does (MultiVariationConstraint heal 2026-06-24).
 *
 * Before this heal the quote went through OrderQuoteService -> PricingService
 * which never invoked the presence-required rule, so the preview accepted a
 * tacos with no meat/no sauce that the store then rejected in 422 => quote≠store,
 * the preview lied. This locks quote/store parity on both surfaces.
 */
class PosQuoteVariationConstraintTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    private Branch $branch;

    private Item $item;

    private ItemAttribute $viande;

    private ItemAttribute $sauce;

    private ItemVariation $poulet;

    private ItemVariation $mayo;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        // Tacos M analogue: two REQUIRED attributes (Viande + Sauce, min_select=1).
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'name' => 'Tacos M',
            'price' => 6.90,
            'status' => Status::ACTIVE,
            'channels' => ['kiosk', 'pos'],
        ]);

        $this->viande = ItemAttribute::query()->create([
            'name' => 'Viande 1 '.uniqid(),
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => false,
        ]);
        $this->sauce = ItemAttribute::query()->create([
            'name' => 'Sauce '.uniqid(),
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => false,
        ]);

        $this->poulet = ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $this->viande->id,
            'name' => 'Poulet',
            'price' => 0.0,
            'new_price' => 0.0,
            'status' => Status::ACTIVE,
        ]);
        $this->mayo = ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $this->sauce->id,
            'name' => 'Mayo',
            'price' => 0.0,
            'new_price' => 0.0,
            'status' => Status::ACTIVE,
        ]);
    }

    private function posOperator(): User
    {
        $operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($operator, $this->branch);

        return $operator;
    }

    /** @return array{0: User, 1: string} kiosk user + bearer token */
    private function kioskActor(): array
    {
        $kioskUser = User::factory()->create([
            'username' => 'kiosk_q_'.uniqid(),
            'branch_id' => $this->branch->id,
        ]);
        KioskMachine::create([
            'machine_id' => 'q-'.uniqid(),
            'branch_id' => $this->branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'kiosk-q',
            'password' => bcrypt('x'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        return [$kioskUser, $token];
    }

    /** @param array<int, array<string, mixed>> $variations */
    private function posQuotePayload(array $variations): array
    {
        return [
            'branch_id' => $this->branch->id,
            'coupon_id' => 0,
            'discount' => 0,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => $variations,
                'item_extras' => [],
            ]]),
        ];
    }

    public function test_pos_quote_rejects_required_attributes_wholly_omitted(): void
    {
        $operator = $this->posOperator();

        $response = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-localization', 'fr')
            ->postJson('/api/admin/pos/quote', $this->posQuotePayload([]));

        $response->assertStatus(422);
        $msg = json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Sélectionnez au moins', (string) $msg);
        $this->assertStringContainsString($this->viande->name, (string) $msg);
    }

    public function test_pos_quote_accepts_required_attributes_present(): void
    {
        $operator = $this->posOperator();

        $response = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-localization', 'fr')
            ->postJson('/api/admin/pos/quote', $this->posQuotePayload([
                ['id' => $this->poulet->id],
                ['id' => $this->mayo->id],
            ]));

        $response->assertOk();
        $this->assertSame(6.90, (float) $response->json('data.total_ttc'));
    }

    public function test_kiosk_quote_rejects_required_attributes_wholly_omitted(): void
    {
        [$kioskUser, $token] = $this->kioskActor();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'x-localization' => 'fr',
        ])->postJson('/api/frontend/order/quote', [
            'branch_id' => $this->branch->id,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]);

        $response->assertStatus(422);
        $msg = json_encode($response->json('errors'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Sélectionnez au moins', (string) $msg);
    }
}
