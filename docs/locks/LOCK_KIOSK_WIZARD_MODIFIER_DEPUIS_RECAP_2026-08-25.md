# LOCK — Édition frozen KioskWizardComponent.vue (modifier un produit depuis le récap)

**Date :** 2026-08-25
**Fichier frozen touché (CLAUDE.md §7) :** `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**SHA-256 avant :** `f445b1a8194dc03ababe6abdea6fec078f05ca951675af57b9dc9b3bc2058307`
**SHA-256 après :** `fcbe3755aa9c118e7bf1feafac293b993073262126228e09c861eae69ee256ac`
**Baseline mise à jour :** `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` (même commit)

## Pourquoi

Demande du propriétaire : « s'il veut modifier un produit du panier, ça va ouvrir le récap,
et à côté de chaque chose il pourra le modifier, et ça ouvre directement la page dédiée —
s'il veut changer la viande, s'il veut changer la formule ».

Aujourd'hui, modifier un article du panier rouvre le wizard **à la première étape**. Pour
corriger une seule sauce, le client reparcourt viande, sauce, suppléments, formule. Sur une
borne, où l'on est debout et où quelqu'un attend derrière, c'est la différence entre corriger
et abandonner.

La mécanique d'édition existait déjà (`P-MEGA-05` : snapshot, restauration des sélections,
remplacement en place, annulation non destructive). Il ne manquait que **le point d'arrivée**
et **le moyen d'atteindre une étape précise**.

## Le changement (logique et câblage, aucun design touché)

Trois ajouts, tous circonscrits :

1. `@modifier="goToStepType"` sur le `<component :is>` qui rend l'étape courante — **une ligne**.
   C'est par là que le récap (non frozen) remonte le type d'étape à ouvrir.
2. `goToStepType(type)` — résout le type contre `activeSteps` et déplace `currentStepIndex`.
   Un type inconnu ou vide **ne déplace pas** le client : l'envoyer sur une étape au hasard
   serait pire que de ne rien faire.
3. `openOnRecapIfEditing()` + `recapStepIndex()` — à l'ouverture EN ÉDITION SEULEMENT, le
   wizard se positionne sur le récap. Appelé aux **deux** endroits d'hydratation : la prop
   `item` et le chemin `fetchItemById` — c'est ce dernier qu'emprunte réellement le panier
   (`/wizard/:itemId?edit=1`), et l'oublier aurait laissé la fonctionnalité morte en production
   tout en paraissant fonctionner en test.

Aucune modification de `<style>`. Aucun changement du chemin d'ajout au panier, du calcul de
prix, ni de la sérialisation des viandes (`LOCK_KIOSK_WIZARD_MULTI_VIANDE_2026-06-30` intact).

## Vérification

- **`tests/js/kioskModifierDepuisRecap.spec.js` — 11 tests** : une première composition commence
  toujours à l'étape 1 ; une modification ouvre sur le récap ; le saut par type atteint la bonne
  étape ; un type inconnu, vide, `null`, `0` ou `false` ne déplace rien et ne lève pas ; on peut
  enchaîner tous les types sans se perdre ; les boutons du récap émettent le bon type ; une
  section sans choix n'affiche aucun bouton ; le libellé est « Modifier », jamais une clé i18n.
- **`tests/js/kioskModifierAbus.spec.js` — 12 tests d'ABUS** (exigés par le propriétaire avant
  tout déploiement) : la ligne n'est jamais perdue pendant l'édition ; l'abandon restaure à
  l'identique ; la validation REMPLACE sans dupliquer ; c'est la ligne ciblée qui change ;
  valider après une annulation ajoute au lieu d'écraser ; deux « Modifier » d'affilée ne gardent
  que la dernière cible ; un index inexistant ou négatif ne casse rien ; le snapshot est une
  copie profonde ; la quantité reste bornée (1 à 20) ; le devis en cache est invalidé.
- **Parcours RÉEL mesuré à la résolution de la borne (1080×1920, Playwright)** : Tacos XL composé
  → récap atteint → 2 boutons « Modifier » rendus (106×44 px, au-dessus du minimum tactile) →
  clic sur les viandes → retour sur « QUELLE VIANDE ? ». **0 erreur JS.**
- **Vitest complet : 3 667 verts / 446 fichiers, 0 rouge.** Aucune régression sur les 97 tests
  wizard existants.
- Sentinelle `FrozenZone` verte après mise à jour de la baseline.

## Ce que ce LOCK ne couvre pas

Le stepper reste **non cliquable**. Le rendre cliquable aurait permis de sauter à une étape
non encore visitée, en contournant les validations d'étape (`canAdvance`) — donc de composer
un produit incomplet. Le saut n'est ouvert que depuis le récap, c'est-à-dire depuis un produit
déjà complet.

## Autorisation

Demande explicite et détaillée du propriétaire (2026-08-25, commande `/goal`), assortie d'une
condition qu'il a posée lui-même : **« tu ne déploieras jamais qu'avec les tests d'abus »**.
Cette condition est respectée : rien n'est déployé au moment où ce LOCK est écrit, et les
12 tests d'abus sont verts.

**Contresignature propriétaire :** ☐ (en attente — le déploiement est volontairement suspendu)
