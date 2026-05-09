<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

class ItemDeleted
{
    use DispatchableAfterCommit;

    public function __construct(
        public int $itemId,
        public ?int $branchId = null
    ) {
    }
}
