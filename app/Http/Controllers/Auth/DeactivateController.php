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

                $checkOrder = Order::where('user_id', $user->id)->whereNotIn('status', [OrderStatus::DELIVERED, OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED])->first();

                if (! $estClient) {
                    throw new Exception(trans('all.message.only_customer_delete'), 422);
                } else if ($checkOrder) {
                    throw new Exception(trans('all.message.account_not_delete'), 422);
                }

                $user->addresses()->delete();

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
                $user->name = 'Compte supprimé';
                $user->email = null;
                // `users.phone` est NOT NULL (migration 2026_05_16_140100) : on ne peut pas y
                // écrire null. Le préfixe `PENDING_` est la convention déjà en place pour
                // « ce n'est pas un vrai numéro » — `PhoneDisplay::safe()` le masque à
                // l'affichage et `ValidPhone` le refuse. Réutiliser cette convention évite
                // d'introduire un second vocabulaire que les gardes existantes ignoreraient.
                $user->phone = 'PENDING_DELETED_' . bin2hex(random_bytes(6));
                $user->username = 'supprime-' . Str::random(12);
                $user->apple_sub = null;
                $user->google_sub = null;
                $user->loyalty_code = null;
                $user->device_token = null;
                $user->web_token = null;
                $user->save();

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

            return response(['status' => true, 'message' => trans("all.message.account_delete_success")]);
        } catch (Exception $exception) {
            DB::rollBack();
            return response(['status' => false, 'message' => QueryExceptionLibrary::message($exception)], 422);
        }
    }
}
