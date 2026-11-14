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
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [POINTS À LA VALIDATION 2026-11-06 · relecture adverse P0-1/P0-2 · audit abusif W3-P1-4 / W2-P1-4]
 *
 * - Une commande PAYÉE crédite dès la validation ; une commande PAS ENCORE PAYÉE ne crédite rien sur un simple
 *   statut : ses points tombent à l'ENCAISSEMENT (ou à la remise). Sinon : points d'une commande jamais réglée,
 *   dépensés, puis commande annulée → reprise plafonnée au solde → points gratuits (W3-P1-4, chaîne rejouée ici).
 * - Une commande borne ANONYME ne crédite jamais le compte propriétaire de la borne (W2-P1-4).
 * - La purge des impayés reprend des points déjà crédités (commandes d'avant ce changement).
 * Chaque test passe par le VRAI chemin (job, route d'annulation avec un vrai jeton client, encaissement).
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
        $u = User::factory()->create([
            'branch_id' => $this->branch->id, 'status' => \App\Enums\Status::ACTIVE,
            'loyalty_points' => 0, 'loyalty_code' => 'REP' . strtoupper(substr(uniqid(), -6)),
        ]);
        $u->assignRole('Customer'); // par NOM : dans le harnais l'id 2 est « Chef » (CustomerAccount::isCustomer)

        return $u;
    }

    private function solde(User $u): int
    {
        return (int) DB::table('users')->where('id', $u->id)->value('loyalty_points');
    }

    private function passer(Order $o, int $vers, int $de = OrderStatus::PENDING): void
    {
        DB::table('orders')->where('id', $o->id)->update(['status' => $vers]);
        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($o->fresh(), $de, $vers));
    }

    private function commandeWebAuComptoir(User $client, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id, 'user_id' => $client->id, 'source_surface' => 'web',
            'order_type' => OrderType::TAKEAWAY, 'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING_COUNTER, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED, // marqueur posé à l'acceptation (OnlineOrderController)
            'fiscal_sequence_no' => null, 'total' => 12.40, 'subtotal' => 12.40, 'discount' => 0,
            'total_tax' => 0, 'delivery_charge' => 0,
        ], $extra));
    }

    private function caissierEnService(): User
    {
        $caissier = User::factory()->create(['branch_id' => $this->branch->id, 'password' => Hash::make('pwd')]);
        $caissier->assignRole('POS Operator');
        $caissier->givePermissionTo('pos');
        app(CashDrawerService::class)->openSession($this->branch->id, $caissier->id, 100.00);

        return $caissier;
    }

    /** « Payer sur place » : validée = rien encore ; encaissée = crédit. */
    public function test_payer_sur_place_rien_a_la_validation_credit_a_l_encaissement(): void
    {
        $client = $this->client();
        $o = $this->commandeWebAuComptoir($client);

        $this->passer($o, OrderStatus::ACCEPT);
        $this->passer($o, OrderStatus::PREPARING, OrderStatus::ACCEPT);
        $this->assertSame(0, $this->solde($client), 'validée mais pas encore payée : aucun point');

        $this->actingAs($this->caissierEnService());
        app(PaymentService::class)->confirmCounterPayment($o->fresh(), PosPaymentMethod::CASH);

        $this->assertSame(124, $this->solde($client), 'encaissée : les points tombent');
    }

    /** W3-P1-4 rejoué avec un VRAI jeton client : acceptée impayée, annulée par le client → aucun point gagné. */
    public function test_chaine_w3_aucun_point_sans_achat(): void
    {
        $client = $this->client();
        $o = $this->commandeWebAuComptoir($client, ['total' => 108.00, 'subtotal' => 108.00]);
        $this->passer($o, OrderStatus::ACCEPT);
        $this->assertSame(0, $this->solde($client), 'acceptée, impayée : rien à dépenser');

        $jeton = $client->createToken('auth_token', ['*'])->plainTextToken;
        $r = $this->withHeaders(['Authorization' => 'Bearer ' . $jeton, 'x-api-key' => '123456', 'X-Idempotency-Key' => 'w3-' . bin2hex(random_bytes(6))])
            ->postJson('/api/frontend/order/change-status/' . $o->id, ['status' => OrderStatus::CANCELED, 'reason' => 'customer_request']);

        if (in_array($r->status(), [200, 201], true)) {
            $this->assertSame(OrderStatus::CANCELED, (int) $o->fresh()->status);
        }
        $this->assertSame(0, $this->solde($client), 'quelle que soit l\'issue de l\'annulation, aucun point sans achat');
        $this->assertSame(0, DB::table('loyalty_transactions')->where('user_id', $client->id)->where('type', 'earn')->count());
    }

    /** P0-1 — la purge d'une commande impayée reprend des points déjà crédités (règle d'avant ce changement). */
    public function test_la_purge_des_impayes_reprend_des_points_deja_credites(): void
    {
        $client = $this->client();
        $o = $this->commandeWebAuComptoir($client, ['created_at' => now()->subHours(8), 'order_datetime' => now()->subHours(8), 'status' => OrderStatus::ACCEPT]);
        app(AwardLoyaltyPointsOnDelivery::class)->award($o->fresh()); // crédit hérité (ex. borne créditée à « prête »)
        $this->assertSame(124, $this->solde($client));

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::CANCELED, (int) $o->fresh()->status, 'pré-condition : la purge a annulé');
        $this->assertSame(0, $this->solde($client), 'jamais payée, purgée : points repris');
    }

    /** P0-2 — borne Plan B : rien à l'acceptation automatique, crédit à l'encaissement. */
    public function test_borne_impayee_rien_a_l_acceptation_automatique_credit_a_l_encaissement(): void
    {
        $client = $this->client();
        $caissier = $this->caissierEnService();
        $this->actingAs($caissier);
        $o = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $caissier->id, 'loyalty_customer_code' => $client->loyalty_code,
            'total' => 12.40, 'subtotal' => 12.40, 'discount' => 0, 'total_tax' => 0, 'delivery_charge' => 0,
            'status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::PENDING_COUNTER,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface' => 'kiosk', 'order_type' => OrderType::KIOSK,
        ]);

        $this->passer($o, OrderStatus::ACCEPT);
        $this->assertSame(0, $this->solde($client), 'borne impayée acceptée par le système : aucun point');

        app(PaymentService::class)->confirmCounterPayment($o->fresh(), PosPaymentMethod::CASH);

        $this->assertSame(124, $this->solde($client), 'encaissée au comptoir : les points tombent');
        $this->assertSame(1, DB::table('loyalty_transactions')->where('order_id', $o->id)->where('type', 'earn')->count());
    }

    /** W2-P1-4 — une commande borne ANONYME ne crédite jamais le compte propriétaire de la borne. */
    public function test_commande_borne_anonyme_ne_credite_pas_le_proprietaire_de_la_borne(): void
    {
        $proprietaire = User::factory()->create(['branch_id' => $this->branch->id, 'loyalty_points' => 0, 'loyalty_code' => 'ADMINBORNE']);
        $proprietaire->assignRole('Admin');
        KioskMachine::withoutGlobalScopes()->forceCreate([
            'user_id' => $proprietaire->id, 'branch_id' => $this->branch->id, 'machine_id' => 'm-anon-01',
            'username' => 'borne-anon', 'password' => bcrypt('x'), 'is_login' => 10, 'status' => 5,
        ]);
        $o = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $proprietaire->id, 'loyalty_customer_code' => null,
            'total' => 19.00, 'subtotal' => 19.00, 'discount' => 0, 'total_tax' => 0, 'delivery_charge' => 0,
            'status' => OrderStatus::PENDING, 'payment_status' => PaymentStatus::PAID,
            'payment_method' => PaymentGateway::CARD, 'source_surface' => 'kiosk', 'order_type' => OrderType::KIOSK,
        ]);

        $this->passer($o, OrderStatus::PREPARING);

        $this->assertSame(0, (int) DB::table('users')->where('id', $proprietaire->id)->value('loyalty_points'),
            'commande anonyme : la cagnotte n\'est pas versée au compte de la borne');
    }
}
