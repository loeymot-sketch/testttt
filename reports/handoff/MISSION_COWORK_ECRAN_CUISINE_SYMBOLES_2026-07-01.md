# MISSION COWORK (STRICTE) — Écran cuisine symbolique : valider CHAQUE produit sans faute

> Prérequis ABSOLU : avoir d'abord exécuté `MISSION_COWORK_INSTALL_DEFINITIVE_2026-07-01.md`
> (déploiement propre de la branche `pos/category-first-caisse-2026-06-23`). Tant que le VPS n'est
> pas redéployé, on teste l'ancien code → résultats faux. **NE PAS commencer cette mission avant.**

## Contexte (déjà validé côté dev — à confirmer sur la VRAIE machine)
Le rendu symbolique cuisine a été prouvé en dev (rendu réel + tests PHP 15/15 + JS 18/18 + capture
du vrai KDS). Format standard, IDENTIQUE caisse et borne (même moteur `KitchenTicketSymbolicFormatter`
PHP ↔ `kdsSymbolic.js`) :
```
[support] | PRODUIT | [taille] | [viandes symboles] | [STO crudités] | [sauce symbole]
+ Supplément payant          (une ligne par supplément : + Cheddar, + Cornichon, + Viande supplémentaire…)
MENU : <sauce frites>        (si formule)
```
Symboles : support S=pain/sandwich, G=galette/tacos · viandes Mex/Cordon/K(hachée)/P(poulet)/Nug/
Tender/Frec · crudités S(salade)T(tomate)O(oignon) · sauces ALG/MAY/SAM/AND/HAR/HAN/BL/KTP/BBQ/FRO/SPI/CURY.

## RÈGLES DE VALIDATION (chaque produit, caisse ET borne)
Pour CHAQUE produit ci-dessous : passer une commande (borne pour la moitié, caisse pour l'autre),
puis vérifier l'affichage sur le KDS (`/admin/kitchen-display-system`) ET sur le ticket cuisine imprimé.

### A. Multi-viandes (DOIVENT montrer 2 viandes distinctes)
- **Méga, Terminator** : choisir 2 viandes différentes → KDS = `S | MÉGA | Mex Cordon | STO | <sauce>`.
  ⚠️ Si une seule viande apparaît → BUG (signaler). Les 2 doivent être là.
- **Tacos L** : 2 viandes → `G | TACOS | L | <v1> <v2> | ...`.

### B. Recette fixe (DOIVENT montrer AUCUNE viande)
- **Cayenne, Suprême, TOUS les burgers** (Chicken, Cheese, Double Cheese, Fish, Big, Grill) :
  → `S | CAYENNE | STO | <sauce>` (pas de symbole viande). ⚠️ Si une étape « choisir viande » vide
  apparaît sur la borne → signaler (ne devrait pas).

### C. Support / base
- **Tacos M, Galette** : support `G` en tête.
- **Bols** (Bol Frites, Bol Riz) : `BOL RIZ | <viande> | <sauce>` (la base Riz/Frites dans le nom).

### D. Suppléments payants (NE DOIVENT PAS être oubliés)
- Ajouter **Cheddar / Champignons / Œuf / Cornichon / Viande supplémentaire** → chacun sur sa ligne
  `+ <nom>` sous le produit. ⚠️ Si un supplément payant n'apparaît pas en cuisine → BUG.
- La **viande supplémentaire** doit être facturée +2,50 € (côté client) ET affichée `+ Viande supplémentaire` (cuisine, sans prix).

### E. Menu / formule
- Prendre le **menu (frites + boisson)** → ligne `MENU : <sauce frites>` sous le produit.

### F. Crudités
- Retirer/ajouter Salade/Tomate/Oignon → le bloc `STO` reflète exactement (ex. sans tomate = `SO`).

## PARITÉ OBLIGATOIRE
Pour 3 produits au moins : comparer **écran KDS** vs **ticket cuisine imprimé** → doivent être
IDENTIQUES (même symboles, mêmes suppléments). Et **commande borne == commande caisse** (même produit
→ même rendu).

## AFFICHAGE COMPLET
- L'écran cuisine doit montrer **TOUTES** les commandes actives (jusqu'au plafond serveur ~40-50, avec
  bandeau « liste pleine » au-delà). Une grosse commande (beaucoup de produits) prend plus de hauteur
  sans masquer les autres.

## À RAPPORTER (photos obligatoires)
1. KDS avec plusieurs commandes (multi-viandes + recette-fixe + supplément + menu visibles).
2. Ticket cuisine imprimé d'une commande composée (Terminator 2 viandes + supplément + menu).
3. Preuve parité : même produit → KDS == ticket, et borne == caisse.
4. **Tout produit dont le symbole est faux/vide/oublié → photo + nom produit + composition exacte.**
   → me le remonter : soit c'est un nouveau produit à ajouter à la table de symboles, soit un vrai bug.

> Rappel : si un symbole est FAUX (pas juste « nouveau »), c'est probablement l'ANCIEN code (VPS pas
> redéployé). Re-vérifier `git HEAD` du VPS == `b27115b35` (ou +) avant de conclure à un bug.
