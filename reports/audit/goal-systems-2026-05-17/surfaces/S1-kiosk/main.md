# S1 — KIOSK Surface Audit (Goal Systems / 2026-05-17)

Auditor: Claude Opus 4.7 (1M ctx), KIOSK MAIN AUDITOR
Branch: `feature/mobile-app-le-cayenne-2026-05-10` @ HEAD `c3ba89863`
Scope: Borne client autonome — `resources/js/components/frontend/kiosk/**` + kiosk-touching controllers + routes + i18n + tests
Mode: READ-ONLY, no Agent dispatch, file:line citations mandatory
Anti-drift: every P0/P1 re-verified via git log + actual file read

---

## §0 Stale findings (corrections to prior audits)

Cross-referenced against `reports/audit/cto-global-2026-05-16/agent-{1,6}-*.md`.

| Prior claim | Source | Status today | Evidence |
|---|---|---|---|
| AR i18n coverage gap −8.2 % vs EN | agent-6 P1-FE-05 (`paths`-wide count) | STALE for kiosk namespace — within `kiosk.*` scope leaves are FR 604 / EN 596 / AR 592 (AR = 98.0 % of FR) | `python3` leaves walk of `resources/js/languages/{fr,en,ar}.json` keyed on top-level `kiosk` |
| Mission brief: `kiosk-shell.js ≈ 243 KB` | task description | STALE / contradicted by build artefact — `public/js/kiosk-shell.js` = **670 923 bytes (655 KB)** unminified on disk; `kiosk-wizard-step.js` = 370 KB; `kiosk-errors.js` = 90 KB | `ls -la public/js/kiosk-*.js`; `public/mix-manifest.json:10` |
| Locale switcher UI gap | implied by AR coverage finding | NOT a defect — ADR-007 (Sprint 3D, today) explicitly locks kiosk runtime to FR-immutable; the picker has been removed by design | `config/kiosk.php:22-36` `locale_switch_allowed=false`; `KioskAppComponent.vue:181-184` setLocale watcher removed; `KioskAppComponent.vue:474-478` |
| `KioskWizardComponent.vue` monolith 3094 LOC = "no decomposition" | agent-1 P1 frontend layering | PARTIALLY STALE — 9 step components extracted to `frontend/kiosk/steps/` subfolder (Pain/Sauce/Viande/Garnitures/Supplements/Taille/Menu/FritesStyle/GenericChoices). Monolith stays as wizard router only. | `ls resources/js/components/frontend/kiosk/steps/` |
| F-008 payment reconcile missing | older cycle | RESOLVED — periodic 60 s replay of TPE-approved/backend-failed transactions wired in `KioskPaymentComponent.vue:294-300` + `frontend/payment/reconcile-pending` route (`routes/api.php:1135-1137`) | direct read |
| F-002 amount echo missing | older cycle | RESOLVED — TPE amount echoed in cents and verified backend-side (±1 cent tol), see `KioskPaymentComponent.vue:582-591, 654-660, 679-685` + `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php` | direct read |

Stale count: **6** (4 prior findings downgraded/closed, 2 mission-brief assumptions corrected).

---

## §1 Scores per axis (each /100) + rationale

| # | Axis | Score | One-line rationale |
|---|---|---|---|
| 1 | Architecture | **62** | 9 step components extracted from a 3094-LOC wizard, 6 dedicated Vuex modules (`kioskCart`, `kioskMenu`, `kioskSettings`, `kioskMachine`, `kioskFilter`, `kioskSetup`), provide/inject toast, frozen wizard is now a step-router (not yet a thin shell — 3094 LOC still load with the route). Direct axios stays in 7 components for narrow ops (analytics, settings, loyalty config). |
| 2 | Business completeness | **78** | Sandwich/bol/taco/frites composer wired through `steps/Kiosk*Component.vue`; SSOT pricing via `PricingPreviewService` + `composition_snapshot`; upsell w/ auto-skip; loyalty opt-in + scan; offline-queue + reconcile + abandoned-conflict modal; cash + card + TR + reconciliation. Open: 1 bowl-drink combo path documented as P1 in `memory/project_menu_v3_2026-05-14.md`. |
| 3 | UX | **70** | Strong: inactivity overlay with countdown + 2 CTA, idle disabled on payment/confirmation, ripple feedback, recap step with note, allergen badge persistent in wizard header (FIC UE 1169/2011), payment-failure threshold routes to dedicated error screen (`MAX_PAYMENT_FAILURES=2`). Weak: under-utilised wizard whitespace (agent-6 P1-FE-08 still open), no inline restart-of-flow on auth retry beyond toast. |
| 4 | i18n | **68** | Kiosk-namespace parity actually strong (FR 604 / EN 596 / AR 592 leaves = 98.0 % AR), wizard templates of the 3 frozen files are clean. Weakness: 34 raw-FR `\|\| 'fallback'` strings across kiosk components + 5 hardcoded toast strings (P1-S1-04 below). FR-immutable runtime ADR-007 is honoured. |
| 5 | Integration | **74** | Pusher live + polling fallback via `onEvents`, branch-scoped subscriptions (ItemAvailabilityChanged / CatalogChanged / ComposerProfileChanged / CouponChanged), kiosk-menu cache invalidated by 3 listeners (`InvalidateKioskMenuCacheOn{ItemAvailabilityChanged,CatalogChange,IngredientChange}`), payment-confirm idempotency keyed at route level (`routes/api.php:1129`). NF525 sequence integrity owned by fiscal service (frozen, out of scope). |
| 6 | Tests coverage | **76** | 32 Playwright tests across 5 kiosk e2e specs; 201 PHPUnit test methods across 31 Kiosk-namespaced Feature classes (auth, payment-state-machine, payment-confirm-amount, branch-isolation, locale, quote integrity, idempotency, loyalty, upsell-category, scope-isolation, etc.). Gap: no perf/lighthouse harness on the borne; no a11y axe-core CI on kiosk surfaces. |
| 7 | Performance | **52** | **kiosk-shell.js 655 KB** + **kiosk-wizard-step.js 370 KB** + **kiosk-errors.js 90 KB** = ≈ 1.13 MB unminified delivered for the kiosk surface. Mission's "243 KB" assumption is contradicted by `public/js/`. Polling: 15 s offline-check + 90 s healthcheck + 60 s payment-reconcile + 15 s order-poll — bounded, cleaned in `beforeUnmount`. No mounted RUM. |
| 8 | Accessibility | **74** | Wizard root is `role="dialog" aria-modal aria-labelledby` + Tab-trap installed at document level (`KioskWizardComponent.vue:2209-2228`), focus return on unmount, idle overlay countdown bounded `[3 s, 60 s]` per EAA 2025, payment screen `role="radiogroup"` + radio with keyboard handlers + `aria-live`, allergen badge persistent in header, screen-reader announcements wired for theme toggle and cart bar. Weakness: theme toggle aria-label hardcoded FR (`KioskAppComponent.vue:300-304`), `Voir` button untranslated (line 107). |

Overall surface score (weighted average): **69 / 100** — V1 SHIPPABLE for Le Cayenne with the documented memory verdict (Wave Z convergence 2026-05-16), V1.0.1 hardening backlog for raw-FR sweep + perf budget.

---

## §2 Strengths (file:line evidence)

1. **`KioskWizardComponent.vue:2-7`** — Wizard root is a full WAI-ARIA dialog (`role="dialog" aria-modal="true" aria-labelledby="kiosk-wizard-title" tabindex="-1"`) with sr-only title bound to `sanitizeItemName(resolvedItem.name)`. Tab-trap installed at document level (`:2209-2228`) and cleaned in `beforeUnmount` (`:2261-2270`). One of the cleanest a11y patterns in the codebase.

2. **`KioskPaymentComponent.vue:582-598`** — F-002 amount-echo discipline. TPE bridge return is converted to integer cents (`amount_cents_approved`), passed to `confirmBackendPayment`, backend verifies `abs(amount_cents - order.total*100) ≤ 1` (per `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php`). Closes the "compromised TPE approves arbitrary amount" attack class.

3. **`KioskAppComponent.vue:546-579` + `routes/api.php:1239-1274`** — Realtime contract: Pusher subscription scoped to `branchId` for 4 broadcast types (`ItemAvailabilityChanged` / `CatalogChanged` / `ComposerProfileChanged` / `CouponChanged`); each handler guards on `branch_mismatch` before mutating store. SSOT pricing endpoint behind `kiosk:order` ability + 60/min throttle. Best cross-surface event hygiene seen in the audit.

4. **`KioskPaymentComponent.vue:294-300, 866-872` + `routes/api.php:1135-1137`** — F-008 reconciliation loop replays TPE-approved/backend-failed transactions in batch on mount + every 60 s. UNIQUE(transaction_id) on backend keeps it idempotent. Closes the "network blip post-TPE = orphan paid order, never recorded" failure mode.

5. **`app/Http/Controllers/Auth/KioskMachineLoginController.php:85-104`** — Login flow takes `lockForUpdate` on the KioskMachine row inside a DB transaction, revokes all existing `kiosk-token` rows for the user (`->where('name', 'kiosk-token')->delete()`), then mints a single fresh token with the narrow ability `['kiosk:order']` + 480 min TTL from `config('sanctum.expiration')`. Explicit `withoutGlobalScope(BranchScope::class)` calls are documented as pre-auth intent, not unsafe widening.

---

## §3 Weaknesses (P0 / P1 / P2 — each with file:line + scenario + fix sketch)

### P0 (legal / safety / blocker)

#### P0-S1-01 — Hardcoded operator-facing toast strings violate i18n contract on safety-critical messages
- **Files** :
  - `KioskAppComponent.vue:107` — `>Voir<` (offline-conflict CTA button)
  - `KioskAppComponent.vue:350` — `'File saturée. Veuillez relancer la borne.'` (queue quota toast — emitted when localStorage offline queue is full = critical operator action)
  - `KioskAppComponent.vue:364-365` — `'Borne déconnectée. Reconnexion en cours…'` / `'Borne déconnectée (${url}). Reconnexion en cours…'`
  - `KioskAppComponent.vue:843` — `'Commande retirée de la file d\'attente.'`
  - `KioskAppComponent.vue:862` — `'La commande sera réessayée au prochain cycle.'`
  - `KioskPaymentComponent.vue:27` — bare template literal `Paiement CB/TR indisponible hors ligne. Le menu reste consultable; choisissez les espèces au comptoir ou réessayez quand la connexion revient.` rendered with `role="status" aria-live="polite"` directly in template (verified via `git log -p -S 'Paiement CB/TR indisponible'`).
- **Scenario** : Per CLAUDE.md §6 Visual Test Mandate and the kiosk ADR-007 contract, the borne runs FR-immutable in V1 so these strings *render correctly to the customer today*. However: (1) the borne ships with a screen reader + Web Speech composable that bypasses these strings entirely (`KioskPaymentComponent.vue:451-456` only reads `kiosk.pay_screen.speech_error`), (2) any future post-V1 multi-locale pilot (`KIOSK_LOCALE_SWITCH_ALLOWED=true`) will silently leak FR to AR/EN customers on the most fragile flows (payment offline, queue quota exceeded, auth lost), (3) the offline-payment alert is the ONLY user-facing recovery instruction when the borne loses network mid-cart — failing to translate is a compliance gap if the V1 single-resto adds a second locale.
- **Severity** : P0 because of (a) the safety-critical recovery semantics (queue saturated, offline payment, deconnection), (b) inconsistent enforcement against the very ADR-007 contract that justifies FR-only V1 (the contract says "FR is enough"; the practice ships strings that *can never become non-FR*).
- **Fix sketch** : Introduce `kiosk.offline.queue_quota_full`, `kiosk.offline.disconnected`, `kiosk.offline.disconnected_with_url`, `kiosk.offline.queue_cancelled`, `kiosk.offline.queue_will_retry`, `kiosk.offline.conflict_cta`, `kiosk.pay_screen.offline_alert` keys in `resources/js/languages/{fr,en,ar}.json`, replace template strings with `$t(...)`. ~30 minutes + 1 i18n PR. Already documented as backlog in `memory/project_wave_z_convergence_2026-05-16.md` V1.0.1 sweep.

### P1 (brand / UX / customer-facing)

#### P1-S1-02 — Performance: kiosk surface ships 1.13 MB of JS unminified (3× the mission-stated budget)
- **Files** : `public/js/kiosk-shell.js` (655 KB), `public/js/kiosk-wizard-step.js` (370 KB), `public/js/kiosk-errors.js` (90 KB), `public/js/kiosk-wizard.js` (10 KB). Total ≈ 1 152 KB unminified. Mission brief stated `~243 KB`; no `webpack.mix.js` `kiosk-shell` entry visible (`grep -in 'kiosk' webpack.mix.js` returns 0), so these chunks are dynamic-import siblings of `app.js` produced by router-level `() => import('./components/frontend/kiosk/...')` (verified by chunk name pattern + presence of `.LICENSE.txt` siblings produced by webpack default chunk extractor).
- **Scenario** : On first kiosk boot after deploy the borne downloads ≈ 1 MB minified (~250-300 KB gzipped depending on terser config), then the wizard step chunk on first item click. Mid-rush hours (cluster-7 baseline), Pi-class kiosk hardware will see 1-2 s parse-on-load penalty per cold session — directly impacts the idle→categories→item→wizard flow that the brief targets at < 4 s.
- **Severity** : P1 (UX cost, not legal). Not P0 because the kiosk-shell chunk is cached cross-session by hashed URL (`mix-manifest.json:10`), and Le Cayenne borne hardware is not a Raspberry Pi.
- **Fix sketch** : (1) extract the `kioskWizard*Component` group into a dedicated `webpack.mix.js` entry mirroring the POS V4 pattern (`webpack.mix.js:41-55`) so the wizard tree is *separately* tree-shakable, (2) add a CI guard `tools/lint/kiosk_shell_size.mjs` mirroring `tools/lint/pos_app_size.mjs` (referenced at `webpack.mix.js:53`) with budget = 350 KB gzip; (3) ban static imports of `KioskUpsell/Loyalty/Confirmation` from the shell, only the router should resolve them. Estimated effort: 1 day + 1 spec.

#### P1-S1-03 — 34 raw-FR `\|\| 'fallback'` sentinels across kiosk components (i18n fallback discipline)
- **Files** (sample, full grep available) :
  - `KioskCategoriesComponent.vue:377` — `selectedCategory?.name \|\| 'Menu'`
  - `KioskCategoriesComponent.vue:728-740` — badge labels `\|\| 'Coup de cœur'`, `\|\| 'Nouveau'`, `\|\| 'Halal'`, `\|\| 'Végétarien'`, `\|\| 'Piquant'`
  - `KioskCartComponent.vue:216` — `aria-label="$t('kiosk.remove_item') \|\| 'Supprimer cet article'"`
  - `KioskCartComponent.vue:544` — toast `\${item?.name \|\| 'Article'} supprimé`
  - `KioskInactivityOverlayComponent.vue:25,28,38,46` — title/desc/stay/leave fallbacks
  - `KioskPromoCarouselComponent.vue:6,21,31` — aria/badge/code fallbacks
  - `ds/KsChip.vue:29`, `ds/KsCartBottomSheet.vue:143,146,155,158,161,164` — DS-layer FR fallbacks
  - `steps/KioskStepFritesStyleComponent.vue:94,97,101` — frites step FR fallbacks
  - `steps/KioskStepGenericChoicesComponent.vue:60` — generic step FR fallback
  - Total: `grep -rn "\|\| '[A-ZÀ-Ü]" resources/js/components/frontend/kiosk/ \| wc -l` = **34**
- **Scenario** : Vue-i18n `MissingHandler` will hit these fallbacks if a race condition during locale boot returns falsy on the key, or if any new key isn't shipped to all 3 locales. Cross-ref agent-6 P1-FE-04 — confirmed.
- **Severity** : P1 (regression risk only — kiosk runtime is FR-immutable today, so end-customer impact is zero in V1. Future-defense matters when post-V1 locale pilot ships).
- **Fix sketch** : Script-based — `rg -l "\|\| '[A-ZÀ-Ü]" resources/js/components/frontend/kiosk/` → for each match, ensure the key exists in fr/en/ar JSON, then delete the `||` tail. Land vue-i18n `missingHandler` enforcement in tests (currently warn-only). 1-2 days.

#### P1-S1-04 — `PricingPreviewController::preview` skips explicit `tokenCan('kiosk:order')` check
- **File** : `app/Http/Controllers/Frontend/PricingPreviewController.php:33-37`
- **Scenario** : Route `POST /api/frontend/pricing/preview` is gated by `auth:sanctum` (`routes/api.php:1245`). Controller relies on `$request->user()->id` + a `KioskMachine::where('user_id', ...)->first()` lookup that returns 503 `KIOSK_MACHINE_NOT_FOUND` if the caller is a non-kiosk Sanctum holder. Compare with `MenuController::kiosk:37`, `UpsellController:32`, `LoyaltyController:579` which DO check `tokenCan('kiosk:order')` explicitly and return 403. The current path is *safe* (any non-kiosk caller silently 503s, no SSOT leak) but breaks the defense-in-depth parity with the rest of the kiosk-namespace controllers.
- **Severity** : P1 (consistency / future-proof). Auth is not bypassed — `OrderRequest::authorize()` is the deep gate per agent-1; this is a *parallel* gate that 3 of 4 kiosk endpoints implement and 1 doesn't.
- **Fix sketch** : Add `if (!$user || !$user->tokenCan('kiosk:order')) return 403(...)` at line 36 of `PricingPreviewController.php`. 3-line patch + 1 test case mirroring `KioskFrontendComprehensiveTest`. ~30 min.

#### P1-S1-05 — `theme toggle aria-label` and 3 backend kiosk-error messages are hardcoded FR
- **Files** :
  - `KioskAppComponent.vue:300-304` — `themeToggleAriaLabel` returns literal `'Passer en mode clair'` / `'Passer en mode sombre'` (no `$t`)
  - `MenuController.php:41,52,61,85` — `'Accès kiosk requis.'`, `'Borne non associée.'`, `'Branche introuvable.'`, `'Erreur serveur.'`
  - `PricingPreviewController.php:42,71,78` — same FR-only error envelope
- **Scenario** : Screen reader users on AR/EN locale pilot will hear FR for theme toggle; backend 503/500 envelopes are surfaced raw in the SPA error overlay (`KioskAppComponent.vue:42-49`, `KioskErrorNetworkComponent.vue`, etc.) which currently renders `branchError` as-is.
- **Severity** : P1 — a11y + i18n contract gap.
- **Fix sketch** : Wrap with `$t('kiosk.theme.toggle_to_light'/'toggle_to_dark')` for the component, route to `trans('all.kiosk.error.*')` keys for controllers (already pattern used by `KioskMachineLoginController`).

### P2 (polish / inconsistency)

#### P2-S1-06 — `KioskAppComponent.vue:1010, 1025, 1059` direct axios to `/frontend/kiosk-event` from app shell
- **File** : `KioskAppComponent.vue:1010-1031`
- **Scenario** : Hardware healthcheck + boot info report directly via axios, bypassing both the `kioskAnalytics` helper and any service abstraction. Mixing 2 transport styles (helper + direct axios in same file) is the kind of drift agent-1 flagged as global. Not a defect — just consistency.
- **Fix sketch** : Add `kioskAnalytics.trackHardware(type, payload)` and route through it.

#### P2-S1-07 — Kiosk theme tokens defined in `KioskAppComponent.vue:1062-1199` instead of `resources/css/kiosk/tokens.css`
- **File** : `KioskAppComponent.vue:1062-1199` (138 LOC of `--kiosk-*` CSS variables inline in scoped block)
- **Scenario** : Tokens are duplicated between this scoped block and `resources/css/kiosk/tokens.css` + `tokens-bold.css` (cited in agent-6 P1-FE-06). Risk of token drift between code paths.
- **Fix sketch** : Strip the inline `--kiosk-*` block, rely on the CSS file already imported globally.

#### P2-S1-08 — Bundle entry never explicitly named in `webpack.mix.js` — kiosk chunks are implicit
- **Files** : `public/js/kiosk-shell.js`, `kiosk-wizard.js`, `kiosk-wizard-step.js`, `kiosk-errors.js` all exist with hashed manifest entries but no `mix.js('resources/js/kiosk-app.js', ...)` declaration. They are emitted as router-level dynamic-import chunks.
- **Severity** : P2 — chunk discipline. Makes the perf budget (P1-S1-02) harder to enforce because CI has no anchor to assert on.
- **Fix sketch** : Add explicit `mix.js('resources/js/kiosk-app.js', 'public/js').vue();` mirroring `pos-app.js`. Lets the CI gate target `public/js/kiosk-app.js` directly.

---

## §4 Integration map (dependencies in / out)

**Inbound (kiosk surface reads from / depends on)** :
- `KioskMachineLoginController` (`app/Http/Controllers/Auth`) — Sanctum token mint
- `MenuController::kiosk` (`app/Http/Controllers/Frontend`) → `KioskMenuService` → `Branch`, `ItemCategory`, `Item`, `ComposerProfile`
- `PricingPreviewController` (`app/Http/Controllers/Frontend`) → `PricingPreviewService` → `PricingService` (SSOT)
- `PromoController::check`, `UpsellController::suggest`, `LoyaltyController::scan/optIn/check/register/config`
- `FrontendOrderController::store/changeStatus/paymentConfirm` (idempotency middleware at route level)
- `PaymentReconcileController::reconcile` (F-008 batch replay)
- `KioskEventController::store` (observability, kiosk:order ability + 30/min throttle)
- Pusher channels via `onEvents(branchId, [...])` from `resources/js/services/eventContract.js` (cf. agent-1 strength #5)
- Vuex stores: `kioskCart`, `kioskMenu`, `kioskSettings`, `kioskMachine`, `kioskFilter`, `kioskSetup`, `globalState`
- Helpers: `kioskFormatPrice`, `kioskCategoryOrder` (wired via `kioskMenu.js:12`), `kioskOfflineQueue`, `kioskHardware`, `kioskAnalytics`, `kioskDisplayText`
- Composables: `useKioskA11y`, `useCatalogChangeNotifier`, `useKioskSpeech`

**Outbound (kiosk surface emits to)** :
- `POST /api/frontend/order` (kiosk order creation, with `composition_snapshot` SSOT)
- `POST /api/frontend/order/quote` (pre-submit pricing)
- `POST /api/frontend/order/{id}/payment-confirm` (TPE amount-echo, F-002)
- `POST /api/frontend/order/change-status/{id}` (TPE-declined void, with whitelisted reason)
- `POST /api/frontend/payment/reconcile-pending` (F-008 boot/cron replay)
- `POST /api/frontend/kiosk-event` and `/kiosk/event` (alias, observability)
- `POST /api/frontend/loyalty/{check,register,opt-in,scan,add-points,redeem}`
- `GET /api/frontend/menu`, `/upsell`, `/loyalty/config`, `/setting`
- Echo channel events consumed (subscriber side only)

**Frozen-zone touch surface (mandatory owner gate before any modification)** :
- `KioskWizardComponent.vue` (3094 LOC, dialog a11y + step routing)
- `KioskAppComponent.vue` (shell + idle/auth/echo/healthcheck/reconcile orchestration)
- `KioskUpsellComponent.vue` (auto-skip timer + analytics + i18n)

---

## §5 Top-3 recommendations

1. **i18n sweep + safety-string promotion (P0-S1-01 + P1-S1-03 + P1-S1-05)** — Single-PR effort, 1-2 days. Replace 34 `\|\| 'FR'` fallbacks + 5 hardcoded safety toasts + theme aria-label + 4 backend FR error envelopes with i18n keys. Add vue-i18n MissingHandler enforcement to CI on PRs touching `resources/js/components/frontend/kiosk/**`. Net: 1 P0 closed, 2 P1 closed, future locale pilot un-blocked, ADR-007 contract fully honoured (FR-immutable by design, not by accident).

2. **Kiosk bundle entry + CI size guard (P1-S1-02 + P2-S1-08)** — 1 day. Add `mix.js('resources/js/kiosk-app.js', 'public/js').vue();` to `webpack.mix.js` mirroring `pos-app.js`; introduce `tools/lint/kiosk_app_size.mjs` with a 350 KB gzip budget; gate CI on it. Lets the 655 KB shell + 370 KB wizard-step regression be caught at PR time instead of post-deploy. Compounding benefit: makes ADR-style perf decisions concrete (e.g. "splitting Loyalty into its own chunk saves N KB").

3. **`PricingPreviewController` kiosk:order parity (P1-S1-04)** — 30 minutes. Mirror the explicit `tokenCan('kiosk:order')` gate from `MenuController:37` / `UpsellController:32` / `LoyaltyController:579`. Defense-in-depth alignment, one test, zero behaviour change for current callers. Closes the only kiosk-namespace controller that diverges from the explicit-ability-check pattern.

---

*End report — S1 KIOSK MAIN AUDITOR / 2026-05-17.*
