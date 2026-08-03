# W4 — MAJ MENU apps client STANDALONE (mobile + web) → canon Le Cayenne

**Date** : 2026-06-26 · **Lentille** : 🧑 CLIENT · **Écriture autorisée** (owner).
**SSOT** : `database/seeders/OwnerMenuUpdate20260623Seeder.php`.
**Fichiers écrits** : `mobile/data/menu.js`, `/Users/1millnonstop/Downloads/web/data/menu.js`,
+ test `mobile/tests/menu.spec.js`. ⛔ Aucun wireup API. Frozen-zones intouchées.

Les 2 fichiers sont désormais **mirror exact** (31 produits / 9 catégories chacun ;
même valeurs ligne-à-ligne ; le web ajoute seulement `badge`/`PEPPER_CLUB`/`W_*`).

## Tableau AVANT → APRÈS

### Prix / noms corrigés
| Élément | AVANT | APRÈS (canon seeder) |
|---|---|---|
| Cat 1 | "Sandwich Cayenne" | **"Sandwichs"** |
| Cat 6 | "Bols Gourmands" | **"Bols"** |
| Sandwich Cayenne 7,00 | 7,00 | **Cayenne 7,40** |
| Tacos L | **8,90** | **7,90** |
| Chicken Burger | **6,90** | **4,90** |
| Desserts (Glace/Tarte/Tiramisu) | 3,80 | **3,50** |
| Boissons canettes (×6) | 1,50 | **1,90** (Eau 1,00 inchangée) |
| Formule menu | **3,00** | **2,50** |
| Supplément "Oignon frais" | Oignon frais | **"Oignons frits"** |
| Viandes | 4 poulet-only | **7 mixtes** (Mexicanos, Cordon Bleu, Viande Hachée, Nuggets, Tenders, Fricadelle, Poulet mariné) |
| Sauces | 11 | **12** (ajout Barbecue ; libellés alignés : "Spicy"→"Spicy maison", "Sauce fromagère maison"→"Fromagère maison") |
| Crudités | 4 (incl. Cornichon) | **3** (Salade/Tomate/Oignon) |

### Produits AJOUTÉS (manquants → présents, prix seeder)
| Produit | Cat | Prix |
|---|---|---|
| Suprême | Sandwichs | 7,00 |
| Méga (2 viandes) | Sandwichs | 8,00 |
| Terminator (2 viandes) | Sandwichs | 9,00 |
| Cheese Burger | Burgers | 6,00 |
| Double Cheese | Burgers | 7,00 |
| Fish Burger | Burgers | 6,00 |
| Big Burger | Burgers | 9,00 |
| Grill Burger | Burgers | 8,00 |
| Menu Enfant Burger (2e SKU) | Menu enfant | 4,90 |

### Produits / catégories SUPPRIMÉS (fantômes purgés)
| Supprimé | Raison (seeder) |
|---|---|
| Big Cayenne, Big Chicken | items 36/39 INACTIVE |
| Cat "Sandwich Classique" + Sandwich/Big Classique | cat3 masquée, items 25/37 INACTIVE |
| 8 "Bowl …" (Frites/Riz × 4 poulets) @ 8,90 | remplacés par 2 bols réels |
| Cat "Suppléments" + 9 items vendables (Cheddar…Champignons) | cat8 masquée (options wizard, pas produits) |
| Crudité "Cornichon" | hors GARNITURES canon |
| "Menu Nuggets" 6,00 (SKU unique) | remplacé par 2 SKU @ 4,90 |

### Conservés / transformés au canon
- **Galette** (cat 2) conservée (gate owner) ; Galette Normale 6,50 / Cayenne 7,00 — les 7 viandes + 12 sauces s'appliquent via les pools partagés.
- **Bols** : Bol Frites 7,90 + Bol Riz 7,90, **viande au choix parmi les 7** (`viandes:1`, plus de viande poulet-only fixe ; `bol_meat_fixed=null`). Bol Riz garde "Option gratiné" (suppléments bol).
- **Frites / Eau / Capri-Sun** : inchangés (réels au catalogue, non listés comme delta).

## « 0 produit inventé »
Chaque produit conservé/ajouté est présent dans `OwnerMenuUpdate20260623Seeder.php` :
Tacos M/L (l.96/102), 6 burgers (l.109-116), Cayenne/Suprême/Méga/Terminator (l.126-147),
Bol Frites/Riz (l.177/179), 2 Menu Enfant @4,90 (l.189/194), Desserts 3,50 (l.200),
boissons 1,90 (l.206). Viandes = `MEATS` l.55-58. Sauces = `SAUCES` l.61-65.
Crudités = `GARNITURES` l.68. Suppléments = `SUPPLEMENTS` l.71-75. Formule +2,50 l.157.
Aucun nom hors-seeder introduit.

## Checks (evidence)
- `node --check mobile/data/menu.js` → **MOBILE SYNTAX OK**
- `node --check /Users/1millnonstop/Downloads/web/data/menu.js` → **WEB SYNTAX OK**
- `node mobile/tests/menu.spec.js` (stub `window`, exécute l'IIFE des 2 fichiers, ~60 assertions canon) → **ALL CHECKS PASSED (0 failures)** sur MOBILE **et** WEB :
  IIFE run OK · 7 viandes exactes · 12 sauces · 3 crudités (Cornichon absent) · 9 suppléments 0,90 ·
  formule 2,50 · Tacos L 7,90 · Chicken 4,90 · 6 burgers · 2 bols (viande au choix) ·
  Desserts 3,50 · canettes 1,90 / Eau 1,00 · 2 SKU enfant 4,90 · Cayenne/Suprême/Méga/Terminator présents ·
  11 fantômes absents · 0 produit "Bowl …" · 0 item cat 8 · `priceFor(Tacos M + menu)=9,40`.
- Grep produits : Tacos M/L 6,90/7,90 OK · "8.90" → **0** dans les 2 fichiers · "Big Cayenne"/"Big Chicken"/"Bowl " → **0** ·
  les occurrences résiduelles "Sandwich Classique"/"Bols Gourmands"/"Oignon frais" sont **uniquement dans des commentaires** documentant la purge/rename (vérifié ligne par ligne).

## git diff --stat
- `mobile/data/menu.js` : **1 file changed, 204 insertions(+), 282 deletions(-)**
- `mobile/tests/menu.spec.js` : **untracked (nouveau)**
- frozen-zone (`pos-wizard.js` / `pos-wizard.css` / `admin-pos-v4.blade.php`) : **diff vide**
- `/Users/1millnonstop/Downloads/web/data/menu.js` (repo git séparé) : **1 file changed, 179 insertions(+), 209 deletions(-)**

## Note technique (limitation renderer standalone, data-only)
Le wizard standalone (mobile `screens-item-steps.jsx` / web `wizard-v2.jsx`) ne sait afficher
un **choix de viande** que via le template `tacos`/`sandwich` (étape VIANDES pilotée par
`item.viandes`), pas dans la branche `custom`. Pour honorer l'exigence owner « bols = viande
au choix parmi les 7 » **sans toucher au JSX** (hors scope), les 2 bols utilisent
`wizard_template:'tacos'` + `viandes:1` + `has_sauce:true` + `has_supplements:true`. Le bol
garde sauce + suppléments ; l'ancien parcours bol-spécifique (`has_bol_wizard`/drink addon)
est retiré au profit du choix-viande, plus important pour le client. `bol_meat_fixed` (qui
affichait "Poulet mariné" en dur) est supprimé. Symboles bol-only (`buildBolComposerProfile`)
retirés des exports ; aucun consommateur JSX ne les appelle (tous gardés par `item.has_bol_wizard`).

⛔ **NON committé** (gate owner). Reprise : `git add mobile/data/menu.js mobile/tests/menu.spec.js`
ici + `git add data/menu.js` dans `/Users/1millnonstop/Downloads/web/`.
