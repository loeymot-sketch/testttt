<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\ItemCategory;
use App\Services\ItemCategoryService;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Http\Requests\PaginateRequest;
use App\Services\Menu\PosMenuProjection;
use Illuminate\Support\Facades\DB;

class PosCategoryController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            $user = $request->user();
            abort_unless($user && $user->canAny(['items_show', 'pos']), 403);

            return $next($request);
        })->only('index');
    }

    protected $itemCateFilter = [
        'name',
        'slug',
        'description',
        'status'
    ];

    protected $exceptFilter = [
        'excepts'
    ];

    public function index(PaginateRequest $request): \Illuminate\Http\Response|array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            $user = $request->user();
            $posRuntimeBranchId = null;
            if ($user && $user->can('pos') && ! $user->can('items_show') && ! $user->hasRole('Branch Manager')) {
                $defaultAccess = app(\App\Services\DefaultAccessService::class)->show();
                $posRuntimeBranchId = (int) ($defaultAccess['branch_id'] ?? 0);
                if ($posRuntimeBranchId < 1) {
                    return response(['status' => false, 'message' => 'Active branch is required for POS catalog access.'], 403);
                }
            }

            $itemCategories =  ItemCategory::with('media')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->itemCateFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })
                ->where(function ($query) {
                    $query->whereNull('channels');
                    if (DB::connection()->getDriverName() === 'sqlite') {
                        $query->orWhere('channels', 'like', '%"pos"%');
                        return;
                    }
                    $query->orWhereJsonContains('channels', 'pos');
                });

            if ($posRuntimeBranchId !== null) {
                $itemCategories->whereHas('items', function ($query) use ($posRuntimeBranchId) {
                    $query->where(function ($channelQuery) {
                        $channelQuery->whereNull('channels');
                        if (DB::connection()->getDriverName() === 'sqlite') {
                            $channelQuery->orWhere('channels', 'like', '%"pos"%');
                            return;
                        }
                        $channelQuery->orWhereJsonContains('channels', 'pos');
                    })->where(function ($availabilityQuery) use ($posRuntimeBranchId) {
                        $availabilityQuery
                            ->whereNotExists(function ($subQuery) use ($posRuntimeBranchId) {
                                $subQuery->select(DB::raw(1))
                                    ->from('item_branch_availability')
                                    ->whereColumn('item_branch_availability.item_id', 'items.id')
                                    ->where('item_branch_availability.branch_id', $posRuntimeBranchId);
                            })
                            ->orWhereExists(function ($subQuery) use ($posRuntimeBranchId) {
                                $subQuery->select(DB::raw(1))
                                    ->from('item_branch_availability')
                                    ->whereColumn('item_branch_availability.item_id', 'items.id')
                                    ->where('item_branch_availability.branch_id', $posRuntimeBranchId)
                                    ->where('item_branch_availability.is_available', true);
                            });
                    });
                });
            } else {
                // [2026-05-20 P-OWNER-6 graceful-degradation heal]
                // Admin / Tenant Admin / Branch Manager paths historically returned
                // EVERY POS-channel category, including ones with zero items. After
                // a half-seeded menu reset (e.g. owner shipping V1 with only 2 of 11
                // cats actually populated), the cashier strip showed 9 empty tabs.
                //
                // Filter: hide categories with ZERO items. We intentionally do NOT
                // restrict by item-channel here — `PosCategoryBranchScopeTest`
                // locks in the contract that admin/tenant-admin retain visibility
                // on categories whose items are kiosk-only (the "configuration"
                // view). The runtime-branch path above already applies the
                // strict per-channel + per-branch availability check.
                $itemCategories->whereHas('items');
            }

            $itemCategories = $itemCategories->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );

            // [CHEF 2026-09-02] Les rayons laissés par les campagnes (AUDIT-, E2E…,
            // ZZ-TEST-, faker latin) atteignaient le CAISSIER : mesuré au navigateur,
            // la bande de rayons servait « E2E_PLAYWRIGHT_STUDIO_CATEGORY » entre
            // Burgers et Sandwichs. Le garde-fou existait mais n'était appliqué qu'au
            // catalogue admin ; ce contrôleur bâtit sa propre requête. Deux rayons
            // pollués sur trois étaient masqués PAR ACCIDENT (whereHas('items') : ils
            // n'avaient aucun article), ce qui rendait le défaut discret.
            //
            // On réutilise le garde-fou existant au lieu d'en écrire un second : deux
            // listes de motifs finissent toujours par diverger. Rien n'est supprimé en
            // base — c'est un masque de lecture. Posé après la requête, donc commun
            // aux deux voies (caissier avec branche courante / vue configuration).
            $sansPollution = static fn (ItemCategory $cat): bool
                => ! ItemCategoryService::isAuditPollutionName($cat->name);

            if ($itemCategories instanceof LengthAwarePaginator) {
                $itemCategories->setCollection($itemCategories->getCollection()->filter($sansPollution)->values());
            } else {
                $itemCategories = $itemCategories->filter($sansPollution)->values();
            }

            $itemCategoryArray = [];

            // [2026-05-18 F-4 / 2026-05-20 P-OWNER-3 slug-resolve heal]
            // Featured allowlist for the POS first-page filter.
            //
            // Source of truth = SLUGS (stable across envs/reseeds). Raw
            // integer IDs drift after menu resets and silently kill the
            // filter (every category collapses to featured=false →
            // cashier lands on supplements/menu-builders).
            //
            // Resolution:
            //   1) Read `pos.featured_category_slugs` from config.
            //   2) Resolve them → integer IDs via DB (single SELECT,
            //      O(N) on slug list, no N+1).
            //   3) If slug resolution yields nothing, fall back to the
            //      legacy `pos.featured_category_ids` knob for dev/test
            //      fixtures that may not populate slugs deterministically.
            //   4) Empty result → "no filter" (every category featured —
            //      safe default).
            $featuredSlugs = (array) config('pos.featured_category_slugs', []);
            $resolvedIds = [];
            if (! empty($featuredSlugs)) {
                $resolvedIds = ItemCategory::query()
                    ->whereIn('slug', array_map('strval', $featuredSlugs))
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }
            if (empty($resolvedIds)) {
                $resolvedIds = array_map('intval', (array) config('pos.featured_category_ids', []));
            }

            // [2026-05-20 P-OWNER-6 graceful-degradation heal]
            // Owner reported "POS tout les catégories sont vide" after the menu
            // reset shipped only 2 of 11 categories populated. The featured
            // allowlist resolved to 7 categories — 6 of which had ZERO items
            // — and the frontend `displayedItems` filter (PosComponent.vue
            // line 1607-1623) excluded the 8 supplements items because their
            // category wasn't featured. Net result: cashier saw ~3 items on
            // landing instead of all 11.
            //
            // Fallback rule: if FEWER than 2 featured categories actually have
            // items, the curated best-seller filter is meaningless — switch
            // to "no filter" so the cashier sees the entire (post-channel-
            // filtered) catalog. This is a safety net for the half-seeded
            // interim state. After menu seeding completes, the filter
            // re-engages naturally because >=2 featured cats have items.
            //
            // Threshold (advisor-validated 2026-05-20): `<2` is the smallest
            // value that meaningfully distinguishes "best-seller subset" from
            // "trivial single-cat result". One non-empty featured cat doesn't
            // justify hiding the rest of the catalog from the cashier.
            $featuredSet = null;
            $degradedFallback = false;
            if (! empty($resolvedIds)) {
                $candidateSet = array_flip($resolvedIds);
                $nonEmptyFeatured = 0;
                foreach ($itemCategories as $cat) {
                    if (isset($candidateSet[(int) $cat->id])) {
                        $nonEmptyFeatured++;
                    }
                }
                if ($nonEmptyFeatured >= 2) {
                    $featuredSet = $candidateSet;
                } else {
                    $degradedFallback = true;
                }
            }

            $addArray[] = [
                'id'          => 0,
                'name'        =>  trans('all.label.all_items'),
                'slug'        => 'all-items',
                'thumb'       => asset("images/default/all-category.png"),
                'cover'       => asset("images/default/all-category.png"),
                'featured'    => true,
            ];
            foreach ($itemCategories as $itemCategory) {
                $isFeatured = $featuredSet === null
                    ? true
                    : isset($featuredSet[(int) $itemCategory->id]);
                $itemCategoryArray[] = [
                    'id'          => $itemCategory->id,
                    'name'        => $itemCategory->name,
                    'slug'        => $itemCategory->slug,
                    'description' => $itemCategory->description === null ? '' : $itemCategory->description,
                    'status'      => $itemCategory->status,
                    'thumb'       => $itemCategory->thumb,
                    'cover'       => $itemCategory->cover,
                    'featured'    => $isFeatured,
                ];
            }

            // Sort featured-first, preserving existing order within each bucket.
            usort($itemCategoryArray, static function (array $a, array $b): int {
                $af = $a['featured'] ?? false ? 0 : 1;
                $bf = $b['featured'] ?? false ? 0 : 1;
                return $af <=> $bf;
            });

            $newObj = array_merge($addArray, $itemCategoryArray);

            // V2 convergence shim: POS runtime branch-scoped users can route
            // this payload through PosMenuProjection (legacy/shadow/unified).
            if ($posRuntimeBranchId !== null) {
                /** @var PosMenuProjection $projection */
                $projection = app(PosMenuProjection::class);
                $newObj = $projection->forBranch(
                    $posRuntimeBranchId,
                    static fn (): array => $newObj
                );
            }

            return ['data'  => $newObj];
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
