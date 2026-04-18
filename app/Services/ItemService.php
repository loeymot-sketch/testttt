<?php

namespace App\Services;


use Exception;
use App\Enums\Ask;
use App\Models\Item;
use App\Enums\Status;
use Illuminate\Support\Str;
use App\Models\ItemVariation;
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

            return $query->orderBy($orderColumn, $orderType)->$method($methodValue);
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
            $q->whereNull('channels')
                ->orWhereJsonContains('channels', $surface);
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
            });
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
                            $variationIdsArray[] = $variation['id'];
                            ItemVariation::where('id', $variation['id'])->update([
                                'name' => $variation['name'],
                                'price' => $variation['price'],
                            ]);
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
                                // Mettre à jour l'extra existant
                                \App\Models\ItemExtra::where('id', $extra['id'])->update([
                                    'name'   => $extra['name'],
                                    'price'  => $extra['price'] ?? 0,
                                    'status' => $extra['status'] ?? \App\Enums\Status::ACTIVE,
                                ]);
                                $extraIdsToKeep[] = $extra['id'];
                            } else {
                                // Créer un nouvel extra
                                $newExtra = \App\Models\ItemExtra::create([
                                    'item_id' => $item->id,
                                    'name'    => $extra['name'],
                                    'price'   => $extra['price'] ?? 0,
                                    'status'  => $extra['status'] ?? \App\Enums\Status::ACTIVE,
                                ]);
                                $extraIdsToKeep[] = $newExtra->id;
                            }
                        }
                        // Supprimer les extras qui ne sont plus dans la liste
                        \App\Models\ItemExtra::where('item_id', $item->id)
                            ->whereNotIn('id', $extraIdsToKeep)
                            ->delete();
                    }
                }
            });
            $refreshed = $item->refresh();

            // [C3] Broadcast item change to all kiosk displays so they can update
            // their menu cache without waiting for the 5-minute TTL.
            // Determine broadcast type: price change triggers full refetch (variations may differ).
            $type = 'status';
            if ($request->has('price') || $request->has('variations') || $request->has('extras')) {
                $type = 'full';
            }
            try {
                event(ItemAvailabilityChanged::fromItem($refreshed, $type));
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
            DB::transaction(function () use ($item) {
                $item->variations()->delete();
                $item->extras()->delete();
                $item->addons()->delete();
                $item->delete();
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
            return $item;
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
}
