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
        $payload = AppVersion::configuration() + [
            'online_payment_enabled' => (bool) config('app_mobile.online_payment_enabled', false),
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
