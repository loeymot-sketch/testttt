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

                // [test-e2e stores F9-004 · 2026-11-12] Les commandes sont conservées (obligation de conservation), mais le
                // COMPLÉMENT de leur adresse (étage, bâtiment, code d'entrée) et son libellé personnel (« Maison ») sont des
                // données personnelles sans aucune valeur comptable : effacés. Rue, commune, code postal et coordonnées
                // restent, car la commande et sa tournée de livraison en dépendent.
                \App\Models\OrderAddress::withoutGlobalScopes()->where('user_id', $user->id)
                    ->update(['apartment' => null, 'label' => 'Livraison']);

                // [test-e2e stores P-012] Même règle pour les courses Uber Direct de ses commandes : téléphone, consignes et nom
                // du destinataire effacés (colonnes ET réponse brute d'Uber) ; prix, statut et suivi de la course restent.
                $idsCommandes = Order::withoutGlobalScopes()->where('user_id', $user->id)->pluck('id');
                if ($idsCommandes->isNotEmpty()) {
                    \App\Models\UberDirectDelivery::withoutGlobalScopes()->whereIn('order_id', $idsCommandes)->get()
                        ->each(function ($course) {
                            $brut = (array) ($course->delivery_payload ?? []);
                            if (isset($brut['dropoff']) && is_array($brut['dropoff'])) {
                                unset($brut['dropoff']['name'], $brut['dropoff']['phone_number'], $brut['dropoff']['notes']);
                            }
                            unset($brut['dropoff_name'], $brut['dropoff_phone_number'], $brut['dropoff_notes']);
                            $course->forceFill(['dropoff_phone' => null, 'dropoff_instructions' => null, 'delivery_payload' => $brut ?: null])->saveQuietly();
                        });
                }

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
                // [PROFIL APP 2026-11-12] Abonnement « offres par e-mail » (profil) : l'adresse quitte la liste des abonnés
                // AVANT d'être effacée — sinon un compte supprimé continuerait de recevoir les e-mails du restaurant.
                $emailAvant = mb_strtolower(trim((string) $user->email));
                if ($emailAvant !== '') {
                    \App\Models\Subscriber::query()->whereRaw('LOWER(email) = ?', [$emailAvant])->delete();
                }

                $user->name = 'Compte supprimé';
                $user->email = null;
                // `users.phone` est NOT NULL (migration 2026_05_16_140100) : on ne peut pas y
                // écrire null. Le préfixe `PENDING_` est la convention déjà en place pour
                // « ce n'est pas un vrai numéro » — `PhoneDisplay::safe()` le masque à
                // l'affichage et `ValidPhone` le refuse. Réutiliser cette convention évite
                // d'introduire un second vocabulaire que les gardes existantes ignoreraient.
                $user->phone = 'PENDING_DELETED_' . bin2hex(random_bytes(6));
                // [APPS 2026-08-19] Le numéro DÉCLARÉ (comptes ouverts par connexion
                // Apple/Google) est une donnée personnelle au même titre que l'autre :
                // l'oublier ici aurait laissé un numéro de téléphone en base après une
                // suppression annoncée comme définitive.
                $user->contact_phone = null;
                $user->username = 'supprime-' . Str::random(12);
                // [STORES 2026-11-12 · Apple 5.1.1(v)] Jeton Apple effacé ici ; révoqué chez Apple après la transaction.
                $user->apple_refresh_token = null;
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
