# AGENT-6-TESTER — Loyalty System E2E Test Specs
**Date** : 2026-05-10
**Scope** : Mobile app `mobile/` (V0 standalone, no backend)
**Mode** : Read-only audit ; specs for future implementation + current testable subset

---

## §0 — Honesty calibration (load-bearing)

**12 of the 15 scenarios test features that do not exist yet.** This is not a flaw in
the brief — the parent orchestration shows Phases 3-5 (earn / state-machine / QR HMAC /
Wallet / ScreenLoyalty multi-sections / wizard redeem) are still pending.

Evidence from current code:
- `mobile/screens-main.jsx:848-849` — `ScreenLoyalty` uses `const points = 347; const goal = 500;`
  (hardcoded literals, NOT `window.LC.loyalty.account`).
- `mobile/screens-main.jsx:900-957` — rewards / history tabs are **inline hardcoded arrays**,
  not `window.LC.loyalty.rewards` / `.history`.
- `mobile/screens-main.jsx:805-806` (ScreenProfile preview) — hardcoded `347 PTS`, `153 pts`.
- `mobile/api/storage.js` — **zero loyalty helpers** (only auth / cart / onboarding).
- `mobile/screens-modals.jsx:115-145` — `ModalRedeem` is a 2-button confirm, NOT a 3-step wizard.
- No `setInterval` for QR refresh anywhere ; no `window.LC.dev` namespace ; no Apple/Google
  Wallet buttons ; no Barcode toggle ; no source filter ; no opt-out RGPD ; no `plastic_card_linked`
  setter wired to UI.

Each scenario below is tagged:
- **STATUS: testable-now** — runs against today's `mobile/` ; 3 scenarios qualify.
- **STATUS: spec-for-impl** — design contract for Phase 3-5 implementation ; 9 scenarios.
- **STATUS: requires-backend** — needs Phase 6 wiring (real `POST /redeem`, refund) ; 3 scenarios.

The 15 scenarios stand as the **acceptance test contract** the implementation must satisfy.

---

## §1 — Test infrastructure prerequisites

### 1.1 Playwright config — separate mobile harness

Project root `playwright.config.js` (line 12) defaults to `baseURL=http://localhost:8000`
and `globalSetup` (`tests/Playwright/global-setup.js`) boots Laravel + logs in. **This is
wrong for the mobile app**, which runs at `http://127.0.0.1:8081/index.html` and has zero
backend dependency.

**Recommendation** : create `tests/mobile-e2e/playwright.config.js` :

```js
const { defineConfig, devices } = require('@playwright/test');
module.exports = defineConfig({
  testDir: __dirname,
  testMatch: '*.spec.js',
  workers: 1,                     // localStorage shared state per worker
  timeout: 90_000,
  retries: 0,                     // V0 should be deterministic ; retries hide flakiness
  reporter: [['list'], ['json', { outputFile: 'reports/antigravity/mobile-loyalty-latest.json' }]],
  use: {
    baseURL: 'http://127.0.0.1:8081',
    viewport: { width: 390, height: 844 },         // iPhone 13
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'php -S 127.0.0.1:8081 -t mobile/',
    url: 'http://127.0.0.1:8081/index.html',
    reuseExistingServer: true,
    timeout: 30_000,
  },
});
```

Invocation : `npx playwright test --config=tests/mobile-e2e/playwright.config.js`.

**Do NOT** run via the root config — `globalSetup` will attempt a Laravel login that has
nothing to do with this app and will fail or pollute state.

### 1.2 In-browser Babel compilation — readiness probe

`mobile/index.html:71-83` loads React + Babel-standalone and 5 `<script type="text/babel">`
tags. `domcontentloaded` fires before Babel finishes ; tests MUST wait on a screen marker.

```js
async function waitForLoyaltyReady(page) {
  await page.goto('/index.html');
  await page.waitForFunction(() => !!window.LC && !!window.LC.storage, { timeout: 10_000 });
  // Auth bypass so we land on home, not splash
  await page.evaluate(() => {
    window.LC.storage.setAuth({ token: 'test', phone: '+33600000000', user_id: 12345 });
    window.LC.storage.markOnboardingSeen();
  });
  await page.reload();
  await page.waitForSelector('[data-screen-label]', { timeout: 10_000 });
}
```

### 1.3 `window.LC.dev` namespace — to be implemented (spec)

Tests cannot drive the app without this. Add to `mobile/data/loyalty.js` or a new
`mobile/data/dev-helpers.js`. Each helper MUST also persist to `localStorage` so reload survives.

| Helper | Signature | Behavior |
|---|---|---|
| `LC.dev.earnPoints(amount, source)` | `(int, 'order_app'\|'kiosk'\|'pos'\|'manual_add') -> void` | mutates `account.balance += amount`, pushes history entry `{type:'earn', source_surface:source}`, persists, fires `lc:loyalty-changed` CustomEvent |
| `LC.dev.redeemReward(rewardId)` | `(int) -> Promise<{ok, error?}>` | atomic check-and-debit ; rejects if `balance < cost` with `{ok:false, error:'INSUFFICIENT_POINTS'}` |
| `LC.dev.advanceTime(seconds)` | `(int) -> void` | stubs `Date.now` AND clears+restarts the QR refresh `setInterval` so the next tick fires with the new time |
| `LC.dev.seedHistory(n)` | `(int) -> void` | generates `n` chronological earn entries, persists |
| `LC.dev.seedAccount(partial)` | `(Partial<Account>) -> void` | merges into `account`, persists |
| `LC.dev.setConsent(status)` | `('opted_in'\|'opted_out') -> void` | sets RGPD flag, persists, triggers re-render |
| `LC.dev.simulateRefund(orderId)` | `(string) -> void` | reverses the corresponding earn entry, writes a `type:'manual_add'` history entry per backend convention |
| `LC.dev.clearAll()` | `() -> void` | wipes loyalty state in localStorage ; restores defaults from `data/loyalty.js` |

**Storage extensions to `api/storage.js`** (also missing today):
- `setLoyalty(state)` / `getLoyalty(fallback)` — single key `lecayenne.loyalty`
- `setConsent(status)` / `getConsent()` — `lecayenne.loyalty_consent`
- `setQRPreference(type)` / `getQRPreference()` — `lecayenne.qr_preference` ∈ `{'qr','barcode'}`
- `setPlasticCardLinked(bool)` / `isPlasticCardLinked()`

### 1.4 `data-testid` selectors — to be added

Today there are **ZERO** `data-testid` attributes in `mobile/`. The only stable hooks are
`[data-screen-label]` on each top-level screen. Tests built on CSS classes (`.lc-display`,
`.lc-pill--ink`) or text content (`'347 PTS'`) are brittle and will break with any styling
change. Required additions before scenarios are stable :

| Location (file:approx-line) | testid | Rationale |
|---|---|---|
| screens-main.jsx:852 root | `loyalty-screen` | screen root |
| screens-main.jsx:862 QR wrapper | `loyalty-qr` | QR svg container |
| screens-main.jsx:862 QR svg | `loyalty-qr-payload` (attr `data-payload="LECAY-LOYALTY-..."`) | assert payload string |
| screens-main.jsx:866 member num | `loyalty-member-number` | text `#FK-12345 · IKYES B.` |
| screens-main.jsx:876 big number | `loyalty-balance` | int value for math assertions |
| screens-main.jsx:881 progress label | `loyalty-progress-text` | `347 / 500 pts` |
| screens-main.jsx:888 countdown (new) | `loyalty-qr-countdown` | `mm:ss` until refresh |
| screens-main.jsx:893 tab buttons | `loyalty-tab-points` / `loyalty-tab-rewards` / `loyalty-tab-history` | tab switching |
| screens-main.jsx:907 reward row | `reward-row-{rewardId}` | per-reward assertion |
| screens-main.jsx:914 redeem btn | `reward-redeem-btn-{rewardId}` | unlocked CTA |
| screens-main.jsx:930 locked label | `reward-locked-tooltip-{rewardId}` | `-N pts manquants` |
| screens-main.jsx:947 history entry | `history-entry-{historyId}` | per-entry assertion |
| screens-main.jsx:948 source icon | `history-source-icon` (attr `data-source-surface="kiosk\|app\|pos"`) | filter assertion |
| screens-main.jsx:967 link btn | `link-plastic-card-btn` | open modal |
| screens-modals.jsx:7 ModalShell root | `modal` (attr `data-modal-kind="redeem\|link\|gain"`), `role="dialog"`, `aria-modal="true"`, `aria-labelledby` | a11y + targeted assertions |
| screens-modals.jsx:237 Toast | `toast` (attr `data-kind="info\|success\|error"`) | toast assertions |
| (new) Wallet section | `wallet-apple-btn` / `wallet-google-btn` | Phase 4 |
| (new) QR/Barcode toggle | `qr-mode-toggle` | preference persistence |
| (new) Opt-out CTA in Settings | `loyalty-opt-out-btn` | RGPD scenario |
| (new) Source filter chips | `history-filter-{source}` | filter scenario |

**Naming convention** : kebab-case, scoped prefix (`loyalty-`, `reward-`, `history-`, `modal`,
`toast`, `wallet-`). IDs interpolated as `{id}` for repeated rows.

### 1.5 Mock backend strategy

V0 = **pure localStorage**, no msw. Justified because :
- App boots offline (mobile/HOW_TO_RESUME.md §"Aucune connexion réseau").
- Adding msw inflates the prototype's purpose (no `npm install` story).
- `window.LC.dev.*` IS the mock surface ; tests drive state directly.

For **Phase 6 backend wiring** (scenarios 9, 10, network assertions), add msw via CDN
(`<script src="https://unpkg.com/msw/lib/iife.js">`) in a test-only `index.test.html`
variant — keeps production `index.html` clean.

---

## §2 — 15 scenarios in full

Scenarios are **specs** for the implementation to satisfy. Compact table form (one
file per spec, ~80 lines avg, naming `tests/mobile-e2e/loyalty-{NN}-{slug}.spec.js`).

Screenshots saved to `tests/e2e/__screenshots__/mobile-loyalty/{NN}-{slug}.png` (mirrors
existing `test-e2e-borne-A` convention).

---

### Scenario 01 — Earn via order app paid
**Status** : spec-for-impl
**File** : `loyalty-01-earn-order-app.spec.js`

| | |
|---|---|
| Precondition | seed `balance=100, lifetime_earned=100`; no in-flight orders |
| Steps | 1) `waitForLoyaltyReady`. 2) `page.evaluate(() => LC.dev.earnPoints(33, 'order_app'))`. 3) wait for `[data-testid="toast"][data-kind="success"]`. 4) navigate Profile→Loyalty. 5) switch to history tab. |
| DOM assertions | toast text matches `/\+33 pts/`; `[data-testid="loyalty-balance"]` = `"133"`; top history entry `[data-testid="history-entry-*"]:first-child` has `data-source-surface="app"` and amount `"+33"` |
| Visual | `01-earn-toast.png` (toast visible), `01-earn-history.png` (history tab) |
| Network | none (V0) ; Phase 6: assert `POST /api/v1/frontend/loyalty/earn` body `{amount:33, source:'order_app'}` |
| Console | no errors ; one `lc:loyalty-changed` CustomEvent dispatched |
| Failure modes | balance not persisted (reload returns 100) ; history entry source icon missing ; toast missing |

---

### Scenario 02 — Earn via phone at kiosk
**Status** : testable-now (partial — `ModalPointsGain` exists)
**File** : `loyalty-02-earn-kiosk-modal.spec.js`

| | |
|---|---|
| Precondition | authenticated user `first_name='Ikyes'`; balance=100 |
| Steps | 1) `waitForLoyaltyReady`. 2) `page.evaluate(() => { window.dispatchEvent(new CustomEvent('lc:kiosk-earn', { detail: { amount: 25, first_name: 'Ikyes' } })); })` — App must wire this to open `ModalPointsGain`. 3) screenshot modal. 4) click "Voir ma carte" |
| DOM assertions | modal visible `[data-modal-kind="gain"]`; text contains `Bienvenue` AND `Ikyes` AND `+25`; ink button text = `"Voir ma carte"`; after click, `[data-screen-label="14 Loyalty"]` visible |
| Visual | `02-kiosk-gain-modal.png` (confetti + +25) |
| Failure modes | modal opens without first_name interpolation (today's `ModalPointsGain` doesn't accept a name prop — UI gap) ; confetti animation not rendered (CSS regression) |

**Implementation gap detected today** : `ModalPointsGain` (screens-modals.jsx:91) has only
`gain` prop, no `firstName`. UI text `// Bienvenue au club` is a static label, not a personalized greeting.

---

### Scenario 03 — QR refresh after 4min10s
**Status** : spec-for-impl (no interval exists today)
**File** : `loyalty-03-qr-refresh.spec.js`

| | |
|---|---|
| Precondition | on Loyalty screen ; QR TTL=300s per `data/loyalty.js:9` comment |
| Steps | 1) capture `[data-testid="loyalty-qr-payload"]` `data-payload` attr value → `payload1`. 2) capture `[data-testid="loyalty-qr-countdown"]` text → expect format `mm:ss` near `5:00`. 3) `page.evaluate(() => LC.dev.advanceTime(250))`. 4) wait 100ms. 5) capture countdown again → expect ~`0:50`. 6) `LC.dev.advanceTime(60)`. 7) wait for `lc:qr-refreshed` CustomEvent. 8) capture payload2 |
| DOM assertions | `payload1 !== payload2`; both match `/^LECAY-LOYALTY-12345-[A-Za-z0-9+/]{16}$/`; countdown after refresh ≈ `5:00` |
| Visual | `03-qr-before.png`, `03-qr-after.png` (diff payload visible) |
| Console | no `Maximum update depth exceeded` ; exactly 1 refresh event |
| Failure modes | interval not cleared on unmount → React `setState on unmounted` warning ; `Date.now` stub not restored after test (cross-test pollution — use `page.context().clearCookies()` and `LC.dev.clearAll()` in `afterEach`) |

**Critical implementation note for §6 perf** : the refresh MUST update only the QR component,
not the entire `ScreenLoyalty`. Today everything is one function ; the interval will trigger
a full screen re-render unless QR is split into a memoized child with its own state.

---

### Scenario 04 — Redeem unlocked reward (100 pts → frites M)
**Status** : spec-for-impl (current `ModalRedeem` is 2-button, not 3-step wizard)
**File** : `loyalty-04-redeem-unlocked.spec.js`

| | |
|---|---|
| Precondition | seed `balance=347`; rewards tab open |
| Steps | 1) click `[data-testid="reward-redeem-btn-1"]` (Frites M, 100pts). 2) wizard step 1 visible ("Tu reçois Frites M"). 3) click "Suivant". 4) step 2: confirmation summary. 5) click "Confirmer". 6) step 3: success + applied-to-next-order info. 7) click "Voir mes points". |
| DOM assertions | wizard `[data-testid="redeem-wizard"][data-step="1\|2\|3"]`; step counter visible; after confirm: balance = `"247"` (debited 100); top history entry type=redeem amount=-100 reward_id=1 |
| Visual | `04-wizard-step1.png`, `04-wizard-step2.png`, `04-wizard-step3.png`, `04-after-redeem.png` |
| Network | Phase 6: `POST /api/v1/frontend/loyalty/redeem {reward_id:1}` → 200 |
| Console | one `lc:loyalty-changed` ; no errors |
| Failure modes | back button on step 2 mid-confirm causes balance debit anyway ; step 3 success shown but localStorage still has 347 (race condition between UI state and persistence) |

---

### Scenario 05 — Redeem locked reward (1000 pts, balance 347)
**Status** : testable-now (locked state exists in rewards tab today)
**File** : `loyalty-05-redeem-locked.spec.js`

| | |
|---|---|
| Precondition | seed `balance=347` |
| Steps | 1) navigate to Loyalty → rewards tab. 2) locate `[data-testid="reward-row-4"]` (Burger gratuit, 1000pts). 3) attempt click on button. 4) hover (or tap-and-hold on mobile) to surface tooltip. |
| DOM assertions | button has `disabled` attr ; button text = `"Verrouillé"` (screens-main.jsx:933) ; `[data-testid="reward-locked-tooltip-4"]` text = `"−653 pts manquants"` (1000-347) ; cursor CSS = `not-allowed` |
| Visual | `05-reward-locked.png` (gray button visible) |
| Failure modes | button still triggers redeem on click despite disabled (event handler not gated — verify by `page.evaluate(() => document.querySelector('[data-testid="reward-redeem-btn-4"]').click())` and asserting balance unchanged) |

---

### Scenario 06 — Link plastic card
**Status** : testable-now (modal exists, persistence is gap)
**File** : `loyalty-06-link-plastic-card.spec.js`

| | |
|---|---|
| Precondition | `plastic_card_linked=false` |
| Steps | 1) Loyalty screen → click `[data-testid="link-plastic-card-btn"]`. 2) `ModalCardLink` opens. 3) click "Activer l'appareil photo" → simulated scan succeeds (V0: mock returns immediately, Phase 6: webcam API). 4) success toast appears. 5) reload page. 6) re-open Loyalty. |
| DOM assertions | modal `[data-modal-kind="link"]` visible ; toast `data-kind="success"` text matches `/carte plastique liée/i` ; after reload: `localStorage.getItem('lecayenne.loyalty')` JSON contains `plastic_card_linked:true` ; Loyalty screen now shows `[data-testid="loyalty-card-linked-badge"]` |
| Visual | `06-link-modal.png`, `06-link-success-toast.png`, `06-after-reload.png` |
| Failure modes | `plastic_card_linked` not persisted (in-memory only — happens today since `storage.js` has no loyalty key) ; modal scan button has no aria-label |

---

### Scenario 07 — Add Apple Wallet
**Status** : spec-for-impl (no Wallet UI today)
**File** : `loyalty-07-apple-wallet.spec.js`

| | |
|---|---|
| Precondition | iOS Safari emulation `userAgent` containing `Mobile/15E148` |
| Steps | 1) Loyalty screen. 2) click `[data-testid="wallet-apple-btn"]`. 3) instructions modal appears. 4) click "J'ai compris". 5) modal closes, returns to QR view. |
| DOM assertions | button only visible when `navigator.userAgent` matches Apple ; modal `[data-modal-kind="wallet-apple"]` ; modal contains 3 numbered steps ; after close: `[data-screen-label="14 Loyalty"]` visible ; `localStorage` `lecayenne.wallet_apple_dismissed_at` set (so we don't nag) |
| Visual | `07-wallet-apple-button.png`, `07-wallet-apple-modal.png` |
| Failure modes | button visible on Android (UA detection bug) ; Phase 11 only: actual `.pkpass` file download — out of scope V0 |

---

### Scenario 08 — Add Google Wallet
**Status** : spec-for-impl (no Wallet UI today)
**File** : `loyalty-08-google-wallet.spec.js`

Same shape as 07 with `userAgent` = Pixel device, testid `wallet-google-btn`,
modal-kind `wallet-google`. Failure modes : button visible on iOS (inverse of 07).

---

### Scenario 09 — Race condition: two concurrent redeems
**Status** : requires-backend (V0 has no async redeem)
**File** : `loyalty-09-redeem-race.spec.js`

| | |
|---|---|
| Precondition | balance=200, redeem cost=100 (Frites), but the IMPL must use atomic compare-and-swap |
| Steps | 1) `await page.evaluate(async () => { const r = await Promise.allSettled([ LC.dev.redeemReward(1), LC.dev.redeemReward(1) ]); window.__raceResult = r; });`. 2) read `window.__raceResult`. |
| DOM assertions | exactly 1 promise resolves `{ok:true}`, 1 rejects `{ok:false, error:'INSUFFICIENT_POINTS'}`; final balance = `100` (not 0, not -100, not 200) ; toast `data-kind="error"` visible with text `/points insuffisants/i` ; exactly 1 history entry of type redeem written |
| Network | Phase 6 backend assertions : 2 POST requests, 1 returns 200, 1 returns 409 Conflict per IDEMPOTENCY contract |
| Failure modes | both succeed (no lock — balance goes negative) ; both fail (over-rejection — false 409) ; ghost history entries (2 writes for 1 successful redeem) |

**Critical** : this scenario is the BUSINESS-CRITICAL test. Today the V0 `ModalRedeem`
just sets a toast (`index.html:183`) with no actual state mutation — the bug surface
won't exist until redemption truly mutates state.

---

### Scenario 10 — Refund (order cancelled → reversal entry)
**Status** : requires-backend
**File** : `loyalty-10-refund-reversal.spec.js`

| | |
|---|---|
| Precondition | seed earn entry `id=9999, amount:+33, order_id:'C-9999', source:'app'`; balance=380 (347+33) |
| Steps | 1) `LC.dev.simulateRefund('C-9999')`. 2) navigate Loyalty → history. |
| DOM assertions | balance = `"347"` (reversed) ; new top history entry has `type='manual_add'` (per backend convention — NOT `'reverse'`) , amount `"−33"`, `reason='Remboursement C-9999'`, `linked_to_history_id=9999` ; original entry 9999 still present (NOT deleted — append-only) |
| Failure modes | refund deletes original entry (violates append-only audit) ; refund creates `type='redeem'` (wrong) ; balance under-reverses (33 should be exact) |

---

### Scenario 11 — Opt-out RGPD
**Status** : spec-for-impl
**File** : `loyalty-11-opt-out.spec.js`

| | |
|---|---|
| Precondition | balance=347, opted-in |
| Steps | 1) Profile → Paramètres → Programme fidélité. 2) click `[data-testid="loyalty-opt-out-btn"]`. 3) confirmation modal appears with destructive warning. 4) click "Confirmer désactivation". 5) navigate back to Loyalty screen. |
| DOM assertions | modal `[data-modal-kind="opt-out-confirm"]` text contains `/Tes points ne seront plus crédités/i` ; after confirm: `localStorage` `lecayenne.loyalty_consent='opted_out'` ; Loyalty screen NOT shown QR card ; shows `[data-testid="loyalty-disabled-state"]` with reactivation CTA ; balance preserved in DB (per RGPD : data retention != active accrual) |
| Visual | `11-opt-out-modal.png`, `11-opt-out-state.png` |
| Failure modes | balance deleted on opt-out (violates 6y NF525 retention) ; reactivation button missing |

---

### Scenario 12 — History pagination (100 entries)
**Status** : spec-for-impl (no scroll/pagination today)
**File** : `loyalty-12-history-pagination.spec.js`

| | |
|---|---|
| Precondition | `LC.dev.seedHistory(100)` |
| Steps | 1) navigate Loyalty → history tab. 2) initial render — assert first 20 entries. 3) scroll to bottom via `page.locator('[data-testid="history-list"]').evaluate(el => el.scrollTop = el.scrollHeight)`. 4) await sentinel `[data-testid="history-load-more"]` invisible. 5) repeat scroll until entry 100 visible. |
| DOM assertions | `[data-testid^="history-entry-"]` count grows from 20 → 40 → ... → 100 ; no duplicate IDs ; loader pulse visible during fetch (V0: setTimeout 200ms simulated) |
| Visual | `12-history-initial.png`, `12-history-entry-100-visible.png` |
| Performance | scroll-to-100 < 3s ; no main-thread block > 50ms (DevTools profiler trace) |
| Failure modes | infinite scroll triggers full screen re-render each batch (perf) ; entry IDs collide ; loader not removed after last batch |

---

### Scenario 13 — Filter history by source
**Status** : spec-for-impl
**File** : `loyalty-13-history-filter.spec.js`

| | |
|---|---|
| Precondition | seed mixed history : 5 source=app, 5 source=kiosk, 3 source=pos |
| Steps | 1) Loyalty → history tab. 2) click `[data-testid="history-filter-kiosk"]`. 3) assert visible entries. 4) click `[data-testid="history-filter-all"]` to reset. |
| DOM assertions | after kiosk filter: only 5 `history-entry` rows visible ; each has `[data-testid="history-source-icon"][data-source-surface="kiosk"]` ; filter chip `data-active="true"` ; after reset: 13 rows visible |
| Visual | `13-filter-kiosk.png` |
| Failure modes | filter accumulates (kiosk + pos both active when toggling) ; counts mismatch |

---

### Scenario 14 — Toggle QR ↔ Barcode, persist across reload
**Status** : spec-for-impl
**File** : `loyalty-14-qr-barcode-toggle.spec.js`

| | |
|---|---|
| Precondition | default = QR |
| Steps | 1) Loyalty screen → click `[data-testid="qr-mode-toggle"]`. 2) assert barcode visible. 3) reload page. 4) re-open Loyalty. |
| DOM assertions | initial: `[data-testid="loyalty-qr"]` visible, `loyalty-barcode` hidden ; after toggle: inverse ; `localStorage.getItem('lecayenne.qr_preference')` = `"barcode"` ; after reload: barcode still visible (preference survives) |
| Visual | `14-qr-mode.png`, `14-barcode-mode.png` |
| Failure modes | toggle does not persist (in-memory only) ; both visible simultaneously |

---

### Scenario 15 — Empty state: new user 0 pts
**Status** : spec-for-impl
**File** : `loyalty-15-empty-state.spec.js`

| | |
|---|---|
| Precondition | `LC.dev.clearAll()` then `LC.dev.seedAccount({ balance: 0, lifetime_earned: 0, lifetime_redeemed: 0 })` |
| Steps | 1) navigate Loyalty. 2) check history tab. |
| DOM assertions | `[data-testid="loyalty-balance"]` = `"0"` ; QR card still rendered (member exists, just no points) ; progress bar at 0% ; history tab shows `[data-testid="loyalty-history-empty"]` with friendly text `/Aucune transaction/i` and CTA `[data-testid="cta-first-order"]` ; clicking CTA navigates to `[data-screen-label="2 Menu"]` |
| Visual | `15-empty-balance.png`, `15-empty-history.png` |
| Failure modes | progress bar NaN% (division by zero — `data/loyalty.js:39` does `347/500` ; if balance=0 and goal=0, NaN) ; CTA text reads as raw key `loyalty.empty.cta` (i18n unresolved) |

---

## §3 — Coverage matrix

| Feature \ Scenario | 01 | 02 | 03 | 04 | 05 | 06 | 07 | 08 | 09 | 10 | 11 | 12 | 13 | 14 | 15 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Earn (app) | X | | | | | | | | | | | | | | |
| Earn (kiosk) | | X | | | | | | | | | | | | | |
| Earn (POS) | | | | | | | | | | X | | | X | | |
| QR refresh / TTL | | | X | | | | | | | | | | | | |
| QR payload format | | | X | | | | | | | | | | | | |
| Redeem unlocked | | | | X | | | | | | | | | | | |
| Redeem locked / disabled | | | | | X | | | | | | | | | | |
| Redeem race-condition | | | | | | | | | X | | | | | | |
| Refund / reversal | | | | | | | | | | X | | | | | |
| Plastic card link | | | | | | X | | | | | | | | | |
| Apple Wallet | | | | | | | X | | | | | | | | |
| Google Wallet | | | | | | | | X | | | | | | | |
| Opt-out RGPD | | | | | | | | | | | X | | | | |
| History pagination | | | | | | | | | | | | X | | | |
| History filter | | | | | | | | | | | | | X | | |
| QR/Barcode preference | | | | | | | | | | | | | | X | |
| Empty state | | | | | | | | | | | | | | | X |
| Toast surface | X | X | | X | | X | | | X | | X | | | | |
| Modal a11y | | X | | X | | X | X | X | | | X | | | | |
| Persistence (reload) | X | | | X | | X | | | | | X | | | X | X |

**Uncovered surface** (no scenario today — flag for future) :
- **Cross-tab sync** : two browser tabs open on Loyalty ; redeem in tab A → tab B updates via `BroadcastChannel` or `storage` event.
- **Branch-scoped points** : Phase 6 — if user redeems at branch X, does balance debit globally or per-branch ? Backend behavior NOT defined in `data/loyalty.js`.
- **Token expiry** during long-running session (Sanctum 480min) — UI must refresh transparently.
- **Welcome bonus** (`config.welcome_bonus: 25` in loyalty.js:19) — first login flow ; tangentially covered by S02 but not as first-time path.
- **Expiry of points** (`expires_after_days: 365`) — point pruning cron, not testable client-side.
- **Reward variation_id / payload usage** — currently only `points_cost` matters in UI ; redeem must respect `type:'free_item' | 'discount' | 'percent_discount'` when applied to cart.
- **i18n EN/AR fallback** — `lang/en/all.php` + `lang/ar/all.php` ; mobile is currently FR-hardcoded.

---

## §4 — Adversarial test ideas (beyond the 15)

### A1 — Clipboard QR copy and replay
| | |
|---|---|
| Threat | user screenshots QR, shares via WhatsApp ; or copies payload to clipboard and another phone uses it |
| Test | 1) capture `data-payload` value. 2) Wait 6 minutes (`LC.dev.advanceTime(360)`). 3) Backend simulated assertion : POST `/api/v1/frontend/loyalty/use-qr` with stale payload → expect 401 `EXPIRED_QR`. 4) Same payload reused TWICE within TTL → expect 409 `QR_ALREADY_CONSUMED` (idempotency per token). |
| Expected | backend MUST reject expired AND single-use violation ; UI must show informative error toast |
| Visual | `adv-A1-expired-qr-toast.png` |

### A2 — iOS screenshot detection (Phase 11 native only)
| | |
|---|---|
| Threat | Capacitor `App.addListener('screenshotTaken')` (iOS 14+) — not available in PWA. Pure mobile-web V0 can't detect this. |
| Test | mark this scenario as **NOT TESTABLE IN V0** ; add to Phase 11 native acceptance criteria |
| Mitigation in V0 | rely on short QR TTL (5min) + single-use semantics (A1) ; visual watermark `userId` overlay on QR so screenshot becomes attributable |

### A3 — localStorage tampering (devtools attack)
| | |
|---|---|
| Threat | user opens devtools, runs `localStorage.setItem('lecayenne.loyalty', JSON.stringify({balance: 99999}))`, reloads, claims free burger at kiosk |
| Test | 1) tamper as above. 2) reload Loyalty screen. 3) attempt to redeem 1000pt reward (which would fail with 347 real balance). 4) Phase 6 backend response. |
| Expected | UI shows balance 99999 (V0 trusts localStorage — that's the bug class) ; Phase 6 backend MUST be the source of truth — UI balance is hint, redeem validates server-side ; toast `BALANCE_MISMATCH` warns user |
| Failure mode | V0 silently trusts tampered balance → motivates Phase 6 with hash-checked balance from backend |

### A4 — Rapid double-tap redeem (mobile thumb)
| | |
|---|---|
| Threat | user taps "Confirmer" button twice within 200ms ; if not debounced, two POST requests fire ; race condition (S09) saves the day server-side but UI shows double-debit toast |
| Test | `await Promise.all([ page.locator('[data-testid="redeem-confirm-btn"]').click(), page.locator('[data-testid="redeem-confirm-btn"]').click() ]);` (or `dblclick` with 50ms delay) |
| Expected | button has `disabled` set on first click ; second click is no-op ; exactly 1 toast ; balance debited once |
| Failure mode | "double point loss" visible to user even if server-side is correct (S09 covers backend, A4 covers client-side UX) |

### A5 — Browser back during wizard step 2
| | |
|---|---|
| Threat | redeem wizard step 2 (confirm screen) ; user hits browser back ; React app uses `history.pushState` so back navigates within app, but balance/network state may be half-mutated |
| Test | 1) reach step 2 of S04. 2) `await page.goBack()`. 3) verify state. |
| Expected | back from step 2 → step 1, no debit ; back from step 3 → blocked or returns to history tab with completed entry visible (not back into pre-debit state) |
| Failure mode | back from step 3 re-shows step 2 → user can confirm again → double redeem ; or back during pending network call leaves orphaned state |

---

## §5 — Visual diff strategy

### 5.1 Reference
- **Kiosk parity reference** : `mobile/screens-main.jsx:861-871` (QR card) and `mobile/screens-modals.jsx:91-112` (ModalPointsGain) are designed to align with kiosk yellow/orange/ink palette. No actual kiosk loyalty screen exists today to diff against — the parity is **palette parity**, not pixel parity.
- **Within-mobile regression** : establish a **golden baseline** captured on first run (Playwright `toHaveScreenshot()` with `{ maxDiffPixelRatio: 0.02 }`).

### 5.2 Region masks (required to prevent flake)
| Region | Reason |
|---|---|
| `[data-testid="loyalty-qr"]` | payload changes every 5min (S03) ; mask entirely or assert payload via DOM attr, not pixels |
| `[data-testid="loyalty-qr-countdown"]` | mm:ss text ; mask or assert via DOM |
| `lc-confetti` animation (`screens-modals.jsx:99`) | random position per render ; mask in S02 |
| `Date.now()` derived elements (history dates `"8 mai"`) | seed `LC.dev.seedHistory` with fixed-date entries |

### 5.3 Tolerance
- `maxDiffPixelRatio: 0.02` for layout-stable regions.
- `maxDiffPixelRatio: 0.001` (strict) for branding-critical regions (logo, palette swatches).
- iPhone 13 DPR=2 — capture at `deviceScaleFactor: 2` to match real devices ; CI runs same DPR (avoid Linux DPI mismatch).

### 5.4 False-positive triage
| Symptom | Likely cause | Action |
|---|---|---|
| Single-pixel offset across all regions | font hinting differs (Inter web-font load timing) | `await page.evaluate(() => document.fonts.ready)` before screenshot |
| Diff in toast/modal animations | timing capture mid-animation | `await page.waitForTimeout(400)` after open (animation duration is 0.3s per `screens-modals.jsx:237` slide-down) |
| Confetti positions differ | `Math.random` not seeded | mock `Math.random` in dev mode to deterministic Lehmer LCG (add to `LC.dev.seedRandom`) |
| Color drift on `var(--yellow)` | OS dark-mode override / Chrome rendering changes | force `colorScheme: 'light'` in Playwright context |

---

## §6 — Performance assertions

### 6.1 What's required (spec-for-impl)
The current `ScreenLoyalty` (screens-main.jsx:846) is a single function with `useState` for
the tab. The QR refresh interval doesn't exist yet. When implemented, the refresh MUST NOT
re-render the whole screen — only the QR component — otherwise scrolling the tabs and reading
history will jank every 5 minutes.

### 6.2 Implementation contract
- Extract `<LoyaltyQR>` as a memoized child with its OWN state for payload + countdown.
- Use `React.memo(LoyaltyQR)` with `areEqual` returning `true` always (QR re-renders only on its own interval, parent re-render is ignored).
- The setInterval lives inside `LoyaltyQR`'s `useEffect` ; cleanup on unmount.

### 6.3 Concrete measurement
```js
test('QR refresh does not re-render parent screen', async ({ page }) => {
  await waitForLoyaltyReady(page);
  await page.goto('/index.html#/loyalty');
  await page.evaluate(() => {
    window.__renderCounts = { ScreenLoyalty: 0, LoyaltyQR: 0 };
    // The implementation MUST expose: window.LC.dev.onRender(componentName, callback)
    window.LC.dev.onRender('ScreenLoyalty', () => window.__renderCounts.ScreenLoyalty++);
    window.LC.dev.onRender('LoyaltyQR', () => window.__renderCounts.LoyaltyQR++);
  });
  const before = await page.evaluate(() => window.__renderCounts);
  // Trigger 3 QR refreshes
  for (let i = 0; i < 3; i++) {
    await page.evaluate(() => LC.dev.advanceTime(300));
    await page.waitForTimeout(100);
  }
  const after = await page.evaluate(() => window.__renderCounts);
  // LoyaltyQR re-rendered 3 times ; ScreenLoyalty unchanged
  expect(after.LoyaltyQR - before.LoyaltyQR).toBe(3);
  expect(after.ScreenLoyalty - before.ScreenLoyalty).toBe(0);
});
```

### 6.4 Additional perf SLOs
| Surface | Metric | Target |
|---|---|---|
| Loyalty screen first paint (cold) | LCP | < 1500ms (DOM mostly static, no images) |
| Tab switch (points ↔ rewards ↔ history) | INP | < 100ms |
| Scroll history to entry 100 | jank frames | < 5% (P95 frame > 16ms) |
| Modal open animation | duration | exactly 300ms (per CSS animation) — assert via `transitionend` event |
| QR refresh tick | main-thread block | < 30ms (CPU profiling) |

### 6.5 Caveats
- React DevTools Profiler API is not directly exposed to Playwright. The render counter
  helper (`LC.dev.onRender`) must be added to the implementation as a hook into
  `React.useEffect` with empty deps + ref counter ; or use the `Profiler` component wrapper
  in dev builds.

---

## §7 — Skill `test-e2e` invocation recommendation

### 7.1 Recommended invocation
```
/test-e2e scope=mobile/loyalty config=tests/mobile-e2e/playwright.config.js base=http://127.0.0.1:8081 specs=loyalty-{01..15}-*.spec.js parallel=false adversarial=true visual=true
```

The `test-e2e` skill (per project CLAUDE.md §4) spawns a GStack main team + an adversarial
supervisor. For loyalty, recommend :
- **GStack scope** : Architect (data flow), Tester (the 15 specs), A11y (modals), Security (S09, S10, A1, A3).
- **Adversarial supervisor focus** : A1-A5 from §4 + race conditions + tampering.

### 7.2 Risks specific to this session
| Risk | Mitigation |
|---|---|
| **No Chrome MCP loaded in current session** — confirmed by absence of `mcp__chrome__*` tools | run as deferred `playwright test` via Bash, not Chrome MCP browse ; specs are file-runnable |
| **Skill may try to use `127.0.0.1:8000`** (root playwright.config baseURL) | explicitly pass `--config=tests/mobile-e2e/playwright.config.js` and `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8081` |
| **`globalSetup` boots Laravel + logs in** | new mobile config must NOT set `globalSetup` |
| **Implementation is incomplete** — 12 of 15 specs are pre-implementation contracts | run only S02, S05, S06 as "testable-now" baseline ; mark S01,03,04,07-15 as `test.skip(impl-pending)` until Phase 3-5 lands |
| **Babel-standalone slow boot on CI** | bump initial wait from 5s to 10s ; add explicit `await page.waitForFunction(() => window.LC && window.LC.storage)` |
| **localStorage persisted across tests** | `test.beforeEach` MUST call `await page.evaluate(() => { localStorage.clear(); })` + reload |
| **No CI integration yet** | add npm script `test:mobile-e2e` → `playwright test --config=tests/mobile-e2e/playwright.config.js` |

### 7.3 Sequencing recommendation
1. **Phase 0** (this report) — accept specs as acceptance criteria.
2. **Phase 1** — implement `window.LC.dev.*` + storage helpers + `data-testid` attributes (§1.3, §1.4) **first**, BEFORE specs are written.
3. **Phase 2** — run testable-now subset (S02, S05, S06) as smoke baseline.
4. **Phase 3-5** (per orchestrator plan) — implement features, unmark `test.skip` per scenario as features land.
5. **Phase 6** — wire backend, enable S01, S04 network assertions + S09 race + S10 refund.
6. **Phase 7** — adversarial cycle via `test-e2e` skill with the 5 adv scenarios.

### 7.4 Deliverable
A loyalty-feature is **green** when : 15/15 specs pass green (no skip) + 5/5 adversarial pass +
zero visual regressions vs golden baseline + perf SLOs (§6.4) met. The honesty calibration of
§0 is the load-bearing claim: do NOT mark loyalty done while specs are skipped.

---

**Verdict** : test design is complete. Implementation prerequisites (`LC.dev.*` helpers +
22 testids) must land before any spec can run reliably. S02, S05, S06 provide a smoke baseline today.
