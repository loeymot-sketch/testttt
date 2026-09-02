<?php

namespace App\Http\Controllers\Admin\Pilotage;

use App\Http\Controllers\Admin\AdminController;
use App\Services\Fiscal\AuditLogService;
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
    public function __construct(
        private readonly InterrupteurService $service,
        private readonly AuditLogService $auditLog,
    ) {
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
            $apres = (bool) $valide['actif'];

            // [2026-09-02 · Sub 4.3 · Codex P2-B] La trace est écrite AVANT la bascule, et
            // dans le journal d'audit chaîné — pas dans un fichier texte.
            //
            // Le soir où le paiement fractionné cesse de fonctionner, la question n'est
            // pas « est-ce que ça marche », c'est « QUI l'a coupé, QUAND, depuis quel
            // état ». Un `Log::info` ne répond pas à ça de façon opposable : le fichier
            // est rotaté, tronquable, et purgeable par la personne même qui a basculé.
            // `audit_logs` est signé en chaîne et sa suppression est refusée par un
            // déclencheur SQL.
            //
            // L'ordre compte : si la trace ne peut pas être écrite, la bascule N'A PAS
            // LIEU. Une caisse dont le comportement change sans que le journal le sache
            // est exactement ce qu'on cherche à rendre impossible.
            $this->auditLog->write([
                'branch_id'   => (int) ($u->branch_id ?? 0),
                'user_id'     => (int) $u->id,
                'action'      => 'pilotage.interrupteur.bascule',
                'resource'    => 'interrupteur',
                'resource_id' => null,
                'payload'     => [
                    'interrupteur'   => $nom,
                    'avant'          => $avant,
                    'apres'          => $apres,
                    'par'            => $u->email,
                    'correlation_id' => $request->header('X-Correlation-Id'),
                    'ip'             => $request->ip(),
                ],
            ]);

            $etat = $this->service->regler($nom, $apres);

            // Le journal applicatif reste, pour le confort du diagnostic — mais il n'est
            // plus la seule trace.
            Log::info('[pilotage] interrupteur bascule', [
                'interrupteur' => $nom,
                'avant'        => $avant,
                'apres'        => $apres,
                'par'          => $u->email,
                'user_id'      => $u->id,
            ]);

            return response()->json(['data' => $etat]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
