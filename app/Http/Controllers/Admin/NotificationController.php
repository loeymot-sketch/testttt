<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\NotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Services\NotificationService;
use Exception;

class NotificationController extends AdminController
{
    private NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
        // [ULTRA-AUDIT V4-DEPLOY 2026-07-02] `index` DOIT être gaté comme `update` : NotificationResource
        // expose notification_fcm_api_key + notification_fcm_json_file (service-account). Sans le gate,
        // un rôle non-admin (POS Operator/Chef, can_settings=N) lit les credentials FCM. Miroir des
        // siblings healés Mail (SET-02) / PaymentGateway (SET-01) / KioskSetup / LoyaltySetup.
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(
    ) : \Illuminate\Http\Response | NotificationResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new NotificationResource($this->notificationService->list());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(NotificationRequest $request
    ) : \Illuminate\Http\Response | NotificationResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new NotificationResource($this->notificationService->update($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
