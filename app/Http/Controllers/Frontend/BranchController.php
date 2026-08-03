<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Models\Branch;
use Illuminate\Http\Request;
use App\Services\BranchService;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\BranchResource;

class BranchController extends Controller
{
    public BranchService $branchService;

    public function __construct(BranchService $branch)
    {
        $this->branchService = $branch;
    }

    public function index(PaginateRequest $request): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [A-002 2026-07-17] Ordre STABLE id ASC côté frontend : la borne
            // (KioskAppComponent frozen) prend branches[0] pour se scoper et
            // s'abonner au temps réel — le défaut DESC du service mettait une
            // branche Faker dev (id 9) en tête → abonnement private-branch.9 ≠
            // branche machine → broadcasting/auth 403, push rupture-86 mort.
            // Tri explicite du résultat (pas de merge de request : fiable
            // uniformément en HTTP live ET en Feature tests).
            $result = $this->branchService->list($request);
            if (! $request->filled('order_column') && ! $request->filled('order_type')
                && $result instanceof \Illuminate\Support\Collection) {
                $result = $result->sortBy('id')->values();
            }

            return BranchResource::collection($result);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(Branch $branch): BranchResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new BranchResource($this->branchService->show($branch));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function showByLatLong(Request $request)
    {
        try {
            return new BranchResource($this->branchService->showByLatLong($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}