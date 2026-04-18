<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CategoryDeleted
{
    use Dispatchable;

    public function __construct(
        public int $categoryId,
        public ?int $branchId = null
    ) {
    }
}
