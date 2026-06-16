<?php

namespace App\Http\Controllers\Admin;


use App\Http\Requests\PaginateRequest;
use App\Http\Resources\SmsGatewayResource;
use App\Services\SmsGatewayService;
use Exception;
use Illuminate\Http\Request;


class SmsGatewayController extends AdminController
{

    private SmsGatewayService $smsGatewayService;

    public function __construct(SmsGatewayService $smsGatewayService)
    {
        parent::__construct();
        $this->smsGatewayService = $smsGatewayService;
        // [SET-01 heal 2026-06-01] Gate index too: GET /admin/setting/sms-gateway returns
        // gateway_options 'value' incl. secrets (twilio_auth_token, nexmo_secret, msg91_key…)
        // via GatewayOptionsResource. Gating index does not break any non-settings surface.
        // Mirrors Mail (SET-02) / KioskSetup / LoyaltySetup ->only('index','update').
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return SmsGatewayResource::collection($this->smsGatewayService->list($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function update(
        Request $request
    ): \Illuminate\Http\Response | SmsGatewayResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        $className          = 'App\\Http\\SmsGateways\\Requests\\' . ucfirst($request->sms_type);
        $gateway            = new $className;
        $validationRequests = $request->validate($gateway->rules());

        try {
            return new SmsGatewayResource($this->smsGatewayService->update($validationRequests));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
