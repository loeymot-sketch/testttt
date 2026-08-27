<?php

namespace App\Http\Controllers\Admin\Pilotage;

use App\Http\Controllers\Admin\AdminController;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * [PILOTAGE 2026-08-09] Les interrupteurs, depuis l'administration.
 *
 * Avant, basculer le paiement fractionné ou la roue demandait un déploiement —
 * alors que ce sont exactement les leviers qu'on veut actionner en quelques
 * minutes, un soir où quelque chose se passe mal.
 *
 * La liste des bascules autorisées est une LISTE BLANCHE tenue dans
 * InterrupteurService::CATALOGUE. `idempotency.enabled` n'y figure pas
 * volontairement : c'est une protection NF525 sous garde de démarrage, pas une
 * option (CLAUDE.md §8).
 */
class InterrupteurController extends AdminController
{
    public function __construct(private readonly InterrupteurService $service)
    {
        parent::__construct();
    }

    /**
     * [ONB-05 T-1.2.1 2026-08-27] Lecture gardée, comme l'écriture l'était déjà.
     *
     * `update()` exige Admin depuis l'origine ; `index()` n'exigeait RIEN au-delà de
     * l'authentification. N'importe quel compte — caissier, cuisinier, livreur —
     * pouvait donc lire l'état de pilotage de l'établissement : paiement fractionné,
     * roue, remise manuelle, fidélité, promo borne, impression automatique.
     *
     * Ce n'est pas une fuite de données clients, et je ne la présente pas comme
     * telle : c'est la configuration opérationnelle du restaurant, et elle renseigne
     * sur ce qui est activé — donc sur ce qui est contournable. Le principe suffit :
     * une écriture réservée à l'Admin ne devrait pas avoir une lecture ouverte à tous.
     *
     * Le seul appelant est l'écran d'observabilité de l'administration
     * (SystemHealthComponent) : la borne, elle, lit sa configuration injectée au
     * rendu, jamais cette route. Vérifié avant de poser la garde — la verrouiller
     * sans le vérifier aurait pu couper une surface de vente.
     */
    public function index(): JsonResponse
    {
        $u = request()->user();
        abort_if(
            ! $u || ! ($u->can('settings') || $u->hasRole('Admin') || $u->hasRole('Tenant Admin')),
            403
        );

        return response()->json(['data' => $this->service->etat()]);
    }

    public function update(Request $request, string $nom): JsonResponse
    {
        $u = $request->user();
        abort_if(! $u || (! $u->hasRole('Admin') && ! $u->hasRole('Tenant Admin')), 403);

        $valide = $request->validate(['actif' => ['required', 'boolean']]);

        try {
            $avant = $this->service->valeur($nom);
            $etat = $this->service->regler($nom, (bool) $valide['actif']);

            // Une bascule qui change le comportement de la caisse doit laisser
            // une trace : sans elle, personne ne saura POURQUOI le paiement
            // fractionné a cessé de fonctionner un mardi soir.
            Log::info('[pilotage] interrupteur bascule', [
                'interrupteur' => $nom,
                'avant'        => $avant,
                'apres'        => (bool) $valide['actif'],
                'par'          => $u->email,
                'user_id'      => $u->id,
            ]);

            return response()->json(['data' => $etat]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
