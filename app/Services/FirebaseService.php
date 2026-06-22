<?php

namespace App\Services;


use Exception;
use GuzzleHttp\Client;
use App\Models\NotificationSetting;
use Illuminate\Support\Facades\Log;
use Smartisan\Settings\Facades\Settings;
use Google\Auth\Credentials\ServiceAccountCredentials;

class FirebaseService
{
    public $filePath;

    public function sendNotification($data, $fcmTokens, $topicName): void
    {

        try {
            $notification = Settings::group('notification')->all();

            $url = 'https://fcm.googleapis.com/v1/projects/' . $notification['notification_fcm_project_id'] . '/messages:send';
            $accessToken = $this->getAccessToken();

            $client  = new Client();
            $headers = [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ];
            foreach ($fcmTokens as $fcmToken) {

                $payload = [
                    'message' => [
                        'token' => $fcmToken,
                        'notification' => [
                            'title' => $data->title,
                            'body' => $data->description,
                            'image' => $data->image ?? null,
                        ],
                        'data' => [
                            'title' => $data->title,
                            'body' => $data->description,
                            'sound' => 'default',
                            'image' => $data->image ?? null,
                            'topicName' => $topicName,
                        ],
                        'webpush' => [
                            "headers" => [
                                "Urgency" => "high"
                            ]
                        ],
                    ],
                ];

                try {
                    $client->post($url, [
                        'headers' => $headers,
                        "body"    => json_encode($payload)
                    ]);
                } catch (\Throwable $th) {
                }
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
        }
    }

    function getAccessToken()
    {
        $notificationSetting = NotificationSetting::where(['key' => 'notification_fcm_json_file'])->first();
        if (!$notificationSetting) {
            throw new Exception('FCM JSON file setting not found');
        }

        // [GOAL-HEAL-SEC-001 2026-05-23] Resolve the JSON file path directly
        // from the Spatie media record instead of parsing a public URL. The
        // `notification-file` collection now lives on the non-public
        // `firebase_private` disk (storage/app/firebase/...), so the old
        // parse_url() + storage/public path math is obsolete. Phase B.3
        // Security RED-team finding B3.2-001.
        $media = $notificationSetting->getFirstMedia('notification-file');
        if (!$media) {
            throw new Exception('FCM JSON file media not found');
        }
        $this->filePath = $media->getPath();

        $SCOPES = ['https://www.googleapis.com/auth/cloud-platform'];

        if (!file_exists($this->filePath)) {
            throw new Exception('Service account key file not found');
        }

        $credentials = new ServiceAccountCredentials($SCOPES, $this->filePath);
        $token = $credentials->fetchAuthToken();

        if (isset($token['access_token'])) {
            return $token['access_token'];
        } else {
            throw new Exception('Failed to fetch access token');
        }
    }
}