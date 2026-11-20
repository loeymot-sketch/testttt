<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [W7-D D-006 2026-11-04] Cachabilité client du CATALOGUE (articles, catégories, détails) : l'application et le site
 * relisaient 47 Ko d'articles à chaque ouverture sans aucun en-tête de validation — seule la vitrine (`storefront`)
 * faisait ETag + 304. Même recette ici : l'ETag est l'empreinte du CORPS EXACT (il change dès qu'un prix, une rupture
 * ou une photo change), `must-revalidate` force le navigateur à revalider à chaque fois, et une réponse inchangée
 * coûte un 304 sans corps. `private` : la réponse peut dépendre de la langue et de la branche demandées — aucun cache
 * partagé ne doit la servir à un autre client.
 */
trait RepondAvecEtag
{
    /**
     * @param  mixed  $reponse  Resource, collection, JsonResponse… tout ce que `toResponse()` ou le routeur accepte.
     */
    protected function avecEtag(Request $request, mixed $reponse, string $prefixe): Response
    {
        $resp = $reponse instanceof Response ? $reponse : $reponse->toResponse($request);
        if ($resp->getStatusCode() !== 200) {
            return $resp;
        }

        $etag = '"'.$prefixe.'-'.substr(sha1((string) $resp->getContent()), 0, 20).'"';
        $entetes = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=0, must-revalidate'];

        if ($this->etagCorrespond((string) $request->header('If-None-Match', ''), $etag)) {
            return response('', 304, $entetes);
        }

        foreach ($entetes as $nom => $valeur) {
            $resp->headers->set($nom, $valeur);
        }

        return $resp;
    }

    /** Même tolérance que `StorefrontController::matches` : liste, `W/` faible, `*`. */
    private function etagCorrespond(string $header, string $etag): bool
    {
        if ($header === '') {
            return false;
        }
        foreach (explode(',', $header) as $candidat) {
            $candidat = trim($candidat);
            if (str_starts_with($candidat, 'W/')) {
                $candidat = substr($candidat, 2);
            }
            if ($candidat === $etag || $candidat === '*') {
                return true;
            }
        }

        return false;
    }
}
