<?php

namespace Tests\Feature\Payment;

use App\Domain\Order\PaymentStateMachine;
use App\Enums\PaymentStatus;
use Tests\TestCase;

class PaymentStateMachineTransitionsTest extends TestCase
{
    public function test_pending_counter_can_only_be_confirmed_or_canceled(): void
    {
        $this->assertTrue(PaymentStateMachine::canTransition(PaymentStatus::PENDING_COUNTER, PaymentStatus::PAID));
        $this->assertTrue(PaymentStateMachine::canTransition(PaymentStatus::PENDING_COUNTER, PaymentStatus::REFUNDED));
        $this->assertFalse(PaymentStateMachine::canTransition(PaymentStatus::PENDING_COUNTER, PaymentStatus::UNPAID));
    }

    public function test_terminal_payment_states_do_not_reopen(): void
    {
        $this->assertFalse(PaymentStateMachine::canTransition(PaymentStatus::PAID, PaymentStatus::PENDING_COUNTER));
        $this->assertFalse(PaymentStateMachine::canTransition(PaymentStatus::PAID, PaymentStatus::REFUNDED));
        $this->assertFalse(PaymentStateMachine::canTransition(PaymentStatus::REFUNDED, PaymentStatus::PAID));
    }
}
