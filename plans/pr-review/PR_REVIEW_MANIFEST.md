# PR Review Manifest — Kiosk Design Execution Wave 4
**Branche :** `claude/blissful-mclean-c915c2`
**Date :** 2026-05-08
**Base :** `b8b4fb76` (merge-base avec main)
**Head :** `9d6fe4ff3`
**Commits design :** 6 (`fb99d12b6` → `9d6fe4ff3`)
**Périmètre review :** 80 fichiers (hors `vendor/` et `*.LICENSE.txt`)

---

## §0 — TL;DR review priorities

| Priorité | Domaine | Fichiers | Risk |
|---|---|---|---|
| 🔴 **P0 — frozen-zone audit** | Cart V1x owner-gate | 1 fichier | Cart owner-frozen, gate explicit owner |
| 🔴 **P0 — security defense** | UpsellPreview match() | 1 fichier | Dynamic class injection guard |
| 🟠 **P1 — public API surface** | OrderRating + UpsellPreview + UpsellRecommendation + KioskTheme | 4 controllers | Auth + branch isolation |
| 🟠 **P1 — DB migrations** | order_ratings + branches.active_theme | 2 fichiers | Schema impact |
| 🟡 **P2 — frontend additive** | KioskIdle voice CTA + KioskPayment microcopy + KioskAdmin staff toggles | 3 fichiers | Default flag OFF, opt-in |
| 🟡 **P2 — greenfield POC** | KioskBurgerBuilder + Theme manager + Upsell preview admin | 6 fichiers | Routes admin only |
| 🟢 **P3 — i18n + tokens + plans** | 3 fichiers | Additive only |

---

## §1 — Découpage par commit

### Commit 1 — `fb99d12b6` `design(wave-alpha)` (28 files, +1422/-23)
10 polish items via 5 sub-agents en parallèle.

**Backend (3 fichiers)**
- `app/Http/Controllers/Frontend/OrderRatingController.php` — 5-star CSAT
- `app/Models/OrderRating.php`
- `database/migrations/2026_05_08_050000_create_order_ratings_table.php`

**Frontend Vue (5 fichiers)**
- `resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue` — aria-hidden + # space + tip + timer pause
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` — aria + payment mode + ETA + total points + 5-star CSAT
- `resources/js/components/frontend/kiosk/KioskSkeletonLoader.vue` — greenfield 4 types
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` — additive 🔒 TLS + 5 logos cartes (additive scope-minimal sur F-002/F-008/F-009 territory)

**CSS (2 fichiers)**
- `resources/css/kiosk/tokens.css` — `--kiosk-opacity-disabled: 0.5`
- `resources/css/kiosk/global-a11y.css` — `:focus-visible 3px ring` WCAG 2.4.7

**Tests (2 fichiers)**
- `tests/Feature/Frontend/OrderRatingTest.php` — 7 tests
- `tests/js/KioskSkeletonLoader.spec.js` — 7 tests

**i18n (3 fichiers)** — `fr.json`, `en.json`, `ar.json` — keys `kiosk.cash.*`, `kiosk.confirmation.*`, `kiosk.payment.*`, `kiosk.skeleton.*`, `rating.*`

**Docs + plans + assets bricks** — 13 fichiers

**⚠️ Cleanup item identifié** : 3 fichiers dans `storage/media-library/temp/` accidentellement commités pendant la build (artifacts upload temp). Voir §6.

---

### Commit 2 — `b44f11455` `design(wave-beta+gamma)` (38 files, +4718/-62)
8 items DS atomic + V2 scaffolding.

**Backend (5 fichiers)**
- `app/Http/Controllers/Admin/KioskThemeController.php` — V2-5 backend (130 LOC)
- `app/Http/Controllers/Frontend/UpsellRecommendationController.php` — V2-3 (62 LOC)
- `app/Services/Recommendation/UpsellRecommendationService.php` — interface (50 LOC)
- `app/Services/Recommendation/Strategies/RuleBasedStrategy.php` — heuristique (315 LOC)
- `app/Services/Recommendation/Strategies/MlPlaceholderStrategy.php` — fallback (39 LOC)
- `config/recommendation.php` — strategy binding (46 LOC)
- `database/migrations/2026_05_08_060000_add_active_theme_to_branches.php`
- `app/Models/Branch.php` — fillable additif (4 LOC)
- `app/Providers/AppServiceProvider.php` — strategy binding container (17 LOC)

**Frontend Vue (3 fichiers)**
- `resources/js/components/frontend/kiosk/KioskAdminComponent.vue` — V1x-5 a11y staff toggles (124 LOC)
- `resources/js/components/frontend/kiosk/KioskVoiceOrderingButton.vue` — V2-4 (269 LOC)
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` — V1x-4 KsButton migration

**Services JS (2 fichiers)**
- `resources/js/services/kioskVoiceOrdering.js` — Web Speech API wrapper (187 LOC)
- `resources/js/services/kioskThemeManager.js` — V2-5 manager (204 LOC)

**CSS V2-5 themes (4 fichiers)**
- `resources/css/kiosk/themes/_base.css` — theme architecture (79 LOC)
- `resources/css/kiosk/themes/standard.css` — no-op default (20 LOC)
- `resources/css/kiosk/themes/halloween.css` — pumpkin/witch (37 LOC)
- `resources/css/kiosk/themes/christmas.css` — sapin/red (37 LOC)

**Tests (5 fichiers)**
- `tests/Feature/Admin/KioskThemeControllerTest.php` — 192 LOC
- `tests/Feature/Recommendation/UpsellRecommendationTest.php` — 349 LOC, 8 tests
- `tests/js/KsButton.spec.js` — 196 LOC
- `tests/js/kioskAdminA11ySection.spec.js` — 181 LOC
- `tests/js/kioskVoiceOrdering.spec.js` — 337 LOC

**Plans gate V1x + V2 (4 fichiers documentation)** — voir `§3`

**Docs + i18n + routes** — 16 fichiers

---

### Commit 3 — `7adeaaa9c` `design(v1x-cart): owner-gate executed` (7 files, +163/-103)
🔴 **P0 ZONE FROZEN — owner-gate explicit executed**

- `resources/css/kiosk/tokens.css` — `+--kiosk-space-7: 28px` + `+--kiosk-space-11: 44px`
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` — V1x-1 ~30 spacing migrations + V1x-3 image clamp + V1x-6 3 templates aria
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` — V1x-1 ~20 spacing migrations
- `tests/js/KioskCartRestyle.spec.js` — V1x-6 aria test additif
- 3 plans markdown owner-gate-executed annotation

**🔍 Review focus :**
- Vérifier 0 changement visuel rendu (tokens === valeurs px exactes)
- V1x-3 Option A safe (clamp(64px, 4.7vw, 96px)) — 1080p inchangé
- V1x-6 Option B extensive — 3 templates `:title`+`:aria-label` (name + selections + note)
- Frozen-zone discipline : seulement V1x-1+V1x-3+V1x-6 modifs autorisés

---

### Commit 4 — `30551044e` `design(wave-4)` (25 files, +2804/-8)
V2-2 POC drag-drop + V2-5 Phase 2 themes activation + V2-3+V2-4 integration.

**🔴 BUG FIX critique** : `resources/js/services/kioskThemeManager.js` URL `/api/admin/...` → `admin/...` (axios baseURL composé `/api/api/...` 404)

**Backend (1 fichier)**
- `app/Http/Controllers/Admin/UpsellPreviewController.php` — POST `/api/admin/upsell-preview` defense-in-depth match() (82 LOC)

**Frontend Vue greenfield (6 fichiers)**
- `resources/js/components/frontend/kiosk/builder/KioskBurgerBuilder.vue` — V2-2 (340 LOC)
- `resources/js/components/frontend/kiosk/builder/KioskBurgerLayer.vue` — V2-2 (116 LOC)
- `resources/js/components/frontend/kiosk/builder/KioskBurgerBuilderPoc.vue` — V2-2 (124 LOC)
- `resources/js/components/admin/kioskTheme/KioskThemeManagerPage.vue` — V2-5 Phase 2 (332 LOC)
- `resources/js/components/admin/kioskTheme/KioskThemePreviewCard.vue` — V2-5 Phase 2 (163 LOC)
- `resources/js/components/admin/upsellPreview/UpsellPreviewPage.vue` — V2-3 admin tool (394 LOC)
- `resources/js/components/frontend/kiosk/KioskVoiceOrderingDialog.vue` — V2-4 (185 LOC)

**Frontend Vue additive (1 fichier)**
- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` — V2-4 voice CTA additif + dialog + handlers (default OFF safe rollout)

**Routes (4 fichiers)**
- `resources/js/router/index.js` — 3 imports + 3 routes registrés
- `resources/js/router/modules/kioskBurgerBuilderPocRoutes.js` — POC admin route
- `resources/js/router/modules/kioskThemeAdminRoutes.js` — V2-5 admin
- `resources/js/router/modules/upsellPreviewRoutes.js` — V2-3 admin
- `routes/api.php` — POST `/api/admin/upsell-preview`

**Tests (4 fichiers)**
- `tests/Feature/Admin/UpsellPreviewControllerTest.php` — 7 tests, 230 LOC
- `tests/js/KioskBurgerBuilder.spec.js` — 10 tests, 175 LOC
- `tests/js/KioskThemeManagerPage.spec.js` — 15 tests, 243 LOC
- `tests/js/kioskVoiceOrderingDialog.spec.js` — 6 tests, 68 LOC
- `tests/js/kioskThemeManager.spec.js` — 26 tests, +2 corrigés post URL fix

**i18n + bootstrap-kiosk.js + tokens.css**

---

### Commit 5 — `f66dae6ea` `design(wave-4): final report` (1 file, +338)
Documentation only : `plans/KIOSK_DESIGN_EXECUTION_FINAL_REPORT_2026-05-08.md`

### Commit 6 — `9d6fe4ff3` `design(wave-4): live audit + APP_URL fix` (1 file, +39/-16)
Documentation only : update final report avec live screenshots + auto-fix dérive section

---

## §2 — Stats globales

```
Backend PHP                10 fichiers   ~1330 LOC
Frontend Vue               18 fichiers   ~3000 LOC
Services JS                 2 fichiers    ~390 LOC
CSS (tokens+a11y+themes)    7 fichiers    ~270 LOC
Routes                      5 fichiers     ~80 LOC
Tests PHPUnit               4 fichiers    ~980 LOC (44 tests)
Tests Vitest                9 fichiers   ~1450 LOC (76 nouveaux tests)
i18n                        3 fichiers    ~310 LOC keys
Migrations                  2 fichiers     ~90 LOC
Plans/docs                 14 fichiers   (~3000 LOC review optionnel)

TOTAL périmètre review actif : ~7700 LOC nettes
```

---

## §3 — Plans documentation (review optionnel mais recommandé)

| Fichier | Status | Owner-decision required ? |
|---|---|---|
| `plans/KIOSK_DESIGN_AUDIT_CART_PAYMENT_2026-05-08.md` | ✅ Validé owner | — |
| `plans/KIOSK_DESIGN_EXECUTION_MASTER_2026-05-08.md` | ✅ Master plan | — |
| `plans/PLAN_DESIGN_V1X1_SPACING_TOKENS_2026-05-08.md` | ✅ Executed | — |
| `plans/PLAN_DESIGN_V1X3_CART_IMAGE_RESPONSIVE_2026-05-08.md` | ✅ Option A executed | Owner choix Option A |
| `plans/PLAN_DESIGN_V1X6_CART_VARIATIONS_ARIA_2026-05-08.md` | ✅ Option B executed | Owner choix Option B |
| `plans/PLAN_DESIGN_V2_2_DRAG_DROP_WIZARD_2026-05-08.md` | ⏸️ Phase A executed | **Owner gate Phase B requis** |
| `plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md` | ⏸️ Backend + admin | **Owner gate Phase B kiosk surface** |
| `plans/PLAN_DESIGN_V2_4_VOICE_ORDERING_2026-05-08.md` | ⏸️ Idle CTA additif | **Owner gate Phase B wizard parsing** |
| `plans/PLAN_DESIGN_V2_5_SKINNING_SAISONNIER_2026-05-08.md` | ✅ Phase 1+2 executed | Owner décide Phase 3 polish |
| `plans/KIOSK_DESIGN_EXECUTION_FINAL_REPORT_2026-05-08.md` | ✅ Final | — |
| `docs/KIOSK_SKELETON_LOADER.md` | ✅ Doc usage | — |
| `docs/KIOSK_THEMES.md` | ✅ Doc usage thèmes | — |

---

## §4 — Checklist review par catégorie

### 4.1 Sécurité backend (P0)
- [ ] `UpsellPreviewController.php` — explicit `match()` au lieu de `Str::studly` dynamic class (defense-in-depth)
- [ ] Auth check : `staff cannot access upsell preview` test PHPUnit
- [ ] Branch isolation : `respects branch id isolation` test
- [ ] `UpsellRecommendationController.php` — abilities check + branch_id input validation
- [ ] `OrderRatingController.php` — `cross_branch_user_cannot_rate` test
- [ ] `KioskThemeController.php` — admin permission only

### 4.2 Frozen-zones discipline (P0)
- [ ] `KioskWizardComponent.vue` 1659 LOC — `git diff HEAD~6 HEAD -- KioskWizardComponent.vue` = empty
- [ ] `KioskCartComponent.vue` modifs limitées à V1x-1+V1x-3+V1x-6 (spacing+img+aria, pas de logique)
- [ ] `KioskPaymentComponent.vue` modifs additive only (microcopy + modal + spacing tokens)
- [ ] `KioskAppComponent.vue` (F-016) — 0 modif
- [ ] F-001..F-017 backend agent files — 0 modif

### 4.3 Schema & migrations (P1)
- [ ] `2026_05_08_050000_create_order_ratings_table.php` — branch_id FK + soft deletes + index
- [ ] `2026_05_08_060000_add_active_theme_to_branches.php` — nullable + default null + index sur branch_id

### 4.4 Tests cumulative (P1)
- [ ] `vitest run` 561/561 ✅ vérifié
- [ ] `php artisan test --filter "UpsellPreview|OrderRating|FiscalSequence|KioskMenu|KioskPayment|UpsellRecommendation"` 44/44 ✅ vérifié
- [ ] `npm run production` Mix 24.32s ✅ vérifié
- [ ] Edge cases : empty cart upsell, branch_id missing, ml_placeholder fallback, voice browser unsupported

### 4.5 Frontend a11y (P1)
- [ ] `KioskBurgerBuilder.vue` keyboard alternative (Enter lift / arrows move / Enter drop) — code path présent
- [ ] `KioskVoiceOrderingDialog.vue` `role="dialog"` + `aria-modal="true"` + ARIA labels
- [ ] `KioskCartComponent.vue` `:title`+`:aria-label` sur 3 templates (V1x-6)
- [ ] `KioskAdminComponent.vue` toggle staff a11y (V1x-5)
- [ ] `global-a11y.css` `:focus-visible` 3px ring scope `:where()` non-spécificité

### 4.6 Performance (P2)
- [ ] `KioskBurgerBuilder.vue` CSS transforms only (pas reflow) — `prefers-reduced-motion` respect via tokens
- [ ] V2-5 themes : pas de FOUC au switch (CSS variables only)
- [ ] Mix bundle size : `js/app.js 4.59 MiB` + `js/kiosk.js 574 KiB` + `js/kiosk-builder-poc.js 15.8 KiB`

### 4.7 i18n (P2)
- [ ] `fr.json` + `en.json` symétriques (~106 keys ajoutées)
- [ ] `ar.json` partial (10 keys de base)
- [ ] Pas de keys orphelines / déclarées non-utilisées
- [ ] `kiosk.builder.*` (10) + `kiosk.admin.upsell_preview_*` (12) + `kiosk.admin.theme_*` (10) + `kiosk.voice.*` (4)

### 4.8 Defaults rollout sécurisé (P2)
- [ ] V2-4 voice : `isVoiceFeatureEnabled = false` default (vs spec `?? true`)
- [ ] V2-5 thème : default `standard` (no-op CSS, branding original)
- [ ] V2-2 POC : route `/kiosk/burger-builder-poc` admin-toggle uniquement, pas en prod kiosk
- [ ] V2-3 : surface admin uniquement, pas en surface kiosk publique

---

## §5 — Risk register en sortie

| Risk | Niveau | Mitigation |
|---|---|---|
| Cart frozen-zone touchée | P0 | Owner-gate explicit executed, scope strictement V1x-1/3/6 |
| Dynamic class injection UpsellPreview | P0 | `match()` explicite vs `Str::studly` |
| URL `/api/api/...` 404 V2-5 | P0 | Bug fix commit 30551044e + 2 specs corrigées |
| Drag visuel non testé hardware kiosk | P1 | Phase B requise (gate explicit + test physique avant intégration wizard) |
| i18n keys raw au boot | P2 | Cosmétique async ~2s, pas frozen |
| Storage media-library temp commité | P2 | 3 binaires ~660 KB à cleanup pre-merge (voir §6) |
| Demo button login submit broken | P2 | Bug latent Vue 2 SPA, hors scope design |
| `/kiosk/categories` "Unable to load menu" | P2 | Demo seeder ne populate pas items kiosk, hors scope |

---

## §6 — Cleanup items pre-merge (recommandé)

```bash
# 3 binaires temp commitées par erreur dans Wave Alpha (fb99d12b6)
git rm storage/media-library/temp/2fc309a37d37cad1dbf848fb1f5de310
git rm storage/media-library/temp/osKSkwzlznf8RzRQZBXZv4HNqbTe31UN/Kq1U7VhUFDgL3iaGDP4LYaDT6DUVjy0Xthumb.png
git rm storage/media-library/temp/osKSkwzlznf8RzRQZBXZv4HNqbTe31UN/cri7jrxyAyaEAvaBRQC8h4hs4GPm80uE.png
# Ajouter à .gitignore
echo "storage/media-library/temp/" >> .gitignore
git add .gitignore && git commit -m "chore: gitignore storage/media-library/temp + remove accidental commits"
```

**Décision pre-merge owner :** garder ces 3 fichiers en commit history (faible risque) OU rebase Wave Alpha pour les retirer.

---

## §7 — Captures live (Claude_in_Chrome MCP, viewport 1080×1920)

| # | URL | Page | Évidence |
|---|---|---|---|
| 1 | `/kiosk/burger-builder-poc` | V2-2 POC drag-drop | 7 ingrédients + drop zone + classique mode |
| 2 | `/login` | Login page | Form + i18n loaded + 5 demo buttons |
| 3 | `/admin/upsell-preview` | V2-3 admin tool | Branch + Strategy + Test cart + Run Preview |
| 4 | `/admin/kiosk-themes` | V2-5 Phase 2 | Standard ✓ ACTIVE / Halloween / Noël |
| 5 | `/kiosk/idle` | Kiosk idle | FoodKing branded + Bienvenue + play btn |

---

## §8 — Recommandations review

### Pour reviewer humain
1. Lire ce manifest en premier (5 min)
2. Lire `plans/KIOSK_DESIGN_EXECUTION_FINAL_REPORT_2026-05-08.md` (10 min)
3. Reviewer les 4 controllers PHP (15 min)
4. Reviewer 3 composants Vue greenfield (20 min) : `KioskBurgerBuilder` + `KioskThemeManagerPage` + `UpsellPreviewPage`
5. Reviewer 1 composant Vue frozen-gate (15 min) : `KioskCartComponent.vue` diff V1x-1/3/6
6. Sampler tests : `UpsellPreviewControllerTest` + `KioskBurgerBuilder.spec.js` (10 min)
7. Vérifier frozen-zones via `git diff main..HEAD -- 'resources/js/components/frontend/kiosk/KioskWizardComponent.vue'` (1 min)

**Total review humain estimé : ~75 min pour audit complet**

### Pour ultra-review automatisé
```bash
# 1. Frozen-zones grep guard (doit retourner 0)
git diff main..HEAD -- \
  'resources/js/components/frontend/kiosk/KioskWizardComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskUpsellComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskProductListComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskAppComponent.vue' | wc -l
# Expected : 0

# 2. Tests cumulative
npx vitest run | tail -5
APP_ENV=testing APP_KEY="base64:xStyFkYpE7z2419nS+F0eDk1xQ89H9xY4S/r+F+Z4eY=" \
  BROADCAST_DRIVER=null DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
  php artisan test --filter "UpsellPreview|OrderRating|FiscalSequence|KioskMenu|KioskPayment|UpsellRecommendation"

# 3. Build production
npm run production

# 4. Lint Vue + ESLint
npx eslint resources/js/components/frontend/kiosk/builder/ \
            resources/js/components/admin/kioskTheme/ \
            resources/js/components/admin/upsellPreview/

# 5. PHPStan + Larastan (si configurés)
./vendor/bin/phpstan analyse app/Http/Controllers/Admin/UpsellPreviewController.php \
                              app/Services/Recommendation/
```

---

## §9 — Status final

[ ] **Review P0 sécurité** done
[ ] **Review P0 frozen-zones** done (diff 8 wizards = 0)
[ ] **Review P1 controllers + migrations** done
[ ] **Review P1 tests** done
[ ] **Review P2 frontend a11y** done
[ ] **Cleanup §6** décidé (rebase ou keep)
[ ] **Captures live §7** validées par owner
[ ] **Decisions Phase B** prises (V2-2/V2-3/V2-4)
[ ] **Merge approval** owner
