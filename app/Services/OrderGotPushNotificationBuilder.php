<?php

namespace App\Services;


use App\Enums\Role;
use App\Enums\SwitchBox;
use App\Models\FrontendOrder;
use App\Models\NotificationAlert;
use App\Enums\OrderType;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;

class OrderGotPushNotificationBuilder
{
    public int $orderId;
    public object $order;

    public function __construct($orderId)
    {
        $this->orderId = $orderId;
        $this->order = FrontendOrder::find($orderId);
    }

    public function send(): void
    {
        if (!blank($this->order)) {
            // Combined single query for all FCM tokens (web and mobile)
            $fcmTokenArray = User::where(function ($query) {
                $query->where(function ($q) {
                    $q->role('Admin')->where('branch_id', 0);
                })->orWhere(function ($q) {
                    $q->role('Admin')->where('branch_id', $this->order->branch_id);
                })->orWhere(function ($q) {
                    $q->role('Branch Manager')->where('branch_id', $this->order->branch_id);
                })->orWhere(function ($q) {
                    $q->role('POS Operator')->where('branch_id', $this->order->branch_id);
                });
            })->where(function ($q) {
                $q->whereNotNull('web_token')->orWhereNotNull('device_token');
            })->get()->flatMap(function ($user) {
                $tokens = [];
                if ($user->web_token) {
                    $tokens[] = $user->web_token;
                }
                if ($user->device_token) {
                    $tokens[] = $user->device_token;
                }
                return $tokens;
            })->unique()->values()->toArray();

            if (count($fcmTokenArray) > 0) {
                try {
                    $notificationAlert = NotificationAlert::where(['language' => 'admin_and_branch_manager_new_order_message'])->first();
                    if ($notificationAlert && $notificationAlert->push_notification == SwitchBox::ON) {
                        $pushNotification = (object) [
                            'title' => 'New Order Notification',
                            'description' => $notificationAlert->push_notification_message,
                            'order_id' => $this->orderId
                        ];
                        $firebase = new FirebaseService();
                        $firebase->sendNotification($pushNotification, $fcmTokenArray, $this->order->order_type == OrderType::DINING_TABLE ? "new-table-order-found" : "new-order-found");
                    }
                } catch (Exception $e) {
                    Log::info($e->getMessage());
                }
            }

        }
    }
}
