<?php

namespace App\Domain\Order;

use App\Enums\PaymentStatus;

final class PaymentStateMachine
{
    private const TRANSITIONS = [
        PaymentStatus::UNPAID => [
            PaymentStatus::PAID,
        ],
        PaymentStatus::PENDING_COUNTER => [
            PaymentStatus::PAID,
            PaymentStatus::REFUNDED,
        ],
        PaymentStatus::PAID => [],
        PaymentStatus::REFUNDED => [],
    ];

    public static function canTransition(int $from, int $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assertCanTransition(int $from, int $to): void
    {
        if (self::canTransition($from, $to)) {
            return;
        }

        throw new \InvalidArgumentException("Invalid payment_status transition {$from} -> {$to}.", 422);
    }
}
