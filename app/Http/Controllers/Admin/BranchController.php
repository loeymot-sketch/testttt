<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Events\BranchStatusChanged;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Services\BranchService;
use App\Http\Requests\BranchRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\BranchResource;

class BranchController extends AdminController
{
    public BranchService $branchService;

    public function __construct(BranchService $branch)
    {
        parent::__construct();
        $this->branchService = $branch;
        $this->middleware(['permission:settings'])->only('store', 'update', 'destroy', 'updateZone');
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return BranchResource::collection($this->branchService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function show(
        Branch $branch
    ): BranchResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new BranchResource($this->branchService->show($branch));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(
        BranchRequest $request
    ): BranchResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new BranchResource($this->branchService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function update(
        BranchRequest $request,
        Branch $branch
    ): BranchResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            // [Wave 5G R10 heal 2026-05-17] Capture old status BEFORE service
            // mutation — BranchService::update uses tap(...)->update() which
            // mutates the model in place; reading $branch->status after the
            // service call would always equal the new value.
            $oldStatus = (int) $branch->status;
            $updated   = $this->branchService->update($request, $branch);
            $newStatus = (int) $updated->status;

            if ($oldStatus !== $newStatus) {
                BranchStatusChanged::dispatch((int) $updated->id, $oldStatus, $newStatus);
            }

            return new BranchResource($updated);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(
        Branch $branch
    ): \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->branchService->destroy($branch);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function updateZone(Request $request, Branch $branch)
    {
        try {
            return new BranchResource($this->branchService->updateZone($request, $branch));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function showByLatLong(Request $request, Branch $branch)
    {
        try {
            return new BranchResource($this->branchService->branchShowByLatLong($request, $branch));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}