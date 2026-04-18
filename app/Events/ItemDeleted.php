<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class ItemDeleted
{
    use Dispatchable;

    public function __construct(
        public int $itemId,
        public ?int $branchId = null
    ) {
    }
}
