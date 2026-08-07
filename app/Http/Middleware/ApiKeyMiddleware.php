<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiKeyMiddleware
{
    /**
     * Handle an incoming request.
     *
     * [ROTATION 2026-08-08] Support d'une clé PRÉCÉDENTE, pour tourner la clé sans coupure.
     *
     * Pourquoi c'est nécessaire ici : la même clé vit à TROIS endroits qui ne peuvent pas
     * être mis à jour au même instant —
     *   1. `.env` du VPS (ce que le serveur compare) ;
     *   2. `public/js/app.js` et `public/js/pos-app.js`, où Laravel Mix la COMPILE
     *      (préfixe `MIX_`) → borne + caisse ; il faut recompiler les assets ;
     *   3. le meta `api-key` d'`index.html` du site, déployé sur Vercel.
     * Avec une seule clé acceptée, toute rotation ouvre une fenêtre où l'une des trois
     * surfaces reçoit `400 invalid_api_key` — soit une panne de prise de commande.
     *
     * Le temps de la rotation, on renseigne `API_KEY_PREVIOUS` avec l'ancienne valeur : les
     * deux clés sont acceptées. Une fois les trois surfaces à jour et vérifiées, on VIDE
     * `API_KEY_PREVIOUS` et l'ancienne clé cesse d'être valable.
     *
     * ⚠ Cette clé n'est PAS un secret et ne l'a jamais été : elle est publiée dans un meta
     * HTML et dans des bundles JS publics. Elle ne protège donc de rien face à quelqu'un qui
     * ouvre le site. Ce qui protège réellement ces routes, ce sont les limiteurs de débit
     * (`throttle:*`) et les jetons Sanctum. La rotation sert uniquement à ne plus porter la
     * valeur d'exemple du dépôt, connue de quiconque a lu le code.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $request->hasHeader('x-api-key')) {
            return response()->json(trans('all.message.invalid_api_key'), 400);
        }

        $fournie = (string) $request->header('x-api-key');

        // [SEC-FIX] Use config() not env() — env() returns null after php artisan config:cache
        $acceptees = [
            (string) config('app.api_key'),
            (string) config('app.api_key_previous', ''),
        ];

        foreach ($acceptees as $attendue) {
            // Une clé attendue VIDE ne doit jamais valider : sans ce garde, un déploiement
            // sans `API_KEY` laisserait passer toute requête envoyant un en-tête vide.
            if ($attendue === '') {
                continue;
            }

            // `hash_equals` : comparaison à temps constant. Le gain est théorique ici (la clé
            // est publique) mais c'est la forme juste pour comparer un secret présumé, et
            // elle ne coûte rien.
            if (hash_equals($attendue, $fournie)) {
                return $next($request);
            }
        }

        return response()->json(trans('all.message.invalid_api_key'), 400);
    }
}
