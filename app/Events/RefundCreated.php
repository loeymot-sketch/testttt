<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * [F-01 + NEW-05] Internal domain event — fired after a refund (cash_back or
 * wallet credit) is recorded against an order. Carries an optional partial
 * refund map; an empty array signals "full refund" and behaves like
 * {@see OrderCanceled} for the release listener.
 *
 * NOT broadcast (internal only). Always dispatched after-commit at call sites.
 *
 * @property-read array<int, array{order_item_id:int, qty:int}> $refundedItems
 */
class RefundCreated
{
    use DispatchableAfterCommit;

    /**
     * @param array<int, array{order_item_id:int, qty:int}> $refundedItems
     */
    public function __construct(
        public readonly Model $order,
        public readonly array $refundedItems = [],
    ) {
    }
}
