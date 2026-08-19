<?php

namespace App\Http\Middleware;

use App\Enums\Ask;
use App\Models\KioskMachine;
use App\Support\PhoneDisplay;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * [APPS 2026-08-19] Aucune commande client sans numéro de téléphone joignable.
 *
 * POURQUOI CE VERROU EST CÔTÉ SERVEUR
 * -----------------------------------
 * L'application affiche un écran bloquant qui réclame le numéro après une connexion
 * Apple ou Google. Mais un écran n'est qu'une politesse : il suffit de fermer puis
 * rouvrir l'application, ou d'appeler l'API directement, pour passer à côté. Le seul
 * endroit où « toujours » veut dire toujours, c'est ici.
 *
 * L'exigence vient de l'exploitation, pas de la sécurité : rupture d'un produit, question
 * sur une cuisson, client qui ne vient jamais chercher sa commande — si personne ne
 * répond sur la messagerie du site, le restaurant doit pouvoir DÉCROCHER SON TÉLÉPHONE.
 * Une commande sans numéro est une commande qu'on ne peut pas rattraper.
 *
 * CE QUI N'EST PAS TOUCHÉ, ET POURQUOI
 * ------------------------------------
 * · La BORNE du restaurant place de vraies commandes sans le moindre client identifié :
 *   lui réclamer un numéro casserait la prise de commande sur place. On la reconnaît par
 *   le NOM de son jeton (`kiosk-token`) — c'est le discriminant déjà retenu ailleurs dans
 *   le projet (BlockKioskMachineToken), pas un critère inventé pour l'occasion.
 * · Les clients venus par le parcours téléphone ont, par construction, un numéro : leur
 *   compte est CRÉÉ à partir de lui. Ce filtre est donc silencieux pour eux, et ne mord
 *   que sur les comptes ouverts par connexion sociale, qui n'en apportent aucun.
 * · Requête sans jeton personnel (session web, appel non authentifié) : on laisse la pile
 *   d'authentification décider, ce n'est pas le rôle de ce filtre.
 */
class RequireCustomerPhone
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // BORNE du restaurant : reconnue par le NOM de son jeton, comme ailleurs dans le
        // projet (BlockKioskMachineToken). Elle prend de vraies commandes sans client
        // identifié — lui réclamer un numéro casserait la prise de commande sur place.
        $jeton = $user->currentAccessToken();
        if ($jeton && method_exists($jeton, 'getAttribute') && $jeton->name === 'kiosk-token') {
            return $next($request);
        }

        // On juge le COMPTE, pas le canal d'authentification.
        //
        // La première version de ce filtre exigeait un jeton nommé `auth_token` et laissait
        // passer tout le reste. Elle avait l'air correcte et ne bloquait rien : les
        // contrôleurs de connexion ouvrent AUSSI une session web (`Auth::guard('web')
        // ->loginUsingId`), si bien qu'une requête authentifiée par cookie arrive ici avec
        // un utilisateur valide mais SANS jeton personnel — `currentAccessToken()` rend
        // null, et le filtre s'effaçait. Un verrou qui dépend de la façon dont on s'est
        // authentifié n'est pas un verrou : il suffit de changer de porte.
        //
        // Le critère juste est la nature du compte. Le personnel et les comptes de service
        // ne commandent pas par cette route (la caisse a la sienne) ; l'exigence de
        // l'exploitant vise le CLIENT, et un client se reconnaît à `is_guest`.
        if ((int) $user->is_guest !== Ask::YES) {
            return $next($request);
        }

        // `PhoneDisplay::safe()` est le juge canonique du projet sur « ce numéro est-il
        // réel ». La colonne `users.phone` est NOT NULL depuis la migration
        // 2026_05_16_140100 : un compte créé sans numéro n'est donc PAS vide, il porte une
        // sentinelle `PENDING_CREATE_<hex>` posée par User::creating. Tester `filled()`
        // aurait laissé passer TOUS les comptes sociaux — le filtre aurait eu l'air de
        // fonctionner sans jamais rien bloquer. On réutilise le helper existant plutôt que
        // de réécrire ici la connaissance du préfixe : deux juges finissent par diverger.
        if (PhoneDisplay::safe((string) $user->phone) !== null) {
            return $next($request);
        }

        // Dernier filet pour la BORNE, et seulement ici : à ce stade on s'apprête à
        // refuser, donc cette requête ne coûte rien au cas courant.
        //
        // Le nom du jeton ne suffit pas toujours à reconnaître une borne : le compte
        // support d'une machine peut être marqué « invité » selon l'installation, et
        // certaines piles d'authentification présentent un jeton sans nom. Le fait
        // OBJECTIF, lui, est en base : ce compte est-il rattaché à une machine ? Si oui,
        // c'est une borne, pas un client, et elle n'a aucun numéro personnel à fournir.
        // Sans ce contrôle, la prise de commande sur place tombait — régression réelle,
        // attrapée par les tests de limitation de débit borne existants.
        if (KioskMachine::where('user_id', $user->id)->exists()) {
            return $next($request);
        }

        return response()->json([
            'status'  => false,
            'code'    => 'PHONE_REQUIRED',
            'message' => 'Merci d\'indiquer votre numéro de téléphone avant de commander : il nous sert à vous joindre en cas de question sur votre commande.',
        ], 422);
    }
}
