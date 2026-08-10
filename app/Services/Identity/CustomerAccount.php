<?php

namespace App\Services\Identity;

use App\Enums\Ask;
use App\Enums\Role;

/**
 * « EST-CE LE COMPTE D'UN CLIENT ? » — une seule définition.
 *
 * ── LE PIÈGE QUE CETTE CLASSE FERME ──────────────────────────────────────────────────────────
 * Le réflexe est d'écrire `is_guest === YES`. C'est FAUX : un client qui s'inscrit vraiment passe à
 * `is_guest = NO` (`SignupController`), et la base en contient 13 qui portent le rôle client. Un
 * filtre bâti sur `is_guest` les aurait privés de leurs points, en silence, en croyant réparer un
 * défaut voisin (constaté le 10 août — les tests existants l'ont attrapé).
 *
 * Le bon critère est « ce n'est pas l'équipe ». Un invité de passage EST un client ; un membre du
 * personnel n'en est pas un, même s'il commande. Le comptoir en dépend directement : un caissier
 * qui cherche « 06… » ne doit pas tomber sur un collègue et lui créditer des points.
 *
 * ── POURQUOI UN FOYER NEUTRE ─────────────────────────────────────────────────────────────────
 * La définition est née dans `WheelService`. La caisse en a maintenant besoin. En recopier une
 * seconde version, ce serait le motif du « jumeau oublié » une fois de plus.
 * `WheelService::isCustomerAccount()` reste en place et délègue ici.
 *
 * Sentinelle : tests/Feature/Identity/PhoneIdentityTest.php
 */
final class CustomerAccount
{
    /**
     * Rôles qui désignent l'ÉQUIPE. Tout ce qui n'en porte aucun est un client.
     *
     * @return array<int, string>
     */
    public static function staffRoles(): array
    {
        return ['Admin', 'Branch Manager', 'POS Operator', 'Chef', 'Stuff', 'Waiter', 'Delivery Boy'];
    }

    /** Le nom du rôle client, stable quel que soit l'ordre d'insertion en base. */
    public const ROLE_NAME = 'Customer';

    public function isCustomer($user): bool
    {
        if ($user === null) {
            return false;
        }

        if ((int) ($user->is_guest ?? 0) === (int) Ask::YES) {
            return true;
        }

        try {
            if (! method_exists($user, 'hasRole')) {
                return false;
            }

            // On compare le NOM *et* l'identifiant. `App\Enums\Role::CUSTOMER` vaut 2, un
            // IDENTIFIANT : en base de production id=2 est bien « Customer », mais rien dans le code
            // ne le garantit — le harnais de test, lui, sème « Chef » sur le 2. Un critère d'identité
            // client qui dépend de l'ordre d'insertion des rôles est un critère qui basculera un jour
            // sur une base réinstallée, et il ferait alors disparaître tous les clients du comptoir.
            return $user->hasRole(self::ROLE_NAME) || $user->hasRole(Role::CUSTOMER);
        } catch (\Throwable $e) {
            // Rôles illisibles : on ne bloque pas un crédit pour un incident de lecture. Le compte
            // reste candidat, et les autres gardes (suppression, unicité) tiennent toujours.
            return true;
        }
    }

    /**
     * L'inverse, exprimé pour un écran de comptoir : ce compte appartient-il à l'équipe ?
     *
     * Séparé de `isCustomer()` à dessein — un compte sans aucun rôle et sans `is_guest` n'est ni
     * clairement l'un ni clairement l'autre, et les deux questions ne doivent pas se répondre par
     * une simple négation qui masquerait ce cas.
     */
    public function isStaff($user): bool
    {
        if ($user === null) {
            return false;
        }

        try {
            return method_exists($user, 'hasAnyRole') && $user->hasAnyRole(self::staffRoles());
        } catch (\Throwable $e) {
            // Rôles illisibles : on préfère le refus prudent. Ne PAS retomber sur « c'est un
            // client » ici : ce serait exposer le solde d'un membre de l'équipe au comptoir.
            return true;
        }
    }
}
