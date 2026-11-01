<?php

namespace App\Services\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * [LIVRAISON QR 2026-11-01] Le jeton court « handover_token » (connexion dédiée de la remise par QR) n'est valable QUE sur
 * `api/frontend/delivery-handover/*`. Partout ailleurs — tournées, profil, caisse, admin — il est traité comme inexistant (401),
 * même s'il appartient à un vrai livreur : un QR scanné ne doit jamais ouvrir autre chose que SA remise.
 *
 * Sanctum n'offre qu'UN crochet (`authenticateAccessTokensUsing`), déjà occupé par {@see CustomerSessionPolicy} : on l'enveloppe.
 */
final class HandoverTokenPolicy
{
    public const TOKEN_NAME = 'handover_token';
    public const ABILITY = 'delivery:handover';
    public const ROUTE_PATTERN = 'api/frontend/delivery-handover/*';

    /** @param mixed $accessToken */
    public static function authenticate($accessToken, bool $isValid): bool
    {
        if ($accessToken instanceof PersonalAccessToken && $accessToken->name === self::TOKEN_NAME) {
            return $isValid && request()->is(self::ROUTE_PATTERN);
        }

        return CustomerSessionPolicy::authenticate($accessToken, $isValid);
    }
}
