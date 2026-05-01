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
    const CATALOG_CHANGED = 'catalog.changed';
    const STOCK_LOW = 'stock.low';

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
            self::CATALOG_CHANGED,
            self::STOCK_LOW,
        ];
    }
}
