<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebPushSubscriptionRequest;
use App\Models\WebPushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Abonnement des appareils d'un client aux notifications Web Push.
 *
 *  GET    /api/frontend/push/key            public  → { enabled, key }  (le site ne propose rien si enabled=false)
 *  POST   /api/frontend/push/subscription   client  → enregistre / met à jour l'appareil (point d'accès unique)
 *  DELETE /api/frontend/push/subscription   client  → retire l'appareil (déconnexion, « couper »)
 */
class WebPushController extends Controller
{
    public function key(): JsonResponse
    {
        $actif = WebPushSender::actif();

        return response()->json(['data' => [
            'enabled' => $actif,
            'key' => $actif ? (string) config('webpush.public_key') : null,
        ]]);
    }

    public function subscribe(WebPushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validated();
        // Adresse NORMALISÉE (hôte en minuscules, port par défaut retiré) avant hash et stockage : `HTTPS://FCM…` et `https://fcm…` sont le MÊME appareil.
        $v['endpoint'] = (string) \App\Services\Push\PushEndpoint::normaliser($v['endpoint']);
        $hash = WebPushSubscription::hashEndpoint($v['endpoint']);
        $max = max(1, (int) config('webpush.max_devices_per_user', 5));

        DB::transaction(function () use ($user, $v, $hash, $max): void {
            // Même point d'accès = même appareil : mis à jour, rattaché au client connecté (un téléphone prêté puis
            // reconnecté sous un autre compte ne reçoit plus les notifications de l'ancien).
            $abonnement = WebPushSubscription::query()->updateOrCreate(
                ['endpoint_hash' => $hash],
                [
                    'user_id' => (int) $user->id,
                    'endpoint' => $v['endpoint'],
                    'p256dh' => $v['keys']['p256dh'],
                    'auth' => $v['keys']['auth'],
                    'content_encoding' => $v['encoding'] ?? 'aes128gcm',
                    'device_label' => isset($v['device_label']) ? mb_substr(trim((string) $v['device_label']), 0, 60) : null,
                    'origin' => $v['origin'] ?? null,
                    'failure_count' => 0,
                ]
            );
            // Un réabonnement à l'identique prouve que l'appareil est VIVANT : `updated_at` avance (le ménage se fonde dessus).
            $abonnement->touch();
            // Plafond EXACT : les AUTRES appareils (le nouveau est exclu avant le tri), le moins récemment réussi part d'abord
            // (jamais réussi = le plus ancien d'abord). Avant : le nouvel appareil, trié le premier, se protégeait lui-même → 6 appareils.
            $autres = WebPushSubscription::query()->where('user_id', (int) $user->id)->where('endpoint_hash', '!=', $hash)
                ->orderByRaw('last_success_at IS NULL DESC')->orderBy('last_success_at')->orderBy('id')->get();
            foreach ($autres->take(max(0, $autres->count() - ($max - 1))) as $ancien) {
                $ancien->delete();
            }
        });

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2048']]);
        WebPushSubscription::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('endpoint_hash', WebPushSubscription::hashEndpoint(\App\Services\Push\PushEndpoint::normaliser((string) $request->input('endpoint')) ?? (string) $request->input('endpoint')))
            ->delete();

        return response()->json(['data' => ['subscribed' => false]]);
    }
}
