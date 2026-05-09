<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * [P0-FIX-1 NF525 / Payment-Idempotency — 2026-05-09]
 *
 * WebhookEvent acts as the unified idempotency ledger for payment provider
 * webhooks (Stripe + SenangPay + future). The handler MUST follow the
 * `firstOrCreate` pattern keyed on (provider, webhook_id) to be idempotent
 * under retry storms.
 *
 * Recommended handler skeleton:
 *
 *   $event = WebhookEvent::firstOrCreate(
 *       ['provider' => 'senangpay', 'webhook_id' => $request->input('txn_id')],
 *       [
 *           'event_type'  => $request->input('payment_status'),
 *           'payload'     => $request->all(),
 *           'signature'   => $request->input('hash'),
 *           'received_at' => now(),
 *           'status'      => 'pending',
 *       ]
 *   );
 *
 *   if ($event->isProcessed()) {
 *       return response()->json(['idempotent' => true], 200);
 *   }
 *
 *   try {
 *       DB::transaction(function () use ($event, $request) {
 *           // ... process payment, update OrderPayment, etc. ...
 *           $event->markProcessed();
 *       });
 *   } catch (\Throwable $e) {
 *       $event->markFailed($e->getMessage());
 *       throw $e;
 *   }
 *
 * Branch isolation note: WebhookEvent is intentionally global (no
 * BranchScope) because providers don't carry tenant context in webhooks.
 * The eventual order_id FK link inherits branch via Order.
 */
class WebhookEvent extends Model
{
    use HasFactory;

    public const PROVIDER_STRIPE = 'stripe';
    public const PROVIDER_SENANGPAY = 'senangpay';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DUPLICATE = 'duplicate';

    protected $table = 'webhook_events';

    protected $fillable = [
        'provider',
        'webhook_id',
        'event_type',
        'payload',
        'signature',
        'received_at',
        'processed_at',
        'status',
        'error_message',
        'attempts',
        'order_id',
    ];

    protected $casts = [
        'payload'      => 'array',
        'received_at'  => 'datetime',
        'processed_at' => 'datetime',
        'attempts'     => 'integer',
        'order_id'     => 'integer',
    ];

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isDuplicate(): bool
    {
        return $this->status === self::STATUS_DUPLICATE;
    }

    public function markProcessed(?int $orderId = null): void
    {
        $this->forceFill([
            'status'       => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'order_id'     => $orderId ?? $this->order_id,
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'status'        => self::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 65535),
            'attempts'      => $this->attempts + 1,
        ])->save();
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
