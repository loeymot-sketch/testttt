<?php

namespace App\Services\Account;

use App\Models\User;

/**
 * [test-e2e stores S17-003 · 2026-11-13] Effacement des données personnelles d'un compte client supprimé, en UN seul endroit :
 * utilisé par la suppression du compte (DeactivateController) ET par le rattrapage des comptes supprimés avant les correctifs
 * (commande `comptes:rattraper-suppressions`). Les deux chemins ne peuvent donc plus diverger.
 *
 * Ce qui reste, et pourquoi : les commandes (preuve commerciale, 5 ans) et les tickets (NF525, 6 ans), avec l'adresse de
 * livraison (rue, code postal, commune, position) et la note ajoutée pour la cuisine.
 */
class AnonymisationCompte
{
    /** Traces laissées sur les commandes passées : complément / libellé d'adresse, téléphone et consignes des courses Uber. */
    public function effacerResidus(int $userId): void
    {
        // [test-e2e stores F9-004 · 2026-11-12] Les commandes sont conservées (obligation de conservation), mais le
        // COMPLÉMENT de leur adresse (étage, bâtiment, code d'entrée) et son libellé personnel (« Maison ») sont des
        // données personnelles sans aucune valeur comptable : effacés. Rue, commune, code postal et coordonnées
        // restent, car la commande et sa tournée de livraison en dépendent.
        \App\Models\OrderAddress::withoutGlobalScopes()->where('user_id', $userId)
            ->update(['apartment' => null, 'label' => 'Livraison']);

        // [test-e2e stores P-012] Même règle pour les courses Uber Direct de ses commandes : téléphone, consignes et nom
        // du destinataire effacés (colonnes ET réponse brute d'Uber) ; prix, statut et suivi de la course restent.
        $idsCommandes = \App\Models\Order::withoutGlobalScopes()->where('user_id', $userId)->pluck('id');
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
    }

    /** Identité du compte : nom, e-mail, téléphones, identifiants Apple/Google, jetons ; et désinscription des offres. */
    public function anonymiserIdentite(User $user): void
    {
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
        $user->username = 'supprime-' . \Illuminate\Support\Str::random(12);
        // [STORES 2026-11-12 · Apple 5.1.1(v)] Jeton Apple effacé ici ; révoqué chez Apple après la transaction.
        $user->apple_refresh_token = null;
        $user->apple_sub = null;
        $user->google_sub = null;
        $user->loyalty_code = null;
        $user->device_token = null;
        $user->web_token = null;
        $user->save();
    }

    /** Vrai quand l'identité de ce compte a déjà été effacée (sentinelle de téléphone posée par anonymiserIdentite). */
    public function identiteDejaEffacee(User $user): bool
    {
        return str_starts_with((string) $user->phone, 'PENDING_DELETED_') && $user->email === null && $user->name === 'Compte supprimé';
    }
}
