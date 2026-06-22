# REPORT_TASK18 — Audit `type="button"` + a11y K-7 (motion / reduced-motion / i18n bidir)

**Date** : 2026-04-20  **Auditeur** : subagent `explore` (readonly)
**Racine auditée** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93`
**Scope** : surfaces kiosk Vue + tokens CSS + composable + workflows CI + i18n FR/EN/AR
**Mode** : lecture / glob / grep uniquement — aucune modification, aucun test exécuté

---

## Verdict global

**FAIL** (7 / 8 V cochées).

- L'invariant K-7 a11y `type="button"` est tenu **dans le worktree p93** (0 violation sur 44 SFC kiosk),
  les motion tokens, le composable `useKioskMotion`, la couverture reduced-motion (15 surfaces ≥ 11),
  l'empty-state Upsell et la parité i18n `kiosk.*` (FR=EN=AR=577 clés, delta = 0) sont conformes.
- En revanche, **aucun workflow GitHub Actions n'exécute le spec vitest** `kioskA11yButtonTypeAudit.spec.js`.
  Seuls `playwright.yml` et `phpunit.yml` existent ; `npm test` (vitest) n'est ni listé, ni invoqué.
  L'audit K-7 est donc **constaté en local** mais **non garde-fou en CI** → V2 KO.
- Risque cycle (hors scope strict de p93, mais relevé pour traçabilité) : le worktree principal
  `testttt/` présente **70 `<button>` sans `type=`** dans 14 SFC kiosk (cf. § Annexe — Divergence inter-worktrees),
  preuve que sans CI bloquante la régression revient effectivement.

---

## Checklist multi-points (p93 = racine de la tâche)

| #  | Critère                                                    | Résultat | Détail |
|----|------------------------------------------------------------|:--------:|--------|
| V1 | 0 `<button>` kiosk sans `type=` (rg + tokenizer K-7 spec)  | ✅ PASS | 44 SFC scannés, **0 offender** (`tests/js/kioskA11yButtonTypeAudit.spec.js` algorithme rejoué). |
| V2 | Audit CI `type="button"` actif                             | ❌ FAIL | Spec vitest présent, mais **aucun workflow** ne lance `npm test` / vitest (cf. `.github/workflows/playwright.yml`, `phpunit.yml`). Pas de `bin/audit-button-type.sh`. |
| V3 | Motion tokens définis + utilisés                           | ✅ PASS | `resources/css/kiosk/tokens.css` L129–138 (`--kiosk-duration-fast 140ms`, `--kiosk-duration-base 240ms`, `--kiosk-duration-slow 280ms ≤ 300ms`, `--kiosk-ease-standard cubic-bezier(.4,0,.2,1)`). Utilisés dans 15 fichiers kiosk (Cart, Categories, Confirmation, Login, Waiting, App, Idle, Upsell, Payment, ProductList + ds/{Stepper,Modal,Chip,Card,Button}). |
| V4 | `useKioskMotion` respecte `prefers-reduced-motion`         | ✅ PASS | `resources/js/composables/useKioskMotion.js` — `matchMedia('(prefers-reduced-motion: reduce)')` réactif, listener nettoyé `onBeforeUnmount`, idempotence `_emittedOnce`, télémétrie `ui.motion_preference` ; fallback silencieux SSR / Safari legacy. |
| V5 | ≥ 11 surfaces avec gate reduced-motion                     | ✅ PASS | **15 fichiers** matchent `prefers-reduced-motion` ou `useKioskMotion` (App, Idle, Upsell, Confirmation, Waiting, Categories, Login, Loyalty, Payment, ProductList, Wizard, OfflineBanner, InactivityOverlay, PromoCarousel + `ds/KsAllergenBadge`). Spec `kioskK7MotionTokens.spec.js` itère explicitement les 11 hot surfaces. |
| V6 | Parité i18n FR / EN / AR (delta = 0)                       | ✅ PASS | Sur la branche `kiosk.*` : FR=EN=AR=**577 clés**, **0 manquante** dans chaque locale (script de comparaison rejoué sur `resources/js/languages/{fr,en,ar}.json`). Note : delta hors `kiosk.*` (admin BO) existe mais hors invariant K-7 / ADR-7. |
| V7 | Empty-state Upsell présent                                 | ✅ PASS | `KioskUpsellComponent.vue` L32–34 (`kiosk-upsell-empty-icon` + `empty_title` + `empty_hint`), L185–193 télémétrie `ui.state_shown` + auto-skip. Clés présentes en FR/EN/AR (`upsell_screen.empty_title` / `empty_hint`). |
| V8 | Aucune violation `aria-hidden` sur focusable               | ✅ PASS | Toutes les occurrences `aria-hidden="true"` portent sur `<svg>`, `<span>`, `<div>` décoratifs (icônes, spinners, dividers). Aucun `<button>`, `<a>`, `<input>` ni élément `tabindex≥0` n'est marqué `aria-hidden`. |

**Score** : 7 / 8 → **FAIL** (la règle « PASS = 8 V cochées » n'est pas atteinte).

---

## Preuves (extraits ciblés)

### V1 — Audit `<button>` (tokenizer K-7 spec)

Algorithme rejoué (strip `<script>`/`<style>`, consommation jusqu'au `>` non-quoté, regex `\btype\s*=`),
identique à `tests/js/kioskA11yButtonTypeAudit.spec.js` :

```
files= 44   naked= 0
```

Tous les `<button` multi-ligne (`KioskCart`, `KioskUpsell`, `KioskWizard`, `KsA11ySettings`,
`KsVirtualKeyboard`, `KsConsentModal`, `KsModal`, `KsChip`, `KsButton`, `KioskCategories`,
`KioskAdmin`, `KioskWaiting`, `KioskLoyalty`, etc.) déclarent `type="button"` dans la balise ouvrante.

### V2 — Absence d'enforcement CI

```
.github/workflows/
├── playwright.yml   → npx playwright test (E2E) — pas de vitest
└── phpunit.yml      → vendor/bin/phpunit (PHP)  — pas de vitest
```

`package.json` :

```json
"scripts": {
  "test": "vitest run",
  "test:watch": "vitest"
}
```

→ Le hook `npm test` existe mais n'est invoqué par aucun job CI. Aucun script `bin/audit-button-type.sh`
ni équivalent shell n'existe. L'invariant n'est donc **pas bloquant à la merge**.

### V3 — Tokens motion (`resources/css/kiosk/tokens.css`)

```
129:  --kiosk-duration-instant: 0ms;
130:  --kiosk-duration-fast:    140ms;
131:  --kiosk-duration-base:    240ms;
132:  --kiosk-duration-slow:    280ms;     ← ≤ 300 ms (ADR-1)
133:  --kiosk-duration-idle:    800ms;     ← gated reduced-motion
135:  --kiosk-ease-standard:    cubic-bezier(0.4, 0, 0.2, 1);
177:  @media (prefers-reduced-motion: reduce) { :root { fast/base/slow/idle = 0ms } }
```

### V5 — Couverture reduced-motion (15 fichiers)

```
KioskAppComponent, KioskIdleScreenComponent, KioskUpsellComponent, KioskConfirmationComponent,
KioskWaitingComponent, KioskCategoriesComponent, KioskLoginComponent, KioskLoyaltyComponent,
KioskPaymentComponent, KioskProductListComponent, KioskWizardComponent,
KioskOfflineBannerComponent, KioskInactivityOverlayComponent, KioskPromoCarouselComponent,
ds/KsAllergenBadge.vue
```

(Spec `kioskK7MotionTokens.spec.js` itère explicitement 11 surfaces hot ; couverture observée = 15.)

### V6 — Parité i18n `kiosk.*`

```
{ totals:        { fr: 577, en: 577, ar: 577, union: 577 },
  missingCounts: { fr:   0, en:   0, ar:   0 } }
```

Spec `kioskI18nParity.spec.js` interdit l'orphelin (sauf `kiosk._legacy.*`), allowlist vide.

### V7 — Empty-state Upsell

`KioskUpsellComponent.vue` L31–35 :

```
<div v-if="!loading && availableUpsells.length === 0" class="kiosk-upsell-empty" role="status" ...>
  <div class="kiosk-upsell-empty-icon" aria-hidden="true">🍽️</div>
  <p class="kiosk-upsell-empty-title">{{ $t('kiosk.upsell_screen.empty_title') }}</p>
  <p class="kiosk-upsell-empty-hint">{{ $t('kiosk.upsell_screen.empty_hint') }}</p>
</div>
```

Auto-skip + télémétrie `ui.state_shown` (L185+).

### V8 — `aria-hidden`

Toutes les occurrences (≈70 dans 22 fichiers) ciblent des conteneurs décoratifs : `<svg>` (icônes),
`<span class="…-icon">` (emoji), `<div class="…-spinner|…-thumb-wrap|…-cta|…-anim">`. Aucune
sur `<button>`, `<a>`, `<input>`, `<select>`, `[tabindex]`.

---

## Annexe — Divergence inter-worktrees (signal hors scope strict)

Même algorithme rejoué sur `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
(worktree principal — racine d'où le rapport est écrit) :

```
files= 44   naked= 70
```

Top fichiers concernés (extraits) :

| Fichier                              | Naked buttons |
|--------------------------------------|--------------:|
| `KioskAdminComponent.vue`            | 16 |
| `KioskLoyaltyComponent.vue`          | 12 |
| `KioskCartComponent.vue`             | 12 |
| `KioskWaitingComponent.vue`          | 6  |
| `KioskWizardComponent.vue`           | 5  |
| `KioskCategoriesComponent.vue`       | 5  |
| `KioskProductListComponent.vue`      | 3  |
| `KioskUpsellComponent.vue`           | 2  |
| `KioskPaymentComponent.vue`          | 2  |
| `KioskConfirmationComponent.vue`     | 2  |
| `KioskOrderSummaryComponent.vue`     | 2  |
| `KioskAppComponent.vue` / `KioskIdleScreenComponent.vue` | 1 + 1 |

→ Le patch K-7 « cleanup `aria-hidden` + ajout `type="button"` » n'a été appliqué **que** dans
le worktree `testttt-kiosk-p93`. Le worktree principal a régressé (ou n'a jamais reçu le patch).
Le diff récent visible dans le `git status` (`M KioskCartComponent.vue` côté `testttt`) confirme
que la régression touche aussi le worktree de référence où la merge se fera. Cela justifie
l'urgence d'activer la CI (V2) : sans elle, la dérive est invisible.

---

## Si FAIL → Top 3 actions correctives

1. **Activer la CI vitest sur l'invariant K-7** (priorité bloquante).
   - Créer `.github/workflows/vitest.yml` (jobs `node-version: 18`, `npm ci`, `npm test`) déclenché
     `pull_request: [main, develop]`. La spec `kioskA11yButtonTypeAudit.spec.js` (et `kioskI18nParity`,
     `kioskK7MotionTokens`) deviennent alors bloquants. Effort ≈ 30 min.
2. **Réconcilier les worktrees** `testttt` ↔ `testttt-kiosk-p93` sur le patch K-7.
   - Porter le patch « ajout `type="button"` × 70 occurrences » de p93 vers `testttt` (cherry-pick
     du commit K-7 ou re-application). Sinon la merge p93→main réintroduira l'invariant et la
     prochaine itération échouera. À traiter par sous-agent `generalPurpose` (T18b).
3. **Étendre la spec à un audit `aria-hidden` sur focusables** (durcissement).
   - Ajouter dans `kioskA11yButtonTypeAudit.spec.js` (ou nouveau `kioskA11yAriaHiddenAudit.spec.js`)
     un check qui rejette toute balise `<button|a|input|select|textarea|[tabindex]`> portant
     `aria-hidden="true"`. Aujourd'hui 0 violation, mais aucun garde-fou ne l'empêche demain.
     Couvre l'invariant V8 explicitement en CI.

---

## Lecture / sources auditées

- `tasks/audit-orchestration/18_TASK_TYPE_BUTTON_A11Y_K7_2026-04-20.md` (prompt)
- `tasks/k-hardening/PLAN_K7_UX_SPLASH_POLISH_2026-04-18.md` (référencé)
- `reports/execution/VERIFY_K7_UX_SPLASH_POLISH_2026-04-18.md` (référencé)
- `resources/js/components/frontend/kiosk/**/*.vue` (44 SFC, p93)
- `resources/js/languages/{fr,en,ar}.json` (577 clés `kiosk.*` chacune)
- `resources/css/kiosk/tokens.css`
- `resources/js/composables/useKioskMotion.js`
- `tests/js/kioskA11yButtonTypeAudit.spec.js`, `tests/js/kioskK7MotionTokens.spec.js`,
  `tests/js/kioskI18nParity.spec.js`
- `.github/workflows/playwright.yml`, `.github/workflows/phpunit.yml`
- `package.json`
