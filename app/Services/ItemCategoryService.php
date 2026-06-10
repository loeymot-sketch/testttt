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
        // [CAT-DEL-01 FIX] Revenue-loss guard. Deleting a populated category
        // silently soft-deleted it, which drops EVERY active item of that
        // category from the sellable kiosk/POS/web menu projection
        // (MenuProjectionService::forChannel only joins items of ACTIVE
        // categories). Refuse the delete with a 409 while active items remain
        // so the catalogue stays sellable; the admin must reassign or retire
        // the items first. The `items()` relation is already ACTIVE-filtered.
        // This check is intentionally placed BEFORE the try/catch below: that
        // catch re-wraps any exception into a 422, which would mask the 409.
        $activeItemCount = (int) $itemCategory->items()->count();
        if ($activeItemCount > 0) {
            throw new Exception(
                sprintf(
                    'La catégorie contient %d article(s) actif(s). Réaffectez ou désactivez ces articles avant de supprimer la catégorie.',
                    $activeItemCount
                ),
                409
            );
        }

        // [GOAL CMS C1.2 2026-06-10] Orphan guards. The delete below is a
        // SOFT-delete (UPDATE deleted_at) so SQL cascade/null-on-delete never
        // fires: deleting a parent would leave sub-categories pointing at an
        // invisible parent, and deleting a category with a published wizard
        // would leave the kiosk wizard profile orphaned. Refuse with 409.
        $childCount = (int) $itemCategory->children()->count();
        if ($childCount > 0) {
            throw new Exception(
                sprintf(
                    'La catégorie contient %d sous-catégorie(s). Supprimez ou détachez ces sous-catégories avant de supprimer la catégorie.',
                    $childCount
                ),
                409
            );
        }

        $wizardProfileCount = (int) \App\Models\ItemWizardProfile::query()
            ->where('item_category_id', $itemCategory->id)
            ->count();
        if ($wizardProfileCount > 0) {
            // [heal P2-4] Wording: unpublishing does NOT detach the profile —
            // only DELETING the wizard (builder) detaches it and unblocks
            // category deletion.
            throw new Exception(
                'Un wizard est rattaché à cette catégorie. Supprimez ce wizard dans le builder (la suppression le détache) avant de supprimer la catégorie.',
                409
            );
        }

        try {
            $categoryId = (int) $itemCategory->id;
            DB::transaction(function () use ($itemCategory, $categoryId): void {
                // No active items, children or wizard profile remain (guarded
                // above): a plain soft-delete is safe. The historical
                // FOREIGN_KEY_CHECKS=0 / PRAGMA foreign_keys=0 toggle around
                // this soft-delete was a no-op footgun (it only matters for
                // hard DELETEs, where it would silently bypass FK integrity)
                // — removed per GOAL CMS C1.2 (RED P1-3).
                $itemCategory->delete();

                DB::afterCommit(function () use ($categoryId): void {
                    event(new CategoryDeleted($categoryId));
                });
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            // Preserve a genuine 409 (defense-in-depth if a future caller moves
            // the guard inside the try); otherwise normalise to a 422.
            if ((int) $exception->getCode() === 409) {
                throw $exception;
            }
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
