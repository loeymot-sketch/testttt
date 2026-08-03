<?php

namespace App\Services;


use Exception;
use Illuminate\Support\Str;
use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Models\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ItemCategoryRequest;

class ItemCategoryService
{
    public function __construct(
        private readonly ItemCategoryHierarchyService $hierarchyService
    ) {
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

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_type') ?? 'desc';

            $query = ItemCategory::with('media')->where(function ($query) use ($requests) {
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
            });

            // [AUDIT 2026-04-17 R1] Channels SSOT parity (POS/Kiosk/Web).
            // See App\Services\ItemService::applyChannelsFilter() for contract.
            $this->applyChannelsFilter($query, $request->get('surface'));

            return $query->orderBy($orderColumn, $orderType)->$method($methodValue);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [AUDIT 2026-04-17 R1] Restrict an `item_categories` query to a client-declared surface.
     *
     * Same contract as {@see App\Services\ItemService::applyChannelsFilter()}:
     *   - Valid surfaces: 'pos', 'kiosk', 'web' (else no-op).
     *   - NULL `channels` = visible everywhere (V1 back-compat).
     */
    private function applyChannelsFilter($query, ?string $surface): void
    {
        if ($surface === null) {
            return;
        }
        $surface = strtolower(trim($surface));
        if (!in_array($surface, ['pos', 'kiosk', 'web'], true)) {
            return;
        }
        $query->where(function ($q) use ($surface) {
            $q->whereNull('channels')
                ->orWhereJsonContains('channels', $surface);
        });
    }

    /**
     * @throws Exception
     */
    public function store(ItemCategoryRequest $request)
    {
        try {
            $validated = $request->validated();
            $this->hierarchyService->validateParent(
                isset($validated['parent_id']) ? (int) $validated['parent_id'] : null
            );

            $itemCategory = null;
            DB::transaction(function () use (&$itemCategory, $request, $validated): void {
                $itemCategory = ItemCategory::create($validated + ['slug' => Str::slug($request->name)]);
                if ($request->image) {
                    $itemCategory->addMediaFromRequest('image')->toMediaCollection('item-category');
                }

                $categoryId = (int) $itemCategory->id;
                DB::afterCommit(function () use ($categoryId): void {
                    event(new CategoryCreated($categoryId));
                });
            });

            return $itemCategory;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(ItemCategoryRequest $request, ItemCategory $itemCategory): ItemCategory
    {
        try {
            $validated = $request->validated();
            $this->hierarchyService->validateParent(
                isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
                (int) $itemCategory->id
            );

            DB::transaction(function () use ($itemCategory, $request, $validated): void {
                $itemCategory->update($validated + ['slug' => Str::slug($request->name)]);
                if ($request->image) {
                    $itemCategory->clearMediaCollection('item-category');
                    $itemCategory->addMediaFromRequest('image')->toMediaCollection('item-category');
                }

                $categoryId = (int) $itemCategory->id;
                DB::afterCommit(function () use ($categoryId): void {
                    event(new CategoryUpdated($categoryId));
                });
            });

            return $itemCategory;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(ItemCategory $itemCategory)
    {
        try {
            $categoryId = (int) $itemCategory->id;
            DB::transaction(function () use ($itemCategory, $categoryId): void {
                // [CAISSE-LOGIC-HEAL 2026-07-11 P1-C] Bloquer la suppression d'une catégorie
                // qui contient des produits ACTIFS. L'ancienne branche `!blank($checkItem)`
                // (= a des items actifs) soft-supprimait quand même la catégorie → les items
                // gardaient `item_category_id` pointant vers une catégorie invisible →
                // `$item->itemCategory` null → disparition des grilles category-first (caisse/
                // borne). Le gérant doit d'abord déplacer/désactiver les produits.
                // [F-CATEGORY-DELETE-ORPHAN 2026-07-15 / P2] Compter TOUS les produits non-supprimés,
                // pas seulement les ACTIFS. La relation items() filtre status=ACTIVE → un produit
                // DÉSACTIVÉ (status=10) échappait au compte → la suppression passait et laissait le
                // produit orphelin (item_category_id → catégorie soft-deleted), RÉACTIVABLE ensuite
                // en produit actif avec category_name=null (invisible des grilles category-first —
                // exactement le trou que le heal 2026-07-11 voulait fermer, contournable en suivant
                // le conseil « désactivez-les » de l'ancien message). Le gérant doit DÉPLACER les
                // produits (pas juste les désactiver) avant de supprimer la catégorie.
                $remainingItems = \App\Models\Item::query()
                    ->where('item_category_id', $itemCategory->id)
                    ->whereNull('deleted_at')
                    ->count();
                if ($remainingItems > 0) {
                    throw new Exception(
                        "Impossible de supprimer cette catégorie : elle contient encore {$remainingItems} produit(s). Déplacez-les vers une autre catégorie d'abord.",
                        422
                    );
                }
                // [AUDIT 2026-07-13 P3] Même pattern que la garde items : bloquer aussi la
                // suppression d'une catégorie qui a des SOUS-CATÉGORIES non-supprimées. Sans
                // ça, les enfants gardent `parent_id` pointant vers une catégorie soft-deleted
                // → sous-catégorie orpheline (parent invisible). Le gérant doit d'abord
                // supprimer/déplacer les sous-catégories.
                $activeChildren = $itemCategory->children()->whereNull('deleted_at')->count();
                if ($activeChildren > 0) {
                    throw new Exception(
                        "Impossible de supprimer cette catégorie : elle contient {$activeChildren} sous-catégorie(s). Supprimez-les ou déplacez-les d'abord.",
                        422
                    );
                }
                if (DB::getDriverName() === 'sqlite') {
                    DB::statement('PRAGMA foreign_keys=0');
                    $itemCategory->delete();
                    DB::statement('PRAGMA foreign_keys=1');
                } else {
                    DB::statement('SET FOREIGN_KEY_CHECKS=0');
                    $itemCategory->delete();
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                }

                DB::afterCommit(function () use ($categoryId): void {
                    event(new CategoryDeleted($categoryId));
                });
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(ItemCategory $itemCategory)
    {
        try {
            return $itemCategory->load('items');
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function sortCategory(Request $request)
    {
        try {
            $categoryIds = array_values(array_filter(
                array_map('intval', (array) $request->category_id),
                static fn (int $id): bool => $id > 0
            ));

            DB::transaction(function () use ($categoryIds) {
                foreach ($categoryIds as $index => $id) {
                    ItemCategory::where('id', $id)->update(['sort' => $index + 1]);
                }

                DB::afterCommit(function () use ($categoryIds): void {
                    foreach ($categoryIds as $id) {
                        event(new CategoryUpdated($id));
                    }
                });
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
