<?php

namespace Tests\Feature\Sentinels;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * @FK-ID F-SPLIT-PAYMENT-001 | @plan docs/audit/plans/PLAN_P12_SPLIT_PAYMENT_BACKEND_2026-05-06.md
 *        | @reason POS multi-tender doit (a) refuser un sum<total, (b) garantir branch isolation
 *                  par tranche (c) ignorer le champ silencieusement quand le flag est OFF.
 *
 * Sentinels narrows : on exerce la vraie route POST /api/admin/pos avec un cashier réel
 * pour garantir le contrat exécutable.
 */
class SplitPaymentSentinelTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $cashierA;
    protected User $customerA;
    protected Item $itemA;
    protected PaymentTerminal $terminalA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('split_payment.enabled', true);
        Config::set('split_payment.max_tranches', 12);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->customerA = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password' => Hash::make('password'),
            // [Sprint 2B compat] users.phone NOT NULL.
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $this->customerA->assignRole('Customer');

        $this->cashierA = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password' => Hash::make('password'),
            'phone' => fake()->unique()->numerify('06########'),
        ]);
        $this->cashierA->assignRole('POS Operator');

        // [Sprint 1B 2026-05-16] POS CASH path now requires an OPEN cash
        // drawer session for the cashier — open one upfront so sentinel
        // scenarios stay focused on split-payment semantics.
        app(\App\Services\Cash\CashDrawerService::class)
            ->openSession($this->branchA->id, $this->cashierA->id, 100.00);

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

        $this->itemA = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'name' => 'Item',
            'price' => 25.00,
            'status' => Status::ACTIVE,
        ]);

        // [F-SPLIT-PHANTOM-CARD-001 2026-05-17] Active TPE on branchA so
        // CARD tranches can pass the new mandatory terminal_id check.
        $this->terminalA = PaymentTerminal::create([
            'branch_id'    => $this->branchA->id,
            'name'         => 'TPE Sentinel',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent'  => 0,
            'fee_fixed'    => 0,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    private function basePayload(int $branchId, int $customerId, int $itemId, array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $customerId,
            'branch_id' => $branchId,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 25,
            'items' => json_encode([
                [
                    'item_id' => $itemId,
                    'item_price' => 25.00,
                    'quantity' => 1,
                    'total_price' => 25.00,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ], $overrides);
    }

    public function test_payment_breakdown_sum_below_total_is_rejected_422(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $payload = $this->basePayload($this->branchA->id, $this->customerA->id, $this->itemA->id, [
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->cashierA, $payload));

        $response->assertStatus(422);
        $this->assertSame(0, OrderPayment::count(),
            'Sentinel: sum<total ne doit JAMAIS persister une tranche orpheline.');
    }

    public function test_payment_breakdown_branch_id_denormalised_matches_order_branch(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $payload = $this->basePayload($this->branchA->id, $this->customerA->id, $this->itemA->id, [
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 10.00, 'tendered' => 10.00, 'change' => 0],
                // [F-SPLIT-PHANTOM-CARD-001] CARD tranche now requires terminal_id.
                ['mode' => PosPaymentMethod::CARD, 'amount' => 15.00, 'reference' => '4242', 'terminal_id' => $this->terminalA->id],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->cashierA, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');
        $rows = OrderPayment::where('order_id', $orderId)->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame((int) $this->branchA->id, (int) $row->branch_id,
                'Sentinel: chaque tranche DOIT porter le branch_id de la commande (jamais cross-branch).');
        }
        // Aucune tranche orpheline cross-branch
        $this->assertSame(0, OrderPayment::where('branch_id', $this->branchB->id)->count());
    }

    public function test_payment_breakdown_silently_ignored_when_flag_disabled(): void
    {
        Config::set('split_payment.enabled', false);
        $this->actingAs($this->cashierA, 'sanctum');

        // Payload contient un payment_breakdown CASSÉ (sum<total) → si la
        // validation tournait quand même, ça donnerait un 422. Avec le flag
        // OFF, prepareForValidation strip le champ → la requête réussit en
        // single-tender et ZÉRO tranche persistée.
        $payload = $this->basePayload($this->branchA->id, $this->customerA->id, $this->itemA->id, [
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 5.00, 'tendered' => 5.00], // sum < total
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->cashierA, $payload));

        $response->assertStatus(201);

        // [W-INT 2026-06-12] Contrat mis à jour pour E-ADV-9 (heal spine
        // dispute-r1 36147531b) : la création POS inline-paid mint désormais
        // UNE OrderPayment mono-mode (ventilation Z par TPE) même flag OFF.
        // L'intent du sentinel est préservé : le payment_breakdown CASSÉ
        // (5.00 < total) doit rester ignoré — donc AUCUNE tranche issue du
        // breakdown (amount=5.00), une seule row mono-mode au montant PLEIN.
        $rows = OrderPayment::all();
        $this->assertCount(1, $rows,
            'Sentinel: flag OFF → une seule row mono-mode E-ADV-9, jamais les tranches du breakdown.');
        $this->assertNotEquals(5.00, (float) $rows[0]->amount,
            'Sentinel: la tranche cassée du breakdown (5.00) ne doit JAMAIS être persistée.');
    }
}
