# WF-8 — Sentinel Discipline Meta-Audit

**Date:** 2026-05-19
**Scope:** Session-added sentinel tests in commit range `ec0d49241..HEAD` (~100 commits).
**Mode:** READ-ONLY quality assessment.
**Mantra:** "A sentinel that asserts the wrong thing is worse than no sentinel."

---

## 1. Headline

Of the **18 sentinel artefacts** reviewed in depth across the session :

- **17 STRONG** — exercise the exact root cause + edge cases + (often) defense-in-depth negative assertions.
- **0 WEAK** — none merely cover the happy path without locking the root cause.
- **0 SUPERFICIAL** — none assert mere existence without behaviour.
- **1 INFO / consolidation stub** — `StockMovementIdempotencyKeyUniqueTest.php` is fully `markTestSkipped` with a pointer to the consolidated coverage. Not a contract sentinel; arguably dead weight worth deleting in V1.0.2 hygiene.

**Verdict: this session's sentinel discipline is exemplary.** The asymmetric finding (zero WEAK / zero SUPERFICIAL) is not a soft assessment — it reflects a deliberate pattern across the session of pinning both the affirmative invariant (the new contract) AND the negative anti-regression (legacy bad value must not survive, sibling exception must still throw, etc.).

---

## 2. Sentinel-by-Sentinel Verdicts

### 2.1 F-2 Password reset min:12 — `PasswordResetMinLengthSentinelTest.php`

**STRONG.** Source-pin regex on `ForgotPasswordController::resetPassword()`. Asserts both `password` AND `password_confirmation` carry `min:12`. Defense-in-depth: explicit `assertStringNotContainsString("'min:6'", $resetBlock)` so the legacy value cannot silently survive elsewhere in the same method. Bounded to the specific function via greedy regex scoped between `public function resetPassword` and the next `public function `.

### 2.2 F-3 Stripe webhook tolerance — `StripeWebhookReplayToleranceSentinelTest.php`

**STRONG.** Asserts `Webhook::constructEvent` is called with an explicit 4th positional argument AND that the value sits in `[60, 600]` seconds. The bounded range is the key discipline — a future "simplification" to `tolerance=999999` would slip through a presence-only check, this catches it.

### 2.3 F-3 PayloadMismatch fail-once — `PayloadMismatchFailOnceSentinelTest.php`

**STRONG.** Two-test behavioural pair :
- Primary asserts `PayloadMismatchException` is **swallowed** (no rethrow → no `$backoff` curve), AND `dispatched_at` cleared, AND `last_error` carries `contract_violation:` prefix.
- Companion asserts **non-PayloadMismatch** `Throwable` (simulated transient outage) **does** rethrow — locking the discriminator that `fail()` is NOT broadened to all exceptions. This second test is the difference between WEAK and STRONG: it guards against the RED-R3 silent-failure regression mode by exception family. Excellent.

Also pins broadcaster-not-reached via Mockery `shouldNotReceive('broadcast')` — proves assertion order (validate-before-broadcast).

### 2.4 F-7 i18n empty-key — `I18nNoEmptyKeySentinelTest.php`

**STRONG.** JSON-decoded **recursive walker** across fr/en/ar — not a string-grep. Records `parent_namespace` for any `"":<value>` occurrence so failure output is actionable. Scope intentionally narrow to V1-active locales (bn/de explicitly out-of-V1 per audit). Correctly avoids false positives.

### 2.5 F-9 PII drop ActionLog — `ActionLogPiiRedactionSentinelTest.php`

**STRONG.** Source-pin scoped to the specific `'Nouvelle commande Web/App'` ActionLog::create block via lazy-greedy regex `.*?\]\);`. Asserts BOTH `Auth::user()->name` AND the `'Auteur:'` label string are absent inside that block. The two-pronged assertion is the discipline: a developer who removed `->name` but kept `Auteur:` as a literal label would still leave the PII surface — sentinel catches this.

### 2.6 Foundation Boot Guards — 4 production-refusal sentinels

All four (`PosSimulationHardware`, `IdempotencyMiddleware`, `CorsAppUrl`, `LoyaltyQrSecret`) follow the matured 4-test template : production+flag-bad throws specific message, production+flag-good doesn't throw THE specific guard (sibling exceptions tolerated via message-absence assertion), local+flag-bad boots clean, config default is false (safe-by-default). `CorsAppUrl` adds a 4th test locking the downstream `array_filter` behaviour. All sibling prod-guards are explicitly neutralized via `config([...])` cascade so each test isolates its own throw. **STRONG across all four.**

### 2.7 PushNotification tenant isolation — `PushNotificationTenantIsolationTest.php` (F-8-RED-001)

**STRONG.** Behavioural with `FirebaseService` mocked at the container level (captures tokens passed to `sendNotification`). Three scenarios covering the full state matrix :
- Branch-scoped broadcast (branch_id=N) MUST filter to that branch only.
- Admin broadcast (branch_id=0) MUST keep global fan-out.
- Single-user path (user_id != 0) MUST stay isolated.

Token capture is exact (`sort` + `assertSame`) — not just count/membership but full set equality. Catches both "leaks to other branch" AND "fails to reach intended branch" regressions.

### 2.8 ReceiptDataServiceWireIn (NF525) — `ReceiptDataServiceWireInTest.php`

**STRONG / exemplary.** Five tests : (1) `buildForOrderModel()` returns 6 NF525 fields with exact values, (2) `OrderDetailsResource` delegates to the service (SSOT enforcement), (3) legacy `buildForOrder(int)` and new `buildForOrderModel(Model)` produce **identical** payloads on the 6 SSOT keys, (4) null `fiscal_sequence_no` round-trips as null (not crash), (5) **`FrontendOrder` sibling** acceptance — locks the F1+F3 regression where `buildForOrderModel(Order $order)` 500'd every `/api/frontend/order POST`. Test #5 is the marquee: an actual production bug captured as permanent regression guard.

### 2.9 StockMovements append-only — `StockMovementsAppendOnlyTest.php`

**STRONG.** Three tests, each exercising a distinct trigger surface :
- UPDATE → `LogicException` (no-edit trigger).
- DELETE → `LogicException` (no-delete trigger).
- Duplicate `idempotency_key` INSERT → `QueryException` (UNIQUE constraint).

Each test creates a real `StockMovement` and triggers the violation directly — behavioural, not source-pin. Defense against schema drift since the UNIQUE constraint + triggers are the substantive contract.

### 2.10 StockMovement idempotency key unique — `StockMovementIdempotencyKeyUniqueTest.php`

**INFO — consolidation stub.** All 4 methods `markTestSkipped` with the exact pointer `tests/Feature/Stock/StockMovementsAppendOnlyTest::test_stock_movement_idempotency_key_is_unique_when_present + migration 2026_04_27_143130_create_stock_movements_table.php L19 UNIQUE constraint`. This is **not a WEAK sentinel** — the contract IS held elsewhere (see 2.9). It's dead weight that survived consolidation. **Recommendation: delete this file in V1.0.2 hygiene** (not in V1; deletion adds churn without benefit).

### 2.11 KDS allergens — `KdsOrderItemsResourceAllergenExposureTest.php` (PK-3)

**STRONG.** Three scenarios :
- Non-empty `allergens_snapshot` round-trips through the resource verbatim (`assertEqualsCanonicalizing` — order-insensitive).
- NULL allergens MUST serialize to `[]` (not null) — this is the frontend `Array.isArray()` compatibility lock cited in `admin-kds.js` line 912.
- Resource `::collection()` over raw OrderItem collection carries allergens for every line.

The NULL → `[]` test is the discipline: a presence-only check would let a regression to NULL pass while breaking the chef UI silently. Sentinel catches it.

### 2.12 MyOrderDetails Authz (Admin S-1 IDOR) — `MyOrderDetailsAuthzSentinelTest.php`

**STRONG.** Six HTTP scenarios with real Sanctum + Spatie : anonymous→401, `customers_show`/`waiters_show`/`delivery-boys_show`→200, **zero-of-6-perms→403** (the IDOR fix — pre-heal returned 200), cross-branch→404 (BranchScope defense). Matches the OR-semantics alternation gate exactly. Test #5 captures the pre-heal vulnerability as the marquee assertion. Docstring honestly notes V1.0.2 follow-up (full IDOR closure needs `OrderService::orderDetails` touch on DIRTY list) — no over-claiming.

### 2.13 LoyaltyQrSigning — `LoyaltyQrSigningSentinelTest.php` (LCS-S-001 P0)

**STRONG / exemplary.** Nine tests covering the full QR contract: valid signed scan (sets `X-Loyalty-QR-Status: signed`, writes nonce), expired (clock 1h past, `error_code=qr_expired`, nonce NOT consumed), tampered HMAC (constant-shape fake sig, `qr_invalid_signature`, no consumption), replay (second scan → `qr_replay`, exactly 1 row in `nonces_consumed`), legacy `FK:` plaintext, generate-without-auth → 401, full round-trip generate→scan, mint-on-demand, prod-guard refuses empty `LOYALTY_QR_SECRET`. The discipline lives in negative paths : each verifies **both** the user-facing `error_code` AND absence of side-effects. HTTP-200-with-error-code invariant (failed scan must NOT block parcours client) correctly locked.

### 2.14 POS Loyalty Redeem — `PosLoyaltyRedeemTest.php` (LOCK Option B)

**STRONG.** Six HTTP scenarios : happy path (balance 500→400, ledger row with `type=redeem, points=-100, balance_after=400, source_surface='pos'`, order discount/total recomputed) ; insufficient balance → 422+`INSUFFICIENT_BALANCE` with **balance/ledger/total unchanged** (rollback assertion) ; customer not found → 404+`CUSTOMER_NOT_FOUND` ; paid-order → 409+`ORDER_ALREADY_FINALIZED` ; missing permission → 403 ; double-redemption → 409+`ALREADY_REDEEMED` with exactly 1 redeem row (DB UNIQUE enforced). Each failure asserts code AND absence of mutation.

### 2.15 POS Loyalty Main-Page CTA — `posLoyaltyMainPageCta.spec.js` (Vitest, 13 cases)

**STRONG.** Pure-function extraction (`canShowLoyaltyMainCta` + `extractLoyaltyOrderInfo`). Exhaustive matrix : id null/0/string/NaN parsing, dine-in on/off × order present/absent × PAID/UNPAID × loop over 4 terminal statuses, defensive guards for missing `terminalStatuses` and `paidStatus` enum mismatch. Mirrors proven `posDineInFlag.spec.js` pattern.

### 2.16 POS Loyalty Redeem Modal — `posLoyaltyRedeemModal.spec.js` (Vitest, 11 cases)

**STRONG.** A11y (`role=dialog`, `aria-modal=true`, `aria-label`), open=false hides, apply-disabled until both code+points, discount preview math, submission with exact URL+payload+idempotency header regex `^pos-redeem-42-TESTCUST-100-`, error_code→i18n mapping (`INSUFFICIENT_BALANCE`, 403), `applied` emit on success with full payload, **`applied` NOT emitted on failure**, Esc/backdrop/cancel all emit `close`. The "NOT emitted on failure" assertion is the discipline — a presence-only check would silently pass on broken paths.

### 2.17 Web DÉMO V1 badges — `test-e2e-web-z7-demo-badges-2026-05-19.spec.js`

**STRONG.** 3 tests × 4 viewports = 12 captures. Each asserts badge text `DÉMO V1` in the targeted DOM region (regex whitespace-tolerant), `aria-label*="démonstration"` present, clean console. The customer-trust risk (POS denies a QR the customer trusts) IS the threat — the badge mitigates it, and the test pins exactly that contract per-viewport.

### 2.18 Systematic-discipline sentinels (Coverage walkers)

- **`BranchScopeCoverageSentinelTest`** — STRONG. Symfony Finder walks `app/Models/*.php`, asserts every model whose underlying table has `branch_id` column declares `BranchScope`, with baseline-lock exemption list (10 V1.0.2 backlog entries explicitly documented).
- **`IdempotencyRequiredRoutesCoverageTest`** — STRONG. Walks `Route::getRoutes()`, asserts every route wired to `idempotency` middleware also matches `config(idempotency.required_routes)`. Prevents header-omission-bypass class of bug at PR-time.

---

## 3. Meta-Findings

### 3.1 Pattern: source-pin sentinels are STRONG against edit-regression but BRITTLE to refactor

Several session sentinels grep PHP source via regex (PasswordReset, Stripe, ActionLog, plus the Loyalty QR boot-guard pattern). This is :

- **STRONG against the regression mode they target** : someone edits the line back to `min:6`, removes the tolerance argument, re-introduces `Auth::user()->name` — sentinel fires at PHPUnit time, no integration test needed.
- **BRITTLE against legitimate refactor** : a legitimate move of `ForgotPasswordController::resetPassword()` into a `ResetPasswordRequest` FormRequest would break `PasswordResetMinLengthSentinelTest` even though the contract (12-char minimum) is preserved.

**Recommendation:** when a source-pin sentinel fires, the contributor's reflex must be "verify the contract still holds, then update the sentinel anchor" — NOT "weaken the regex". A short paragraph in CLAUDE.md §13 codifying this would help. Not blocking V1.

### 3.2 Pattern: prod-guard sentinels share a 4-test template

`PosSimulationHardwareProductionGuardSentinelTest`, `IdempotencyMiddlewareProductionGuardSentinelTest`, `CorsAppUrlProductionGuardSentinelTest`, and the QR-secret block inside `LoyaltyQrSigningSentinelTest` all share :
1. production + flag-bad → expects `RuntimeException` matching regex.
2. production + flag-good → does NOT throw THE specific guard (sibling-guard exceptions tolerated, but message absence asserted).
3. local + flag-bad → boots clean.
4. config default is false (safe-by-default).

The "neutralize sibling guards via `config([...])` cascade" pattern is correct discipline — without it, a sibling guard would mask the test's own throw. This template is mature and worth codifying.

### 3.3 Pattern: behavioural sentinels assert both success-state AND no-side-effect on failure

`PosLoyaltyRedeemTest`, `LoyaltyQrSigningSentinelTest`, `PushNotificationTenantIsolationTest`, `MyOrderDetailsAuthzSentinelTest`, `PayloadMismatchFailOnceSentinelTest` all follow the same discipline : every failure-path test asserts `HTTP/code` AND `no row written / balance unchanged / nonce not consumed / dispatched_at cleared`.

This is the difference between SUPERFICIAL ("returns 422") and STRONG ("returns 422 AND zero ledger rows AND balance == before"). The session uniformly meets the STRONG bar.

### 3.4 No genuine WEAK or SUPERFICIAL sentinels found

Honest finding : zero. The combination of (a) source-pin sentinels including negative assertions, (b) prod-guard sentinels neutralizing siblings, (c) behavioural sentinels asserting absence-of-side-effect, and (d) systematic-discipline sentinels using Finder/Route walking — places this session's sentinel discipline at the top of the bar.

---

## 4. Recommendations (Non-Blocking)

| # | Recommendation | Tier |
|---|---|---|
| 1 | Delete `StockMovementIdempotencyKeyUniqueTest.php` in V1.0.2 hygiene — full skip pointer to consolidated coverage, not a sentinel. | INFO |
| 2 | Codify the **prod-guard 4-test template** (3.2) in CLAUDE.md §13 so future prod-guards inherit the discipline. | INFO |
| 3 | Codify the **source-pin brittle-to-refactor** note (3.1) in CLAUDE.md §13 — when a source-pin sentinel fires on a legitimate refactor, the reflex is "verify contract + re-anchor regex", not "weaken regex". | INFO |
| 4 | The 10 `BASELINE_V1_2026-05-18` exemptions in `BranchScopeCoverageSentinelTest` are tracked V1.0.2 backlog (C-P0-D) — no action V1. | INFO |

No P0/P1 sentinel quality findings.

---

## 5. Closing Statement

This is a **READY** signal on sentinel discipline. The 100-commit session added sentinel coverage that is :

- **behavioural where behaviour matters** (auth, fiscal payload, push fan-out, redeem ledger, QR contract).
- **source-pinned where edit-regression is the threat** (regex grep + negative assertions for legacy values).
- **systematic where coverage matters** (BranchScope walker, idempotency-route walker, i18n recursive walker).
- **paired with negative assertions** (no side-effects on failure paths) — the discipline that separates STRONG from SUPERFICIAL.

The single consolidation stub (`StockMovementIdempotencyKeyUniqueTest.php`) is dead weight worth deleting in V1.0.2 hygiene, not a quality defect — its claimed contract is held by `StockMovementsAppendOnlyTest::test_stock_movement_idempotency_key_is_unique_when_present` (verified read).

**No assertion-strengthening recommendations issued — all 17 active session sentinels are STRONG as written.**

---

*End WF-8 STATUS.md — Sentinel Discipline Meta-Audit.*
