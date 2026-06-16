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
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return PaymentGatewayResource::collection($this->paymentGatewayService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
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
            return $this->jsonError($exception, 422);
        }
    }
}
