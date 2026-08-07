<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * [MULTI-DEVICE 2026-08-07] Écran « Appareils connectés ».
 *
 * Contrepartie indispensable du multi-terminaux : dès lors que plusieurs
 * postes peuvent rester connectés simultanément sur un compte, il faut
 * pouvoir VOIR ces sessions et en COUPER une à distance (tablette perdue,
 * poste laissé connecté chez un ancien employé).
 *
 * Périmètre volontairement restreint à ses PROPRES sessions : l'endpoint
 * vit sous `auth:sanctum` sans permission particulière, exactement comme
 * `/logout`, parce que reprendre la main sur son propre compte est un
 * contrôle de sécurité qui ne doit dépendre d'aucun droit annexe. La
 * révocation d'un jeton appartenant à un AUTRE compte renvoie 404 (pas 403 :
 * on ne confirme pas l'existence d'un identifiant qui ne vous regarde pas).
 */
class DeviceSessionController extends Controller
{
    /**
     * Le jeton porteur de la requête courante, ou null.
     *
     * Sanctum renvoie un `TransientToken` (sans `id`) quand l'utilisateur est
     * résolu par la session web plutôt que par un Bearer — cas d'un domaine
     * « stateful ». Sans ce filtre, marquer « session courante » explose en
     * accès à une propriété inexistante.
     */
    private function currentPersonalAccessToken($user): ?PersonalAccessToken
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /**
     * Sessions actives du compte courant.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $current = $this->currentPersonalAccessToken($user);

        // Un jeton expiré n'est PAS une session connectée : Sanctum le refuse
        // déjà (RefreshTokenController garde aussi contre sa résurrection).
        // L'afficher ferait croire à l'exploitant qu'un poste est encore
        // ouvert, et lui ferait chercher à couper un accès qui n'existe plus —
        // exactement le contraire de ce que cet écran doit lui apprendre.
        $devices = $user->tokens()
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($token) use ($current) {
                return [
                    'id'           => (int) $token->id,
                    'device_id'    => $token->device_id,
                    'device_label' => $token->device_label ?: 'Appareil inconnu',
                    'kind'         => $token->name,
                    'last_ip'      => $token->last_ip,
                    'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                    'created_at'   => optional($token->created_at)->toIso8601String(),
                    'expires_at'   => optional($token->expires_at)->toIso8601String(),
                    'is_current'   => $current && (int) $current->id === (int) $token->id,
                ];
            })
            ->values();

        return new JsonResponse([
            'devices'     => $devices,
            'max_devices' => (int) config('auth.max_devices_per_user', 10),
        ], 200);
    }

    /**
     * Renomme un appareil.
     *
     * Sans ça l'écran est inexploitable dès qu'on a plusieurs postes admin :
     * ils s'affichent tous sous le même libellé automatique, et l'exploitant
     * ne peut pas décider lequel couper.
     */
    public function update(Request $request, int $token): JsonResponse
    {
        $request->validate([
            'device_label' => ['required', 'string', 'max:120'],
        ]);

        $user   = $request->user();
        $target = $user->tokens()->whereKey($token)->first();

        if (! $target) {
            return new JsonResponse([
                'message' => trans('all.message.device_not_found'),
            ], 404);
        }

        // Le libellé s'affiche dans une liste d'administration : on le borne
        // au même jeu de caractères que le service d'émission.
        $label = preg_replace('/[^\p{L}\p{N} \-_.()\/]/u', '', (string) $request->input('device_label')) ?? '';
        $label = trim(mb_substr($label, 0, 120));

        if ($label === '') {
            return new JsonResponse([
                'errors' => ['device_label' => [trans('validation.required', ['attribute' => 'device_label'])]],
            ], 422);
        }

        $target->forceFill(['device_label' => $label])->save();

        return new JsonResponse([
            'message'      => trans('all.message.device_renamed'),
            'device_label' => $label,
        ], 200);
    }

    /**
     * Déconnecte un appareil à distance.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $user = $request->user();

        // `$user->tokens()` est déjà scopé au propriétaire : un identifiant
        // appartenant à quelqu'un d'autre ne sera simplement pas trouvé.
        $target = $user->tokens()->whereKey($token)->first();

        if (! $target) {
            return new JsonResponse([
                'message' => trans('all.message.device_not_found'),
            ], 404);
        }

        $current   = $this->currentPersonalAccessToken($user);
        $isCurrent = $current && (int) $current->id === (int) $target->id;

        $snapshot = [
            'token_id'     => (int) $target->id,
            'device_id'    => $target->device_id,
            'device_label' => $target->device_label,
            'kind'         => $target->name,
        ];

        $target->delete();

        // Révoquer une session est un événement de sécurité : il doit laisser
        // une trace dans la chaîne d'audit (NF525, conservation 6 ans). Comme
        // pour `user.login`, un incident d'écriture ne doit jamais empêcher la
        // révocation elle-même — couper un accès prime sur le journaliser.
        try {
            app(AuditLogService::class)->write([
                'branch_id'   => (int) ($user->branch_id ?? 0),
                'user_id'     => (int) $user->id,
                'action'      => 'user.device_revoked',
                'resource'    => 'personal_access_token',
                'resource_id' => (int) $snapshot['token_id'],
                'payload'     => $snapshot + [
                    'revoked_by_user_id' => (int) $user->id,
                    'self_revocation'    => $isCurrent,
                    'ip'                 => (string) ($request->ip() ?? ''),
                ],
                'ip'         => (string) ($request->ip() ?? null),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MULTI-DEVICE] écriture audit user.device_revoked échouée', [
                'user_id' => (int) $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        return new JsonResponse([
            'message'    => trans('all.message.device_revoked'),
            'is_current' => $isCurrent,
        ], 200);
    }
}
