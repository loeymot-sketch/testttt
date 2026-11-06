<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Loyalty\PosLoyaltyAttachService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [POINTS À LA VALIDATION 2026-11-06 · relecture adverse, 2ᵉ tour] Compte technique sans rôle client jamais crédité ;
 * rattachement refusé quand les points sont déjà crédités à un autre ; points NETS affichés après une reprise ;
 * « marquer payé » crédite une commande déjà validée.
 */
class PointsRelectureTour2Test extends TestCase
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
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $this->app->instance(FiscalSequenceService::class, new class(9000) extends FiscalSequenceService {
            private int $c;
            public function __construct(int $s) { $this->c = $s; }
            public function next(int $branchId): int { return ++$this->c; }
        });
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    private function client(string $code): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE, 'loyalty_points' => 0, 'loyalty_code' => $code]);
        $u->assignRole('Customer');

        return $u;
    }

    private function commandePayee(User $u, array $extra = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id, 'user_id' => $u->id, 'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB, 'source_surface' => 'web', 'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::PENDING, 'total' => 18.50, 'subtotal' => 18.50,
        ], $extra));
    }

    private function valider(Order $o): void
    {
        DB::table('orders')->where('id', $o->id)->update(['status' => OrderStatus::ACCEPT]);
        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($o->fresh(), OrderStatus::PENDING, OrderStatus::ACCEPT));
    }

    private function solde(User $u): int
    {
        return (int) DB::table('users')->where('id', $u->id)->value('loyalty_points');
    }

    public function test_un_compte_technique_sans_role_client_n_est_jamais_credite(): void
    {
        $uber = User::factory()->create(['username' => 'uber-eats-system', 'branch_id' => $this->branch->id, 'loyalty_points' => 0, 'loyalty_code' => 'UBERSYS1']);
        $o = $this->commandePayee($uber, ['total' => 30.00, 'subtotal' => 30.00]);

        $this->valider($o);

        $this->assertSame(0, $this->solde($uber), 'compte technique (aucun rôle client) : aucun point');
    }

    public function test_rattacher_un_autre_client_apres_le_credit_est_refuse(): void
    {
        $a = $this->client('CLIENTAA');
        $b = $this->client('CLIENTBB');
        $o = $this->commandePayee($a);
        $this->valider($o);
        $this->assertSame(185, $this->solde($a));

        $r = app(PosLoyaltyAttachService::class)->attach($o->fresh(), 'CLIENTBB', null);

        $this->assertFalse((bool) ($r['ok'] ?? true), 'rattacher B à une commande déjà créditée à A doit être refusé');
        $this->assertNull($o->fresh()->loyalty_customer_code, 'aucun code posé');

        app(LoyaltyService::class)->clawbackEarnedForOrder($o->fresh(), 'test');
        $this->assertSame(0, $this->solde($a), 'la reprise vise le client réellement crédité (A)');
        $this->assertSame(0, $this->solde($b));
    }

    public function test_points_nets_apres_une_reprise_sans_changement_de_statut(): void
    {
        $a = $this->client('CLIENTNE');
        $o = $this->commandePayee($a);
        $this->valider($o);
        $this->assertSame(185, LoyaltyService::pointsNetsCommande($o->fresh()));

        app(LoyaltyService::class)->clawbackEarnedPoints($a->id, 185, (int) $o->id, 'Remboursement après clôture');

        $this->assertSame(OrderStatus::ACCEPT, (int) $o->fresh()->status, 'pré-condition : statut inchangé');
        $this->assertSame(0, LoyaltyService::pointsNetsCommande($o->fresh()), 'points repris : le client ne lit plus « +185 crédités »');
    }

    public function test_marquer_paye_une_commande_validee_credite_les_points(): void
    {
        $a = $this->client('CLIENTMP');
        // Déjà PRÊTE : aucune promotion automatique (ACCEPT → PREPARING) n'aura lieu au paiement, donc aucun événement
        // de statut — seul l'appel explicite de changePaymentStatus peut créditer (sinon le test ne prouverait rien).
        $o = $this->commandePayee($a, ['payment_status' => PaymentStatus::UNPAID, 'payment_method' => PaymentGateway::CARD, 'status' => OrderStatus::PENDING]);
        DB::table('orders')->where('id', $o->id)->update(['status' => OrderStatus::PREPARED]);
        (new AwardLoyaltyPointsOnDelivery())->handle(new OrderStatusChanged($o->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED));
        $this->assertSame(0, $this->solde($a), 'prête mais impayée : rien');

        $admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $admin->assignRole('Admin');
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo('online-orders');
        $r = $this->actingAs($admin->fresh(), 'sanctum')
            ->withHeaders(['x-api-key' => '123456', 'X-Idempotency-Key' => 'mp-' . bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-payment-status/{$o->id}", ['payment_status' => PaymentStatus::PAID]);

        $this->assertContains($r->status(), [200, 201], $r->getContent());
        $this->assertSame(PaymentStatus::PAID, (int) $o->fresh()->payment_status, 'pré-condition : payée');
        $this->assertSame(185, $this->solde($a), '« marquer payé » d\'une commande validée : les points tombent');
    }

    /** Audit tour 2 · P2 — le code d'un compte de l'équipe (saisi à la borne / rattaché en caisse) ne reçoit rien. */
    public function test_le_code_d_un_compte_de_l_equipe_ne_recoit_pas_les_points(): void
    {
        $admin = User::factory()->create(['branch_id' => $this->branch->id, 'loyalty_points' => 0, 'loyalty_code' => 'ADMINCOD']);
        $admin->assignRole('Admin');
        $client = $this->client('ACHETEUR');
        $o = $this->commandePayee($client, ['loyalty_customer_code' => 'ADMINCOD']);

        $this->valider($o);

        $this->assertSame(0, $this->solde($admin), 'compte de l\'équipe : aucun point par le code');
        $this->assertSame(185, $this->solde($client), 'repli sur le vrai client de la commande');
    }

    /** Un client créé au comptoir (invité fidélité, sans rôle) reste crédité par son code. */
    public function test_un_client_du_comptoir_sans_role_reste_credite_par_son_code(): void
    {
        $invite = User::factory()->create(['branch_id' => 0, 'loyalty_points' => 0, 'loyalty_code' => 'INVITE01', 'is_guest' => \App\Enums\Ask::YES]);
        $ancien = User::factory()->create(['branch_id' => 0, 'loyalty_points' => 0, 'loyalty_code' => 'ANCIEN01']); // sans rôle ni statut invité
        $caissier = User::factory()->create(['branch_id' => $this->branch->id]);
        $caissier->assignRole('POS Operator');

        foreach ([$invite, $ancien] as $u) {
            $o = $this->commandePayee($caissier, ['loyalty_customer_code' => $u->loyalty_code, 'source_surface' => 'pos', 'order_type' => OrderType::POS]);
            $this->valider($o);
            $this->assertSame(185, $this->solde($u), $u->loyalty_code . ' : client du comptoir crédité');
        }
    }
}
