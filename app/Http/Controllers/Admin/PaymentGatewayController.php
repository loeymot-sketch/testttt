<?php

namespace App\Http\Controllers\Admin;


use App\Http\Requests\PaginateRequest;
use App\Http\Resources\PaymentGatewayResource;
use App\Services\PaymentGatewayService;
use Exception;
use Illuminate\Http\Request;


class PaymentGatewayController extends AdminController
{
    private PaymentGatewayService $paymentGatewayService;

    public function __construct(PaymentGatewayService $paymentGatewayService)
    {
        parent::__construct();
        $this->paymentGatewayService = $paymentGatewayService;
        // [SET-01 heal 2026-06-01] Gate index too: GET /admin/setting/payment-gateway
        // returns gateway_options 'value' incl. secrets (stripe_secret, paypal_client_secret…)
        // via GatewayOptionsResource. Only the settings component consumes this read, so
        // gating index does not break any non-settings surface. Mirrors Mail (SET-02) /
        // KioskSetup / LoyaltySetup ->only('index','update').
        //
        // [HEAL dispute-r1 B-R1-19 2026-06-12] The /admin/transactions « Mode
        // de paiement » filter ALSO consumes this read — the SET-01 gate made
        // every Branch Manager visit 403 + uncaught AxiosError. index now
        // accepts settings OR transactions; the SET-01 secret-leak intent is
        // preserved at the RESOURCE level: PaymentGatewayResource strips the
        // option values unless the caller holds `settings`. update stays
        // settings-only (write gate unchanged, both middlewares stack on it).
        // Sentinels: GatewaySecretIndexAuthzSentinelTest (structural) +
        // PaymentGatewayIndexBranchManagerAccessTest (behavioral).
        $this->middleware(['permission:settings|transactions'])->only('index', 'update');
        $this->middleware(['permission:settings'])->only('update');
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return PaymentGatewayResource::collection($this->paymentGatewayService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(
        Request $request
    ): PaymentGatewayResource | \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        $className          = 'App\\Http\\PaymentGateways\\Requests\\' . ucfirst($request->payment_type);
        $gateway            = new $className;
        $validationRequests = $request->validate($gateway->rules());

        try {
            return new PaymentGatewayResource($this->paymentGatewayService->update($validationRequests));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
