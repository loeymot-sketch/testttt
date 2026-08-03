<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

class StockLevelChanged
{
    use DispatchableAfterCommit;

    /**
     * @param array<int, int> $stockLevelIds
     */
    public function __construct(
        public readonly int $branchId,
        public readonly array $stockLevelIds,
    ) {
    }
}
