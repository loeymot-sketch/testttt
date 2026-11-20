<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [OWNER 2026-11-06 · bout en bout] Le parcours des points vu par le CLIENT, par les VRAIES routes :
 * la caisse valide (/api/admin/online-order/change-status), encaisse (/api/admin/pos/counter-collect/…/confirm)
 * ou annule ; le client lit « Mes commandes » (/api/frontend/order), le suivi (/api/frontend/order/show) et son
 * profil (/api/profile). À chaque étape : rien en attente, crédit à la validation d'une commande payée (ou à
 * l'encaissement d'une commande à régler sur place), aucun doublon, reprise à l'annulation, et JAMAIS de champ
 * « dépensé » dans ce que le client reçoit.
 */
class ParcoursPointsBoutEnBoutTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);
        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => 10, 'loyalty_points_for_1_euro_discount' => 100, 'loyalty_min_redeem_points' => 50,
        ]);
        // Le parcours fidélité, pas la chaîne HMAC (même montage que WebOrderCounterCollectableTest).
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog { return new \App\Models\AuditLog(); }
        });
        $this->app->instance(FiscalSequenceService::class, new class(8000) extends FiscalSequenceService {
            private int $c;
            public function __construct(int $s) { $this->c = $s; }
            public function next(int $branchId): int { return ++$this->c; }
        });
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    private function client(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE,
            'loyalty_points' => 0, 'loyalty_code' => 'BEB' . strtoupper(substr(uniqid(), -6))]);
        $u->assignRole('Customer'); // par NOM : dans le harnais l'id 2 est « Chef » (CustomerAccount::isCustomer)

        return $u->fresh();
    }

    private function caissier(): User
    {
        $u = User::factory()->create(['branch_id' => $this->branch->id]);
        $u->assignRole('POS Operator');
        $u->givePermissionTo('online-orders');

        return $u->fresh();
    }

    private function commandeWeb(User $client, int $paiement, float $total = 18.50): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $client->id, 'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB, 'source_surface' => 'web',
            'payment_method' => $paiement === PaymentStatus::PAID ? PaymentGateway::CARD : PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => $paiement, 'pos_payment_method' => null, 'status' => OrderStatus::PENDING,
            'total' => $total, 'subtotal' => $total,
        ]);
    }

    private function caisse(User $op, Order $o, int $statut, array $extra = []): void
    {
        // Chaque acteur envoie ses propres en-têtes (aucun reste d'une requête précédente).
        $this->flushHeaders();
        $r = $this->actingAs($op, 'sanctum')->withHeaders(['x-api-key' => '123456', 'X-Idempotency-Key' => 'st-' . bin2hex(random_bytes(6))])
            ->postJson("/api/admin/online-order/change-status/{$o->id}", ['status' => $statut] + $extra);
        $r->assertStatus(200);
    }

    /** @return array{liste: array, suivi: array, solde: int} */
    private function vuClient(User $client, Order $o): array
    {
        // Relu à chaque lecture : en production chaque requête recharge le client (solde à jour).
        $this->flushHeaders();
        $this->actingAs($client->fresh(), 'sanctum');
        $liste = collect($this->withHeader('x-api-key', '123456')->getJson('/api/frontend/order?limit=50')->assertOk()->json('data'))
            ->firstWhere('id', $o->id);
        $suivi = $this->withHeader('x-api-key', '123456')->getJson('/api/frontend/order/show/' . $o->id)->assertOk()->json('data');
        $profil = $this->withHeader('x-api-key', '123456')->getJson('/api/profile')->assertOk()->json('data');

        foreach ([$liste, $suivi, $profil] as $corps) {
            $this->assertDoesNotMatchRegularExpression('/spent|depens|dépens|lifetime|total_spent/i', json_encode(array_keys((array) $corps)),
                'aucun champ « dépensé » dans ce que reçoit le client');
        }

        return ['liste' => $liste, 'suivi' => $suivi, 'solde' => (int) ($profil['loyalty_points'] ?? -1)];
    }

    public function test_commande_payee_en_ligne_points_des_la_validation_un_seul_credit_jusqu_a_la_remise(): void
    {
        $client = $this->client();
        $op = $this->caissier();
        $o = $this->commandeWeb($client, PaymentStatus::PAID);

        $v = $this->vuClient($client, $o);
        $this->assertSame(0, $v['liste']['loyalty_points_awarded'], 'en attente : aucun point');
        $this->assertSame(0, $v['solde']);

        $this->caisse($op, $o, OrderStatus::ACCEPT);
        $v = $this->vuClient($client, $o);
        $this->assertSame(185, $v['liste']['loyalty_points_awarded'], 'validée : 18,50 € × 10 = 185 points, visibles tout de suite');
        $this->assertSame(185, $v['suivi']['loyalty_points_awarded']);
        $this->assertSame(185, $v['solde']);

        foreach ([OrderStatus::PREPARING, OrderStatus::PREPARED, OrderStatus::DELIVERED] as $statut) {
            $this->caisse($op, $o, $statut);
        }
        $v = $this->vuClient($client, $o);
        $this->assertSame(185, $v['solde'], 'en préparation → prête → remise : aucun second crédit');
        $this->assertSame(1, DB::table('loyalty_transactions')->where('order_id', $o->id)->where('type', 'earn')->count());
    }

    public function test_payer_sur_place_rien_a_la_validation_credit_a_l_encaissement(): void
    {
        $client = $this->client();
        $op = $this->caissier();
        $o = $this->commandeWeb($client, PaymentStatus::UNPAID);

        $this->caisse($op, $o, OrderStatus::ACCEPT);
        $v = $this->vuClient($client, $o);
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $v['liste']['payment_status'], 'pré-condition : à régler au comptoir');
        $this->assertSame(0, $v['liste']['loyalty_points_awarded'], 'validée mais pas réglée : aucun point');
        $this->assertSame(0, $v['solde']);

        $this->flushHeaders();
        $this->actingAs($op, 'sanctum')->withHeaders(['x-api-key' => '123456', 'X-Idempotency-Key' => 'col-' . bin2hex(random_bytes(6))])
            ->postJson("/api/admin/pos/counter-collect/{$o->id}/confirm", ['mode' => PosPaymentMethod::CASH, 'received' => 20.00])
            ->assertStatus(200);

        $v = $this->vuClient($client, $o);
        $this->assertSame(185, $v['liste']['loyalty_points_awarded'], 'réglée au comptoir : les points tombent');
        $this->assertSame(185, $v['solde']);
    }

    public function test_annulee_apres_validation_points_repris_et_plus_affiches(): void
    {
        $client = $this->client();
        $op = $this->caissier();
        $o = $this->commandeWeb($client, PaymentStatus::PAID);
        $this->caisse($op, $o, OrderStatus::ACCEPT);
        $this->assertSame(185, $this->vuClient($client, $o)['solde']);

        // L'annulation est un geste de responsable (un opérateur de caisse reçoit 403) : l'administrateur annule.
        $admin = User::factory()->create(['branch_id' => $this->branch->id]);
        $admin->assignRole('Admin');
        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo('pos-refund'); // annuler une commande PAYÉE = geste de remboursement
        $this->caisse($admin->fresh(), $o, OrderStatus::CANCELED, ['reason' => 'Rupture de stock']);

        $v = $this->vuClient($client, $o);
        $this->assertSame(0, $v['solde'], 'annulée : points repris');
        $this->assertSame(0, $v['liste']['loyalty_points_awarded'], 'annulée : aucun gain affiché');
        $this->assertSame(0, $v['suivi']['loyalty_points_awarded']);
    }
}
