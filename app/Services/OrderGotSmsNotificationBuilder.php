<?php

namespace App\Services;


use App\Enums\Role;
use App\Enums\SwitchBox;
use App\Models\FrontendOrder;
use App\Models\NotificationAlert;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;

class OrderGotSmsNotificationBuilder
{
    public int $orderId;
    public object $order;

    public function __construct($orderId)
    {
        $this->orderId = $orderId;
        $this->order = FrontendOrder::find($orderId);
    }

    public function send()
    {
        if (!blank($this->order)) {
            // Combined single query for all SMS recipients
            $smsArrays = User::where(function ($query) {
                $query->where(function ($q) {
                    $q->role('Admin')->where('branch_id', 0);
                })->orWhere(function ($q) {
                    $q->role('Admin')->where('branch_id', $this->order->branch_id);
                })->orWhere(function ($q) {
                    $q->role('Branch Manager')->where('branch_id', $this->order->branch_id);
                });
            })->whereNotNull('phone')->get()->map(function ($user) {
                return [
                    'code' => $user->country_code,
                    'phone' => $user->phone,
                ];
            })->unique(function ($item) {
                return $item['code'] . $item['phone'];
            })->values()->toArray();

            if (count($smsArrays) > 0) {
                try {
                    $notificationAlert = NotificationAlert::where(['language' => 'admin_and_branch_manager_new_order_message'])->first();
                    if ($notificationAlert && $notificationAlert->sms == SwitchBox::ON) {
                        $message = 'Order ID : '.$this->order->order_serial_no . ' '.$notificationAlert->sms_message;
                        foreach ($smsArrays as $smsArray) {
                            $this->sms($smsArray['code'], $smsArray['phone'], $message);
                        }
                    }
                } catch (Exception $e) {
                    Log::info($e->getMessage());
                }
            }
        }
    }

    private function sms($code, $phone, $message): void
    {
        try {
            $smsManagerService = new SmsManagerService();
            $smsService        = new SmsService();
            if ($smsService->gateway() && $smsManagerService->gateway($smsService->gateway())->status()) {
                $smsManagerService->gateway($smsService->gateway())->send($code, $phone, $message);
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
        }
    }
}
