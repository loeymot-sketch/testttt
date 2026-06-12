<?php

namespace App\Services\Ingredients;

use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemWizardProfile;
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
        // V1.6+ : déduplication par **nom logique** pour matcher la doctrine
        // "1 ingrédient = 1 nom partagé entre items" (cf. plan E2E heal + audit
        // utilisateur 2026-05-04 : 535 ItemExtra rows mais ~43 noms distincts ;
        // l'écran Ingrédients affichait 730 lignes au lieu de ~50 attendues).
        // Toggle disponibilité cascade aussi par nom — voir IngredientAvailabilityService.

        // Attributes : group by name (rare, mais "Viande 1" / "Viande 2" partagent
        // potentiellement même nom si plusieurs items ont leur propre wizard step).
        $attributesByName = ItemAttribute::query()->get()->groupBy(fn (ItemAttribute $a): string => (string) $a->name);
        $attributes = $attributesByName->map(function ($group, string $name): array {
            /** @var \Illuminate\Support\Collection<int, ItemAttribute> $group */
            $representative = $group->sortBy('id')->first();
            $allAvailable = $group->every(fn (ItemAttribute $a) => (bool) ($a->is_available ?? true));
            $reason = $group->firstWhere(fn (ItemAttribute $a) => ! ($a->is_available ?? true))?->unavailable_reason;

            return [
                'global_id' => self::globalId(self::TYPE_ATTRIBUTE, (int) $representative->id),
                'type' => self::TYPE_ATTRIBUTE,
                'id' => (int) $representative->id,
                'name' => $name,
                'is_available' => $allAvailable,
                'unavailable_reason' => $allAvailable ? null : $reason,
                'used_by_count' => $group->sum(fn (ItemAttribute $a) => $this->usageCountForAttribute((int) $a->id)),
            ];
        })->values();

        // Extras : group by (name + group_label) — un extra "Sauce" dans le groupe
        // "burger.sauces" est un ingrédient distinct du "Sauce" dans "tacos.sauces".
        $extrasByKey = ItemExtra::query()->get()->groupBy(
            fn (ItemExtra $e): string => (string) $e->name . '||' . ($e->group_label ?? '')
        );
        $extras = $extrasByKey->map(function ($group): array {
            /** @var \Illuminate\Support\Collection<int, ItemExtra> $group */
            $representative = $group->sortBy('id')->first();
            $allAvailable = $group->every(fn (ItemExtra $e) => (bool) ($e->is_available ?? true));
            $reason = $group->firstWhere(fn (ItemExtra $e) => ! ($e->is_available ?? true))?->unavailable_reason;

            return [
                'global_id' => self::globalId(self::TYPE_EXTRA, (int) $representative->id),
                'type' => self::TYPE_EXTRA,
                'id' => (int) $representative->id,
                'name' => (string) $representative->name,
                'group_label' => $representative->group_label,
                'is_available' => $allAvailable,
                'unavailable_reason' => $allAvailable ? null : $reason,
                'used_by_count' => $group->count(),
            ];
        })->values();

        // Addons : group by addon_item_id (= item produit réutilisé). Si "Coca-Cola 33cl"
        // est addon de 32 produits parents, on affiche 1 ligne avec used_by_count=32 — sinon
        // l'écran "Ingrédients" listait 184 addons pour ~30 boissons/accompagnements distincts.
        $addonsByItem = ItemAddon::query()->with('addonItem')->get()->groupBy(fn (ItemAddon $a) => (int) $a->addon_item_id);
        $addons = $addonsByItem->map(function ($group): array {
            /** @var \Illuminate\Support\Collection<int, ItemAddon> $group */
            $representative = $group->sortBy('id')->first();

            return [
                'global_id' => self::globalId(self::TYPE_ADDON, (int) $representative->id),
                'type' => self::TYPE_ADDON,
                'id' => (int) $representative->id,
                'name' => $representative->addonItem?->name ?? "Addon #{$representative->id}",
                'role' => $representative->role,
                'is_available' => true,
                'unavailable_reason' => null,
                'used_by_count' => $group->count(),
            ];
        })->values();

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

    /**
     * Usage drill-down for admin: wizard steps that reference this ingredient, plus addon owner item.
     *
     * @return array<string, mixed>|null null when global id is invalid or ingredient row is missing
     */
    public function usageDetailsForGlobalId(string $globalId): ?array
    {
        $parsed = self::parseGlobalId($globalId);
        if (! $parsed) {
            return null;
        }

        $snapshot = $this->findByGlobalId($globalId);
        if (! $snapshot) {
            return null;
        }

        $usedBy = match ($parsed['type']) {
            self::TYPE_ATTRIBUTE => $this->usedByRowsForAttribute((int) $parsed['id']),
            self::TYPE_EXTRA => $this->usedByRowsForExtra((int) $parsed['id']),
            self::TYPE_ADDON => $this->usedByRowsForAddon((int) $parsed['id']),
            default => [],
        };

        $this->sortUsedBy($usedBy);

        return [
            'global_id' => (string) $snapshot['global_id'],
            'type' => (string) $snapshot['type'],
            'id' => (int) $snapshot['id'],
            'name' => (string) $snapshot['name'],
            'is_available' => (bool) $snapshot['is_available'],
            'used_by' => $usedBy,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usedByRowsForAttribute(int $attributeId): array
    {
        $steps = ItemWizardStep::query()
            ->where('source_type', 'item_attribute')
            ->where(function ($query) use ($attributeId): void {
                $query->where('source_item_attribute_id', $attributeId)
                    ->orWhere('source_ref', (string) $attributeId);
            })
            ->with(['profile.category', 'profile.item'])
            ->get();

        return $this->mapStepsToUsedBy($steps);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usedByRowsForExtra(int $extraId): array
    {
        $extra = ItemExtra::query()->find($extraId);
        if (! $extra) {
            return [];
        }

        // [W-REM T-R2.4 — D-B1-01 2026-06-12] Drawer menteur : la liste groupe
        // les extras par name||group_label et compte les rows ItemExtra
        // (« Utilisé dans 8 produit(s) »), mais ce drill-down ne résolvait
        // l'usage QUE via les wizard steps matchés sur group_label → tout
        // extra legacy SANS group_label répondait « Non utilisé » (8 vs 0,
        // risque de suppression cassante). Fallback par NOM : les items
        // propriétaires des rows de même nom (group_label null) SONT l'usage.
        if (! $extra->group_label) {
            return $this->usedByRowsForGrouplessExtra($extra);
        }

        $steps = ItemWizardStep::query()
            ->where('source_type', 'extra_group')
            ->where('source_ref', $extra->group_label)
            ->with(['profile.category', 'profile.item'])
            ->get();

        return $this->mapStepsToUsedBy($steps);
    }

    /**
     * [W-REM T-R2.4 — D-B1-01] By-name usage for legacy extras without a
     * group_label: each ItemExtra row of the same name (group_label null)
     * belongs to an item — those owner items are the usage, in parity with
     * the list's `used_by_count = $group->count()`.
     *
     * @return list<array<string, mixed>>
     */
    private function usedByRowsForGrouplessExtra(ItemExtra $extra): array
    {
        $rows = ItemExtra::query()
            ->where('name', (string) $extra->name)
            ->whereNull('group_label')
            // withTrashed : les rows legacy pointent souvent des items
            // archivés (purge catalogue 2026-05-28) — le drawer doit les
            // NOMMER (« X (archivé) ») plutôt que rendre "Article #N".
            ->with(['item' => fn ($query) => $query->withTrashed()])
            ->get();

        $usedBy = [];
        $seenItemIds = [];
        foreach ($rows as $row) {
            $ownerId = (int) $row->item_id;
            if ($ownerId <= 0 || isset($seenItemIds[$ownerId])) {
                continue;
            }
            $seenItemIds[$ownerId] = true;

            $ownerItem = $row->item;
            $ownerName = (string) ($ownerItem?->name ?? "Article #{$ownerId}");
            if ($ownerItem && $ownerItem->trashed()) {
                $ownerName .= ' (archivé)';
            }
            $wizardProfileId = ItemWizardProfile::query()
                ->where('item_id', $ownerId)
                ->value('id');

            $usedBy[] = [
                'owner_type' => 'item',
                'owner_id' => $ownerId,
                'owner_name' => $ownerName,
                'step_key' => 'extra',
                'step_label' => 'Supplément',
                'wizard_profile_id' => $wizardProfileId !== null ? (int) $wizardProfileId : null,
                'admin_url' => "/admin/items/{$ownerId}/composer",
            ];
        }

        return $usedBy;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function usedByRowsForAddon(int $addonId): array
    {
        $addon = ItemAddon::query()->with(['item'])->find($addonId);
        if (! $addon) {
            return [];
        }

        $ownerItem = $addon->item;
        if (! $ownerItem) {
            return [];
        }

        $ownerId = (int) $addon->item_id;
        $ownerName = (string) ($ownerItem->name ?? "Article #{$ownerId}");
        $wizardProfileId = ItemWizardProfile::query()
            ->where('item_id', $ownerId)
            ->value('id');

        return [[
            'owner_type' => 'item',
            'owner_id' => $ownerId,
            'owner_name' => $ownerName,
            'step_key' => 'addon',
            'step_label' => 'Addon',
            'wizard_profile_id' => $wizardProfileId !== null ? (int) $wizardProfileId : null,
            'admin_url' => "/admin/items/{$ownerId}/composer",
        ]];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ItemWizardStep>  $steps
     * @return list<array<string, mixed>>
     */
    private function mapStepsToUsedBy(Collection $steps): array
    {
        $rows = [];

        foreach ($steps as $step) {
            $profile = $step->profile;
            if ($profile === null) {
                continue;
            }

            $ownerType = $profile->item_category_id !== null ? 'category' : 'item';
            $ownerId = $ownerType === 'category' ? (int) $profile->item_category_id : (int) $profile->item_id;

            if ($ownerType === 'item' && (! $profile->item_id || (int) $profile->item_id === 0)) {
                continue;
            }

            $ownerName = $ownerType === 'category'
                ? (string) ($profile->category?->name ?? "Catégorie #{$ownerId}")
                : (string) ($profile->item?->name ?? "Article #{$ownerId}");

            $adminUrl = $ownerType === 'category'
                ? "/admin/categories/{$ownerId}/composer"
                : "/admin/items/{$ownerId}/composer";

            $rows[] = [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'owner_name' => $ownerName,
                'step_key' => (string) $step->step_key,
                'step_label' => (string) $step->label,
                'wizard_profile_id' => (int) $profile->id,
                'admin_url' => $adminUrl,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $usedBy
     */
    private function sortUsedBy(array &$usedBy): void
    {
        usort($usedBy, static function (array $a, array $b): int {
            $rank = static fn (string $type): int => $type === 'category' ? 0 : 1;
            $byType = $rank($a['owner_type']) <=> $rank($b['owner_type']);
            if ($byType !== 0) {
                return $byType;
            }

            return strcmp($a['owner_name'], $b['owner_name']);
        });
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
        if (! $extra) {
            return 0;
        }

        // [W-REM T-R2.4 — D-B1-01] Symétrie avec usedByRowsForExtra : un
        // extra sans group_label compte ses items propriétaires by-name
        // (avant : 0 systématique, scellait la divergence liste/drawer).
        if (! $extra->group_label) {
            return count($this->usedByRowsForGrouplessExtra($extra));
        }

        return ItemWizardStep::query()
            ->where('source_type', 'extra_group')
            ->where('source_ref', $extra->group_label)
            ->count();
    }
}
