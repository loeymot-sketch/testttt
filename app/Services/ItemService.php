<?php

namespace App\Services;


use Exception;
use App\Enums\Ask;
use App\Models\Item;
use App\Enums\Status;
use Illuminate\Support\Str;
use App\Events\ItemCreated;
use App\Events\ItemDeleted;
use App\Models\ItemBranchAvailability;
use App\Models\ItemVariation;
use App\Models\ItemExtra;
use App\Http\Requests\ItemRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Http\Requests\ChangeImageRequest;
use App\Events\ItemAvailabilityChanged;

class ItemService
{
    public $item;
    protected $itemFilter = [
        'name',
        'slug',
        'item_category_id',
        'price',
        'is_featured',
        'item_type',
        'tax_id',
        'status',
        'order',
        'description',
        'except'
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

            return Item::with('media', 'category', 'tax')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->itemFilter)) {
                        if ($key == "except") {
                            $explodes = explode('|', $request);
                            if (count($explodes)) {
                                foreach ($explodes as $explode) {
                                    $query->where('id', '!=', $explode);
                                }
                            }
                        } else {
                            if ($key == "item_category_id") {
                                $query->where($key, $request);
                            } else {
                                $query->where($key, 'like', '%' . $request . '%');
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                    $methodValue
                );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function simpleList(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType = $request->get('order_type') ?? 'desc';

            $query = Item::with('media', 'category', 'offer')->where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->itemFilter)) {
                        if ($key == "except") {
                            $explodes = explode('|', $request);
                            if (count($explodes)) {
                                foreach ($explodes as $explode) {
                                    $query->where('id', '!=', $explode);
                                }
                            }
                        } else {
                            if ($key == "item_category_id") {
                                $query->where($key, $request);
                            } else {
                                $query->where($key, 'like', '%' . $request . '%');
                            }
                        }
                    }
                }
            });

            // [AUDIT 2026-04-17 R1] Channels SSOT parity (POS/Kiosk/Web).
            // Gate visibility only when the caller declares a surface; legacy
            // callers with no `?surface=` keep the previous "catalog-wide"
            // behaviour. NULL `channels` = visible on every surface (V1 default).
            $this->applyChannelsFilter($query, $request->get('surface'));

            $result = $query->orderBy($orderColumn, $orderType)->$method($methodValue);
            $this->applyBranchAvailabilityOverlay($result, $request->get('branch_id'));

            return $result;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * [AUDIT 2026-04-17 R1] Restrict an `items` query to a client-declared surface.
     *
     * Contract:
     *   - Valid surfaces: 'pos', 'kiosk', 'web'. Anything else is ignored (no-op)
     *     so forged query strings cannot widen or break the query.
     *   - Back-compat: items with `channels IS NULL` stay visible on every surface.
     *   - Portable: uses `whereJsonContains` (MySQL) + `whereNull` fallback; Laravel
     *     emits SQLite-compatible JSON predicates for the test suite.
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
            $q->whereNull('channels');
            if (DB::connection()->getDriverName() === 'sqlite') {
                $q->orWhere('channels', 'like', '%"' . $surface . '"%');
                return;
            }
            $q->orWhereJsonContains('channels', $surface);
        });
    }

    private function applyBranchAvailabilityOverlay($result, mixed $branchId): void
    {
        $branchId = (int) $branchId;
        if ($branchId < 1) {
            return;
        }

        $items = method_exists($result, 'getCollection') ? $result->getCollection() : $result;
        if (! $items || ! method_exists($items, 'pluck')) {
            return;
        }

        $ids = $items->pluck('id')->filter()->unique()->values()->all();
        if ($ids === []) {
            return;
        }

        $availability = ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $ids)
            ->get()
            ->keyBy('item_id');

        $items->each(function (Item $item) use ($availability): void {
            $row = $availability->get($item->id);
            $branchAvailable = $row ? (bool) $row->is_available : true;
            $globalAvailable = $item->is_available === null ? true : (bool) $item->is_available;

            $item->setAttribute('branch_is_available', $branchAvailable);
            $item->setAttribute('availability_reason', $row && ! $branchAvailable ? $row->unavailable_reason : null);
            $item->setAttribute('effective_is_available', $branchAvailable && $globalAvailable);
        });
    }

    /**
     * @throws Exception
     */
    public function store(ItemRequest $request)
    {
        try {
            DB::transaction(function () use ($request) {
                $this->item = Item::create($request->validated() + ['slug' => Str::slug($request->name)]);
                if ($request->image) {
                    $this->item->addMedia($request->image)->toMediaCollection('item');
                }
                if ($request->variations) {
                    $variations = $this->safeJsonDecode($request->variations, true);
                    if ($variations !== null) {
                        $this->item->variations()->createMany($variations);
                    }
                }

                // [SPRINT 7] Gestion des extras
                if ($request->extras) {
                    $extras = $this->safeJsonDecode($request->extras, true);
                    if ($extras !== null) {
                        foreach ($extras as $extra) {
                            \App\Models\ItemExtra::create([
                                'item_id' => $this->item->id,
                                'name'    => $extra['name'],
                                'price'   => $extra['price'] ?? 0,
                                'status'  => $extra['status'] ?? \App\Enums\Status::ACTIVE,
                            ]);
                        }
                    }
                }

                $createdItemId = (int) $this->item->id;
                DB::afterCommit(function () use ($createdItemId): void {
                    event(new ItemCreated($createdItemId));
                });
            });
            $this->warnCatalogChannelsNullIfNeeded($this->item, 'store');

            return $this->item;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(ItemRequest $request, Item $item): Item
    {
        try {
            DB::transaction(function () use ($request, $item) {
                $item->update($request->validated() + ['slug' => Str::slug($request->name)]);
                if ($request->image) {
                    $item->addMedia($request->image)->toMediaCollection('item');
                }
                if ($request->variations) {
                    $variationIdsArray = [];
                    $variationDeleteArray = [];
                    $oldVariations = $item->variations->pluck('id')->toArray();
                    $decodedVariations = $this->safeJsonDecode($request->variations, true);
                    if ($decodedVariations === null) {
                        throw new Exception('Invalid variations JSON format', 422);
                    }
                    foreach ($decodedVariations as $variation) {
                        if (isset($variation['id'])) {
                            $variationId = (int) $variation['id'];
                            $variationIdsArray[] = $variationId;
                            $updated = $item->variations()->whereKey($variationId)->update([
                                'name' => $variation['name'],
                                'price' => $variation['price'],
                            ]);
                            if ($updated === 0) {
                                throw new Exception(trans('all.item_match'), 422);
                            }
                        } else {
                            $item->variations()->create($variation);
                        }
                    }

                    if ($variationIdsArray) {
                        foreach ($oldVariations as $oldVariation) {
                            if (!in_array($oldVariation, $variationIdsArray)) {
                                $variationDeleteArray[] = $oldVariation;
                            }
                        }
                    }

                    if ($variationDeleteArray) {
                        ItemVariation::whereIn('id', $variationDeleteArray)->delete();
                    }
                }

                // [SPRINT 7] Gestion des extras — diff sync
                if ($request->extras !== null) {
                    $decodedExtras = $this->safeJsonDecode($request->extras, true);
                    if ($decodedExtras !== null) {
                        $extraIdsToKeep = [];
                        foreach ($decodedExtras as $extra) {
                            if (isset($extra['id'])) {
                                $extraId = (int) $extra['id'];
                                $updated = $item->extras()->whereKey($extraId)->update([
                                    'name'   => $extra['name'],
                                    'price'  => $extra['price'] ?? 0,
                                    'status' => $extra['status'] ?? \App\Enums\Status::ACTIVE,
                                ]);
                                if ($updated === 0) {
                                    throw new Exception(trans('all.item_match'), 422);
                                }
                                $extraIdsToKeep[] = $extraId;
                            } else {
                                // Créer un nouvel extra
                                $newExtra = ItemExtra::create([
                                    'item_id' => $item->id,
                                    'name'    => $extra['name'],
                                    'price'   => $extra['price'] ?? 0,
                                    'status'  => $extra['status'] ?? \App\Enums\Status::ACTIVE,
                                ]);
                                $extraIdsToKeep[] = $newExtra->id;
                            }
                        }
                        // Supprimer les extras qui ne sont plus dans la liste
                        ItemExtra::where('item_id', $item->id)
                            ->whereNotIn('id', $extraIdsToKeep)
                            ->delete();
                    }
                }
            });
            $refreshed = $item->refresh();
            $this->warnCatalogChannelsNullIfNeeded($refreshed, 'update');

            // [C3] Broadcast item change to all kiosk displays so they can update
            // their menu cache without waiting for the 5-minute TTL.
            // Determine broadcast type: price change triggers full refetch (variations may differ).
            $type = 'status';
            if ($request->has('price') || $request->has('variations') || $request->has('extras')) {
                $type = 'full';
            }
            try {
                ItemAvailabilityChanged::dispatch(
                    (int) $refreshed->id,
                    (int) $refreshed->status,
                    (float) $refreshed->price,
                    $type
                );
            } catch (\Throwable $e) {
                // Non-blocking: broadcast failure must not break the admin save
                Log::warning('[C3] ItemAvailabilityChanged broadcast failed: ' . $e->getMessage());
            }

            return $refreshed;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Item $item)
    {
        try {
            $itemId = (int) $item->id;
            DB::transaction(function () use ($item, $itemId) {
                $item->variations()->delete();
                $item->extras()->delete();
                $item->addons()->delete();
                $item->delete();
                DB::afterCommit(function () use ($itemId): void {
                    event(new ItemDeleted($itemId));
                });
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            DB::rollBack();
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Item $item): Item
    {
        try {
            return $item->load('media', 'category', 'tax', 'offer', 'addons', 'variations', 'extras');
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function changeImage(ChangeImageRequest $request, Item $item): Item
    {
        try {
            if ($request->image) {
                $item->clearMediaCollection('item');
                $item->addMedia($request->image)->toMediaCollection('item');
            }
            $refreshed = $item->refresh();

            try {
                ItemAvailabilityChanged::dispatch(
                    (int) $refreshed->id,
                    (int) $refreshed->status,
                    (float) $refreshed->price,
                    'full'
                );
            } catch (\Throwable $e) {
                Log::warning('[C3] ItemAvailabilityChanged broadcast failed after image change: ' . $e->getMessage());
            }

            return $refreshed;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function featuredItems()
    {
        try {
            return Item::with('media', 'category', 'offer')->where(['is_featured' => Ask::YES, 'status' => Status::ACTIVE])->inRandomOrder()->limit(8)->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function mostPopularItems()
    {
        try {
            return Item::with('media', 'category', 'offer')->withCount('orders')->where(['status' => Status::ACTIVE])->orderBy('orders_count', 'desc')->limit(6)->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function itemReport(PaginateRequest $request)
    {
        try {
            $requests = $request->all();
            $method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            return Item::with('category')->withCount('orders')->where(function ($query) use ($requests) {
                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $first_date = date('Y-m-d', strtotime($requests['from_date']));
                    $last_date = date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('created_at', '>=', $first_date)->whereDate(
                        'created_at',
                        '<=',
                        $last_date
                    );
                }
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->itemFilter)) {
                        if ($key == "except") {
                            $explodes = explode('|', $request);
                            if (count($explodes)) {
                                foreach ($explodes as $explode) {
                                    $query->where('id', '!=', $explode);
                                }
                            }
                        } else {
                            $query->where($key, 'like', '%' . $request . '%');
                        }
                    }
                }
            })->orderBy('orders_count', 'desc')->$method(
                    $methodValue
                );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function itemDetails(Item $item)
    {
        return $item->load('media', 'category', 'tax', 'offer', 'addons', 'variations', 'extras');
    }

    /**
     * Safely decode JSON with error checking
     */
    private function safeJsonDecode(?string $json, bool $assoc = false): mixed
    {
        if (empty($json)) {
            return $assoc ? [] : null;
        }
        $decoded = json_decode($json, $assoc);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : ($assoc ? [] : null);
    }

    /**
     * Log [catalog.channels-null] when an item is saved with channels=NULL (legacy all-surfaces default).
     */
    private function warnCatalogChannelsNullIfNeeded(Item $item, string $action): void
    {
        if (! config('catalog_v15.channels_filter.warn_on_null', true)) {
            return;
        }
        if ($item->channels !== null) {
            return;
        }
        Log::warning('[catalog.channels-null]', [
            'item_id'   => (int) $item->id,
            'user_id'   => auth()->id(),
            'tenant_id' => auth()->user()?->getAttribute('tenant_id'),
            'action'    => $action,
        ]);
    }
}
