<?php

namespace App\Services\Kiosk;

use App\Enums\Status;
use App\Libraries\AppLibrary;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\KioskPromo;
use App\Models\UpsellRule;
use App\Services\Composer\ComposerProfileProjection;
use App\Services\Menu\MenuSnapshot;
use App\Services\Stock\ChoiceAvailabilityResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Kiosk Design V1 — Phase 1.4
 *
 * Construit le payload menu unifié consommé par `GET /api/frontend/menu`.
 *
 * Différence avec `MenuProjectionService` :
 *  - Renvoie variations + extras + allergens (pivot) par item.
 *  - Inclut la hiérarchie catégories via `parent_id` (profondeur max 2).
 *  - Inclut les règles d'upsell de la branche (brute — pas de matching ici).
 *  - Inclut `is_chef_pick` (flag admin statique, invariant §1.5).
 *  - Inclut les locales actives de la branche.
 *
 * Invariants :
 *  - Pas de calcul de prix — seuls les prix DB sont exposés pour affichage.
 *  - `branch_id` lu depuis l'appelant (controller résout via KioskMachine).
 *  - Filtrage `channels` = ['kiosk'] ou null (back-compat).
 */
final class KioskMenuService
{
    public const CHANNEL = 'kiosk';

    public function __construct(
        private ?MenuSnapshot $snapshot = null,
        private ?ComposerProfileProjection $composerProjection = null,
        private ?ChoiceAvailabilityResolver $choiceAvailabilityResolver = null,
    )
    {
    }

    /**
     * @return array{
     *   branch: array<string, mixed>,
     *   categories: array<int, array<string, mixed>>,
     *   items: array<int, array<string, mixed>>,
     *   upsell_rules: array<int, array<string, mixed>>,
     *   snapshot_version: int,
     *   branch_id: int,
     *   channel: string
     * }
     */
    public function build(Branch $branch): array
    {
        $branchId = (int) $branch->id;

        // -- Catégories actives visibles sur le canal kiosk ----------------
        $categories = ItemCategory::query()
            ->where('status', Status::ACTIVE)
            ->get();

        $visibleCategories = $categories
            ->filter(fn (ItemCategory $cat): bool => $cat->isVisibleOn(self::CHANNEL))
            ->values();

        $categoryIds = $visibleCategories->pluck('id')->all();

        // -- Items associés, eager load variations/extras/allergens --------
        $items = $categoryIds
            ? Item::query()
                ->with([
                    'variations:id,item_id,item_attribute_id,name,price,visible_on,status',
                    'extras:id,item_id,name,price,visible_on,group_label,status',
                    'addons:id,item_id,addon_item_id,addon_item_variation,role',
                    'addons.addonItem:id,name,status,is_available,channels',
                    'allergens:id,code,name_key,icon,sort',
                    // [DBPERF-P1-01 heal 2026-05-29] Eager-load Item media
                    // (Spatie polymorphic). Wave D measured 89 queries on
                    // /api/frontend/menu cold path due to lazy
                    // getFirstMediaUrl() on Item::thumb accessor. Only Item
                    // implements HasMedia (verified app/Models/Item.php:15).
                    // Variations/Extras do NOT have media — those N+1 hits
                    // come from addonItem.thumb chain which loads Item media.
                    'media',
                    'addons.addonItem.media',
                ])
                ->where('status', Status::ACTIVE)
                ->whereIn('item_category_id', $categoryIds)
                ->get()
            : collect();

        $availability = $items->isNotEmpty()
            ? ItemBranchAvailability::query()
                ->whereIn('item_id', $items->pluck('id'))
                ->where('branch_id', $branchId)
                ->get()
                ->keyBy('item_id')
            : collect();

        $visibleItems = $items
            ->filter(fn (Item $it): bool => $it->isVisibleOn(self::CHANNEL))
            ->values();

        // -- Upsell rules actives pour la branche --------------------------
        $upsellRules = UpsellRule::query()
            ->activeForBranch($branchId)
            ->get();

        // -- Promos kiosk actives pour la branche (Phase 8.2) --------------
        $promos = $this->loadActivePromos($branchId);

        return [
            'branch' => $this->projectBranch($branch),
            'categories' => $this->projectCategories($visibleCategories),
            'items' => $this->projectItems($visibleItems, $availability, $branchId),
            'upsell_rules' => $this->projectUpsellRules($upsellRules),
            'promos' => $this->projectPromos($promos),
            'snapshot_version' => $this->snapshot()->current($branchId),
            'branch_id' => $branchId,
            'channel' => self::CHANNEL,
        ];
    }

    private function snapshot(): MenuSnapshot
    {
        return $this->snapshot ??= MenuSnapshot::make();
    }

    /**
     * Phase 8.2 — Récupère les promos kiosk actives pour la branche au moment
     * de la requête (filtrage serveur ; le front ne filtre jamais par branche).
     *
     * @return Collection<int, KioskPromo>
     */
    private function loadActivePromos(int $branchId): Collection
    {
        try {
            $now = Carbon::now();
            return KioskPromo::query()
                ->where('branch_id', $branchId)
                ->where('active', true)
                ->where(function ($q) use ($now) {
                    $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
                })
                ->where(function ($q) use ($now) {
                    $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
                })
                ->get();
        } catch (Throwable $e) {
            // Si la table n'existe pas encore (legacy dev env), ne pas casser le menu.
            return collect();
        }
    }

    private function projectBranch(Branch $branch): array
    {
        return [
            'id'                => (int) $branch->id,
            'name'              => (string) $branch->name,
            'available_locales' => $branch->activeLocales(),
            'currency'          => (string) config('app.currency_symbol', '€'),
            // Phase 8.2 — Flags contextuels live (DATA_CONTRACT §3.1).
            // Server-side only ; jamais dérivé côté front.
            'is_rush'           => $this->computeIsRush($branch),
            'is_night'          => $this->computeIsNight($branch),
            'server_time'       => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * Heuristique `is_rush` V1 : si la configuration `kiosk.rush_windows`
     * (array de couples `HH:MM-HH:MM`) contient l'heure courante, on considère
     * que la branche est en coup de feu. Toute logique plus fine (charge KDS,
     * file d'attente) sera branchée en V2 sans changer le contrat côté front.
     */
    private function computeIsRush(Branch $branch): bool
    {
        $windows = (array) config('kiosk.rush_windows', []);
        if (empty($windows)) {
            return false;
        }

        try {
            $tz = (string) ($branch->timezone ?? config('app.timezone', 'Europe/Paris'));
            $now = Carbon::now($tz);
            $minutes = $now->hour * 60 + $now->minute;

            foreach ($windows as $w) {
                if (!is_string($w) || !str_contains($w, '-')) {
                    continue;
                }
                [$start, $end] = array_map('trim', explode('-', $w, 2));
                $s = $this->timeStringToMinutes($start);
                $e = $this->timeStringToMinutes($end);
                if ($s === null || $e === null) continue;

                // Fenêtre qui enjambe minuit (ex: "22:00-02:00").
                if ($s <= $e) {
                    if ($minutes >= $s && $minutes <= $e) return true;
                } else {
                    if ($minutes >= $s || $minutes <= $e) return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Après 22h locale → mode nuit (jusqu'à 05h). Server-driven pour éviter
     * que le front utilise l'horloge client (invariant SSOT).
     */
    private function computeIsNight(Branch $branch): bool
    {
        try {
            $tz = (string) ($branch->timezone ?? config('app.timezone', 'Europe/Paris'));
            $h = Carbon::now($tz)->hour;
            return $h >= 22 || $h < 5;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function timeStringToMinutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) return null;
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;
        return $h * 60 + $min;
    }

    /**
     * Projection plate avec `parent_id` explicite ET arbre `children` pour
     * permettre les deux stratégies côté UI (liste plate ou accordéon).
     *
     * @param  Collection<int, ItemCategory>  $categories
     */
    private function projectCategories(Collection $categories): array
    {
        // Sort par ordre kiosk (ou sort legacy), puis par id pour stabilité.
        $sorted = $categories->sortBy([
            fn (ItemCategory $c) => $c->sortFor(self::CHANNEL),
            fn (ItemCategory $c) => (int) $c->id,
        ])->values();

        $byId = $sorted->keyBy('id');

        return $sorted->map(function (ItemCategory $cat) use ($byId): array {
            $childIds = $byId
                ->filter(fn (ItemCategory $c): bool => (int) $c->parent_id === (int) $cat->id)
                ->pluck('id')
                ->map(fn ($v): int => (int) $v)
                ->all();

            return [
                'id'                  => (int) $cat->id,
                'parent_id'           => $cat->parent_id !== null ? (int) $cat->parent_id : null,
                'slug'                => (string) $cat->slug,
                'name'                => $cat->displayNameFor(self::CHANNEL),
                'thumb'               => $cat->thumb,
                'cover'               => $cat->cover,
                'image'               => $cat->thumb ?: $cat->cover,
                'image_full_path'     => $cat->cover ?: $cat->thumb,
                'kiosk_label'         => $cat->kiosk_label,
                'sort'                => $cat->sortFor(self::CHANNEL),
                'wizard_template'     => $cat->wizard_template,
                'has_menu'            => (bool) $cat->has_menu,
                'default_menu_kiosk'  => (bool) $cat->default_menu_kiosk,
                'sauce_included_menu' => (bool) $cat->sauce_included_menu,
                'child_ids'           => $childIds,
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, ItemBranchAvailability>  $availability
     */
    private function projectItems(Collection $items, Collection $availability, int $branchId): array
    {
        $composerProfiles = $this->publishedComposerProfiles($items, $branchId);
        $choiceAvailability = $this->choiceAvailabilityResolver()->snapshotForItems($items, $branchId, self::CHANNEL);

        // Wave Y A-001 root-cause fix — Laravel `sortBy([fn1, fn2])` interprets
        // fn2 as a direction string for fn1, NOT as a tie-breaker. The old form
        // produced non-deterministic ordering across all kiosk categories.
        // Chained sortBy is stable in PHP (since 8.0 spl) so the second sort
        // preserves the first sort's order on ties.
        return $items->sortBy(fn (Item $it) => (int) $it->id)
            ->sortBy(fn (Item $it) => (int) ($it->order ?? 0))
            ->values()
            ->map(function (Item $item) use ($availability, $composerProfiles, $choiceAvailability, $branchId): array {
            $avail = $availability->get($item->id);
            $isAvailable = $avail ? (bool) $avail->is_available : true;
            $itemChoiceAvailability = $choiceAvailability[(int) $item->id] ?? [
                'variations' => [],
                'extras' => [],
                'addons' => [],
            ];

            // [VIANDE-COUNT 2026-06-24] SSOT serveur du nombre de viandes à choisir
            // = nombre d'attributs « Viande N » ayant des variations actives. La
            // borne (KioskWizardComponent.detectViandeCount, frozen) lit ce champ
            // EN PRIORITÉ (avant l'heuristique nom buggée : « Méga »→4, « Terminator »
            // / « Bol »→null = pas d'étape viande). Méga/Terminator=2, Tacos M/Bols=1,
            // Tacos L=2, burgers/desserts=0 (la borne retombe alors sur le nom = 0).
            $projectedItemAttributes = $this->projectItemAttributes($item, self::CHANNEL);
            $viandeCount = collect($projectedItemAttributes)
                ->filter(function ($a): bool {
                    $n = mb_strtolower((string) ($a['name'] ?? ''));
                    return str_contains($n, 'viande') || str_contains($n, 'meat');
                })
                ->count();

            return [
                'viande_count'       => $viandeCount,
                'id'                 => (int) $item->id,
                'category_id'        => (int) $item->item_category_id,
                'item_category_id'   => (int) $item->item_category_id,
                'slug'               => (string) $item->slug,
                'name'               => (string) $item->name,
                'description'        => $item->description,
                'price'              => (float) $item->price,
                'convert_price'      => AppLibrary::convertAmountFormat($item->price),
                'currency_price'     => AppLibrary::currencyAmountFormat($item->price),
                'flat_price'         => AppLibrary::flatAmountFormat($item->price),
                'tax_id'             => $item->tax_id !== null ? (int) $item->tax_id : null,
                'item_type'          => (int) $item->item_type,
                'is_featured'        => (int) $item->is_featured,
                'is_upsell'          => (int) ($item->is_upsell ?? 0),
                // Wave Y A-001 — surface the admin-controlled display order so
                // the kiosk client can sort signatures first within a category.
                // Cf. compareKioskItemsDisplay() in helpers/kioskItemDisplayOrder.js.
                'order'              => (int) ($item->order ?? 0),
                'is_chef_pick'       => (bool) ($item->is_chef_pick ?? false),
                'chef_pick_order'    => $item->chef_pick_order !== null ? (int) $item->chef_pick_order : null,
                // Phase 8.1 — Flags diététiques (DATA_CONTRACT §3.3).
                'is_new'             => (bool) ($item->is_new ?? false),
                'is_spicy'           => (bool) ($item->is_spicy ?? false),
                'is_vegetarian'      => (bool) ($item->is_vegetarian ?? false),
                'is_pork_free'       => (bool) ($item->is_pork_free ?? false),
                'is_halal'           => (bool) ($item->is_halal ?? false),
                'is_gluten_free'     => (bool) ($item->is_gluten_free ?? false),
                'kiosk_emoji'        => $item->kiosk_emoji,
                'thumb'              => $item->thumb,
                'cover'              => $item->cover,
                'image'              => $item->thumb ?: $item->cover,
                'preview'            => $item->preview,
                'is_available'       => $isAvailable && (bool) ($item->is_available ?? true),
                'unavailable_reason' => $avail && !$isAvailable ? $avail->unavailable_reason : null,
                'channels'           => is_array($item->channels) ? array_values($item->channels) : null,
                'offer'              => [],
                'allergens'          => $item->allergens->map(fn ($a) => [
                    'id'       => (int) $a->id,
                    'code'     => (string) $a->code,
                    'name_key' => (string) $a->name_key,
                    'icon'     => $a->icon,
                    'is_trace' => (bool) ($a->pivot->is_trace ?? false),
                ])->values()->all(),
                'variations'         => $item->variations
                    ->filter(fn ($v) => (int) $v->status === \App\Enums\Status::ACTIVE && $v->isVisibleOn(self::CHANNEL))
                    ->map(function ($v) use ($itemChoiceAvailability) {
                        $availability = $itemChoiceAvailability['variations'][(int) $v->id] ?? ['is_available' => true, 'unavailable_reason' => null];

                        return [
                            'id'           => (int) $v->id,
                            'attribute_id' => $v->item_attribute_id !== null ? (int) $v->item_attribute_id : null,
                            'item_attribute_id' => $v->item_attribute_id !== null ? (int) $v->item_attribute_id : null,
                            'name'         => (string) $v->name,
                            'price'        => (float) $v->price,
                            'convert_price' => AppLibrary::convertAmountFormat($v->price),
                            'currency_price' => AppLibrary::currencyAmountFormat($v->price),
                            'thumb'        => $v->thumb,
                            'status'       => (int) $v->status,
                            'visible_on'   => $v->visible_on,
                            'is_available' => $availability['is_available'],
                            'unavailable_reason' => $availability['unavailable_reason'],
                        ];
                    })->values()->all(),
                'itemAttributes'      => $projectedItemAttributes,
                'composer_profile'    => $this->composerProfileProjection()->project($composerProfiles->get($item->id), $item, self::CHANNEL, $branchId),
                'extras'             => $item->extras
                    ->filter(fn ($e) => $e->isVisibleOn(self::CHANNEL))
                    ->map(function ($e) use ($itemChoiceAvailability) {
                        $availability = $itemChoiceAvailability['extras'][(int) $e->id] ?? ['is_available' => true, 'unavailable_reason' => null];

                        return [
                            'id'          => (int) $e->id,
                            'name'        => (string) $e->name,
                            'price'       => (float) $e->price,
                            'convert_price' => AppLibrary::convertAmountFormat($e->price),
                            'currency_price' => AppLibrary::currencyAmountFormat($e->price),
                            'thumb'       => $e->thumb,
                            'status'      => (int) $e->status,
                            'group_label' => $e->group_label,
                            'visible_on'  => $e->visible_on,
                            'is_available' => $availability['is_available'],
                            'unavailable_reason' => $availability['unavailable_reason'],
                        ];
                    })->values()->all(),
                'addons'             => $item->addons
                    ->filter(function ($addon): bool {
                        $addonItem = $addon->addonItem;

                        return $addonItem !== null
                            && (int) $addonItem->status === Status::ACTIVE
                            && (bool) ($addonItem->is_available ?? true)
                            && $addonItem->isVisibleOn(self::CHANNEL);
                    })
                    ->map(function ($addon) use ($itemChoiceAvailability): array {
                        $availability = $itemChoiceAvailability['addons'][(int) $addon->id] ?? ['is_available' => true, 'unavailable_reason' => null];

                        return [
                            'id' => (int) $addon->id,
                            'addon_item_id' => (int) $addon->addon_item_id,
                            'addon_item_variation' => $addon->addon_item_variation,
                            'role' => $addon->role,
                            'addon_item_name' => $addon->addonItem?->name,
                            'is_available' => $availability['is_available'],
                            'unavailable_reason' => $availability['unavailable_reason'],
                        ];
                    })->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return Collection<int, ItemWizardProfile>
     */
    private function publishedComposerProfiles(Collection $items, int $branchId): Collection
    {
        $itemIds = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if (empty($itemIds)) {
            return collect();
        }

        return ItemWizardProfile::query()
            ->with(['steps' => fn ($query) => $query->where('is_active', true)->orderBy('position')])
            ->whereIn('item_id', $itemIds)
            ->where('is_published', true)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id_scope')
                    ->when($branchId > 0, fn ($q) => $q->orWhere('branch_id_scope', $branchId));
            })
            ->get()
            ->groupBy('item_id')
            ->map(fn (Collection $profiles): ItemWizardProfile => $profiles
                ->sort(fn (ItemWizardProfile $a, ItemWizardProfile $b): int => $this->compareComposerProfiles($a, $b))
                ->first());
    }

    private function compareComposerProfiles(ItemWizardProfile $a, ItemWizardProfile $b): int
    {
        $aScope = $a->branch_id_scope === null ? 0 : 1;
        $bScope = $b->branch_id_scope === null ? 0 : 1;

        return [$bScope, (int) $b->version, (int) $b->id] <=> [$aScope, (int) $a->version, (int) $a->id];
    }

    private function projectItemAttributes(Item $item, string $channel): array
    {
        return $item->variations
            ->filter(fn ($variation): bool => $variation->isVisibleOn($channel) && $variation->itemAttribute !== null)
            ->map(fn ($variation) => $variation->itemAttribute)
            ->unique('id')
            ->sortBy('id')
            ->values()
            ->map(fn ($attribute): array => [
                'id'           => (int) $attribute->id,
                'name'         => (string) $attribute->name,
                'status'       => (int) $attribute->status,
                'min_select'   => (int) ($attribute->min_select ?? 0),
                'max_select'   => (int) ($attribute->max_select ?? 1),
                'allow_repeat' => (bool) ($attribute->allow_repeat ?? false),
            ])
            ->all();
    }

    private function composerProfileProjection(): ComposerProfileProjection
    {
        return $this->composerProjection ??= app(ComposerProfileProjection::class);
    }

    private function choiceAvailabilityResolver(): ChoiceAvailabilityResolver
    {
        return $this->choiceAvailabilityResolver ??= app(ChoiceAvailabilityResolver::class);
    }

    /**
     * Phase 8.2 — Projection `KioskPromo` (DATA_CONTRACT §3.1 + §6).
     *
     * @param  Collection<int, KioskPromo>  $promos
     */
    private function projectPromos(Collection $promos): array
    {
        return $promos->sortBy([
            fn (KioskPromo $p) => (int) $p->id,
        ])->map(function (KioskPromo $promo): array {
            return [
                'id'         => (int) $promo->id,
                'code'       => (string) $promo->code,
                'type'       => (string) $promo->type,
                'value'      => (float) $promo->value,
                'min_cart'   => $promo->min_cart !== null ? (float) $promo->min_cart : null,
                'valid_from' => $promo->valid_from ? Carbon::parse($promo->valid_from)->toIso8601String() : null,
                'valid_to'   => $promo->valid_to ? Carbon::parse($promo->valid_to)->toIso8601String() : null,
                'max_uses'   => $promo->max_uses !== null ? (int) $promo->max_uses : null,
                'uses_count' => (int) ($promo->uses_count ?? 0),
                // Jamais utilisé côté front pour calculer un prix (SSOT
                // /pricing/preview). Exposé uniquement pour affichage du code
                // saisissable dans le bandeau promo (UX).
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, UpsellRule>  $rules
     */
    private function projectUpsellRules(Collection $rules): array
    {
        return $rules->map(fn (UpsellRule $r) => [
            'id'                => (int) $r->id,
            'trigger_type'      => (string) $r->trigger_type,
            'trigger_value'     => is_array($r->trigger_value) ? $r->trigger_value : [],
            'suggested_item_id' => (int) $r->suggested_item_id,
            'priority'          => (int) $r->priority,
        ])->values()->all();
    }
}
