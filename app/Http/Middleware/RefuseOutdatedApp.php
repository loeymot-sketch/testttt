<?php

namespace App\Http\Middleware;

use App\Support\AppVersion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [STORES T-3.3.2 · 2026-10-01] Une application trop ancienne ne passe pas de commande.
 *
 * L'application affiche déjà « Mise à jour nécessaire » quand le serveur publie une version
 * minimale plus récente que la sienne. Mais un écran ne suffit pas : la configuration a pu
 * ne pas se charger (réseau capricieux au lancement), et c'est précisément le vieux paquet
 * — celui qu'on ne peut plus corriger — qui doit être arrêté. Le refus vit donc ici aussi.
 *
 * Seules les requêtes qui DÉCLARENT une version (en-tête `X-LC-App-Version`, posé par
 * l'application) sont jugées. Le site web et la borne n'en envoient pas : jamais concernés.
 * Une version illisible ne bloque personne (AppVersion::estAvant).
 *
 * 422 + code, comme PHONE_REQUIRED : un client qui ne connaît pas le code affiche quand même
 * le message en clair.
 */
class RefuseOutdatedApp
{
    public const ENTETE = 'X-LC-App-Version';

    public function handle(Request $request, Closure $next): Response
    {
        $version = $request->header(self::ENTETE);
        if ($version === null || ! AppVersion::estAvant($version, AppVersion::minimum())) {
            return $next($request);
        }

        return response()->json(array_merge([
            'status' => false,
            'code'   => 'APP_UPDATE_REQUIRED',
        ], AppVersion::configuration()), 422);
    }
}
