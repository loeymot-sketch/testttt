<?php

namespace App\Jobs;

use App\Services\FcmNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * [PHASE-36-P1] Queue job for sending FCM push notifications.
 *
 * This job runs asynchronously via the database queue driver,
 * ensuring that FCM API calls (which can be slow ~200-500ms)
 * never block the HTTP response or order creation flow.
 *
 * Queued by: OrderStatusChanged listener, OrderCreated listener, etc.
 * Queue: default (or 'fcm' for dedicated worker if high volume)
 */
class SendFcmNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    /**
     * Backoff strategy: wait 10s, then 30s before retry.
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    /**
     * Create a new job instance.
     *
     * @param string|null $token   Device token (null = use topic)
     * @param string|null $topic    Topic name (null = use token)
     * @param string      $title    Notification title
     * @param string      $body     Notification body
     * @param array       $data     Data payload
     * @param string      $sound    Sound file
     */
    public function __construct(
        public ?string $token,
        public ?string $topic,
        public string $title,
        public string $body,
        public array $data = [],
        public string $sound = 'default'
    ) {
        // [NEW-03] Route to the dedicated 'notifications' queue so slow FCM
        // sends cannot starve `high` (DispatchDomainEventsJob → POS/KDS sync).
        // Encoded in the constructor so dispatch sites do not need to chain
        // ->onQueue('notifications').
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Token takes precedence over topic
        if (!empty($this->token)) {
            $result = FcmNotificationService::sendToToken(
                $this->token,
                $this->title,
                $this->body,
                $this->data,
                $this->sound
            );
        } elseif (!empty($this->topic)) {
            $result = FcmNotificationService::sendToTopic(
                $this->topic,
                $this->title,
                $this->body,
                $this->data
            );
        } else {
            Log::warning('[SendFcmNotificationJob] No token or topic provided — skipping');
            return;
        }

        if (!$result) {
            // Throwing an exception triggers a retry (up to $tries)
            throw new \RuntimeException('FCM send failed — will retry');
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[SendFcmNotificationJob] Final failure after retries', [
            'token'  => $this->token ? substr($this->token, 0, 20) . '...' : null,
            'topic'  => $this->topic,
            'title'  => $this->title,
            'error'  => $exception->getMessage(),
        ]);
    }
}
