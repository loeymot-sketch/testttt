<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\RawMaterialRecipeLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [OWNER SANDWICH-CLASSIQUE 2026-08-12] Duplique « Cayenne » (#22, sandwich) et « Galette
 * Cayenne » (#24) en « Sandwich Classique » / « Galette Classique » : MÊMES choix de viande,
 * sauce, crudités, suppléments, MÊME prix — SANS le mélange fromager du Cayenne (Cheddar +
 * Sauce maison, forcés dans la recette matière première de chaque vente, cf.
 * raw_material_recipe_lines). Ces deux ingrédients restent néanmoins des SAUCES/SUPPLÉMENTS
 * sélectionnables au wizard comme avant (« Fromagère maison », « Cheddar » supplément) — seul
 * le mélange FORCÉ à la base disparaît, pas le choix client.
 *
 * Le nom cuisine (sans « Cayenne », juste S/G + la viande) est géré à part dans
 * KitchenTicketSymbolicFormatter::produitCode()/mainLine() (et son jumeau JS kdsSymbolic.js) —
 * ce n'est pas une donnée DB.
 *
 * Idempotent : ré-exécutable sans dupliquer (items par slug, variations/extras par nom+attr,
 * lignes recette par matière première).
 */
class AddSandwichClassiqueCommand extends Command
{
    protected $signature = 'menu:add-sandwich-classique {--dry-run : compter sans écrire}';

    protected $description = 'Duplique Cayenne/Galette Cayenne en Sandwich Classique/Galette Classique (mêmes choix et prix, sans le mélange fromager forcé).';

    /** @var array<int, array{source: string, name: string, slug: string, description: string, exclude_raw_materials: list<int>}> */
    private const CLONES = [
        [
            'source'                 => 'cayenne',
            'name'                   => 'Sandwich Classique',
            'slug'                   => 'sandwich-classique',
            'description'            => 'Poulet mariné, crudités et sauce au choix, comme le Cayenne — sans le mélange fromager.',
            'exclude_raw_materials'  => [3, 7], // Cheddar, Sauce maison
        ],
        [
            'source'                 => 'galette-cayenne',
            'name'                   => 'Galette Classique',
            'slug'                   => 'galette-classique',
            'description'            => 'Galette croustillante, crudités et sauce au choix, comme la Galette Cayenne — sans la sauce fromagère maison.',
            'exclude_raw_materials'  => [7], // Sauce maison
        ],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = ['items' => 0, 'variations' => 0, 'extras' => 0, 'recipe_lines' => 0];

        foreach (self::CLONES as $spec) {
            $source = Item::where('slug', $spec['source'])->whereNull('deleted_at')->first();
            if (! $source) {
                $this->warn("Source « {$spec['source']} » introuvable — clone « {$spec['name']} » sauté.");

                continue;
            }

            $target = $this->ensureItem($source, $spec, $dry, $stats);
            if ($target === null) {
                continue; // dry-run, item pas encore créé : rien à cloner en dessous
            }

            $stats['variations'] += $this->cloneVariations($source->id, $target->id, $dry);
            $stats['extras'] += $this->cloneExtras($source->id, $target->id, $dry);
            $stats['recipe_lines'] += $this->cloneRecipeLines($source->id, $target->id, $spec['exclude_raw_materials'], $dry);
        }

        $prefix = $dry ? '[dry-run] ' : '';
        $this->info("{$prefix}Sandwich/Galette Classique — items:{$stats['items']} variations:{$stats['variations']} extras:{$stats['extras']} recette:{$stats['recipe_lines']}.");

        return self::SUCCESS;
    }

    /** @param array{name: string, slug: string, description: string} $spec */
    private function ensureItem(Item $source, array $spec, bool $dry, array &$stats): ?Item
    {
        $existing = Item::withTrashed()->where('slug', $spec['slug'])->first();
        if ($existing) {
            if ($existing->trashed()) {
                if ($dry) {
                    return null;
                }
                $existing->restore();
            }

            return $existing;
        }

        if ($dry) {
            $stats['items']++;

            return null;
        }

        $payload = $source->only([
            'item_category_id', 'tax_id', 'item_type', 'price', 'is_featured', 'is_upsell',
            'is_chef_pick', 'is_new', 'is_spicy', 'is_vegetarian', 'is_pork_free', 'is_halal',
            'is_gluten_free', 'channels', 'allergen_flags', 'kiosk_emoji', 'kds_station',
        ]);
        $payload['name'] = $spec['name'];
        $payload['slug'] = $spec['slug'];
        $payload['description'] = $spec['description'];
        $payload['status'] = Status::ACTIVE;
        $payload['is_available'] = 1;
        $payload['order'] = 0;

        $stats['items']++;

        return Item::create($payload);
    }

    private function cloneVariations(int $sourceItemId, int $targetItemId, bool $dry): int
    {
        $created = 0;
        $rows = ItemVariation::where('item_id', $sourceItemId)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        foreach ($rows as $row) {
            $exists = ItemVariation::where('item_id', $targetItemId)
                ->where('item_attribute_id', $row->item_attribute_id)
                ->where('name', $row->name)
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if ($dry) {
                continue;
            }
            ItemVariation::create([
                'item_id'           => $targetItemId,
                'item_attribute_id' => $row->item_attribute_id,
                'name'              => $row->name,
                'price'             => $row->price,
                'caution'           => $row->caution,
                'status'            => $row->status,
                'visible_on'        => $row->visible_on,
            ]);
        }

        return $created;
    }

    private function cloneExtras(int $sourceItemId, int $targetItemId, bool $dry): int
    {
        $created = 0;
        $rows = ItemExtra::where('item_id', $sourceItemId)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();

        foreach ($rows as $row) {
            $exists = ItemExtra::where('item_id', $targetItemId)
                ->where('group_label', $row->group_label)
                ->where('name', $row->name)
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if ($dry) {
                continue;
            }
            ItemExtra::create([
                'item_id'      => $targetItemId,
                'name'         => $row->name,
                'status'       => $row->status,
                'price'        => $row->price,
                'visible_on'   => $row->visible_on,
                'group_label'  => $row->group_label,
                'is_available' => $row->is_available,
            ]);
        }

        return $created;
    }

    /** @param list<int> $excludeRawMaterials */
    private function cloneRecipeLines(int $sourceItemId, int $targetItemId, array $excludeRawMaterials, bool $dry): int
    {
        $created = 0;
        $branchId = (int) (RawMaterialRecipeLine::where('subject_type', Item::class)
            ->where('subject_id', $sourceItemId)
            ->value('branch_id') ?? 1);

        $rows = RawMaterialRecipeLine::where('subject_type', Item::class)
            ->where('subject_id', $sourceItemId)
            ->whereNotIn('raw_material_id', $excludeRawMaterials)
            ->get();

        foreach ($rows as $row) {
            $exists = RawMaterialRecipeLine::where('subject_type', Item::class)
                ->where('subject_id', $targetItemId)
                ->where('raw_material_id', $row->raw_material_id)
                ->exists();
            if ($exists) {
                continue;
            }
            $created++;
            if ($dry) {
                continue;
            }
            RawMaterialRecipeLine::create([
                'branch_id'      => $branchId,
                'subject_type'   => Item::class,
                'subject_id'     => $targetItemId,
                'subject_group'  => $row->subject_group,
                'raw_material_id' => $row->raw_material_id,
                'qty'            => $row->qty,
                'note'           => $row->note,
            ]);
        }

        return $created;
    }
}
