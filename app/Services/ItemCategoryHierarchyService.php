<?php

namespace App\Services;

use App\Models\ItemCategory;
use InvalidArgumentException;

class ItemCategoryHierarchyService
{
    public function validateParent(?int $parentId, ?int $childId = null): void
    {
        if ($parentId === null) {
            return;
        }

        if ($childId !== null && $parentId === $childId) {
            throw new InvalidArgumentException('Categorie ne peut etre son propre parent');
        }

        $parent = ItemCategory::query()->find($parentId);
        if (!$parent) {
            return;
        }

        if ($parent->parent_id !== null) {
            throw new InvalidArgumentException('Hierarchie categorie limitee a deux niveaux');
        }

        // [GOAL CMS heal P1-1 2026-06-10] Re-parenting a category that has
        // children would silently create depth 3 (C -> parent -> children)
        // and break every 2-level tree renderer (Studio sidebar, stock rail).
        if ($childId !== null
            && ItemCategory::query()->where('parent_id', $childId)->exists()) {
            throw new InvalidArgumentException(
                'Cette categorie a des sous-categories : elle ne peut pas devenir elle-meme une sous-categorie'
            );
        }

        $visited = [];
        $cursor = $parent;

        while ($cursor !== null) {
            if (in_array($cursor->id, $visited, true)) {
                throw new InvalidArgumentException('Cycle detecte dans la hierarchie des categories');
            }

            if ($childId !== null && (int) $cursor->id === $childId) {
                throw new InvalidArgumentException('Cycle detecte dans la hierarchie des categories');
            }

            $visited[] = (int) $cursor->id;
            $cursor = $cursor->parent;
        }
    }
}
