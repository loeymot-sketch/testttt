<?php

namespace App\Services\Ingredients;

use App\Events\IngredientAvailabilityChanged;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use Illuminate\Support\Facades\DB;

class IngredientAvailabilityService
{
    public function toggle(string $type, int $id, bool $isAvailable, ?string $reason = null): bool
    {
        $reason = $isAvailable ? null : ($reason ?: 'manual_admin');

        return DB::transaction(function () use ($type, $id, $isAvailable, $reason): bool {
            if ($type === IngredientService::TYPE_ATTRIBUTE) {
                $row = ItemAttribute::query()->find($id);
            } elseif ($type === IngredientService::TYPE_EXTRA) {
                $row = ItemExtra::query()->find($id);
            } else {
                return false;
            }

            if (! $row) {
                return false;
            }

            $row->is_available = $isAvailable;
            $row->unavailable_reason = $reason;

            $saved = (bool) $row->save();

            if ($saved) {
                IngredientAvailabilityChanged::dispatch($type, $id, $isAvailable, $reason, null);
            }

            return $saved;
        });
    }
}
