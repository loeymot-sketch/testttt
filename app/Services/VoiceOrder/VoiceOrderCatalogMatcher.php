<?php

namespace App\Services\VoiceOrder;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Str;

class VoiceOrderCatalogMatcher
{
    /** @return array<int, array{id:int,name:string,category_id:int,slots:array<int,string>,options:array<int,string>}> */
    public function catalog(int $branchId): array
    {
        $categoryIds = ItemCategory::query()
            ->where('status', Status::ACTIVE)
            ->get(['id', 'channels'])
            ->filter(fn (ItemCategory $category) => $category->isVisibleOn('pos'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($categoryIds === []) {
            return [];
        }

        // [CHEF 2026-09-02 · §9] Filtre de branche explicite ci-dessous : on retire
        // BranchScope au SINGULIER. Le pluriel retirait aussi SoftDeletingScope —
        // sans effet ici (ItemBranchAvailability n'utilise pas SoftDeletes) mais il
        // masquait l'intention et faisait échouer la sentinelle Z6-P1-WGS.
        $unavailable = ItemBranchAvailability::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('is_available', false)
            ->pluck('item_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return Item::query()
            ->where('status', Status::ACTIVE)
            ->with([
                'variations' => fn ($query) => $query->select('id', 'item_id', 'item_attribute_id', 'name', 'status', 'visible_on')->with('itemAttribute:id,name'),
                'extras' => fn ($query) => $query->select('id', 'item_id', 'name', 'status', 'visible_on', 'group_label', 'is_available'),
            ])
            ->orderBy('name')
            ->whereIn('item_category_id', $categoryIds)
            ->limit(600)
            ->get(['id', 'name', 'item_category_id', 'channels', 'status', 'is_available'])
            ->filter(fn (Item $item) => $item->isVisibleOn('pos')
                && $item->is_available !== false
                && ! in_array((int) $item->id, $unavailable, true))
            ->map(function (Item $item) {
                $variations = $item->variations->filter(fn ($variation) => $variation->isVisibleOn('pos'));
                $extras = $item->extras->filter(fn ($extra) => $extra->isVisibleOn('pos') && $extra->is_available !== false);

                return [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                    'category_id' => (int) $item->item_category_id,
                    'slots' => $variations
                        ->map(fn ($variation) => trim((string) ($variation->itemAttribute?->name ?? '')))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'options' => $variations->pluck('name')
                        ->merge($extras->pluck('name'))
                        ->map(fn ($name) => trim((string) $name))
                        ->filter()
                        ->unique()
                        ->take(80)
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function deterministic(string $transcript, array $catalog): array
    {
        $normalizedTranscript = $this->normalize($transcript);
        $candidates = collect($catalog)
            ->map(fn (array $item) => $item + ['normalized_name' => $this->normalize($item['name'])])
            ->filter(fn (array $item) => mb_strlen($item['normalized_name']) >= 3)
            ->sortByDesc(fn (array $item) => mb_strlen($item['normalized_name']));

        $lines = [];
        $occupiedNames = [];
        foreach ($candidates as $item) {
            if (! str_contains($normalizedTranscript, $item['normalized_name'])) {
                continue;
            }
            if (collect($occupiedNames)->contains(fn (string $name) => str_contains($name, $item['normalized_name']))) {
                continue;
            }

            $quantity = $this->quantityBefore($normalizedTranscript, $item['normalized_name']);
            $lines[] = [
                'item_id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'quantity' => $quantity,
                'notes' => null,
                'confidence' => 0.88,
                'missing_slots' => array_slice((array) $item['slots'], 0, 8),
                'needs_review' => true,
            ];
            $occupiedNames[] = $item['normalized_name'];
        }

        return [
            'source' => 'deterministic',
            'lines' => $lines,
            'ambiguities' => $lines === [] && trim($transcript) !== ''
                ? ['Aucun produit du catalogue n’a été reconnu avec assez de certitude.']
                : [],
            'needs_review' => true,
            'generated_at' => now()->toISOString(),
        ];
    }

    public function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function quantityBefore(string $transcript, string $name): int
    {
        $quoted = preg_quote($name, '/');
        if (preg_match('/(?:^|\s)([1-9]|1[0-9]|20)\s+(?:[a-z]+\s+){0,2}'.$quoted.'/u', $transcript, $matches)) {
            return max(1, min(20, (int) $matches[1]));
        }

        $words = [
            'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4,
            'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10,
        ];
        if (preg_match('/(?:^|\s)('.implode('|', array_keys($words)).')\s+(?:[a-z]+\s+){0,2}'.$quoted.'/u', $transcript, $matches)) {
            return $words[$matches[1]];
        }

        return 1;
    }
}
