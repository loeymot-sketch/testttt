<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Typed read facade for POS discount entries in the fiscal audit chain.
 *
 * Writes stay centralised in AuditLogService so the HMAC chain and
 * append-only guarantees remain unchanged.
 */
class OrderDiscountLog extends AuditLog
{
    public const ACTION = 'order.discount_applied';

    protected $table = 'audit_logs';

    public function scopeDiscounts(Builder $query): Builder
    {
        return $query->where('action', self::ACTION);
    }

    public function scopeForOrder(Builder $query, int $orderId): Builder
    {
        return $query
            ->discounts()
            ->where('resource', 'order')
            ->where('resource_id', $orderId);
    }
}
