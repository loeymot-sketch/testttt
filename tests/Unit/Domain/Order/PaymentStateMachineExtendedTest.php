<?php

namespace Tests\Unit\Domain\Order;

use App\Domain\Order\PaymentStateMachine;
use App\Enums\PaymentStatus;
use Tests\TestCase;

/**
 * @FK-ID F-VERIFY-09-01 | @plan docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 *
 * Extends the matrix proven by {@see \Tests\Feature\Payment\PaymentStateMachineTransitionsTest}.
 * Locks Option B (D1) — `PAID` is terminal; refunds go through `PaymentService::cancelCounterPayment`.
 * Refund of an already-PAID order via this state machine is REJECTED — that is the spec.
 */
class PaymentStateMachineExtendedTest extends TestCase
{
    /**
     * @return array<string, array{0:int,1:int}>
     */
    public static function legalProvider(): array
    {
        return [
            'unpaid -> paid'               => [PaymentStatus::UNPAID, PaymentStatus::PAID],
            'pending_counter -> paid'      => [PaymentStatus::PENDING_COUNTER, PaymentStatus::PAID],
            'pending_counter -> refunded'  => [PaymentStatus::PENDING_COUNTER, PaymentStatus::REFUNDED],
        ];
    }

    /**
     * @return array<string, array{0:int,1:int}>
     */
    public static function illegalProvider(): array
    {
        return [
            // Terminal — Option B: PAID is closed.
            'paid -> unpaid'              => [PaymentStatus::PAID, PaymentStatus::UNPAID],
            'paid -> refunded'            => [PaymentStatus::PAID, PaymentStatus::REFUNDED],
            'paid -> pending_counter'     => [PaymentStatus::PAID, PaymentStatus::PENDING_COUNTER],
            'refunded -> paid'            => [PaymentStatus::REFUNDED, PaymentStatus::PAID],
            'refunded -> unpaid'          => [PaymentStatus::REFUNDED, PaymentStatus::UNPAID],
            'refunded -> pending_counter' => [PaymentStatus::REFUNDED, PaymentStatus::PENDING_COUNTER],
            // UNPAID is the starting state — only PAID is reachable directly.
            'unpaid -> refunded'          => [PaymentStatus::UNPAID, PaymentStatus::REFUNDED],
            'unpaid -> pending_counter'   => [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER],
            // PENDING_COUNTER cannot rewind to UNPAID.
            'pending_counter -> unpaid'   => [PaymentStatus::PENDING_COUNTER, PaymentStatus::UNPAID],
        ];
    }

    /**
     * @dataProvider legalProvider
     */
    public function test_legal_transitions_are_accepted(int $from, int $to): void
    {
        $this->assertTrue(
            PaymentStateMachine::canTransition($from, $to),
            sprintf('Expected %d -> %d to be legal under Option B.', $from, $to)
        );

        // assertCanTransition must NOT throw.
        PaymentStateMachine::assertCanTransition($from, $to);
        $this->assertTrue(true);
    }

    /**
     * @dataProvider illegalProvider
     */
    public function test_illegal_transitions_are_rejected(int $from, int $to): void
    {
        $this->assertFalse(
            PaymentStateMachine::canTransition($from, $to),
            sprintf('Expected %d -> %d to be illegal under Option B.', $from, $to)
        );

        try {
            PaymentStateMachine::assertCanTransition($from, $to);
            $this->fail(sprintf('assertCanTransition(%d, %d) should have thrown.', $from, $to));
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(422, $e->getCode(), 'State-machine guard must throw with HTTP 422 code.');
            $this->assertStringContainsString((string) $from, $e->getMessage());
            $this->assertStringContainsString((string) $to, $e->getMessage());
        }
    }

    public function test_paid_is_terminal_under_option_b(): void
    {
        // Hard lock — F-VERIFY-09-01 D1=Option B. If this assertion ever
        // flips, the existing PaymentStateMachineTransitionsTest::21 must
        // be re-considered alongside NF525 refund flow review.
        $this->assertSame([], (function () {
            $reflection = new \ReflectionClass(PaymentStateMachine::class);
            $reflection = $reflection->getReflectionConstant('TRANSITIONS');
            return $reflection->getValue()[PaymentStatus::PAID];
        })());
    }
}
