<?php

namespace App\Http\Controllers\Admin\Pilotage;

use App\Http\Controllers\Admin\AdminController;
use App\Services\Fiscal\AuditLogService;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        // [fusion 2026-09-02] Les DEUX lignes ont corrigé le même vrai défaut — « GET listait
        // les bascules à tout rôle dashboard (caissier) ; seul PUT était Admin » — mais
        // différemment. La garde globale `role:Admin|Tenant Admin` posée ici fermait aussi la
        // porte à un gérant porteur du droit `settings`, alors que c'est précisément le compte
        // qui doit lire le plan de panne : InterrupteurLectureGardeeTest l'exige. On garde donc
        // le contrôle FIN par méthode (voir index() et update() ci-dessous) : lecture ouverte à
        // `settings` OU Admin, écriture réservée à Admin. Le caissier reste dehors dans les deux
        // cas — c'est ce que le défaut d'origine demandait de corriger.
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

        // [G3/T3.2 2026-09-03] Le refus d'un nom hors liste blanche est tranché ICI, avant
        // toute écriture : ni réglage, ni trace. Auparavant l'audit était déjà écrit quand
        // `regler()` levait son `InvalidArgumentException`, et la ligne — indélébile —
        // affirmait la bascule d'un interrupteur qui n'existe pas.
        //
        // Ce contrôle anticipé sert aussi à ne pas confondre deux choses très différentes
        // sous la même exception : un nom refusé (404) et une panne d'écriture (500).
        // `InterrupteurService::regler()` reste l'autorité — il relève la même liste
        // blanche et refusera de son côté.
        if (! isset(InterrupteurService::CATALOGUE[$nom])) {
            return response()->json(['message' => "Interrupteur inconnu : {$nom}"], 404);
        }

        $avant = $this->service->valeur($nom);
        $demande = (bool) $valide['actif'];
        $applique = null;

        // [2026-09-02 · Sub 4.3 · Codex P2-B] La trace va dans le journal d'audit chaîné,
        // pas dans un fichier texte.
        //
        // Le soir où le paiement fractionné cesse de fonctionner, la question n'est pas
        // « est-ce que ça marche », c'est « QUI l'a coupé, QUAND, depuis quel état ». Un
        // `Log::info` ne répond pas à ça de façon opposable : le fichier est rotaté,
        // tronquable, et purgeable par la personne même qui a basculé. `audit_logs` est
        // signé en chaîne et sa suppression est refusée par un déclencheur SQL.
        //
        // [G3/T3.2 2026-09-03 · défaut V-07] L'ORDRE ÉTAIT INVERSÉ.
        //
        // La ligne d'audit était écrite AVANT `regler()`. Or l'écriture en base de
        // `InterrupteurService::regler()` peut échouer — verrou, délai dépassé,
        // `QueryException` — et le seul `catch` du contrôleur n'attrapait que
        // `InvalidArgumentException` : la panne remontait en 500 et la ligne restait.
        // Cette ligne affirmait alors une bascule qui n'avait pas eu lieu ; comme
        // `audit_logs` est append-only et chaîné, elle ne pouvait plus jamais être
        // retirée, et restait six ans. Une preuve fausse et irrétractable.
        //
        // Désormais : le FAIT d'abord, la PREUVE ensuite. Un journal d'audit n'a de
        // valeur que s'il ne peut pas devancer l'événement qu'il atteste.
        //
        // La transaction tient les deux invariants ensemble, sans quoi inverser l'ordre
        // en briserait un pour réparer l'autre :
        //   · pas de trace sans bascule (le défaut corrigé ici) ;
        //   · pas de bascule sans trace (déjà exigé par
        //     InterrupteurBasculeEstAuditeeTest — une caisse dont le comportement change
        //     sans que le journal le sache est exactement ce qu'on veut rendre
        //     impossible).
        // Si l'audit échoue, la transaction est annulée : le réglage n'est pas retenu.
        // Rien n'est réécrit ni corrigé a posteriori dans `audit_logs` — la mécanique de
        // chaînage HMAC (AuditLogService, zone gelée CLAUDE.md §7/§8) n'est pas touchée :
        // seul l'ordre des appels du côté appelant change.
        try {
            $etat = DB::transaction(function () use ($nom, $demande, $avant, $u, $request, &$applique) {
                $etat = $this->service->regler($nom, $demande);

                // La valeur RELUE, pas celle demandée : c'est la seule qui atteste de
                // l'état réel du système. Consigner l'intention plutôt que le fait
                // ramènerait le défaut par une autre porte.
                $applique = $this->service->valeur($nom);

                $this->auditLog->write([
                    'branch_id'   => (int) ($u->branch_id ?? 0),
                    'user_id'     => (int) $u->id,
                    'action'      => 'pilotage.interrupteur.bascule',
                    'resource'    => 'interrupteur',
                    'resource_id' => null,
                    'payload'     => [
                        'interrupteur'   => $nom,
                        'avant'          => $avant,
                        'apres'          => $applique,
                        'par'            => $u->email,
                        'correlation_id' => $request->header('X-Correlation-Id'),
                        'ip'             => $request->ip(),
                    ],
                ]);

                return $etat;
            });
        } catch (\Throwable $e) {
            // [G3/T3.3] Le `catch` couvre maintenant ce qui arrive vraiment : une
            // `QueryException` (base indisponible, contrainte), un verrou de chaîne non
            // obtenu (`RuntimeException` d'AuditLogService), un délai dépassé. Tous
            // produisent une erreur explicite et AUCUNE trace affirmant la bascule.
            //
            // La transaction a rendu la base à son état d'avant. Reste la copie EN
            // MÉMOIRE que `regler()` pose via `Config::set()` pour la requête en cours :
            // on la remet, sinon la fin de cette requête continuerait de croire à une
            // bascule qui n'a pas été retenue.
            $cle = InterrupteurService::CATALOGUE[$nom]['cle'] ?? null;
            if ($cle !== null) {
                Config::set($cle, $avant);
            }

            Log::error('[pilotage] bascule interrupteur refusée — annulée, sans trace d’audit', [
                'interrupteur'    => $nom,
                'avant'           => $avant,
                'demande'         => $demande,
                'par'             => $u->email,
                'user_id'         => $u->id,
                'exception_class' => get_class($e),
                'exception'       => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "La bascule « {$nom} » n'a pas pu être appliquée : rien n'a été modifié "
                    ."et aucune trace n'a été enregistrée. Réessayez ; si l'erreur persiste, "
                    .'la base ou le journal d’audit est indisponible.',
            ], 500);
        }

        // Le journal applicatif reste, pour le confort du diagnostic — mais il n'est
        // plus la seule trace.
        Log::info('[pilotage] interrupteur bascule', [
            'interrupteur' => $nom,
            'avant'        => $avant,
            'apres'        => $applique,
            'par'          => $u->email,
            'user_id'      => $u->id,
        ]);

        return response()->json(['data' => $etat]);
    }
}
