<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\ItemCategory;
use App\Http\Requests\PaginateRequest;

class PosCategoryController extends AdminController
{
    /**
     * [SECURITY] order_column whitelist (P1-10 / Track-5 finding fix).
     *
     * Laravel's QueryGrammar backticks the identifier, so a malicious
     * `order_column=id; DROP TABLE orders;--` cannot reach the parser as
     * code — but it returns 0 rows (silent-empty / DoS / fingerprintable).
     * This whitelist closes the defense-in-depth gap. Columns must match
     * the `item_categories` schema (see migrations 2022_11_17 + 2024_02_29
     * + 2026_04_18).
     */
    private const ALLOWED_ORDER_COLUMNS = [
        'id', 'parent_id', 'name', 'slug', 'status', 'sort', 'created_at', 'updated_at',
    ];

    private const ALLOWED_ORDER_DIRECTIONS = ['asc', 'desc'];

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

            $rawColumn   = $request->get('order_column') ?? 'id';
            $orderColumn = in_array($rawColumn, self::ALLOWED_ORDER_COLUMNS, true) ? $rawColumn : 'id';

            $rawType     = strtolower((string) ($request->get('order_type') ?? 'desc'));
            $orderType   = in_array($rawType, self::ALLOWED_ORDER_DIRECTIONS, true) ? $rawType : 'desc';

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
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );

            $itemCategoryArray = [];

            $addArray[] = [
                'id'          => 0,
                'name'        =>  trans('all.label.all_items'),
                'slug'        => 'all-items',
                'thumb'       => asset("images/default/all-category.png"),
                'cover'       => asset("images/default/all-category.png")
            ];
            foreach ($itemCategories as $itemCategory) {
                $itemCategoryArray[] = [
                    'id'          => $itemCategory->id,
                    'name'        => $itemCategory->name,
                    'slug'        => $itemCategory->slug,
                    'description' => $itemCategory->description === null ? '' : $itemCategory->description,
                    'status'      => $itemCategory->status,
                    'thumb'       => $itemCategory->thumb,
                    'cover'       => $itemCategory->cover
                ];
            }

            $newObj = array_merge($addArray, $itemCategoryArray);

            return ['data'  => $newObj];
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
