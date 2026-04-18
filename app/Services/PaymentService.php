<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Support\Facades\Auth;

class PaymentService
{
    public function payment($order, $gatewaySlug, $transactionNo)
    {
        $transaction = Transaction::where(['order_id' => $order->id])->first();
        if (!$transaction) {
            $transaction = Transaction::create([
                'order_id'       => $order->id,
                'transaction_no' => $transactionNo,
                'amount'         => $order->total,
                'payment_method' => $gatewaySlug,
                'sign'           => '+',
                'type'           => 'payment'
            ]);
        }
        $order->payment_status = PaymentStatus::PAID;
        $order->save();
        return $transaction;
    }

    public function cashBack($order, $gatewaySlug, $transactionNo)
    {
        $transaction = Transaction::where(['order_id' => $order->id])->first();
        if ($transaction) {
            $transaction = Transaction::create([
                'order_id'       => $order->id,
                'transaction_no' => $transactionNo,
                'amount'         => $order->total,
                'payment_method' => $gatewaySlug,
                'sign'           => '-',
                'type'           => 'cash_back'
            ]);

            $user = User::find($order->user_id);
            if ($user) {
                $user->balance = ($user->balance + $order->total);
                $user->save();
            }

            // [POS-9.4.BL.2] NF525 audit trail on cash back. A cash back is
            // fiscally equivalent to a refund — it must leave a tamper-evident
            // record on the HMAC chain so a fraudulent cashier can be
            // detected even if the Transaction row is later mutated.
            app(AuditLogService::class)->write([
                'branch_id'   => (int) ($order->branch_id ?? 0),
                'user_id'     => Auth::check() ? (int) Auth::id() : null,
                'action'      => 'payment.cash_back_issued',
                'resource'    => 'order',
                'resource_id' => (int) $order->id,
                'payload'     => [
                    'order_serial_no'     => $order->order_serial_no,
                    'transaction_id'      => $transaction?->id,
                    'transaction_no'      => $transactionNo,
                    'payment_method'      => $gatewaySlug,
                    'amount'              => round((float) $order->total, 2),
                    'fiscal_sequence_no'  => $order->fiscal_sequence_no,
                ],
            ]);
        }

        return $transaction;
    }
}
