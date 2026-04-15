<?php

namespace Tests\Unit\Domain\Order;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStateMachineTest extends TestCase
{
    public function test_pending_to_accept_without_user(): void
    {
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PENDING, OrderStatus::ACCEPT, null));
    }

    public function test_pending_to_delivered_rejected(): void
    {
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::PENDING, OrderStatus::DELIVERED, null));
    }

    public function test_same_status_always_allowed(): void
    {
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::PREPARING, null));
    }
}
