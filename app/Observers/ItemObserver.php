<?php

namespace App\Observers;

use App\Models\Item;
use App\Services\AllergenService;

class ItemObserver
{
    public function __construct(private readonly AllergenService $allergenService)
    {
    }

    public function saving(Item $item): void
    {
        $this->allergenService->projectFlags($item);
    }

    public function saved(Item $item): void
    {
        if ($item->wasRecentlyCreated) {
            $this->allergenService->projectFlags($item);
        }
    }
}
