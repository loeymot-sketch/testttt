<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use App\Events\Concerns\DispatchableAfterCommit;

class OrderPaidAtCounter
{
    use DispatchableAfterCommit;

    public function __construct(
        public BroadcastableOrder $order,
        public int $paymentMethod,
    ) {
    }
}
