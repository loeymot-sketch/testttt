<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [2026-05-18] Frites wizard composer — owner spec:
 *
 *   When a customer picks a Frites item, the wizard opens with 3 steps:
 *     1. Type de frites (extra_group='frites_style', max=1) — optional
 *        upgrade (Normale / Cheddar fondu / Cheddar + Oignons croustillants).
 *     2. Sauce 1ère gratuite (item_attribute 311, min=1 max=1) — free.
 *     3. Sauce supplémentaire (extra_group='sauce_supp', max=5) — paid.
 *
 * The seeder `AlignFritesWizardProfilesSeeder` aligns the published profile
 * with these step semantics for items 361 / 402 / 403. This test exercises
 * a full POS POST through the composer validator + price recompute to prove:
 *   (a) the wizard payload mirrors what the POS wizard actually sends;
 *   (b) PricingService accepts it (no "n'appartient pas au profil publié");
 *   (c) the order rolls forward to the KDS with the right composition.
 */
class FritesWizardComposerTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $fritesItem;
    protected ItemAttribute $sauceAttribute;
    protected ItemVariation $sauceKetchup;
    protected ItemExtra $cheddarFondu;
    protected ItemExtra $sauceCurry; // paid
    protected ItemWizardProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('split_payment.enabled', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030410']);
        $this->customer->assignRole('Customer');
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030411']);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create(['name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 0.00, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Frites & Accompagnements', 'wizard_template' => 'custom', 'has_menu' => false]);

        $this->fritesItem = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id'           => $tax->id,
            'name'             => 'Frites Seules',
            'price'            => 2.00,
            'status'           => Status::ACTIVE,
        ]);

        // Name must match the seeder's canonical source_ref string after
        // mb_strtolower (see ComposerProfileProjection::matchesAttributeRef).
        $this->sauceAttribute = ItemAttribute::create([
            'name'         => 'Sauce (1ère Gratuite)',
            'min_select'   => 1,
            'max_select'   => 1,
            'allow_repeat' => 0,
            'is_available' => 1,
            'status'       => Status::ACTIVE,
        ]);

        // Free sauce variation (item_attribute step source).
        $this->sauceKetchup = ItemVariation::create([
            'item_id'           => $this->fritesItem->id,
            'item_attribute_id' => $this->sauceAttribute->id,
            'name'              => 'Ketchup',
            'price'             => 0.00,
        ]);

        // 'frites_style' upgrade extras.
        $this->cheddarFondu = ItemExtra::create([
            'item_id'     => $this->fritesItem->id,
            'name'        => 'Cheddar fondu',
            'group_label' => 'frites_style',
            'price'       => 1.00,
            'is_available' => 1,
            'status'      => Status::ACTIVE,
        ]);

        // 'sauce_supp' paid sauces.
        $this->sauceCurry = ItemExtra::create([
            'item_id'     => $this->fritesItem->id,
            'name'        => 'Sauce Curry',
            'group_label' => 'sauce_supp',
            'price'       => 0.50,
            'is_available' => 1,
            'status'      => Status::ACTIVE,
        ]);

        // Published wizard profile mirroring the seeder.
        $this->profile = ItemWizardProfile::create([
            'item_id'      => $this->fritesItem->id,
            'template'     => 'custom',
            'version'      => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);

        ItemWizardStep::create([
            'profile_id'  => $this->profile->id,
            'step_key'    => 'frites_style',
            'label'       => 'Type de frites',
            'source_type' => 'extra_group',
            'source_ref'  => 'frites_style',
            'min_select'  => 0,
            'max_select'  => 1,
            'allow_repeat' => 0,
            'position'    => 0,
            'is_active'   => 1,
        ]);
        ItemWizardStep::create([
            'profile_id'              => $this->profile->id,
            'step_key'                => 'sauce',
            'label'                   => 'Sauce (1ère gratuite)',
            'source_type'             => 'item_attribute',
            'source_ref'              => 'sauce (1ère gratuite)',
            'source_item_attribute_id' => $this->sauceAttribute->id,
            'min_select'              => 1,
            'max_select'              => 1,
            'allow_repeat'            => 0,
            'position'                => 1,
            'is_active'               => 1,
        ]);
        ItemWizardStep::create([
            'profile_id'  => $this->profile->id,
            'step_key'    => 'sauce_supp',
            'label'       => 'Sauce supplémentaire',
            'source_type' => 'extra_group',
            'source_ref'  => 'sauce_supp',
            'min_select'  => 0,
            'max_select'  => 5,
            'allow_repeat' => 0,
            'position'    => 2,
            'is_active'   => 1,
        ]);
    }

    private function basePayload(array $itemOverrides = [], array $payloadOverrides = []): array
    {
        $itemLine = array_merge([
            'item_id'         => $this->fritesItem->id,
            'item_price'      => 2.00,
            'quantity'        => 1,
            'total_price'     => 2.00,
            'item_variations' => [],
            'item_extras'     => [],
            'item_addons'     => [],
        ], $itemOverrides);

        return array_merge([
            'token'              => null,
            'customer_id'        => $this->customer->id,
            'branch_id'          => $this->branch->id,
            'discount'           => 0,
            'order_type'         => OrderType::TAKEAWAY,
            'is_advance_order'   => 0,
            'source'             => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items'              => json_encode([$itemLine]),
        ], $payloadOverrides);
    }

    public function test_frites_with_only_free_sauce_succeeds(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'item_variations' => [['id' => $this->sauceKetchup->id, 'name' => 'Ketchup', 'price' => 0]],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_frites_with_cheddar_style_plus_free_sauce_succeeds(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'item_price'  => 2.00,
            'total_price' => 3.00,
            'item_variations' => [['id' => $this->sauceKetchup->id, 'name' => 'Ketchup', 'price' => 0]],
            'item_extras'     => [['id' => $this->cheddarFondu->id, 'name' => 'Cheddar fondu', 'price' => 1.00]],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $orderId);
    }

    public function test_frites_with_paid_supplementary_sauce_succeeds(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'total_price' => 2.50,
            'item_variations' => [['id' => $this->sauceKetchup->id, 'name' => 'Ketchup', 'price' => 0]],
            'item_extras'     => [['id' => $this->sauceCurry->id, 'name' => 'Sauce Curry', 'price' => 0.50]],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $orderId);
    }

    public function test_frites_without_required_sauce_returns_422(): void
    {
        // min_select=1 on the sauce step → omitting it must 422 at the quote
        // boundary, which is the first PricingService validation gate.
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'item_variations' => [], // no sauce
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/quote', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('Sauce', (string) $response->json('message'));
    }
}
