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
        // [abuse-heal 2026-06-20 W5 W5-SET-NOTIF-INDEX-01] Gate index too — GET /admin/setting/notification
        // returns the FCM legacy server key + Firebase service-account JSON (an arbitrary-push primitive),
        // readable by any non-settings staff (POS/Chef). Missed sibling of the Mail SET-02 secret-index heal
        // (Mail/SmsGateway/PaymentGateway/KioskSetup/LoyaltySetup all use ->only('index','update')).
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index(
    ) : \Illuminate\Http\Response | NotificationResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return new NotificationResource($this->notificationService->list());
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function update(NotificationRequest $request
    ) : \Illuminate\Http\Response | NotificationResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new NotificationResource($this->notificationService->update($request));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
