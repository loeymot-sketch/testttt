# rush-pos round-1 — Wave POS re-verification report

**Run** : `rush-pos-2026-05-13` round-1 wave-POS
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10` (HEAD `bb4b4c805`)
**Spec** : `tests/e2e/rush-100-pos-capture.spec.js` (5 scenarios + setup)
**Window** : 2026-05-13 13:39:13 → 13:40:51 UTC (~98 s)
**Verdict** : **PARTIAL** — 2/3 heals VERIFIED, 1 heal (WB-R1-03) NOT VERIFIED (code path unreached).

---

## 1. Spec execution

| Metric | Value |
|---|---|
| Tests planned | 6 (1 setup + 5 scenarios) |
| Tests passed (Playwright runner) | **6 / 6** |
| Wall-clock | 2.2 min |
| Total artifact quartets emitted | 32 (PNG + DOM + console + network per state) |
| Total page-errors (across 32 captures) | **0** |
| New `orders` rows persisted | **0 / 5** |
| Fiscal sequence (max, gap-free) | **321** (= baseline ; no advance, no gap) |

**Setup test 00 — login admin → /admin/pos-v4** : PASS within 15 s timeout. POS V4 grid `.pos-v5-grid` rendered, sidebar fully hydrated. The pos-app.js slim-router heal (`5218168ef`) is doing its job — the prior round-1 / round-2 timeout is GONE.

---

## 2. Heal verification

### 2.1 WB-R1-01 — pos-app.js slim-router stubs (commit `5218168ef`)

**Status : VERIFIED FULLY GREEN.**

- `pageerrors=0` on every one of the 32 captures (was 37 / load round-1).
- Setup test rendered the grid in <15 s (was 15 s timeout in round-1 / round-2).
- Vue Router 4 `useLink()` no longer throws `MATCHER_NOT_FOUND` because the 5 stub routes (`admin.pos-orders.tracker`, `.list`, `.show`, `admin.order-status-screen`, `auth.login`) are now resolvable in pos-app.js.

### 2.2 WB-R1-02 — POS V4 sidebar `aria-label` + `title` on category pills (commit `e7cb4578e`)

**Status : VERIFIED FULLY GREEN.**

DOM grep on `00-pos-v4-ready.dom.html` produced 10/10 matched pairs :

```
aria-label="Toutes les catégories" title="Toutes les catégories"
aria-label="Sandwich Cayenne"      title="Sandwich Cayenne"
aria-label="Galette"               title="Galette"
aria-label="Sandwich Classique"    title="Sandwich Classique"
aria-label="Tacos"                 title="Tacos"
aria-label="Bols Gourmands"        title="Bols Gourmands"
aria-label="Frites"                title="Frites"
aria-label="Suppléments"           title="Suppléments"
aria-label="Desserts"              title="Desserts"
aria-label="Boissons"              title="Boissons"
```

Screen-reader + sighted operators can now disambiguate the two `Sandwich…` pills (visually CSS-truncated). 0 frozen-zone touch — `PosComponent.vue` is not in the §7 list.

### 2.3 WB-R1-03 — PaymentComponent defensive `modalHide('#orderpayment')` (commit `0f201e29d`)

**Status : NOT VERIFIED — code path unreached this run.**

The heal lives inside `if (paymentSucceeded) { try { appService.modalHide('#orderpayment'); } … }` in `PaymentComponent.vue` (around line 879). It only fires when the POS create POST returns a `paymentSucceeded=true` payload (HTTP 200).

In this run **all 5 scenario POSTs `/api/admin/pos` were 429-throttled** (see §3). Therefore :
- `paymentSucceeded` never flipped to `true`.
- The defensive `modalHide` was never invoked.
- The receipt screens (`S?-05-receipt.dom.html`) show `#orderpayment .active` and `#receiptModal` not active — but that is the **expected** 429-error UI state (banner "Trop de requêtes — patientez 30s avant de réessayer" visible in S6 screenshot), **not** a failure of the heal.

**Verdict** : heal is correctly committed in source, but its visual effect is impossible to demonstrate without an HTTP 200 path. Defer re-verification to a follow-up run where the rate-limit bucket is empty AND sufficient cool-down separates scenarios (see §4).

---

## 3. Rate-limit observations (production-correct behaviour)

All 5 scenario POSTs were stamped **HTTP 429**. Console of every `*-05-receipt` capture contains exactly one error :

```
Failed to load resource: the server responded with a status of 429 (Too Many Requests)
```

The `clearFoodKingRateLimits` helper is invoked before every scenario and clears `pos-order-create`, `admin-mutation`, `api`, etc. for `admin@lecayenne.fr` + `127.0.0.1`. Yet the throttle still triggered for **all 5** orders (brief assumption was "2-3 rapid orders"). Two non-exclusive hypotheses :

1. The `admin-mutation` limiter is keyed differently than the cleared keys (e.g. session id, or `Sanctum` user_id token suffix, not the simple md5 used by the helper).
2. A prior in-flight POST left the bucket at maximum — the clear races with the actual request enqueue.

This is a **test-infra concern**, not an application bug. The 429 → "Trop de requêtes" banner is exactly what a rush-hour cashier should see.

**Anomaly to surface (paid_response_status mystery)** : the DB-checks JSON records `paid_response_status: 200` for all 5 scenarios. The mega-audit-snap network log filters to `status >= 400`, so a 200 would not appear in the network artefact (only the 429 does). The DB row read after each POST is `id=1401, total=6.5, fiscal_sequence_no=null` for all 5 — that is the **pre-test rush-sync order**, not a new one. Two interpretations :
- Idempotency replay : a stale `X-Idempotency-Key` returned a cached 200 response for a prior order — possible if a rush-sync run finished < 24 h ago.
- The waitForResponse predicate matched a *different* `/api/admin/pos`-shaped POST (e.g. observability heartbeat) that happens to return 200.

Worth tracing in the next round if reproducible.

---

## 4. DB integrity

```sql
-- Pre-test (baseline)
max(orders.id)                = 1401     (created 2026-05-13 13:26:28, rush-sync)
max(fiscal_sequence_no)       = 321
fiscal sequence range 300..321 = gap-free, 22 / 22 present

-- Post-test (after the 5-scenario run window 13:39–13:40)
max(orders.id)                = 1401     (unchanged, no new rows)
max(fiscal_sequence_no)       = 321      (unchanged)
fiscal sequence range 300..321 = still gap-free
SELECT COUNT(*) FROM orders WHERE created_at BETWEEN '13:30' AND '14:00' = 0
```

**NF525 invariant : intact.** Zero fiscal-chain perturbation, zero gap, zero new orders persisted (because no POST succeeded). This is acceptable for the test — no half-allocated `fiscal_alloc_error_at` flags either.

Per-scenario summary (read totals are the grand_total displayed in the cart pre-confirm) :

| Scen | Item | Read total € | POST status | Persisted order | fiscal_seq | Heals visually checkable |
|---|---|---|---|---|---|---|
| S6 | Big Tacos | 11.50 | **429** | none (DB unchanged) | — | aria-label YES, modalHide N/A |
| S8 | Bol Gratiné | 12.50 | **429** | none | — | aria-label YES, modalHide N/A |
| S3 | Galette Cayenne | 7.00 | **429** | none | — | aria-label YES, modalHide N/A |
| S4 | Sandwich Classique | 7.00 | **429** | none | — | aria-label YES, modalHide N/A |
| S10 | Multi-cart (Sandwich Cayenne + Frites + Tarte Daim) | 21.00 | **429** | none | — | aria-label YES, modalHide N/A |

---

## 5. Recommendations for next round

1. **Bypass rate-limit** : add a `pos-rate-limit-bypass=1` test header gated by `APP_ENV=local` (or use `RouteServiceProvider::for('admin-mutation', …)` to skip on a known test IP). Without bypass, WB-R1-03 cannot be empirically verified.
2. **Investigate `paid_response_status: 200` mystery** : add request-URL logging in the spec's `waitForResponse` callback to capture which 200 matched.
3. **Cool-down between scenarios** : `await sleep(35_000)` between orders if bypass is not feasible — the 429 banner says 30 s wait.
4. Optional : write a tiny unit test asserting `modalHide('#orderpayment')` is invoked exactly once on `paymentSucceeded=true` (jsdom is enough). That closes the heal verification gap without depending on rate-limit infra.

---

## 6. Final verdict

- WB-R1-01 (pos-app.js slim-router) → **CLOSED GREEN**.
- WB-R1-02 (POS V4 sidebar aria-label) → **CLOSED GREEN**.
- WB-R1-03 (PaymentComponent defensive modalHide) → **DEFERRED — code is shipped, runtime verification blocked by rate-limit. Re-run on a clean limiter or with bypass.**

Spec ran 6/6 PASS, 0 page-errors, 32 artefact quartets emitted. NF525 fiscal chain unchanged. POS V4 path is fully renderable post-heal — round-1 / round-2 timeout regression is gone.
