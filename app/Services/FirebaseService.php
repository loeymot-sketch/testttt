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

    /**
     * @return array{destinataires:int, envoyes:int, echecs:int, erreur:string|null}
     *   [ONB-09 2026-08-28] Le type de retour etait `void` : l'appelant ne pouvait
     *   RIEN savoir. C'est ce qui rendait le mensonge de l'ecran inevitable.
     */
    public function sendNotification($data, $fcmTokens, $topicName): array
    {
        // [ONB-09 2026-08-28] La methode ne rendait RIEN : l'appelant ne pouvait pas
        // savoir si la notification etait partie, ni vers combien d'appareils. Elle
        // rend desormais un compte rendu — c'est la seule facon pour l'ecran de dire
        // la verite au commercant.
        $destinataires = is_array($fcmTokens) ? count($fcmTokens) : 0;
        $envoyes = 0;
        $echecs = 0;
        $erreurGlobale = null;


        try {
            $notification = Settings::group('notification')->all();

            $url = 'https://fcm.googleapis.com/v1/projects/' . $notification['notification_fcm_project_id'] . '/messages:send';
            $accessToken = $this->getAccessToken();

            $envoyes = 0;
            $echecs = 0;
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
                    $envoyes++;
                } catch (\Throwable $th) {
                    // [ONB-09 2026-08-28] Ce bloc etait VIDE. Chaque echec par appareil
                    // disparaissait sans meme une trace : ni journal, ni compteur, ni
                    // remontee a l'appelant. Un jeton expire, un reseau coupe, une cle
                    // FCM revoquee — le commercant lisait « envoye avec succes ».
                    $echecs++;
                    Log::info('[push] envoi refuse pour un appareil : ' . $th->getMessage());
                }
            }
        } catch (Exception $e) {
            Log::info($e->getMessage());
            $erreurGlobale = $e->getMessage();
        }

        return [
            'destinataires' => $destinataires,
            'envoyes'       => $envoyes,
            'echecs'        => $echecs,
            'erreur'        => $erreurGlobale,
        ];
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