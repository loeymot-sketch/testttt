# LOCK — afficher le prix d'un supplément payant sur l'écran de la borne

**Invariant visé** : « la projection du composeur ne porte AUCUN prix »
**Fichiers concernés** : `app/Services/Composer/ComposerProfileProjection.php`,
`resources/js/components/frontend/kiosk/steps/KioskStepGenericChoicesComponent.vue`
**Gardé par** : `tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php` → `assertNoPriceKeys()`
**Pendant de** : `app/Http/Requests/ComposerStepRequest.php:32` → `'price' => ['prohibited']`
**Date** : 2026-08-28
**Gate** : **G-PRIX-BORNE**
**État** : ⏳ **EN ATTENTE DE CONTRESIGNATURE**

---

## 1. Ce que voit un client aujourd'hui

Sur une étape de wizard borne — les sauces, par exemple — le client voit une grille de cases
portant **uniquement le nom** du choix. Aucun montant, nulle part.

Il en tape une. Le total, en haut de l'écran, augmente de 0,50 €. Rien ne le lui a annoncé.
Il découvre le supplément au récapitulatif, s'il le lit.

Vérifié en lecture :

- `ComposerProfileProjection.php:98-106` (variations) et `:122-130` (extras) rendent
  `id, name, source_type, status, is_available, unavailable_reason` — **jamais `price`**, alors
  que `item_extras.price` et `item_variations.price` existent en base et sont facturés.
- `KioskStepGenericChoicesComponent.vue:20` n'affiche que `choice.name`. Aucune occurrence de
  `price` ni de `€` dans le fichier.
- Le total, lui, bouge bien : `KioskWizardComponent.vue:2110` et `:2120` ajoutent
  `variation.price` / `extra.price`, récupérés par un autre chemin.

## 2. Pourquoi je ne l'ai pas corrigé

**Parce que ça contredit une décision stable du projet, appliquée et testée.**

L'invariant « pas de prix dans le composeur » n'est pas un oubli. Il est posé en deux endroits :

- à l'écriture — `ComposerStepRequest.php:32` : `'price' => ['prohibited']` ;
- à la lecture — `assertNoPriceKeys()` balaye récursivement la projection et échoue sur toute clé
  `price`, `flat_price`, `currency_price`, `convert_price` ou `total`.

La raison est saine : `PricingService` est la source unique des prix (CLAUDE.md §8), et un prix qui
transite par le navigateur est un prix qu'on pourrait croire faisant autorité.

J'avais écrit le correctif — projection + affichage conditionnel + six tests. **Je l'ai annulé** en
découvrant la sentinelle. Modifier `assertNoPriceKeys` pour laisser passer mon changement aurait été
exactement ce que je reproche au reste du dépôt depuis cette nuit : faire taire un garde-fou pour
faire passer son travail. `CLAUDE.md §12` l'interdit, et il a raison.

## 3. Pourquoi ça mérite quand même votre signature

**Ce n'est pas une préférence d'affichage.** En France, le prix d'une prestation payante doit être
porté à la connaissance du consommateur **avant** l'achat. Une borne qui facture 0,50 € sans jamais
afficher ce montant sur l'écran de choix n'est pas dans un débat de design.

Et c'est au cœur de votre demande du 2026-08-26 : « personnalisation par catégories … avec règles
choix unique / choix gratuit / **supplément payant** ». Un supplément payant dont le client ne voit
pas le prix n'est pas une fonctionnalité — c'est une surprise à l'encaissement.

## 4. Ce que je propose, et ce qui reste protégé

1. **La projection transporte `price`**, en flottant, pour les extras et les variations.
   Purement DESCRIPTIF : `PricingService` continue de relire le prix en base et ne fait aucune
   confiance au client. Aucun chemin de facturation nouveau côté navigateur.
2. **La carte de choix affiche « + 0,50 € »** — et **uniquement si le prix est strictement
   positif**. Un choix gratuit garde exactement la carte qu'il a aujourd'hui : ni « + 0,00 € », ni
   tiret, ni espace réservé. Le design que vous avez déclaré parfait n'est modifié que là où il y a
   de l'argent en jeu.
3. **La zone gelée n'est pas touchée.** Le sous-composant `steps/` ne figure pas dans la liste §7,
   qui nomme `KioskWizardComponent`, `KioskAppComponent` et `KioskUpsellComponent`. Un test de
   discipline vérifie que l'affichage ne migre pas dans l'un des trois.
4. **`assertNoPriceKeys` serait resserrée, pas supprimée** : elle continuerait d'interdire
   `flat_price`, `currency_price`, `convert_price` et `total` — les formes qui font autorité — et
   n'admettrait que `price`, nombre nu, sur les choix.

## 5. Si vous refusez

C'est un choix défendable : garder la projection strictement muette sur les prix, et afficher le
montant par un autre chemin (celui qu'emprunte déjà le total). Dites-le, et je le ferai ainsi —
mais alors il faut le faire, parce que le client doit voir le prix d'une façon ou d'une autre.

---

☐ **Contresigné par le propriétaire** — date : ................
