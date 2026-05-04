<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class IngredientAvailabilityChanged
{
    use DispatchableAfterCommit;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly bool $isAvailable,
        public readonly ?string $reason,
        public readonly ?int $branchId = null,
    ) {
    }
}
