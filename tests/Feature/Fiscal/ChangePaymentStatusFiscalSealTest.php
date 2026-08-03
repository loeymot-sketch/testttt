<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ULTRA-AUDIT Wave 2 2026-07-04 — P1 vente off-book NF525]
 *
 * Sceller une commande en PAID DOIT allouer un `fiscal_sequence_no` (invariant CLAUDE.md §8,
 * appliqué aux 3 autres points de scellage : POS create, confirmCounterPayment:335-337, kiosk TPE).
 * L'arête UNPAID→PAID de {@see \App\Services\OrderService::changePaymentStatus} (« marquer payé »
 * sur commandes livraison/en-ligne/table) l'OUBLIAIT → commande PAID sans numéro fiscal = vente
 * HORS chaîne NF525 (exclue du Z signé, jamais rattrapée par RetryFiscalAllocCommand).
 *
 * Le garde off-book existant ne couvrait QUE PENDING_COUNTER→PAID ; cette arête-là reste bloquée
 * (doit passer par l'encaissement pour le cash_movement). Ce test verrouille le comportement des
 * DEUX arêtes + les exclusions (Uber non fiscalisé, statut terminal non scellé).
 */
class ChangePaymentStatusFiscalSealTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch  = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'user_id'            => $this->cashier->id,
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'status'             => OrderStatus::ACCEPT,
            'fiscal_sequence_no' => null,
        ], $attrs));
    }

    private function markPaid(Order $order)
    {
        return $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/admin/pos-order/change-payment-status/' . $order->id, [
                'payment_status' => PaymentStatus::PAID,
            ]);
    }

    /** @test — LE FIX : sceller UNPAID→PAID alloue le numéro fiscal (plus d'off-book). */
    public function unpaid_to_paid_allocates_a_fiscal_sequence_number(): void
    {
        $order = $this->makeOrder();
        $this->assertNull($order->fiscal_sequence_no, 'précondition : pas encore scellée');

        $response = $this->markPaid($order);
        $response->assertStatus(200);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'Une commande scellée PAID DOIT porter un fiscal_sequence_no (fin de la vente off-book NF525).'
        );
        $this->assertGreaterThan(0, (int) $fresh->fiscal_sequence_no);
    }

    /** @test — non-régression : le cas différé borne Plan B reste INTERDIT (doit passer par l'encaissement). */
    public function pending_counter_to_paid_is_still_blocked_422(): void
    {
        $order = $this->makeOrder(['payment_status' => PaymentStatus::PENDING_COUNTER]);

        $response = $this->markPaid($order);
        $response->assertStatus(422);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $fresh->payment_status);
        $this->assertNull($fresh->fiscal_sequence_no, 'aucune allocation depuis ce chemin interdit');
    }

    /** @test — exclusion Uber : source_surface=uber_eats n'est PAS fiscalisée (uber.fiscalize=false, facturé par l'agrégateur). */
    public function uber_order_unpaid_to_paid_is_not_fiscalized(): void
    {
        $order = $this->makeOrder(['source_surface' => 'uber_eats']);

        $response = $this->markPaid($order);
        $response->assertStatus(200);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertNull(
            $fresh->fiscal_sequence_no,
            'Une commande Uber (facturée séparément) NE DOIT PAS consommer un numéro fiscal FR.'
        );
    }

    /** @test — exclusion statut terminal : une commande annulée ne consomme pas de séquence (miroir PaymentService:323). */
    public function canceled_order_marked_paid_does_not_consume_a_sequence(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::CANCELED]);

        $this->markPaid($order);

        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertNull(
            $fresh->fiscal_sequence_no,
            'Pas de numéro fiscal pour une vente void (le Z exclut déjà les statuts terminaux).'
        );
    }
}
