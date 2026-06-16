<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * [AUDIT-F-003 / Sub-task 2] CashDrawerService TDD coverage.
 *
 * Cible les 5 invariants I1..I5 définis dans le service + idempotency.
 */
class CashDrawerServiceTest extends TestCase
{
    use RefreshDatabase;

    private CashDrawerService $service;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CashDrawerService();
        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($this->user);

        // [Sprint 1D / F-4] These legacy tests target the I1..I5 + idempotency
        // invariants and pre-date the variance gate. Neutralise the gate
        // (threshold 999€, approval not required) so existing test data
        // (variances around 5€) continues to exercise the original arithmetic
        // paths. Dedicated coverage of the gate lives in
        // tests/Feature/Cash/CashVarianceGateTest.php.
        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
    }

    /** I1 — open session créée OK */
    public function test_open_session_creates_open_session_with_opening_amount(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);

        $this->assertSame(CashDrawerSession::STATUS_OPEN, $session->status);
        $this->assertSame('100.00', (string) $session->opening_amount);
        $this->assertSame($this->branch->id, $session->branch_id);
        $this->assertSame($this->user->id, $session->opened_by_user_id);
        $this->assertNotNull($session->opened_at);
    }

    /** I1 — pas de double session OPEN */
    public function test_open_session_rejects_409_if_already_open_for_user_branch(): void
    {
        $this->service->openSession($this->branch->id, $this->user->id, 50.00);

        try {
            $this->service->openSession($this->branch->id, $this->user->id, 75.00);
            $this->fail('Expected HttpException 409');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_open_session_rejects_negative_opening_amount(): void
    {
        try {
            $this->service->openSession($this->branch->id, $this->user->id, -1.00);
            $this->fail('Expected HttpException 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** I2 — close transitionne OPEN → CLOSED */
    public function test_close_session_marks_closed_and_records_closing_amount(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);

        $closed = $this->service->closeSession($session->id, 250.00);

        $this->assertSame(CashDrawerSession::STATUS_CLOSED, $closed->status);
        $this->assertSame('250.00', (string) $closed->closing_amount);
        $this->assertNotNull($closed->closed_at);
    }

    /** I2 — close idempotent sur déjà closed */
    public function test_close_session_is_idempotent_on_already_closed(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        $this->service->closeSession($session->id, 250.00);

        $second = $this->service->closeSession($session->id, 999.00); // ignored
        $this->assertSame('250.00', (string) $second->closing_amount);
    }

    /** I2 — close refusé sur reconciled */
    public function test_close_session_rejects_on_reconciled_session(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        $this->service->closeSession($session->id, 250.00);
        $this->service->reconcileSession($session->id);

        try {
            $this->service->closeSession($session->id, 300.00);
            $this->fail('Expected HttpException 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** I3 — reconcile calcule expected + variance correctement */
    public function test_reconcile_session_computes_expected_and_variance(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);

        // 3 ventes cash = +30, 1 cashback = -5  =>  expected = 100 + 25 = 125
        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 10.00, CashMovement::DIRECTION_IN);
        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 10.00, CashMovement::DIRECTION_IN);
        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 10.00, CashMovement::DIRECTION_IN);
        $this->service->recordMovement($session->id, CashMovement::TYPE_CASHBACK, 5.00, CashMovement::DIRECTION_OUT);

        $this->service->closeSession($session->id, 130.00); // counted physically

        $result = $this->service->reconcileSession($session->id);

        $this->assertSame(125.00, $result['expected']);
        $this->assertSame(5.00, $result['variance']); // +5€ surplus
        $this->assertSame(CashDrawerSession::STATUS_RECONCILED, $result['session']->status);
    }

    /** [CAISSE-S1] SSOT drift-lock: the extracted expectedCashForSession() read-model (used by the
     *  live cash-overview display) returns exactly the value reconcileSession gates the variance against. */
    public function test_expected_cash_for_session_matches_reconcile_expected(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 10.00, CashMovement::DIRECTION_IN);
        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 10.00, CashMovement::DIRECTION_IN);
        $this->service->recordMovement($session->id, CashMovement::TYPE_CASHBACK, 5.00, CashMovement::DIRECTION_OUT);

        $readModel = $this->service->expectedCashForSession($session->fresh()); // the overview display formula

        $this->assertSame(115.00, $readModel); // 100 + (10 + 10 - 5)
        $this->service->closeSession($session->id, $readModel);
        $this->assertSame($readModel, $this->service->reconcileSession($session->id)['expected']); // display == gate
    }

    /** I3 — variance négative possible */
    public function test_reconcile_session_supports_negative_variance(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 50.00);

        $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 20.00, CashMovement::DIRECTION_IN);
        $this->service->closeSession($session->id, 65.00); // expected 70, manque 5

        $result = $this->service->reconcileSession($session->id);
        $this->assertSame(70.00, $result['expected']);
        $this->assertSame(-5.00, $result['variance']);
    }

    /** I3 — reconcile idempotent */
    public function test_reconcile_session_is_idempotent(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        $this->service->closeSession($session->id, 100.00);
        $first = $this->service->reconcileSession($session->id);
        $second = $this->service->reconcileSession($session->id);

        $this->assertSame($first['expected'], $second['expected']);
        $this->assertSame($first['variance'], $second['variance']);
    }

    /** I3 — reconcile refusé sur OPEN */
    public function test_reconcile_session_rejects_on_open_session(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        try {
            $this->service->reconcileSession($session->id);
            $this->fail('Expected HttpException 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** I4 — recordMovement OK sur OPEN */
    public function test_record_movement_creates_movement_on_open_session(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);

        $movement = $this->service->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            42.50,
            CashMovement::DIRECTION_IN,
            orderId: 7,
        );

        $this->assertNotNull($movement);
        $this->assertSame($session->id, $movement->cash_drawer_session_id);
        $this->assertSame($this->branch->id, $movement->branch_id);
        $this->assertSame(7, $movement->order_id);
        $this->assertSame('42.50', (string) $movement->amount);
    }

    /** I4 — best-effort: session closed → null + log warning, sans throw */
    public function test_record_movement_returns_null_on_closed_session_in_best_effort_mode(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        $this->service->closeSession($session->id, 100.00);

        $movement = $this->service->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            10.00,
            CashMovement::DIRECTION_IN,
        );

        $this->assertNull($movement);
        $this->assertSame(0, CashMovement::query()->count());
    }

    /** I4 — strict mode: session closed → 422 */
    public function test_record_movement_throws_on_closed_session_in_strict_mode(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        $this->service->closeSession($session->id, 100.00);

        try {
            $this->service->recordMovement(
                $session->id,
                CashMovement::TYPE_ORDER_PAYMENT,
                10.00,
                CashMovement::DIRECTION_IN,
                strict: true,
            );
            $this->fail('Expected HttpException 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** I5 — type whitelist strict */
    public function test_record_movement_rejects_invalid_type_in_strict_mode(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        try {
            $this->service->recordMovement($session->id, 'fake_type', 10.00, 'in', strict: true);
            $this->fail('Expected HttpException 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** I5 — direction whitelist */
    public function test_record_movement_rejects_invalid_direction_in_strict_mode(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);
        try {
            $this->service->recordMovement($session->id, CashMovement::TYPE_ORDER_PAYMENT, 10.00, 'sideways', strict: true);
            $this->fail('Expected HttpException 422');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /** Helper findOpenSessionForUser */
    public function test_find_open_session_for_user_returns_open_only(): void
    {
        $session = $this->service->openSession($this->branch->id, $this->user->id, 100.00);

        $found = $this->service->findOpenSessionForUser($this->branch->id, $this->user->id);
        $this->assertNotNull($found);
        $this->assertSame($session->id, $found->id);

        $this->service->closeSession($session->id, 100.00);
        $foundAfterClose = $this->service->findOpenSessionForUser($this->branch->id, $this->user->id);
        $this->assertNull($foundAfterClose);
    }

    public function test_close_session_404_on_unknown_session_id(): void
    {
        try {
            $this->service->closeSession(99999, 100.00);
            $this->fail('Expected HttpException 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
