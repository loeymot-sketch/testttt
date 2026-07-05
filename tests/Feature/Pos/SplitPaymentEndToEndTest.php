<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\Tax;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * F-SPLIT-PAYMENT-001 — End-to-end POST /api/admin/pos avec
 * `payment_breakdown[]`. Couvre :
 *
 *  1. Création POS multi-tender → rows order_payments persistées
 *  2. Création POS sans payment_breakdown → backward-compat legacy
 *  3. Sum < total → 422
 *  4. Cash tranche sans tendered → 422
 *  5. (Si idempotency activé) replay même payload → 1 set tranches
 *  6. OrderDetailsResource (GET /api/admin/pos-order/show/{id}) renvoie
 *     la liste des tranches.
 */
class SplitPaymentEndToEndTest extends TestCase
{
    use HasPosQuoteBinding;
    use RefreshDatabase;

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

        Config::set('split_payment.enabled', true);
        Config::set('split_payment.max_tranches', 12);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        $this->branch = Branch::factory()->create();

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            // [Sprint 2B compat] users.phone NOT NULL — provide unique sentinel.
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%',
            'code' => 'TVA0',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0.00,
            'status' => Status::ACTIVE,
        ]);

        $cat = ItemCategory::factory()->create([
            'name' => 'Cat',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'name' => 'Item',
            'price' => 25.00,
            'status' => Status::ACTIVE,
        ]);

        // [Sprint 1B 2026-05-16] NF525 cash trail — POS direct & split CASH
        // require an OPEN cash drawer session for the cashier. Opening one
        // upfront keeps the existing split-payment scenarios green; the new
        // PosCashTrailTest suite covers the "no session → 422" path.
        app(CashDrawerService::class)
            ->openSession($this->branch->id, $this->operator->id, 100.00);

        // [F-SPLIT-PHANTOM-CARD-001 2026-05-17] CARD tranches now require an
        // ACTIVE terminal_id scoped to the order's branch — see PosOrderRequest.
        $this->terminal = PaymentTerminal::create([
            'branch_id' => $this->branch->id,
            'name' => 'TPE E2E',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent' => 0,
            'fee_fixed' => 0,
            'status' => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 25,
            'items' => json_encode([
                [
                    'item_id' => $this->item->id,
                    'item_price' => $this->item->price,
                    'quantity' => 1,
                    'total_price' => 25.00,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ], $overrides);
    }

    public function test_pos_create_with_payment_breakdown_persists_two_rows(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 12.00, 'change' => 2.00],
                // [F-SPLIT-PHANTOM-CARD-001] CARD tranche now requires terminal_id.
                ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '4242', 'terminal_id' => $this->terminal->id],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId, 'order id missing in response');

        $rows = OrderPayment::where('order_id', $orderId)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(25.00, $rows->sum(fn ($r) => (float) $r->amount), 0.01);
        $this->assertSame((int) $this->branch->id, (int) $rows[0]->branch_id);
        $this->assertSame((int) $this->branch->id, (int) $rows[1]->branch_id);
    }

    public function test_pos_create_without_payment_breakdown_legacy_single_tender_works(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload(); // pas de payment_breakdown

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId);

        $this->assertSame(0, OrderPayment::where('order_id', $orderId)->count(),
            'Legacy single-tender ne doit PAS écrire dans order_payments.');
    }

    public function test_pos_create_with_breakdown_sum_below_total_returns_422(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 5.00, 'tendered' => 5.00],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(422);
        $this->assertSame(0, OrderPayment::count(),
            'Aucune tranche ne doit être persistée quand la validation échoue.');
    }

    public function test_pos_create_cash_tranche_without_tendered_returns_422(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 25.00], // pas de tendered
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(422);
        $this->assertSame(0, OrderPayment::count());
    }

    public function test_order_details_resource_returns_payments_breakdown_array(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00, 'change' => 0],
                // [F-SPLIT-PHANTOM-CARD-001] CARD tranche now requires terminal_id.
                ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '4242', 'terminal_id' => $this->terminal->id],
            ],
        ]);

        $createResp = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));
        $createResp->assertStatus(201);
        $orderId = $createResp->json('data.id');

        // GET show
        $showResp = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-order/show/'.$orderId);

        $showResp->assertStatus(200);
        $breakdown = $showResp->json('data.payments_breakdown');

        $this->assertIsArray($breakdown);
        $this->assertCount(2, $breakdown);
        // Chaque tranche : method, amount, currency_amount, change_amount, reference
        $this->assertArrayHasKey('method', $breakdown[0]);
        $this->assertArrayHasKey('amount', $breakdown[0]);
    }

    /**
     * [ULTRA-AUDIT Wave 3 2026-07-04] Le frontend frozen (PaymentComponent.vue:833-847) envoie en
     * split pos_payment_method = mode de la tranche DOMINANTE + pos_received_amount = tendered de la
     * SEULE tranche cash (montant PARTIEL). Ces deux tests reproduisent ce payload réel (les tests
     * existants envoyaient pos_received_amount=total complet, ce qui MASQUAIT les deux bugs).
     */
    public function test_split_cash_dominant_with_partial_received_amount_succeeds(): void
    {
        // Split cash-dominant (15 cash > 10 card). La garde cash serveur (OrderService:1071) comparait
        // le tendered PARTIEL (15) au TOTAL complet (25) → 422 à tort. La somme 15+10=25 est déjà
        // garantie par SplitPaymentService::validateBreakdown → la garde single-tender ne doit pas s'appliquer.
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 15.00, // tendered PARTIEL de la tranche cash, pas le total
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 15.00, 'tendered' => 15.00, 'change' => 0],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 10.00, 'reference' => '4242', 'terminal_id' => $this->terminal->id],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertCount(2, OrderPayment::where('order_id', $orderId)->get(),
            'Un split cash-dominant valide doit aboutir et persister 2 tranches.');
    }

    public function test_split_card_dominant_with_multi_tender_note_succeeds(): void
    {
        // Split card-dominant (15 card > 10 cash). Le frontend pose pos_payment_method=CARD +
        // pos_payment_note='multi-tender' + PAS de terminal_id top-level (il vit par-tranche). Les règles
        // single-tender CARD (note 4 chiffres PosOrderRequest:129 + terminal_id required_if :141) se
        // déclenchaient à tort sur le champ dominant → 422. Elles doivent être gatées sur l'absence de split.
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'pos_payment_method' => PosPaymentMethod::CARD,
            'pos_payment_note' => 'multi-tender',
            'pos_received_amount' => 10.00,
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '4242', 'terminal_id' => $this->terminal->id],
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00, 'change' => 0],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertCount(2, OrderPayment::where('order_id', $orderId)->get(),
            'Un split card-dominant valide doit aboutir et persister 2 tranches.');
    }

    /**
     * [SELF-AUDIT P1 2026-07-05 — double cash-in NF525] Un split multi-tender sur une commande
     * DIFFÉRÉE au comptoir (defer_to_counter) est rejeté fail-closed AVANT toute persistance.
     * confirmCounterPayment est mono-tender : laisser passer un split différé armait un double
     * cash-in (tranches cash écrites à la création PUIS total réécrit à l'encaissement). Mon unblock
     * Wave 3 (cesser de 422 les splits) avait rouvert ce chemin → cette garde le referme.
     */
    public function test_split_payment_with_defer_to_counter_is_rejected_422(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'defer_to_counter' => true,
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00, 'change' => 0],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '4242', 'terminal_id' => $this->terminal->id],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('payment_breakdown');
        $this->assertSame(0, OrderPayment::count(),
            'Aucune tranche persistée : le rejet précède la création (fail-closed anti double cash-in).');
    }

    public function test_pos_create_with_breakdown_when_flag_off_falls_back_legacy(): void
    {
        Config::set('split_payment.enabled', false);
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        // Flag OFF : prepareForValidation strip le champ AVANT validation,
        // legacy single-tender doit fonctionner et 0 tranche persistée.
        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertSame(0, OrderPayment::where('order_id', $orderId)->count(),
            'Quand le flag est OFF, payment_breakdown doit être ignoré silencieusement.');
    }
}
