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
 * @FK-ID F-SPLIT-PHANTOM-CARD-001
 *        | @reason Phantom-CARD theft vector — without a mandatory + branch-scoped
 *                  terminal_id, a cashier could submit a CARD tranche with a forged
 *                  reference and pocket the matching cash (no cash_movement is
 *                  written for CARD tranches → drawer reconciles silently).
 *
 * Sentinels narrows : on exerce la vraie route POST /api/admin/pos avec un cashier
 * réel pour garantir le contrat exécutable de bout en bout.
 */
class PosSplitPaymentPhantomCardSentinelTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $cashierA;
    protected User $customerA;
    protected Item $itemA;
    protected PaymentTerminal $terminalA;
    protected PaymentTerminal $terminalB;

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
            'password'  => Hash::make('password'),
            'phone'     => fake()->unique()->numerify('06########'),
        ]);
        $this->customerA->assignRole('Customer');

        $this->cashierA = User::factory()->create([
            'branch_id' => $this->branchA->id,
            'password'  => Hash::make('password'),
            'phone'     => fake()->unique()->numerify('06########'),
        ]);
        $this->cashierA->assignRole('POS Operator');

        app(\App\Services\Cash\CashDrawerService::class)
            ->openSession($this->branchA->id, $this->cashierA->id, 100.00);

        $tax = Tax::factory()->create([
            'name'     => 'TVA 0%',
            'code'     => 'TVA0',
            'type'     => TaxType::PERCENTAGE,
            'tax_rate' => 0.00,
            'status'   => Status::ACTIVE,
        ]);

        $cat = ItemCategory::factory()->create([
            'name'            => 'Cat',
            'wizard_template' => 'tacos',
            'has_menu'        => true,
        ]);

        $this->itemA = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id'           => $tax->id,
            'name'             => 'Item75',
            'price'            => 75.00,
            'status'           => Status::ACTIVE,
        ]);

        // Active TPE on branchA — legitimate CARD tranches.
        $this->terminalA = PaymentTerminal::create([
            'branch_id'    => $this->branchA->id,
            'name'         => 'TPE Branch A',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent'  => 0,
            'fee_fixed'    => 0,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        // Active TPE on branchB — used to prove cross-branch leakage is blocked.
        $this->terminalB = PaymentTerminal::create([
            'branch_id'    => $this->branchB->id,
            'name'         => 'TPE Branch B',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent'  => 0,
            'fee_fixed'    => 0,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);
    }

    private function basePayload(int $branchId, int $customerId, int $itemId, array $overrides = []): array
    {
        return array_merge([
            'token'               => null,
            'customer_id'         => $customerId,
            'branch_id'           => $branchId,
            'discount'            => 0,
            'order_type'          => OrderType::TAKEAWAY,
            'is_advance_order'    => 0,
            'source'              => 1,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 75,
            'items'               => json_encode([
                [
                    'item_id'          => $itemId,
                    'item_price'       => 75.00,
                    'quantity'         => 1,
                    'total_price'      => 75.00,
                    'item_variations'  => [],
                    'item_extras'      => [],
                ],
            ]),
        ], $overrides);
    }

    /**
     * Phantom-CARD core: CARD tranche without terminal_id MUST be rejected.
     * Without this guard a cashier could pocket the equivalent cash since no
     * cash_movement is written for CARD tranches.
     */
    public function test_card_tranche_without_terminal_id_is_rejected_422(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $payload = $this->basePayload($this->branchA->id, $this->customerA->id, $this->itemA->id, [
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 20.00, 'tendered' => 20.00],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 55.00, 'reference' => 'VISA-fake-1234'],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->cashierA, $payload));

        $response->assertStatus(422);
        $this->assertSame(
            0,
            OrderPayment::count(),
            'Sentinel: CARD sans terminal_id ne doit JAMAIS persister.'
        );
    }

    /**
     * Cross-branch leak: a terminal owned by branchB MUST be unusable on a
     * branchA order. BranchScope is bypassed by Laravel's `exists` rule, so
     * branch_id is checked explicitly in PosOrderRequest::withValidator.
     */
    public function test_card_tranche_with_cross_branch_terminal_is_rejected_422(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $payload = $this->basePayload($this->branchA->id, $this->customerA->id, $this->itemA->id, [
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 20.00, 'tendered' => 20.00],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 55.00, 'terminal_id' => $this->terminalB->id],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->cashierA, $payload));

        $response->assertStatus(422);
        $this->assertSame(
            0,
            OrderPayment::count(),
            'Sentinel: terminal_id d\'une autre branche ne doit JAMAIS persister (cross-branch leak).'
        );
    }

    /**
     * Happy path: CASH + CARD with a valid same-branch terminal_id MUST succeed
     * and both tranches MUST be persisted on the order's branch.
     */
    public function test_card_tranche_with_valid_same_branch_terminal_persists(): void
    {
        $this->actingAs($this->cashierA, 'sanctum');

        $payload = $this->basePayload($this->branchA->id, $this->customerA->id, $this->itemA->id, [
            'payment_breakdown' => [
                ['mode' => PosPaymentMethod::CASH, 'amount' => 20.00, 'tendered' => 20.00],
                ['mode' => PosPaymentMethod::CARD, 'amount' => 55.00, 'terminal_id' => $this->terminalA->id],
            ],
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->cashierA, $payload));

        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');
        $rows = OrderPayment::where('order_id', $orderId)->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(PosPaymentMethod::CASH, (int) $rows[0]->mode);
        $this->assertSame(PosPaymentMethod::CARD, (int) $rows[1]->mode);
        $this->assertSame((int) $this->terminalA->id, (int) $rows[1]->terminal_id,
            'Sentinel: la tranche CARD DOIT mémoriser le terminal_id (audit + Z-report).');
    }
}
