<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\PaymentTerminal;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [GOAL-8AXES V6 T-3.2.1 2026-08-05] La CB caisse est un ENREGISTREMENT
 * comptable déclaratif (owner : « j'encaisse manuellement sur mon TPE, le
 * logiciel doit juste enregistrer que c'était en carte »).
 *
 * Cause prouvée (repro Vague 2) du « la CB n'est pas fonctionnelle » : la
 * validation exigeait les 4 derniers chiffres (pos_payment_note
 * required|min_digits:4) alors que le champ UI n'est pas câblé (pas de
 * v-model, PaymentComponent:144 frozen) et que canConfirmCard ne le vérifie
 * pas → 422 dont le seul feedback est un toast anglais fugace. Le chemin
 * multi-tender n'exigeait déjà PAS cette note (incohérence).
 *
 * Nouveau contrat : pos_payment_note OPTIONNELLE pour CARD ; si fournie,
 * toujours 4 chiffres exacts. terminal_id reste requis (attribution TPE au Z).
 * Montage : copié de PosSimulationHardware4ScenariosTest (S2).
 */
class PosCardDeclarativeNoNoteTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;

    protected User $customer;

    protected User $operator;

    protected Item $item;

    protected PaymentTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        $this->branch = Branch::factory()->create();
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030410',
        ]);
        $this->customer->assignRole('Customer');
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030411',
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%', 'code' => 'TVA0',
            'type' => TaxType::PERCENTAGE, 'tax_rate' => 0.00, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create([
            'name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false,
        ]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'name' => 'Coca-Cola 33cl', 'price' => 1.50, 'status' => Status::ACTIVE,
        ]);
        $this->terminal = PaymentTerminal::create([
            'branch_id' => $this->branch->id, 'name' => 'TPE Déclaratif',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent' => 0, 'fee_fixed' => 0,
            'status' => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    private function cardPayload(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CARD,
            'pos_received_amount' => 0,
            'terminal_id' => $this->terminal->id,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'item_price' => 1.50,
                'quantity' => 1,
                'total_price' => 1.50,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    private function postCard(array $payload)
    {
        $this->actingAs($this->operator, 'sanctum');

        return $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));
    }

    public function test_card_sale_without_note_is_accepted(): void
    {
        $response = $this->postCard($this->cardPayload());

        $response->assertStatus(201);
        $order = Order::withoutGlobalScopes()->find($response->json('data.id'));
        $this->assertSame(PosPaymentMethod::CARD, (int) $order->pos_payment_method);
        // L'attribution TPE→ligne de paiement est couverte par TerminalIdWireInTest.
    }

    public function test_card_note_when_provided_must_still_be_4_digits(): void
    {
        $this->postCard($this->cardPayload(['pos_payment_note' => 'abc']))->assertStatus(422);
    }

    public function test_card_without_terminal_still_rejected(): void
    {
        $payload = $this->cardPayload();
        unset($payload['terminal_id']);

        $this->postCard($payload)->assertStatus(422);
    }
}
