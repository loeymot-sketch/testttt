<?php

namespace App\Domain\Order;

use App\Enums\OrderType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\OrderStatusTransition;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for allowed order status transitions (mirrors ValidStatusTransition rules).
 *
 * Two entry points:
 * - {@see self::allows()} / {@see self::assertAllows()} — pure checks, no side effect.
 * - {@see self::apply()} — atomic guard + mutate + audit. Use this from NEW call sites.
 *
 * Existing OrderService / FrontendOrderService call sites keep their historical
 * pattern (`$order->status = $next; save(); recordTransition(...)`) to honour
 * the frozen zone V1 rule. The `apply()` method is the path forward.
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
                // [LOCK-OSM-PREZ-REFUND 2026-06-04, owner-gated] Pre-Z refund of a
                // not-yet-delivered order: a refund-capable user (pos-refund =
                // Admin/Branch Manager) may RETURN it. Captured in the open Z (no gap);
                // cashback + audit are wired in the changeStatus→RETURNED path.
                if ($to === OrderStatus::RETURNED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos-refund')) {
                    return true;
                }

                return in_array($to, [OrderStatus::PREPARING, OrderStatus::CANCELED], true);

            case OrderStatus::PREPARING:
                if ($to === OrderStatus::DELIVERED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos')) {
                    return true;
                }
                // [LOCK-OSM-PREZ-REFUND 2026-06-04, owner-gated] see ACCEPT case.
                if ($to === OrderStatus::RETURNED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos-refund')) {
                    return true;
                }

                return in_array($to, [OrderStatus::PREPARED, OrderStatus::CANCELED], true);

            case OrderStatus::PREPARED:
                // [LOCK-OSM-PREZ-REFUND 2026-06-04, owner-gated] see ACCEPT case.
                if ($to === OrderStatus::RETURNED && $user && method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos-refund')) {
                    return true;
                }

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
     * @return int[]
     */
    public static function kitchenReleaseStatuses(): array
    {
        return [
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
            OrderStatus::PREPARED,
        ];
    }

    public static function isKitchenReleaseStatus(int $status): bool
    {
        return in_array($status, self::kitchenReleaseStatuses(), true);
    }

    public static function isKitchenReleaseTransition(int $from, int $to): bool
    {
        if ($from === $to) {
            return self::isKitchenReleaseStatus($from);
        }

        return ($from === OrderStatus::ACCEPT && $to === OrderStatus::PREPARING)
            || ($from === OrderStatus::PREPARING && $to === OrderStatus::PREPARED);
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

    /**
     * Atomic guard + mutate + audit. Throws IllegalTransitionException if the
     * transition is not permitted by {@see self::allows()}, leaves the DB
     * unchanged, and never emits an audit row.
     *
     * Reason is required for cancellation-like transitions (CANCELED/REJECTED/RETURNED).
     *
     * This method is the preferred entry point for NEW code. Existing frozen-zone
     * call sites in OrderService / FrontendOrderService remain on the historical
     * pattern per the V1 frozen-zone rule.
     *
     * @param  Model                   $order  Must expose `status` attribute (Order or FrontendOrder)
     * @param  int                     $next   Target OrderStatus::* constant
     * @param  Authenticatable|null    $actor  Authenticated user for permission checks + audit
     * @param  string|null             $reason Required for cancel/reject/return transitions
     *
     * @throws IllegalTransitionException
     */
    public static function apply(
        Model $order,
        int $next,
        ?Authenticatable $actor = null,
        ?string $reason = null
    ): void {
        // [iter15 P0-12 LOCKFORUPDATE 2026-05-10] Concurrent apply() race fix.
        //
        // Previously `$from = (int) $order->status` read the in-memory model
        // BEFORE the transaction, so two concurrent apply($order, DELIVERED)
        // calls both read the same source status, both passed allows(), and
        // both wrote DELIVERED — duplicating audit rows and corrupting the
        // state machine.
        //
        // Mirrors the pattern already used by OrderService::changeStatus
        // (see app/Services/OrderService.php:1515 — iter13 P1 LOCKFORUPDATE):
        //   - DB::transaction wraps lock + read + guard + write + audit
        //   - Order::query()->whereKey($id)->lockForUpdate()->firstOrFail()
        //     forces concurrent transactions to serialize on the row
        //   - Idempotent early-return when the locked row already matches the
        //     target status, so the second tx exits cleanly without writing
        $modelClass = get_class($order);
        $orderKey = $order->getKey();
        if ($orderKey === null) {
            throw new IllegalTransitionException(
                sprintf('Cannot apply transition to unsaved %s instance.', $modelClass)
            );
        }

        DB::transaction(function () use ($order, $modelClass, $orderKey, $next, $actor, $reason): void {
            /** @var Model $locked */
            $locked = $modelClass::query()->whereKey($orderKey)->lockForUpdate()->firstOrFail();
            $from = (int) $locked->status;

            // Idempotent: another concurrent transaction already applied the
            // same target. Bail out without re-writing or re-auditing.
            if ($from === $next) {
                // Sync the caller-provided model instance with persisted state
                // so its getStatus() reflects the winning transition.
                $order->setRawAttributes($locked->getAttributes(), true);
                return;
            }

            if (!self::allows($from, $next, $actor)) {
                throw new IllegalTransitionException(
                    sprintf('Illegal transition %d → %d for %s#%s', $from, $next, $modelClass, $orderKey)
                );
            }

            if (self::requiresReason($next) && (!is_string($reason) || trim($reason) === '')) {
                throw new IllegalTransitionException(
                    sprintf('Transition to status %d requires a non-empty reason.', $next)
                );
            }

            $locked->status = $next;
            if ($reason !== null && $locked->isFillable('reason')) {
                $locked->reason = $reason;
            }
            $locked->save();

            // Keep the caller-provided instance in sync with the persisted row
            // so post-apply reads on $order observe the new status without
            // requiring an explicit ->refresh().
            $order->setRawAttributes($locked->getAttributes(), true);

            self::recordTransition(
                $modelClass,
                (int) $orderKey,
                $from,
                $next,
                $actor?->getAuthIdentifier() ? (int) $actor->getAuthIdentifier() : null,
                $reason
            );
        });
    }

    /**
     * Transitions that MUST carry a human-readable reason.
     * Kept conservative for V1 — only terminal negative outcomes.
     */
    public static function requiresReason(int $to): bool
    {
        return in_array($to, [
            OrderStatus::CANCELED,
            OrderStatus::REJECTED,
            OrderStatus::RETURNED,
        ], true);
    }

    /**
     * Enumerate every legal (from, to) pair — used by tests and docs tooling.
     *
     * @return array<int, array{from:int, to:int, requires_reason:bool}>
     */
    public static function legalTransitions(): array
    {
        $pairs = [];
        foreach (self::allStatuses() as $from) {
            foreach (self::allStatuses() as $to) {
                if ($from === $to) {
                    continue;
                }
                if (self::allows($from, $to, null)) {
                    $pairs[] = [
                        'from' => $from,
                        'to' => $to,
                        'requires_reason' => self::requiresReason($to),
                    ];
                }
            }
        }

        return $pairs;
    }

    /**
     * @return int[]
     */
    public static function allStatuses(): array
    {
        return [
            OrderStatus::PENDING,
            OrderStatus::ACCEPT,
            OrderStatus::PREPARING,
            OrderStatus::PREPARED,
            OrderStatus::OUT_FOR_DELIVERY,
            OrderStatus::DELIVERED,
            OrderStatus::CANCELED,
            OrderStatus::REJECTED,
            OrderStatus::RETURNED,
        ];
    }
}
