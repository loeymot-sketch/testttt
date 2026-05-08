# Ultra-Review Prompts — 4 Batches Copy-Paste Ready
**Branche :** `claude/blissful-mclean-c915c2`
**Base :** `b8b4fb76` (merge-base avec main)
**Head :** `8126fd26e` (8 commits design)
**Total fichiers :** 80 (49 code + 31 plans/tests/routes/i18n/assets)

> **Mode d'emploi :** chaque section ci-dessous est un **prompt autonome copy-paste-ready** pour ton outil ultra-review. Lance-les **un par un** (pas en parallèle) pour rester sous le seuil de taille.
>
> **Ordre recommandé** : Batch 1 → 2 → 3 → 4 (dépendances logiques croissantes).
> Après chaque batch, applique les findings avant de lancer le suivant.

---

## 📋 Découpage stratégique

| Batch | Périmètre | Fichiers | LOC | Focus | Criticité |
|---|---|---|---|---|---|
| **1/4** | Backend PHP (security + business logic) | 18 | ~2400 | Auth + branch isolation + defense-in-depth | 🔴 P0 |
| **2/4** | Frozen-zone audit Cart + Payment | 7 (4 code + 3 plans) | ~600 | Owner-gate discipline + 0 logic change | 🔴 P0 |
| **3/4** | Greenfield Vue (POC + admin tools + voice) | 14 | ~2600 | Drag/drop + a11y + admin UX + new routes | 🟠 P1 |
| **4/4** | Additive Vue + DS + i18n + bootstrap | 13 | ~2100 | Additive only + opt-in flags + tokens | 🟡 P2 |

**Avant chaque batch** : pull la branche `claude/blissful-mclean-c915c2` localement.
**Après chaque batch** : note les findings dans un doc séparé pour pouvoir réagir entre les passes.

---

# 🔴 BATCH 1/4 — Backend PHP (security + business logic)

## Copy-paste ce qui suit dans ton ultra-review :

```markdown
# Ultra-Review Batch 1/4 — Backend PHP (FoodKing Kiosk Design)

## Contexte projet
FoodKing = SaaS restaurant multi-tenant Laravel 9 + Vue 3.
Invariants critiques :
- **Backend = source of truth pricing** (jamais price calc côté client)
- **BranchScope** = strict branch isolation entre tenants
- **NF525 fiscal compliance** (France) — sequence + audit logs HMAC
- **Sanctum abilities** : `kiosk:order` pour borne, `manage-*` pour admin
- **Spatie permissions** (settings, items_edit, etc.)
- **Outbox pattern** `domain_events` + Pusher broadcast

Branche en review : `claude/blissful-mclean-c915c2` (8 commits design).
Cette review cible **uniquement le backend PHP livré dans ce cycle**.

## Périmètre review (18 fichiers)

### Controllers HTTP (4)
- app/Http/Controllers/Frontend/OrderRatingController.php (81 LOC) — endpoint POST 5-star CSAT post-commande kiosk
- app/Http/Controllers/Frontend/UpsellRecommendationController.php (62 LOC) — endpoint kiosk POST /api/upsell-recommendations
- app/Http/Controllers/Admin/UpsellPreviewController.php (82 LOC) — admin QA tool POST /api/admin/upsell-preview
- app/Http/Controllers/Admin/KioskThemeController.php (130 LOC) — admin theme CRUD per-branch

### Services & Strategy pattern (3)
- app/Services/Recommendation/UpsellRecommendationService.php (50 LOC) — interface
- app/Services/Recommendation/Strategies/RuleBasedStrategy.php (315 LOC) — heuristique production
- app/Services/Recommendation/Strategies/MlPlaceholderStrategy.php (39 LOC) — fallback safe

### Models & migrations (4)
- app/Models/OrderRating.php (46 LOC) — nouveau model
- app/Models/Branch.php (modif +4 LOC) — fillable additif `active_theme`
- database/migrations/2026_05_08_050000_create_order_ratings_table.php (45 LOC)
- database/migrations/2026_05_08_060000_add_active_theme_to_branches.php (47 LOC)

### Configuration & Bootstrap (3)
- app/Providers/AppServiceProvider.php (modif +17 LOC) — strategy container binding
- config/recommendation.php (46 LOC) — strategy config
- routes/api.php (modif +41 LOC) — POST /api/admin/upsell-preview registered

### Tests Feature PHPUnit (4)
- tests/Feature/Frontend/OrderRatingTest.php (211 LOC, 7 tests)
- tests/Feature/Recommendation/UpsellRecommendationTest.php (349 LOC, 8 tests)
- tests/Feature/Admin/UpsellPreviewControllerTest.php (230 LOC, 7 tests)
- tests/Feature/Admin/KioskThemeControllerTest.php (192 LOC)

## Focus de review

### 🔴 P0 — Sécurité (BLOQUANT si fail)
1. **Auth & abilities** sur chaque endpoint :
   - OrderRating : authenticated user, customer of the order
   - UpsellRecommendation kiosk : sanctum ability `kiosk:order`
   - UpsellPreview admin : permission staff/admin only — vérifier le test `staff_cannot_access_upsell_preview`
   - KioskTheme : permission `settings` ou `manage-kiosk-themes`
2. **Branch isolation strict** :
   - Aucun controller ne doit fuiter cross-branch data
   - Vérifier `BranchScope` ou filtre `branch_id` explicite
   - Test `cross_branch_user_cannot_rate_other_branch_order` doit pass
3. **Defense in depth — UpsellPreviewController** :
   - Auteur a explicitement remplacé `Str::studly($strategy) . 'Strategy'` par `match()` explicite
   - Vérifier qu'il n'y a AUCUNE autre dynamic class injection / eval / unserialize
4. **Validation** : Form Request ou inline validation strict sur :
   - `OrderRating` : rating 1-5 integer, comment max 500
   - `UpsellRecommendation` : cart_items array required, branch_id required
   - `UpsellPreview` : strategy enum `['rule_based', 'ml_placeholder']`, branch_id, cart_items
   - `KioskTheme` : theme enum `['standard', 'halloween', 'christmas']`, branch_id

### 🔴 P0 — Architecture & business invariants
5. **Pricing source of truth** : aucun controller ne doit calculer ou stocker un price reçu du client. RuleBasedStrategy doit toujours lire price depuis Item model (DB)
6. **N+1 queries** dans RuleBasedStrategy.php (315 LOC) : eager-loading correct sur les joins ?
7. **Pure functions** : strategies doivent être stateless / sans side-effect (logging acceptable mais pas DB write hors lecture)
8. **Migrations safe** :
   - `order_ratings` : index sur `branch_id` + `order_id`, FK avec onDelete behavior, soft deletes ?
   - `add_active_theme_to_branches` : nullable + default null + reversible (down migration cleanly)
9. **Provider binding** : `AppServiceProvider` strategy container binding — pas de fuite de scope, vérifier qu'on bind via interface

### 🟠 P1 — Tests coverage
10. **PHPUnit 4 fichiers, ~26 tests cumulés** :
    - Edge cases couverts : auth manquant, branch_id missing, strategy invalide, cart vide, ml fallback rule_based
    - Asserts sur HTTP status codes 422 / 401 / 403 / 200
    - Asserts sur réponse JSON shape
11. **Performance** : `returns_latency_measurement` test sur UpsellPreview valide ?

### 🟡 P2 — Code quality
12. PSR-12 compliance, type hints PHP 8+ partout
13. PHPDoc sur méthodes publiques
14. Nommage cohérent (FR business, EN code)
15. Pas de TODO / FIXME laissés

## Critical questions à investiguer

Q1 — Le `UpsellPreviewController::preview()` reçoit `branch_id` du body. Est-ce vérifié contre les permissions du user ? Un admin tenant A peut-il preview tenant B ?

Q2 — `RuleBasedStrategy` — quelle est sa logique de scoring ? Y a-t-il un risque de recommander des items inactifs / out-of-stock ? Est-ce que `item_branch_availability` est checké ?

Q3 — `OrderRating` model — y a-t-il un index unique sur `(order_id, customer_id)` pour empêcher les doubles ratings ? Sinon, le test `same_order_rating_update_or_creates` est-il vraiment safe en concurrent ?

Q4 — `KioskThemeController` — la modification de `active_theme` déclenche-t-elle un domain_event Outbox pour propager le changement aux bornes en realtime via Pusher ?

Q5 — Les migrations sont-elles compatibles SQLite (test) ET MySQL (prod) ? Notamment les FK constraints + index syntax.

## Acceptance criteria

- [ ] **Aucune** vulnérabilité d'injection / authorization bypass
- [ ] **0** test PHPUnit failing (run avec `php artisan test --filter "UpsellPreview|OrderRating|UpsellRecommendation|KioskTheme"`)
- [ ] **0** N+1 query dans RuleBasedStrategy
- [ ] Branch isolation strict vérifié dans les 4 controllers
- [ ] Migrations reversible (down() implémenté correctement)
- [ ] Defense-in-depth `match()` dans UpsellPreviewController confirmé
- [ ] Pas de `Str::studly`, `eval()`, `unserialize()`, `call_user_func` non-validé

## Output attendu

Pour chaque fichier listé : statut **OK / WARN / BLOCK** + findings spécifiques + recommandation.
À la fin : verdict global **MERGE / HEAL / BLOCK** + liste des actions correctives prioritaires.
```

---

# 🔴 BATCH 2/4 — Frozen-zone audit Cart + Payment (owner-gate discipline)

## Copy-paste ce qui suit dans ton ultra-review :

```markdown
# Ultra-Review Batch 2/4 — Frozen-Zone Cart+Payment (FoodKing Kiosk)

## Contexte projet
FoodKing kiosk = bornes self-order restaurant.
**Discipline frozen-zones** : 8 composants Vue kiosk + 2 agent files sont OWNER-FROZEN.
Toute modification requiert un **gate explicite** documenté.

Pour ce cycle, l'owner a **explicitement débloqué** le `KioskCartComponent.vue` UNIQUEMENT pour 3 items design (V1x-1 + V1x-3 + V1x-6) — ZÉRO modif logique autorisée.
Le `KioskPaymentComponent.vue` est agent F-002/F-008/F-009 territory : modifs **scope-minimal additive** seulement (M-4 + V1x-2 + V1x-1).

Cette review cible uniquement la conformité frozen-zone discipline.

## Périmètre review (7 fichiers)

### Code touché — frozen-gate executed (4)
- resources/js/components/frontend/kiosk/KioskCartComponent.vue (V1x-1 spacing tokens + V1x-3 image clamp + V1x-6 aria-label)
- resources/js/components/frontend/kiosk/KioskPaymentComponent.vue (M-4 microcopy + V1x-2 modal + V1x-1 spacing)
- resources/css/kiosk/tokens.css (additif: --kiosk-space-7=28px, --kiosk-space-11=44px, --kiosk-opacity-disabled=0.5)
- tests/js/KioskCartRestyle.spec.js (assertion V1x-6 aria-label additive)

### Plans gate documentation (3)
- plans/PLAN_DESIGN_V1X1_SPACING_TOKENS_2026-05-08.md (mapping px→tokens)
- plans/PLAN_DESIGN_V1X3_CART_IMAGE_RESPONSIVE_2026-05-08.md (Option A safe choisi)
- plans/PLAN_DESIGN_V1X6_CART_VARIATIONS_ARIA_2026-05-08.md (Option B extensive choisi)

## Focus de review

### 🔴 P0 — Frozen-zone discipline (BLOQUANT)

1. **KioskCartComponent.vue diff audit** :
   - Strictement V1x-1 (~30 props CSS spacing migrées vers var(--kiosk-space-*))
   - V1x-3 image responsive : `.kiosk-cart-item-img { width: clamp(64px, 4.7vw, 96px); height: clamp(64px, 4.7vw, 96px); }` + `.kiosk-cart-item-emoji { font-size: clamp(32px, 2.4vw, 48px); }`
   - V1x-6 aria : 3 templates `:title` + `:aria-label` sur `.kiosk-cart-item-name`, `.kiosk-cart-item-selections`, `.kiosk-cart-item-note`
   - **AUCUNE** modif `<script>` (computed, methods, data, watch)
   - **AUCUN** nouveau import / new component
   - **AUCUNE** modif sur `getItemSelectionSummary()` méthode existante (déjà présente lignes 434-462)

2. **KioskPaymentComponent.vue audit additive** :
   - M-4 : section `🔒 Transaction sécurisée TLS 1.3` + 5 logos cartes (additive section, pas modif des sections existantes)
   - V1x-2 : modal payment refusé (additive `<div>` section + scoped CSS)
   - V1x-1 : ~20 props CSS spacing migrées vers tokens
   - **AUCUNE** modif state machine `confirmPayment` / `cancelPayment` / `simulateCardSuccess`
   - **AUCUNE** modif appel API
   - **AUCUNE** modif emit / props

3. **tokens.css additif strict** :
   - 3 nouveaux tokens AJOUTÉS (pas modif tokens existants)
   - Valeurs px exactes (28px, 44px, 0.5) qui matchent les usages dans Cart+Payment
   - Pas de breaking change

4. **tests/js/KioskCartRestyle.spec.js** :
   - Test V1x-6 ajout (assertion `:aria-label` non vide sur item.name + selections + note)
   - Tests V1x-1 et V1x-3 existants doivent toujours pass

### 🔴 P0 — Owner gate documentation
5. Plans V1x-1/3/6 doivent contenir :
   - Decision owner trace (Option A/B/C choisie)
   - Acceptance criteria executed
   - Pas de drift par rapport au brief original

### 🟠 P1 — Visual regression risk
6. **V1x-3 image clamp Option A** : 1080p doit rester strictement 64×64 (inchangé), 4K scale ~96
7. **V1x-1 spacing migration** : 0 changement visuel rendu (tokens === valeurs px exactes)
8. **V1x-6 aria** : pas de modif visuelle DOM rendu (juste attributes title/aria-label)

## Critical questions à investiguer

Q1 — Y a-t-il un `<style scoped>` block modifié dans KioskCartComponent ? Si oui, lister les sélecteurs touchés et confirmer qu'ils correspondent SEULEMENT à V1x-1+V1x-3+V1x-6.

Q2 — Le `<template>` de KioskCartComponent : combien de balises `:title` + `:aria-label` ajoutées ? Doit être 3 (name, selections, note). Si 0, 1, 2, 4+ → drift detected.

Q3 — KioskPaymentComponent : la modal V1x-2 est-elle conditionnée par un état (ex: `showPaymentErrorModal`) ? Si oui, où est-il toggled ? Si new state ajouté, est-il vraiment additive ou touche-t-il logique existante ?

Q4 — Les 3 nouveaux tokens (--kiosk-space-7, -11, --kiosk-opacity-disabled) sont-ils utilisés ailleurs en plus de Cart+Payment ? S'ils sont utilisés dans wizard frozen-zone, c'est un signal de drift caché.

Q5 — Les plans V1x-1/3/6 sont-ils alignés avec ce qui est réellement livré ? Vérifier que decisions documentées correspondent au code.

## Anti-drift commands

Run ces commandes et vérifier expected outputs :
```bash
# 1. Cart : aucune modif <script> section
git diff main..HEAD -- resources/js/components/frontend/kiosk/KioskCartComponent.vue \
  | grep -E "^\+.*\b(methods|computed|data|watch|created|mounted)" | head -5
# Expected: empty

# 2. Cart : 3 templates aria ajoutés
git diff main..HEAD -- resources/js/components/frontend/kiosk/KioskCartComponent.vue \
  | grep -cE "^\+.*:aria-label="
# Expected: 3

# 3. Payment : pas de modif state machine
git diff main..HEAD -- resources/js/components/frontend/kiosk/KioskPaymentComponent.vue \
  | grep -E "^\+.*\b(confirmPayment|cancelPayment|simulateCardSuccess)\b" | head -5
# Expected: empty

# 4. Tokens additifs strict (pas de modif existants)
git diff main..HEAD -- resources/css/kiosk/tokens.css \
  | grep "^-" | grep -v "^---" | head -10
# Expected: empty (que des additions, pas de suppression)
```

## Acceptance criteria

- [ ] KioskCartComponent.vue : 0 modif `<script>` section
- [ ] KioskCartComponent.vue : exactement 3 nouveaux `:aria-label` dans `<template>`
- [ ] KioskPaymentComponent.vue : 0 modif state machine / API calls
- [ ] tokens.css : que des additions (3 nouveaux tokens, 0 modif existant)
- [ ] KioskCartRestyle.spec.js : tests existants pass + 1 nouveau pour V1x-6
- [ ] Plans V1x : décisions Option A/B documentées et alignées avec code
- [ ] **Hypothèse 0 régression visuelle** : 1080p inchangé, 4K scale, aria invisible (DOM only)

## Output attendu

Confirmation écrite **discipline frozen-zone respectée** avec preuves diff (commandes ci-dessus).
Si drift détecté : liste exacte des modifs hors scope V1x-1/3/6 avec ligne/section.
Verdict : **GATE RESPECTED / DRIFT MINOR / DRIFT MAJOR**.
```

---

# 🟠 BATCH 3/4 — Greenfield Vue (POC drag-drop + admin tools + voice dialog)

## Copy-paste ce qui suit dans ton ultra-review :

```markdown
# Ultra-Review Batch 3/4 — Frontend Greenfield (FoodKing Kiosk Vue 2)

## Contexte projet
FoodKing kiosk frontend = Vue 2.x + Vuex + Vue-Router + i18n + axios.
4 features greenfield livrées dans ce cycle :
- **V2-2** : POC drag-drop ingrédients (admin route, hors prod kiosk publique)
- **V2-5 Phase 2** : admin theme manager UI (sélection thème saisonnier per-branche)
- **V2-3** : admin upsell preview tool (QA tool hors prod)
- **V2-4** : voice ordering dialog (modal pré-flight avant kiosk navigation)

Tous ces composants sont **greenfield** = aucun composant existant touché en logique.
Le `KioskIdleScreenComponent.vue` reçoit additive voice CTA, audité dans Batch 4.

Cette review cible la qualité Vue + a11y + UX + tests des 14 fichiers greenfield.

## Périmètre review (14 fichiers)

### V2-2 POC Drag & Drop (4)
- resources/js/components/frontend/kiosk/builder/KioskBurgerBuilder.vue (340 LOC)
- resources/js/components/frontend/kiosk/builder/KioskBurgerLayer.vue (116 LOC)
- resources/js/components/frontend/kiosk/builder/KioskBurgerBuilderPoc.vue (124 LOC)
- resources/js/router/modules/kioskBurgerBuilderPocRoutes.js (route admin /kiosk/burger-builder-poc)

### V2-5 Phase 2 Theme Manager Admin UI (3)
- resources/js/components/admin/kioskTheme/KioskThemeManagerPage.vue (332 LOC)
- resources/js/components/admin/kioskTheme/KioskThemePreviewCard.vue (163 LOC)
- resources/js/router/modules/kioskThemeAdminRoutes.js (route admin)

### V2-3 Upsell Preview Admin Tool (2)
- resources/js/components/admin/upsellPreview/UpsellPreviewPage.vue (394 LOC)
- resources/js/router/modules/upsellPreviewRoutes.js (route admin)

### V2-4 Voice Ordering UI (2)
- resources/js/components/frontend/kiosk/KioskVoiceOrderingButton.vue (269 LOC)
- resources/js/components/frontend/kiosk/KioskVoiceOrderingDialog.vue (185 LOC)

### Tests Vitest (3)
- tests/js/KioskBurgerBuilder.spec.js (175 LOC, 10 tests)
- tests/js/KioskThemeManagerPage.spec.js (243 LOC, 15 tests)
- tests/js/kioskVoiceOrderingDialog.spec.js (68 LOC, 6 tests)

## Focus de review

### 🟠 P1 — Sortable.js / vue-draggable-next correctness (V2-2)
1. **vue-draggable-next v2.3.0 limitations** :
   - Lib supporte UNIQUEMENT default slot (pas `#item` slot template)
   - Le code utilise pattern `v-for` directement INSIDE `<draggable>` (pattern correct du codebase ItemCateogryListComponent.vue)
   - Vérifier qu'il n'y a pas de `<template #item>` qui casserait silencieusement
2. **Drag-drop pattern** :
   - Source pool : `pull: 'clone'` mode (drag clone vers cible, source pool reste intact)
   - Drop zone : event `@change` émet `update:item_extras` parent
   - z-index layers correct pour visualisation stack burger
3. **Touch + mouse support** : Sortable.js handle natif via lib

### 🔴 P0 — A11y WCAG 2.1.1 (drag-drop alternative keyboard)
4. **Keyboard alternative obligatoire** (drag-drop fragile WCAG) :
   - Tab pour navigate ingredients
   - Enter pour "lift" (mark as selected)
   - Flèches haut/bas pour move dans burger zone
   - Enter pour "drop"
   - Escape pour cancel
5. **ARIA labels** :
   - Source items : `aria-label="Ingrédient {name}, supplément {price}€"`
   - Drop zone : `aria-label="Votre burger en construction"`
   - `aria-grabbed` ou ARIA 1.2 pattern correct
6. **Focus management** : `:focus-visible` ring visible (token --kiosk-focus-ring)
7. **Reduced motion** : `@media (prefers-reduced-motion: reduce)` respecte les animations CSS

### 🟠 P1 — Admin tools UX (V2-5 + V2-3)

8. **KioskThemeManagerPage.vue** :
   - Branch selector + 3 theme cards (Standard / Halloween / Christmas/Noël)
   - Active theme indicator visible (✓ ACTIVE rouge)
   - Click → POST `admin/kiosk-theme/{branchId}` (URL relative, pas `/api/admin/...`)
   - Loading + error states
   - Note "next restart" affiché
9. **UpsellPreviewPage.vue** (394 LOC) :
   - Branch dropdown
   - Strategy dropdown (rule_based / ml_placeholder)
   - Test cart : Item ID + qty + Add A Line
   - Run Preview → POST /api/admin/upsell-preview
   - Display recommendations + latency + cart size
   - Empty state si pas de recommendations
   - Error state propre

### 🟠 P1 — Voice dialog accessibility (V2-4)
10. **KioskVoiceOrderingDialog.vue** (185 LOC) :
    - `role="dialog"` + `aria-modal="true"`
    - Focus trap dans la modal
    - Escape ferme la modal
    - Transcript display lisible
    - Confirm + Cancel buttons clairs
    - Disclaimer text "vous pourrez ajuster"
    - i18n fallback `tr()` si `$t` not available
11. **KioskVoiceOrderingButton.vue** (269 LOC) :
    - Web Speech API integration via service `kioskVoiceOrdering.js`
    - Mic button avec listening state animation
    - Browser unsupported fallback : button disabled + tooltip
    - Locale prop : fr-FR / en-US / ar-SA
    - Pas de capture micro sans interaction user explicite (consent)

### 🟡 P2 — Tests Vitest coverage
12. KioskBurgerBuilder.spec.js : 10 tests — drag emit + keyboard + a11y + remove
13. KioskThemeManagerPage.spec.js : 15 tests — branches load + active state + click switch + error
14. kioskVoiceOrderingDialog.spec.js : 6 tests — render + confirm + cancel + escape + i18n fallback + role/aria

### 🟡 P2 — Routes admin
15. 3 router modules : `kioskBurgerBuilderPocRoutes`, `kioskThemeAdminRoutes`, `upsellPreviewRoutes`
16. Permissions guard router : seul role admin peut accéder
17. Lazy loading via `() => import(...)` pour pas bloat le main bundle

## Critical questions à investiguer

Q1 — KioskBurgerBuilder : la prop `mockIngredients` ou similaire est-elle passée en POC mode ? Le composant peut-il être réutilisé avec items réels (ItemModel) ou nécessite-t-il refactor pour Phase B ?

Q2 — KioskVoiceOrderingButton : le micro est-il demandé en consent au premier click ? Si l'utilisateur refuse une fois, le button doit-il être disabled définitivement ou retry ? Vérifier le UX privacy.

Q3 — UpsellPreviewPage 394 LOC c'est gros — y a-t-il moyen de le splitter en sous-components (CartInput, ResultsTable, StrategySelector) ? Ou est-ce justifié ?

Q4 — KioskThemeManagerPage : comment est gérée la concurrence si 2 admins switchent le thème en même temps ? Race condition sur `branches.active_theme` ?

Q5 — Routes admin : comment sont-elles protégées côté router ? Vérifier qu'il y a un beforeEnter guard ou middleware permission, pas juste backend permission (defense in depth).

## Acceptance criteria

- [ ] V2-2 POC : pattern `v-for` direct dans `<draggable>` (pas `#item` slot)
- [ ] V2-2 a11y : keyboard alternative fonctionnelle (Tab + Enter + arrows)
- [ ] V2-2 ARIA : labels présents source + drop + remove
- [ ] V2-5 KioskThemeManagerPage : URL relative `admin/kiosk-theme/...` (pas `/api/admin/...`)
- [ ] V2-4 dialog : `role="dialog"` + `aria-modal="true"` + focus trap
- [ ] V2-4 mic : consent user explicite avant capture
- [ ] Tests vitest 31/31 (10+15+6) green
- [ ] Routes admin : permission guard côté Vue router (beforeEnter)
- [ ] Reduced motion respecté
- [ ] i18n keys cohérentes (`kiosk.builder.*`, `kiosk.admin.theme_*`, `kiosk.admin.upsell_preview_*`, `kiosk.voice.*`)

## Output attendu

Pour chaque composant : statut **OK / WARN / BLOCK** + a11y findings + UX findings + perf findings.
Recommandations refactor si LOC > 300 sans justification.
Verdict global : **MERGE-READY / HEAL / BLOCK**.
```

---

# 🟡 BATCH 4/4 — Additive Vue + Services + DS + i18n + bootstrap

## Copy-paste ce qui suit dans ton ultra-review :

```markdown
# Ultra-Review Batch 4/4 — Additive Vue + DS + i18n (FoodKing Kiosk)

## Contexte projet
FoodKing kiosk = Vue 2 SPA Laravel-served. Surfaces hors-frozen :
- KioskCashInstructionComponent (cash payment screen)
- KioskConfirmationComponent (post-order confirmation)
- KioskAdminComponent (admin staff settings drawer)
- KioskIdleScreenComponent (idle screen avant order start)
- KioskSkeletonLoader (greenfield wave alpha)

Toutes les modifs ici sont **strictement additive** (pas de breaking change).
Plus services JS (themes + voice), 4 themes CSS greenfield, i18n keys, bootstrap-kiosk init, et tokens additifs.

Cette review cible la cohérence DS / a11y / additive discipline / opt-in safety.

## Périmètre review (13 fichiers)

### Vue components (additive only) (5)
- resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue (Wave Alpha polish : aria-hidden + # space + tip + timer pause)
- resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue (Wave Alpha + V1x-4 KsButton migration)
- resources/js/components/frontend/kiosk/KioskAdminComponent.vue (V1x-5 a11y staff toggles)
- resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue (V2-4 voice CTA additif)
- resources/js/components/frontend/kiosk/KioskSkeletonLoader.vue (M-1 greenfield 4 types)

### Bootstrap & Services JS (3)
- resources/js/bootstrap-kiosk.js (modif : 4 themes CSS imports + kioskThemeManager.initialize() boot hook)
- resources/js/services/kioskThemeManager.js (204 LOC, V2-5 manager, BUG FIX URL `/api/admin/...` → `admin/...`)
- resources/js/services/kioskVoiceOrdering.js (187 LOC, Web Speech API wrapper)

### CSS Design System (2)
- resources/css/kiosk/global-a11y.css (`:focus-visible` 3px ring WCAG 2.4.7, scope `:where()` low specificity)
- resources/css/kiosk/themes/_base.css + standard.css + halloween.css + christmas.css (4 fichiers, ~170 LOC total)

### i18n (3)
- resources/js/languages/fr.json (modif +106 LOC keys)
- resources/js/languages/en.json (modif +106 LOC keys)
- resources/js/languages/ar.json (modif +10 LOC partial)

### Tests Vitest additionnels (5 — référence)
- tests/js/KioskSkeletonLoader.spec.js
- tests/js/KsButton.spec.js (DS atomic)
- tests/js/kioskAdminA11ySection.spec.js
- tests/js/kioskThemeManager.spec.js (26 tests, 2 corrigés post URL fix)
- tests/js/kioskVoiceOrdering.spec.js

## Focus de review

### 🟡 P2 — Additive discipline (KioskIdleScreen + KioskCash + KioskConfirmation + KioskAdmin)

1. **KioskIdleScreenComponent.vue V2-4 voice CTA** :
   - `isVoiceFeatureEnabled = false` DEFAULT (CRITIQUE — safe rollout, vs spec original `?? true`)
   - Voice CTA visible UNIQUEMENT si flag `true`
   - voice_intent query param sur `$router.push({ name: 'kiosk.categories', query: { voice_intent: ... } })`
   - locale → Web Speech API mapping (`fr-FR` / `en-US` / `ar-SA`)
   - Server settings sync : `data.kiosk_voice_ordering_enabled` (loadSettings)
   - Vuex sync : `kioskSettings.voiceOrderingEnabled`
   - **Aucune modif handlers existants** (additive uniquement)

2. **KioskCashInstructionComponent.vue Wave Alpha polish** :
   - aria-hidden="true" sur emojis décoratifs
   - `# 1 2 3 4` espacement formaté
   - Tip "💡 Ticket imprimé après paiement"
   - Timer pause sur interaction (mouse/touch détecté)

3. **KioskConfirmationComponent.vue Wave Alpha + V1x-4** :
   - Timer color muted
   - ETA cuisine
   - Mode payment dans card
   - Total points fidélité (gagnés + balance)
   - 5-star CSAT inline post-commande
   - V1x-4 : KsButton DS migration (boutons remplacés par `<KsButton>` atomic)

4. **KioskAdminComponent.vue V1x-5** :
   - Toggle high-contrast mode (persistance localStorage)
   - Toggle a11y staff (vibrations + audio cues si applicable)
   - Pas de modif flow admin existant

### 🟡 P2 — Services JS

5. **kioskThemeManager.js (204 LOC) BUG FIX critique** :
   - URL changée de `/api/admin/kiosk-theme/{branchId}` (ABSOLUTE leading slash) à `admin/kiosk-theme/{branchId}` (RELATIVE)
   - Raison : `window.axios.defaults.baseURL = '/api'`, donc absolute leading `/api/...` produirait `/api/api/...` 404
   - Pattern correct du codebase confirmé via DeliveryPlatformsPage
   - Tests `kioskThemeManager.spec.js` : 2 specs corrigées pour matcher nouvelle URL
6. **kioskVoiceOrdering.js (187 LOC)** :
   - Web Speech API wrapper safe (feature detection)
   - Browser unsupported : graceful degradation (return null/false, pas throw)
   - Cleanup proper (stop recognition + remove listeners)

### 🟡 P2 — Bootstrap themes init

7. **bootstrap-kiosk.js modif (+58 LOC)** :
   - 4 imports CSS themes (`_base`, `standard`, `halloween`, `christmas`)
   - `kioskThemeManager.initialize(branchId)` au DOMContentLoaded
   - Resolve `branchId` via `window.kioskBranchId` ou Vuex store fallback
   - Short-circuit si `branchId` null (admin pages safe — pas de fetch inutile)
   - Try/catch silent (theme init non-critical, ne doit jamais bloquer le boot)

### 🟢 P3 — DS & CSS

8. **global-a11y.css** : `:focus-visible` 3px ring scope `:where()` (specificity 0)
   - Scoped Vue styles always win
   - WCAG 2.4.7 compliance
9. **Themes CSS (4 fichiers)** :
   - `_base.css` : architecture (CSS variables `--kiosk-theme-*`)
   - `standard.css` : no-op blank theme
   - `halloween.css` : pumpkin orange (#FF6B35) + witch purple (#6B2D5C)
   - `christmas.css` : sapin red (#C8102E) + fir green (#2D5016)
   - Activation via attribute `:root[data-kiosk-theme="<slug>"]`
10. **tokens.css additif** : 3 nouveaux tokens (--kiosk-space-7, --kiosk-space-11, --kiosk-opacity-disabled), pas de modif existant

### 🟢 P3 — i18n

11. **fr.json + en.json symétriques** (~106 keys ajoutées chacun) :
    - `kiosk.builder.*` (10 keys)
    - `kiosk.admin.upsell_preview_*` (12 keys)
    - `kiosk.admin.theme_manager_*` + `theme_*_description` (10 keys)
    - `kiosk.voice.dialog_*` (4 keys ajoutés à existing kiosk.voice block)
    - `kiosk.cash.*`, `kiosk.confirmation.*`, `kiosk.payment.*` (Wave Alpha)
12. **ar.json partial** (10 keys) : OK (Arabic n'est pas exhaustif, fallback fr default)
13. **Pas de keys orphelines** : grep des keys déclarées doit toutes avoir usage code

## Critical questions à investiguer

Q1 — KioskIdleScreen : si `isVoiceFeatureEnabled` est dans `data()`, comment est synchronisé avec `kioskSettings.voiceOrderingEnabled` Vuex et avec `data.kiosk_voice_ordering_enabled` server ? Y a-t-il un risque de race condition / 3 sources of truth ?

Q2 — bootstrap-kiosk.js charge themes CSS sur TOUTES pages (admin + kiosk). Est-ce qu'on a un risque de leak CSS sur admin (theme appliqué visible si attribute posé par erreur) ? Le fallback "no attribute = no rule match" est-il garanti ?

Q3 — kioskThemeManager.js URL fix : y a-t-il un autre endroit dans le codebase qui fait `/api/...` ABSOLUTE par erreur ? Faire un grep `axios.get('/api/'` / `axios.post('/api/'` pour audit.

Q4 — KioskConfirmationComponent : la migration V1x-4 KsButton remplace TOUS les boutons ou seulement quelques-uns ? Si partiel, y a-t-il dette UX (mix anciens/nouveaux) ?

Q5 — KioskSkeletonLoader (greenfield M-1) : 4 types skeleton (categories / menu / cart / payment ?). Sont-ils tous utilisés ou y a-t-il du dead code ?

## Anti-drift commands

```bash
# 1. Voice flag default OFF confirmé
grep -A1 "isVoiceFeatureEnabled" resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue \
  | grep -E "false|true" | head -3
# Expected: "isVoiceFeatureEnabled: false," au moins

# 2. Audit URL `/api/...` absolute hors auth (potentielle baseURL drift)
grep -rE "axios\.(get|post|put|delete)\(['\"]\/api\/" resources/js/services/ resources/js/components/admin/ \
  | grep -v "^Binary file" | head -10
# Expected: 0 ou seulement intentionnels

# 3. i18n symétrie fr ↔ en
diff <(jq -r 'paths(scalars) | join(".")' resources/js/languages/fr.json | sort) \
     <(jq -r 'paths(scalars) | join(".")' resources/js/languages/en.json | sort) | head -20
# Expected: empty ou minimes seulement

# 4. Themes CSS : pas de leak hors data-kiosk-theme attribute
grep -n "^:root\|^body\|^html" resources/css/kiosk/themes/halloween.css resources/css/kiosk/themes/christmas.css | head -10
# Expected: tous wrappés par [data-kiosk-theme="..."]
```

## Acceptance criteria

- [ ] V2-4 voice flag default `false` (safe rollout)
- [ ] kioskThemeManager.js URL relative `admin/kiosk-theme/...`
- [ ] bootstrap-kiosk.js short-circuit si branchId null (admin pages safe)
- [ ] global-a11y.css scope `:where()` (specificity 0)
- [ ] Themes CSS leak-safe (pas de rule sans `[data-kiosk-theme="..."]` attribute)
- [ ] i18n fr/en symétriques sur les ~106 keys ajoutées
- [ ] Pas de keys orphelines dans json (grep pour usage)
- [ ] Skeleton loader 4 types tous utilisés (pas dead code)
- [ ] V1x-4 KsButton migration cohérente (pas de mix anciens/nouveaux dans même surface)
- [ ] Tests vitest des composants additive pass (KioskSkeletonLoader, KsButton, kioskAdminA11ySection, kioskThemeManager, kioskVoiceOrdering)

## Output attendu

Pour chaque composant : confirmation **discipline additive respectée** + a11y compliance + UX cohérence DS.
i18n : confirmation symétrie fr/en + 0 keys orphelines.
CSS : confirmation themes leak-safe + tokens additifs strict.
Verdict : **MERGE-READY / HEAL / BLOCK**.
```

---

## 🎯 Workflow recommandé

```
[1] Pull branch claude/blissful-mclean-c915c2 localement
[2] Lance Batch 1 (Backend PHP) → review → fix findings → recommit si besoin
[3] Lance Batch 2 (Frozen-zone Cart+Payment) → review → fix findings → recommit
[4] Lance Batch 3 (Greenfield Vue) → review → fix findings → recommit
[5] Lance Batch 4 (Additive Vue + DS + i18n) → review → fix findings → recommit
[6] Si tous batches ✅ → push + gh pr create
[7] Decision Phase B (V2-2/V2-3/V2-4 wizard integration) post-merge
```

**Estimation temps reviewer humain :**
- Batch 1 : ~30 min (security focus)
- Batch 2 : ~15 min (frozen-zone strict, peu de fichiers)
- Batch 3 : ~40 min (greenfield Vue lourd, a11y deep)
- Batch 4 : ~25 min (additive cohérence)
- **Total : ~110 min ultra-review complète**

---

## Status

[ ] Batch 1/4 lancé
[ ] Batch 1/4 findings appliqués
[ ] Batch 2/4 lancé
[ ] Batch 2/4 findings appliqués
[ ] Batch 3/4 lancé
[ ] Batch 3/4 findings appliqués
[ ] Batch 4/4 lancé
[ ] Batch 4/4 findings appliqués
[ ] Final verdict global validé
[ ] Branch poussée + PR créée
