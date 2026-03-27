<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\LoyaltySetupRequest;
use App\Http\Resources\LoyaltySetupResource;
use App\Services\LoyaltySetupService;
use Exception;

class LoyaltySetupController extends AdminController
{
    public LoyaltySetupService $loyaltySetupService;

    public function __construct(LoyaltySetupService $loyaltySetupService)
    {
        parent::__construct();
        $this->loyaltySetupService = $loyaltySetupService;
        // [GAP-19-2] Apply permission:settings on both read and write.
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(): \Illuminate\Http\Response|LoyaltySetupResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new LoyaltySetupResource($this->loyaltySetupService->list());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(LoyaltySetupRequest $request): \Illuminate\Http\Response|LoyaltySetupResource|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new LoyaltySetupResource($this->loyaltySetupService->update($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
