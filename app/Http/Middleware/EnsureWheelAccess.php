<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * LA PORTE DES ÉCRANS DE LA ROUE — et le P0 qu'elle répare.
 *
 * ── LE DÉFAUT ────────────────────────────────────────────────────────────────────────────────
 * [P0 2026-08-10, audit E2E vagues A et C convergentes] Les quatre écrans de la roue
 * (`roue-validation`, `roue-lot`, `roue-reglages`, `roue-borne`) étaient gardés par `auth`. Or dans
 * cette application :
 *
 *   · `config/auth.php` met la garde par défaut à `sanctum` ;
 *   · `LoginController` détruit la session web (`Auth::guard('web')->logout()`) et rend un jeton
 *     Bearer que la SPA garde en mémoire ;
 *   · une navigation de DOCUMENT (taper une URL, ouvrir un onglet) ne porte JAMAIS d'en-tête
 *     `Authorization`.
 *
 * Conséquence : après une connexion parfaitement normale, ouvrir un écran de la roue rendait
 * `{"errors":"unauthenticated"}`. Aucun de ces écrans n'était donc utilisable par qui que ce soit —
 * y compris l'écran de réglages, construit précisément pour que le propriétaire débloque le jeu tout
 * seul. Il était derrière une porte que personne ne pouvait ouvrir.
 *
 * ── LA SOLUTION, ET POURQUOI CELLE-LÀ ────────────────────────────────────────────────────────
 * Le projet a DÉJÀ un modèle éprouvé pour ce cas exact — un écran de service, autonome, sur tablette,
 * hors SPA : le code PIN du Carnet (`/carnet`) et du Stock mobile (`/m`). Même besoin, même contrainte
 * d'authentification, même public. On le réemploie plutôt que d'inventer une troisième mécanique.
 *
 * Deux chemins sont acceptés, dans cet ordre :
 *   1. une SESSION WEB avec la permission `pos` — rien n'est retiré à qui en possède une (un accès
 *      par cookie reste valable, par exemple depuis un outil interne) ;
 *   2. un DÉVERROUILLAGE PAR PIN, glissant, comme le Carnet.
 *
 * ── FAIL-CLOSED ──────────────────────────────────────────────────────────────────────────────
 * Sans PIN configuré et sans session web, l'accès est REFUSÉ — pas ouvert. Ces écrans distribuent
 * des lots : une porte ouverte par défaut serait pire que la porte fermée qu'on répare. Et si le PIN
 * est retiré de la configuration, les sessions EN COURS se referment immédiatement (le Carnet a déjà
 * eu ce défaut : le fail-closed du contrôleur ne bloquait que les nouveaux déverrouillages).
 */
class EnsureWheelAccess
{
    public const SESSION_KEY = 'wheel_unlocked_at';

    public function handle(Request $request, Closure $next)
    {
        // Chemin 1 — une vraie session web habilitée. Rien n'est affaibli pour elle.
        $user = Auth::guard('web')->user();
        if ($user !== null && $this->habilite($user)) {
            $this->poserContexte($request, (int) ($user->branch_id ?: 1), (int) $user->id);

            return $next($request);
        }

        $pin = (string) config('wheel.access.pin', '');

        if ($pin === '') {
            return $this->refus(
                $request,
                'Les écrans de la roue ne sont pas encore ouverts sur cette machine.',
                403
            );
        }

        $depuis = (int) $request->session()->get(self::SESSION_KEY, 0);
        $duree = ((int) config('wheel.access.session_minutes', 240)) * 60;

        if ($depuis <= 0 || (now()->getTimestamp() - $depuis) > $duree) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->refus($request, 'Entre le code pour ouvrir les écrans de la roue.', 401);
        }

        // Session glissante : chaque action ravive le déverrouillage. Un écran de comptoir reste
        // ouvert des heures ; se faire éjecter en plein service serait absurde.
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());

        // Ouvert par le code : il n'y a donc PAS d'utilisateur. La branche vient de la
        // configuration (V1 LOCAL = une seule caisse) et l'auteur reste nul — on ne va pas
        // attribuer un geste à quelqu'un qui ne s'est pas identifié.
        $this->poserContexte($request, (int) config('wheel.counter_branch_id', 1), null);

        return $next($request);
    }

    /**
     * LA PERMISSION `pos`, CHERCHÉE SUR LES DEUX GARDES.
     *
     * Les permissions de ce projet sont enregistrées sous la garde `sanctum` (c'est la garde par
     * défaut de l'application). Un simple `$user->can('pos')` sur un utilisateur résolu par la garde
     * `web` ne les trouve donc pas, et ce chemin ne se serait JAMAIS ouvert — un cas typique de garde
     * qui a l'air de fonctionner et qui ne fait rien.
     */
    private function habilite($user): bool
    {
        foreach (['sanctum', 'web'] as $garde) {
            try {
                if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('pos', $garde)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Permission inexistante pour cette garde : ce n'est pas une erreur, on essaie la
                // suivante. Une exception ici ne doit jamais refermer la porte sur l'autre chemin.
            }
        }

        return false;
    }

    /**
     * LE CONTEXTE, RÉSOLU UNE SEULE FOIS ICI.
     *
     * Les écrans ont besoin de deux choses : quelle caisse, et qui agit. Les laisser relire
     * `$request->user()` chacun de leur côté était précisément la cause du P0 — sur le chemin du
     * code, il n'y a pas d'utilisateur, et chaque écran répondait 403 tout seul. Une seule source,
     * posée par la porte, vaut mieux que quatre lectures qui peuvent diverger une à une.
     */
    private function poserContexte(Request $request, int $branchId, ?int $actorId): void
    {
        $request->attributes->set('wheel_branch_id', $branchId > 0 ? $branchId : 1);
        $request->attributes->set('wheel_actor_id', $actorId);
    }

    /**
     * Un navigateur qui ouvre une page doit VOIR une page, jamais un JSON d'erreur. C'est exactement
     * l'impasse qu'on répare : l'ancienne redirection menait au talon JSON de l'API, sans aucun
     * écran pour s'en sortir. On renvoie donc vers l'accueil de la roue, qui porte le champ du code.
     */
    private function refus(Request $request, string $message, int $status)
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['status' => false, 'message' => $message, 'locked' => true], $status);
        }

        return redirect()
            ->route('admin.wheel.home')
            ->with('wheel_locked', $message);
    }
}
