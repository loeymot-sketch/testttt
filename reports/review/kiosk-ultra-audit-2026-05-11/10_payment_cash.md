# K10 — Payment + Cash Instruction

> Branch: `feature/mobile-app-le-cayenne-2026-05-10` — HEAD `6a33a9763` (verified `git rev-parse`)
> Mode: read-only audit, frontend payment + cash-instruction surfaces.

## Files audited

- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (1289 lines)
- `resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue` (261 lines)
- `tests/js/KioskPaymentRestyle.spec.js` (122 lines)

Cross-references read (context, NOT scoped):
- `resources/js/router/modules/kioskRoutes.js` (cash-instruction + payment routes)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (idle timer + cart bar exclusion list)
- `resources/js/store/modules/kioskCart.js` (submitOrder action)
- `resources/js/languages/{fr,en,ar,de,bn}.json` (i18n coverage)
- `routes/api.php:1120,1127,1217` (backend endpoints used)
- `app/Http/Controllers/Frontend/OrderController.php:95-145` (amount echo verify)

## Findings

### P0 (blocker pre-merge V1)

#### K10-P0-01 — Payment confirm + reconcile NEVER carries `X-Idempotency-Key` on retries except the inline 3-retry loop

- **File**: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:746-803` + `_reconcilePendingPayments()` lines 839-886
- **Issue**: `confirmBackendPayment()` builds a stable idempotency key (`kiosk-payment-confirm-${orderId}-${transaction_id}`) per the inline retry loop (good). BUT `_reconcilePendingPayments()` at boot replays the entry by POSTing `frontend/payment/reconcile-pending` **without** any `X-Idempotency-Key` header (line 870). The endpoint is in routes/api.php:1127 inside the same `auth:sanctum` group as `payment-confirm` — if `IdempotencyKeyMiddleware` is wired to that prefix the request is 422 and the reconcile silently degrades; if not wired, two simultaneous tabs/borne reboots may double-charge the audit chain.
- **Evidence**: line 870 `await axios.post('frontend/payment/reconcile-pending', { entries });` — no `requestConfig`.
- **Suggested fix**: Pass `X-Idempotency-Key: kiosk-reconcile-${order_id}-${transaction_id}` and confirm middleware coverage on `/api/frontend/payment/reconcile-pending` in K18.

#### K10-P0-02 — TPE timeout reject path skips the `paymentResult` outer `else` so the backend order stays PENDING with no `void` on TPE_TIMEOUT throw

- **File**: `KioskPaymentComponent.vue:528-561`
- **Issue**: When `Promise.race` rejects with `TPE_TIMEOUT` (line 530), control jumps directly to the outer `catch` at line 433. The orphan-order void block at lines 547-559 is INSIDE the `if (!paymentResult.approved)` branch — it only fires when the bridge resolves with `{approved: false}`. On Promise.race timeout there is no `paymentResult` and no void call → PENDING order stays in DB even though `paymentFailureCount` increments and UI surfaces the timeout toast. Branch isolation + Z-report reconciliation would later flag orphan PENDING rows from kiosk timeouts.
- **Evidence**: line 530 `new Promise((_, reject) => setTimeout(() => reject(new Error('TPE_TIMEOUT')), TPE_TIMEOUT_MS))` rejects → goes to outer catch at line 433, never reaches lines 548-559 `axios.post(...change-status, status: CANCELED, reason: 'tpe_timeout')`.
- **Suggested fix**: Wrap `Promise.race` rejection in a try/finally that voids the order with `reason='tpe_timeout'` before re-throwing, OR add explicit void in the outer catch when `err.message === 'TPE_TIMEOUT' && this._lastOrder?.id`.

### P1 (high — V1.0.1 sprint)

#### K10-P1-01 — Back-button during TPE flow is not blocked; only the back-CTA disables on `submitting`

- **File**: `KioskPaymentComponent.vue:5-13` + no `beforeRouteLeave` guard
- **Issue**: The header back button correctly disables when `submitting=true` (line 8). However, **the browser/hardware back-button** is not intercepted via `beforeRouteLeave` or `popstate`. On a typical kiosk Chromium kiosk-mode build no hardware back exists, but on staging/dev or accidental gesture (right-edge swipe on Android-shell), the customer can leave the route mid-TPE and the `_lastOrder` order ID is lost (component unmount nulls `_lastOrder` at line 303). Result: TPE may approve, the `processCardPayment` promise resolves on an unmounted instance, and the auto-redirect to `/waiting/:orderId` is dead. The reconcile localStorage queue saves the entry IF the backend confirm failed, BUT the entry needs `_lastOrder.id` which gets set then nulled — race between the route guard and `beforeUnmount`.
- **Evidence**: line 302-313 `beforeUnmount() { this._lastOrder = null; ... }` AND nothing in router/route definitions blocks navigation when `tpeWaiting=true`.
- **Suggested fix**: Add `beforeRouteLeave(to, from, next) { if (this.submitting || this.tpeWaiting) { next(false); return; } next(); }`. Also `window.addEventListener('beforeunload', preventNavigationGuard)` registered in `processCardPayment` and cleared in finally.

#### K10-P1-02 — Cash-instruction "acknowledged" event has no listener; route renders the component directly so emit is a dead reference

- **File**: `KioskCashInstructionComponent.vue:117-123` + `kioskRoutes.js:243-257`
- **Issue**: The component documents `emits: ['acknowledged']` and emits it via `acknowledge(reason)`. But the router renders the component directly (no parent template listens). The component falls back to `$router.push({name:'kiosk.idle'})` which works for navigation, BUT the analytics expectation (`KIOSK_ANALYTICS_EVENTS.md` cited in the file header — Phase 1.9) of having the parent track `acknowledged({reason: 'user'|'timeout'})` never fires anywhere. Observability is reduced to the `cash_instruction_ack` POST which is best-effort and silently swallowed on failure (line 128 `.catch(() => {})`).
- **Evidence**: line 120 `this.$emit('acknowledged', reason);` and the route has `component: KioskCashInstructionComponent` direct, no wrapper view.
- **Suggested fix**: Either drop the dead emit and document it as internal-only, OR insert a thin wrapper view that listens to `@acknowledged` and pipes to vuex/analytics.

#### K10-P1-03 — Cash instruction route accepts unsigned `total` query param → trust boundary violation

- **File**: `kioskRoutes.js:248-256` + `KioskCashInstructionComponent.vue:25-32`
- **Issue**: `orderTotal` is parsed from `route.query.total` (`parseFloat`). The customer URL is `?number=X&total=22.50&timeout=45`. While the NF525 Pricing SSOT lives backend, the displayed **cash amount** is read from query string with no signature/HMAC. A malicious URL (e.g. shared via someone fiddling with kiosk in store, or test fixture leaking) could show "Montant à régler 0,01€" while the customer pays cash at counter for that wrong amount. POS would reconcile later, but the kiosk-side instruction can mislead a less attentive cashier. F-003 cashier-supervised cash design assumes the screen total = order total in DB.
- **Evidence**: `KioskPaymentComponent.vue:418-423` writes `query: { number, total, timeout }` then `KioskCashInstructionComponent` displays `orderTotal` prop without re-validating against backend.
- **Suggested fix**: Either fetch `GET /api/frontend/order/:id` to display the canonical backend `total` (route the orderId via the query too), or sign the query with a short-lived HMAC matching `quote_token` style.

#### K10-P1-04 — `cancelCardPayment` never cancels `paymentFailureCount` reset → if user cancels mid-second-attempt then retries, ErrorRefused redirect fires too eagerly

- **File**: `KioskPaymentComponent.vue:716-744`
- **Issue**: `cancelCardPayment` resets `tpeWaiting`, `submitting`, sets error/toast and voids the order. But it does NOT reset `this.paymentFailureCount`. After attempt 1 (auto-refused), the customer sees the cancel button, presses it, then tries CB again from the methods grid — `selectMethod` (line 350) resets the counter to 0, so this might be OK. BUT if they keep `method='card'` and just press confirm directly without re-clicking the radio (the method stays selected after error/cancel — line 345 only fires on actual radio click), the counter increments from 1 → 2 on next failure → routes to `kiosk.error.payment-refused`. Whether this is desired UX depends on the spec (line 270-275 says "let user retry once").
- **Evidence**: line 350 only fires in `selectMethod` flow, not on direct re-confirm.
- **Suggested fix**: Reset `paymentFailureCount` to 0 in `cancelCardPayment` and document the policy. Alternatively reset on `tpeWaiting → false` only when user is cancelling (not when bridge declined).

#### K10-P1-05 — TTS speech_error has no AR mp3 fallback wired despite comment claim

- **File**: `KioskPaymentComponent.vue:449-456`
- **Issue**: Comment line 453 says "clef i18n pour le fallback AR mp3 statique" — the `{ key: 'kiosk.pay_screen.speech_error' }` is passed to `_kioskSpeech.speak`. There's no audit of whether `useKioskSpeech` actually has an AR mp3 mapping for `kiosk.pay_screen.speech_error`. If absent, AR locale falls through to Web Speech API which on kiosk Chromium often lacks Arabic voice → EAA 2025 a11y gap for AR users.
- **Evidence**: line 454 `{ key: 'kiosk.pay_screen.speech_error' }`. Confirmed key exists in fr/en/ar/de/bn (verified). Speech composable scope is K15.
- **Suggested fix**: Cross-flag to K15 to verify AR mp3 fallback exists for `kiosk.pay_screen.speech_error`. NEEDS INVESTIGATION (composable scope).

### P2 (medium — backlog)

#### K10-P2-01 — Hardcoded French copy `Paiement CB/TR indisponible hors ligne. …` bypasses i18n

- **File**: `KioskPaymentComponent.vue:27`
- **Issue**: The offline alert text is a raw FR string in template, not `$t(...)`. Also `offlinePaymentMessage()` at line 333 returns raw French. FR-lock V1 means V1 is FR-only by policy but this still violates the FR-lock pattern (use keys even if only fr.json is populated) — future EN/AR rollout will silently miss this string.
- **Suggested fix**: Add `kiosk.pay_screen.offline_alert` + `kiosk.pay_screen.offline_message` keys in all 5 locale files.

#### K10-P2-02 — `_reconcileInterval` setInterval(60000) keeps running even on offline payment screen — wastes battery + creates 401 storms on token expiry

- **File**: `KioskPaymentComponent.vue:296-300`
- **Issue**: 60s interval polls `/frontend/payment/reconcile-pending` continuously while the user is on the payment screen. If sanctum kiosk:order token expires mid-session (TTL 480min), the reconcile pollster emits 401s that the global interceptor handles (visible in network.json captures from Wave E). It also runs even when the localStorage queue is empty (line 841 short-circuit is fine, but the timer itself stays armed).
- **Suggested fix**: Only arm the interval when `_readPendingReconcile().length > 0`. Re-arm after `_appendPendingReconcile`.

#### K10-P2-03 — `kiosk-payment-tpe-cancel` button has no `aria-label` distinct from text + no `data-testid` for its `aria-describedby` link to `kiosk-tpe-stuck-help`

- **File**: `KioskPaymentComponent.vue:183-192`
- **Issue**: A11y: the help paragraph has `id="kiosk-tpe-stuck-help"` but the cancel button does NOT use `aria-describedby="kiosk-tpe-stuck-help"`. Screen reader users with motor difficulties cannot easily understand that the help text is associated with the cancel option.
- **Suggested fix**: Add `aria-describedby="kiosk-tpe-stuck-help"` on the cancel button.

#### K10-P2-04 — Amount card has `aria-live="polite"` but is also re-rendered (v-if) so SR may miss the announcement on transition

- **File**: `KioskPaymentComponent.vue:31-39`
- **Issue**: Amount card with `aria-live="polite"` only renders when `!submitting && !submitted && !tpeWaiting`. When the user switches to `tpeWaiting`, the live region disappears entirely — its `aria-live` is meaningless during TPE flow. The user heard the amount once on mount, never again.
- **Suggested fix**: Keep a hidden `aria-live` region with the amount during TPE flow, OR add `aria-live="assertive"` on the tpe overlay title which already has it as `aria-live="polite"` (line 181) but the `tpeMessage` is the action, not the amount.

#### K10-P2-05 — `kiosk-cash-instruction` countdown timer keeps ticking even if user is interacting (no reset on interaction)

- **File**: `KioskCashInstructionComponent.vue:100-110`
- **Issue**: 45s countdown fires `acknowledge('timeout')` regardless of customer behavior. If a customer is reading the help text and writing down the order number, screen redirects mid-read. Not a P0 (instruction is also printable later via Print receipt + ack at POS), but UX gap.
- **Suggested fix**: Reset countdown on `touchstart`/`pointerdown` in the section, OR display a "Tap to reset" affordance. Alternatively allow `autoRedirectSeconds=0` from admin settings.

#### K10-P2-06 — Spec coverage too thin for restyle: no test for the `tpeWaiting` overlay state machine, TPE_TIMEOUT path, or paymentFailureCount escalation

- **File**: `tests/js/KioskPaymentRestyle.spec.js:59-122`
- **Issue**: Spec covers data-testids, a11y roles, basic click/keyboard. NOT covered: `tpeWaiting → declined → paymentFailureCount=1`, `tpeWaiting → timeout → paymentFailureCount=1`, `paymentFailureCount=2 → router.push payment-refused`, cancel button void call, offline disablement of card/tr radios, `confirmBackendPayment` 3-retry + reconcile localStorage write.
- **Suggested fix**: See "Proposed new E2E tests" below — flesh out unit specs.

### P3 (low — nice-to-have)

#### K10-P3-01 — Hard-coded color hex `#FFFFFF/#FFE8DD/#F4501E/#5A5A5A/#0F0F0F/#E5E5E5` in TPE overlay scoped styles bypasses tokens

- **File**: `KioskPaymentComponent.vue:1215-1281`
- **Issue**: Comment line 1211 says "ramené en light mode pour cohérence" — but tokens like `--kiosk-bg`, `--kiosk-primary`, `--kiosk-text` would have done the job. Hardcoded hex breaks dark-mode operator override and design-system audit.
- **Suggested fix**: Replace with tokens. Already done in `.kiosk-tpe-overlay` for the main amount card area but not TPE styles.

#### K10-P3-02 — `🎫` emoji used for TR icon (line 178) is locale-dependent rendering + no aria-hidden

- **File**: `KioskPaymentComponent.vue:178`
- **Issue**: Emoji is wrapped in a `<span>` with no `aria-hidden="true"` while parent `kiosk-tpe-card-anim` is `aria-hidden="true"` — OK on parent but explicit on emoji span would be cleaner. Also emoji rendering varies across Chromium kiosk firmware (some show monochrome glyph instead of full color).
- **Suggested fix**: Add `aria-hidden="true"` on the emoji span and consider an SVG TR icon mirror of the CB SVG above.

#### K10-P3-03 — Comment cites "[AUDIT-52-BUG7]" + "[AUDIT-P0/P1/P2/P48-BUG3]" without consolidated cross-ref in PROJECT_BRAIN.md §6 DECISIONS LOG

- **Issue**: Inline `[AUDIT-...]` comment tags refer to audits that may have been resolved or superseded. No project-level ledger.
- **Suggested fix**: Audit-tag inventory pass in V1.0.1 cleanup.

## Cross-link flags (NOT duplicated, sent to K18 backend audit)

| Tag | K18 owner | Why K10 cares |
|---|---|---|
| **P0-09** CashDrawerService no-lock dual sessions | K18 backend | Cash flow assumes single open session; kiosk doesn't open drawer (B5b) but POS-side dual-session risk affects reconciliation of kiosk-PENDING_COUNTER orders settled at POS |
| **P0-10** RefundWithCounterEntryService split refund | K18 backend | If a customer pays card kiosk-side then asks refund at POS, the split-refund path interacts with kiosk's PENDING orphan recovery |
| **P0-11** SenangPay Gateway class missing | K18 backend | Frontend never references SenangPay directly; verify K17 routes are unused / not user-facing |
| **F-003** cashier-supervised cash design Option A | K18 + memory | Cash flow on kiosk routes to /cash-instruction; F-003 wired as expected (no drawer open client-side, line 693-704) |

## Existing E2E coverage

- `tests/js/KioskPaymentRestyle.spec.js` — testids + a11y + click + keyboard + TPE overlay role only (no state machine)
- `tests/js/kioskPaymentRetryGate.spec.js` — confirmBackendPayment 3-retry behavior + Idempotency-Key (NEEDS INVESTIGATION on exact content)
- `tests/js/kioskPaymentTpeTimeout.spec.js` — TPE_TIMEOUT timeout path (NEEDS INVESTIGATION — does it cover orphan PENDING void? Probably NOT given K10-P0-02 still unmitigated)
- `tests/js/kioskCounterPaymentFlow.spec.js` — counter-payment integration (NEEDS INVESTIGATION on what)
- `tests/js/paymentComponent401Retry.spec.js` — POS-side, not kiosk (file name)
- `tests/js/paymentComponentPropMutation.spec.js` — POS-side prop mutation
- `tests/js/kioskCartOfflinePaymentScope.spec.js` — store-level offline electronic payment block (lines 764-770)
- No spec found for: `_reconcilePendingPayments`, `_appendPendingReconcile` localStorage queue, `_invokeTpe` QA force decline/timeout toggle, hardware bridge stub, `cancelCardPayment` void, paymentFailureCount escalation to error/payment-refused
- `KioskCashInstructionComponent` — NO unit spec found (NEEDS INVESTIGATION via `grep KioskCashInstructionComponent tests/js/`)

## Proposed new E2E tests

### T-K10-01 — TPE_TIMEOUT must void the order before throw

- **Type**: Vitest unit on `KioskPaymentComponent.vue`
- **Steps**:
  1. Mount with stubbed store + stub `_invokeTpe` to never resolve (Promise pending)
  2. Override `KIOSK_HARDWARE.TPE_TIMEOUT_MS = 50`
  3. Stub axios spy on `frontend/order/change-status/:id` POST
  4. Call `confirmPayment()` after `selectMethod('card')`
  5. Wait 80ms
- **Assertions**:
  - `axios.post` called with `change-status/<orderId>`, body `{status: CANCELED, reason: 'tpe_timeout'}`
  - `tpeWaiting === false`, `submitting === false`, `error` contains the timeout i18n key value
- **Fail today**: P0-02 confirmed — current code doesn't void on race timeout.

### T-K10-02 — Browser back during tpeWaiting must be blocked

- **Type**: Vitest unit + Playwright (kiosk smoke)
- **Steps**:
  1. Vitest: mount + set `tpeWaiting=true` + `submitting=true`, call `$router.push({name:'kiosk.cart'})` — expect cancelled
  2. Playwright: complete cart, hit Payer, set `?tpe_force=...` toggle to slow stub, hit browser-back during overlay
- **Assertions**:
  - Route stays `kiosk.payment`
  - Order is NOT voided (or order IS voided + redirect to cart with explicit user action via cancel button only)
- **Fail today**: P1-01 confirmed — no `beforeRouteLeave` guard.

### T-K10-03 — Reconcile pending payments must send Idempotency-Key + skip empty queue

- **Type**: Vitest unit
- **Steps**:
  1. Pre-seed `localStorage.pending_payment_confirms` with 2 fresh entries
  2. Mount component, wait `nextTick`
  3. Spy axios on `frontend/payment/reconcile-pending`
- **Assertions**:
  - Call made with `entries.length === 2`
  - Header `X-Idempotency-Key` set (suggest `kiosk-reconcile-${order_id}`)
  - On success response `[{status:'reconciled'}, {status:'already_paid'}]`, localStorage now empty
  - When called again with empty queue → no axios call
- **Fail today**: P0-01 confirmed — no header set.

### T-K10-04 — paymentFailureCount escalation after 2 declines routes to error page

- **Type**: Vitest unit
- **Steps**:
  1. Mount with stubbed `_invokeTpe` returning `{approved:false, error_code:'declined'}`
  2. `selectMethod('card')` → `confirmPayment()` → wait → fail count = 1, toast shown
  3. `confirmPayment()` again (without re-clicking method) → fail count = 2 → router.push `{name:'kiosk.error.payment-refused', query:{code:'declined', order_id:...}}`
- **Assertions**:
  - `$router.push` called exactly once with the expected payload
  - `paymentFailureCount` reset to 0 before navigation
  - Order is voided with `reason='tpe_declined'`

### T-K10-05 — Cash-instruction screen displays SERVER-side total, not query string

- **Type**: Playwright smoke
- **Steps**:
  1. Inject a payment cash flow, navigate to `/kiosk/cash-instruction?number=42&total=99.99` directly
  2. Compare displayed `Montant à régler` with `GET /api/frontend/order/42` total
- **Assertions**:
  - Displayed amount === backend `total` (or screen refuses to render if no signed token)
- **Fail today**: P1-03 confirmed — current code displays the unsigned query value.

### T-K10-06 — Cash-instruction countdown timer can be paused by interaction (post-fix)

- **Type**: Vitest unit + Playwright
- **Steps**:
  1. Mount with `autoRedirectSeconds=10`
  2. After 5s, trigger `touchstart` on root
  3. Wait 10s more
- **Assertions**:
  - `acknowledged('timeout')` NOT emitted before 15s
  - Countdown reset to original on touch

## Risks & open questions (owner gate)

1. **F-003 cashier-supervised cash design**: K10 confirms client-side flow matches Option A — no drawer open on kiosk (line 693-704). Whether the cash-instruction screen should display ONE amount or also include a tax breakdown is product/owner call.
2. **TPE_TIMEOUT_MS = 120s** (`config/kioskHardware.js:15`) — confirm with hardware integrator if this is the right ceiling. Some banks reject TPE 90s after start.
3. **`kiosk.payment.tpe_timeout_message` vs `kiosk.pay_screen.tpe_timeout`** — two i18n keys for the same concept (line 439 uses the first, line 1421 fr.json defines `pay_screen.tpe_timeout`). Decide canonical key and clean up. NEEDS INVESTIGATION on dead-key inventory.
4. **`beforeRouteLeave` guard on payment** — adding this changes kiosk shell UX globally (back-arrow disable + browser-back block); owner gate to coordinate with K01 routing audit.
5. **Reconcile interval frequency** (60s) — coordinate with K15 hardware/offline + K17 menu cache TTL to ensure no thundering-herd at branch-wide kiosk reboot.
