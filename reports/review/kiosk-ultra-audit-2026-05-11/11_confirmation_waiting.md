# K11 — Confirmation + Waiting

> Branch `feature/mobile-app-le-cayenne-2026-05-10`, HEAD `245e8ab57`. Read-only audit.

## Files audited

- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` — 731 lines
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` — 861 lines
- `resources/js/languages/fr.json` — `kiosk.confirmation.*` block lines 1834–1858; `kiosk.waiting.*` 1862–1864; `kiosk.waiting_ui.*` 1380–1384; `kiosk.waiting_screen.*` 1385–1398; `kiosk.offline_queue.*` 1374–1379
- `resources/js/languages/en.json` — same blocks (1989–2013 confirmation, 2017–2019 waiting, 1536–1554 waiting_ui/screen, 1530–1534 offline_queue)
- `resources/js/languages/ar.json` — same blocks (1819–1843 confirmation, 1847–1849 waiting, 1370–1388 waiting_ui/screen, 1364–1369 offline_queue)
- `tests/js/kioskConfirmationFallback.spec.js` — 84 lines (single it() for print_failed banner)
- Adjacent code read for context: `resources/js/router/modules/kioskRoutes.js:75–230`,
  `resources/js/store/modules/kioskCart.js:334–337, 814–819`,
  `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:410–430`,
  `resources/js/helpers/kioskPrinter.js:250–278`,
  `app/Http/Controllers/Frontend/OrderController.php:71–88`

PHP `lang/<locale>/all.php` files are **out-of-band** for this surface: the Vue
`$t()` SSOT for kiosk text is the `resources/js/languages/*.json` shipped via
vue-i18n. The prompt's path `lang/fr/all.php + …kiosk.confirmation` returns zero
hits because that namespace is JS-side. No legacy `kiosk.wizard.confirmation.*`
nor `csat_*` lingers — see Migration check below.

## i18n migration HEAD status — PASS

| Verification | Result |
|---|---|
| Component refs `kiosk.confirmation.*` exclusively (23 keys) | PASS — `KioskConfirmationComponent.vue` lines 28, 33, 38, 44, 46, 50, 51, 60, 67, 81–84, 88, 102, 103, 111, 122, 126, 131, 135, 136, 349, 354, 394–402 |
| No legacy `kiosk.wizard.confirmation.*` anywhere | PASS — `grep -rn "kiosk\.wizard\.confirmation\|wizard\.confirmation" resources/js/` returns 0 results |
| All 23 keys present in fr/en/ar | PASS — see grep matrix below |
| `print_failed` + `print_failed_hint` present in all 3 locales | PASS — fr:1856–1857, en:2011–2012, ar:1841–1842 |
| No `csat_*` leftover at old broken path (blissful-mclean cross-branch warning) | PASS — `grep -n "csat_" resources/js/languages/*.json` returns 0 hits |
| Secondary keys consumed by confirmation (`kiosk.card`, `kiosk.cash`, `kiosk.subtotal`, `kiosk.loyalty_card`, `kiosk.pay_screen.tr_title`, `label.payment_method`, `message.please_come_again`) | PASS — present in fr/en/ar |
| Waiting keys (`kiosk.waiting_title`, `kiosk.order_ready_title`, `kiosk.waiting_subtitle`, `kiosk.new_order`, `kiosk.auto_redirect`, `kiosk.waiting.ready_visual_fallback`, `kiosk.waiting_ui.*`, `kiosk.waiting_screen.*`, `kiosk.offline_queue.*`) | PASS — present in fr/en/ar |

Per-key matrix (count `"<key>":` in each JSON file). 23/23 PASS:

```
title FR:1+ EN:1+ AR:1+    order_number FR:2 EN:2 AR:2
total_paid 1/1/1           message_kitchen 1/1/1   message_counter 1/1/1
loyalty_points 1/1/1       auto_return 1/1/1       print_button 1/1/1
printing 2/2/2             printed 1/1/1           print_error 2/2/2
new_order 2/2/2            receipt_number 1/1/1    receipt_discount 1/1/1
receipt_total 1/1/1        receipt_loyalty 1/1/1   receipt_thanks 1/1/1
receipt_present 1/1/1      speech_summary 1/1/1    fallback_receipt_title 1/1/1
fallback_receipt_help 1/1/1  print_failed 1/1/1   print_failed_hint 1/1/1
```

Counts >1 mean the literal key string appears in another namespace too (e.g.
`"title"` in many sub-blocks); the explicit `kiosk.confirmation` block hosts a
unique copy in each locale.

## Findings

### P0 (blocker pre-merge V1)

**None.** i18n migration is clean, polling fallback is implemented, cancel
guard via `requireOrderRef` is wired, `print_failed_hint` + `print_failed` are
present in all locales, and the offline-queue branch correctly skips polling
on `offline_<id>` order IDs. The component does not regress any frozen
behaviour cited in memory `project_kiosk_confirmation_i18n_fix.md`.

### P1 (high — V1.0.1 sprint)

- **K11-P1-01: Waiting screen has no aria-live region — ready/cancelled/timeout transitions are silent for screen readers**
  - File: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:2-6`
  - Issue: `<div class="kiosk-waiting" :class="{ ready: isReady, …}">` carries no
    `role`/`aria-live`. The `isReady → true` transition swaps the entire
    sub-tree (preparing → ready) but a blind user receives no AT announcement.
    The audio fallback at line 388 only triggers a visual toast.
    Confirmation does have `role="status" aria-live="polite"` (line 2), so the
    inconsistency is the bug.
  - Evidence: lines 2–6 vs `KioskConfirmationComponent.vue:2`.
  - Suggested fix: add `role="status" aria-live="polite"` on the root, or wrap
    `kiosk-ready-title` + `kiosk-waiting-number` in a `<div role="status"
    aria-live="assertive">` so the screen reader reads "Votre commande est
    prête — Numéro A12". Same for `timedOut` overlay and `networkLost` banner
    (lines 100-104, 108-118) — those need `role="alert"`.

- **K11-P1-02: No ETA shown despite `preparation_time` available on backend**
  - File: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:42, 34`
  - Issue: Waiting screen shows only an indeterminate progress bar (`kiosk-waiting-progress`)
    and a static "Présentez-vous au comptoir lorsque votre numéro est appelé" hint
    (`kiosk.waiting_ui.preparing_hint`). Backend `OrderDetailsResource.php:45`
    already exposes `preparation_time` (minutes integer) and `FrontendOrder.php`
    casts it. Customer has no informed wait expectation, which is a common
    KDS-coupled industry standard (McDonald's, Burger King self-order have
    explicit "≈8 min" hints).
  - Evidence: `_doPoll()` only reads `data.queue_number` and `data.status` —
    line 281–283. `preparation_time` is dropped.
  - Suggested fix: add `prepTimeMinutes` data field; in `_doPoll()` capture
    `data.preparation_time`; show `kiosk.waiting_ui.eta_hint` ("≈{n} min").
    Add the key to FR/EN/AR.

- **K11-P1-03: `loyalty_points` translation reads as broken sentence in FR/AR — no `name` interpolation**
  - File: `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue:59-60`
  - Issue: Template renders `<span class="kiosk-points-name">{{ loyaltyCustomerName }},</span>`
    followed by `<span class="kiosk-points-value">{{ $t('kiosk.confirmation.loyalty_points', { n: pointsEarned }) }}</span>`.
    The FR string is "vous gagnez +{n} points fidélité !". Concatenation yields:
    `"Alice, vous gagnez +12 points fidélité !"` — grammatically OK in FR,
    but the AR string is `"تهانينا! ربحت +{n} نقاط ولاء!"` ("Congratulations!
    You earned +{n} points!") so the concatenation reads:
    `"Alice, تهانينا! ربحت +12 نقاط ولاء!"` — the "Alice," prefix sits in
    French direction (LTR) inside an RTL paragraph. Bidi mirroring is broken.
  - Evidence: lines 56-63 (template) + fr.json:1840 / ar.json:1825.
  - Suggested fix: pass `name` as a vue-i18n placeholder and embed inside the
    translated string: `loyalty_points: "تهانينا {name}! ربحت +{n} نقاط ولاء!"`.
    Drop the separate `<span class="kiosk-points-name">`. Adds `name` slot to
    all 3 locales.

- **K11-P1-04: `cancel_error` displays raw backend message — risk of leaking internal exception text in production**
  - File: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:441`
  - Issue: `const msg = err.response?.data?.message || this.$t('kiosk.waiting_screen.cancel_blocked');`
    Backend `OrderController.php:81-87` returns `['status' => false, 'message' => $exception->getMessage()]`
    on a 422. A schema mismatch or unrelated exception would surface raw English/PHP
    in the UI (e.g. `SQLSTATE[…]…`). Customer-facing kiosk should never show
    backend exception strings.
  - Evidence: line 441 + OrderController.php:84–87.
  - Suggested fix: only show server message when backend explicitly sets a
    whitelisted, customer-safe code (e.g. `code === 'cancel_blocked'`). Default
    to translated `kiosk.waiting_screen.cancel_blocked` for any other 4xx/5xx.

- **K11-P1-05: TIMEOUT_SECONDS hard-coded 900s — no remote config**
  - File: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:155`
  - Issue: A branch with slow kitchen at lunchtime can blow through 15 min,
    triggering a customer-facing "appelez le personnel" overlay even when the
    order is still healthy. Should mirror the `confirmationAutoReturnSeconds`
    pattern (line 158 of confirmation) and pull from
    `window.foodkingConfig.kioskWaitingTimeoutSeconds` (with fallback 900).
  - Evidence: hard literal `900`.
  - Suggested fix: introduce `config('kiosk.waiting_timeout_seconds', 900)`
    via `master.blade.php` like the existing `kioskConfirmationAutoReturnSeconds`.

### P2 (medium — backlog)

- **K11-P2-01: Confirmation auto-prints in real bridge but no visual indicator the print is auto-triggered**
  - File: `KioskConfirmationComponent.vue:338-341`
  - Issue: `if (kioskHardware.isKioskBridge()) this.printReceipt();` fires
    silently. UX: customer sees the print button suddenly switch to ⏳ "Impression…"
    without context. Edge case: race between auto-print done and the 30s timer
    may briefly show printed-success then redirect.
  - Suggested fix: log analytics event `kiosk.receipt.auto_print_triggered`
    and optionally suppress the manual button when bridge present.

- **K11-P2-02: `pointsEarned` rate computed at render — stale if config invalidates mid-session**
  - File: `KioskConfirmationComponent.vue:208-214`
  - Issue: `parseInt(lists?.loyalty_points_per_euro, 10)` is read live every
    render from `globalState`. If the admin changes the rate during a session,
    confirmation reflects new rate but the actual award (server) used the old
    rate. Customer-facing mismatch with the receipt printed.
  - Suggested fix: snapshot rate at `mounted()` (same pattern as items).

- **K11-P2-03: `_doPoll()` interval 15s deviates from CLAUDE.md §7 stated 5s polling fallback**
  - File: `KioskWaitingComponent.vue:153`
  - Issue: Code comment "POLL_INTERVAL_MS = 15000 — Echo provides real-time
    pushes". Acceptable when Echo connects, but if Echo silently fails to
    subscribe (catch at line 245 swallows the error), the worst-case time-to-ready-detection
    is 15s. CLAUDE.md mandates 5s polling fallback. Either (a) lower interval
    when `this._eventSub === null` (Echo down), or (b) update CLAUDE.md.
  - Suggested fix: branch on `_eventSub`: 5s when null, 15s when subscribed.

- **K11-P2-04: `goHome()` does not stop the speech utterance if `_kioskSpeech.stop()` referenced object is gone**
  - File: `KioskConfirmationComponent.vue:441`
  - Issue: `goHome()` calls `this.clearTimer()` + `reset` + `$router.push`. The
    `_kioskSpeech.stop()` cleanup is in `beforeUnmount` (line 362), which is
    triggered by router navigation — OK, but only if `_kioskSpeech` was
    successfully assigned. If `useKioskSpeech` throws at line 348, `_kioskSpeech`
    is undefined and `?.stop()` is a no-op. Fine, but the utterance keeps
    running because no `cancel()` is called on speechSynthesis. The composable
    no-op guard masks this.
  - Suggested fix: call `window.speechSynthesis?.cancel()` in `beforeUnmount`
    even when `_kioskSpeech` is null.

- **K11-P2-05: `kiosk-print-receipt` element is in DOM at all times — flash if `@media print` rule fails**
  - File: `KioskConfirmationComponent.vue:92-137` + style line 664
  - Issue: `.kiosk-receipt-zone { display: none; }` hides it, but if a CSS
    cascade in production overrides, the entire receipt block (items + total)
    leaks above the timer. Low risk (style scoped + `display: none`), but
    `:hidden="!printFailed"` on the element would be more defensive.

- **K11-P2-06: `displayNumber` falls back to `—` em-dash, not localized**
  - File: `KioskConfirmationComponent.vue:220`
  - Issue: `state.kioskCart?.queueNumber || '—'`. The em-dash is fine for
    LTR FR/EN but should be confirmed for AR readability. Cosmetic.

### P3 (low — nice-to-have)

- **K11-P3-01: `kiosk-confirmation-anim` SVG carries `fill="none"` but no `aria-hidden` on the inner `<path>`** — parent has `aria-hidden="true"` at line 4 so it cascades. No fix needed.
- **K11-P3-02: `setTimeout(() => { this.printStatus = null; }, 2000)` (line 416) is not cleared in `beforeUnmount`** — leak risk only if unmount happens between print-done and the 2s reset.
- **K11-P3-03: `playReadySound()` ramps 3 notes but the `setTimeout` close at +1.2s assumes 0.18s × 2 + 0.5s — leaves an extra 130ms idle AudioContext** — harmless.
- **K11-P3-04: `Echo` subscription only resubscribes on branch change, not on Echo reconnect** — relies on Laravel Echo internal reconnect.
- **K11-P3-05: `printReceipt()` doesn't pass `paymentMethod` localized label when snapshot was taken on a now-different locale** — fr.json:1854 already locks label at snapshot time, acceptable.

## Existing E2E coverage

- `tests/js/kioskConfirmationFallback.spec.js` — single it(): `printFailed=true` renders `data-print-failed="true"` + `.kiosk-fallback-receipt-title` text "Votre ticket"
- `tests/js/kioskConfirmationCountdown.spec.js` — 30s countdown + auto-redirect via `foodkingConfig.kioskConfirmationAutoReturnSeconds`
- `tests/js/kioskWaitingAutoReturn.spec.js` — 20s auto-reset after `isReady`
- `tests/js/kioskWaitingAudioFallback.spec.js` — visual fallback toast when AudioContext unavailable
- `tests/js/sentinels/f004KioskCancelReasonSent.spec.js` — confirms `reason: 'customer_request'` body on cancel POST
- `tests/js/sentinels/kioskOfflineIdPrefix.spec.js` — `offline_<id>` skip-polling branch
- `tests/Playwright/kiosk-offline-waiting.spec.js` — offline queued order UI
- `tests/e2e/kiosk-post-payment-auto-return.spec.js` — full payment → waiting → confirmation → idle journey
- `tests/e2e/test-e2e-kiosk-kds-sync-2026-05-11-wave-A.spec.js` — kiosk↔KDS realtime hand-off
- `tests/e2e/iter15-mega-kiosk-roundtrip.spec.js` — round-trip from idle to confirmation
- Gap: no test for `cancel_blocked` 422 message rendering, no test for Echo
  subscription failure → polling-only path, no test for timeout-modal dismiss
  + resume, no test for `print_failed` aria-live announcement, no a11y axe
  scan on waiting screen ready state.

## Proposed new E2E tests

- **T-K11-01: Polling fallback when Echo blocked** — Playwright:
  1. Block `window.Echo` by injecting `window.Echo = undefined` in `addInitScript`.
  2. Navigate to `/kiosk/waiting/<id>` with mock backend serving `status: PREPARING` for 30s then `status: PREPARED`.
  3. Assert: `_eventSub` is `null` (via `evaluate`), `_doPoll()` fires at 15s cadence (network log), `isReady` flips within 16s of backend transition.
  4. Assertion ties to K11-P2-03 — if interval lowers to 5s when Echo absent, raise expectation.

- **T-K11-02: Cancel before kitchen — backend 422 → safe localized fallback**
  - Stub `POST frontend/order/change-status/<id>` to return 500 with body `{message: 'SQLSTATE[42S02]: Base table not found'}`.
  - Click cancel → confirm.
  - Assert `kiosk-cancel-error-msg` text equals `$t('kiosk.waiting_screen.cancel_blocked')` (i.e. SQL error NOT leaked). Currently this **fails** — see K11-P1-04.

- **T-K11-03: aria-live announcement on ready transition**
  - Vitest with `@testing-library/vue` + `aria-live` queries.
  - Trigger `wrapper.setData({ isReady: true })`.
  - Assert: an element with `[role="status"]` or `[aria-live]` matches and contains the queue number + "prête". Currently fails — see K11-P1-01.

- **T-K11-04: print_failed fallback shows order number prominently and announces it**
  - Mount confirmation with `printFailed: true`.
  - Assert `[data-testid="kiosk-confirmation-root"]` has `aria-live="polite"`.
  - Assert `.kiosk-printer-fallback-number` text matches `#A12`.
  - Assert role-status receipt zone wraps it.

- **T-K11-05: ETA hint visible when `preparation_time` returned**
  - Mock `_doPoll()` to return `{preparation_time: 8, status: PREPARING}`.
  - Assert `.kiosk-waiting-hint` includes "≈8 min" or new `kiosk.waiting_ui.eta_hint` text.
  - Currently fails — feature missing per K11-P1-02.

## Risks & open questions

- **[OWNER GATE — K11-P1-02]** Adding ETA hint touches the waiting hint copy. Is
  the FR/EN/AR phrasing finalized? Suggestion: `"Estimation : ≈{n} min"` /
  `"Estimated: ≈{n} min"` / `"تقدير: ≈{n} د"`.
- **[OWNER GATE — K11-P1-05]** Is moving TIMEOUT_SECONDS to remote config in
  scope for V1, or V1.0.1? It is a customer-experience regression risk if
  staff overrides too aggressively.
- **[QUESTION]** `useKioskSpeech` speaks `total: 10,50 €` via `formatPrice` —
  is the TTS engine reading commas/€ in AR correctly? Not testable without a
  bridge. Should the speech_summary skip the currency symbol?
- **[QUESTION]** `routeToConfirmation()` in waiting (line 324) re-navigates if
  payment status flips to PAID mid-wait. Should it also reset the elapsed
  timer to avoid timeout fire on a paid-and-ready order? Edge case — kiosk
  prep_time long, payment confirmation late.
- **[CROSS-BRANCH WARNING]** Memory `project_kiosk_confirmation_i18n_fix.md`
  flagged `csat_*` keys at OLD broken path on branch `blissful-mclean-c915c2`.
  HEAD `245e8ab57` (this branch) has **zero** csat references in any locale.
  PASS, no regression here, but worth re-checking after eventual merge.

## Verdict

**GO with P1 punch list for V1.0.1**. No P0 blocker on the confirmation/waiting
surface. i18n migration is fully clean (all 23 keys including the two new
`print_failed*` present in fr/en/ar at the canonical `kiosk.confirmation.*`
path; no legacy or csat regression). Five P1 issues — primarily a11y on
waiting, missing ETA, RTL loyalty banner, leaked error string, and hard-coded
timeout — should be addressed in V1.0.1.
