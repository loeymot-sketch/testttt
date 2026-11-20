<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Abonnement Web Push d'un appareil de client (`PushSubscription.toJSON()` du navigateur).
 * Exige un client connecté ; le point d'accès doit être une adresse https (jamais http, jamais un schéma exotique).
 */
class WebPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // [audit adverse P1] Une origine inconnue (ex. un `?src=flyer` mémorisé par le site) ne doit JAMAIS faire échouer l'abonnement.
        $brut = $this->input('origin', '');
        $o = is_string($brut) ? strtolower($brut) : '';
        $this->merge(['origin' => in_array($o, ['nfc', 'site', 'app'], true) ? $o : 'site']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => ['bail', 'required', 'string', 'max:2048', function ($attribute, $value, $fail) {
                if (! \App\Services\Push\PushEndpoint::estAutorise((string) $value)) {
                    $fail('Adresse de notification non reconnue.');
                }
            }],
            'keys' => ['required', 'array'],
            // Clés réelles : une clé publique P-256 non compressée (65 octets, 0x04…) et un secret d'au moins 16 octets. Une clé invalide
            // fait échouer le chiffrement à l'envoi : mieux vaut la refuser ici (audit adverse tour 2).
            'keys.p256dh' => ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
                $brut = base64_decode(strtr((string) $value, '-_', '+/'), true);
                if ($brut === false || strlen($brut) !== 65 || $brut[0] !== "\x04") {
                    $fail('Clé d\'appareil invalide.');
                }
            }],
            'keys.auth' => ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
                $brut = base64_decode(strtr((string) $value, '-_', '+/'), true);
                if ($brut === false || strlen($brut) < 16) {
                    $fail('Secret d\'appareil invalide.');
                }
            }],
            'encoding' => ['nullable', 'string', 'in:aes128gcm,aesgcm'],
            'origin' => ['nullable', 'string', 'max:20'], // une origine inconnue est ramenée à « site » (prepareForValidation)
            'device_label' => ['nullable', 'string', 'max:60'],
        ];
    }
}
