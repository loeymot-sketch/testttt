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

    /**
     * Payment-release predicate for the KITCHEN BOARD (what list() surfaces and
     * what changeStatus() lets a chef bump). BROADER than isReleasedToKitchen():
     * it additionally admits PENDING_COUNTER orders — the Plan B kiosk→counter
     * encashment flow (config kiosk.payment_route_all_to_counter), where the
     * kitchen starts preparing while the customer pays at the till. Status is
     * enforced separately via visibleStatuses() / canTransition(), so this
     * predicate is intentionally payment-dimension only.
     *
     * SSOT: applyBoardReleaseFilter() is the SQL mirror of this boolean.
     * list() and changeStatus() MUST share one definition so "visible on the
     * board" and "bumpable" can never diverge again — that divergence was the
     * root cause of the unreleased-order bump defect (an UNPAID order, invisible
     * in list(), was still bumpable via the change-status endpoint, firing
     * customer notifications before payment).
     */
    public static function isReleasedForBoard(
        int $paymentStatus,
        ?int $orderType = null,
        ?int $posPaymentMethod = null
    ): bool {
        if ($paymentStatus === PaymentStatus::PAID
            || $paymentStatus === PaymentStatus::PENDING_COUNTER) {
            return true;
        }

        return $orderType === OrderType::POS
            && $posPaymentMethod === PosPaymentMethod::CASH;
    }

    public static function orderIsReleasedForBoard(Order $order): bool
    {
        return self::isReleasedForBoard(
            (int) $order->payment_status,
            $order->order_type === null ? null : (int) $order->order_type,
            $order->pos_payment_method === null ? null : (int) $order->pos_payment_method
        );
    }

    /**
     * SQL mirror of isReleasedForBoard() — applies the board payment-release
     * filter to a query builder so list()'s server-side filter and
     * changeStatus()'s in-memory guard share one definition.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    public static function applyBoardReleaseFilter($query)
    {
        return $query->where(function ($q) {
            $q->where('payment_status', PaymentStatus::PAID)
                ->orWhere('payment_status', PaymentStatus::PENDING_COUNTER)
                ->orWhere(function ($cashQuery) {
                    $cashQuery->where('order_type', OrderType::POS)
                        ->where('pos_payment_method', PosPaymentMethod::CASH);
                });
        });
    }
}
