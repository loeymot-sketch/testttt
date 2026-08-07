<?php

namespace App\Http\Middleware;

use App\Models\FrontendOrder;
use App\Models\Scopes\BranchScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Donne au garde d'idempotence une branche DÉRIVÉE DU SERVEUR, prise sur la commande
 * portée par la route.
 *
 * [P0 PAIEMENT EN LIGNE 2026-08-07] Pourquoi ce middleware existe.
 *
 * `IdempotencyKeyMiddleware` (zone gelée, CLAUDE.md §7) résout la branche dans cet ordre :
 * `users.branch_id` > 0, puis Admin + payload, puis la borne rattachée
 * (`kiosk_machines.user_id`), et **en dernier recours `input('branch_id', -1)`**. Un compte
 * de rôle « Customer » porte `branch_id = 0` et n'a aucune borne : la résolution retombait
 * donc sur le corps de la requête. Le site l'envoie par convention (posée le 08/07 pour
 * placeOrder / checkCoupon / loyaltyRedeem) — mais l'appel de paiement Mollie l'avait
 * oublié pendant un mois, et **le paiement en ligne était mort pour 21 comptes sur 24**
 * (422 « Idempotency requires authenticated user with resolvable branch_id », jamais
 * transmis à Mollie). Corriger le client refermait CE trou ; ce middleware referme LA
 * CLASSE de trou : le prochain appel qui oubliera la convention n'échouera plus.
 *
 * Deux propriétés, l'une de robustesse et l'autre de sécurité :
 *
 *   1. ROBUSTESSE — la branche vient de la commande visée par la route, c'est-à-dire de la
 *      base, jamais d'une convention que l'appelant doit se souvenir d'honorer.
 *   2. SÉCURITÉ — la valeur du serveur ÉCRASE celle du corps. Sans cela, un client pourrait
 *      faire varier `branch_id` d'un envoi à l'autre pour changer la portée de sa propre clé
 *      d'idempotence et contourner la garde anti-double-débit. La vérité du serveur prime.
 *
 * Ce qu'il ne fait PAS, délibérément :
 *   - Il ne modifie pas le fichier gelé : il alimente son point d'extension documenté
 *     (`input('branch_id')`).
 *   - Il ne touche pas au corps BRUT. `IdempotencyKeyMiddleware` calcule son empreinte de
 *     charge utile sur `$request->getContent()`, que `merge()` ne modifie pas : la détection
 *     de conflit 409 « même clé, corps différent » reste rigoureusement inchangée.
 *   - Il ne lève JAMAIS. Commande introuvable ou branche absente ⇒ requête laissée telle
 *     quelle, et le garde gelé décide comme avant (fail-closed préservé). Un middleware
 *     d'infrastructure ne doit pas devenir une nouvelle cause de panne sur le chemin
 *     du paiement.
 *
 * À poser AVANT `idempotency` sur toute route portant une commande. Sentinelle :
 * tests/Feature/Payment/IdempotencyBranchFromRouteTest.php
 */
class ResolveIdempotencyBranchFromRoute
{
    /** Noms de paramètres de route susceptibles de porter une commande. */
    private const ORDER_PARAMETERS = ['frontendOrder', 'order'];

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $branchId = $this->branchFromRoute($request);

        if ($branchId !== null) {
            $request->merge(['branch_id' => $branchId]);
        }

        return $next($request);
    }

    private function branchFromRoute(Request $request): ?int
    {
        $route = $request->route();

        if (! $route || ! method_exists($route, 'parameter')) {
            return null;
        }

        foreach (self::ORDER_PARAMETERS as $name) {
            if (! $route->hasParameter($name)) {
                continue;
            }

            $value = $route->parameter($name);

            // Cas normal : la liaison de modèle a déjà eu lieu.
            if ($value instanceof Model) {
                $branchId = (int) ($value->getAttribute('branch_id') ?? 0);

                return $branchId > 0 ? $branchId : null;
            }

            // Liaison pas encore résolue. L'ordre des middlewares est réglé par
            // `$middlewarePriority` (Kernel) et ce middleware n'y figure pas : ne PAS en
            // faire une hypothèse silencieuse — on résout nous-mêmes.
            $id = (int) $value;
            if ($id <= 0) {
                continue;
            }

            try {
                // Sans le scope de branche : c'est précisément la branche qu'on cherche,
                // et le scope la présupposerait déjà connue.
                $branchId = (int) (FrontendOrder::withoutGlobalScope(BranchScope::class)
                    ->whereKey($id)
                    ->value('branch_id') ?? 0);

                return $branchId > 0 ? $branchId : null;
            } catch (\Throwable) {
                // Base indisponible, table absente en test unitaire, etc. : on n'aggrave rien.
                return null;
            }
        }

        return null;
    }
}
