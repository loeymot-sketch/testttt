<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

class CategoryDeleted
{
    use DispatchableAfterCommit;

    public function __construct(
        public int $categoryId,
        public ?int $branchId = null
    ) {
    }
}
