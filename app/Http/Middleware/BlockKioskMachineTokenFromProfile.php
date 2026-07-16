<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [TERRAIN-HEAL 2026-07-16 · KIOSK-PROFILE-ESCALATION — P1]
 *
 * TROU CONFIRMÉ (repro live) : le groupe /api/profile/* n'avait que `auth:sanctum` —
 * sans garde d'ability/identité. Un token de MACHINE borne (obtenu via POST
 * /api/auth/kiosk-login, éventuellement avec les creds semés kiosk123) est émis sur le
 * USER auquel la KioskMachine est rattachée (staff/admin dans la config seeder par défaut).
 * Ce token pouvait donc :
 *   - GET  /api/profile/               → fuite PII du user lié (email, téléphone…)
 *   - PUT  /api/profile/               → MODIFIER l'email/téléphone de ce user
 *   - PUT  /api/profile/change-password
 * → si la borne est liée à admin (défaut seeder) = TAKEOVER DE COMPTE ADMIN par reset mot de passe.
 *
 * POURQUOI PAS `block_kiosk_token_admin` : ce garde bloque tout token d'ability kiosk:order-only.
 * Or les tokens CLIENT web (GuestSignupController: name='auth_token', ['kiosk:order']) ont la MÊME
 * ability et DOIVENT pouvoir gérer LEUR propre profil. La distinction fiable = le NOM du token :
 *   - token MACHINE borne  : name = 'kiosk-token' (KioskMachineLoginController:105)
 *   - token CLIENT web/app : name = 'auth_token'  (GuestSignupController:156)
 *
 * Ce middleware refuse 403 UNIQUEMENT les tokens nommés 'kiosk-token' (identité machine) sur
 * les routes profil — la borne place des commandes (kiosk:order), elle n'a aucune raison de
 * lire/modifier le profil du user support. Les clients (auth_token) sont INCHANGÉS.
 * Session-based auth (pas de token Sanctum) : INCHANGÉ. Tokens wildcard '*' : INCHANGÉS.
 */
class BlockKioskMachineTokenFromProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Pas de token d'accès personnel (session web, ou pas authentifié) → laisser passer,
        // la pile auth:sanctum décide. On ne cible QUE les PersonalAccessToken nommés 'kiosk-token'.
        if ($token && method_exists($token, 'getAttribute') && $token->name === 'kiosk-token') {
            return response()->json([
                'status'  => false,
                'code'    => 'KIOSK_MACHINE_TOKEN_FORBIDDEN_ON_PROFILE',
                'message' => 'Un terminal borne ne peut pas accéder au profil utilisateur.',
            ], 403);
        }

        return $next($request);
    }
}
