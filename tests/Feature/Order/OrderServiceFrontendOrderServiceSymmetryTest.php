<?php

namespace Tests\Feature\Order;

use App\Services\FrontendOrderService;
use App\Services\OrderService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Sentinel — Mission #2 Vague 2 action 2.4.
 *
 * Verrouille l'invariant FoodKing #5 : OrderService et FrontendOrderService
 * doivent rester symétriques sur les méthodes critiques de cycle de commande.
 *
 * Si une méthode est ajoutée/modifiée dans l'une mais pas l'autre, ce test
 * échoue et oblige une revue manuelle (pattern SYMMETRY_NOTE dans le plan).
 *
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md §2.4.
 * Référence audit : reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md.
 */
class OrderServiceFrontendOrderServiceSymmetryTest extends TestCase
{
    /**
     * Critical lifecycle methods that MUST exist in both services (same name,
     * declared on each class). Arity check uses required parameters only so
     * optional admin flags on OrderService (e.g. show(), changeStatus()) do
     * not false-positive.
     */
    private const SYMMETRIC_METHODS = [
        'myOrderStore',
        'show',
        'changeStatus',
    ];

    /**
     * Soft drift cap: |#public(declared) OrderService − FrontendOrderService|.
     * Baseline 2026-05-02: OrderService ≈ 20, FrontendOrderService ≈ 5 → delta ≈ 15.
     */
    private const PUBLIC_SURFACE_DRIFT_MAX_EXCLUSIVE = 20;

    public function test_both_services_expose_a_consistent_critical_method_surface(): void
    {
        $orderRefl = new ReflectionClass(OrderService::class);
        $frontendRefl = new ReflectionClass(FrontendOrderService::class);

        $orderPublic = $this->publicNonInheritedMethods($orderRefl);
        $frontendPublic = $this->publicNonInheritedMethods($frontendRefl);

        $missingFromFrontend = array_values(array_diff(self::SYMMETRIC_METHODS, $frontendPublic));
        $missingFromOrder = array_values(array_diff(self::SYMMETRIC_METHODS, $orderPublic));

        $this->assertSame(
            [],
            $missingFromFrontend,
            'Symmetry violation: methods declared symmetric are missing from FrontendOrderService: '
            . implode(', ', $missingFromFrontend)
        );

        $this->assertSame(
            [],
            $missingFromOrder,
            'Symmetry violation: methods declared symmetric are missing from OrderService: '
            . implode(', ', $missingFromOrder)
        );
    }

    public function test_symmetric_methods_have_compatible_required_parameter_counts(): void
    {
        $orderRefl = new ReflectionClass(OrderService::class);
        $frontendRefl = new ReflectionClass(FrontendOrderService::class);

        foreach (self::SYMMETRIC_METHODS as $methodName) {
            if (! $orderRefl->hasMethod($methodName) || ! $frontendRefl->hasMethod($methodName)) {
                continue;
            }

            $oReq = $orderRefl->getMethod($methodName)->getNumberOfRequiredParameters();
            $fReq = $frontendRefl->getMethod($methodName)->getNumberOfRequiredParameters();

            $this->assertSame(
                $oReq,
                $fReq,
                sprintf(
                    'Symmetry required-arity mismatch for %s(): OrderService=%d required vs FrontendOrderService=%d',
                    $methodName,
                    $oReq,
                    $fReq
                )
            );
        }
    }

    public function test_no_uncovered_drift_introduced_to_either_service(): void
    {
        $orderRefl = new ReflectionClass(OrderService::class);
        $frontendRefl = new ReflectionClass(FrontendOrderService::class);

        $orderCount = count($this->publicNonInheritedMethods($orderRefl));
        $frontendCount = count($this->publicNonInheritedMethods($frontendRefl));

        $this->assertGreaterThan(0, $orderCount, 'OrderService has zero public methods (anomaly).');
        $this->assertGreaterThan(0, $frontendCount, 'FrontendOrderService has zero public methods (anomaly).');

        $delta = abs($orderCount - $frontendCount);
        $this->assertLessThan(
            self::PUBLIC_SURFACE_DRIFT_MAX_EXCLUSIVE,
            $delta,
            sprintf(
                'Public surface drift between OrderService (%d) and FrontendOrderService (%d) exceeds %d. '
                . 'Review symmetry per FoodKing invariant #5 and add SYMMETRY_NOTE in plan if intentional.',
                $orderCount,
                $frontendCount,
                self::PUBLIC_SURFACE_DRIFT_MAX_EXCLUSIVE
            )
        );
    }

    /**
     * @return array<int, string>
     */
    private function publicNonInheritedMethods(ReflectionClass $refl): array
    {
        $declaring = $refl->getName();
        $names = [];

        foreach ($refl->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $declaring) {
                continue;
            }
            $name = $m->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }
}
