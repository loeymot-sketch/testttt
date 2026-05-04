<?php

namespace App\Services\Ingredients;

use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemWizardStep;
use Illuminate\Support\Collection;

class IngredientService
{
    public const TYPE_ATTRIBUTE = 'attribute';
    public const TYPE_EXTRA = 'extra';
    public const TYPE_ADDON = 'addon';
    public const TYPES = [self::TYPE_ATTRIBUTE, self::TYPE_EXTRA, self::TYPE_ADDON];

    public static function globalId(string $type, int $id): string
    {
        return "{$type}:{$id}";
    }

    public static function parseGlobalId(string $globalId): ?array
    {
        if (! str_contains($globalId, ':')) {
            return null;
        }

        [$type, $id] = explode(':', $globalId, 2);
        if (! in_array($type, self::TYPES, true) || ! ctype_digit($id)) {
            return null;
        }

        return ['type' => $type, 'id' => (int) $id];
    }

    /**
     * @param  int|null  $branchId  Reserved for V2 multi-filiale (cf. plan CV1-V1-PIVOT-MASTER décision Q1 :
     *                              ingrédients catalogue global mono-filiale en V1 ; table
     *                              `ingredient_branch_availability` reportée V1.5/V2). Le paramètre est
     *                              accepté pour stabilité de signature mais NON appliqué tant que la
     *                              table d'availability per-branch n'existe pas. Voir invariant I3 :
     *                              en V1 il n'y a aucun cross-branch leak car le catalogue lui-même
     *                              n'est pas branché — toute requête tenant-scoped passe par le
     *                              menu projection (KioskMenuService / PosMenuProjection) qui filtre
     *                              en aval. Audit terminal Claude 2026-05-04 REWORK point 3.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listAll(?int $branchId = null): Collection
    {
        $attributes = ItemAttribute::query()->get()->map(fn (ItemAttribute $attribute): array => [
            'global_id' => self::globalId(self::TYPE_ATTRIBUTE, (int) $attribute->id),
            'type' => self::TYPE_ATTRIBUTE,
            'id' => (int) $attribute->id,
            'name' => (string) $attribute->name,
            'is_available' => (bool) ($attribute->is_available ?? true),
            'unavailable_reason' => $attribute->unavailable_reason,
            'used_by_count' => $this->usageCountForAttribute((int) $attribute->id),
        ]);

        $extras = ItemExtra::query()->get()->map(fn (ItemExtra $extra): array => [
            'global_id' => self::globalId(self::TYPE_EXTRA, (int) $extra->id),
            'type' => self::TYPE_EXTRA,
            'id' => (int) $extra->id,
            'name' => (string) $extra->name,
            'group_label' => $extra->group_label,
            'is_available' => (bool) ($extra->is_available ?? true),
            'unavailable_reason' => $extra->unavailable_reason,
            'used_by_count' => $this->usageCountForExtra((int) $extra->id),
        ]);

        $addons = ItemAddon::query()->with('addonItem')->get()->map(fn (ItemAddon $addon): array => [
            'global_id' => self::globalId(self::TYPE_ADDON, (int) $addon->id),
            'type' => self::TYPE_ADDON,
            'id' => (int) $addon->id,
            'name' => $addon->addonItem?->name ?? "Addon #{$addon->id}",
            'role' => $addon->role,
            'is_available' => true,
            'unavailable_reason' => null,
            'used_by_count' => 1,
        ]);

        return collect()
            ->concat($attributes)
            ->concat($extras)
            ->concat($addons)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listByType(string $type): Collection
    {
        if (! in_array($type, self::TYPES, true)) {
            return collect();
        }

        return $this->listAll()->where('type', $type)->values();
    }

    public function findByGlobalId(string $globalId): ?array
    {
        $parsed = self::parseGlobalId($globalId);
        if (! $parsed) {
            return null;
        }

        return $this->listAll()->firstWhere('global_id', $globalId);
    }

    private function usageCountForAttribute(int $attributeId): int
    {
        return ItemWizardStep::query()
            ->where('source_type', 'item_attribute')
            ->where(function ($query) use ($attributeId): void {
                $query->where('source_item_attribute_id', $attributeId)
                    ->orWhere('source_ref', (string) $attributeId);
            })
            ->count();
    }

    private function usageCountForExtra(int $extraId): int
    {
        $extra = ItemExtra::query()->find($extraId);
        if (! $extra || ! $extra->group_label) {
            return 0;
        }

        return ItemWizardStep::query()
            ->where('source_type', 'extra_group')
            ->where('source_ref', $extra->group_label)
            ->count();
    }
}
