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

    /*
     * ─── [GOAL ULTRA-SYNC W4 2026-07-20] Commandes programmées (scheduled_at) ───
     * Mandat owner : une commande pour DANS 1 H ne doit PAS s'afficher en cuisine ;
     * elle apparaît `lead` minutes avant l'heure cible (défaut 20), avec avant ça un
     * bandeau « ⏰ programmée pour HH:MM ». NULL = ASAP (tout l'existant inchangé).
     * Même discipline SSOT que le release-filter : le SQL (applyScheduledBoardFilter)
     * et le booléen (orderIsWithinScheduledWindow) partagent UNE définition pour que
     * « visible sur le board » et « bumpable » ne divergent jamais (leçon du défaut
     * unreleased-order-bump). NF525 : zéro impact — visibilité SELECT-only.
     */

    public static function scheduledLeadMinutes(): int
    {
        return max(1, (int) config('kds.scheduled_lead_minutes', 20));
    }

    /**
     * Board (KDS + OSS, les 5 chemins jumeaux) : ne montrer que l'ASAP (NULL) et
     * les programmées entrées dans leur fenêtre (scheduled_at <= now + lead).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  \DateTimeInterface|null  $now  horloge injectable (tests) — défaut now()
     */
    public static function applyScheduledBoardFilter($query, $now = null)
    {
        $horizon = ($now ? \Illuminate\Support\Carbon::instance(
            $now instanceof \Illuminate\Support\Carbon ? $now : \Illuminate\Support\Carbon::parse($now)
        ) : now())->copy()->addMinutes(self::scheduledLeadMinutes());

        return $query->where(function ($q) use ($horizon) {
            $q->whereNull('scheduled_at')
                ->orWhere('scheduled_at', '<=', $horizon);
        });
    }

    /**
     * Bandeau « à venir » : les programmées ENCORE HORS fenêtre (strictement après
     * now + lead). Complément exact du board filter — une commande est toujours dans
     * exactement un des deux ensembles.
     */
    public static function applyScheduledUpcomingFilter($query, $now = null)
    {
        $horizon = ($now ? \Illuminate\Support\Carbon::instance(
            $now instanceof \Illuminate\Support\Carbon ? $now : \Illuminate\Support\Carbon::parse($now)
        ) : now())->copy()->addMinutes(self::scheduledLeadMinutes());

        return $query->whereNotNull('scheduled_at')
            ->where('scheduled_at', '>', $horizon);
    }

    /**
     * Jumeau booléen du board filter — pour le guard changeStatus() (bump) : une
     * programmée hors fenêtre n'est PAS bumpable (422), miroir de son invisibilité.
     */
    public static function orderIsWithinScheduledWindow(Order $order, $now = null): bool
    {
        if ($order->scheduled_at === null) {
            return true; // ASAP — comportement historique intact
        }

        $horizon = ($now ? \Illuminate\Support\Carbon::instance(
            $now instanceof \Illuminate\Support\Carbon ? $now : \Illuminate\Support\Carbon::parse($now)
        ) : now())->copy()->addMinutes(self::scheduledLeadMinutes());

        return $order->scheduled_at->lessThanOrEqualTo($horizon);
    }
}
