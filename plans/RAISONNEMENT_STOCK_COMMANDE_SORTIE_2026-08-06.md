# STOCK ↔ COMMANDE ↔ SORTIE — raisonnement complet
**2026-08-06** · portée : V1 LOCAL Le Cayenne, `branch_id=1` · **diagnostic + architecture, aucun correctif appliqué**

---

## 0. LE RÉSULTAT EN UNE PHRASE

Le moteur de consommation matière **tourne déjà** (694 mouvements de vente) mais il calcule à
partir de la **fiche produit** au lieu du **choix réel du client**. Il produit donc des chiffres
faux avec l'autorité d'un chiffre juste — ce qui est plus dangereux que pas de chiffre du tout.

---

## 1. CE QUI EXISTE (vérifié en base, pas supposé)

```
   ACHAT ──────► raw_materials (13)  ──────► RawMaterialStockService
                 + raw_material_movements (954 : 694 sale, 258 reversal, 2 purchase)

   VENTE ──────► RawMaterialConsumptionService ──► recipe_lines (111)
                 lit composition_snapshot (NF525)     104 par PRODUIT · 7 par GROUPE d'extra
                                                      0 par VARIATION  ◄── LA FAILLE

   SORTIE ─────► stock_outflows (repas personnel / pertes)   1 ligne
   HORS VENTE

   STOCK ──────► stock_levels polymorphe                     3 lignes sur 114 articles
   À L'UNITÉ      (Menu Frites+Boisson · Frites Seules · Coca 33cl)
```

L'architecture est bonne : couche additive, idempotente sur `(order_item, matière)`, rejouable,
n'écrit rien dans la chaîne fiscale. **Le problème n'est pas la plomberie, c'est la donnée.**

---

## 2. LA FAILLE CENTRALE — la recette ignore ce que le client a choisi

Neuf produits laissent le client **choisir sa viande**. La recette, elle, est attachée au
**produit**, pas à la variation. Il y a **zéro ligne de recette par variation**. Donc la viande
consommée est toujours la même, quel que soit le choix.

### Preuves sur des commandes réelles

| Commande réelle | Viande SCELLÉE (NF525) | Matière décrémentée |
|---|---|---|
| `order_item #5729` Cayenne | **Mixte (hachée + poulet)** | Poulet −200 g · **hachée 0 g** |
| `order_item #5917` Cayenne | **Poulet mariné** | Poulet −200 g |
| `order_item #5849` Méga | **Tenders + Cordon Bleu** | ❌ **aucune viande** |

Le Méga, le Terminator, la Galette Cayenne et la Galette Normale n'ont **aucune viande dans leur
recette** : ce sont des sandwichs à viande qui consomment zéro viande.

### Et les quantités fixes sont fausses aussi

| Produit | Recette actuelle | Réalité (description en base + owner) |
|---|---|---|
| Big Burger | hachée **×75 g** = 1 steak | **3 steaks** = 225 g |
| Double Cheese | hachée **×75 g** = 1 steak | **2 steaks** = 150 g |
| Grill Burger | hachée ×75 g | **2 steaks** = 150 g |
| Suprême | hachée ×75 + **Poulet ×0** | 1 steak + 1 cordon bleu |

`Poulet ×0` est une ligne à quantité nulle : la recette a été écrite avec un bouchon jamais rempli.

---

## 3. L'ÉCART CHIFFRÉ — 30 jours, 371 lignes de commande

| Ce qui est SORTI de la cuisine (snapshots scellés) | Ce que le stock a décrémenté |
|---|---|
| **69 steaks hachés** = 5 175 g | Viande hachée **3 900 g** → **−25 %** |
| **136 portions de frites** | Portion frites **6** → **−96 %** |
| **20 cordons bleus** | Cordon bleu **6** → **−70 %** |
| 168 poulet · 68 Mexicanos · 53 Tenders · 49 Nuggets · 6 Fricadelle | Poulet 10 200 g, réparti selon la fiche produit — pas selon les pièces réelles |
| 94 pièces de supplément viande non nommé | traité en hachée forfaitaire |

**176 pièces de viande vendues n'ont AUCUNE matière où être décomptées** : Mexicanos, Tenders,
Nuggets, Fricadelle, chicken burger et poisson pané sont **absents de `raw_materials`**.

Les 10 200 g de poulet ne viennent pas des poulets vendus : ils viennent du forfait 200 g posé
sur chaque Cayenne et Chicken Burger — y compris ceux commandés en viande hachée.

---

## 4. POURQUOI CELA ARRIVE — et la règle qui l'empêche

Deux modèles calculent « combien de viande » et ils ne se parlent pas :

- la **fiche produit** (`recipe_lines`) — ignore le choix du client ;
- le **snapshot scellé** (`composition_snapshot`) — le sait exactement.

Le bandeau CUISSON lit le second. Le stock lit le premier. Résultat : **la cuisine cuit 2 steaks
pendant que le stock retire 200 g de poulet.** Tant que deux modèles coexistent, ils divergeront —
c'est le défaut qui revient le plus souvent dans ce projet.

> **RÈGLE : chaque matière a UN SEUL propriétaire de sa quantité.**

| Décidé par | Autorité | Matières |
|---|---|---|
| **le CLIENT** (scellé au snapshot) | `MeatPortionCalculator` | viandes, frites de menu, suppléments |
| **la RECETTE** (invariable) | `raw_material_recipe_lines` | pain, galette, cheddar, jambon, salade, tomate, oignon, sauce |

Les lignes de viande doivent alors être **retirées** des recettes produit, sinon on compte deux
fois. Le moteur de portions devient la seule voix sur la viande — la même qui parle au cuisinier.

---

## 5. CE QUI MANQUE EN DONNÉES

1. **6 matières à créer** : Mexicanos · Tenders · Nuggets · Fricadelle · Chicken burger · Poisson pané.
2. **Les poids** : seule la viande hachée en a un (75 g). Il faut le poids unitaire de chaque
   viande ci-dessus **plus** poulet mariné, cordon bleu, et une portion de frites.
3. **Corriger 4 recettes** : Big Burger 3 steaks, Double Cheese 2, Grill Burger 2, Suprême
   (supprimer `Poulet ×0`).
4. **Les bols** (Bol Frites 14 ventes, Bol Riz 7) n'ont aucune recette.

---

## 6. LE MAILLON VRAIMENT MANQUANT — la boucle de contrôle

Aujourd'hui la chaîne s'arrête au **stock théorique**. Or un stock théorique qui n'est jamais
confronté au réel dérive en silence, indéfiniment : personne ne peut dire s'il a tort.

```
   ACHAT (entrée)  ─┐
                    ├─►  STOCK THÉORIQUE  ──┐
   VENTE (sortie)  ─┤                        │
   REPAS / PERTE   ─┘                        ├──►  ÉCART  ◄── LA VALEUR EST ICI
                                             │
   INVENTAIRE COMPTÉ (réel)  ────────────────┘        (gaspillage · portions trop
                                                       généreuses · vol · erreur de saisie)
```

`stock_outflows` (repas personnel / pertes) existe déjà et fait le troisième bras — mais avec
**1 seule ligne**, il n'est pas utilisé. Et il n'y a **aucun inventaire compté**, donc aucun écart,
donc aucun moyen de savoir que les 25 % de viande manquants sont un bug de calcul et non du vol.

**C'est l'écart qui a de la valeur, pas le chiffre théorique.**

---

## 7. ORDRE D'EXÉCUTION PROPOSÉ

```
 1. DONNÉES     créer les 6 matières + saisir les poids (owner)          ← bloquant
 2. RECETTES    retirer la viande des fiches produit, corriger les 4     ← change le food-cost
 3. BRANCHER    MeatPortionCalculator devient la source de la viande
                et des frites dans RawMaterialConsumptionService
 4. REJOUER     RawMaterialReplayConsumptionCommand existe déjà :
                rejeu à blanc, comparaison avant/après, puis application
 5. INVENTAIRE  écran de comptage + rapport d'écart théorique ↔ réel
 6. SORTIES     rendre le module repas/pertes réellement utilisé au quotidien
```

L'étape 2 modifie des chiffres de coût matière existants : **elle demande votre accord explicite**
avant d'être appliquée. Les étapes 1 et 5 sont additives et sans risque.
