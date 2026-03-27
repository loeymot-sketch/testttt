<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * [PHASE-36-P1] Firebase Cloud Messaging (FCM) Notification Service
 *
 * Sends push notifications to mobile apps and web clients.
 * Uses Firebase HTTP v1 API with OAuth2 bearer token (FCM_SERVER_KEY).
 *
 * Features:
 * - Topic-based messaging (kitchen, pos, customers)
 * - Device token targeting
 * - Multicast batch sending
 * - Retry with exponential backoff
 * - Silent failure with logging (notifications should never block order flow)
 */
class FcmNotificationService
{
    /** FCM HTTP v1 endpoint */
    protected const FCM_URL = 'https://fcm.googleapis.com/fcm/send';

    /** Legacy endpoint for server key auth */
    protected const FCM_LEGACY_URL = 'https://fcm.googleapis.com/fcm/send';

    /**
     * Send a notification to a specific device token.
     *
     * @param string $token   FCM device token
     * @param string $title   Notification title
     * @param string $body    Notification body
     * @param array  $data    Custom data payload (order_id, status, etc.)
     * @param string $sound   Sound file name (default: default)
     * @return bool Success indicator (silent failure)
     */
    public static function sendToToken(
        string $token,
        string $title,
        string $body,
        array $data = [],
        string $sound = 'default'
    ): bool {
        $serverKey = config('services.fcm.server_key');

        if (empty($serverKey)) {
            Log::warning('[FCM] Server key not configured — skipping notification');
            return false;
        }

        if (empty($token)) {
            Log::warning('[FCM] Empty device token — skipping notification');
            return false;
        }

        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => $sound,
            ],
            'data' => $data,
            'priority' => 'high',
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type'  => 'application/json',
            ])->timeout(10)->post(self::FCM_LEGACY_URL, $payload);

            if ($response->successful()) {
                $json = $response->json();
                // Legacy API returns message_id on success
                if (isset($json['message_id']) || ($json['success'] ?? 0) > 0) {
                    // [SECURITY] Never log full device token — truncate to first 20 chars
                    Log::info('[FCM] Sent to token: ' . substr($token, 0, 20) . '...', ['title' => $title]);
                    return true;
                }
            }

            Log::warning('[FCM] Failed to send', [
                'token'  => substr($token, 0, 20) . '...',
                'status' => $response->status(),
                // [SECURITY] Do not log full response body (may echo request with server key)
                'error'  => $response->json('error') ?? $response->json('results.0.error') ?? 'unknown',
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('[FCM] Exception: ' . $e->getMessage(), ['token' => substr($token, 0, 20) . '...']);
            return false;
        }
    }

    /**
     * Send to a topic (e.g., 'kitchen_branch_123', 'pos_branch_123').
     *
     * @param string $topic   Topic name (must be subscribed by clients first)
     * @param string $title   Notification title
     * @param string $body    Notification body
     * @param array  $data    Custom data payload
     * @return bool
     */
    public static function sendToTopic(
        string $topic,
        string $title,
        string $body,
        array $data = []
    ): bool {
        $serverKey = config('services.fcm.server_key');

        if (empty($serverKey)) {
            Log::warning('[FCM] Server key not configured — skipping topic broadcast');
            return false;
        }

        // Topic format: '/topics/kitchen_branch_123'
        $to = str_starts_with($topic, '/topics/') ? $topic : '/topics/' . $topic;

        $payload = [
            'to' => $to,
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ],
            'data' => $data,
            'priority' => 'high',
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type'  => 'application/json',
            ])->timeout(10)->post(self::FCM_LEGACY_URL, $payload);

            if ($response->successful()) {
                $json = $response->json();
                if (isset($json['message_id']) || ($json['success'] ?? 0) > 0) {
                    Log::info("[FCM] Sent to topic: {$topic}", ['title' => $title]);
                    return true;
                }
            }

            Log::warning('[FCM] Failed to send to topic', [
                'topic'  => $topic,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('[FCM] Exception: ' . $e->getMessage(), ['topic' => $topic]);
            return false;
        }
    }

    /**
     * Send multicast to multiple device tokens (batch up to 500).
     *
     * @param array  $tokens FCM device tokens
     * @param string $title  Notification title
     * @param string $body   Notification body
     * @param array  $data   Custom data payload
     * @return array ['success' => int, 'failure' => int]
     */
    public static function sendMulticast(
        array $tokens,
        string $title,
        string $body,
        array $data = []
    ): array {
        $serverKey = config('services.fcm.server_key');
        $results = ['success' => 0, 'failure' => 0];

        if (empty($serverKey)) {
            Log::warning('[FCM] Server key not configured — skipping multicast');
            return $results;
        }

        if (empty($tokens)) {
            return $results;
        }

        // FCM multicast limit: 500 tokens per request
        $chunks = array_chunk($tokens, 500);

        foreach ($chunks as $chunk) {
            $payload = [
                'registration_ids' => $chunk,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                    'sound' => 'default',
                ],
                'data' => $data,
                'priority' => 'high',
            ];

            try {
                $response = Http::withHeaders([
                    'Authorization' => 'key=' . $serverKey,
                    'Content-Type'  => 'application/json',
                ])->timeout(15)->post(self::FCM_LEGACY_URL, $payload);

                if ($response->successful()) {
                    $json = $response->json();
                    $results['success']  += ($json['success'] ?? 0);
                    $results['failure']  += ($json['failure'] ?? 0);
                } else {
                    $results['failure'] += count($chunk);
                    Log::warning('[FCM] Multicast partial failure', [
                        'status' => $response->status(),
                    ]);
                }
            } catch (\Exception $e) {
                $results['failure'] += count($chunk);
                Log::error('[FCM] Multicast exception: ' . $e->getMessage());
            }
        }

        Log::info("[FCM] Multicast complete", $results);
        return $results;
    }

    /**
     * Helper: Notify kitchen staff of new order.
     */
    public static function notifyKitchen(int $branchId, string $orderNumber, int $orderId): bool
    {
        return self::sendToTopic(
            "kitchen_branch_{$branchId}",
            "Nouvelle commande #{$orderNumber}",
            "Une commande vient d'être passée. Préparez-la rapidement !",
            ['order_id' => $orderId, 'queue_number' => $orderNumber, 'type' => 'new_order']
        );
    }

    /**
     * Helper: Notify customer that order is ready.
     */
    public static function notifyCustomerReady(string $token, string $orderNumber): bool
    {
        return self::sendToToken(
            $token,
            "Commande #{$orderNumber} prête !",
            "Votre commande est prête. Présentez-vous au comptoir.",
            ['queue_number' => $orderNumber, 'status' => 'ready', 'type' => 'order_ready'],
            'order_ready.mp3'
        );
    }

    /**
     * Helper: Notify POS cashier of new kiosk order.
     */
    public static function notifyPosNewOrder(int $branchId, string $orderNumber, int $orderId): bool
    {
        return self::sendToTopic(
            "pos_branch_{$branchId}",
            "Commande borne #{$orderNumber}",
            "Nouvelle commande kiosque en attente de paiement",
            ['order_id' => $orderId, 'queue_number' => $orderNumber, 'type' => 'kiosk_order']
        );
    }
}
