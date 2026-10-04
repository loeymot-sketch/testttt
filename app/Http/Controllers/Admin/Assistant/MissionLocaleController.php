<?php

namespace App\Http\Controllers\Admin\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Assistant\MissionLocale\ExecuteurDeMission;
use App\Services\Assistant\MissionLocale\InterpreteDeMission;
use App\Services\Assistant\MissionLocale\PlanificateurDeMission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [ONB-04 2026-08-28] L'assistant de missions locales.
 *
 * Le mandat le demande en toutes lettres : « chatbot de missions locales sur le
 * profil », « ajoute une sauce à tous les tacos ». Il n'existait pas — `grep` sur
 * `chatbot|missions locales` rendait zéro fichier.
 *
 * ═══ DEUX TEMPS, JAMAIS UN ═══
 *
 * `lecture`     comprend la phrase et rend un PLAN. N'écrit RIEN.
 * `application` refait le plan et l'exécute, après confirmation explicite.
 *
 * Une mission touche cinquante produits d'un coup. Sans le plan affiché avant,
 * l'assistant est un accélérateur d'erreurs : « j'ai ajouté la sauce à vos 47
 * tacos » n'est pas rattrapable en un clic.
 *
 * ═══ LE PLAN N'EST JAMAIS REÇU DU CLIENT ═══
 *
 * `application` ne fait pas confiance à un diff envoyé par le navigateur : elle
 * ré-interprète la phrase et re-planifie avant d'écrire. Sinon un plan trafiqué en
 * route ferait écrire n'importe quoi sous couvert d'une confirmation humaine.
 *
 * Les deux exigent `items_edit` : une mission locale MODIFIE le catalogue, ce n'est
 * pas une consultation — et `lecture` prépare cette écriture.
 */
class MissionLocaleController extends Controller
{
    public function __construct(
        private readonly InterpreteDeMission $interprete,
        private readonly PlanificateurDeMission $planificateur,
        private readonly ExecuteurDeMission $executeur,
    ) {
        $this->middleware('permission:items_edit');
    }

    /** Comprend la phrase et rend le plan. N'écrit rien. */
    public function lire(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'phrase' => ['required', 'string', 'max:300'],
        ]);

        $lu = $this->interprete->comprendre($donnees['phrase']);

        if ($lu['mission'] === null) {
            // 200 et non 422 : ne pas comprendre une phrase n'est pas une erreur de
            // saisie, c'est une conversation. L'écran affiche la réponse dans le fil,
            // avec la liste de ce que l'assistant sait faire.
            return response()->json([
                'compris' => false,
                'reponse' => $lu['refus'],
                'formes'  => InterpreteDeMission::formesComprises(),
            ]);
        }

        return response()->json([
            'compris' => true,
            'plan'    => $this->planificateur->planifier($lu['mission']),
        ]);
    }

    /** Applique la mission, après confirmation explicite du commerçant. */
    public function appliquer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'phrase'       => ['required', 'string', 'max:300'],
            // Le commerçant a vu le plan et a cliqué. Sans ce drapeau, on refuse :
            // une écriture de masse ne doit jamais partir d'un seul appel.
            'confirmation' => ['required', 'accepted'],
        ]);

        $lu = $this->interprete->comprendre($donnees['phrase']);

        if ($lu['mission'] === null) {
            return response()->json([
                'compris' => false,
                'reponse' => $lu['refus'],
            ], 422);
        }

        return response()->json([
            'compris' => true,
            'rapport' => $this->executeur->executer($lu['mission']),
        ]);
    }
}
