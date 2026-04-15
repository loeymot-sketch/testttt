<?php

namespace App\Enums;

class EventType
{
    const ORDER_CREATED = 'order.created';
    const ORDER_STATUS_CHANGED = 'order.status_changed';
    const ORDER_ITEM_ADDED = 'order.item_added';
    const ORDER_CANCELLED = 'order.cancelled';
    const MENU_ITEM_AVAILABILITY_CHANGED = 'menu.item_availability_changed';
    const STOCK_LOW = 'stock.low';

    public static function all(): array
    {
        return [
            self::ORDER_CREATED,
            self::ORDER_STATUS_CHANGED,
            self::ORDER_ITEM_ADDED,
            self::ORDER_CANCELLED,
            self::MENU_ITEM_AVAILABILITY_CHANGED,
            self::STOCK_LOW,
        ];
    }
}
