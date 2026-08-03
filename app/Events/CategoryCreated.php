<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

class CategoryCreated
{
    use DispatchableAfterCommit;

    public function __construct(
        public int $categoryId,
        public ?int $branchId = null
    ) {
    }
}
