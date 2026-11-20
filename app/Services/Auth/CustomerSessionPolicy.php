<?php

namespace App\Services\Auth;

use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * [SESSION DURABLE 2026-10-30 · T-S2.1.1] Session client « jamais déconnecté ».
 *
 * Défaut corrigé : un jeton client est émis pour 30 jours
 * (GuestSignupController / SocialAuthController -> issueForDevice(..., 60*24*30)),
 * mais `Laravel\Sanctum\Guard::isValidAccessToken()` exige AUSSI
 * `created_at > now() - config('sanctum.expiration')` (480 min). Le client était donc
 * déconnecté au bout de 8 h, quoi que dise `expires_at`. `vendor/` n'est pas modifié :
 * le Guard offre le crochet `Sanctum::$accessTokenAuthenticationCallback`, branché dans
 * `AppServiceProvider::boot()`.
 *
 * Pourquoi `expires_at` ne suffit pas à reconnaître un client : TOUS les jetons portent
 * une échéance (LoginController, KioskMachineLoginController, RefreshTokenController,
 * ForgotPasswordController : `now() + sanctum.expiration`). Les aptitudes non plus : le
 * client et la borne ont tous deux `['kiosk:order']`. Un jeton n'est donc rattrapé que si
 * TOUTES ces conditions sont réunies (sinon le résultat de Sanctum est rendu inchangé) :
 *
 *   1. Sanctum l'a refusé, et la SEULE cause est la limite globale `created_at`
 *      (le jeton n'est pas échu, le propriétaire est un `User`) ;
 *   2. nom `auth_token` — jamais `kiosk-token` (la borne) ;
 *   3. aptitudes EXACTEMENT `['kiosk:order']` — jamais `['*']` (caisse, admin) ;
 *   4. durée de vie voulue à l'émission (`expires_at - created_at`) d'au moins
 *      LIFETIME_MINUTES (30 j) : un jeton staff/borne est émis pour le plafond global
 *      (480 min) et n'atteint jamais cette durée ;
 *   5. propriétaire actif, non supprimé, dont l'UNIQUE rôle est Client — un client
 *      qui serait aussi du personnel garde les 8 h.
 *
 * Aucune de ces conditions n'autorise un jeton à dépasser SA PROPRE échéance, et la
 * révocation (suppression de la ligne), la désactivation du compte
 * (EnsureUserStatusActive) et la suppression de compte continuent de s'appliquer.
 */
final class CustomerSessionPolicy
{
    public const TOKEN_NAME = 'auth_token';

    /** @var array<int,string> */
    public const ABILITIES = ['kiosk:order'];

    /** Durée d'une session client : 30 jours. */
    public const LIFETIME_MINUTES = 60 * 24 * 30;

    /**
     * Callback de `Sanctum::authenticateAccessTokensUsing()`.
     *
     * @param  mixed  $accessToken
     */
    public static function authenticate($accessToken, bool $isValid): bool
    {
        if ($isValid) {
            return true;
        }

        return self::survivesGlobalLimit($accessToken);
    }

    /**
     * Vrai si le jeton est un jeton client longue durée, encore dans son échéance,
     * et que seul le plafond global de Sanctum l'a fait refuser.
     *
     * @param  mixed  $accessToken
     */
    public static function survivesGlobalLimit($accessToken): bool
    {
        if (! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        $limit = (int) config('sanctum.expiration');
        if ($limit <= 0) {
            return false; // pas de plafond global : le refus a une autre cause
        }

        $createdAt = $accessToken->created_at;
        if ($createdAt === null || $createdAt->gt(now()->subMinutes($limit))) {
            return false; // le plafond global n'est pas en cause
        }

        $expiresAt = $accessToken->expires_at;
        if ($expiresAt === null || $expiresAt->isPast()) {
            return false; // pas d'échéance explicite, ou échu : Sanctum a raison
        }

        return self::isCustomerToken($accessToken, $limit);
    }

    /**
     * Le jeton est-il un jeton de session client longue durée ? (nom, aptitudes,
     * durée voulue > plafond global, propriétaire client actif). Ne regarde PAS
     * l'échéance courante : sert aussi au renouvellement.
     *
     * @param  mixed  $accessToken
     */
    public static function isCustomerToken($accessToken, ?int $limit = null): bool
    {
        if (! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        $limit ??= (int) config('sanctum.expiration');
        if ($limit <= 0) {
            return false;
        }

        if ($accessToken->name !== self::TOKEN_NAME) {
            return false;
        }

        if (($accessToken->abilities ?? null) !== self::ABILITIES) {
            return false;
        }

        $createdAt = $accessToken->created_at;
        $expiresAt = $accessToken->expires_at;
        if ($createdAt === null || $expiresAt === null) {
            return false;
        }
        // Durée voulue à l'émission : celle d'une session client (30 j), pas celle d'un
        // jeton borne/staff (= plafond global). Comparé à la constante, pas au plafond
        // courant : un plafond rabaissé APRÈS coup ne doit pas promouvoir un jeton 480 min.
        if ($createdAt->diffInMinutes($expiresAt, false) < self::LIFETIME_MINUTES) {
            return false;
        }

        $user = $accessToken->tokenable;
        if (! $user instanceof User) {
            return false;
        }
        if ((int) $user->status !== Status::ACTIVE) {
            return false;
        }

        $roleIds = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $roleIds === [EnumRole::CUSTOMER];
    }
}
