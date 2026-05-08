# Ultra Review Checklist — Wave 4 Kiosk Design
**Branche :** `claude/blissful-mclean-c915c2`
**Pour reviewer :** suivre dans cet ordre, cocher au fur et à mesure

---

## Phase 1 — Auto-checks (≤ 5 min, scriptable)

```bash
# Toutes ces commandes doivent passer

# 1.1 Frozen-zones grep guard (doit retourner 0)
git diff main..HEAD -- \
  'resources/js/components/frontend/kiosk/KioskWizardComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskUpsellComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskPromoCarouselComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskProductListComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskAppComponent.vue' \
  | wc -l
# Expected output: 0

# 1.2 Vitest cumulative
npx vitest run 2>&1 | tail -5
# Expected: "Test Files 66 passed (66) | Tests 561 passed (561)"

# 1.3 PHPUnit touched
APP_ENV=testing APP_KEY="base64:xStyFkYpE7z2419nS+F0eDk1xQ89H9xY4S/r+F+Z4eY=" \
  BROADCAST_DRIVER=null DB_CONNECTION=sqlite DB_DATABASE=":memory:" \
  php artisan test --filter "UpsellPreview|OrderRating|FiscalSequence|KioskMenu|KioskPayment|UpsellRecommendation"
# Expected: "Tests: 44 passed"

# 1.4 Build production
npm run production 2>&1 | tail -10
# Expected: "Compiled Successfully"
```

- [ ] 1.1 Frozen-zones = 0
- [ ] 1.2 Vitest 561/561
- [ ] 1.3 PHPUnit 44/44
- [ ] 1.4 Build OK

---

## Phase 2 — Sécurité backend (P0, ~15 min)

### 2.1 `app/Http/Controllers/Admin/UpsellPreviewController.php`
- [ ] Auth middleware applied (`auth:sanctum`)
- [ ] Permission check : seulement admin (`can:manage-recommendations` ou similaire)
- [ ] Validation rules : `cart_items` array required, `branch_id` required, `strategy` in `['rule_based', 'ml_placeholder']`
- [ ] **Defense-in-depth** : `match($strategy) { 'rule_based' => RuleBasedStrategy::class, 'ml_placeholder' => MlPlaceholderStrategy::class }` — **PAS** `Str::studly($strategy) . 'Strategy'`
- [ ] Branch isolation : utilise `branch_id` validé, pas `auth()->user()->branch_id` directement
- [ ] Rate limiting (si applicable)
- [ ] Tests `tests/Feature/Admin/UpsellPreviewControllerTest.php` 7/7

### 2.2 `app/Http/Controllers/Frontend/UpsellRecommendationController.php`
- [ ] Sanctum abilities `['kiosk:order']` ou similaire
- [ ] Branch isolation strict
- [ ] Cart_items validation
- [ ] Tests `UpsellRecommendationTest.php` 8/8

### 2.3 `app/Http/Controllers/Frontend/OrderRatingController.php`
- [ ] Auth check : utilisateur authentifié
- [ ] `cross_branch_user_cannot_rate_other_branch_order` test pass
- [ ] Range 1-5 validation
- [ ] Comment max 500
- [ ] Soft create-or-update logic safe

### 2.4 `app/Http/Controllers/Admin/KioskThemeController.php`
- [ ] Permission `settings` ou `manage-kiosk-themes`
- [ ] `branch_id` validation
- [ ] `theme` enum check `['standard', 'halloween', 'christmas']`
- [ ] Tests `KioskThemeControllerTest.php`

### 2.5 `app/Services/Recommendation/Strategies/RuleBasedStrategy.php`
- [ ] Pas de N+1 queries
- [ ] Branch isolation (toujours filter `branch_id`)
- [ ] Pas de side-effects (pure function)
- [ ] Heuristics : burger → sides+drinks · 3+ items → dessert · cart < 10€ → combo

### 2.6 `app/Services/Recommendation/Strategies/MlPlaceholderStrategy.php`
- [ ] Fallback sécurisé vers RuleBasedStrategy si ML pas dispo
- [ ] Pas de fuites d'erreur

---

## Phase 3 — Frozen-zones discipline (P0, ~10 min)

### 3.1 V1x-Cart owner-gate
```bash
# Voir ce qui a été modifié sur le Cart frozen
git log --oneline 7adeaaa9c -- 'resources/js/components/frontend/kiosk/KioskCartComponent.vue'
git diff 7adeaaa9c~1..7adeaaa9c -- 'resources/js/components/frontend/kiosk/KioskCartComponent.vue' | head -200
```
- [ ] Modifs strictement V1x-1 (CSS spacing tokens) + V1x-3 (image clamp + emoji clamp) + V1x-6 (3 templates aria-label)
- [ ] **AUCUNE modification logique JS/computed/methods** (sauf pure CSS template attributes)
- [ ] **AUCUN nouveau component imported**
- [ ] Tests `KioskCartRestyle.spec.js` ajout V1x-6 aria assertion

### 3.2 Payment additive only
```bash
git diff main..HEAD -- 'resources/js/components/frontend/kiosk/KioskPaymentComponent.vue' | head -100
```
- [ ] M-4 microcopy `🔒 TLS 1.3` + 5 logos cartes
- [ ] V1x-2 modal payment refusé (additive section + scoped CSS)
- [ ] V1x-1 spacing tokens (CSS only)
- [ ] **Pas de modif state machine**
- [ ] **Pas de modif appel API**

### 3.3 KioskAppComponent (F-016 agent territory)
```bash
git diff main..HEAD -- 'resources/js/components/frontend/kiosk/KioskAppComponent.vue'
```
- [ ] **0 modif** (intentionnel)

### 3.4 Backend agent files (F-001..F-017)
```bash
git diff main..HEAD -- 'app/Models/Order.php' 'app/Services/Fiscal/' 'app/Services/PaymentTerminals/'
```
- [ ] **0 modif** (intentionnel)

---

## Phase 4 — Greenfield Vue components (P1, ~25 min)

### 4.1 `KioskBurgerBuilder.vue` (340 LOC)
- [ ] Imports `vue-draggable-next` (déjà dans `package.json:43`)
- [ ] `<draggable>` utilise default slot pattern (pas `#item` slot — lib v2.3.0 ne supporte pas)
- [ ] Source pool : `pull: 'clone'` mode
- [ ] Drop zone : computed `droppedExtras` array
- [ ] Emit `update:item_extras` sur change
- [ ] Keyboard alternative : Enter lift / arrows move / Enter drop
- [ ] ARIA labels : `aria-label="ingredient {name} +{price}€"` + `aria-grabbed`
- [ ] `prefers-reduced-motion` respect (CSS animations gated)
- [ ] DEBUG section dans POC visible

### 4.2 `KioskBurgerLayer.vue` (116 LOC)
- [ ] Visual layer rendering avec z-index
- [ ] Remove button avec `aria-label="Remove {name} from burger"`
- [ ] Pas de mutation directe parent state (emit only)

### 4.3 `KioskBurgerBuilderPoc.vue` (124 LOC)
- [ ] Standalone POC route — pas accessible en surface kiosk publique
- [ ] Mock 7 ingredients (Steak haché, Cheddar, Bacon, Salade, Tomate, Oignon, Sauce BBQ)
- [ ] Toggle "Utiliser le mode classique"
- [ ] Display debug emit log

### 4.4 `KioskThemeManagerPage.vue` (332 LOC)
- [ ] Branch selector
- [ ] 3 theme cards (Standard / Halloween / Christmas)
- [ ] Active theme indicator (✓ ACTIVE rouge)
- [ ] Click → POST `admin/kiosk-theme/{branchId}` (note: relative URL, pas `/api/admin/...`)
- [ ] Note "next restart or session refresh"
- [ ] Loading + error states
- [ ] i18n keys `kiosk.admin.theme_*`

### 4.5 `KioskThemePreviewCard.vue` (163 LOC)
- [ ] Props : `theme`, `isActive`, `disabled`
- [ ] Emit click event
- [ ] ARIA active state

### 4.6 `UpsellPreviewPage.vue` (394 LOC)
- [ ] Branch dropdown
- [ ] Strategy dropdown (rule_based / ml_placeholder)
- [ ] Test cart : Item ID + qty inputs + Add A Line button
- [ ] Run Preview button → POST `/api/admin/upsell-preview`
- [ ] Display recommendations + latency + cart size
- [ ] Empty state si pas de recommendations
- [ ] Error state

### 4.7 `KioskVoiceOrderingDialog.vue` (185 LOC)
- [ ] `role="dialog"` + `aria-modal="true"`
- [ ] Transcript display
- [ ] Confirm / Cancel buttons
- [ ] Disclaimer text
- [ ] i18n fallback via `tr()` quand `$t` not available
- [ ] Tests 6/6

### 4.8 `KioskVoiceOrderingButton.vue` (269 LOC)
- [ ] Web Speech API integration via `kioskVoiceOrdering.js` service
- [ ] Mic button + listening state animation
- [ ] Browser unsupported fallback : button disabled + tooltip
- [ ] Locale prop : `fr-FR` / `en-US` / `ar-SA`
- [ ] Tests `kioskVoiceOrdering.spec.js`

---

## Phase 5 — Frontend additive (P1, ~15 min)

### 5.1 `KioskIdleScreenComponent.vue` additive V2-4
- [ ] **`isVoiceFeatureEnabled = false` default** (safe rollout)
- [ ] Voice CTA visible UNIQUEMENT si flag true
- [ ] Voice dialog shown post-transcript
- [ ] `voice_intent` query param sur navigate `kiosk.categories`
- [ ] `voiceLang` mapping locale → Web Speech (fr-FR/en-US/ar-SA)
- [ ] Server settings sync : `data.kiosk_voice_ordering_enabled`
- [ ] Vuex store sync : `kioskSettings.voiceOrderingEnabled`
- [ ] Pas de modif handlers existants (additive only)

### 5.2 `KioskAdminComponent.vue` V1x-5
- [ ] Toggle high-contrast mode (persistance localStorage)
- [ ] Toggle a11y staff (vibrations + audio cues)
- [ ] Pas de touche au flow admin existant
- [ ] Tests `kioskAdminA11ySection.spec.js`

### 5.3 `bootstrap-kiosk.js` V2-5 themes init
- [ ] 4 imports CSS themes (`_base`, `standard`, `halloween`, `christmas`)
- [ ] `kioskThemeManager.initialize(branchId)` au DOMContentLoaded
- [ ] Resolve `branchId` via `window.kioskBranchId` ou Vuex store
- [ ] Short-circuit si `branchId` null (admin pages safe)
- [ ] Try/catch silent (theme init non-critical)

---

## Phase 6 — Tests review (P1, ~10 min)

### 6.1 PHPUnit
- [ ] `UpsellPreviewControllerTest.php` 7 tests :
  - admin can preview with rule_based
  - admin can preview with ml_placeholder
  - admin default strategy is rule_based
  - **staff cannot access** ✅ critical
  - validates invalid strategy returns 422
  - validates empty cart returns 422
  - returns latency measurement
- [ ] `UpsellRecommendationTest.php` 8 tests : auth + validation + heuristics + branch isolation + ml fallback + container binding
- [ ] `OrderRatingTest.php` 7 tests : range + cross-branch + max comment + auth required
- [ ] `KioskThemeControllerTest.php` : auth admin + valid theme + valid branch_id

### 6.2 Vitest
- [ ] `KioskBurgerBuilder.spec.js` 10 tests
- [ ] `KioskThemeManagerPage.spec.js` 15 tests
- [ ] `kioskVoiceOrderingDialog.spec.js` 6 tests
- [ ] `kioskAdminA11ySection.spec.js` toggle persistence
- [ ] `KioskCartRestyle.spec.js` V1x-6 aria assertions
- [ ] `kioskThemeManager.spec.js` 26 tests (incl. 2 corrigés post URL fix)

---

## Phase 7 — Performance & UX (P2, ~10 min)

- [ ] Mix bundle delta vs main < 100 KB sur kiosk.js
- [ ] CSS tokens additifs uniquement (3 nouveaux)
- [ ] V2-2 POC : CSS transforms only (pas reflow)
- [ ] V2-5 themes : pas de FOUC au switch (CSS variables only)
- [ ] V2-4 voice : `prefers-reduced-motion` respect
- [ ] i18n keys cohérentes fr/en (ar partiel OK)

---

## Phase 8 — Validation owner (P2, ~15 min, post-merge)

- [ ] Démo POC `/kiosk/burger-builder-poc` sur kiosk physique (touch capacitif)
- [ ] Démo theme switch `/admin/kiosk-themes` Halloween → reload kiosk → orange/violet
- [ ] Démo upsell preview `/admin/upsell-preview` avec 3 cart items
- [ ] Smoke test cart V1x-3 sur 1080p + 4K (taille image varie)
- [ ] axe-core a11y audit sur pages touchées : zéro nouvelle violation WCAG AA
- [ ] Décision Phase B (V2-2/V2-3/V2-4 wizard integration)

---

## Decision matrix post-review

| Si toutes phases 1-7 ✅ | → MERGE approved |
| Si phase 1 ❌ | → BLOCK + redo waves |
| Si phase 2 ❌ | → BLOCK security review |
| Si phase 3 ❌ | → BLOCK frozen-zone violation |
| Si phase 4-5 ❌ partiel | → HEAL + recommit (max 3 cycles) |
| Si phase 6 ❌ | → BLOCK tests |
| Si phase 7 ⚠️ | → CONTINUE merge + perf BACKLOG |
