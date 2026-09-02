<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * [FIX-3 2026-08-25] Le bandeau « Réconciliation caisse » de /admin/cash-overview
 * doit être VRAI.
 *
 * Défaut constaté en base de dev (confirmé SQL par le superviseur adverse,
 * `reports/test-e2e/supervisor-caisse-2026-08-24/round-1/wave-D-findings.json`) :
 * `CashOverviewController::resolveOpenCashSession()` se terminait par
 * `orderByDesc('opened_at')->first()` SANS AUCUNE BORNE DE DATE, alors que 11
 * sessions `status=open` coexistent en base. Résultat : le bandeau affichait la
 * session #38 (ouverte le 2026-07-06, dernier mouvement le 2026-07-11) sur une
 * page datée du 2026-08-25, avec le mot « aujourd'hui », une heure nue sans
 * date, et strictement les mêmes chiffres quel que soit le filtre de période —
 * y compris sur une fenêtre à 0,00 € et 0 transaction.
 *
 * Les trois mensonges sont épinglés ici :
 *   1. mauvaise session      → test_banner_follows_the_drawer_that_actually_moved_cash_in_the_period
 *   2. insensibilité période → test_no_drawer_banner_for_a_period_without_any_drawer
 *   3. « aujourd'hui » faux  → test_banner_exposes_the_drawer_age_so_the_ui_can_date_it
 * + cohérence intra-page     → test_banner_cash_is_coherent_with_the_period_breakdown
 */
class CashOverviewDrawerPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $manager;
    private User $cashierActive;
    private User $cashierAbandoned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager->assignRole('Branch Manager');

        $this->cashierActive    = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierAbandoned = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    /**
     * Reproduit la base de dev : deux tiroirs `open` ouverts le même jour d'il y
     * a ~50 jours (l'index unique partiel n'autorise qu'un open par
     * (branch,user), d'où deux caissiers), dont :
     *  - #ACTIF   : ouvert EN PREMIER, mais c'est lui qui encaisse encore aujourd'hui
     *  - #ABANDON : ouvert EN DERNIER (donc gagnant du `orderByDesc('opened_at')`),
     *               avec un seul mouvement, vieux de 45 jours.
     *
     * @return array{0:CashDrawerSession,1:CashDrawerSession}
     */
    private function seedTwoOpenDrawers(): array
    {
        $active = CashDrawerSession::create([
            'branch_id'         => $this->branch->id,
            'opened_by_user_id' => $this->cashierActive->id,
            'opened_at'         => Carbon::now('Europe/Paris')->subDays(50)->setTime(19, 26),
            'opening_amount'    => 100.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);

        $abandoned = CashDrawerSession::create([
            'branch_id'         => $this->branch->id,
            'opened_by_user_id' => $this->cashierAbandoned->id,
            // Ouvert APRÈS l'actif → c'est lui que renvoyait orderByDesc('opened_at').
            'opened_at'         => Carbon::now('Europe/Paris')->subDays(50)->setTime(20, 56),
            'opening_amount'    => 50.00,
            'status'            => CashDrawerSession::STATUS_OPEN,
        ]);

        // Unique mouvement du tiroir abandonné : 8,50 € il y a 45 jours.
        $this->makeMovement($abandoned, 8.50, Carbon::now('Europe/Paris')->subDays(45)->setTime(14, 29));

        // Le tiroir ACTIF encaisse aujourd'hui : 2 × 2,50 €.
        $this->makeMovement($active, 2.50, Carbon::now('Europe/Paris')->startOfDay()->addHours(3)->addMinutes(55));
        $this->makeMovement($active, 2.50, Carbon::now('Europe/Paris')->startOfDay()->addHours(4)->addMinutes(27));

        return [$active, $abandoned];
    }

    private function makeMovement(CashDrawerSession $session, float $amount, Carbon $at, ?int $orderId = null): CashMovement
    {
        $mv = CashMovement::create([
            'cash_drawer_session_id' => $session->id,
            'branch_id'              => $session->branch_id,
            'order_id'               => $orderId,
            'type'                   => CashMovement::TYPE_ORDER_PAYMENT,
            'direction'              => CashMovement::DIRECTION_IN,
            'amount'                 => $amount,
        ]);
        CashMovement::query()->where('id', $mv->id)
            ->update(['created_at' => $at, 'updated_at' => $at]);

        return $mv->fresh();
    }

    private function makeCashOrderTransaction(float $amount, Carbon $createdAt): Transaction
    {
        $order = Order::create([
            'branch_id'       => $this->branch->id,
            'user_id'         => $this->cashierActive->id,
            'queue_number'    => random_int(100, 999),
            'order_serial_no' => 'FIX3-'.uniqid(),
            'subtotal'        => $amount,
            'total'           => $amount,
            'total_tax'       => 0,
            'discount'        => 0,
            'delivery_charge' => 0,
            'order_type'      => OrderType::POS,
            'source_surface'  => 'pos',
            'status'          => 1,
            'payment_status'  => 1,
            'created_at'      => $createdAt,
            'updated_at'      => $createdAt,
        ]);

        $txn = Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'TXN-FIX3-'.$order->id,
            'amount'         => $amount,
            'payment_method' => 'cash',
            'sign'           => '+',
            'type'           => 'payment',
        ]);
        Transaction::query()->where('id', $txn->id)
            ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $txn->fresh();
    }

    /**
     * MENSONGE 1 — le bandeau montrait le tiroir le plus récemment OUVERT, pas
     * celui qui encaisse réellement sur la période affichée.
     */
    public function test_banner_follows_the_drawer_that_actually_moved_cash_in_the_period(): void
    {
        [$active, $abandoned] = $this->seedTwoOpenDrawers();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertNotNull($response->json('cash_session'), 'Un tiroir encaisse aujourd\'hui : le bandeau doit exister.');
        $this->assertSame(
            $active->id,
            $response->json('cash_session.id'),
            'Le bandeau doit suivre le tiroir qui a encaissé sur la période, pas le dernier ouvert (#'.$abandoned->id.', abandonné depuis 45 jours).'
        );
        $this->assertEquals(100.00, $response->json('cash_session.opening_amount'));
        // 100 (fond) + 5,00 (2 × 2,50 encaissés aujourd'hui) — et surtout PAS 58,50.
        $this->assertEquals(105.00, $response->json('cash_session.expected_cash'));
    }

    /**
     * MENSONGE 2 — insensibilité totale au filtre de période : une fenêtre sans
     * un centime affichait quand même 8,50 € encaissés / 58,50 € au tiroir.
     */
    public function test_no_drawer_banner_for_a_period_without_any_drawer(): void
    {
        $this->seedTwoOpenDrawers();

        $from = Carbon::now('Europe/Paris')->subDays(200)->toDateString();
        $to   = Carbon::now('Europe/Paris')->subDays(199)->toDateString();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/admin/cash-overview?from={$from}&to={$to}");

        $response->assertOk();
        $this->assertEquals(0.0, (float) $response->json('summary.total'), 'Fenêtre témoin : aucune transaction.');
        $this->assertNull(
            $response->json('cash_session'),
            'Aucun tiroir n\'était ouvert NI actif sur cette période — le bandeau doit se taire, pas afficher les chiffres d\'un autre mois.'
        );
    }

    /**
     * MENSONGE 3 — le libellé « Espèces encaissées aujourd'hui » sur un tiroir
     * vieux de 50 jours, avec une heure nue (`formatTime`) qui masque
     * l'ancienneté. Le payload doit porter de quoi DATER le tiroir à l'écran.
     */
    public function test_banner_exposes_the_drawer_age_so_the_ui_can_date_it(): void
    {
        $this->seedTwoOpenDrawers();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertFalse(
            $response->json('cash_session.opened_today'),
            'Le tiroir a été ouvert il y a 50 jours : le bandeau ne doit jamais le présenter comme ouvert aujourd\'hui.'
        );
        $this->assertGreaterThanOrEqual(
            49,
            (int) $response->json('cash_session.age_days'),
            'L\'ancienneté du tiroir doit être exposée pour être affichée (« ouvert il y a N jours »).'
        );
        $this->assertFalse(
            $response->json('cash_session.opened_in_period'),
            'Le tiroir n\'a pas été ouvert dans la période affichée — le bandeau doit le dire.'
        );
    }

    /**
     * Borner le bandeau à la période ne doit pas troquer un mensonge contre une
     * omission : les tiroirs laissés ouverts (10 en base de dev, 650 € de fonds
     * immobilisés) doivent être NOMMÉS et DATÉS, pas effacés de l'écran.
     */
    public function test_abandoned_drawers_are_surfaced_instead_of_impersonating_today(): void
    {
        [$active, $abandoned] = $this->seedTwoOpenDrawers();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();
        $this->assertSame(1, $response->json('stale_open_drawers.count'));
        $this->assertContains($abandoned->id, $response->json('stale_open_drawers.ids'));
        $this->assertNotContains(
            $active->id,
            $response->json('stale_open_drawers.ids'),
            'Le tiroir affiché dans le bandeau ne doit pas être re-signalé comme abandonné.'
        );
        $this->assertStringContainsString('sans aucune activité', (string) $response->json('stale_open_drawers.message'));
    }

    /**
     * COHÉRENCE INTRA-PAGE — le bandeau et la « Répartition par mode » de la
     * MÊME page ne doivent plus se contredire (8,50 € contre 25,00 € à 100 px
     * d'écart sur la capture 02 du superviseur).
     */
    public function test_banner_cash_is_coherent_with_the_period_breakdown(): void
    {
        [$active] = $this->seedTwoOpenDrawers();

        // Les 2 × 2,50 € du tiroir actif existent aussi côté transactions.
        $this->makeCashOrderTransaction(2.50, Carbon::now('Europe/Paris')->startOfDay()->addHours(3)->addMinutes(55));
        $this->makeCashOrderTransaction(2.50, Carbon::now('Europe/Paris')->startOfDay()->addHours(4)->addMinutes(27));

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/admin/cash-overview');

        $response->assertOk();

        $breakdownCash = (float) $response->json('summary.by_mode.cash.total');
        $this->assertEquals(5.00, $breakdownCash, 'Témoin : la répartition par mode voit 5,00 € d\'espèces sur la période.');

        $this->assertEquals(
            $breakdownCash,
            (float) $response->json('cash_session.cash_collected_in_period'),
            'Le montant encaissé annoncé par le bandeau doit correspondre aux espèces de la période affichée sur la même page.'
        );
        $this->assertSame($active->id, $response->json('cash_session.id'));
    }
}
