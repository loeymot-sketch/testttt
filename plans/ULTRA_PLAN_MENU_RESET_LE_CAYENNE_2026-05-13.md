# ULTRA PLAN — Menu Reset Le Cayenne (Archive + Nouvelle structure 9 catégories)

**Date** : 2026-05-13
**Owner** : Sorrow / Le Cayenne
**Auteur orchestration** : Claude Code (single-agent + 6 sub-agents Explore parallèles)
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10` (HEAD `4937d08b2`)
**Status** : ⏸️ **DRAFT — OWNER GATE OBLIGATOIRE AVANT EXÉCUTION**

---

## §0. TL;DR (en 6 lignes)

1. **Archiver** (soft-delete, NON suppression) les 7 catégories abandonnées : Burgers, Assiettes, Ojja, Omelettes, Salades, Poulet croustillant, Menus Enfants.
2. **Garder + renommer** 4 catégories : Tacos, Suppléments, Desserts, Boissons, Frites.
3. **Créer 4 nouvelles catégories** : Sandwich Cayenne, Galette, Sandwich Classique, Bols Gourmands.
4. **Archiver l'ancienne "Nos Sandwichs"** car remplacée par 3 sous-catégories spécialisées.
5. **Backup non-destructif** : branche git `backup/pre-menu-reset-2026-05-13` + dump DB SQLite (script existant).
6. **Wizard NON touché** dans cette phase (frozen). Ajustations wizard reportées à un cycle ultérieur (notamment cas "Bols sans wizard").

**Total catégories finales : 9** = `Sandwich Cayenne` + `Galette` + `Sandwich Classique` + `Tacos` + `Bols Gourmands` + `Frites` + `Suppléments` + `Desserts` + `Boissons`.

---

## §1. CONTEXTE & MISSION

### 1.1 Demande owner (verbatim 2026-05-13)

> "On va laisser au total 6 catégories qui vont être même modifiés [...] sandwich + une autre [Galette] + tacos + bol + supplément + dessert + boisson + frites [...] toutes les autres catégories comme les assiettes, salades [...] on va les mettre archivés."
>
> "Je veux la correction globale mais surtout je veux pas la suppression de l'état actuel, je veux le sauvegarder comme archives."
>
> "Le wizard va rester juste, il y aura des ajustations à faire pas maintenant."
>
> "Les bols vont pas y avoir de wizard ça veut dire normal parce que le bol il a pas de crudités [...] juste le choix de viande et la sauce."
>
> "On doit rajouter, on doit laisser les frites comme catégorie [...] petite frites et grande frites comme catégorie."

### 1.2 Hypothèses confirmées par cartographie

- **Le menu actuel a 13 catégories** (cf. `config/menu.php:47-62` + `mobile/data/menu.js:228-242`).
- **Le restaurant reste le même** (Le Cayenne Hénin-Beaumont 62210) — pas de changement d'identité.
- **Suppléments, sauces et formules (menu/sans menu)** restent identiques.
- **Les "viandes" changent** : nouveau set = `Poulet classic, Poulet curry, Poulet tandoori, Poulet crispy` (différent de l'actuel `Merguez/Kefta/Mexicain/Cordon Bleu/Viande Hachée/Nuggets/Escalope/Tenders/Fricandelle`).

### 1.3 Conformité §1/§2/§3 CLAUDE.md

- Vision long-terme préservée (V1 fast-food restaurant Le Cayenne).
- Architecture inchangée (DB schema, NF525, BranchScope, multi-tenant).
- **Frozen zones intactes** : POS Vanilla wizard + Kiosk Vue wizard + NF525 + BranchScope NON touchés.
- Pricing SSOT backend autoritatif maintenu.

---

## §2. PRINCIPES NON-NÉGOCIABLES (de ce plan)

1. **Non-destructif** : aucune ligne SQL `DELETE` / `TRUNCATE` / `menu:reset` (hard purge). Soft-delete uniquement.
2. **Historique des commandes intact** : `composition_snapshot` JSON + FK RESTRICT garantissent que les anciens orders restent lisibles.
3. **Wizard frozen** : `public/js/pos-wizard.js` + `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` ne sont PAS modifiés. Si une nouvelle catégorie nécessite un nouveau `wizard_template`, on adapte la donnée (champ `wizard_template`) pas le code wizard.
4. **NF525 intact** : ZReport / FiscalSequence / AuditLog non touchés (zéro impact attendu).
5. **Backend = SSOT** : changes appliqués d'abord en DB + `config/menu.php`, puis miroirs (mobile/data/menu.js) en lockstep.
6. **Reversible en 1 commande** : `git checkout backup/pre-menu-reset-2026-05-13` ou `ItemCategory::onlyTrashed()->restore()`.
7. **Tests vert avant commit** : PHPUnit filter Menu|Catalog + Vitest + smoke Playwright kiosk/admin.
8. **Visual evidence obligatoire** (§6 CLAUDE.md) : captures kiosk + admin + POS + KDS post-migration.

---

## §3. ÉTAT ACTUEL CARTOGRAPHIÉ

### 3.1 Catégories existantes (DB `item_categories` + `config/menu.php`)

| ID DB | Slug | Nom actuel | wizard_template | has_menu | Items | Décision |
|-------|------|------------|-----------------|----------|-------|----------|
| 1 | `nos-tacos` | Nos Tacos | tacos | ✓ | 4 | **KEEP** → rename "Tacos" |
| 2 | `nos-sandwichs` | Nos Sandwichs | sandwich | ✓ | 8 | **ARCHIVE** (remplacée par 3 sous-cats) |
| 3 | `nos-burgers` | Nos Burgers | burger | ✓ | 6 | **ARCHIVE** |
| 4 | `nos-assiettes` | Nos Assiettes | assiette | ✗ | 4 | **ARCHIVE** |
| 5 | `ojja` | Ojja | omelette | ✗ | 4 | **ARCHIVE** |
| 6 | `omelettes` | Omelettes | omelette | ✗ | 3 | **ARCHIVE** |
| 7 | `nos-salades` | Nos Salades | salade | ✓ | 4 | **ARCHIVE** |
| 8 | `chicken-tenders` | Poulet croustillant | snacking | ✗ | 4 | **ARCHIVE** |
| 9 | `nos-menus-enfants` | Nos Menus Enfants | omelette | ✗ | 2 | **ARCHIVE** |
| 10 | `frites-accompagnements` | Frites & Accompagnements | simple | ✗ | 2 | **KEEP** → rename "Frites" |
| 11 | `nos-desserts` | Nos Desserts | simple | ✗ | 3 | **KEEP** → rename "Desserts" |
| 12 | `nos-boissons` | Nos Boissons | simple | ✗ | 8 | **KEEP** → rename "Boissons" |
| 13 | `supplements` | Suppléments | simple | ✗ | 8 | **KEEP** (nom OK) |

**Total à archiver : 8 catégories (incluant `nos-sandwichs`) = 35 items soft-delete.**

### 3.2 Architecture DB confirmée (sub-agent #1)

- Table : `item_categories` (avec `softDeletes()`, `status` enum 5/10, `wizard_template`, `wizard_profile_id`, `parent_id`, `channels` JSON, `kiosk_sort`/`pos_sort`/`kiosk_label`).
- Modèle : `app/Models/ItemCategory.php` uses `SoftDeletes`, `HasFactory`, `InteractsWithMedia`.
- FK `items.item_category_id` → `item_categories.id` : **RESTRICT par défaut** (soft-delete OK, hard-delete bloqué tant que des items existent).
- FK `item_wizard_profiles.item_category_id` → CASCADE : **⚠️ attention**, soft-delete catégorie ne supprime pas le profile, mais hard-delete CASCADE le ferait.
- FK `item_categories.parent_id` → SET NULL.
- `order_items.composition_snapshot` JSON immutable garantit historique safe.

### 3.3 Surfaces consommatrices (sub-agents #2 + #3)

| Surface | Endpoint | Mécanisme cat-filter |
|---------|----------|----------------------|
| **POS Vanilla wizard** (FROZEN) | XHR `/admin/item/*` intercepté | detect `wizard_template` puis fallback sur `category_name` |
| **Admin POS Vue** | `GET /api/admin/menu` | sidebar par cat ID |
| **Kiosk Vue (FROZEN)** | `GET /api/frontend/menu` (5min cache) | Vuex `kioskMenu/categories`, hidden ID `[315]` hard-coded |
| **KDS** | `GET /api/admin/kds-order` | grouping par `item.kds_station` (pas par category direct) |
| **OSS** | `GET /api/admin/oss-order/popular-items` | pas de cat filtering |
| **Admin Categories CRUD** | `GET/POST/PATCH/DELETE /api/admin/item-category` | service + paginate |
| **Mobile app** | offline PWA, NO API | `mobile/data/menu.js` hardcoded |

### 3.4 Hardcoded references à updater

- `config/kiosk.php:43,86` — `parent_category_slug: 'nos-sandwichs'` (sandwich-split sidebar split kiosk)
- `config/kiosk.php:25-30,44,87-88` — `cold_item_slugs: ['sandwich-froid', 'panini', 'sandwich-classique-pain', 'sandwich-classique-galette']`
- `resources/js/store/modules/kioskMenu.js:85` — `KIOSK_HIDDEN_CATEGORY_IDS = new Set([315])` (id=315 = vieille "Frites" sub-cat ?)
- `config/menu_images.php` — mappings slug → PNG (à étendre pour nouvelles cats)
- `mobile/data/menu.js:1-450` — replica complète à réécrire
- `lang/{fr,en,ar}/all.php` — clés `label.composer.template_*` (à ne PAS toucher : ce sont des template generic, pas des cats)
- POS wizard `pos-wizard.js:359-397` — switch hardcodé par `wizard_template` : `tacos | sandwich | burger | assiette | salade | omelette | ojja | snacking + default`. **PAS de case 'bols'** → fallback dangereux ⚠️.

### 3.5 Stock + Sync impact (sub-agent #4)

- **Stock** : zéro dépendance `category_id`. ItemBranchAvailability + StockLevel + StockMovement basés sur item_id polymorphique. **SAFE**.
- **Sync** : `CategoryDeleted` → `CatalogChanged::fromMenuMutation` → `PersistCatalogChangedToOutbox` → fan-out par branche sur channel `private-branch.{id}`. Idempotency clé inclut `(entity_type, entity_id, branch_id, change_type)`. **Soft-delete déclenchera bien un event sync OK**.
- **Order persistence** : `composition_snapshot` ne contient PAS de `category_id` ni `category_name` — uniquement item_id/variation_id/extra_id + prix. **Order history 100% safe**.

### 3.6 Archive tooling existant (sub-agent #6)

- ✅ Soft-delete trait sur `ItemCategory`, `Item`, `ItemAddon`, `ItemExtra`, `ItemVariation`.
- ✅ `deletion_log` table (audit trail soft-deletes).
- ✅ `scripts/db/backup.sh` (SQLite/MySQL/PG backup).
- ❌ Pas de scopes `archived()` / `active()` sur les models.
- ❌ Pas de bouton "Archiver" UI admin (juste "Delete" qui soft-delete sans label clair).
- ❌ Pas d'artisan command `categories:archive`.
- ⚠️ `php artisan menu:reset` existe MAIS fait `purgeExistingData()` = HARD DELETE → **À NE PAS UTILISER**.

---

## §4. ÉTAT CIBLE — Spec détaillée des 9 catégories

### 4.1 Vue d'ensemble

| Ordre | Slug nouveau | Nom affiché | wizard_template | has_menu | Items | Origine |
|-------|--------------|-------------|-----------------|----------|-------|---------|
| 1 | `sandwich-cayenne` | Sandwich Cayenne | `sandwich` | ✓ | 4 viandes × 1 sandwich (variation) | **NEW** |
| 2 | `galette` | Galette | `sandwich` (avec sub-type step) | ✓ | 2 sous-produits (normale + Cayenne) × 4 viandes | **NEW** |
| 3 | `sandwich-classique` | Sandwich Classique | `sandwich` | ✓ | 1 produit pain faluche × 4 viandes (variations) | **NEW** |
| 4 | `tacos` | Tacos | `tacos` | ✓ | 2 sous-produits (Tacos 1 viande + Big Tacos 2 viandes) | **RENAME ex `nos-tacos`** |
| 5 | `bols-gourmands` | Bols Gourmands | `simple` (no wizard standard, mini-flow viande+sauce) | ✗ | 5 sous-produits (Curry/Tandoori/Mariné/Crousti/Gratiné) | **NEW** |
| 6 | `frites` | Frites | `simple` (flat, no wizard) | ✗ | 2 items (petite + grande) | **RENAME ex `frites-accompagnements`** |
| 7 | `supplements` | Suppléments | `simple` | ✗ | 10 items (cf. §4.3) | **KEEP id=13** |
| 8 | `desserts` | Desserts | `simple` | ✗ | items existants | **RENAME ex `nos-desserts`** |
| 9 | `boissons` | Boissons | `simple` | ✗ | items existants | **RENAME ex `nos-boissons`** |

### 4.2 Spec produit par catégorie

#### **4.2.1 Sandwich Cayenne** (NEW)

```yaml
slug: sandwich-cayenne
name: Sandwich Cayenne
wizard_template: sandwich
has_menu: true
parent_id: null
items:
  - slug: sandwich-cayenne-classique
    name: Sandwich Cayenne
    price: 7.00  # placeholder, owner à confirmer
    variations (attribut: viande, min=1 max=1):
      - Poulet classic
      - Poulet curry
      - Poulet tandoori
      - Poulet crispy
    crudites (toggle, default ON):
      - Salade, Tomate, Oignon, Cornichon
    sauce_fixe: Sauce Cayenne     # ⚠️ NEW : sauce non-choosable, imposée
    supplements (multi): Cheddar (1€), Raclette (1€), Emmental (1€), Œuf (1€), Bacon (1€), Légumes sautés (1€)
    menu_addon: option frites + boisson (+3€)
```

**Note wizard** : sauce_fixe = nouveau comportement. Soit on l'implémente via :
- (a) un `ItemVariation` avec `min_select=1 max_select=1` ne contenant qu'UNE option "Sauce Cayenne", OU
- (b) un flag DB `sauce_locked` + label affiché en step recap.

→ **Option (a) recommandée** : zéro impact wizard code, pure data.

#### **4.2.2 Galette** (NEW)

```yaml
slug: galette
name: Galette
wizard_template: sandwich     # même contrat, juste 1 step en plus
has_menu: true
items:
  - slug: galette-normale
    name: Galette Normale
    price: 6.50  # placeholder
    variations:
      - Type galette: 'Normale' (single, ItemAttribute step 'pain/type')
      - Viande (1 viande, mêmes options)
    crudites: Salade/Tomate/Oignon/Cornichon
    sauce_libre: au choix (toutes les 13 sauces)
    supplements: idem cayenne
    menu_addon: option frites + boisson

  - slug: galette-cayenne
    name: Galette Cayenne
    price: 7.00  # placeholder
    variations: idem
    sauce_fixe: Sauce Cayenne maison    # locked
    supplements/menu/crudites: idem
```

**Décision data-only** : Galette Normale et Galette Cayenne = **2 items séparés** dans la même catégorie. L'user choisit dans la catégorie quel sous-produit, puis le wizard standard `sandwich` se déclenche. Pas besoin d'un nouveau step "type de galette".

#### **4.2.3 Sandwich Classique** (NEW)

```yaml
slug: sandwich-classique
name: Sandwich Classique
wizard_template: sandwich
has_menu: true
items:
  - slug: sandwich-classique-faluche
    name: Sandwich Classique (Pain Faluche)
    price: 6.50  # placeholder
    base: Pain faluche  # fixé (description ou variation single)
    variations: 4 viandes
    crudites: Salade/Tomate/Oignon/Cornichon
    sauce_libre: au choix
    supplements: idem
    menu_addon: option frites + boisson
```

#### **4.2.4 Tacos** (RENAME + restructure)

```yaml
slug: tacos          # nouveau (ex 'nos-tacos')
name: Tacos
wizard_template: tacos
has_menu: true
items (2 sous-produits) :
  - slug: tacos-1-viande
    name: Tacos
    composition: 1 viande au choix + frites maison + sauce fromagère maison (toutes incluses)
    price: 8.50  # placeholder (l'actuel cat 1 a 4 items M/L/XL/XXL — à archiver + remplacer)
    variations: 1 viande (min=1 max=1)
    menu_addon: option frites + boisson

  - slug: big-tacos-2-viandes
    name: Big Tacos
    composition: 2 viandes au choix + frites maison + sauce fromagère maison
    price: 11.50  # placeholder
    variations: 2 viandes (min=2 max=2)
    menu_addon: option frites + boisson
```

**Note** : les 4 anciens items (tacos-m/l/xl/xxl) sont archivés (soft-delete). Les nouveaux items utilisent le même `wizard_template='tacos'` ; pas de changement code wizard.

#### **4.2.5 Bols Gourmands** (NEW — comportement spécial)

```yaml
slug: bols-gourmands
name: Bols Gourmands
wizard_template: simple        # ⚠️ KEY : pas de wizard tacos/sandwich/burger
has_menu: false                # pas d'option menu (mais option boisson possible)
items (5 sous-produits) :
  - slug: bol-curry
    name: Bol Curry
    composition_fixe: Poulet curry + sauce curry maison
    price: 10.50
    base_choice (single ItemAttribute, min=1 max=1):
      - Frites
      - Riz basmati
    supplements: Jambon, Oignons frits, Champignons, Boule gratinée (1€ each)
    boisson_addon: option +boisson

  - slug: bol-tandoori
    name: Bol Tandoori
    composition_fixe: Poulet tandoori + sauce tandoori
    [...]

  - slug: bol-marine
    name: Bol Mariné
    composition_fixe: Poulet mariné + sauce blanche maison
    [...]

  - slug: bol-crousti
    name: Bol Crousti
    composition_fixe: Poulet crispy + sauce fromagère maison
    [...]

  - slug: bol-gratine
    name: Bol Gratiné
    composition_fixe: Poulet mariné + sauce fromagère maison + boule gratinée
    [...]
```

**Décision critique : "Bols sans wizard"** (citation owner)

Le POS Vanilla wizard frozen a un fallback dangereux pour `wizard_template='simple'` ou inconnu. **Option recommandée** :

- Mettre `wizard_template='simple'` sur la catégorie ⇒ wizard kiosk ne déclenche QUE step recap (déjà géré par le code, voir mobile/sub-agent #2 : "menu_enfant/dessert/boisson → only recap step").
- Mais bols a une **base à choisir** (frites ou riz) et **sauces/suppléments**. Donc en pratique on a besoin d'un wizard light **2 steps** : (1) base, (2) supplements/recap.
- **Solution data-only** : créer un `ItemAttribute` "Base" min=1 max=1 sur chaque bol item, et utiliser le wizard `sandwich`/`tacos` réutilisé. Le step viande sera bypassed (viande fixée dans le nom du produit), le step sauce bypassed (sauce fixée), le step crudités bypassed (`has_crudites=false`), le step menu bypassed (`has_menu=false`).

**Owner gate Q1** : confirmer si "pas de wizard" signifie :
- (a) zéro modal, direct add-to-cart ⇒ alors la "base frites/riz" doit être un produit séparé OU une variation default → ambigu.
- (b) wizard minimal 1-2 steps (base + recap, voire base+supplements+recap) ⇒ recommandé pour conserver UX cohérente.

→ **À clarifier owner avant exec.** Plan default = (b) wizard minimal data-only.

#### **4.2.6 Frites** (RENAME + flat)

```yaml
slug: frites              # nouveau (ex 'frites-accompagnements')
name: Frites
wizard_template: simple   # déjà le cas
has_menu: false
items:
  - slug: frites-petite
    name: Petite Frites
    price: 2.50           # placeholder, owner confirm
    no_wizard: direct add-to-cart

  - slug: frites-grande
    name: Grande Frites
    price: 4.00           # placeholder
    no_wizard: direct add-to-cart
```

**Conservation des "frites_style"** : actuellement la table item_extras a des rows `group_label='frites_style'` (Nature/Cheddar fondu/Cheddar+Oignons) attachées aux items frites quand `has_frites_style=true`. À **garder telles quelles** pour les sandwiches/tacos qui choisissent un menu+frites. Pour la catégorie "Frites" standalone : flag `has_frites_style=false` pour rester flat (option : laisser l'attribut visible si owner veut donner le choix au moment de l'achat frites direct).

**Owner gate Q2** : sur "Frites" standalone, frites_style upgrade activé ou pas ?

#### **4.2.7 Suppléments** (KEEP renforcé)

```yaml
slug: supplements   # déjà OK
name: Suppléments
wizard_template: simple
has_menu: false
items (10 — owner liste fraîche):
  - Cheddar (1€)
  - Raclette (1€)
  - Emmental (1€)
  - Œuf (1€)
  - Bacon (1€)
  - Légumes sautés (1€)
  - Jambon (1€)
  - Oignons frits (1€)
  - Champignons (1€)
  - Boule gratinée (1€)
```

**Mappage avec items existants (cat 13 actuelle, 8 items)** :
- ✅ Conserver : Cheddar (déjà item-fromage), Œuf (item-oeuf), Raclette (item-raclette), Galette pommes de terre → **rename "Boule gratinée"** ? owner clarification.
- ✅ Conserver : Jambon (item-jambon), Boursin → archive (pas dans liste owner), Sauce supplémentaire → archive (déjà géré par sauce step).
- ➕ Ajouter : Bacon, Légumes sautés, Oignons frits, Champignons, Emmental.

**Owner gate Q3** : "Boule gratinée" = même chose que "Galette pommes de terre" existante ? Si non, créer item séparé.

#### **4.2.8 Desserts + Boissons** (RENAME minimal)

- `nos-desserts` → `desserts` (slug + name; items inchangés : Glace, Tarte Daim, Tiramisu).
- `nos-boissons` → `boissons` (slug + name; items inchangés : Coca/Coca Zero/Fanta/Sprite/Oasis/Orangina/Eau plate/Capri-Sun).

### 4.3 Sauces canoniques (13 sauces — owner liste finale)

```yaml
sauces_pool:
  - Mayonnaise
  - Ketchup
  - Algérienne
  - Samouraï
  - Curry
  - Andalouse
  - Harissa     # spicy
  - Hannibal    # spicy
  - Blanche
  - Tandoori
  - Fromagère
  - Pimentée    # nouveau ?
  - Cayenne     # signature
```

**Diff vs liste actuelle (cat 13 sauces existants)** : `Mayonnaise, Ketchup, Algérienne, Curry, Andalouse, Burger, Samouraï, Barbecue, Cocktail, Américaine, Hannibal, Harissa, Blanche, Poivre, Sans Sauce`.

**À retirer** : Burger, Barbecue, Cocktail, Américaine, Poivre, Sans Sauce.
**À ajouter** : Tandoori, Fromagère, Pimentée, Cayenne (4 nouvelles).

**Owner gate Q4** : confirmer liste finale 13 sauces + ajouter ou retirer.

### 4.4 Viandes canoniques (4 viandes — owner liste finale)

```yaml
viandes_pool:
  - Poulet classic   # mariné simple
  - Poulet curry
  - Poulet tandoori
  - Poulet crispy
```

**Diff vs liste actuelle (9 viandes)** : `Merguez, Kefta, Mexicain, Cordon Bleu, Viande Hachée, Nuggets, Escalope, Tenders, Fricandelle`.

→ **TOUTES archivées** (9 ItemVariations soft-deleted). 4 nouvelles créées.

**Owner gate Q5** : confirmer le set est uniquement 4 viandes Poulet, OU si on conserve aussi steak halal / cordon bleu / kefta pour certains produits.

---

## §5. SAUVEGARDE & ROLLBACK

### 5.1 Avant exécution (mandatory)

```bash
# 1. Snapshot git branche dédiée (instant, zero downtime)
git checkout -b backup/pre-menu-reset-le-cayenne-2026-05-13
git push origin backup/pre-menu-reset-le-cayenne-2026-05-13   # owner gate: OK to push?

# 2. Snapshot DB SQLite (paranoïa)
bash scripts/db/backup.sh \
  --env=local \
  --driver=sqlite \
  --database=storage/database.sqlite \
  --output-dir=storage/backups/menu-reset-2026-05-13 \
  --i-understand-backup
# → storage/backups/menu-reset-2026-05-13/<timestamp>/dump.sqlite + manifest.json

# 3. Snapshot config + mobile data (git tracked, mais paranoïa)
cp config/menu.php config/menu.php.backup-2026-05-13
cp mobile/data/menu.js mobile/data/menu.js.backup-2026-05-13
# → ignorer en .gitignore via backup-* pattern OU commit dans branche backup

# 4. Captures visuelles "avant" pour comparaison post-migration
npx playwright test tests/e2e/baseline-categories-pre-reset.spec.js
# → reports/audit/menu-reset-2026-05-13/before/*.png
```

### 5.2 Rollback plan (si exec foire ou owner change d'avis)

**Niveau 1 — soft (les 8 catégories ré-activées)** :
```php
DB::transaction(function () {
    ItemCategory::withTrashed()
        ->whereIn('id', [2,3,4,5,6,7,8,9])
        ->restore();
    Item::withTrashed()
        ->whereIn('item_category_id', [2,3,4,5,6,7,8,9])
        ->restore();
    // sync event
    event(new \App\Events\CatalogChanged('reset', null, 'restored', null));
});
```
→ ~5 secondes, restore complet, mobile/kiosk rebroadcast les anciennes catégories.

**Niveau 2 — full git revert** :
```bash
git checkout backup/pre-menu-reset-le-cayenne-2026-05-13
git checkout -B feature/mobile-app-le-cayenne-2026-05-10
git push --force-with-lease origin feature/mobile-app-le-cayenne-2026-05-10
# ⚠️ owner gate explicite — force push branche protégée
```

**Niveau 3 — DB restore from dump** :
```bash
cp storage/backups/menu-reset-2026-05-13/<timestamp>/dump.sqlite storage/database.sqlite
php artisan cache:clear
php artisan config:clear
```

---

## §6. SÉQUENCE D'EXÉCUTION (8 phases — toutes après owner gate)

> ⚠️ **STOP — Aucune phase n'est exécutée tant que l'owner n'a pas validé ce plan + répondu aux 5 Owner Gates Q1–Q5 ci-dessous.**

### Phase 0 — BACKUP (≤5 min)

- Git branch `backup/pre-menu-reset-le-cayenne-2026-05-13`.
- Dump DB SQLite.
- Cp config + mobile data.
- Captures baseline Playwright kiosk + admin + POS + KDS.
- **Acceptance** : branch poussé, dump existe, captures dans `reports/audit/menu-reset-2026-05-13/before/`.

### Phase 1 — MIGRATION SCHEMA (≤30 min, 0 ligne de code wizard)

Créer une migration **idempotente** :

```php
// database/migrations/2026_05_13_120000_menu_reset_le_cayenne_archive_and_seed.php
public function up(): void {
    DB::transaction(function () {
        // Pas d'ALTER TABLE — soft-delete via Eloquent suffit.
        // Migration sert juste de point d'entrée audit (deletion_log).
    });
}
```

**Pas de schema change** (toutes les colonnes nécessaires existent déjà : SoftDeletes, status, wizard_template, has_menu, channels, etc.).

### Phase 2 — ARTISAN COMMAND : `menu:archive-and-seed` (≤2j-agent)

Créer `app/Console/Commands/MenuArchiveAndSeedCommand.php` :

```php
class MenuArchiveAndSeedCommand extends Command
{
    protected $signature = 'menu:archive-and-seed
                            {--dry-run : Show what would be done without executing}
                            {--force : Skip interactive confirmation}';

    public function handle()
    {
        // 1. Confirm owner intent
        if (!$this->option('force') && !$this->confirm('Archive 8 cats + seed 4 new. Sure?')) return 1;

        // 2. Snapshot before (defensive)
        $beforeCount = ItemCategory::count();
        $this->info("Before: {$beforeCount} active categories");

        // 3. Archive (soft-delete) 8 categories not in keep list
        $archiveSlugs = [
            'nos-sandwichs', 'nos-burgers', 'nos-assiettes',
            'ojja', 'omelettes', 'nos-salades', 'chicken-tenders', 'nos-menus-enfants',
        ];
        DB::transaction(function () use ($archiveSlugs) {
            $cats = ItemCategory::whereIn('slug', $archiveSlugs)->get();
            foreach ($cats as $cat) {
                // Archive items first (soft-delete)
                $cat->items()->update(['deleted_at' => now()]);  // Item uses SoftDeletes
                $cat->delete();  // category soft-deleted
                // Audit trail
                DeletionLog::create([
                    'model_type' => ItemCategory::class,
                    'model_id'   => $cat->id,
                    'actor_type' => 'console',
                    'reason'     => 'menu:archive-and-seed 2026-05-13 owner-gated reset',
                ]);
                // Sync event
                event(new CategoryDeleted($cat->id, null));
            }
        });

        // 4. Rename 4 categories (kept)
        $renames = [
            'nos-tacos'              => ['slug' => 'tacos', 'name' => 'Tacos'],
            'frites-accompagnements' => ['slug' => 'frites', 'name' => 'Frites'],
            'nos-desserts'           => ['slug' => 'desserts', 'name' => 'Desserts'],
            'nos-boissons'           => ['slug' => 'boissons', 'name' => 'Boissons'],
            // 'supplements' reste comme tel
        ];
        foreach ($renames as $oldSlug => $new) {
            $cat = ItemCategory::where('slug', $oldSlug)->first();
            if ($cat) { $cat->update($new); event(new CategoryUpdated($cat->id, null)); }
        }

        // 5. Seed 4 new categories (idempotent : firstOrCreate)
        $newCats = [
            ['slug' => 'sandwich-cayenne', 'name' => 'Sandwich Cayenne', 'wizard_template' => 'sandwich', 'has_menu' => true, 'sort' => 1],
            ['slug' => 'galette',          'name' => 'Galette',          'wizard_template' => 'sandwich', 'has_menu' => true, 'sort' => 2],
            ['slug' => 'sandwich-classique','name' => 'Sandwich Classique','wizard_template' => 'sandwich', 'has_menu' => true, 'sort' => 3],
            ['slug' => 'bols-gourmands',   'name' => 'Bols Gourmands',   'wizard_template' => 'simple',   'has_menu' => false, 'sort' => 5],
            // tacos, frites, desserts, boissons, supplements re-sortés via $renames
        ];
        foreach ($newCats as $payload) {
            ItemCategory::firstOrCreate(['slug' => $payload['slug']], $payload);
            event(new CategoryCreated(ItemCategory::where('slug', $payload['slug'])->first()->id, null));
        }

        // 6. Update sort order to user-requested sequence
        $sortMap = [
            'sandwich-cayenne' => 1, 'galette' => 2, 'sandwich-classique' => 3, 'tacos' => 4,
            'bols-gourmands' => 5, 'frites' => 6, 'supplements' => 7, 'desserts' => 8, 'boissons' => 9,
        ];
        foreach ($sortMap as $slug => $sort) {
            ItemCategory::where('slug', $slug)->update(['sort' => $sort]);
        }

        // 7. Seed items via dedicated MenuSeederLeCayenne2026 (Phase 3)
        $this->call('db:seed', ['--class' => 'MenuLeCayenneReset20260513Seeder']);

        // 8. Verify
        $afterCount = ItemCategory::count();
        $this->info("After: {$afterCount} active categories (expected 9, was {$beforeCount})");

        return 0;
    }
}
```

### Phase 3 — SEEDER ITEMS + VARIATIONS + SAUCES + VIANDES (≤2j-agent)

Créer `database/seeders/MenuLeCayenneReset20260513Seeder.php` qui :
- Archive les 9 viandes existantes (ItemVariation soft-delete), créé 4 nouvelles ItemVariations canoniques.
- Archive les 6 sauces retirées (Burger/Barbecue/Cocktail/Américaine/Poivre/Sans Sauce), crée 4 nouvelles (Tandoori/Fromagère/Pimentée/Cayenne).
- Archive 25 items abandonnés.
- Crée nouveaux items (cf. §4.2) avec leurs variations + addons + extras attachés.

Idempotency : `firstOrCreate` partout, scoped par slug.

### Phase 4 — UPDATE `config/menu.php` (≤30 min)

Réécrire le block `categories` lignes 47-62 avec 9 categories nouvelle structure. Garder structure compatible MenuSeeder (idempotence). Garder meats / sauces sections alignées.

### Phase 5 — UPDATE `config/kiosk.php` (≤10 min)

- Ligne 86 : `parent_category_slug: 'nos-sandwichs'` → reconsidérer (l'ancien sandwich-split avait 4 sous-cats sandwiches "froides", ce besoin disparaît avec la nouvelle structure 3 cats sandwich séparées).
- Lignes 25-30 : `cold_item_slugs` → vider ou remplacer.

**Owner gate Q6** : sandwich-split kiosk UI (sidebar froid vs signature) à conserver ? Si non → simplifier kiosk.php.

### Phase 6 — UPDATE `mobile/data/menu.js` (≤1j-agent)

Réécriture complète :
- CATEGORIES array (9 entrées).
- MEATS array (4 viandes).
- SAUCES array (13 sauces).
- ITEMS array (nouveaux items par catégorie).
- SUPPLEMENTS array (10 items).
- Maintenir helpers `imgFor`, `heroFor`, `mkItem`.
- **Assets** : copier les nouveaux PNG manquants dans `mobile/assets/menu/` (Bols, Galette, Cayenne hero existants déjà).

### Phase 7 — UPDATE i18n (`lang/fr|en|ar/*`) (≤1j-agent)

- Pas de cat-spécifique key visible (les noms cats viennent du DB).
- Vérifier wizards step labels (`wizard.step_*`) restent valides : oui car wizard frozen non touché.
- Ajouter clés `kiosk.catalog.sauce_locked_cayenne_label` si on affiche "Sauce Cayenne (incluse)" en step recap.

### Phase 8 — VERIFY (mandatory §5 LOOP CLAUDE.md)

#### 8.1 Tests techniques
```bash
php artisan test --filter='Menu|Catalog|ItemCategory|Item'
npm run test:unit -- --testPathPattern='menu|catalog|kiosk'
```

#### 8.2 E2E Playwright
```bash
npx playwright test tests/e2e/kiosk-categories-after-reset.spec.js
npx playwright test tests/e2e/admin-categories-after-reset.spec.js
npx playwright test tests/e2e/pos-wizard-after-reset.spec.js
```

#### 8.3 Visual evidence (§6 CLAUDE.md mandatory)
Captures sur les 7 surfaces production :
- `http://127.0.0.1:8000/kiosk/idle` → 9 catégories visibles dans l'ordre
- `http://127.0.0.1:8000/admin/pos` → POS sidebar 9 catégories
- `http://127.0.0.1:8000/admin/items` → admin items list filtré par nouvelles cats
- `http://127.0.0.1:8000/admin/categories` → liste 9 active + 8 archivées (toggle "show archived")
- `http://127.0.0.1:8000/kds` → KDS reçoit orders sur nouveaux items
- `http://127.0.0.1:8000/order-status-screen` → OSS pas impacté
- Mobile : `http://127.0.0.1:8081/` → mobile/index.html nouvelle catalog

#### 8.4 Sync verification
- Vérifier `domain_events` table : 8 `CategoryDeleted` + 4 `CategoryCreated` + 4 `CategoryUpdated` = 16 events fan-out.
- Vérifier Pusher channel `private-branch.{id}` reçoit les broadcasts (ou polling fallback marche).

#### 8.5 Order history regression check
```bash
# Vérifier qu'un old order créé en cat archivée reste lisible
php artisan tinker
> Order::with('orderItems')->find(<old-order-id>)->orderItems->first()->composition_snapshot
# Doit afficher les champs item_name + variation_name + extras intactes
```

---

## §7. RISQUES IDENTIFIÉS & MITIGATIONS

| # | Risque | Sévérité | Mitigation |
|---|--------|----------|------------|
| R1 | POS wizard frozen n'a PAS de case `bols` dans son switch — fallback dangereux | **HIGH** | Mettre `wizard_template='simple'` sur bols-gourmands, qui force le path "recap-only" déjà testé (utilisé par desserts/boissons/menu_enfant). Alternative : ajouter mini-step via composer-aware gate. |
| R2 | Kiosk sandwich-split logic (config/kiosk.php) repose sur slug `nos-sandwichs` | MEDIUM | Soit garder cat technique `nos-sandwichs` archivée (slug intact dans DB), soit reconfigurer `parent_category_slug` ou désactiver split. **Owner gate Q6**. |
| R3 | `KIOSK_HIDDEN_CATEGORY_IDS = new Set([315])` hardcoded dans kioskMenu.js — id obsolète | LOW | Vérifier que id 315 n'existe pas ou est déjà archivé. Si existe, soft-delete + supprimer la const. |
| R4 | Mobile app embarque menu.js statique : si app déjà déployée en prod, requires rebuild + redeploy | MEDIUM | Inclure mobile rebuild dans la séquence ; documenter dans CONNECTION_PLAN.md ; alternativement implémenter `/api/v1/frontend/menu` que mobile fetcherait au boot (hors scope ce plan). |
| R5 | `item_wizard_profiles` FK CASCADE — soft-delete cat ne supprime pas le profile, mais hard-delete oui | LOW | On ne hard-delete jamais. Vérifier qu'aucun profile orphelin ne pointe vers cat soft-deleted (cleanup post-archive optionnel). |
| R6 | Tests existants assertent peut-être des slugs/names ancien menu (e.g. "Nos Tacos") | MEDIUM | Sub-agent #4 a confirmé : les tests utilisent factory IDs, pas slugs. Mais un grep `'Nos Tacos\|Nos Sandwichs\|Nos Burgers\|...'` sur tests/ + reports/ à faire avant exec pour catch les exceptions. |
| R7 | Ordering : si on archive une cat AVANT d'archiver ses items, FK RESTRICT bloque | LOW | Ordre dans Phase 2 : items first, then cat. `$cat->items()->delete()` AVANT `$cat->delete()`. |
| R8 | Sync outbox : 8 CategoryDeleted événements × N branches = explosion d'events | LOW | Idempotency key sur outbox empêche doublons. Pusher rate-limit OK pour 8-12 events. |
| R9 | Old order viewer (admin/orders/{id}) référence cat archivée pour affichage | LOW | composition_snapshot ne stocke pas category_name. Order resource construit category via `item.category_id` join. Avec `withTrashed()` ou query sans cat-scope, OK. À vérifier dans Phase 8.5. |
| R10 | POS hardcoded items in `public/js/pos-wizard.js:65-83 ALL_SAUCES` (15 sauces) ne matche pas nouveau set 13 | MEDIUM | Le wizard frozen affiche emoji par lookup name → name introuvable = emoji blanc, pas crash. Acceptable temporairement. Owner gate post-MVP pour update wizard. |
| R11 | Branches multiples : si plusieurs restaurants tournent sur même DB, leurs items partagent les categories. Reset = global. | **HIGH** | Confirmer avec owner que ce reset s'applique à TOUTES les branches (single-tenant DB) ou seulement branch Le Cayenne (id=?). Si multi-tenant : restreindre soft-delete via `branch_item.branch_id` (pas applicable car cats sont globales, pas branche-scoped). **Owner gate Q7**. |
| R12 | Tests sentinels `posKioskVariationParity` (cf. BRAIN §2 P0-14) comparent fixtures menu vs vraies items → casseront | LOW | Update les fixtures `tests/fixtures/menu-snapshot.json` post-migration. Inclure dans Phase 8.1. |

---

## §8. ACCEPTANCE CRITERIA

### 8.1 État final attendu

- ✅ DB `item_categories` : 9 actives + 8 soft-deleted (deleted_at NOT NULL).
- ✅ DB `items` : nouvelles items créés pour 4 new cats + ~25 items soft-deleted.
- ✅ `config/menu.php` : 9 catégories listées, structure cohérente.
- ✅ `mobile/data/menu.js` : 9 categories, 4 meats, 13 sauces, items alignés.
- ✅ `config/kiosk.php` : sandwich-split logic adaptée ou désactivée (selon owner Q6).
- ✅ Tests : PHPUnit Menu|Catalog filter vert (100%), Vitest catalog suite vert.
- ✅ Playwright : kiosk + admin + POS smoke tests verts (9 cats affichées correctement).
- ✅ Visual : captures dans `reports/audit/menu-reset-2026-05-13/after/` validées (read+analysées par Claude).
- ✅ Sync : `domain_events` montre 16 events idempotency-protected ; Pusher OR polling fallback fonctionne.
- ✅ Order history : 5 anciens orders aléatoires affichent leurs items correctement (composition_snapshot intact).
- ✅ Frozen-zones : 0 ligne diff sur `public/js/pos-wizard.js`, `resources/js/components/frontend/kiosk/KioskWizard*Component.vue`, NF525, BranchScope, PricingService.
- ✅ Backup : branche `backup/pre-menu-reset-le-cayenne-2026-05-13` poussée, dump SQLite stocké.
- ✅ Rollback : `php artisan tinker` test restore des 8 cats archivées → 17 cats active à nouveau (puis re-archive).

### 8.2 Definition of Done

- BRAIN.md §2 §3 §4 mis à jour avec timestamp + résumé.
- Graphiti épisode pushé (`group_id: foodking`).
- Plans déplacés/archivés (`plans/ULTRA_PLAN_MENU_RESET_LE_CAYENNE_2026-05-13.md` annoté DONE).
- Owner reçoit un récap court avec : commits, captures, blocker éventuels.

---

## §9. NON-SCOPE EXPLICITE

Ne PAS faire dans ce cycle (différé) :

1. **Code wizard POS/Kiosk** : 0 ligne touchée (frozen, owner gate strict).
2. **Mobile API menu sync** : pas d'endpoint `/api/v1/frontend/menu` (hors scope, V0 reste offline).
3. **UI admin "Archiver" dédiée** : on utilise `destroy` (soft-delete) existant ; pas de nouveau bouton.
4. **Scopes Eloquent `active()`/`archived()`** : pas ajoutés (queries explicites avec `whereNull('deleted_at')` suffisent).
5. **Frites_style upgrade sur catégorie Frites standalone** : pas activé par défaut, owner Q2.
6. **Modification de NF525/Fiscal/BranchScope/PricingService** : zéro impact.
7. **Sandwich-split kiosk UI logic** : refactor si owner Q6 dit "remove", sinon laissé comme tel.
8. **Wizard 'bols' custom step** : utiliser data-only (`wizard_template='simple'`) ; refactor wizard code = future cycle.

---

## §10. OWNER GATES REQUIS (avant exécution Phase 0)

Owner doit répondre aux 7 questions ci-dessous **avant** que Claude ne lance la moindre commande modifiante :

### Q1 — Bols Gourmands wizard
> "Vous avez dit 'pas de wizard pour les bols'. Concrètement :
> - **(a)** wizard zéro modal — l'utilisateur clique sur "Bol Curry" et c'est directement dans le panier (pas de choix de base frites/riz, pas de supplément). Dans ce cas il faut 5 produits séparés × 2 bases = 10 items distincts.
> - **(b)** wizard minimal 1-2 steps — choix de la base (frites ou riz) puis recap. Suppléments laissés optionnels.
> → **Plan default = (b)** (recommandé pour UX cohérente). Confirmer ou switch."

### Q2 — Frites standalone : style upgrade ?
> "Pour la catégorie 'Frites' (petite + grande standalone), conserve-t-on la possibilité d'upgrade 'Cheddar fondu / Cheddar+Oignons croustillants' au moment de l'achat ?
> - **(a)** oui : wizard light 1 step choix style (Nature/Cheddar/Cheddar+Oignon)
> - **(b)** non : flat add-to-cart instantané
> → **Plan default = (b)** (flat). Confirmer."

### Q3 — Suppléments naming
> "Dans votre liste 'Suppléments', vous citez 'Boule gratinée'. Est-ce le même produit que l'item existant 'Galette pommes de terre' (cat 13 ancienne) ? Ou un nouveau produit (boule de mozzarella gratinée par exemple) ?
> → Affecte le mapping seed."

### Q4 — Set des 13 sauces
> "Sauces à conserver : Mayonnaise, Ketchup, Algérienne, Samouraï, Curry, Andalouse, Harissa, Hannibal, Blanche, Tandoori, Fromagère, Pimentée, Cayenne. Confirmé ?
> Sauces à archiver : Burger, Barbecue, Cocktail, Américaine, Poivre, Sans Sauce. OK ?"

### Q5 — Set des 4 viandes
> "Viandes restantes uniquement : Poulet classic, Poulet curry, Poulet tandoori, Poulet crispy.
> ⚠️ Question : ces 4 viandes s'appliquent aux **bols** (vous l'avez dit) ET aux **sandwiches/galettes/tacos** ? Ou les sandwiches gardent une liste plus large (kefta, merguez, cordon bleu, etc.) ?
> → Affecte le seed des ItemVariations partagées."

### Q6 — Sandwich-split kiosk UI
> "Le kiosk a actuellement une logique 'sandwich-split' qui sépare en sidebar les sandwiches signature des sandwiches froids (slugs cold_item_slugs: panini, sandwich-froid, etc.). Avec la nouvelle structure (3 catégories sandwich séparées), cette logique devient obsolète.
> - **(a)** désactiver complètement (vider `config/kiosk.php` cold_item_slugs)
> - **(b)** garder (et alimenter avec nouveaux slugs)
> → **Plan default = (a)** (désactiver, plus simple)."

### Q7 — Périmètre multi-tenant
> "Ce reset s'applique à **toutes les branches** sur la même DB ou seulement à la branche Le Cayenne ?
> Si plusieurs restaurants tournent sur cette instance et que vous voulez juste Le Cayenne, le plan doit être adapté (les `item_categories` sont globales et non branchées).
> → **Plan default = single-tenant Le Cayenne**. Confirmer."

---

## §11. SUB-AGENTS GSTACK PLANIFIÉS POUR EXÉCUTION (après owner gate)

Si owner valide, Claude lance en parallèle (selon §4 CLAUDE.md) :

| Agent | Mission | Phase | Read/Write |
|-------|---------|-------|------------|
| **Architect** | Vérifier que aucune dépendance code cachée n'est cassée par la rename slug (grep tests + reports + fixtures) | Pre-Phase 2 | Read-only |
| **DBA** | Valider migration idempotency + FK cascade behavior + deletion_log audit completeness | Phase 1-2 | Read-only |
| **Tester** | Update fixtures `tests/fixtures/menu-snapshot.json` + écrire 3 nouveaux specs Playwright (kiosk-9cats / admin-9cats / pos-9cats) | Phase 8.1-8.3 | Write tests only |
| **Security** | Audit que aucune route n'expose items soft-deleted en prod (vérifier scopes Frontend/Item*Resource) | Phase 8 | Read-only |
| **A11y** | Vérifier visuellement les nouvelles labels (Sandwich Cayenne / Galette / Bol Curry...) ne créent pas de raw-label ni de débordement | Phase 8.3 | Read-only |
| **SRE** | Monitorer queue jobs (`PersistCatalogChangedToOutbox` × 16 events) + Pusher rate-limit | Phase 8.4 | Read-only |
| **Adversarial Red-Team** | Catch any miss : i18n key sans translation, image manquante, FK orphan, regression order history | Post-Phase 8 | Read-only |

---

## §12. ESTIMATION GLOBALE

| Phase | Effort | Wall-clock |
|-------|--------|------------|
| Phase 0 Backup | 5 min | 5 min |
| Phase 1 Migration | 30 min | 30 min |
| Phase 2 Command Archive+Seed | 2j-agent | ~3-4h actual |
| Phase 3 Seeder items | 2j-agent | ~3-4h actual |
| Phase 4 config/menu.php | 30 min | 30 min |
| Phase 5 config/kiosk.php | 10 min | 10 min |
| Phase 6 mobile/data/menu.js | 1j-agent | ~2-3h actual |
| Phase 7 i18n | 1j-agent | ~1-2h actual |
| Phase 8 Verify (tests+visual+sync+history) | 1j-agent | ~2-3h actual |
| **TOTAL** | **~7-8j-agent** | **~12-15h wall-clock** |

→ **Single-session feasible** sur ~12-15h Claude Code avec sub-agents parallèles.

---

## §13. DECISION FRAMEWORK (§10 CLAUDE.md)

À l'issue de chaque phase, Claude rend l'un de :
- ✅ **continue** : phase OK, next phase
- 🟡 **heal** : minor issue détecté, fix in-loop max 3 cycles
- 🔴 **block** : major issue, stop + escalate
- 🚨 **escalate à owner** : decision ambiguous

**Trigger d'escalation auto** : si après Phase 1 (migration) un quelconque order ancien devient illisible OU si frozen-zones bougent OU si Playwright kiosk perd >1 catégorie visible OR si une route API renvoie 500.

---

## §14. STATUS DE CE PLAN

- ⏸️ **DRAFT — owner gate required**
- ⏳ En attente de réponses aux 7 questions §10
- 📋 Sera promu à **READY** dès owner OK + Q1-Q7 résolues
- ❌ Aucune commande modifiante n'est exécutée tant que ce plan n'est pas approuvé

---

**Auteur** : Claude Code (single-agent + 6 sub-agents Explore parallèles)
**Cartographie sources** : `app/Models/ItemCategory.php`, `database/migrations/2022_11_17_*`, `2026_*`, `config/menu.php`, `config/kiosk.php`, `public/js/pos-wizard.js` (read-only), `resources/js/store/modules/kioskMenu.js`, `mobile/data/menu.js`, `PROJECT_BRAIN.md`, Graphiti `foodking` group.
**Validité** : 7 jours (re-cartographier si Phase 0 lancée après 2026-05-20).
