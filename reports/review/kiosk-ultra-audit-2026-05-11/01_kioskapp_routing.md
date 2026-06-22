# K01 — App root + Routing + Bootstrap

> Scope: Vue mount lifecycle, route guards, FR-lock V1 enforcement,
> idle timeout wiring, theme provide/inject, global error boundary.
> Branch `feature/mobile-app-le-cayenne-2026-05-10` HEAD `245e8ab57`.

## Files audited

| Path | Lines | Status |
|---|---|---|
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | 1576 | **FROZEN** — fixes tagged `[OWNER GATE REQUIRED]` |
| `resources/js/router/modules/kioskRoutes.js` | 292 | auditable |
| `resources/js/bootstrap-kiosk.js` | 79 | auditable |
| `routes/web.php` (kiosk SPA mount) | grep | auditable — catch-all only |

### Frozen drift vs `main` (verified via `git diff main..HEAD --stat`)

| File | Insertions | Deletions |
|---|---|---|
| `KioskAppComponent.vue` | **+ ~810** | **– ~199** (net +611 lines, summed shortstat `1009 +/-`) |
| `kioskRoutes.js` | +118 / split | (118 lines net delta) |
| `bootstrap-kiosk.js` | +68 / split | (68 lines net delta) |

`KioskAppComponent.vue` drift is **massive** (parallels the POS audit's
+892 finding). Drift adds: theme toggle UI + `loadKioskTheme/toggleTheme`,
catalog change toast + notifier, offline conflict CTA + modal, kiosk-auth-failed
+ kiosk-auth-retried listeners, hardware healthcheck phase 5.2, analytics
gate phase 5.5, item-availability prune-cart pipeline with branch-mismatch
guard, abandoned-orders indicator. No commits show explicit `[LOCK_*]` or
`[OWNER GATE]` tag — drift accreted opportunistically through P-MEGA-W6/W7,
iter15 round-4/7, cluster-3, KR2 cycle 6, etc. **Documentation gap = high.**

---

## Findings

### P0 (blocker pre-merge V1)

- **K01-P0-01: No global Vue error boundary protects the kiosk shell.**
  - File: `resources/js/components/frontend/kiosk/KioskAppComponent.vue:194-1059`
  - Issue: An uncaught exception inside any routed child (`KioskCategoriesComponent`,
    `KioskWizardComponent`, etc.) crashes the whole borne to a white screen.
    `<router-view>` (line 116-129) has no `errorCaptured` hook, no
    `<ErrorBoundary>` wrapper, and `app.config.errorHandler` is not set
    in `resources/js/app.js` (verified by grep — only admin has an
    `ErrorBoundary` component, kiosk does not import it).
  - Evidence: `grep -rn "errorCaptured|ErrorBoundary|errorHandler" resources/js/`
    returns matches only under `components/admin/*`. The same comment at
    line 111-115 admits a past prod incident where the DOM showed only
    the theme button — the symptom of an unhandled crash.
  - Suggested fix `[OWNER GATE REQUIRED]`: wrap `<component :is="Component">`
    in an `<ErrorBoundary>` (port the admin one, ~30 LOC) that surfaces a
    full-screen "Borne indisponible — Retry" fallback + analytics push
    `kiosk.shell.error`. Required because the borne is unmanned; a white
    screen burns customers for the duration of the manager's coffee break.

- **K01-P0-02: `requireKioskAuth` redirects 401 silently and the route guard
  is the only gate on the entire payment/order surface.**
  - File: `resources/js/router/modules/kioskRoutes.js:42-69`
  - Issue: When `kioskFilter/init` rejects or `store.state.kioskCart.kioskToken`
    is falsy, the user is bounced to `kiosk.login`. But `kioskLoginRouteGuard`
    (line 75-77) is a pass-through (`next()`) and the login screen runs
    the auto-credentials silently. If `kioskAutoLogin` config is misconfigured
    on a deployed borne, the borne enters an infinite redirect loop
    (`requireKioskAuth → login → auto-login fails → no manual fallback`).
    No `data-testid` "borne-not-configured" surface, no operator escape.
  - Evidence: `getKioskAutoCredentials` (line 27-36) returns `null` when
    `kioskAutoLogin.password` is empty; the guard's catch (line 53)
    just routes to login again — but the login component has no manual
    PIN form per the comment block (line 71-77: *"Il n'affiche plus de
    formulaire de saisie borne"*).
  - Suggested fix `[OWNER GATE REQUIRED]`: add explicit
    `KioskErrorBornNotProvisioned` screen + telemetry `kiosk.boot.config_missing`,
    or revive the staff PIN form behind `kioskSettings.allowManualLogin`
    flag for ops emergency.

- **K01-P0-03: Idle timer is the only thing standing between a left-mid-wizard
  cart and the next customer's order — but the timer is paused on
  `kiosk.payment` for legitimate reasons, with no orphan-cart sentinel.**
  - File: `resources/js/components/frontend/kiosk/KioskAppComponent.vue:875-904`
  - Issue: `startIdleTimer` skips `['kiosk.idle', 'kiosk.waiting', 'kiosk.payment',
    'kiosk.confirmation']` (line 881-882). The justification is correct
    (TPE interaction has no DOM events). But there is **no watchdog**:
    if a customer launches payment then physically walks away, the
    borne sits on `kiosk.payment` indefinitely — `kioskHardware` reports
    timeouts, but the Vue component never reads them to force a reset.
    Combined with the `pruneUnavailableLines` flow (line 700-701, 723-727)
    that can mutate the cart between payment.show and TPE confirm, the
    customer could pay a different total than agreed on the wizard.
  - Evidence: line 881 `noTimerRoutes` includes `kiosk.payment` ; no
    `setTimeout` inside `KioskPaymentComponent` (out of K01 scope, but
    `kiosk.payment` is reached via this guard, and no `payment_abandoned`
    analytics event exists in `kioskAnalytics.js` per grep — confirmed
    via the K01 visible call sites only).
  - Suggested fix `[OWNER GATE REQUIRED]`: add a hard cap watchdog
    (`config('kiosk.payment_timeout_ms', 300000)` = 5 min) that fires
    `resetKiosk()` from `KioskAppComponent` even on payment route, with
    a 30s countdown overlay. Mirror exists in KDS pickup-timeout pattern.

### P1 (high — V1.0.1 sprint)

- **K01-P1-01: `setLocale` is still called runtime in `useKioskA11y.js:86,160`
  despite ADR-007 FR-lock immutable.**
  - File: `resources/js/composables/useKioskA11y.js:86,160`
  - Issue: The `ADR-007 / iter15-P1a` comment in `KioskAppComponent.vue:181-183`
    promises *"aucun watcher runtime ne doit re-trigger setLocale"*, but
    `applyKioskA11yFromStore` (called line 331 of the shell) still does
    `setLocale(s.locale || 'fr')`. If `kioskSettings.locale` is persisted
    as `ar` or `en` from a prior session (the comment in `i18n.js:118-119`
    even warns about this), the borne switches off FR-lock at boot.
  - Evidence: `grep -n "setLocale" resources/js/composables/useKioskA11y.js`
    returns lines 30 (import), 86 (boot call), 160 (watcher inside the same
    composable).
  - Suggested fix: force FR at boot, ignore persisted store value when
    feature flag `KIOSK_FR_LOCK=true`. `useKioskA11y` should pass `'fr'`
    unconditionally on kiosk surface, or the i18n helper should refuse
    non-FR locales when document is a kiosk shell.

- **K01-P1-02: `kiosk.admin` route silently redirects to `kiosk.idle` —
  zero feedback to staff who deep-link there.**
  - File: `resources/js/router/modules/kioskRoutes.js:229-234`
  - Issue: `redirect: { name: "kiosk.idle" }` with no analytics, no toast,
    no log. Staff trying to access admin from the borne URL bar (deliberate
    or muscle memory) get a silent bounce, masking deploy / config issues
    where admin route is meant to be live.
  - Suggested fix: track via `trackLegacyRouteHit` (already imported line 2)
    so SRE has visibility on legacy URL hits.

- **K01-P1-03: `branchError` UX is FR-only via i18n — but a borne in a
  failed-init state has no language toggle.**
  - File: `resources/js/components/frontend/kiosk/KioskAppComponent.vue:43-48`
  - Issue: `branchError` displays `$t('kiosk.app.service_unavailable')`. If
    the borne is genuinely failing init (line 511-538 `loadBranch`), i18n
    might not be hydrated and the user sees `kiosk.app.service_unavailable`
    raw — the named past failure `Label.X` raw-key visible in PROJECT_BRAIN.
  - Suggested fix: hardcode FR fallback string `"Service indisponible"`
    inline as text node when i18n is not ready (probe via
    `this.$te('kiosk.app.service_unavailable')`).

- **K01-P1-04: `_loadSettingsIntoGlobalState` fetches `frontend/setting`
  pre-auth and swallows errors silently.**
  - File: `resources/js/components/frontend/kiosk/KioskAppComponent.vue:500-509`
  - Issue: The borne is in branchLoading=true overlay state but a parallel
    axios call hits `frontend/setting` without a token. On a 401 the catch
    just `console.warn`s and continues — the kiosk shell starts up with
    empty `globalState`, which downstream components (loyalty, allergens)
    may consume without realising the data is missing.
  - Suggested fix: gate behind `kioskToken` presence, or merge into
    `loadBranch` ordering so settings load after kiosk:order token is set.

- **K01-P1-05: `kiosk.confirmation` props uses `parseFloat(route.query.total)`
  with no guard against non-numeric (e.g., `?total=NaN&number=ABC`).**
  - File: `resources/js/router/modules/kioskRoutes.js:223-228`
  - Issue: A `total=foo` query lands as `NaN` and may render `formatPrice(NaN)`
    raw. The `[AUDIT-P47-BUG6]` comment claims a fix for zero, but didn't
    guard NaN.
  - Suggested fix: `Number.isFinite(parsed) ? parsed : null` wrapper.

- **K01-P1-06: Frozen drift of `KioskAppComponent.vue` is undocumented
  (≈+611 net lines vs main) — directly contradicts FROZEN policy.**
  - File: `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
  - Issue: Memory note `feedback_wizard_popup_pos_protected` declares
    POS wizard *parfait*, and `00_ULTRA_PLAN.md §3` lists this file as
    FROZEN. Yet 30+ commits modified it (theme toggle, catalog toast,
    healthcheck phase 5.2, analytics gate phase 5.5, hardware bridge,
    auth retry listeners, offline conflict CTA — see `git log main..HEAD`).
    No `LOCK_*.md` doc found under `plans/` for these.
  - Suggested fix `[OWNER GATE REQUIRED]`: backfill 1 retrospective LOCK doc
    grouping iter15/W6-W7/cluster-3 changes; OR re-baseline the frozen
    snapshot at HEAD and update memory note accordingly.

### P2 (medium)

- **K01-P2-01: `_offlineQuotaListener` toast hardcodes FR string.**
  - File: `KioskAppComponent.vue:349-351` — `'File saturée. Veuillez relancer la borne.'`
  - Issue: FR-only acceptable per V1 FR-lock, but violates i18n pattern used elsewhere in the same file.
  - Suggested fix: move to `kiosk.app.offline_quota_exceeded` key, even if only FR translation exists.

- **K01-P2-02: `themeToggleAriaLabel` (line 300-304) hardcodes FR.**
  - File: `KioskAppComponent.vue:300-304`
  - Issue: `'Passer en mode clair' / 'Passer en mode sombre'` not wrapped in `$t()`. AR/RTL kiosk would expose FR aria-label.
  - Suggested fix: `kiosk.app.theme_toggle_to_light/dark` keys.

- **K01-P2-03: Cart-bar `showCartBar` hidden-route list is a maintenance trap.**
  - File: `KioskAppComponent.vue:261-272`
  - Issue: 9-route exclusion list. Any new route (e.g., a future allergens-deep-dive screen) will accidentally show the cart bar unless someone remembers to add it.
  - Suggested fix: invert to meta-flag `meta.showCartBar = true` declared per-route in `kioskRoutes.js`.

- **K01-P2-04: `getKioskAutoCredentials` reads `window.foodkingConfig` which is server-rendered — no schema validation.**
  - File: `kioskRoutes.js:27-36`
  - Issue: Silent string coercion `String(a.password)`. A stringified `null`/`undefined` from blade template could match `!== ''` and trip the auth call with garbage.
  - Suggested fix: validate password as non-empty string after trim.

- **K01-P2-05: `_handleCouponChanged` (line 780-812) catches `clearCouponCache` reject silently — masks a Vuex regression.**
  - File: `KioskAppComponent.vue:801-811`
  - Issue: "Action may not exist yet; that's OK" comment is a smell. If the action is added later then bugs out, the silent catch hides it.
  - Suggested fix: `try { … } catch (e) { console.warn('[KioskApp.coupon]', e); }`.

- **K01-P2-06: `bootstrap-kiosk.js` pre-seeds localStorage `foodking:kiosk-theme=light` before Vue mount but `loadKioskTheme()` runs again at mounted (line 324) — race condition if user toggles theme in iter15 dark mode and then bootstrap re-seeds to light.**
  - File: `bootstrap-kiosk.js:56-63`
  - Issue: The pre-seed checks if a value exists before writing — good. But the comment claims "écriture explicite localStorage prend précédence" — verified. P2 nit: this contract is fragile and the bootstrap reads the **frozen** component's storage key (`foodking:kiosk-theme`) without abstraction. If the key changes, bootstrap silently desyncs.
  - Suggested fix: extract storage key to a shared constant `kiosk-theme-storage-key.js`.

### P3 (low / nice-to-have)

- **K01-P3-01: `kiosk.products/:categoryId` legacy redirect (line 165-170) still in place — track and prune.**
- **K01-P3-02: `HEALTHCHECK_INTERVAL_MS = 90000` hardcoded — should pull from `kioskSettings.healthcheckMs`.**
- **K01-P3-03: 1576 lines in one SFC — refactor target. Hardware/analytics/auth-bridge could be Pinia composables.**

---

## Existing E2E coverage

- `tests/e2e/kiosk-spa-black-screen-guard.spec.js` — pins the synchronous-import
  trio (App + Idle + Categories) and the regression where `<router-view>`
  rendered nothing on `/kiosk/categories` cold-load. Anchored in the comment
  block `kioskRoutes.js:3-5`.
- `tests/e2e/03-kiosk-wizard.spec.js` — happy path `/kiosk/idle → wizard`.
- `tests/e2e/kiosk-happy-path.spec.js` — end-to-end happy flow.
- `tests/e2e/audit-kiosk-ux-2026-05-07.spec.js` — `/kiosk/cart` empty
  state + summary contract.
- `tests/e2e/audit-kiosk-cycle5-2026-05-07.spec.js:43` — asserts
  `/kiosk/admin` redirect to `/kiosk/idle` (covers K01-P1-02 happy side
  only — silent redirect is by-design per this spec).
- `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-D.spec.js` — wraps
  `requireConfirmationContext` guard (mentioned in spec comment line 602).
- `tests/js/KioskPhase3Routes.spec.js` — Vue Router setup unit tests.
- `tests/js/kioskFrLockImmutable.spec.js` — guards K01-P1-01.
- `tests/js/kioskIdleWarningEvent.spec.js` — guards `idle_warning_shown` analytics.
- `tests/js/kioskRouterLockdown.spec.js` — covers the forbidden-asset 404 (`routes/web.php:60`).
- `tests/js/kioskSettingsIdleTimeouts.spec.js` — guards `startIdleTimer`
  reading `kioskSettings.idleMs / confirmMs` (line 887-888).

**Coverage gap:** zero spec covers (a) global error fallback when a child
component throws, (b) `requireKioskAuth` auto-credentials *failure* leading
to indefinite loop, (c) payment-route watchdog (K01-P0-03).

---

## Proposed new E2E tests

- **T-K01-01: SPA crash fallback surfaces a visible error.**
  - Steps: Inject `throw new Error('crash')` into a stubbed
    `KioskCategoriesComponent.mounted`, then `page.goto('/kiosk/categories')`.
  - Assertions: `data-testid="kiosk-shell-error"` is visible within 3s,
    contains FR "Borne indisponible", and offers a retry button which
    `location.reload()`s. Currently no such surface — test will fail until
    K01-P0-01 is fixed. Doubles as the failing-red test.

- **T-K01-02: `kioskAutoLogin` misconfig surfaces operator-actionable screen.**
  - Steps: Override `window.foodkingConfig.kioskAutoLogin = { username: 'x', password: '' }`
    in init script; mock `POST /api/kiosk-login` → `400`; `page.goto('/kiosk/idle')`.
  - Assertions: After ≤5s, page does NOT loop between `/kiosk/idle` and
    `/kiosk/login`; instead a `data-testid="kiosk-boot-config-missing"`
    is rendered with FR copy. Pins K01-P0-02.

- **T-K01-03: Payment timeout watchdog.**
  - Steps: Stub `kioskCart/createOrder` to land on `/kiosk/payment` and
    halt; wait 5min15s (or use `page.clock.fastForward` Playwright API).
  - Assertions: After `kiosk.payment_timeout_ms`, an overlay countdown
    appears (30s), then the borne navigates to `/kiosk/idle` and dispatches
    `payment_abandoned` analytics. Pins K01-P0-03.

- **T-K01-04: FR-lock immutable when store rehydrated with `locale=ar`.**
  - Steps: Pre-seed `localStorage.foodking:kiosk-settings` with
    `{ locale: 'ar' }`; load `/kiosk/idle`.
  - Assertions: `document.documentElement.lang === 'fr'` and
    `document.documentElement.dir === 'ltr'`. Pins K01-P1-01.

- **T-K01-05: `kiosk.confirmation?total=NaN` does not render raw NaN.**
  - Steps: Seed Vuex `orderRef = 'offline_1'`; `page.goto('/kiosk/confirmation?number=A123&total=NaN')`.
  - Assertions: No occurrence of `NaN`, `null€`, or `0undefined` in the
    DOM. Pins K01-P1-05.

---

## Risks & open questions

- **R-1 (owner gate)**: ~611 net lines drift inside a FROZEN file with no
  `LOCK_*.md` per commit. Either backfill retrospective LOCK doc covering
  the iter15 + P-MEGA-W6/W7 + cluster-3 + KR2 commit set, OR re-baseline
  the frozen snapshot at HEAD and amend the memory note
  `feedback_wizard_popup_pos_protected` to distinguish "POS wizard"
  (strict no-touch) from "Kiosk app shell" (frozen-with-audit-trail).
  See `feedback_kiosk_wizard_not_protected`.

- **R-2 (architecture)**: kiosk auth model is entirely client-side guard
  + bearer token attached at axios level (no `auth:sanctum` middleware on
  the `/kiosk` web route — verified `routes/web.php:62` catch-all).
  This is correct V1 design (the SPA loads anonymously, then issues
  `POST /api/kiosk-login` and stores token). The 6 ability checks in
  `OrderRequest`, `LoyaltyController`, `UpsellController`, `MenuController`,
  `PromoValidateRequest`, `PricingPreviewRequest` (verified via grep)
  are the actual gate. **No SPA-level Sanctum guard is missing — confirmed.**

- **R-3**: Vue 3 `app.config.errorHandler` not set globally (verified via
  grep of `resources/js/app.js`). All silent JS errors disappear into
  `console.error`. Combined with the kiosk being unattended, this is the
  single most impactful invisible failure mode.

---

## Verdict: **HEAL**

The shell works on the happy path and covers an impressive surface area
(theme, a11y, offline queue, hardware, analytics, RTL). But three P0s
materially block a V1 production sign-off on an unattended borne:

1. No global error boundary on a 1576-line shell that wraps every kiosk surface.
2. Auth misconfig can loop the customer between guard and login indefinitely.
3. Payment timeout has no watchdog despite the legitimate idle-timer pause.

Plus the unannounced frozen drift = **policy gap** that owner must resolve
before any further frozen-zone work. Heal in V1.0.1, do not block merge
on cosmetic P1/P2.
