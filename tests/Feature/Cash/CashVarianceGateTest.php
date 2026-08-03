<?php

namespace Tests\Feature\Cash;

use App\Exceptions\CashVarianceRequiresApprovalException;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [Sprint 1D / F-4 — 2026-05-16] Cash variance gate coverage.
 *
 * Verrouille :
 *   - variance = 0          → OK, pas de reason exigée
 *   - |variance| <= seuil   → OK, pas de reason exigée
 *   - |variance| >  seuil sans reason          → 422 CASH_VARIANCE_REASON_REQUIRED
 *   - |variance| >  seuil + reason + cashier   → 422 CASH_VARIANCE_MANAGER_APPROVAL_REQUIRED
 *   - |variance| >  seuil + reason + manager   → OK, variance_reason persistée
 *   - reason trop long (>255)                  → 422 (validation)
 *
 * Threshold configurable via `cash.variance_threshold_eur` (env override).
 */
class CashVarianceGateTest extends TestCase
{
    use RefreshDatabase;

    private CashDrawerService $service;
    private Branch $branch;
    private User $cashier;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        $this->service = new CashDrawerService();
        $this->branch = Branch::factory()->create();

        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');

        $this->manager = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager->assignRole('Branch Manager');

        // Default config — explicit so failing env defaults don't poison the suite.
        Config::set('cash.variance_threshold_eur', 2.00);
        Config::set('cash.variance_manager_approval_required', true);
        Config::set('cash.variance_override_permission', 'cash.reconcile.variance.override');
        Config::set('cash.variance_reason_max_length', 255);
    }

    private function openCloseWith(float $opening, float $movementIn, float $closing): CashDrawerSession
    {
        $this->actingAs($this->cashier);
        $session = $this->service->openSession($this->branch->id, $this->cashier->id, $opening);
        if ($movementIn > 0) {
            $this->service->recordMovement(
                $session->id,
                CashMovement::TYPE_ORDER_PAYMENT,
                $movementIn,
                CashMovement::DIRECTION_IN,
            );
        }
        $this->service->closeSession($session->id, $closing);
        return $session->refresh();
    }

    /** Variance 0 → reconcile direct, pas de reason exigée */
    public function test_reconcile_zero_variance_succeeds_without_reason(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 100.00); // expected 100, closing 100 → variance 0

        $this->actingAs($this->cashier);
        $result = $this->service->reconcileSession($session->id);

        $this->assertSame(0.00, $result['variance']);
        $this->assertSame(CashDrawerSession::STATUS_RECONCILED, $result['session']->status);
        $this->assertNull($result['session']->variance_reason);
    }

    /** |variance| <= seuil → OK même sans reason */
    public function test_reconcile_variance_under_threshold_succeeds_without_reason(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 101.50); // variance = +1.50 (<=2)

        $this->actingAs($this->cashier);
        $result = $this->service->reconcileSession($session->id);

        $this->assertSame(1.50, $result['variance']);
        $this->assertSame(CashDrawerSession::STATUS_RECONCILED, $result['session']->status);
    }

    /** |variance| > seuil sans reason → CashVarianceRequiresApprovalException (CODE_REASON_REQUIRED) */
    public function test_reconcile_above_threshold_without_reason_throws_reason_required(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 105.00); // variance +5 > 2

        $this->actingAs($this->cashier);

        try {
            $this->service->reconcileSession($session->id);
            $this->fail('Expected CashVarianceRequiresApprovalException');
        } catch (CashVarianceRequiresApprovalException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(CashVarianceRequiresApprovalException::CODE_REASON_REQUIRED, $e->getErrorCode());
            $this->assertSame(5.00, $e->getVariance());
            $this->assertSame(2.00, $e->getThreshold());
        }

        // Session must remain in CLOSED state, not advanced to RECONCILED.
        $session->refresh();
        $this->assertSame(CashDrawerSession::STATUS_CLOSED, $session->status);
        $this->assertNull($session->variance);
    }

    /** |variance| > seuil + reason mais user n'a pas perm → CODE_MANAGER_APPROVAL */
    public function test_reconcile_above_threshold_with_reason_but_cashier_throws_manager_approval(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 95.00); // variance -5 > 2 absolute

        $this->actingAs($this->cashier);

        try {
            $this->service->reconcileSession($session->id, 'cashier short on 5€ — accidental coin drop');
            $this->fail('Expected CashVarianceRequiresApprovalException');
        } catch (CashVarianceRequiresApprovalException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(
                CashVarianceRequiresApprovalException::CODE_MANAGER_APPROVAL,
                $e->getErrorCode()
            );
            $this->assertSame(-5.00, $e->getVariance());
        }

        $session->refresh();
        $this->assertSame(CashDrawerSession::STATUS_CLOSED, $session->status);
    }

    /** |variance| > seuil + reason + manager → OK + variance_reason persistée */
    public function test_reconcile_above_threshold_with_reason_and_manager_succeeds(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 110.00); // variance +10 > 2

        // Cashier closed, but manager reconciles with explicit approval.
        $this->actingAs($this->manager);
        $result = $this->service->reconcileSession(
            $session->id,
            'Surplus 10€ identified after till re-count by floor manager',
            $this->manager,
        );

        $this->assertSame(10.00, $result['variance']);
        $this->assertSame(CashDrawerSession::STATUS_RECONCILED, $result['session']->status);
        $this->assertSame(
            'Surplus 10€ identified after till re-count by floor manager',
            $result['session']->variance_reason,
        );
    }

    /** Trimming: reason " " (whitespace only) treated as missing → CODE_REASON_REQUIRED */
    public function test_reconcile_above_threshold_whitespace_reason_is_treated_as_missing(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 105.00);

        $this->actingAs($this->manager);

        try {
            $this->service->reconcileSession($session->id, '    ', $this->manager);
            $this->fail('Expected CashVarianceRequiresApprovalException');
        } catch (CashVarianceRequiresApprovalException $e) {
            $this->assertSame(CashVarianceRequiresApprovalException::CODE_REASON_REQUIRED, $e->getErrorCode());
        }
    }

    /** Reason > 255 chars → 422 */
    public function test_reconcile_above_threshold_with_overlong_reason_throws_422(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 105.00);
        $this->actingAs($this->manager);

        $longReason = str_repeat('x', 256);
        try {
            $this->service->reconcileSession($session->id, $longReason, $this->manager);
            $this->fail('Expected 422');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('variance_reason', $e->getMessage());
        }
    }

    /**
     * variance_manager_approval_required = false → seul reason est exigée;
     * permission devient optionnelle (rollback emergency switch).
     */
    public function test_reconcile_with_approval_required_false_accepts_reason_only(): void
    {
        Config::set('cash.variance_manager_approval_required', false);

        $session = $this->openCloseWith(100.00, 0.00, 110.00);
        $this->actingAs($this->cashier);

        $result = $this->service->reconcileSession(
            $session->id,
            'Approved by phone — manager not on site',
        );

        $this->assertSame(10.00, $result['variance']);
        $this->assertSame('Approved by phone — manager not on site', $result['session']->variance_reason);
    }

    /** Reason is optionally persisted even when under threshold */
    public function test_reconcile_persists_reason_when_provided_even_under_threshold(): void
    {
        $session = $this->openCloseWith(100.00, 0.00, 101.00); // variance +1, under

        $this->actingAs($this->cashier);
        $result = $this->service->reconcileSession($session->id, 'note: 1€ tip from regular');

        $this->assertSame(1.00, $result['variance']);
        $this->assertSame('note: 1€ tip from regular', $result['session']->variance_reason);
    }
}
