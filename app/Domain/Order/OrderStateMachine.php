<?php

namespace App\Domain\Order;

use App\Enums\OrderStatus;
use App\Models\OrderStatusTransition;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Single source of truth for allowed order status transitions (mirrors ValidStatusTransition rules).
 */
final class OrderStateMachine
{
    /**
     * @param  Authenticatable|null  $user  Authenticated user for POS shortcut / Admin override checks
     */
    public static function allows(int $from, int $to, ?Authenticatable $user = null): bool
    {
        if ($from === $to) {
            return true;
        }

        switch ($from) {
            case OrderStatus::PENDING:
                return in_array($to, [OrderStatus::ACCEPT, OrderStatus::CANCELED, OrderStatus::REJECTED], true);

            case OrderStatus::ACCEPT:
                if ($to === OrderStatus::DELIVERED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos')) {
                    return true;
                }

                return in_array($to, [OrderStatus::PREPARING, OrderStatus::CANCELED], true);

            case OrderStatus::PREPARING:
                if ($to === OrderStatus::DELIVERED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos')) {
                    return true;
                }

                return in_array($to, [OrderStatus::PREPARED, OrderStatus::CANCELED], true);

            case OrderStatus::PREPARED:
                return in_array($to, [OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED], true);

            case OrderStatus::OUT_FOR_DELIVERY:
                return $to === OrderStatus::DELIVERED;

            case OrderStatus::DELIVERED:
                return $to === OrderStatus::RETURNED;

            case OrderStatus::CANCELED:
            case OrderStatus::REJECTED:
            case OrderStatus::RETURNED:
                if ($user && method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
                    return true;
                }

                return false;

            default:
                return false;
        }
    }

    public static function assertAllows(int $from, int $to, ?Authenticatable $user = null): void
    {
        if (!self::allows($from, $to, $user)) {
            throw new IllegalTransitionException('Illegal order status transition from ' . $from . ' to ' . $to);
        }
    }

    /**
     * Persist an audit row for a successful transition (best-effort; failures are logged only).
     */
    public static function recordTransition(
        string $orderType,
        int $orderId,
        int $fromStatus,
        int $toStatus,
        ?int $actorId = null,
        ?string $reason = null,
    ): void {
        if ($fromStatus === $toStatus) {
            return;
        }

        try {
            OrderStatusTransition::query()->create([
                'order_id' => $orderId,
                'order_type' => $orderType,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor_id' => $actorId,
                'actor_type' => $actorId ? 'user' : null,
                'reason' => $reason,
                'correlation_id' => request()?->header('X-Correlation-ID'),
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[OrderStateMachine] Failed to record transition: ' . $e->getMessage());
        }
    }
}
