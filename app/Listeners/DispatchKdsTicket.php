<?php

namespace App\Listeners;

use App\Domain\Kds\KitchenReleaseRule;
use App\Events\OrderStatusChanged;
use App\Models\Order;

final class DispatchKdsTicket
{
    public function dispatch(Order $order, int $oldStatus, int $newStatus): bool
    {
        if (! KitchenReleaseRule::shouldDispatchStatusChanged($oldStatus, $newStatus)) {
            return false;
        }

        OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);

        return true;
    }
}
