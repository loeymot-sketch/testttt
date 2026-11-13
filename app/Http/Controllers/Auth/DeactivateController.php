<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Enums\Role;
use Illuminate\Http\Request;
use Exception;
use App\Libraries\QueryExceptionLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class DeactivateController extends Controller
{

    function deleteAccount(Request $request): \Illuminate\Http\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory
    {
        // [test-e2e stores F-005] Jeton Apple lu AVANT la transaction et révoqué APRÈS sa validation : un appel réseau ne tient
        // plus la transaction ouverte, et une suppression refusée (commande en cuisine…) ne révoque rien chez Apple.
        $jetonApple = null;
        try {
            $jetonApple = $request->user()?->apple_refresh_token;
        } catch (\Throwable) {
            $jetonApple = null;   // jeton illisible (clé de chiffrement changée) : rien à révoquer
        }
        try {
            DB::transaction(function () use ($request) {
                $user = $request->user();

                /**
                 * [APPS 2026-08-19] Le rôle est reconnu par son NOM, plus par son
                 * identifiant en base.
                 *
                 * Avant : `$user->myRole !== Role::CUSTOMER`, qui compare `roles.id` à la
                 * constante 2. Cela ne fonctionne QUE si le rôle « Customer » a reçu l'id 2
                 * au semis. Le dépôt documente déjà ce piège
                 * (Database\Seeders\SpatieRoleLookup) : « comparer les constantes comme des
                 * roles.id casse dès qu'un rollback MySQL décale l'auto-incrément ».
                 *
                 * La conséquence ici est sévère et silencieuse : si les identifiants
                 * dérivent, PLUS AUCUN client ne peut supprimer son compte — la méthode
                 * répond « seuls les clients peuvent supprimer leur compte » à un client.
                 * Un manquement que ni Apple ni Google ne laissent passer, et qui ne se
                 * voit qu'en le testant sur une base dont les rôles n'ont pas été semés
                 * dans l'ordre d'origine (constaté exactement ainsi en test).
                 *
                 * On conserve la sémantique d'origine — le rôle PRINCIPAL doit être
                 * « Customer », pas seulement figurer parmi les rôles — pour qu'un compte
                 * d'exploitation ne puisse pas se supprimer par cette porte.
                 */
                $premierRole = $user->roles->first();
                $estClient = $premierRole && $premierRole->name === 'Customer';

                // [GOAL STORES T-3.1.3 · 2026-09-30] Ce que le client aurait pu annuler lui-même
                // (jamais commencé par la cuisine, jamais payé) est annulé par le chemin
                // d'annulation client existant — puis seulement on regarde s'il reste une
                // commande qui bloque. Sans ça, un examinateur Apple/Google qui commande « sur
                // place » sans jamais retirer ne pouvait plus supprimer son compte (5.1.1(v)).
                // Tout se passe dans la transaction englobante : un refus plus bas annule aussi
                // ces annulations — on ne supprime jamais « à moitié ».
                app(\App\Services\FrontendOrderService::class)->cancelAllOwnCancellableForAccountDeletion(
                    (int) $user->id,
                    'Suppression du compte par le client'
                );

                $checkOrder = Order::where('user_id', $user->id)->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])->first();

                if (! $estClient) {
                    throw new Exception(trans('all.message.only_customer_delete'), 422);
                } else if ($checkOrder) {
                    throw new Exception(trans('all.message.account_not_delete_kitchen'), 422);
                }

                $user->addresses()->delete();

                app(\App\Services\Account\AnonymisationCompte::class)->effacerResidus((int) $user->id);

                /**
                 * [APPS 2026-08-19] EFFACEMENT RÉEL DES DONNÉES PERSONNELLES.
                 *
                 * Avant : la méthode se contentait de `delete()`, une suppression DOUCE qui
                 * laissait le nom, l'e-mail et le téléphone intacts dans la ligne. Le compte
                 * disparaissait de l'écran, les données restaient. C'est une désactivation,
                 * pas une suppression — Apple comme Google exigent une vraie suppression, et
                 * le RGPD aussi.
                 *
                 * Deuxième conséquence, plus grave : le parcours d'inscription RESSUSCITE un
                 * compte invité supprimé qui porte le même téléphone
                 * (`GuestSignupController::register`, bloc « restore »). Un compte « supprimé »
                 * revenait donc à la vie, avec son historique et ses points, dès que la
                 * personne redonnait son numéro. Effacer les identifiants ferme ce chemin
                 * sans toucher au code d'inscription : plus rien ne permet de retrouver la
                 * ligne, ni par téléphone, ni par e-mail, ni par identité Apple/Google.
                 *
                 * CE QUI EST CONSERVÉ, ET POURQUOI : les commandes et leurs justificatifs
                 * fiscaux ne sont PAS touchés. La loi française impose leur conservation
                 * pendant six ans (NF525), et le RGPD prévoit explicitement ce cas. On efface
                 * le PROFIL, pas la comptabilité.
                 */
                app(\App\Services\Account\AnonymisationCompte::class)->anonymiserIdentite($user);

                // TOUS les jetons, pas seulement celui de l'appareil courant : un client qui
                // supprime son compte depuis son téléphone ne doit pas laisser une session
                // vivante sur la tablette où il s'était connecté le mois dernier.
                // (L'ancien code appelait `currentAccessToken()->delete()` APRÈS avoir
                // supprimé l'utilisateur, ce qui ne révoquait qu'une session — et échouait
                // net sur une authentification par session, où il n'y a pas de jeton.)
                $user->tokens()->delete();

                $user->delete();

                session()->flush();
            });

            // [STORES 2026-11-12 · Apple 5.1.1(v)] Révocation chez Apple, comme Apple l'exige pour une suppression de compte.
            // Non bloquante : un refus d'Apple est journalisé, la suppression chez nous est faite (le client l'a demandée).
            if (filled($jetonApple)) {
                app(\App\Services\Auth\AppleTokenRevoker::class)->revoquer((string) $jetonApple);
            }

            return response(['status' => true, 'message' => trans("all.message.account_delete_success")]);
        } catch (Exception $exception) {
            DB::rollBack();
            return response(['status' => false, 'message' => QueryExceptionLibrary::message($exception)], 422);
        }
    }
}
