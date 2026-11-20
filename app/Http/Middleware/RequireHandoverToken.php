<?php

namespace App\Http\Middleware;

use App\Services\Auth\HandoverTokenPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [LIVRAISON QR 2026-11-01] Les routes de remise par QR n'acceptent QUE le jeton court « handover_token » émis par la connexion
 * dédiée. Un jeton de session ordinaire (`auth_token`, y compris celui de /livreur resté dans le navigateur) est refusé : la page
 * de remise redemande TOUJOURS l'e-mail et le mot de passe.
 */
class RequireHandoverToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token || ! method_exists($token, 'can')
            || $token->name !== HandoverTokenPolicy::TOKEN_NAME || ! $token->can(HandoverTokenPolicy::ABILITY)) {
            return response()->json([
                'status'  => false,
                'code'    => 'HANDOVER_LOGIN_REQUIRED',
                'message' => 'Connectez-vous avec votre compte livreur pour valider cette remise.',
            ], 403);
        }

        return $next($request);
    }
}
