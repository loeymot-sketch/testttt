# 📋 CATALOGUE RAISONNÉ — Le Cayenne mobile app

> Owner-driven re-examination 2026-05-11 · branche `feature/mobile-app-le-cayenne-2026-05-10` · HEAD `a2dd65cda`
> Principe : **raisonnement humain par catégorie + par produit**, PAS copy-kiosk aveugle. Quand le kiosk a une bêtise, on la corrige aussi (LOCK plan owner-gate cleared).

---

## TL;DR

1. **Carte Le Cayenne** : 13 catégories, 47 produits réels (mobile dit 60 mais double 13 desserts + boissons + suppléments standalone — voir §1)
2. **Raisonnement wizard par catégorie** : appliqué via principes humains (§2)
3. **Bêtises kiosk identifiées** : 4 à corriger (§3) — owner gate cleared
4. **Drifts mobile actuels** : 12 à fixer post-raisonnement (§4)
5. **App flow end-to-end confirmé** : 13 écrans + 10 modals, walkthrough étape par étape (§5)
6. **Plan d'action** : sprint correction mobile + LOCK plan kiosk (§6)

---

## §1 — CARTE LE CAYENNE (SSOT confirmé via `config/menu.php`)

### 13 catégories

| ID | Slug | Nom | Description | wizard_template raisonné |
|----|------|-----|-------------|--------------------------|
| 1 | `nos-tacos` | Nos Tacos | Tacos viandes au choix | **tacos** : viandes(1-4 selon item) → sauce → crudités → suppléments → menu |
| 2 | `nos-sandwichs` | Nos Sandwichs | Sandwichs gourmands | **sandwich** : viandes(0-2 selon item) → sauce → crudités → suppléments → menu |
| 3 | `nos-burgers` | Nos Burgers | Burgers maison 100% frais | **burger** : sauce → crudités → suppléments → menu (viandes fixes par item) |
| 4 | `nos-assiettes` | Nos Assiettes | Assiettes complètes | **assiette** : sauce → suppléments (viandes fixes, frites incluses) |
| 5 | `ojja` | Ojja | Ojja traditionnelle | **simple** : sauce → suppléments (viandes fixes, frites incluses) |
| 6 | `omelettes` | Omelettes | Omelettes maison | **simple** : sauce → suppléments (frites incluses) |
| 7 | `nos-salades` | Nos Salades | Salades fraîches | **salade-simple** : sauce → suppléments → menu (PAS 6 steps comme kiosk!) |
| 8 | `chicken-tenders` | Poulet croustillant | Wings + Tenders | **snacking** : sauce → suppléments → menu |
| 9 | `nos-menus-enfants` | Menus Enfants | Pour les petits | **simple** : sauce → recap (pré-fixé, frites+Capri inclus) |
| 10 | `frites-accompagnements` | Frites & Accompagnements | Frites | **simple** : frites_style → sauce → recap |
| 11 | `nos-desserts` | Nos Desserts | Desserts | **direct** : ajout direct au panier |
| 12 | `nos-boissons` | Nos Boissons | Boissons | **direct** : ajout direct au panier |
| 13 | `supplements` | Suppléments | Suppléments seuls | **direct** : ajout direct (sauf sauce sup = step sauce + recap) |

### 47 produits réels (extraction config/menu.php)

#### 🌮 Nos Tacos (4 items) — viandes au choix selon taille
| Slug | Nom | Prix | Composition | Viandes choix |
|------|-----|------|-------------|---------------|
| tacos-m | Tacos M | 6,50 € | 1 viande au choix | 1 |
| tacos-l | Tacos L | 8,50 € | 2 viandes au choix | 2 |
| tacos-xl | Tacos XL | 10,50 € | 3 viandes au choix | 3 |
| tacos-xxl | Tacos XXL | 12,50 € | 4 viandes au choix | 4 |

#### 🥖 Nos Sandwichs (8 items) — viandes varient par produit
| Slug | Nom | Prix | Composition | Viandes choix | **Raisonnement** |
|------|-----|------|-------------|---------------|------------------|
| le-mega | Le Méga | 8,00 € | 2 viandes + Cheddar + Œuf | **2** | "2 viandes au choix" explicit |
| le-terminator | Le Terminator | 9,00 € | 2 viandes + 2 Cheddar + Œuf + Jambon | **2** | "2 viandes au choix" explicit |
| **le-supreme** | **Le Suprême** | 7,00 € | Steak + Cordon Bleu + Cheddar | **2** ⚠️ | **CORRECTION** : user a dit "2 viandes au choix, steak et cordon bleu par exemple". Mobile actuel `viandes=0` FAUX. Config dit `viandes=1` FAUX. Vérité = **2**. |
| le-cayenne | Le Cayenne | 7,00 € | Viande hachée ou chicken + mozza + cheddar + crème fraîche | **1** | "ou" = 1 viande au choix entre 2 options |
| sandwich-froid | Sandwich Froid | 4,50 € | Sandwich au Thon | **0** | Thon fixe, pas de choix viande |
| panini | Panini | 5,00 € | Thon-Jambon-Hachée-Chèvre-Saumon-Escalope | **1** | "ou" implicite (6 options 1 choix) |
| sandwich-pain | Sandwich Classique (Pain) | 6,50 € | 1 viande au choix | **1** | Explicit |
| sandwich-galette | Sandwich Classique (Galette) | 6,50 € | 1 viande au choix | **1** | Explicit |

#### 🍔 Nos Burgers (6 items) — viandes fixes, pas de choix viande
| Slug | Nom | Prix | Composition | Viandes choix |
|------|-----|------|-------------|---------------|
| burger-poulet | Burger Poulet | 6,00 € | Poulet pané + Cheddar | 0 (poulet fixe) |
| cheese-burger | Cheese Burger | 6,00 € | 1 Steak + 1 Cheddar | 0 (steak fixe) |
| fish-burger | Fish Burger | 6,00 € | Poisson pané + Cheddar | 0 (poisson fixe) |
| double-cheese | Double Cheese | 7,00 € | 2 Steaks + 2 Cheddars | 0 (steak fixe) |
| big-burger | Big Burger | 9,00 € | 3 Steaks + 3 Cheddar + 2 Jambon dinde | 0 (steak fixe) |
| grill-burger | Grill Burger | 8,00 € | 2 Steaks + 2 Cheddars + Jambon dinde | 0 (steak fixe) |

#### 🍽️ Nos Assiettes (4 items) — viandes fixes par nom, frites incluses
| Slug | Nom | Prix | Composition | Viandes choix |
|------|-----|------|-------------|---------------|
| assiette-poulet | Assiette Poulet | 12,50 € | Poulet (Nature/Curry/Paprika) + Frites + Salade + Pain + Sauce | 0 (poulet fixe) |
| assiette-kefta | Assiette Kefta | 12,50 € | Kefta + Frites + Salade + Pain + Sauce | 0 (kefta fixe) |
| assiette-merguez | Assiette Merguez | 12,50 € | Merguez + Frites + Salade + Pain + Sauce | 0 (merguez fixe) |
| assiette-mixte | Assiette Mixte | 14,50 € | Poulet + Kefta + Merguez + Frites + Salade + Pain + Sauce | 0 (3 viandes fixes) |

#### 🍳 Ojja (4 items) — viande fixe par nom, frites incluses
| Slug | Nom | Prix | Composition | Viandes choix |
|------|-----|------|-------------|---------------|
| ojja-boeuf | Ojja Bœuf | 13,50 € | Ojja + Bœuf + Frites + Pain | 0 |
| ojja-poulet | Ojja Poulet | 13,50 € | Ojja + Poulet + Frites + Pain | 0 |
| ojja-hachee | Ojja Viande Hachée | 13,50 € | Ojja + Hachée + Frites + Pain | 0 |
| ojja-merguez | Ojja Merguez | 13,50 € | Ojja + Merguez + Frites + Pain | 0 |

#### 🥚 Omelettes (3 items) — frites incluses
| Slug | Nom | Prix | Composition |
|------|-----|------|-------------|
| omelette-nature | Omelette Nature | 7,50 € | Omelette + Frites + Pain |
| omelette-fromage | Omelette Fromage | 8,50 € | Omelette + Fromage + Frites + Pain |
| omelette-champi | Omelette Champignons Fromage | 9,50 € | Omelette + Champi + Fromage + Frites + Pain |

#### 🥗 Nos Salades (4 items) — composition fixe par nom de salade
| Slug | Nom | Prix | Composition |
|------|-----|------|-------------|
| salade-chevre | Salade Chèvre | 7,50 € | Laitue + Tomate + Chèvre + Croûtons + Vinaigrette + Maïs |
| salade-royale | Salade Royale | 7,50 € | Laitue + Tomate + Maïs + Poulet + Olives |
| salade-saumon | Salade Saumon | 7,50 € | Laitue + Tomate + Maïs + Saumon + Olives |
| salade-tunisienne | Salade Tunisienne | 7,50 € | Concombre + Tomate + Oignon + Poivrons + Thon + Olives + Huile d'Olive |

#### 🍗 Poulet croustillant (4 items) — sauce optionnelle
| Slug | Nom | Prix | Composition |
|------|-----|------|-------------|
| wings-6 | Ailes de poulet (6) | 6,00 € | 6 ailes |
| wings-12 | Ailes de poulet (12) | 10,50 € | 12 ailes |
| tenders-6 | Filets croustillants (6) | 7,50 € | 6 tenders |
| tenders-12 | Filets croustillants (12) | 13,50 € | 12 tenders |

#### 🧒 Menus Enfants (2 items) — sauce optionnelle, frites+capri inclus
| Slug | Nom | Prix | Composition |
|------|-----|------|-------------|
| menu-cheese-enfant | Menu Cheese Burger (Enfant) | 6,00 € | 1 steak + 1 cheddar + frites + Capri sun |
| menu-nuggets-enfant | Menu Nuggets (Enfant) | 6,00 € | 6 Nuggets + frites + Capri sun |

#### 🍟 Frites & Accompagnements (2 items) — taille déjà dans nom, style upgrade dispo
| Slug | Nom | Prix | Style upgrade dispo |
|------|-----|------|---------------------|
| frites-moyenne | Frites Moyenne | 2,50 € | Nature / Cheddar fondu +1€ / Cheddar+Oignons +1,50€ |
| frites-grande | Frites Grande | 4,00 € | Nature / Cheddar fondu +1€ / Cheddar+Oignons +1,50€ |

#### 🍰 Nos Desserts (3 items) — ajout direct
| Slug | Nom | Prix |
|------|-----|------|
| glace | Glace | 3,80 € |
| tarte-daim | Tarte Daim | 3,80 € |
| tiramisu | Tiramisu | 3,80 € |

#### 🥤 Nos Boissons (8 items) — ajout direct
| Slug | Nom | Prix |
|------|-----|------|
| coca | Coca-Cola 33cl | 1,50 € |
| coca-zero | Coca-Cola Zero 33cl | 1,50 € |
| fanta | Fanta Orange 33cl | 1,50 € |
| sprite | Sprite 33cl | 1,50 € |
| oasis | Oasis Tropical 33cl | 1,50 € |
| orangina | Orangina 33cl | 1,50 € |
| eau-plate | Eau Plate 50cl | 1,00 € |
| capri-sun | Capri-Sun | 1,50 € |

#### ➕ Suppléments (cat 13) — items vendus seuls
| Slug | Nom | Prix | Wizard |
|------|-----|------|--------|
| item-sauce-sup | Sauce supplémentaire | 0,50 € | step sauce + recap |
| item-fromage | Fromage supplémentaire | 1,00 € | direct |
| item-jambon | Jambon de dinde | 1,00 € | direct |
| item-boursin | Boursin | 1,00 € | direct |
| item-raclette | Fromage à raclette | 1,00 € | direct |
| item-oeuf | Œuf | 1,00 € | direct |
| item-galette | Galette pommes de terre | 1,00 € | direct |
| item-salade | Salade verte | 2,00 € | direct |

**Total : 47 produits-mère + 8 boissons + 8 suppléments standalone = 63 références au panier potentielles**.

---

## §2 — CATALOGUE RAISONNÉ — WIZARD LOGIQUE PAR CATÉGORIE

### Principe directeur

> **Logique humaine d'un client de fast-food** : "Je veux ce produit. Le système me demande UNIQUEMENT ce qui est variable (mes choix) et OPTIONNEL ce qui est optionnel. Pas de step obligatoire pour quelque chose que je vais probablement valider tel quel."

Règles :
- ✅ **Step obligatoire** : seulement si choix VARIE le produit (viandes pour Tacos)
- ✅ **Step optionnel skippable** : sauce, suppléments, formule menu
- ❌ **PAS de step pour quelque chose de fixe** (ex. salade composition déjà déterminée par le nom)
- ❌ **PAS de step "garnitures" pour une Salade** (la salade arrive déjà composée selon son nom)

### 🌮 Cat 1 — Nos Tacos
**Wizard** : `viandes (N selon taille) → sauce → crudités → suppléments → menu → [cascade si menu] → recap`

| Step | Obligatoire ? | Raisonnement |
|------|---------------|--------------|
| viandes | ✅ OBLIGATOIRE | 1-4 selon Tacos M/L/XL/XXL — c'est la variable principale |
| sauce | ✅ OBLIGATOIRE (1 gratuite) | Le tacos est mangé avec sauce, c'est culturel — 1 sauce gratuite + 0,50€/sauce additionnelle |
| crudités | ✅ OBLIGATOIRE (toggle) | Default ALL ON, user peut retirer ce qu'il veut pas — geste rapide |
| suppléments | ⚪ OPTIONNEL | Skippable (default empty) — Boursin, raclette, etc. |
| menu | ✅ OBLIGATOIRE (radio) | Tacos seul OU +3€ menu (frites+boisson) OU +2€ frites OU +2€ boisson |
| drink (cascade) | ✅ OBL si menu=full ou boisson | Choisir UNE boisson dans les 8 dispos |
| frites_style (cascade) | ✅ OBL si menu=full ou frites | Nature / Cheddar +1€ / Cheddar+Oignons +1,50€ |
| frites_sauce (cascade) | ✅ OBL si menu=full ou frites | 1 sauce gratuite + 0,50€ additionnelle pour les frites |
| recap | ✅ FINAL | Vue d'ensemble + qty + bouton "Ajouter au panier" |

**Mobile actuel** : ✅ correct, déjà aligné

### 🥖 Cat 2 — Nos Sandwichs
**Wizard** : `viandes (0-2 selon item) → sauce → crudités → suppléments → menu → cascade → recap`

| Item | viandes | Notes |
|------|---------|-------|
| Le Méga | **2** | "2 viandes au choix" + Cheddar + Œuf (fixes) |
| Le Terminator | **2** | "2 viandes au choix" + Cheddar×2 + Œuf + Jambon (fixes) |
| **Le Suprême** | **2** ⚠️ CORRECTION | Owner clarification : "2 viandes au choix, steak et cordon bleu par exemple" |
| Le Cayenne | **1** | "viande hachée ou chicken" = 1 viande parmi 2 options |
| Sandwich Froid | **0** | Thon fixe, pas de step viande — direct sauce → crudités |
| Panini | **1** | "Thon-Jambon-Hachée-Chèvre-Saumon-Escalope" = 1 viande au choix parmi 6 |
| Sandwich Classique Pain/Galette | **1** | "1 viande au choix" explicit |

**Mobile actuel drift** : Le Suprême `viandes=0` doit passer à `2` (P0)

### 🍔 Cat 3 — Nos Burgers
**Wizard** : `sauce → crudités → suppléments → menu → cascade → recap` (PAS de step viandes car composition fixe par nom)

**Mobile actuel** : ✅ correct

### 🍽️ Cat 4 — Nos Assiettes
**Wizard** : `sauce → suppléments → recap` (PAS de crudités ni menu — frites + salade DÉJÀ incluses)

**Raisonnement** : assiette = repas complet, le client veut juste sauce + suppléments optionnels.

**Mobile actuel** : ✅ correct (mais cf. §3 — note style cuisson Poulet)

### 🍳 Cat 5 — Ojja
**Wizard** : `sauce → suppléments → recap` (frites + pain DÉJÀ inclus, pas de crudités)

**Mobile actuel** : ✅ correct (wizard_template `omelette` = omelette+ojja équivalents)

### 🥚 Cat 6 — Omelettes
**Wizard** : `sauce → suppléments → recap`

**Mobile actuel** : ✅ correct

### 🥗 Cat 7 — Nos Salades ⚠️ KIOSK BUG À CORRIGER
**Wizard CORRECT** (= ce que je propose après owner re-cadrage 2026-05-11) :
`sauce (optionnel) → suppléments (optionnel) → menu (optionnel) → cascade si menu → recap`

| Step | Statut | Raisonnement |
|------|--------|--------------|
| ~~garnitures~~ | ❌ SUPPRIMER | Inutile : Salade Royale arrive avec Laitue+Tomate+Maïs+Poulet+Olives FIXES — pas de wizard |
| sauce | ⚪ OPTIONNEL (skip = Sans Sauce) | Vinaigrette de base existe ; certains préfèrent autre sauce |
| suppléments | ⚪ OPTIONNEL | Ex : +1€ Fromage de chèvre |
| menu | ⚪ OPTIONNEL | "Veux-tu rajouter frites OU boisson OU les deux ?" — proposition naturelle |
| cascade (drink/frites) | ✅ OBL si menu | Comme tacos |
| recap | ✅ FINAL | |

**Kiosk actuel** (`KW.vue:619-628`) : `garnitures → sauce → menu → frites_style → supplements → recap` = **6 steps** = trop, contradictoire (garnitures pour une salade qui arrive déjà composée n'a pas de sens)

**Mobile actuel** (`steps.jsx:88-92`) : `sauce → suppléments → recap` (3 steps) = ✅ proche du correct, manque juste le **step menu optionnel**

### 🍗 Cat 8 — Poulet croustillant (Wings/Tenders)
**Wizard** : `sauce (optionnelle) → suppléments → menu → cascade → recap`

**Mobile actuel** (`steps.jsx:93-98`) : `sauce → menu → suppléments → recap` (ordre OK)
**Kiosk** (`KW.vue:598-608`) : `sauce → menu → frites_style → supplements → recap`
**Drift** : mobile n'insère pas frites_style explicit dans snacking (mais cascade `menu=full|frites` insère drink+fritesStyle+fritesSauce → OK indirectement)

### 🧒 Cat 9 — Menus Enfants
**Wizard** : `sauce (optionnelle pour la viande) → recap` (frites + Capri-Sun FIXES déjà dans le menu)

**Raisonnement** : c'est un menu enfant pré-composé. Juste demander la sauce optionnelle pour le steak/nuggets, c'est tout. Pas de step menu (déjà un menu), pas de cascade frites.

**Mobile actuel** : ✅ correct (`omelette` template avec has_sauce=true post owner-gate D2)

### 🍟 Cat 10 — Frites & Accompagnements
**Wizard** : `frites_style (Nature/Cheddar/Cheddar+Oignons) → sauce (optionnelle) → recap`

**Mobile actuel** : ✅ correct (template `simple` avec has_frites_style true)

### 🍰 Cat 11 — Nos Desserts
**Wizard** : direct (juste qty + ajouter au panier)

**Mobile actuel** : ✅ correct

### 🥤 Cat 12 — Nos Boissons
**Wizard** : direct (juste qty + ajouter au panier)

**Mobile actuel** : ✅ correct

### ➕ Cat 13 — Suppléments
**Wizard** :
- Sauce supplémentaire (0,50€) → step sauce → recap
- Autres → direct ajout

**Mobile actuel** : ✅ correct

---

## §3 — BÊTISES KIOSK À CORRIGER (owner gate cleared)

### Bêtise #1 — Salades 6 steps (kiosk WRONG)
**Fichier** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:619-628` (FROZEN-ZONE)
**Bug** : `salade` template a 5 steps obligatoires + recap = 6 steps
**Fix** : aligner sur la version simplifiée du mobile (sauce optionnel + suppléments optionnel + menu optionnel)
**LOCK plan** : oui — required avant modification KioskWizardComponent
**Justification** : Owner a explicitement dit "il y a une bêtise sur Kiosk qu'il faudra la corriger aussi, les salades ça vient pas avec 5 étapes"

### Bêtise #2 — Le Suprême viandes (config + mobile WRONG)
**Fichiers** :
- `config/menu.php:218-227` — dit `viandes=1` mais commentaire dit "limit to 0 custom meats since it's fixed"
- `mobile/data/menu.js` — dit `viandes=0` (suit le commentaire)
**Bug** : composition réelle = "2 viandes au choix" (steak + cordon bleu par exemple)
**Fix mobile** : `viandes=0` → `viandes=2`
**Fix config** : `viandes=1` → `viandes=2`, remove contradictory comment
**LOCK plan** : non — config et mobile/data ne sont pas frozen

### Bêtise #3 — Sandwich Froid step sauce + crudités sur thon
**Fichier** : `config/menu.php:233-238` (Sandwich Froid `has_sauce=true, has_crudites=true`)
**Question** : un sandwich au thon froid, le client veut-il vraiment choisir sauce + crudités via wizard ou juste le commander tel quel ? Peut-être laisser sauce optionnelle mais crudités pré-incluses (sans toggle).
**Décision proposée** : garder tel quel (3 steps minimum reste rapide), mais marquer crudités comme "défault ON, tu peux retirer" (déjà le cas en mobile).
**Verdict** : ✅ pas un bug, juste vigilance UX

### Bêtise #4 — Panini "1 viande au choix" parmi 6
**Fichier** : `config/menu.php:241-247` (Panini `viandes=1`, description "Thon - Jambon - ...")
**Question** : kiosk traite-t-il les 6 options Panini comme "viandes" (réutilisation des 9 viandes standards) ou comme un attribut séparé "Panini fillings" ?
**Vérification nécessaire** : DB Item Panini attached attributes (tinker ne marche pas en V1 — relation non définie)
**Décision proposée** : si DB attache attribut "panini_fillings" custom → mobile doit le respecter ; si DB attache "viandes" standard → ne pas changer
**Verdict** : ⚠️ à vérifier, possible drift

### Bêtise #5 — Assiette Poulet cooking style (Nature/Curry/Paprika)
**Fichier** : `config/menu.php:328` description "Poulet (Nature - Curry - Paprika)"
**Bug** : kiosk ne propose AUCUN step cooking style. Mais la description LE MENTIONNE.
**Question** : staff demande verbalement ? Ou config c'est juste descriptif ?
**Owner clarification needed** : OUI/NON sur step "Style de cuisson" pour Assiette Poulet
**Verdict** : ⚠️ owner gate D9 NEW

---

## §4 — DRIFTS MOBILE ACTUELS À FIXER

| # | Drift | Sévérité | Fichier | Fix |
|---|-------|----------|---------|-----|
| D1 | **Le Suprême viandes=0 → 2** | P0 | mobile/data/menu.js | 1 ligne |
| D2 | **Salade menu addon manquant** (cat 7) | P1 | mobile/screens-item-steps.jsx (salade template) | Add menu+cascade steps |
| D3 | **Quick-add "+" bypasse wizard** (P0-7 ultraplan) | P0 | mobile/screens-main.jsx:212 | Disable for items with viandes>0 OR has_sauce — open wizard instead |
| D4 | **Allergens display 0%** (EU FIC obligation légale) | P0 | screens-main.jsx + screens-item-steps.jsx | Add AllergenBadge component |
| D5 | **Special instructions textarea absent** wizard recap | P1 | screens-item-steps.jsx (Recap) | Add textarea max 190 char |
| D6 | **Promo code input absent** sur cart | P1 | screens-main.jsx ScreenCart | Add input + mock validation |
| D7 | **Step Pain (Pain vs Galette)** — actuellement 2 items distincts (`sandwich-pain` vs `sandwich-galette`) | P2 | data + steps | Fusionner en 1 item + step pain/galette OR keep 2 items |
| D8 | **Cat 312/313 has_menu drift** (audit 2026-05-10) | P1 | mobile/data/menu.js | Check + align if true |
| D9 | **Tacos slug mismatch** `tacos-m` vs `tacos-m-1-viande` | P2 | data | Phase 6.B wireup |
| D10 | **Search non-functional** (Phase 6.B) | P2 | screens-main.jsx | Defer |
| D11 | **Favorites non-functional** | P2 | screens-main.jsx | Defer |
| D12 | **Cooking style Assiette Poulet** | ⚠️ owner gate | data | Owner D9 |

**Décision** : D1 + D2 + D3 + D4 sont les vrais P0 — les autres P1/P2 attendent.

---

## §5 — APP FLOW END-TO-END (confirmé écran par écran)

> Walkthrough du chemin utilisateur de A à Z. Statut actuel post Phase 6.A.

### Écran 1 — Splash (1.8s auto-advance)
**Fichier** : `screens-onboarding.jsx::ScreenSplash`
**Affichage** : Logo Le Cayenne (poivron orange sur cercle noir) + "HÉNIN-BEAUMONT · 62210" + "Du peuple, pour le peuple"
**Tap** : skip vers Onboarding 1
**Fonctionnement** : ✅ OK

### Écrans 2-5 — Onboarding (4 slides)
**Fichier** : `screens-onboarding.jsx::ScreenOnb1/2/3/4`
**Slides** :
1. "Bienvenue" + hero Le Cayenne signature sandwich (Phase 6.A real-asset wired)
2. "Commande en 30 sec" + hero Tenders croustillants
3. "Cuit minute, livré chaud"
4. "Récompensé à chaque commande" + Loyalty teaser
**Bouton Passer** : skip toute la séquence vers Login
**Fonctionnement** : ✅ OK post Phase 6.A

### Écran 6 — Login (phone input)
**Fichier** : `screens-onboarding.jsx::ScreenLogin`
**Affichage** : "Entre ton numéro" + input phone + RGAA validation aria
**Bouton** : "Continuer" → screen OTP
**Mode V0** : pas de SMS réel envoyé, juste UI mock
**Fonctionnement** : ✅ OK (Phase 6.B wireup Twilio plus tard)

### Écran 7 — OTP (code à 4 chiffres)
**Fichier** : `screens-onboarding.jsx::ScreenOTP`
**Affichage** : "Entre ton code" + 4 inputs + "Code de démo : 1234" (gated par `?dev` flag post cluster-4 fix A-001)
**Bouton** : auto-submit dès 4 chiffres → home
**Mode V0** : code 1234 hardcodé
**Fonctionnement** : ✅ OK

### Écran 8 — Home (post-auth)
**Fichier** : `screens-main.jsx::ScreenHome`
**Affichage** :
- Header : avatar IB (initiales) + Logo "LE CAYENNE • OUVERT" + bell
- Greeting : "Bonjour/Bonsoir, IKYES !" (selon heure)
- Marquee categories scroll ("FAIT MAISON / SMASH BURGERS / TACOS / ...")
- Featured signature card : **Tacos XXL** photo (Phase 6.A signature hero) + "SIGNATURE" + 12,50€ + bouton Commander
- Grid 6 catégories preview (emoji + nom)
- "Les envies du moment" : items is_featured horizontal scroll
- "Nouveautés" : items tags TOP grid 2×2
- Restaurant info card (yellow accent)
- TabBar 4 onglets (Accueil/Menu/Commandes/Profil) — buttons post cluster-A11

**Drift** : Si user click un item dans "Les envies"/"Nouveautés" → ouvre wizard direct (OK). Si click bouton "+" → ⚠️ **DRIFT D3** : bypasse wizard pour items qui devraient en avoir.
**Fonctionnement** : ✅ OK sauf D3

### Écran 9 — Menu (catégories + items)
**Fichier** : `screens-main.jsx::ScreenMenu`
**Affichage** :
- Header "MENU" + 13 cat · 60 produits
- Search icon (non fonctionnel V0 — Phase 6.B)
- Filter chips : Tout + 13 catégories (sticky scroll)
- Sections : par catégorie filtrée, grid items
- Item card : image (Phase 6.A) + name + desc (line-clamp 2) + prix + bouton "+"
- TabBar bottom

**Drift** : 
- D3 (bouton +) — voir Home
- D10 search non-functional

**Fonctionnement** : ✅ OK sauf drifts

### Écran 10 — Item (wizard ou direct)
**Fichier** : `screens-item-steps.jsx::ScreenItemWizard` ou `ScreenItemDirectAdd`
**Wizard mode** (items avec viandes>0 OR has_sauce OR has_supplements) :
- Header : back arrow + "ÉTAPE N/M" + nom step + close X
- Progress dots (N steps)
- Step body : choices (viandes/sauce/crudités/etc.) avec real images Phase 6.A
- Bottom : "SUIVANT" CTA disabled si step incomplet + total live
- Last step : Recap (composition + qty + bouton "AJOUTER AU PANIER")
**Direct mode** (desserts, boissons) :
- Header : back + close
- Hero image + nom + description
- Qty stepper
- Bouton "AJOUTER AU PANIER + N€"

**Drifts** :
- D4 allergens display absent
- D5 special instructions textarea absent recap
- D2 salade menu addon manquant

**Fonctionnement** : ✅ OK 60 items, wizard kiosk-aligned post audit, sauf drifts ci-dessus

### Écran 11 — Cart (panier)
**Fichier** : `screens-main.jsx::ScreenCart`
**Affichage** :
- Header back + "PANIER"
- Titre "TA COMMANDE" + "N article(s) · prêt dans ~12 min"
- Lignes items : thumb (Phase 6.A) + nom + composition_summary + qty stepper + prix + trash icon
- Banner loyalty : "+N pts gagnés sur cette commande / Plus que N pts pour ton burger gratuit"
- Section "POUR ACCOMPAGNER ?" (cross-sell — actuellement vide)
- Sticky footer : "TOTAL N,NN €" + "TVA incluse" + bouton "VALIDER MA COMMANDE"
- Empty state : "Ton panier est vide / Faim ? Va voir le menu" + icon

**Drift** :
- D6 promo code input absent

**Fonctionnement** : ✅ OK

### Écran 12 — Modal Pay choice
**Fichier** : `screens-modals.jsx::ModalPayChoice`
**Affichage** : bottom-sheet modal
- Title "COMMENT TU PAIES ?"
- Subtitle "Total N,NN € · TVA incluse"
- Option 1 : "PAYER À LA CAISSE — Cash ou CB sur place" (RECOMMANDÉ badge)
- Option 2 : "PAYER MAINTENANT — CB sécurisée Stripe (Visa · Mastercard · Apple Pay)"
**Fonctionnement** : ✅ OK V0 (Stripe = placeholder)

### Écran 13 — Confirm (post-paiement)
**Fichier** : `screens-main.jsx::ScreenConfirm`
**Affichage** :
- Hero card jaune avec icon ✓
- "Commande #C-XXXX" + ETA "08h07" (live)
- Total payé + méthode
- "SUIVRE MA COMMANDE" CTA → orders
- "Retour à l'accueil" link
**Fonctionnement** : ✅ OK post cluster-2 fix

### Écran 14 — Modal Points Gain (auto 900ms post confirm)
**Fichier** : `screens-modals.jsx::ModalPointsGain`
**Affichage** : confetti + "+N POINTS GAGNÉS" + "BIENVENUE AU CLUB" (premier achat) ou "MERCI" (récurrent)
**Fonctionnement** : ✅ OK

### Écran 15 — Orders (Commandes)
**Fichier** : `screens-main.jsx::ScreenOrders`
**Affichage** :
- Tabs "En cours" / "Historique" (badges count)
- Active : card commande en préparation avec ETA + bouton "Détails"
- Historique : liste commandes passées + bouton "↻ Refaire"
- Stats bar (Commandes/Dépensé/Pts) — derived from data post cluster-3
**Fonctionnement** : ✅ OK

### Écran 16 — Order Detail
**Fichier** : `screens-main.jsx::ScreenOrderDetail` (post cluster-2 fix)
**Affichage** : détail complet commande + items + total + statut + bouton "↻ Recommander"
**Fonctionnement** : ✅ OK

### Écran 17 — Profile
**Fichier** : `screens-main.jsx::ScreenProfile`
**Affichage** :
- Avatar grand + nom IB
- Loyalty preview card (cliquable → ScreenLoyalty)
- Menu rows : Mes infos / Mes adresses / Méthodes paiement / Notifications / Aide / Programme fidélité / Déconnexion
**Fonctionnement** : ✅ OK

### Écran 18 — Loyalty (3 tabs)
**Fichier** : `screens-main.jsx::ScreenLoyalty`
**Tabs** :
- **Mes points** : QR/Barcode toggle + balance + progress vers next reward + countdown TTL 4:59
- **Récompenses** : 8 rewards (Frites M gratuites, Burger gratuit, etc.) avec lock si insufficient + bouton "Échanger"
- **Historique** : liste transactions (+5 pts cmd C-1212, -100 pts redeem, etc.)
- RGPD opt-out button → modal
- Réactiver mon compte (post opt-out)
**Fonctionnement** : ✅ OK post cluster-5 RGPD copy alignée

### Modals additionnels (10 total)
- ModalQRMode (toggle QR/Barcode)
- ModalCardLink (camera scan mock)
- ModalRedeemWizard (3 steps confirm reward)
- ModalOptOutConfirm (RGPD)
- ModalWalletV0Notice (Apple/Google pay stub)
- ModalStripeCheckout (V0 placeholder)
- ModalProfileEdit
- ModalSupport (V0)
- Toast notifications
**Fonctionnement** : ✅ OK

---

## §6 — PLAN D'ACTION (post-validation owner)

### Sprint A — Mobile fixes raisonnés (~3-4h)
1. **D1** Le Suprême viandes=2 — 1 ligne `mobile/data/menu.js`
2. **D2** Salade menu addon — étendre `salade` template `screens-item-steps.jsx` pour inclure menu step optionnel + cascade
3. **D3** Quick-add bypass fix — `screens-main.jsx:212` ne pas bypasser wizard si item.viandes>0 OR item.has_sauce OR item.has_supplements
4. **D4** AllergenBadge component — nouveau component + wire menu cards + wizard recap + item detail
5. **D5** Special instructions textarea — ajouter sur ScreenStepRecap (190 char max)
6. **D6** Promo code input — ajouter sur ScreenCart (mock V0)
7. Update `config/menu.php` Le Suprême `viandes=2` + remove comment

### Sprint B — Kiosk LOCK plan (1 fichier frozen-zone)
1. Generate `LOCK_KIOSK_SALADE_2026-05-11.md` per skill `lock-plan`
2. Modify `KioskWizardComponent.vue:619-628` :
   - Old: `garnitures → sauce → menu → frites_style → supplements → recap`
   - New: `sauce → supplements → menu → frites_style → recap` (menu/frites_style filtered by shouldShowStep)
3. Owner gate visual review on kiosk salade flow
4. PHPUnit + Playwright régression

### Sprint C — Owner gates restants
- D9 Cooking style Assiette Poulet (Nature/Curry/Paprika step OU descriptive only ?)
- Panini fillings step (verify DB attribute)

### Sprint D — Re-validation E2E
1. Re-run 4 waves Playwright après fixes
2. Confirm visual + functional convergence
3. Update BRAIN + Graphiti

---

## §7 — DÉCISIONS OWNER À VALIDER

| ID | Question | Ma recommandation |
|----|----------|-------------------|
| **OG1** | Salade wizard simplifié sur **kiosk** aussi ? (touche frozen-zone) | OUI — owner a explicitement gate ✓ |
| **OG2** | Le Suprême viandes=2 ? | OUI — owner clarification ✓ |
| **OG3** | Sandwich Pain vs Galette = 2 items distincts (actuel) OU 1 item + step pain ? | Garder 2 items (simple, cohérent UI) |
| **OG4** | Cooking style Assiette Poulet (Nature/Curry/Paprika) = wizard step OU descriptive only ? | À TRANCHER (kiosk = descriptive, mais user pourrait le souhaiter) |
| **OG5** | Allergens — bundler dans mobile/data localement V0 OU bloquer sur backend wireup ? | Bundler localement (legal critical, on attendra pas backend) |
| **OG6** | Quick-add bouton "+" — toujours ouvrir wizard pour items configurables OU permettre quick-add avec composition default ? | Ouvrir wizard (intent claire = "personnalise-moi") |

---

## §8 — VERDICT — État du fonctionnement de l'app

🟢 **Fonctionnement actuel** : l'app marche end-to-end de Home → Confirm. 16+ écrans + 10 modals OK.

⚠️ **Drifts à corriger AVANT de continuer Phase 6.B** : 6 fixes scope-minimal (D1-D6 ci-dessus) — 3-4h agent total.

⚠️ **Bêtise kiosk salades** : 1 LOCK plan + 1 fichier modif après owner gate cleared.

🚫 **Pas pour l'instant** : OTP réel, Stripe réel, App Store, Production. On finit l'app V0 raisonnée d'abord.

---

## 🚦 PROCHAINE ACTION

Owner choisit :
- **A** : Tu valides OG1-OG6 ci-dessus → j'attaque Sprint A (mobile fixes ~3-4h) puis Sprint B (kiosk LOCK)
- **B** : Tu réponds OG4 (cooking style Assiette Poulet) + autres en attente → puis Sprint A
- **C** : Tu veux d'abord screenshots actuels (Le Suprême wizard / Salade wizard / Allergens absent) avant validation
