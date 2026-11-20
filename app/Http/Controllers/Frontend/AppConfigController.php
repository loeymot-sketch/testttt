<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * [STORES T-3.3.2 · 2026-10-01] Ce que l'application des stores doit savoir du serveur
 * avant de laisser commander : la version la plus ancienne encore acceptée, le message à
 * afficher sinon, et où se trouve la mise à jour. Public et en lecture seule.
 */
class AppConfigController extends Controller
{
    public function show(Request $request): JsonResponse|Response
    {
        // [GOAL APP HYBRIDE · W4 · 2026-11-03] + interrupteur du paiement dans l'application (faux par défaut).
        // [TEST APP + PAIEMENT · 2026-11-10] On n'annonce un paiement en ligne que s'il peut ABOUTIR : mêmes conditions que
        // le checkout (MolliePaymentController::checkout). Constaté en production : réglage « Passerelle de paiement en
        // ligne » DÉSACTIVÉ alors que le site proposait la carte → le client saisissait sa carte, recevait un 503, et se
        // voyait renvoyer vers « Payer sur place ». Le réglage du propriétaire pilote désormais ce que le client VOIT :
        // `web_online_payment_enabled` pour le site, `online_payment_enabled` (en plus de son interrupteur) pour l'app.
        // Mêmes deux conditions que Mollie::isMollieConfigured(), lues sans instancier la passerelle (son constructeur
        // interroge la base) : cette route est relue à chaque retour de l'application au premier plan.
        $passerelle = (bool) config('payment.mollie.enabled', false)
            && (string) config('payment.mollie.api_key', '') !== ''
            && (int) \Smartisan\Settings\Facades\Settings::group('site')
                ->get('site_online_payment_gateway', \App\Enums\Activity::ENABLE) === \App\Enums\Activity::ENABLE;
        $payload = AppVersion::configuration() + [
            'online_payment_enabled'     => (bool) config('app_mobile.online_payment_enabled', false) && $passerelle,
            'web_online_payment_enabled' => $passerelle,
        ];

        // [GOAL LIVRAISON-TICKETS 2026-11-10 · T-3.4.1] ETag + 304 comme la vitrine : l'application relit cette configuration
        // à chaque retour au premier plan ; sans changement, la relecture ne coûte plus de corps (mesuré : 200 à chaque fois).
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $etag = '"cfg-'.substr(sha1((string) $body), 0, 20).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'public, max-age=0, must-revalidate'];
        foreach (explode(',', (string) $request->header('If-None-Match', '')) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate !== '' && ($candidate === $etag || $candidate === '*')) {
                return response('', 304, $headers);
            }
        }

        return response()->json($payload, 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
