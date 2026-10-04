# LOCK — refuser une commande sans taxe, au lieu de la facturer à 0 %

**Fichier gelé visé** : `app/Services/Pricing/PricingService.php` (CLAUDE.md §7 — SSOT des prix)
**Date** : 2026-08-27
**Gate** : **G-PRIX**
**État** : ⏳ **EN ATTENTE DE CONTRESIGNATURE**

---

## 1. Le défaut

```php
// app/Services/Pricing/PricingService.php:240-243
$taxId  = (int) ($dbItem->tax_id ?? 0);   // null devient 0
$taxObj = $taxes[$taxId] ?? null;          // aucune taxe ne porte l'id 0
$taxRate = $taxObj ? (float) $taxObj->tax_rate : 0.0;
```

Un article dont la taxe est introuvable est facturé **à 0 %**, sans une alerte, sans une ligne de
journal. La commande part, le ticket s'imprime, la clôture Z l'enregistre. Personne ne saura jamais.

## 2. Pourquoi ce n'est pas déjà réglé

Ça l'est **à moitié**, et c'est important de le dire précisément.

Le 2026-08-27, `tax_id` est devenu obligatoire dans `ItemRequest` (commit `339c073fa`) puis dans
`ItemImport` (commit `00f2bf5a3`). Les deux portes par lesquelles un commerçant crée un article
sont donc fermées.

Mais un agent adverse a démontré, le même jour, que **le trou se déplace au lieu de se fermer** :
la création rapide du Studio choisissait automatiquement la taxe d'identifiant le plus bas — « No-VAT »,
0 %, active — et passait la règle sans difficulté, puisque c'est une taxe réelle. Corrigé aussi.

Le point qui reste : **chaque nouveau chemin d'écriture rouvre le trou**. Une API tierce, une reprise
de données, une commande artisan, un module futur. La règle de formulaire protège les formulaires ;
elle ne protège pas le calcul.

Tant que le moteur de prix accepte silencieusement une taxe absente, la protection dépend de la
vigilance de chaque auteur de chaque futur chemin d'écriture. C'est une dette qui se rembourse en
une fois, ici.

## 3. Ce qui est mesuré aujourd'hui, sur la base de travail

| | |
|---|---|
| Articles au total | 170 |
| Sans taxe du tout (`tax_id` nul) | 5 |
| Rattachés à un taux à 0 % | 72 |
| **Exposition** | **77 / 170** |

Une partie est de la pollution d'audit. Le mécanisme qui la rend possible, lui, est celui du produit.

## 4. Périmètre — volontairement minuscule

**Autorisé** : dans la boucle de calcul, quand `tax_id` est nul ou ne résout aucune taxe, **lever une
exception** au lieu de retomber sur 0.0. Le message doit nommer l'article, pour qu'un caissier sache
quoi corriger et un développeur où regarder.

**Rien d'autre.** Aucun taux modifié, aucun arrondi, aucun ordre d'application changé.

**Interdit explicitement :**
- refuser un article rattaché à une taxe **réelle** dont le taux vaut 0 % — un produit exonéré existe,
  c'est une décision légitime du commerçant. On refuse l'**absence** de taxe, pas le taux zéro ;
- toucher `composition_snapshot`, la séquence fiscale, ou la chaîne d'audit.

## 5. La question que le propriétaire doit trancher, et elle n'est pas confortable

**Refuser une commande, c'est refuser un encaissement.**

Si un article mal configuré traîne dans la carte, une caisse en service commencera à rejeter des
ventes — au comptoir, avec un client devant. Aujourd'hui elle les accepte, et sous-déclare la TVA.

Ce sont les deux seules options, et aucune n'est indolore :

| | Aujourd'hui | Après ce LOCK |
|---|---|---|
| Article sans taxe | vendu à 0 %, en silence | **vente refusée**, message explicite |
| Risque | fiscal, invisible, cumulatif | commercial, visible, immédiat |
| Qui le découvre | l'inspecteur | le caissier, tout de suite |

Mon avis, puisque vous le demandez toujours : **le refus est le bon choix**, mais il ne doit pas
partir sans un filet — un balayage préalable des articles sans taxe, corrigé avant activation. Sinon
le premier jour de production sera un mauvais souvenir.

## 6. Le filet

`tests/Feature/Pricing/LigneDeBasePrixTest.php` — 12 cas, 47 assertions, et **elle mord** (un
centime de décalage la fait rougir, vérifié). Elle garantit qu'aucun total existant ne bouge.

À ajouter avant activation : une commande de diagnostic qui liste les articles sans taxe, à faire
tourner sur la base réelle et à vider **avant** que le refus n'entre en vigueur.

**Rollback** : la modification est un `throw` dans une branche aujourd'hui silencieuse. Revenir en
arrière, c'est le retirer.

---

**Contresignature propriétaire :** ☐ *(à cocher par le propriétaire — je ne signe pas à sa place)*

**Décision demandée, en une ligne** : préférez-vous qu'une vente soit **refusée devant le client**,
ou qu'elle parte **hors taxe sans que personne le sache** ?
