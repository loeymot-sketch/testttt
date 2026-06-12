<?php

namespace App\Domain\Kds;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Order;

final class KitchenReleaseRule
{
    /**
     * @return int[]
     */
    public static function visibleStatuses(): array
    {
        return [
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
            OrderStatus::PREPARED,
        ];
    }

    /**
     * @return int[]
     */
    public static function itemBoardStatuses(): array
    {
        return [
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
        ];
    }

    public static function isVisibleStatus(int $status): bool
    {
        return in_array($status, self::visibleStatuses(), true);
    }

    public static function canTransition(int $from, int $to): bool
    {
        if ($from === $to) {
            return self::isVisibleStatus($from);
        }

        return ($from === OrderStatus::ACCEPT && $to === OrderStatus::PREPARING)
            || ($from === OrderStatus::PREPARING && $to === OrderStatus::PREPARED);
    }

    public static function shouldDispatchStatusChanged(int $from, int $to): bool
    {
        return $from !== $to && self::canTransition($from, $to);
    }

    public static function isReleasedToKitchen(
        int $status,
        int $paymentStatus,
        ?int $orderType = null,
        ?int $posPaymentMethod = null
    ): bool {
        if ($status < OrderStatus::ACCEPT) {
            return false;
        }

        if ($paymentStatus === PaymentStatus::PAID) {
            return true;
        }

        // [LOT E / ultra-audit 2026-06-10] Counter-collect orders (kiosk
        // Plan B + walk-in deferred) are PENDING_COUNTER and the kitchen
        // prepares them BEFORE payment (owner decision W-D1) — the KDS board
        // query already shows them (KitchenDisplaySystemOrderService:74-82).
        // This rule predated that decision; aligning it lets changeStatus()
        // enforce orderIsReleased() without breaking the Plan B flow.
        if ($paymentStatus === PaymentStatus::PENDING_COUNTER) {
            return true;
        }

        return $orderType === OrderType::POS
            && $posPaymentMethod === PosPaymentMethod::CASH;
    }

    public static function orderIsReleased(Order $order): bool
    {
        return self::isReleasedToKitchen(
            (int) $order->status,
            (int) $order->payment_status,
            $order->order_type === null ? null : (int) $order->order_type,
            $order->pos_payment_method === null ? null : (int) $order->pos_payment_method
        );
    }
}
