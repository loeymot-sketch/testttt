<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardStep;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use App\Support\Menu\SauceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [GOAL DASHBOARD-PILOTABLE 2026-09-02] Construit la bibliothèque de pages de wizard du Cayenne à
 * partir de ce qui existe DÉJÀ en base (attributs, variations, extras, addons) — sans inventer un seul
 * choix — puis relie chaque étape existante des wizards à sa page.
 *
 * Idempotente : une page déjà présente n'est pas réécrite (les prix édités par le gérant sont
 * conservés) ; seuls les choix manquants sont ajoutés. Ne matérialise rien et ne publie rien : c'est le
 * rôle de `composer:materialize`.
 *
 * Sur une base vide (tests, installation neuve sans catalogue) : ne fait rien.
 */
class WizardPagesBootstrapCommand extends Command
{
    protected $signature = 'wizard-pages:bootstrap {--dry-run : Affiche le plan sans écrire en base}';

    protected $description = 'Crée la bibliothèque de pages de wizard depuis le catalogue existant et relie les étapes des wizards';

    /** Alias de group_label → clé de page. */
    private const EXTRA_GROUP_PAGES = [
        'crudite' => 'garnitures', 'crudites' => 'garnitures', 'crudité' => 'garnitures', 'crudités' => 'garnitures',
        'garniture' => 'garnitures', 'garnitures' => 'garnitures',
        'supplement' => 'supplements', 'supplements' => 'supplements', 'supplément' => 'supplements', 'suppléments' => 'supplements',
        'extra' => 'supplements', 'extras' => 'supplements', 'default' => 'supplements',
        'supplement_bol' => 'supplement_bol',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = self::bootstrap($dry);

        foreach ($report['lines'] as $line) {
            $this->line($line);
        }
        $this->info(sprintf(
            '%s — %d page(s) créée(s), %d choix ajouté(s), %d étape(s) reliée(s)',
            $dry ? 'SIMULATION' : 'APPLIQUÉ',
            $report['pages_created'], $report['choices_added'], $report['steps_linked']
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{pages_created: int, choices_added: int, steps_linked: int, lines: array<int, string>}
     */
    public static function bootstrap(bool $dry = false): array
    {
        $report = ['pages_created' => 0, 'choices_added' => 0, 'steps_linked' => 0, 'lines' => []];

        if (ItemAttribute::query()->count() === 0 && Item::query()->count() === 0) {
            $report['lines'][] = 'Catalogue vide : aucune bibliothèque à construire.';

            return $report;
        }

        $run = function () use (&$report, $dry): void {
            $definitions = self::definitions();
            $pagesByKey = [];
            foreach ($definitions as $definition) {
                $page = self::upsertPage($definition, $dry, $report);
                if ($page) {
                    $pagesByKey[$definition['key']] = $page;
                }
            }

            self::linkSteps($pagesByKey, $dry, $report);
        };

        if ($dry) {
            $run();
        } else {
            DB::transaction($run);
        }

        return $report;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function definitions(): array
    {
        $attr = fn (array $names): ?ItemAttribute => ItemAttribute::query()
            ->where(function ($q) use ($names): void {
                foreach ($names as $name) {
                    $q->orWhereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
                }
            })
            ->orderBy('id')
            ->first();

        $pain = $attr(['Type de Pain', 'Pain', 'Type de pain']);
        $viande1 = $attr(['Viande 1', 'Viande']);
        $viande2 = $attr(['Viande 2']);
        $viande3 = $attr(['Viande 3']);
        $sauce = $attr(['Sauce (1ère Gratuite)', 'Sauce', 'Sauces']);
        $sauceBol = $attr(['Sauce bol']);

        $sauces = array_values(array_map(fn (array $s): array => ['name' => (string) $s['name'], 'price' => 0], SauceCatalog::all()));

        return [
            [
                'key' => 'pain', 'label' => 'Choisis ton pain', 'kind' => 'pain', 'source_type' => 'item_attribute',
                'attribute' => $pain, 'attribute_name' => 'Type de Pain', 'min' => 1, 'max' => 1,
                'choices' => $pain ? self::variationChoices($pain->id) : [],
            ],
            [
                'key' => 'viande', 'label' => 'Choisis ta viande', 'kind' => 'viande', 'source_type' => 'item_attribute',
                'attribute' => $viande1, 'attribute_name' => 'Viande 1', 'min' => 1, 'max' => 1,
                'choices' => $viande1 ? self::variationChoices($viande1->id) : [],
            ],
            [
                'key' => 'viande_2', 'label' => 'Viande 2', 'kind' => 'viande', 'source_type' => 'item_attribute',
                'attribute' => $viande2, 'attribute_name' => 'Viande 2', 'min' => 0, 'max' => 1,
                'choices' => $viande2 ? self::variationChoices($viande2->id) : [],
            ],
            [
                'key' => 'viande_3', 'label' => 'Viande 3', 'kind' => 'viande', 'source_type' => 'item_attribute',
                'attribute' => $viande3, 'attribute_name' => 'Viande 3', 'min' => 0, 'max' => 1,
                'choices' => $viande3 ? self::variationChoices($viande3->id) : [],
            ],
            [
                'key' => 'sauce', 'label' => 'Choisis ta sauce', 'kind' => 'sauce', 'source_type' => 'item_attribute',
                'attribute' => $sauce, 'attribute_name' => 'Sauce (1ère Gratuite)', 'min' => 1, 'max' => 1,
                'choices' => $sauces !== [] ? $sauces : ($sauce ? self::variationChoices($sauce->id) : []),
            ],
            [
                'key' => 'sauce_bol', 'label' => 'Sauce du bol', 'kind' => 'sauce', 'source_type' => 'item_attribute',
                'attribute' => $sauceBol, 'attribute_name' => 'Sauce bol', 'min' => 1, 'max' => 1,
                'choices' => $sauceBol ? self::variationChoices($sauceBol->id) : $sauces,
            ],
            [
                'key' => 'garnitures', 'label' => 'Choisis tes garnitures', 'kind' => 'garnitures', 'source_type' => 'extra_group',
                'group' => 'crudite', 'min' => 0, 'max' => 6,
                'choices' => self::extraChoices(['crudite', 'crudites', 'crudité', 'crudités', 'garniture', 'garnitures']),
            ],
            [
                'key' => 'supplements', 'label' => 'Suppléments', 'kind' => 'supplements', 'source_type' => 'extra_group',
                'group' => 'supplement', 'min' => 0, 'max' => 5,
                'choices' => self::extraChoices(['supplement', 'supplements', 'supplément', 'suppléments']),
            ],
            [
                'key' => 'supplement_bol', 'label' => 'Suppléments du bol', 'kind' => 'supplements', 'source_type' => 'extra_group',
                'group' => 'supplement_bol', 'min' => 0, 'max' => 4,
                'choices' => self::extraChoices(['supplement_bol']),
            ],
            [
                'key' => 'boisson', 'label' => 'Boisson', 'kind' => 'menu', 'source_type' => 'addon',
                'role' => 'drink', 'min' => 0, 'max' => 1,
                'choices' => self::addonChoices('drink'),
            ],
            [
                'key' => 'menu', 'label' => 'Choisis ta formule', 'kind' => 'menu', 'source_type' => 'addon',
                'role' => 'menu_component', 'min' => 0, 'max' => 1,
                'choices' => self::addonChoices('menu_component'),
            ],
            [
                'key' => 'side', 'label' => 'Accompagnement', 'kind' => 'menu', 'source_type' => 'addon',
                'role' => 'side', 'min' => 0, 'max' => 1,
                'choices' => self::addonChoices('side'),
            ],
        ];
    }

    /**
     * Choix = union des variations (produits non supprimés) sous l'attribut, du plus répandu au plus rare.
     *
     * @return array<int, array{name: string, price: float}>
     */
    private static function variationChoices(int $attributeId): array
    {
        $rows = ItemVariation::query()
            ->where('item_attribute_id', $attributeId)
            ->where('status', Status::ACTIVE)
            ->whereIn('item_id', Item::query()->whereNull('deleted_at')->select('id'))
            ->get(['name', 'price', 'item_id']);

        return self::unionChoices($rows);
    }

    /**
     * @param  array<int, string>  $groupLabels
     * @return array<int, array{name: string, price: float}>
     */
    private static function extraChoices(array $groupLabels): array
    {
        $rows = ItemExtra::query()
            ->where('status', Status::ACTIVE)
            ->whereIn('item_id', Item::query()->whereNull('deleted_at')->select('id'))
            ->get(['name', 'price', 'item_id', 'group_label'])
            ->filter(function (ItemExtra $extra) use ($groupLabels): bool {
                $label = mb_strtolower(trim((string) ($extra->group_label ?? '')));

                return in_array($label, array_map('mb_strtolower', $groupLabels), true);
            });

        return self::unionChoices($rows);
    }

    /**
     * @return array<int, array{name: string, price: float, addon_item_id: int}>
     */
    private static function addonChoices(string $role): array
    {
        $rows = ItemAddon::query()
            ->where('role', $role)
            ->whereIn('item_id', Item::query()->whereNull('deleted_at')->select('id'))
            ->with('addonItem:id,name,status,deleted_at')
            ->get()
            ->filter(fn (ItemAddon $addon): bool => $addon->addonItem !== null
                && $addon->addonItem->deleted_at === null
                && (int) $addon->addonItem->status === Status::ACTIVE);

        $byAddon = [];
        foreach ($rows as $addon) {
            $id = (int) $addon->addon_item_id;
            $byAddon[$id] ??= ['name' => (string) $addon->addonItem->name, 'price' => 0.0, 'addon_item_id' => $id, 'count' => 0];
            $byAddon[$id]['count']++;
        }
        uasort($byAddon, fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return array_values(array_map(fn (array $c): array => ['name' => $c['name'], 'price' => 0.0, 'addon_item_id' => $c['addon_item_id']], $byAddon));
    }

    /**
     * @return array<int, array{name: string, price: float}>
     */
    private static function unionChoices($rows): array
    {
        $byName = [];
        foreach ($rows as $row) {
            $key = WizardPageChoice::normalizeName($row->name);
            if ($key === '') {
                continue;
            }
            $byName[$key] ??= ['name' => (string) $row->name, 'prices' => [], 'items' => []];
            $byName[$key]['prices'][] = round((float) $row->price, 6);
            $byName[$key]['items'][(int) $row->item_id] = true;
        }

        $out = [];
        foreach ($byName as $entry) {
            $counts = array_count_values(array_map('strval', $entry['prices']));
            arsort($counts);
            $out[] = ['name' => $entry['name'], 'price' => (float) array_key_first($counts), 'spread' => count($entry['items'])];
        }
        usort($out, fn (array $a, array $b): int => [$b['spread'], $a['name']] <=> [$a['spread'], $b['name']]);

        return array_map(fn (array $c): array => ['name' => $c['name'], 'price' => $c['price']], $out);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private static function upsertPage(array $definition, bool $dry, array &$report): ?WizardPage
    {
        $page = WizardPage::query()->library()->where('key', $definition['key'])->first();

        if (! $page) {
            $attribute = $definition['attribute'] ?? null;
            if ($definition['source_type'] === 'item_attribute' && ! $attribute && ! $dry) {
                $attribute = ItemAttribute::create([
                    'name' => $definition['attribute_name'],
                    'status' => Status::ACTIVE,
                    'min_select' => (int) $definition['min'],
                    'max_select' => max((int) $definition['max'], (int) $definition['min']),
                    'allow_repeat' => false,
                ]);
                $report['lines'][] = sprintf('  + attribut « %s » créé', $definition['attribute_name']);
            }

            $report['pages_created']++;
            $report['lines'][] = sprintf('  + page « %s » (%s, %d choix)', $definition['label'], $definition['key'], count($definition['choices']));
            if ($dry) {
                return null;
            }

            $page = WizardPage::create([
                'key' => $definition['key'],
                'label' => $definition['label'],
                'kind' => $definition['kind'],
                'source_type' => $definition['source_type'],
                'item_attribute_id' => $attribute?->id,
                'extra_group_label' => $definition['group'] ?? null,
                'addon_role' => $definition['role'] ?? null,
                'min_select' => (int) $definition['min'],
                'max_select' => max((int) $definition['max'], (int) $definition['min']),
                'allow_repeat' => false,
                'visible_on' => ['pos', 'kiosk'],
                'is_active' => true,
                'sort' => $report['pages_created'],
            ]);
        }

        $existing = $page->choices()->get()->map(fn (WizardPageChoice $c): string => WizardPageChoice::normalizeName($c->name))->flip();
        $sort = (int) $page->choices()->max('sort');
        foreach ($definition['choices'] as $choice) {
            $key = WizardPageChoice::normalizeName($choice['name']);
            if ($key === '' || $existing->has($key)) {
                continue;
            }
            $report['choices_added']++;
            $report['lines'][] = sprintf('    · %s ← « %s » (%s €)', $definition['key'], $choice['name'], number_format((float) $choice['price'], 2, ',', ' '));
            if ($dry) {
                continue;
            }
            $sort++;
            $page->choices()->create([
                'name' => $choice['name'],
                'price' => (float) $choice['price'],
                'addon_item_id' => $choice['addon_item_id'] ?? null,
                'sort' => $sort,
                'status' => Status::ACTIVE,
            ]);
        }

        return $page;
    }

    /**
     * Relie les étapes existantes (toutes les versions, brouillons et clones) à leur page.
     *
     * @param  array<string, WizardPage>  $pages
     */
    private static function linkSteps(array $pages, bool $dry, array &$report): void
    {
        if ($pages === []) {
            return;
        }

        $byAttribute = [];
        $byGroup = [];
        $byRole = [];
        foreach ($pages as $key => $page) {
            if ($page->source_type === 'item_attribute' && $page->item_attribute_id) {
                $byAttribute[(int) $page->item_attribute_id] = $page;
            } elseif ($page->source_type === 'extra_group') {
                $byGroup[mb_strtolower((string) $page->extra_group_label)] = $page;
            } elseif ($page->source_type === 'addon' && $page->addon_role) {
                $byRole[(string) $page->addon_role] = $page;
            }
        }

        $attributes = ItemAttribute::query()->get(['id', 'name'])->keyBy('id');

        $steps = ItemWizardStep::query()->whereNull('wizard_page_id')->orderBy('id')->get();
        foreach ($steps as $step) {
            $page = self::matchStep($step, $pages, $byAttribute, $byGroup, $byRole, $attributes);
            if (! $page) {
                continue;
            }
            $report['steps_linked']++;
            if (! $dry) {
                $step->forceFill(['wizard_page_id' => $page->id])->save();
            }
        }
        if ($report['steps_linked'] > 0) {
            $report['lines'][] = sprintf('  ≡ %d étape(s) reliée(s) à leur page', $report['steps_linked']);
        }
    }

    private static function matchStep(ItemWizardStep $step, array $pages, array $byAttribute, array $byGroup, array $byRole, $attributes): ?WizardPage
    {
        $ref = mb_strtolower(trim((string) $step->source_ref));
        $key = mb_strtolower(trim((string) $step->step_key));

        if ($step->source_type === 'item_attribute') {
            $attributeId = (int) ($step->source_item_attribute_id ?? 0);
            if ($attributeId <= 0 && $ref !== '' && ctype_digit($ref)) {
                $attributeId = (int) $ref;
            }
            if ($attributeId <= 0 && $ref !== '') {
                $match = $attributes->first(fn ($a): bool => mb_strtolower(trim((string) $a->name)) === $ref);
                $attributeId = $match ? (int) $match->id : 0;
            }
            if ($attributeId > 0 && isset($byAttribute[$attributeId])) {
                return $byAttribute[$attributeId];
            }
            // Étape sans source (« pain », « viande » posées par un template) : la page du même nom.
            foreach (['pain', 'viande', 'viande_2', 'viande_3', 'sauce'] as $candidate) {
                if ($key === $candidate && isset($pages[$candidate])) {
                    return $pages[$candidate];
                }
            }

            return null;
        }

        if ($step->source_type === 'extra_group') {
            $label = $ref !== '' ? $ref : $key;
            $pageKey = self::EXTRA_GROUP_PAGES[$label] ?? null;
            if ($pageKey && isset($pages[$pageKey])) {
                return $pages[$pageKey];
            }
            if (isset($byGroup[$label])) {
                return $byGroup[$label];
            }

            return null;
        }

        if ($step->source_type === 'addon') {
            $role = (string) ($step->addon_role ?: $ref);
            if ($role === '' && in_array($key, ['boisson', 'drink'], true)) {
                $role = 'drink';
            }
            if ($role === '' && in_array($key, ['menu', 'formule'], true)) {
                $role = 'menu_component';
            }

            return $byRole[$role] ?? null;
        }

        return null;
    }
}
