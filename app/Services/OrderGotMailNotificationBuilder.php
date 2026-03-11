<?php

namespace App\Services;


use App\Enums\Role;
use App\Enums\SwitchBox;
use App\Mail\OrderGotMail;
use App\Models\FrontendOrder;
use App\Models\NotificationAlert;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderGotMailNotificationBuilder
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
            // Combined single query for all recipients
            $emailArray = User::where(function ($query) {
                $query->where(function ($q) {
                    $q->role('Admin')->where('branch_id', 0);
                })->orWhere(function ($q) {
                    $q->role('Admin')->where('branch_id', $this->order->branch_id);
                })->orWhere(function ($q) {
                    $q->role('Branch Manager')->where('branch_id', $this->order->branch_id);
                });
            })->whereNotNull('email')->pluck('email')->unique()->values()->toArray();

            if (count($emailArray) > 0) {
                try {
                    $notificationAlert = NotificationAlert::where(['language' => 'admin_and_branch_manager_new_order_message'])->first();
                    if ($notificationAlert && $notificationAlert->mail == SwitchBox::ON) {
                        try {
                            Mail::to($emailArray[0])->cc($emailArray)->send(new OrderGotMail($this->order->order_serial_no, $notificationAlert->mail_message));
                        } catch (Exception $e) {
                            Log::info($e->getMessage());
                        }
                    }
                } catch (Exception $e) {
                    Log::info($e->getMessage());
                }
            }

        }
    }
}
