<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\ItemCategory;
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
            }

            $itemCategories = $itemCategories->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );

            $itemCategoryArray = [];

            // [2026-05-18 F-4] Featured allowlist for the POS first-page filter.
            // Empty config = "no filter" → every category renders as featured
            // (safe default before owner provisions the env knob).
            $featuredIds = (array) config('pos.featured_category_ids', []);
            $featuredSet = ! empty($featuredIds)
                ? array_flip(array_map('intval', $featuredIds))
                : null;

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
