<?php

namespace App\Services\Ingredients;

use App\Events\IngredientAvailabilityChanged;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use Illuminate\Support\Facades\DB;

class IngredientAvailabilityService
{
    /**
     * Toggle availability for an ingredient (attribute or extra).
     *
     * V1.6+ — Cascade by **name** : a single ItemAttribute / ItemExtra is duplicated per item
     * (e.g. "Jambon de dinde" exists 32× because each Tacos owns its own ItemExtra row).
     * The Ingredients admin lists 1 logical ingredient per name (see IngredientService::listAll
     * dedup) ; therefore toggling rupture must propagate to **every sibling row of the same name**
     * so that POS / Kiosk see a globally unavailable ingredient. Without this cascade, ticking
     * "Sauce ketchup → rupture" only affected one product. The event fires once with the id
     * the caller passed in (preserves payload contract for IngredientAvailabilityChangedAfterCommitTest).
     */
    public function toggle(string $type, int $id, bool $isAvailable, ?string $reason = null): bool
    {
        $reason = $isAvailable ? null : ($reason ?: 'manual_admin');

        return DB::transaction(function () use ($type, $id, $isAvailable, $reason): bool {
            if ($type === IngredientService::TYPE_ATTRIBUTE) {
                $row = ItemAttribute::query()->find($id);
                if (! $row) {
                    return false;
                }

                $name = (string) $row->name;
                ItemAttribute::query()
                    ->where('name', $name)
                    ->update([
                        'is_available' => $isAvailable,
                        'unavailable_reason' => $reason,
                    ]);
            } elseif ($type === IngredientService::TYPE_EXTRA) {
                $row = ItemExtra::query()->find($id);
                if (! $row) {
                    return false;
                }

                $name = (string) $row->name;
                $groupLabel = $row->group_label;

                $query = ItemExtra::query()->where('name', $name);
                // Same logical extra = same name + same group_label (or both null).
                // Without the group filter, a "Sauce" extra in Burger group could collide
                // with a different "Sauce" extra in Tacos group.
                if ($groupLabel !== null) {
                    $query->where('group_label', $groupLabel);
                } else {
                    $query->whereNull('group_label');
                }
                $query->update([
                    'is_available' => $isAvailable,
                    'unavailable_reason' => $reason,
                ]);
            } else {
                return false;
            }

            IngredientAvailabilityChanged::dispatch($type, $id, $isAvailable, $reason, null);

            return true;
        });
    }
}
