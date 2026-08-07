<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;

/**
 * [MULTI-DEVICE 2026-08-07] Émission de jetons Sanctum SCOPÉE À L'APPAREIL.
 *
 * Problème corrigé (constaté en exploitation par le propriétaire) :
 * `LoginController` faisait `$user->tokens()->where('name','auth_token')->delete()`
 * à chaque connexion. Avec un compte utilisé sur plusieurs terminaux (caisse
 * + tablettes de salle + téléphone), toute nouvelle connexion invalidait les
 * jetons de tous les autres postes, qui partaient en 401 au premier appel API
 * (déconnexion sèche côté caisse, « impossible de procéder » côté admin).
 *
 * L'intention d'origine — ne pas laisser des jetons zombies valides 8h
 * (CLAUDE.md §9, Sprint 5D Z6-01) — reste appliquée, mais par appareil :
 *
 *   1. reconnexion d'un appareil  → on révoque UNIQUEMENT son jeton précédent
 *   2. plafond de terminaux       → au-delà, on évince le moins récemment actif
 *   3. identité tracée            → device_id/device_label/last_ip sur le jeton
 *
 * Le point 3 sert deux besoins distincts : l'écran « Appareils connectés »
 * (révocation à distance d'une tablette perdue) et la traçabilité NF525 —
 * quand plusieurs postes partagent un compte, l'audit doit pouvoir dire
 * depuis quel terminal l'action a été faite.
 */
class DeviceTokenService
{
    /**
     * Plafond de repli si `config('auth.max_devices_per_user')` est absent.
     */
    public const DEFAULT_MAX_DEVICES = 10;

    /**
     * Identifiant d'appareil transmis par le client (localStorage), ou repli
     * déterministe pour les clients anciens qui n'envoient pas l'en-tête.
     *
     * Le repli hache user-agent + IP. Deux terminaux STRICTEMENT identiques
     * derrière la même IP publique retomberaient sur le même identifiant et
     * continueraient donc à s'évincer — c'est exactement le comportement
     * actuel, jamais pire, et nos propres surfaces envoient toutes l'en-tête
     * (`resources/js/shared/axios-setup.js`). Le préfixe `legacy-` rend ces
     * lignes repérables dans l'écran « Appareils connectés ».
     */
    public function resolveDeviceId(Request $request): string
    {
        $raw = (string) ($request->header('X-Device-Id') ?? '');
        $raw = trim($raw);

        // Liste blanche stricte : l'identifiant finit en base et s'affiche
        // dans une liste d'administration — aucune raison d'accepter autre
        // chose que de l'alphanumérique et quelques séparateurs.
        if ($raw !== '' && preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $raw) === 1) {
            return $raw;
        }

        if ($raw !== '') {
            Log::info('[MULTI-DEVICE] En-tête X-Device-Id rejeté (format), repli hérité appliqué', [
                'length' => strlen($raw),
            ]);
        }

        return 'legacy-' . substr(
            hash('sha256', ((string) $request->userAgent()) . '|' . ((string) $request->ip())),
            0,
            32
        );
    }

    /**
     * Libellé lisible de l'appareil. Jamais de confiance aveugle : la valeur
     * est affichée dans l'admin, donc bornée et nettoyée.
     */
    public function resolveDeviceLabel(Request $request): string
    {
        $label = trim((string) ($request->header('X-Device-Label') ?? ''));

        // Le client encode le libellé en pourcent : un en-tête HTTP ne
        // transporte que du latin-1, et un nom accentué ferait échouer la
        // requête côté navigateur avant même l'envoi. `rawurldecode` est sans
        // effet sur une valeur déjà en clair, donc un client ancien qui
        // enverrait le libellé brut reste géré.
        $label = rawurldecode($label);

        if (! mb_check_encoding($label, 'UTF-8')) {
            $label = '';
        }

        $label = preg_replace('/[^\p{L}\p{N} \-_.()\/]/u', '', $label) ?? '';
        $label = trim(mb_substr($label, 0, 120));

        if ($label !== '') {
            return $label;
        }

        return $this->labelFromUserAgent((string) $request->userAgent());
    }

    /**
     * Émet un jeton pour cet appareil, en révoquant seulement ce qu'il faut.
     *
     * @param  array<int,string>  $abilities
     */
    public function issueForDevice(
        User $user,
        string $name,
        array $abilities,
        Request $request,
        ?int $ttlMinutes = null,
        ?string $deviceIdOverride = null,
        ?string $deviceLabelOverride = null
    ): NewAccessToken {
        $deviceId = $deviceIdOverride ?? $this->resolveDeviceId($request);
        $label    = $deviceLabelOverride !== null
            ? trim(mb_substr($deviceLabelOverride, 0, 120))
            : $this->resolveDeviceLabel($request);

        // 1. Reconnexion du MÊME appareil : son jeton précédent tombe.
        //    (anti-prolifération d'origine, désormais chirurgicale)
        $user->tokens()
            ->where('name', $name)
            ->where('device_id', $deviceId)
            ->delete();

        // 2. Plafond de terminaux simultanés. On évince par activité la plus
        //    ancienne (last_used_at, à défaut created_at) : un poste en service
        //    est par définition récemment actif, il ne saute jamais au profit
        //    d'un poste oublié dans un tiroir.
        $this->enforceDeviceCap($user, $name);

        $expiresAt = $ttlMinutes === null
            ? null
            : now()->addMinutes($ttlMinutes);

        $newToken = $user->createToken($name, $abilities, $expiresAt);

        $newToken->accessToken->forceFill([
            'device_id'    => $deviceId,
            'device_label' => $label,
            'last_ip'      => substr((string) ($request->ip() ?? ''), 0, 45),
        ])->save();

        return $newToken;
    }

    /**
     * Supprime les jetons excédentaires pour laisser une place libre au jeton
     * sur le point d'être créé (d'où le `>=` : on vise plafond-1 avant l'ajout).
     */
    private function enforceDeviceCap(User $user, string $name): void
    {
        $max = (int) config('auth.max_devices_per_user', self::DEFAULT_MAX_DEVICES);
        if ($max <= 0) {
            return; // plafond désactivé explicitement
        }

        $tokens = $user->tokens()
            ->where('name', $name)
            ->get(['id', 'last_used_at', 'created_at', 'device_id', 'device_label']);

        $excess = $tokens->count() - ($max - 1);
        if ($excess <= 0) {
            return;
        }

        $doomed = $tokens
            ->sortBy(fn ($token) => optional($token->last_used_at ?? $token->created_at)->timestamp ?? 0)
            ->take($excess);

        $user->tokens()->whereIn('id', $doomed->pluck('id')->all())->delete();

        Log::info('[MULTI-DEVICE] Plafond d\'appareils atteint — sessions les plus anciennes révoquées', [
            'user_id'  => (int) $user->id,
            'name'     => $name,
            'max'      => $max,
            'revoked'  => $doomed->map(fn ($t) => [
                'device_id'    => $t->device_id,
                'device_label' => $t->device_label,
            ])->values()->all(),
        ]);
    }

    /**
     * Libellé de repli quand le client n'envoie pas `X-Device-Label`.
     * Volontairement grossier : il sert à reconnaître une ligne dans une
     * liste, pas à faire du fingerprinting.
     */
    private function labelFromUserAgent(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        $os = match (true) {
            str_contains($ua, 'android')                             => 'Android',
            str_contains($ua, 'iphone')                              => 'iPhone',
            str_contains($ua, 'ipad')                                => 'iPad',
            str_contains($ua, 'windows')                             => 'Windows',
            str_contains($ua, 'mac os') || str_contains($ua, 'macintosh') => 'Mac',
            str_contains($ua, 'linux')                               => 'Linux',
            default                                                  => 'Appareil',
        };

        $browser = match (true) {
            str_contains($ua, 'edg/')    => 'Edge',
            str_contains($ua, 'chrome')  => 'Chrome',
            str_contains($ua, 'firefox') => 'Firefox',
            str_contains($ua, 'safari')  => 'Safari',
            default                      => null,
        };

        return $browser ? "{$os} · {$browser}" : $os;
    }
}
