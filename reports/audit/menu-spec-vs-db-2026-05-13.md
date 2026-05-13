# Audit Menu — DB actuel vs Spec validée 2026-05-13

**Date** : 2026-05-13 16:00 CEST
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**Source spec** : owner message du 2026-05-13 (validation complète menu)
**Source DB** : `mysql foodking` (post menu-reset 2026-05-13)

---

## §1 Synthèse — drift sévérité

**38 drifts détectés** vs spec validée :
- **8 P0** financial / produits manquants (impact CA + UX critique)
- **17 P1** structure / variations / sauces / suppléments
- **13 P2** organisation visuelle / wording

Le menu actuel = menu RESET 2026-05-13 mais ne reflète PAS la nouvelle spec owner. Il faut un **deuxième cycle menu-reset** pour aligner.

---

## §2 Catégories — comparaison

### Spec validée (8 catégories logiques)
1. Sandwiches (Cayenne + Classique avec leurs Big variantes)
2. Tacos
3. **Burgers** ← NOUVEAU
4. Bowls
5. Frites seules
6. **Menu enfant** ← NOUVEAU
7. Boissons
8. Desserts
+ Sauces & Suppléments transverses

### Current DB (10 actives)
| ID | Nom DB | Spec | Drift |
|----|--------|------|-------|
| 344 | Sandwich Cayenne | Sandwiches (sous-bloc) | **P1** — devrait fusionner avec Classique sous "Sandwiches" |
| 345 | Galette | (PAS catégorie — format de wizard) | **P1** — galette = option de format pain/galette, pas catégorie |
| 346 | Sandwich Classique | Sandwiches (sous-bloc) | **P1** — fusionner avec Cayenne |
| 306 | Tacos | Tacos | ✓ |
| — | (manquant) | **Burgers** | **P0** — NOUVELLE catégorie à créer |
| 347 | Bols Gourmands | Bowls | ⚠️ — renommer simple "Bowls" + restructurer items |
| 348 | Frites | Frites seules | ✓ (renommer "Frites seules" cosmétique) |
| — | (manquant) | **Menu enfant** | **P0** — NOUVELLE catégorie à créer |
| 318 | Suppléments | (transverse, pas affiché catégorie) | ⚠️ — laisser visible kiosk ? owner choix UX |
| 316 | Desserts | Desserts | ✓ |
| 317 | Boissons | Boissons | ✓ |
| 315 | Frites & Accompagnements (hidden) | (internal addons) | ✓ |

---

## §3 Items — comparaison prix

| Item DB | DB prix | Spec prix | Drift |
|---------|---------|-----------|-------|
| Sandwich Cayenne (id 474) | **7.00€** | **7.50€** | **P0 +0.50€** |
| (manquant) | — | **Big Cayenne 9.50€** | **P0 — produit manquant** |
| Galette Normale (id 475) | 6.50€ | (n'existe pas — fusion via format) | **P1 — supprimer ou ré-architecturer** |
| Galette Cayenne (id 476) | 7.00€ | (n'existe pas — fusion via format) | **P1 — supprimer ou ré-architecturer** |
| Sandwich Classique (id 477) | **6.50€** | **7.00€** | **P0 +0.50€** |
| (manquant) | — | **Big Classique 9.00€** | **P0 — produit manquant** |
| Tacos (id 478) | **8.50€** | **Tacos M 6.90€** | **P0 −1.60€ + nom** |
| Big Tacos (id 479) | **11.50€** | **Tacos L 7.90€** | **P0 −3.60€ + nom** |
| (manquant) | — | **Chicken Burger 6.90€** | **P0 — produit manquant** |
| (manquant) | — | **Big Chicken / Double Chicken 8.90€** | **P0 — produit manquant** |
| Bol Curry (id 480) | 10.50€ | (rejoint Bowl Frites/Riz 8.90€) | **P0 — restructurer + −1.60€** |
| Bol Tandoori (481) | 10.50€ | (idem) | **P0** |
| Bol Mariné (482) | 10.50€ | (idem) | **P0** |
| Bol Crousti (483) | 10.50€ | (idem) | **P0** |
| Bol Gratiné (484) | 12.50€ | (option gratiné +2.00€ sur bol normal) | **P0 — devient option, pas item** |
| (manquant) | — | **Bowl Frites 8.90€** | **P0 — nouveau produit base** |
| (manquant) | — | **Bowl Riz 8.90€** | **P0 — nouveau produit base** |
| Petite Frites (485) | 2.50€ | 2.50€ | ✓ MATCH |
| Grande Frites (486) | 4.00€ | 4.00€ | ✓ MATCH |
| (manquant) | — | **Menu Nuggets 6.00€** | **P0 — produit manquant** |
| Glace (404) | 3.80€ | 3.80€ | ✓ |
| Tarte Daim (405) | 3.80€ | 3.80€ | ✓ |
| Tiramisu (406) | 3.80€ | 3.80€ | ✓ |
| Coca-Cola 33cl (407) | 1.50€ | 1.50€ générique | ✓ |
| Coca-Cola Zero (408) | 1.50€ | 1.50€ | ✓ |
| Fanta Orange (409) | 1.50€ | 1.50€ | ✓ |
| Sprite (410) | 1.50€ | 1.50€ | ✓ |
| Oasis Tropical (411) | 1.50€ | 1.50€ | ✓ |
| Orangina (412) | 1.50€ | 1.50€ | ✓ |
| Eau Plate 50cl (413) | 1.00€ | (pas explicite spec) | ⚠️ owner ack |
| Capri-Sun (414) | 1.50€ | (inclus Menu nuggets) | ⚠️ — peut rester boisson autonome |
| Menu Frites+Boisson (360) | **3.00€** | **+2.50€** | **P0 −0.50€ menu option** |
| Frites Seules addon (361) | 2.00€ | (interne, pas affiché) | ✓ |
| Boisson Seule addon (362) | 2.00€ | (interne) | ✓ |

**P0 financiers totaux** : 13 items prix-drift OU manquants.

---

## §4 Viandes (current 4 vs spec 4)

| Spec | DB current | Drift |
|------|-----------|-------|
| Poulet mariné | **Poulet classic** | **P1 — RENAME** |
| Poulet curry | Poulet curry | ✓ |
| Poulet tandoori | Poulet tandoori | ✓ |
| Poulet crispy | Poulet crispy | ✓ |

---

## §5 Sauces (current 13 vs spec 11)

| Spec | Current | Drift |
|------|---------|-------|
| Sauce blanche | Blanche | ✓ |
| **Sauce fromagère maison** | Fromagère (rename) | **P1 RENAME explicite** |
| Mayonnaise | Mayonnaise | ✓ |
| Ketchup | Ketchup | ✓ |
| Algérienne | Algérienne | ✓ |
| Samouraï | Samouraï | ✓ |
| Curry | Curry | ✓ |
| Andalouse | Andalouse | ✓ |
| Harissa | Harissa | ✓ |
| Hannibal | Hannibal | ✓ |
| **Spicy** | (n'existe pas explicite — "Pimentée" proche) | **P1 — RENAME Pimentée → Spicy OU ADD Spicy** |
| (n'est PAS dans spec) | **Tandoori** | **P1 REMOVE — c'est une viande, pas une sauce** |
| (n'est PAS dans spec) | **Cayenne** | **P1 REMOVE — c'est un sandwich, pas une sauce** |

**Bowls sauces clé** : spec dit "fromagère + spicy" inclus pour Bowls. Current bols utilise attribute "Sauce bol" avec 65 variations (mass-cloned per item) — devrait n'avoir que Spicy + Fromagère par défaut.

---

## §6 Suppléments (current 10 vs spec 9)

| Spec | Current | Drift |
|------|---------|-------|
| Oignon frais | **Oignons frits** | **P1 — RENAME** (frits ≠ frais ; cuisinés vs cru) |
| Champignons | Champignons | ✓ |
| Jambon | Jambon | ✓ |
| Cheddar | Cheddar | ✓ |
| Raclette | Raclette | ✓ |
| Emmental | Emmental | ✓ |
| **Boursin** | (manquant) | **P1 — ADD Boursin** |
| Œuf | Œuf | ✓ |
| Légumes sautés | Légumes sautés | ✓ |
| (PAS dans spec) | **Bacon** | **P1 REMOVE Bacon** |
| (PAS dans spec) | **Boule gratinée** | ⚠️ — cohérent comme supp spécifique bols, sinon REMOVE |

**Prix supplément** : DB 1.00€ vs **spec 0.90€** → **P1 −0.10€ × 10 supp** (rebrand kiosk light pricing).

---

## §7 Wizard / Architecture produit

### Sandwiches
- **Spec** : 4 produits visibles (Cayenne, Big Cayenne, Classique, Big Classique) avec wizard composant : Format(pain/galette) + Viande(1 ou 2 selon Big) + Sauce + Suppléments + Menu+2.50€
- **Current** : Galette est une CATÉGORIE séparée + manque les Big variants → **MAJOR P0**

### Tacos
- **Spec** : 2 produits (Tacos M = 1 viande, Tacos L = 2 viandes) avec sauce fromagère maison incluse + Menu +2.50€
- **Current** : Tacos (8.50€) + Big Tacos (11.50€) — noms + prix différents → **P0**

### Burgers
- **Spec** : 2 produits (Chicken Burger 6.90€, Big Chicken 8.90€) avec pain burger + viande croustillante + sauce au choix + Menu +2.50€
- **Current** : **CATÉGORIE ABSENTE** → **P0**

### Bowls
- **Spec** : 2 bases (Bowl Frites 8.90€, Bowl Riz 8.90€) + option Gratiné +2.00€ + viande + sauce(fromagère+spicy) + suppléments
- **Current** : 5 bols (4 noms par viande à 10.50€ + Gratiné item à 12.50€) → **MAJOR P0 restructure**

### Frites seules
- **Spec** : Petite 2.50€, Grande 4.00€ (pas de Cheddar/Cheddar+Oignons en spec)
- **Current** : composer profile "Style frites" Nature/+Cheddar/+Cheddar+Oignons — **P2 retire option style** ? OWNER décide.

### Menu enfant
- **Spec** : 6 nuggets + frites + Capri-Sun = 6.00€
- **Current** : **CATÉGORIE ABSENTE** → **P0**

---

## §8 Composer profiles

### Spec wizard standard sandwich/tacos/burger
1. Format (pain/galette) — sandwiches/burgers uniquement, **pour tacos = tortilla par défaut**
2. Viande(s) selon produit (1 normal, 2 Big)
3. Sauce (fromagère maison pour Cayenne/Tacos/Bowls par défaut OR libre pour Classique/Burger)
4. Crudités (salade/tomate/oignon — INCLUSES)
5. Suppléments (0.90€ each)
6. Option Menu +2.50€ (frites + boisson)
7. Récap

### Current
- 7 composer profiles published (5 bols + 2 frites)
- Sandwich/Galette/Classique/Tacos utilisent `wizard_template='sandwich'` ou `'tacos'` (legacy template, pas composer profile)
- Bowls + Frites utilisent composer profile

**DRIFT P1** : devrait migrer **tous** les produits vers composer_profile architecture (cohérence + extensibilité). Actuellement hybride.

---

## §9 Tableau de drifts consolidé

| # | Catégorie | Sev | Drift | Action |
|---|-----------|-----|-------|--------|
| 1 | Sandwich Cayenne prix | P0 | 7.00 → 7.50€ | UPDATE items SET price=7.50 WHERE id=474 |
| 2 | Big Cayenne missing | P0 | NEW item 9.50€ | INSERT + composer |
| 3 | Sandwich Classique prix | P0 | 6.50 → 7.00€ | UPDATE id=477 |
| 4 | Big Classique missing | P0 | NEW item 9.00€ | INSERT + composer |
| 5 | Galette catégorie | P1 | Catégorie → option format | Migrate items Galette → option dans wizard sandwich |
| 6 | Tacos M renom + prix | P0 | "Tacos" 8.50 → "Tacos M" 6.90 | UPDATE id=478 |
| 7 | Tacos L renom + prix | P0 | "Big Tacos" 11.50 → "Tacos L" 7.90 | UPDATE id=479 |
| 8 | Chicken Burger missing | P0 | NEW category + 2 items | Major addition |
| 9 | Big Chicken missing | P0 | NEW item 8.90€ | (suite #8) |
| 10 | Bowl Frites missing | P0 | NEW item 8.90€ | INSERT |
| 11 | Bowl Riz missing | P0 | NEW item 8.90€ | INSERT |
| 12 | 5 bowls anciens | P0 | Restructure 5→2 + gratiné option | Major refactor |
| 13 | Menu enfant Nuggets | P0 | NEW catégorie + item 6.00€ | Major addition |
| 14 | Menu addon prix | P0 | 3.00 → 2.50€ | UPDATE id=360 |
| 15 | Supplément prix | P1 | 1.00 → 0.90€ × 10 | UPDATE items WHERE category=318 |
| 16 | Viande "Poulet classic" | P1 | RENAME "Poulet mariné" | UPDATE item_variations |
| 17 | Sauce Fromagère | P1 | RENAME "Sauce fromagère maison" | UPDATE name |
| 18 | Sauce Pimentée | P1 | RENAME → "Spicy" (ou supp) | UPDATE / DELETE |
| 19 | Sauce Tandoori | P1 | DELETE (c'est viande) | DELETE variation |
| 20 | Sauce Cayenne | P1 | DELETE (c'est sandwich) | DELETE variation |
| 21 | Supp Boursin missing | P1 | INSERT | INSERT item cat 318 |
| 22 | Supp Bacon | P1 | DELETE (pas dans spec) | DELETE id=468 |
| 23 | Supp Oignons frits | P1 | RENAME "Oignon frais" | UPDATE id=471 |
| 24 | Bowls sauces réduction | P1 | Sauce bol attribut → Fromagère + Spicy par défaut | Refactor attr |
| 25 | Crudités INCLUSES | P2 | spec dit salade+tomate+oignon inclus dans sandwich | Verify wizard ne fait pas payer crudités |
| 26 | Frites Cheddar option | P2 | spec n'a pas Cheddar/Oignons → owner UX choix | Defer owner |
| 27 | Cat "Suppléments" visible kiosk | P2 | spec dit "transverse" | UX choix : laisser ou cacher |
| 28 | "Sandwich Cayenne" + "Sandwich Classique" fusion | P1 | Une seule cat "Sandwiches" | Architecture migration |
| 29 | wizard_template hybride | P1 | Migrer tous vers composer_profile | Architecture refactor |
| 30 | Boisson "Boisson" générique | P2 | spec: 1.50€ générique | OK — current 8 boissons OK |
| 31-38 | Affichage 4 pages kiosk | P2 | spec UX 4 pages | UI implementation choice |

---

## §10 Recommandation owner

### Option A — Menu-reset v2 complet (recommandé)
Script artisan `menu:reset-v2-2026-05-13` qui :
1. Soft-delete tous les items actuels
2. Crée 8 catégories alignées (Sandwiches fusionné + Burgers + Menu enfant)
3. Crée 14 nouveaux items prix spec
4. Recompose les composer profiles (sandwich/tacos/burger/bowl/enfant/frites)
5. Met à jour sauces + supp prix + viande "Poulet mariné"
6. Fire CatalogChanged events pour sync KDS/Kiosk/POS

**Impact** : prod-grade alignement avec spec mais beaucoup de migrations + risque casse historique (orders historiques pointent items soft-deleted).

### Option B — Heal-light incrémental (rapide)
Apply uniquement les P0 prix-drifts (1-14) via UPDATE simples :
1. Sandwich Cayenne 7→7.50, Classique 6.50→7
2. Tacos rename + prix (mais devra créer Tacos L item séparé)
3. ADD Big Cayenne, Big Classique, Bowl Frites, Bowl Riz, Menu Nuggets, Chicken Burger, Big Chicken
4. Menu addon 3→2.50
5. DELETE old Bowls (Curry/Tandoori/Mariné/Crousti) ou les masquer via channels='[]'

**Impact** : moins propre architecture, mais ship V1 plus vite.

### Option C — Spec-as-input pour PROCHAIN cycle
Marquer le menu actuel comme V1-shippable + créer plan `MENU_V2_2026-05-XX` pour cycle suivant.

---

## §11 Frozen-zone respect

Cette analyse n'a touché à AUCUN fichier. Pour appliquer les drifts :
- ❌ NE PAS modifier `public/js/pos-wizard.js` (frozen) — utiliser composer_profile pour bols/frites/nouvelles cats
- ❌ NE PAS modifier `KioskWizardComponent.vue` — utiliser composer_profile pour wizard custom
- ✓ DB updates OK (data layer)
- ✓ `config/menu.php`, `mobile/data/menu.js` OK
- ✓ Nouveaux composer profiles OK (data)

---

## §12 RESUME_TOKEN_MENU_AUDIT_VS_SPEC_20260513-1600
