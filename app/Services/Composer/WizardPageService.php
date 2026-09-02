<?php

namespace App\Services\Composer;

use App\Enums\Status;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bibliothèque de pages de wizard : création, modification, copie privée par catégorie, usages.
 */
class WizardPageService
{
    /** kind connu ⇒ type de source imposé (cohérence avec les écrans caisse/borne). */
    private const KIND_SOURCE = [
        'pain' => 'item_attribute',
        'taille' => 'item_attribute',
        'viande' => 'item_attribute',
        'sauce' => 'item_attribute',
        'garnitures' => 'extra_group',
        'supplements' => 'extra_group',
        'menu' => 'addon',
    ];

    /**
     * Pages visibles pour une catégorie : la bibliothèque + ses pages privées.
     *
     * @return Collection<int, WizardPage>
     */
    public function listFor(?int $categoryId): Collection
    {
        $pages = WizardPage::query()
            ->with(['choices', 'itemAttribute', 'ownerCategory'])
            ->withCount('steps')
            ->visibleFor($categoryId)
            ->orderBy('owner_category_id')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        // [2026-09-02] La liste annonçait « N catégorie(s) » à partir d'un champ qui n'existait pas :
        // impossible de voir qu'une page est partagée AVANT de la modifier. `steps_count` ne peut pas
        // servir (il compte aussi les étapes des clones produit, une par produit). Une seule requête
        // pour toutes les pages, pas une par ligne.
        $counts = $this->categoryUsageCounts($pages->pluck('id')->all());
        foreach ($pages as $page) {
            $page->setAttribute('usage_count', $counts[$page->id] ?? 0);
        }

        return $pages;
    }

    /**
     * Nombre de catégories DISTINCTES qui utilisent chaque page, en une requête.
     *
     * @param  array<int, int>  $pageIds
     * @return array<int, int>
     */
    private function categoryUsageCounts(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $rows = ItemWizardStep::query()
            ->whereIn('item_wizard_steps.wizard_page_id', $pageIds)
            ->join('item_wizard_profiles', 'item_wizard_profiles.id', '=', 'item_wizard_steps.profile_id')
            ->leftJoin('items', 'items.id', '=', 'item_wizard_profiles.item_id')
            ->selectRaw('item_wizard_steps.wizard_page_id as page_id, COALESCE(item_wizard_profiles.item_category_id, items.item_category_id) as category_id')
            ->distinct()
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ($row->category_id === null) {
                continue;
            }
            $out[(int) $row->page_id] = ($out[(int) $row->page_id] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Catégories qui utilisent la page (via une étape de leur wizard ou d'un clone produit).
     *
     * @return array<int, array{id: int, name: string, published: bool}>
     */
    public function usage(WizardPage $page): array
    {
        $profiles = ItemWizardProfile::query()
            ->whereIn('id', ItemWizardStep::query()->where('wizard_page_id', $page->id)->select('profile_id'))
            ->with(['category:id,name', 'item:id,name,item_category_id'])
            ->get();

        $byCategory = [];
        foreach ($profiles as $profile) {
            $categoryId = $profile->item_category_id
                ?: ($profile->item?->item_category_id ?? null);
            if (! $categoryId) {
                continue;
            }
            $name = $profile->category?->name
                ?? ItemCategory::query()->whereKey($categoryId)->value('name')
                ?? ('#'.$categoryId);
            $byCategory[$categoryId] ??= ['id' => (int) $categoryId, 'name' => (string) $name, 'published' => false];
            if ($profile->is_published) {
                $byCategory[$categoryId]['published'] = true;
            }
        }

        return array_values($byCategory);
    }

    public function create(array $data, ?ItemCategory $owner = null): WizardPage
    {
        $data = $this->normalize($data);
        $ownerId = $owner?->id ?? ($data['owner_category_id'] ?? null);
        $data['owner_category_id'] = $ownerId ? (int) $ownerId : null;
        $data['key'] = $this->resolveKey($data['key'] ?? null, $data['label'], $data['owner_category_id']);

        return DB::transaction(function () use ($data): WizardPage {
            $choices = $data['choices'] ?? [];
            unset($data['choices']);

            $page = WizardPage::create($data);
            $this->ensureAttributeFor($page);
            $this->syncChoices($page, $choices);

            return $page->fresh(['choices', 'itemAttribute']);
        });
    }

    /**
     * Mise à jour PARTIELLE : ce que l'appelant n'envoie pas garde sa valeur en base.
     *
     * [2026-09-02] Avant, seuls `kind`, `source_type` et `label` étaient repris de la page : tout le
     * reste retombait sur les valeurs par défaut de `normalize()`. L'écran n'envoyant que 8 champs,
     * corriger un prix suffisait à mettre `item_attribute_id` à `null` — et `ensureAttributeFor()`
     * recréait alors un attribut NEUF, orphelinant les variations produits que la caisse lit.
     * Idem pour `extra_group_label` (plus aucun supplément servi), `allow_repeat`,
     * `stockable_choices`, `description` et `sort`. Couvert par `ModifierUnePageNeCasseRienTest`.
     */
    public function update(WizardPage $page, array $data): WizardPage
    {
        $data = $this->normalize($data + [
            'kind' => $page->kind,
            'source_type' => $page->source_type,
            'label' => $page->label,
            'item_attribute_id' => $page->item_attribute_id,
            'extra_group_label' => $page->extra_group_label,
            'addon_role' => $page->addon_role,
            'min_select' => $page->min_select,
            'max_select' => $page->max_select,
            'allow_repeat' => $page->allow_repeat,
            'visible_on' => $page->visible_on,
            'stockable_choices' => $page->stockable_choices,
            'is_active' => $page->is_active,
            'description' => $page->description,
            'sort' => $page->sort,
        ]);
        unset($data['owner_category_id']);
        if (array_key_exists('key', $data)) {
            $data['key'] = $this->resolveKey($data['key'], $data['label'], $page->owner_category_id, $page->id);
        }

        return DB::transaction(function () use ($page, $data): WizardPage {
            $choices = $data['choices'] ?? null;
            unset($data['choices']);

            $page->update($data);
            $this->ensureAttributeFor($page);
            if (is_array($choices)) {
                $this->syncChoices($page, $choices);
            }

            return $page->fresh(['choices', 'itemAttribute']);
        });
    }

    public function delete(WizardPage $page): void
    {
        $usage = $this->usage($page);
        if ($usage !== []) {
            throw ValidationException::withMessages([
                'page' => sprintf(
                    'Cette page est utilisée par %s. Retirez-la de ces wizards avant de la supprimer.',
                    implode(', ', array_map(fn (array $u): string => '« '.$u['name'].' »', $usage))
                ),
            ]);
        }

        DB::transaction(function () use ($page): void {
            $attributeId = $page->item_attribute_id;
            $page->choices()->delete();
            $page->delete();
            $this->dropOrphanAttribute($attributeId, $page->id);
        });
    }

    /**
     * [2026-09-02] Une page attribut crée son `ItemAttribute` à l'enregistrement. Supprimer la page
     * laissait l'attribut derrière elle : la liste « Attribut d'articles » se remplissait d'entrées
     * fantômes que personne ne pouvait relier à quoi que ce soit. On ne le retire QUE s'il ne sert
     * plus à rien : aucune variation produit, aucune autre page, aucune étape de wizard.
     */
    private function dropOrphanAttribute(?int $attributeId, int $deletedPageId): void
    {
        if (! $attributeId) {
            return;
        }

        $encoreUtilise = ItemVariation::query()->where('item_attribute_id', $attributeId)->exists()
            || WizardPage::query()->where('item_attribute_id', $attributeId)->whereKeyNot($deletedPageId)->exists()
            || ItemWizardStep::query()->where('source_item_attribute_id', $attributeId)->exists();

        if ($encoreUtilise) {
            return;
        }

        ItemAttribute::query()->whereKey($attributeId)->delete();
    }

    /**
     * Copie privée : la catégorie peut la modifier sans toucher la bibliothèque. Pour une page
     * attribut, l'attribut est partagé (les variations restent par produit) — c'est ce qui permet à la
     * borne de garder son écran dédié (« Viande », « Sauce »…).
     */
    public function duplicateForCategory(WizardPage $page, ItemCategory $category): WizardPage
    {
        return DB::transaction(function () use ($page, $category): WizardPage {
            $existing = WizardPage::query()
                ->where('owner_category_id', $category->id)
                ->where('key', $page->key)
                ->first();
            if ($existing) {
                return $existing->load(['choices', 'itemAttribute']);
            }

            $copy = $page->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
            $copy->owner_category_id = $category->id;
            $copy->save();

            foreach ($page->choices as $choice) {
                $clone = $choice->replicate(['id', 'wizard_page_id', 'created_at', 'updated_at', 'deleted_at']);
                $clone->wizard_page_id = $copy->id;
                $clone->save();
            }

            return $copy->fresh(['choices', 'itemAttribute']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $choices
     */
    public function syncChoices(WizardPage $page, array $choices): void
    {
        $existing = $page->choices()->get()->keyBy('id');
        $kept = [];
        $sort = 0;

        foreach ($choices as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $payload = [
                'name' => $name,
                'price' => max(0, (float) ($row['price'] ?? 0)),
                'addon_item_id' => isset($row['addon_item_id']) && $row['addon_item_id'] !== '' ? (int) $row['addon_item_id'] : null,
                'sort' => isset($row['sort']) && is_numeric($row['sort']) ? (int) $row['sort'] : $sort,
                'status' => isset($row['status']) && (int) $row['status'] === Status::INACTIVE ? Status::INACTIVE : Status::ACTIVE,
                'visible_on' => isset($row['visible_on']) && is_array($row['visible_on']) && $row['visible_on'] !== [] ? array_values($row['visible_on']) : null,
            ];
            $sort++;

            $id = isset($row['id']) ? (int) $row['id'] : 0;
            if ($id > 0 && $existing->has($id)) {
                $existing->get($id)->update($payload);
                $kept[$id] = true;
                continue;
            }

            $created = $page->choices()->create($payload);
            $kept[$created->id] = true;
        }

        foreach ($existing as $id => $choice) {
            if (! isset($kept[$id])) {
                $choice->delete();
            }
        }
    }

    private function ensureAttributeFor(WizardPage $page): void
    {
        if ($page->source_type !== 'item_attribute') {
            if ($page->item_attribute_id !== null) {
                $page->forceFill(['item_attribute_id' => null])->save();
            }

            return;
        }

        if ($page->item_attribute_id && ItemAttribute::query()->whereKey($page->item_attribute_id)->exists()) {
            return;
        }

        $attribute = ItemAttribute::create([
            'name' => $page->label,
            'status' => Status::ACTIVE,
            'min_select' => (int) $page->min_select,
            'max_select' => max((int) $page->max_select, (int) $page->min_select),
            'allow_repeat' => (bool) $page->allow_repeat,
        ]);
        $page->forceFill(['item_attribute_id' => $attribute->id])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $kind = (string) ($data['kind'] ?? 'generic');
        if (! in_array($kind, WizardPage::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Type de page inconnu.']);
        }

        $sourceType = (string) ($data['source_type'] ?? (self::KIND_SOURCE[$kind] ?? 'item_attribute'));
        if (! in_array($sourceType, WizardPage::SOURCE_TYPES, true)) {
            throw ValidationException::withMessages(['source_type' => 'Type de source inconnu.']);
        }
        if (isset(self::KIND_SOURCE[$kind]) && self::KIND_SOURCE[$kind] !== $sourceType) {
            throw ValidationException::withMessages([
                'source_type' => sprintf('Une page « %s » tire ses choix de « %s ».', $kind, self::KIND_SOURCE[$kind]),
            ]);
        }

        $min = max(0, (int) ($data['min_select'] ?? 0));
        $max = max($min, (int) ($data['max_select'] ?? 1));

        $visibleOn = $data['visible_on'] ?? null;
        $visibleOn = is_array($visibleOn) && $visibleOn !== [] ? array_values(array_unique(array_map('strval', $visibleOn))) : null;

        $addonRole = $sourceType === 'addon' ? ($data['addon_role'] ?? null) : null;
        if ($sourceType === 'addon' && ($addonRole === null || $addonRole === '')) {
            $addonRole = $kind === 'menu' ? 'menu_component' : 'upsell';
        }

        $out = [
            'label' => trim((string) ($data['label'] ?? '')),
            'kind' => $kind,
            'source_type' => $sourceType,
            'item_attribute_id' => $sourceType === 'item_attribute' && ! empty($data['item_attribute_id']) ? (int) $data['item_attribute_id'] : null,
            'extra_group_label' => $sourceType === 'extra_group' ? (trim((string) ($data['extra_group_label'] ?? '')) ?: null) : null,
            'addon_role' => $addonRole,
            'min_select' => $min,
            'max_select' => $max,
            'allow_repeat' => (bool) ($data['allow_repeat'] ?? false),
            'visible_on' => $visibleOn,
            'stockable_choices' => (bool) ($data['stockable_choices'] ?? false),
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            'description' => isset($data['description']) ? (trim((string) $data['description']) ?: null) : null,
            'sort' => max(0, (int) ($data['sort'] ?? 0)),
        ];
        if (array_key_exists('key', $data)) {
            $out['key'] = $data['key'];
        }
        if (array_key_exists('owner_category_id', $data)) {
            $out['owner_category_id'] = $data['owner_category_id'];
        }
        if (array_key_exists('choices', $data)) {
            $out['choices'] = is_array($data['choices']) ? $data['choices'] : [];
        }
        if ($out['label'] === '') {
            throw ValidationException::withMessages(['label' => 'Le nom de la page est obligatoire.']);
        }

        return $out;
    }

    private function resolveKey(?string $requested, string $label, ?int $ownerId, ?int $ignoreId = null): string
    {
        $key = Str::slug(trim((string) ($requested ?: $label)), '_');
        $key = preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($key)) ?: '';
        $key = trim($key, '_');
        if ($key === '') {
            $key = 'page';
        }
        $key = mb_substr($key, 0, 100);

        $base = $key;
        $suffix = 2;
        while ($this->keyTaken($key, $ownerId, $ignoreId)) {
            $key = mb_substr($base, 0, 96).'_'.$suffix;
            $suffix++;
        }

        return $key;
    }

    private function keyTaken(string $key, ?int $ownerId, ?int $ignoreId): bool
    {
        return WizardPage::query()
            ->where('key', $key)
            ->where(function ($q) use ($ownerId): void {
                $ownerId === null ? $q->whereNull('owner_category_id') : $q->where('owner_category_id', $ownerId);
            })
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
