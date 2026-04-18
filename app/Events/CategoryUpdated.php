<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CategoryUpdated
{
    use Dispatchable;

    public function __construct(
        public int $categoryId,
        public ?int $branchId = null
    ) {
    }
}
