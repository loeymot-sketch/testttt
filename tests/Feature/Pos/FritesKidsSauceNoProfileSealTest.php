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
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [OWNER 2026-07-28 · RÉGRESSION 422 "Mechanism A"] Verrou du money-path multi-sauce des frites/menus
 * enfants tels qu'ils sont RÉELLEMENT servis : PAS de profil composer publié + étape sauce via template
 * catégorie ('snacking'/'sandwich') + facturation de la 2ᵉ sauce par l'ItemExtra générique « Sauce
 * supplémentaire » (group_label='sauce'), exactement ce que la caisse (renderSinglePage) et la borne
 * (KioskStepSauce) envoient.
 *
 * Pourquoi ce test existe : deux agents adversaires ont prouvé qu'un profil composer PUBLIÉ (l'ancienne
 * approche) faisait 422 dès la 2ᵉ sauce — `assertComposerSelectionsBelongToPublishedProfile` rejetait
 * l'extra générique non projeté. SANS profil, le contrôle est skippé → 2+ sauces scellent au centime.
 * Ce test échoue si quiconque re-publie un profil sur ces items (la régression reviendrait).
 */
class FritesKidsSauceNoProfileSealTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $fritesItem;
    protected ItemAttribute $sauceAttribute;
    protected ItemVariation $sauceA;
    protected ItemVariation $sauceB;
    protected ItemExtra $sauceSupplement; // générique @0,50, group_label='sauce'

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('split_payment.enabled', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030420']);
        $this->customer->assignRole('Customer');
        $this->operator = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('password'), 'phone' => '0102030421']);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create(['name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 0.00, 'status' => Status::ACTIVE]);

        // Catégorie frites en 'snacking' (mécanisme de la commande) — has_menu false, PAS de profil.
        $cat = ItemCategory::factory()->create([
            'name' => 'Frites', 'wizard_template' => 'snacking', 'has_menu' => false,
        ]);
        $this->fritesItem = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'name' => 'Petite Frites', 'price' => 2.00, 'status' => Status::ACTIVE,
        ]);

        $this->sauceAttribute = ItemAttribute::create([
            'name' => 'Sauce (1ère Gratuite)', 'min_select' => 1, 'max_select' => 1,
            'allow_repeat' => 0, 'is_available' => 1, 'status' => Status::ACTIVE,
        ]);
        $this->sauceA = ItemVariation::create([
            'item_id' => $this->fritesItem->id, 'item_attribute_id' => $this->sauceAttribute->id,
            'name' => 'Ketchup', 'price' => 0.00,
        ]);
        $this->sauceB = ItemVariation::create([
            'item_id' => $this->fritesItem->id, 'item_attribute_id' => $this->sauceAttribute->id,
            'name' => 'Mayonnaise', 'price' => 0.00,
        ]);

        // Véhicule de facturation de la 2ᵉ sauce — l'extra GÉNÉRIQUE group_label='sauce' (posé par
        // EnsureSauceSupplementExtrasCommand). C'est CELUI-CI que les deux surfaces envoient.
        $this->sauceSupplement = ItemExtra::create([
            'item_id' => $this->fritesItem->id, 'name' => 'Sauce supplémentaire',
            'group_label' => 'sauce', 'price' => 0.50, 'is_available' => 1, 'status' => Status::ACTIVE,
        ]);
    }

    private function basePayload(array $itemOverrides = []): array
    {
        $itemLine = array_merge([
            'item_id' => $this->fritesItem->id,
            'item_price' => 2.00, 'quantity' => 1, 'total_price' => 2.00,
            'item_variations' => [], 'item_extras' => [], 'item_addons' => [],
        ], $itemOverrides);

        return [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items' => json_encode([$itemLine]),
        ];
    }

    public function test_frites_one_sauce_seals(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'item_variations' => [['id' => $this->sauceA->id, 'name' => 'Ketchup', 'price' => 0]],
        ]);
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
    }

    public function test_frites_TWO_sauces_seals_without_422_and_bills_the_extra(): void
    {
        // LE cœur du verrou — payload EXACT du wizard pour 2 sauces (caisse pos-wizard.js:3969+4020,
        // borne KioskWizard) : la 1ère sauce = 1 VARIATION (Ketchup), la 2ᵉ = 1× l'extra GÉNÉRIQUE
        // « Sauce supplémentaire » @0,50 (PAS une 2ᵉ variation — l'attribut sauce est max_select=1).
        // Ancienne approche (profil publié) → 422 sur cet extra non projeté. Sans profil → 201, +0,50.
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'total_price' => 2.50,
            'item_variations' => [['id' => $this->sauceA->id, 'name' => 'Ketchup', 'price' => 0]],
            'item_extras' => [['id' => $this->sauceSupplement->id, 'name' => 'Sauce supplémentaire', 'price' => 0.50]],
        ]);
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $orderId);

        // Money-path au centime : base 2,00 + 2ᵉ sauce 0,50 = 2,50.
        $order = \App\Models\Order::find($orderId);
        $this->assertEqualsWithDelta(2.50, (float) $order->total, 0.01, '2,00 base + 0,50 (2ᵉ sauce) = 2,50');
    }
}
