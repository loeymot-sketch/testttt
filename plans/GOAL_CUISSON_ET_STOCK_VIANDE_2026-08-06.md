# GOAL — BANDEAU « CUISSON » + CONSOMMATION VIANDE → STOCK
**Date** : 2026-08-06 · **Owner** : Kossay
**Backend** : `foodking-web/web/testttt` · KDS `resources/js/helpers/kdsSymbolic.js` + `app/Services/Hardware/KitchenTicketSymbolicFormatter.php`

---

## 0. LA DEMANDE, REFORMULÉE

> **Mission 1 — le cuisinier ne doit plus lire la commande pour savoir quoi cuire.**
> Au-dessus du numéro de commande, sur l'**écran cuisine** ET le **ticket cuisine**, un bandeau
> **CUISSON** agrège TOUTES les viandes de la commande entière, en notation symbolique.
> Il met tout à cuire, puis prépare pains/sauces/crudités pendant la cuisson.
>
> **Mission 2 — brancher ça sur le stock.**
> Les mêmes portions alimentent la consommation réelle du jour (« aujourd'hui on a sorti
> X steaks »), pour calculer charges et consommations à partir des poids réels.

### Notation (déjà en place dans le dépôt — `MEAT_TABLE`)
`K` = viande hachée · `P` = poulet · `Mex` = Mexicanos · `Nug` = Nuggets ·
`Tender` = Tenders · `Frec` = Fricadelle · `Cordon` = Cordon Bleu.
**Agrégation sur toute la commande** : `9K 3P 2Cordon`, une seule ligne.

### Règle de portion, telle que je la comprends
- **Portion complète = 2 pièces.** Un sandwich à **UNE** viande reçoit la portion complète.
  → Cayenne en hachée = **2K** · Tacos M en hachée = **2K** · Galette en poulet = **2P**
- Un produit à **DEUX** viandes reçoit **1 pièce de chacune** (deux demi-portions).
  → Méga poulet + hachée = **1P 1K** · Méga tout hachée = **2K** · Tacos L = idem
- **Supplément viande** = **portion complète** (2 pièces) — à confirmer, voir §2.
- **Burgers** : autant de `K` que de steaks dans la recette (1, 2 ou 3 selon la taille).

---

## 1. CE QUE LA BASE SAIT DÉJÀ (vérifié, `file:line` à l'appui)

### Produits à CHOIX de viande — le client choisit, donc la viande est connue à la commande

| Produit | Attributs viande | Portion CUISSON |
|---|---|---|
| **Cayenne** #22 | Viande 1 (3 choix) | 2 × la viande choisie |
| **Méga** #104 | Viande 1 + Viande 2 (7 choix ch.) | 1 + 1 |
| **Terminator** #105 | Viande 1 + Viande 2 | 1 + 1 |
| **Tacos M** #26 | Viande 1 | 2 × |
| **Tacos L** #97 | Viande 1 + Viande 2 | 1 + 1 |
| **Galette Normale** #23 | Viande 1 | 2 × |
| **Galette Cayenne** #24 | Viande 1 (8 choix) | 2 × |
| **Bol Frites** #41 | Viande 1 | ❓ voir §2 |
| **Bol Riz** #45 | Viande 1 | ❓ voir §2 |

Les 7 viandes au choix : Poulet mariné · Mexicanos · Cordon Bleu · Viande Hachée · Nuggets ·
Tenders · Fricadelle. Plus **« Mixte (hachée + poulet) »** sur Cayenne et Galette Cayenne.

### Matière première déjà en base (`raw_materials`)
**« Viande hachée » : `piece_weight_g` = 75 g/pièce.** C'est l'ancrage du volet stock.
« Poulet », « Cordon bleu » existent aussi mais **sans poids**.
⚠️ Aucune table de recette produit → matière : c'est ce que la mission 2 doit créer.

---

## 2. RECETTES FIXES — RÉSOLU (owner 2026-08-06, vérifié en base)

L'owner a donné les 9 recettes et demandé vérification plutôt que d'être cru sur parole.
**La colonne `description` de la table `items` confirme chacune d'elles :**

| Produit | Description en base | CUISSON |
|---|---|---|
| Cheese Burger #98 | « Steak, cheddar… » | **1K** |
| Double Cheese #99 | « 2 steaks, 2 cheddars… » | **2K** |
| Grill Burger #102 | « 2 steaks, 2 cheddars, jambon de dinde… » | **2K** |
| Big Burger #101 | « 3 steaks, 3 cheddars, 2 jambons de dinde… » | **3K** |
| Fish Burger #100 | « Poisson pané, cheddar… » | **1 Poi** |
| Chicken Burger #38 | (nom) | **1 Chick** |
| Suprême #103 | « Steak haché, cordon bleu… » | **1K + 1 Cordon** |
| Menu Enfant Nuggets #40 | « 6 nuggets, frites et Capri-Sun » | **6 Nug + 1F** |
| Menu Enfant Chicken #106 | « Chicken burger, frites et Capri-Sun » | **1 Chick + 1F** |

Jambon de dinde et cheddar sont volontairement absents : ils ne passent pas à la plancha, et
le bandeau ne doit dire que ce qu'il faut CUIRE.

**Confirmé par l'owner, déjà conforme à l'implémentation** : les **bols** prennent la portion
complète (2 pièces) ; **« Mixte (hachée + poulet) »** vaut **1K + 1P** ; le **supplément
viande** vaut une portion complète.

**Ajout owner — les FRITES** : « le nombre de menu tu mets 5F », « une grande frite c'est
automatiquement 2F ». Chaque menu apporte 1F, une frite vendue seule 1F, une grande 2F, et la
frite d'un menu enfant est déjà dans sa recette (jamais comptée deux fois).

### ⚠️ Un écart mineur à trancher
Vous avez dit que le **Big Burger** contient « une jambon » ; la base dit **2 jambons de
dinde**. Sans effet sur la cuisson (le jambon ne va pas à la plancha), mais cela comptera le
jour où le stock suivra le jambon.

## 2bis. CE QUI MANQUE ENCORE — mission 2 (stock)

Seule la viande hachée a un poids (**75 g/pièce**). Il me faut le poids unitaire (ou de
portion) de : **Poulet mariné · Mexicanos · Cordon Bleu · Nuggets · Tenders · Fricadelle ·
Chicken burger · Poisson pané**, ainsi que le poids d'une **portion de frites**.
Le moteur compte déjà les pièces : il ne manque que la conversion en grammes.

---

## 3. PLAN D'EXÉCUTION

```
 W1  MOTEUR DE PORTIONS — une matrice DÉCLARATIVE unique (PHP) + sa jumelle JS,
     produisant depuis un composition_snapshot la liste { viande => nb de pièces }.
     Source de vérité UNIQUE : le ticket, l'écran et le stock la partagent.
     ⚠️ Leçon de la campagne précédente : jamais deux moitiés qui divergent.

 W2  BANDEAU CUISSON — rendu au-dessus du numéro de commande :
       · écran KDS (composant Vue)
       · ticket cuisine ESC/POS (formatter PHP)
     Agrégation sur TOUTE la commande, une seule ligne : « CUISSON  9K 3P 2Cordon ».

 W3  SENTINELLES COMPORTEMENTALES — la matrice est testée en APPELANT le moteur,
     produit par produit, et chaque test doit être prouvé capable de ROUGIR.
     Parité PHP↔JS verrouillée (le dépôt a déjà un test de parité pour les symboles).

 W4  STOCK — table de recette viande + décrément à la vente, alimenté par LE MÊME moteur.
     Rapport du jour : pièces sorties par viande, converties en grammes via raw_materials.

 W5  VÉRIFICATION — commande réelle bout-en-bout : écran, ticket, et ligne de stock.
```

### Ce que je peux faire SANS vos réponses
W1 (structure), W2 (rendu), W3 (sentinelles) sur les **9 produits à choix de viande**, dont la
recette est déjà connue de la base. Les produits du §2 seront déclarés `INCONNU` dans la matrice
et **signalés explicitement** sur le ticket (« ? » plutôt qu'un chiffre inventé) — jamais une
valeur devinée : une portion fausse fait cuire la mauvaise quantité et fausse le stock.
