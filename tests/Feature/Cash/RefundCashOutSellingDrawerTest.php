<?php

namespace Tests\Feature\Cash;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M3-103] Quand un responsable annule une vente PAYÉE en espèces, la sortie d'espèces était posée sur
 * la session de tiroir de CELUI QUI ANNULE (findOpenSessionForUser(Auth::id())) : en base locale, l'IN de la vente
 * sur la session 36 du caissier, l'OUT sur la session 32 de l'admin (497,70 € en 21 annulations). Sans session ouverte
 * pour l'annulant, la sortie était perdue (une ligne de journal).
 *
 * Règle (CashDrawerService::findSessionForCashOut) : la sortie va
 *   1. sur la session qui porte l'ENTRÉE de la vente, si elle est encore ouverte ;
 *   2. sinon sur la session ouverte du caissier qui a encaissé (nouveau service, même tiroir) ;
 *   3. sinon sur la session ouverte de celui qui rend l'argent ;
 *   4. sinon : annulation refusée (422, message clair) — jamais de sortie perdue.
 */
class RefundCashOutSellingDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $caissier;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('pos.simulation_hardware', false);
        foreach (['pos-refund', 'pos-orders', 'pos', 'online-orders'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }

        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });
        $this->app->instance(FiscalSequenceService::class, new class extends FiscalSequenceService {
            private int $c = 9500;

            public function __construct() {}

            public function next(int $branchId): int
            {
                return ++$this->c;
            }
        });

        $this->branch = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->caissier->assignRole('POS Operator');
        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo(['pos-refund', 'pos-orders', 'pos', 'online-orders']);
    }

    private function sessionTiroir(User $owner, string $status = CashDrawerSession::STATUS_OPEN): CashDrawerSession
    {
        return CashDrawerSession::create([
            'branch_id' => $this->branch->id,
            'opened_by_user_id' => $owner->id,
            'opened_at' => now(),
            'opening_amount' => 100.0,
            'status' => $status,
        ]);
    }

    /** Vente caisse espèces payée par le caissier sur $session (IN réel), avec ou sans ligne Transaction. */
    private function venteEspeces(CashDrawerSession $session, bool $avecTransaction): Order
    {
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->caissier->id,
            'order_type' => OrderType::POS,
            'source_surface' => 'pos',
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal' => 10.20,
            'total' => 10.20,
            'total_tax' => 0,
            'discount' => 0,
        ]);
        if ($avecTransaction) {
            Transaction::create([
                'order_id' => $order->id, 'transaction_no' => 'TXN-M3103-'.$order->id,
                'amount' => 10.20, 'payment_method' => 'cash', 'sign' => '+', 'type' => 'payment',
            ]);
        }
        CashMovement::create([
            'branch_id' => $this->branch->id,
            'order_id' => $order->id,
            'cash_drawer_session_id' => $session->id,
            'type' => CashMovement::TYPE_ORDER_PAYMENT,
            'direction' => CashMovement::DIRECTION_IN,
            'amount' => 10.20,
        ]);

        return $order->fresh();
    }

    /** @return array<int, float> session_id => montant sorti pour cette commande */
    private function sorties(Order $order): array
    {
        return CashMovement::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->get()
            ->mapWithKeys(fn (CashMovement $m) => [(int) $m->cash_drawer_session_id => round((float) $m->amount, 2)])
            ->all();
    }

    private function annulerParAdmin(Order $order)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/pos-order/change-status/'.$order->id, [
                'status' => OrderStatus::CANCELED,
                'reason' => 'Erreur de saisie',
            ]);
    }

    public function test_cancel_by_admin_of_a_direct_cash_sale_takes_cash_out_of_the_selling_cashiers_session(): void
    {
        $sessionCaissier = $this->sessionTiroir($this->caissier);
        $sessionAdmin = $this->sessionTiroir($this->admin);
        $order = $this->venteEspeces($sessionCaissier, false);

        $this->annulerParAdmin($order)->assertSuccessful();

        $this->assertSame([$sessionCaissier->id => 10.20], $this->sorties($order),
            "La sortie d'annulation doit aller sur la session qui porte l'entrée de la vente (caissier), pas sur celle de l'admin #{$sessionAdmin->id}.");
    }

    public function test_cancel_by_admin_of_a_cash_sale_with_transaction_takes_cash_out_of_the_selling_cashiers_session(): void
    {
        $sessionCaissier = $this->sessionTiroir($this->caissier);
        $this->sessionTiroir($this->admin);
        $order = $this->venteEspeces($sessionCaissier, true);

        $this->annulerParAdmin($order)->assertSuccessful();

        $this->assertSame([$sessionCaissier->id => 10.20], $this->sorties($order));
    }

    public function test_cancel_when_the_annulling_admin_has_no_session_still_records_the_cash_out(): void
    {
        $sessionCaissier = $this->sessionTiroir($this->caissier);
        $order = $this->venteEspeces($sessionCaissier, false);

        $this->annulerParAdmin($order)->assertSuccessful();

        $this->assertSame([$sessionCaissier->id => 10.20], $this->sorties($order),
            "Sans session pour l'annulant, la sortie ne doit pas être perdue.");
    }

    public function test_selling_session_closed_goes_to_the_cashiers_new_open_session(): void
    {
        $ancienne = $this->sessionTiroir($this->caissier);
        $order = $this->venteEspeces($ancienne, false);
        $ancienne->update(['status' => CashDrawerSession::STATUS_CLOSED, 'closed_at' => now(), 'closing_amount' => 110.20]);
        $nouvelle = $this->sessionTiroir($this->caissier);
        $this->sessionTiroir($this->admin);

        $this->annulerParAdmin($order)->assertSuccessful();

        $this->assertSame([$nouvelle->id => 10.20], $this->sorties($order));
    }

    public function test_selling_session_closed_and_cashier_gone_goes_to_the_session_of_whoever_hands_the_cash_back(): void
    {
        $ancienne = $this->sessionTiroir($this->caissier);
        $order = $this->venteEspeces($ancienne, false);
        $ancienne->update(['status' => CashDrawerSession::STATUS_CLOSED, 'closed_at' => now(), 'closing_amount' => 110.20]);
        $sessionAdmin = $this->sessionTiroir($this->admin);

        $this->annulerParAdmin($order)->assertSuccessful();

        $this->assertSame([$sessionAdmin->id => 10.20], $this->sorties($order));
    }

    public function test_post_z_counter_entry_refund_by_admin_takes_cash_out_of_the_selling_cashiers_session(): void
    {
        $sessionCaissier = $this->sessionTiroir($this->caissier);
        $sessionAdmin = $this->sessionTiroir($this->admin);
        $order = $this->venteEspeces($sessionCaissier, false);
        $order->forceFill(['fiscal_sequence_no' => 700, 'created_at' => now()->subDay()->setTime(12, 0)])->save();
        \App\Models\ZReport::create([
            'branch_id' => $this->branch->id, 'sequence_no' => 1,
            'opened_at' => now()->subDay()->setTime(8, 0), 'closed_at' => now()->subDay()->setTime(20, 0),
            'status' => \App\Models\ZReport::STATUS_CLOSED,
        ]);

        $this->actingAs($this->admin, 'sanctum');
        $mirror = app(\App\Services\Order\RefundWithCounterEntryService::class)->execute($order->fresh(), 'Client remboursé le lendemain');

        $this->assertSame([$sessionCaissier->id => 10.20], $this->sorties($mirror),
            "Contre-écriture post-Z : la sortie va sur la session du caissier qui a encaissé, pas sur celle de l'admin #{$sessionAdmin->id}.");
    }

    public function test_no_open_session_anywhere_refuses_the_cash_cancellation_instead_of_losing_the_cash_out(): void
    {
        $ancienne = $this->sessionTiroir($this->caissier);
        $order = $this->venteEspeces($ancienne, true);
        $ancienne->update(['status' => CashDrawerSession::STATUS_CLOSED, 'closed_at' => now(), 'closing_amount' => 110.20]);

        $res = $this->annulerParAdmin($order);

        $res->assertStatus(422);
        $this->assertStringContainsString('caisse', mb_strtolower((string) $res->json('message')));
        $order->refresh();
        $this->assertSame(OrderStatus::PREPARING, (int) $order->status, "La vente ne doit pas être annulée sans sortie d'espèces enregistrée.");
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertSame([], $this->sorties($order));
        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count(),
            'Refus = retour arrière complet (aucun remboursement écrit).');
    }
}
