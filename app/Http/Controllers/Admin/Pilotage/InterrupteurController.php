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
        // Avant : GET listait les bascules à tout rôle dashboard (caissier).
        // Seul PUT était Admin. Lecture = plan de panne.
        $this->middleware(['role:Admin|Tenant Admin']);
    }

    public function index(): JsonResponse
    {
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
