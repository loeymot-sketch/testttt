<?php

namespace App\Http\Controllers\Admin;

use App\Exports\ItemExport;
use App\Http\Requests\ItemImportRequest;
use App\Http\Resources\NormalItemResource;
use App\Http\Resources\SimpleItemResource;
use App\Imports\ItemImport;
use Exception;
use App\Models\Item;
use App\Services\Catalog\CatalogWarningService;
use App\Services\ItemService;
use App\Http\Requests\ItemRequest;
use App\Http\Resources\ItemResource;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\ChangeImageRequest;
use Illuminate\Support\Facades\DB;
use Response;

class ItemController extends AdminController
{
    public ItemService $itemService;

    public function __construct(ItemService $itemService)
    {
        parent::__construct();
        $this->itemService = $itemService;
        $this->middleware(['permission:items'])->only('export');
        $this->middleware(['permission:items_create'])->only('store', 'import');
        $this->middleware(['permission:items_edit'])->only('update', 'changeImage');
        $this->middleware(['permission:items_delete'])->only('destroy');
        $this->middleware(['permission:items_show'])->only('show', 'downloadSample');
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            abort_unless($user && $user->canAny(['items_show', 'pos']), 403);

            return $next($request);
        })->only('index', 'itemDetails', 'lookupBarcode');
    }

    public function index(PaginateRequest $request) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        $forcedBranchId = $this->forcePosRuntimeBranchScope($request);
        $branchId = $forcedBranchId ?? ($request->filled('branch_id') ? (int) $request->get('branch_id') : null);

        if ($branchId !== null) {
            $this->authorizeBranchScope($request, $branchId);
        }

        // CV1 catalog convergence (audit CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_1 §A.1 #3):
        // POS-only runtime callers (permission `pos` without catalog `items_show`) must get
        // `?surface=pos` semantics by default so `/api/admin/item` never leaks kiosk-only SKUs.
        // Same heuristic as forcePosRuntimeBranchScope(). Client-provided surface wins.
        $this->applyDefaultPosSurfaceForPosRuntimeUser($request);

        try {
            return SimpleItemResource::collection($this->itemService->simpleList($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(Item $item) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        if (request()->filled('branch_id')) {
            $this->authorizeBranchScope(request(), (int) request()->get('branch_id'));
        }

        try {
            $loaded   = $this->itemService->show($item);
            $resource = new ItemResource($loaded);

            if (config('catalog_v15.warnings.expose_to_admin_show', true)) {
                $branchId = request()->filled('branch_id') ? (int) request()->get('branch_id') : null;
                $warnings = app(CatalogWarningService::class)->forItem($loaded, $branchId);

                return $resource->additional(['warnings' => $warnings]);
            }

            return $resource;
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(ItemRequest $request) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            if (env('DEMO')) {
                return new ItemResource($this->itemService->store($request));
            } else {
                    return new ItemResource($this->itemService->store($request));
            }
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(ItemRequest $request, Item $item) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new ItemResource($this->itemService->update($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Item $item) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory | \Illuminate\Http\JsonResponse
    {
        $forceDelete = request()->boolean('force');

        try {
            $this->itemService->destroy($item, $forceDelete);
            return response('', 202);
        } catch (Exception $exception) {
            if ((int) $exception->getCode() === 409) {
                return response()->json([
                    'status' => false,
                    'message' => $exception->getMessage(),
                    'error' => 'errors.item.cannot_force_delete_with_history',
                ], 409);
            }

            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeImage(ChangeImageRequest $request, Item $item) : \Illuminate\Http\Response | ItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        $user = $request->user();
        abort_if(! $user || (! $user->hasRole('Admin') && ! $user->hasRole('Tenant Admin')), 403);

        try {
            return new ItemResource($this->itemService->changeImage($request, $item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function export(PaginateRequest $request) : \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return Excel::download(new ItemExport($this->itemService, $request), 'Item.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function downloadSample()
    {
        try {
            return Response::download(public_path('/file/itemImportSample.xlsx'));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function import(ItemImportRequest $request)
    {
        try {
            Excel::import(new ItemImport($request->file('file')), $request->file('file'));
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function itemDetails(Item $item)
    {
        $forcedBranchId = $this->forcePosRuntimeBranchScope(request());
        $branchId = $forcedBranchId ?? (request()->filled('branch_id') ? (int) request()->get('branch_id') : null);

        if ($branchId !== null) {
            $this->authorizeBranchScope(request(), $branchId);
        }

        $surface = strtolower(trim((string) request()->get('surface', '')));
        if (in_array($surface, ['pos', 'kiosk', 'web'], true)) {
            $item->loadMissing('category');
            abort_unless($item->isVisibleOn($surface), 404);
            abort_unless(! $item->category || $item->category->isVisibleOn($surface), 404);
        }

        try {
           return new NormalItemResource($this->itemService->itemDetails($item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * POS: resolve an item by exact barcode (available + visible on pos channel).
     */
    public function lookupBarcode(string $code)
    {
        try {
            $code = rawurldecode($code);
            $base = Item::query()
                ->with('media', 'category', 'offer')
                ->where('barcode', $code)
                ->where('is_available', true)
                ->where(function ($q) {
                    $q->whereNull('channels');
                    if (DB::connection()->getDriverName() === 'sqlite') {
                        $q->orWhere('channels', 'like', '%"pos"%');
                        return;
                    }
                    $q->orWhereJsonContains('channels', 'pos');
                });
            $count = (clone $base)->count();
            $item = (clone $base)->orderBy('id')->first();
            if (! $item) {
                return response()->json(['error' => 'not_found'], 404);
            }
            if ($count > 1) {
                Log::warning('POS barcode lookup: multiple available items share barcode', [
                    'barcode' => $code,
                    'count' => $count,
                ]);
            }

            return (new SimpleItemResource($item))->additional([
                'meta' => [
                    'duplicate_barcode' => $count > 1,
                ],
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function forcePosRuntimeBranchScope($request): ?int
    {
        $user = $request->user();
        if (! $user || $user->can('items_show') || ! $user->can('pos')) {
            return null;
        }

        $branchId = (int) $user->branch_id;
        abort_if($branchId < 1, 403);

        $request->merge(['branch_id' => $branchId]);
        $request->query->set('branch_id', $branchId);

        return $branchId;
    }

    /**
     * DefaultAccessService only tracks saved defaults (branch, etc.). POS vs admin "surface intent"
     * is inferred here from the same Spatie gates as POS runtime branch forcing: `pos` without
     * `items_show` means menu-only callers that must not see kiosk-scoped SKUs unless they pass ?surface=kiosk.
     */
    private function applyDefaultPosSurfaceForPosRuntimeUser(\Illuminate\Http\Request $request): void
    {
        $user = $request->user();
        if (! $user || $user->can('items_show') || ! $user->can('pos')) {
            return;
        }
        if ($request->filled('surface')) {
            return;
        }

        $request->merge(['surface' => 'pos']);
        $request->query->set('surface', 'pos');
    }
}
