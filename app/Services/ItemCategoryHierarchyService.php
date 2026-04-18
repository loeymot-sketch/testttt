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
