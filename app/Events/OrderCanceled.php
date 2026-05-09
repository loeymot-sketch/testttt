<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * [F-01] Internal domain event — fired after an order is canceled (any source:
 * customer self-cancel, admin cancel, refund-on-cancel). Triggers the
 * compensating release of stock previously decremented by
 * {@see \App\Listeners\DecrementItemAvailabilityOnOrder}.
 *
 * NOT broadcast (internal only). Always dispatched after-commit at call sites
 * (see {@see DispatchableAfterCommit} trait).
 */
class OrderCanceled
{
    use DispatchableAfterCommit;

    public function __construct(public readonly Model $order)
    {
    }
}
