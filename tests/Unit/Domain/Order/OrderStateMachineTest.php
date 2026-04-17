<?php

namespace Tests\Unit\Domain\Order;

use App\Domain\Order\IllegalTransitionException;
use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;
use PHPUnit\Framework\TestCase;

class OrderStateMachineTest extends TestCase
{
    /**
     * Full legal transition matrix (no-user context), derived from ValidStatusTransition.
     * Used by both allows() and assertAllows() tests below — adding a state must update
     * this matrix AND OrderStateMachine::allStatuses() together.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    public static function legalPairsProvider(): array
    {
        return [
            // PENDING → {ACCEPT, CANCELED, REJECTED}
            'pending → accept'            => [OrderStatus::PENDING, OrderStatus::ACCEPT],
            'pending → canceled'          => [OrderStatus::PENDING, OrderStatus::CANCELED],
            'pending → rejected'          => [OrderStatus::PENDING, OrderStatus::REJECTED],

            // ACCEPT → {PREPARING, CANCELED}
            'accept → preparing'          => [OrderStatus::ACCEPT, OrderStatus::PREPARING],
            'accept → canceled'           => [OrderStatus::ACCEPT, OrderStatus::CANCELED],

            // PREPARING → {PREPARED, CANCELED}
            'preparing → prepared'        => [OrderStatus::PREPARING, OrderStatus::PREPARED],
            'preparing → canceled'        => [OrderStatus::PREPARING, OrderStatus::CANCELED],

            // PREPARED → {OUT_FOR_DELIVERY, DELIVERED}
            'prepared → out_for_delivery' => [OrderStatus::PREPARED, OrderStatus::OUT_FOR_DELIVERY],
            'prepared → delivered'        => [OrderStatus::PREPARED, OrderStatus::DELIVERED],

            // OUT_FOR_DELIVERY → DELIVERED
            'out_for_delivery → delivered' => [OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED],

            // DELIVERED → RETURNED
            'delivered → returned'        => [OrderStatus::DELIVERED, OrderStatus::RETURNED],
        ];
    }

    /**
     * @return array<int, array{0:int, 1:int}>
     */
    public static function illegalPairsProvider(): array
    {
        // Anything not listed in legalPairsProvider() OR identity pair is illegal (sans-user).
        $legal = [];
        foreach (self::legalPairsProvider() as $pair) {
            $legal[$pair[0] . '→' . $pair[1]] = true;
        }

        $cases = [];
        foreach (OrderStateMachine::allStatuses() as $from) {
            foreach (OrderStateMachine::allStatuses() as $to) {
                if ($from === $to) {
                    continue; // identity is always allowed, not illegal
                }
                if (isset($legal[$from . '→' . $to])) {
                    continue;
                }
                $cases[$from . '→' . $to] = [$from, $to];
            }
        }

        return $cases;
    }

    /**
     * @dataProvider legalPairsProvider
     */
    public function test_legal_transitions_are_allowed(int $from, int $to): void
    {
        $this->assertTrue(
            OrderStateMachine::allows($from, $to, null),
            "Expected legal transition {$from} → {$to} to be allowed"
        );
    }

    /**
     * @dataProvider illegalPairsProvider
     */
    public function test_illegal_transitions_are_rejected_without_user(int $from, int $to): void
    {
        $this->assertFalse(
            OrderStateMachine::allows($from, $to, null),
            "Expected illegal transition {$from} → {$to} to be rejected"
        );
    }

    public function test_identity_transition_is_always_allowed(): void
    {
        foreach (OrderStateMachine::allStatuses() as $status) {
            $this->assertTrue(
                OrderStateMachine::allows($status, $status, null),
                "Identity transition {$status} → {$status} must be allowed"
            );
        }
    }

    public function test_assert_allows_throws_on_illegal_transition(): void
    {
        $this->expectException(IllegalTransitionException::class);
        $this->expectExceptionMessageMatches('/1 to 22/');

        // PENDING (1) → RETURNED (22) is illegal without admin.
        OrderStateMachine::assertAllows(OrderStatus::PENDING, OrderStatus::RETURNED, null);
    }

    public function test_assert_allows_does_not_throw_on_legal_transition(): void
    {
        OrderStateMachine::assertAllows(OrderStatus::PENDING, OrderStatus::ACCEPT, null);
        $this->assertTrue(true);
    }

    public function test_pos_shortcut_allows_accept_to_delivered_for_pos_user(): void
    {
        $user = $this->makeUserStub(posPermission: true, adminRole: false);

        $this->assertTrue(
            OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::DELIVERED, $user)
        );
    }

    public function test_pos_shortcut_does_not_apply_to_non_pos_user(): void
    {
        $user = $this->makeUserStub(posPermission: false, adminRole: false);

        $this->assertFalse(
            OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::DELIVERED, $user)
        );
    }

    public function test_admin_can_transition_from_terminal_states(): void
    {
        $admin = $this->makeUserStub(posPermission: false, adminRole: true);

        $this->assertTrue(OrderStateMachine::allows(OrderStatus::CANCELED, OrderStatus::PENDING, $admin));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::REJECTED, OrderStatus::PENDING, $admin));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::RETURNED, OrderStatus::PENDING, $admin));
    }

    /**
     * Build a real class (not Mockery) that implements Authenticatable AND exposes
     * hasPermissionTo / hasRole so method_exists() in OrderStateMachine returns true.
     */
    private function makeUserStub(bool $posPermission, bool $adminRole): Authenticatable
    {
        return new class($posPermission, $adminRole) implements Authenticatable {
            public function __construct(private bool $posPermission, private bool $adminRole) {}
            public function hasPermissionTo(string $perm): bool
            {
                return $perm === 'pos' ? $this->posPermission : false;
            }
            public function hasRole(string $role): bool
            {
                return $role === 'Admin' ? $this->adminRole : false;
            }
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
            public function getAuthPassword() { return ''; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return ''; }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_requires_reason_is_true_for_cancel_reject_return(): void
    {
        $this->assertTrue(OrderStateMachine::requiresReason(OrderStatus::CANCELED));
        $this->assertTrue(OrderStateMachine::requiresReason(OrderStatus::REJECTED));
        $this->assertTrue(OrderStateMachine::requiresReason(OrderStatus::RETURNED));
    }

    public function test_requires_reason_is_false_for_happy_path(): void
    {
        $this->assertFalse(OrderStateMachine::requiresReason(OrderStatus::ACCEPT));
        $this->assertFalse(OrderStateMachine::requiresReason(OrderStatus::PREPARING));
        $this->assertFalse(OrderStateMachine::requiresReason(OrderStatus::PREPARED));
        $this->assertFalse(OrderStateMachine::requiresReason(OrderStatus::DELIVERED));
    }

    public function test_legal_transitions_matrix_contains_exactly_expected_count(): void
    {
        // 11 legal non-identity pairs in V1. If this changes, docs/ORDER_FLOW.md must change too.
        $this->assertCount(11, OrderStateMachine::legalTransitions());
    }

    public function test_all_statuses_returns_nine_v1_states(): void
    {
        $this->assertCount(9, OrderStateMachine::allStatuses());
    }
}
