<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderStatusChanged;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [POINTS À LA VALIDATION 2026-11-06 · relecture adverse P0-1 / P0-2 / P1-3]
 *
 * Les points tombent désormais à la VALIDATION : chaque chemin qui annule ensuite une commande validée doit
 * les reprendre, et l'acceptation AUTOMATIQUE d'une commande borne impayée n'est pas une validation.
 * Chaque test passe par le VRAI chemin (job de purge, route d'annulation du client, encaissement comptoir).
 */
class PointsValidationRepriseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10, 'loyalty_points_for_1_euro_discount' => 100, 'loyalty_min_redeem_points' => 50,
        ]);
        $this->branch = Branch::factory()->create();
    }

    private function client(): User
    {
        return User::factory()->create([
            'branch_id' => $this->branch->id, 'status' => \App\Enums\Status::ACTIVE,
            'loyalty_points' => 0, 'loyalty_code' => 'REP' . strtoupper(substr(uniqid(), -6)),
        ]);
    }

    private function solde(User $u): int
    {
        return (int) DB::table('users')->where('id', $u->id)->value('loyalty_points');
    }

    private function valider(Order $o, int $vers = OrderStatus::ACCEPT): void
    {
        DB::table('orders')->where('id', $o->id)->update(['status' => $vers]);
        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($o->fresh(), OrderStatus::PENDING, $vers));
    }

    private function commandeWebAuComptoir(User $client, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id, 'user_id' => $client->id, 'source_surface' => 'web',
            'order_type' => OrderType::TAKEAWAY, 'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING_COUNTER, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'fiscal_sequence_no' => null, 'total' => 12.40, 'subtotal' => 12.40, 'discount' => 0,
            'total_tax' => 0, 'delivery_charge' => 0,
        ], $extra));
    }

    /** P0-1 — la purge automatique d'une commande validée jamais payée reprend les points gagnés. */
    public function test_la_purge_des_impayes_reprend_les_points_gagnes_a_la_validation(): void
    {
        $client = $this->client();
        $o = $this->commandeWebAuComptoir($client, ['created_at' => now()->subHours(8), 'order_datetime' => now()->subHours(8)]);
        $this->valider($o);
        $this->assertSame(124, $this->solde($client), 'pré-condition : crédité à la validation');

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::CANCELED, (int) $o->fresh()->status, 'pré-condition : la purge a bien annulé');
        $this->assertSame(0, $this->solde($client), 'commande jamais payée, annulée par la purge : les points sont repris');
    }

    /**
     * P1-3 (réfuté sous sa forme d'origine) — la route d'annulation du site n'est ouverte qu'au jeton de la borne
     * (sa propre commande) et au personnel, que le service renvoie à la route d'administration (laquelle reprend
     * déjà les points, OrderService). Un client ne peut PAS annuler lui-même une commande validée.
     */
    public function test_un_client_ne_peut_pas_annuler_lui_meme_sa_commande_validee(): void
    {
        $client = $this->client();
        $o = $this->commandeWebAuComptoir($client);
        $this->valider($o);

        Sanctum::actingAs($client);
        $this->withHeaders(['x-api-key' => '123456', 'X-Idempotency-Key' => 'rep-' . bin2hex(random_bytes(6))])
            ->postJson('/api/frontend/order/change-status/' . $o->id, ['status' => OrderStatus::CANCELED, 'reason' => 'x'])
            ->assertStatus(403);
        $this->assertSame(124, $this->solde($client), 'la commande validée reste due et créditée');
    }

    /** P0-2 — l'acceptation AUTOMATIQUE d'une commande borne impayée ne crédite rien ; l'encaissement, si. */
    public function test_borne_impayee_rien_a_l_acceptation_automatique_credit_a_l_encaissement(): void
    {
        $client = $this->client();
        $caissier = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('pwd')]);
        $caissier->assignRole('POS Operator');
        $caissier->givePermissionTo('pos');
        app(CashDrawerService::class)->openSession($this->branch->id, $caissier->id, 100.00);
        $this->actingAs($caissier);

        $o = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $caissier->id, 'loyalty_customer_code' => $client->loyalty_code,
            'total' => 12.40, 'subtotal' => 12.40, 'discount' => 0, 'total_tax' => 0, 'delivery_charge' => 0,
            'status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::PENDING_COUNTER,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface' => 'kiosk', 'order_type' => OrderType::KIOSK,
        ]);

        $this->valider($o, OrderStatus::ACCEPT); // acceptation automatique à la création (FrontendOrderService)
        $this->assertSame(0, $this->solde($client), 'borne impayée acceptée par le système : aucun point');

        app(PaymentService::class)->confirmCounterPayment($o->fresh(), PosPaymentMethod::CASH);

        $this->assertSame(124, $this->solde($client), 'encaissée au comptoir : les points tombent');
        $this->assertSame(1, DB::table('loyalty_transactions')->where('order_id', $o->id)->where('type', 'earn')->count());
    }
}
