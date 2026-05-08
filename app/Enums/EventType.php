<?php

namespace App\Enums;

class EventType
{
    const ORDER_CREATED = 'order.created';
    const ORDER_STATUS_CHANGED = 'order.status_changed';
    const ORDER_PAYMENT_CONFIRMED = 'order.payment_confirmed';
    const ORDER_ITEM_ADDED = 'order.item_added';
    const ORDER_CANCELLED = 'order.cancelled';
    // [F-02] Floor-plan table reassignment (occupy / transfer). KDS uses this
    // to update the table label of an in-flight prep card without re-printing.
    const ORDER_TABLE_CHANGED = 'order.table_changed';
    const MENU_ITEM_AVAILABILITY_CHANGED = 'menu.item_availability_changed';
    // [F-016a-BIS] Branch-scoped extra rupture toggles. Outbox payload mirrors
    // ItemAvailabilityChanged contract (extra_id + branch_id + is_available + reason).
    const MENU_EXTRA_AVAILABILITY_CHANGED = 'menu.extra_availability_changed';
    // [F-016a-BIS] Branch-scoped variation rupture toggles.
    const MENU_VARIATION_AVAILABILITY_CHANGED = 'menu.variation_availability_changed';
    const CATALOG_CHANGED = 'catalog.changed';
    const STOCK_LOW = 'stock.low';
    // [PROMO-DASH-2026-05-06] Code promo CRUD/toggle propagation pour les
    // surfaces (POS/kiosk/web) abonnées au canal `branch.{id}` de chaque
    // branche concernée par le scope du coupon (toutes branches actives si
    // branch_scope est null/empty).
    const COUPON_CHANGED = 'promo.coupon_changed';
    // [P13 — F-VERIFY-09-01 / F-VERIFY-09-10] payment_status transitions on
    // an order. Used by KDS, Z-report aggregation, and outbox fan-out so any
    // `payment_status` mutation is observable as a first-class domain event.
    const ORDER_PAYMENT_STATUS_CHANGED = 'order.payment_status_changed';

    public static function all(): array
    {
        return [
            self::ORDER_CREATED,
            self::ORDER_STATUS_CHANGED,
            self::ORDER_PAYMENT_CONFIRMED,
            self::ORDER_ITEM_ADDED,
            self::ORDER_CANCELLED,
            self::ORDER_TABLE_CHANGED,
            self::MENU_ITEM_AVAILABILITY_CHANGED,
            // [F-016a-BIS]
            self::MENU_EXTRA_AVAILABILITY_CHANGED,
            self::MENU_VARIATION_AVAILABILITY_CHANGED,
            self::CATALOG_CHANGED,
            self::STOCK_LOW,
            self::COUPON_CHANGED,
            // [P13]
            self::ORDER_PAYMENT_STATUS_CHANGED,
        ];
    }
}
