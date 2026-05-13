# rush-pos round-1 — Wave POS adversarial review

**Reviewer** : adversarial supervisor (single pass)
**Verdict** : **GO-CONDITIONAL** — 2/3 heals empirically verified, 1 heal (WB-R1-03) shipped but unverifiable this round due to a test-infra bug.

## Claims verified (primary-source evidence)

1. **WB-R1-01 pos-app.js slim-router heal (commit `5218168ef`)** — **GREEN**. Zero pos-app.js entries in any of the 32 console.json files (pre-heal: 37 unhandled-promise rejections per load). The only `level=error` entries are vendor.js Pusher WebSocket failures (allowlisted) and one 429 toast on each `*-05-receipt` (expected — banner visible in capture). Setup test 00 rendered `.pos-v5-grid` within the 15 s budget.
2. **WB-R1-02 aria-label + title on category pills (commit `e7cb4578e`)** — **GREEN**. DOM grep on `00-pos-v4-ready.dom.html` confirms 10/10 matched pairs verbatim (`Toutes les catégories`, `Sandwich Cayenne`, `Galette`, `Sandwich Classique`, `Tacos`, `Bols Gourmands`, `Frites`, `Suppléments`, `Desserts`, `Boissons`). The two CSS-truncated "Sandwich…" pills are now SR-disambiguable.
3. **NF525 fiscal chain** — **INTACT**. `max(fiscal_sequence_no)=321` unchanged, gap-free `300..321`, zero new orders, zero `fiscal_alloc_error_at`.
4. **Frozen zone** — `git diff HEAD~12 -- public/js/pos-wizard.js` = 0 lines. POS Vanilla wizard untouched.

## Claims disputed / surfaced

- **`paid_response_status: 200` is FALSE-POSITIVE** (WP-R1-01, P1 test-infra). `tests/e2e/rush-100-pos-capture.spec.js:255-261` uses `waitForResponse` with predicate `/\/api\/admin\/pos(?:\?|$|\/)/.test(url) && method==='POST' && status<500` — too loose. The 200 caught by the predicate is **not** the order POST (which got 429 per `network.json`) but a different `/api/admin/pos/*` sub-URL hit. The GStack report's hypothesis "idempotency replay returning cached 200" is **incorrect** — there is no replayed 200, just a misfiring predicate. Every db-checks.json row's `paid_response_status: 200` is misleading.
- **`clearFoodKingRateLimits` does NOT clear the right bucket** (WP-R1-02, P2). The helper's `md5($name.$key)` key-construction does not match Laravel's internal `cleanRateLimiterKey()` format used by `RateLimiter::for('admin-mutation', ...)` (RouteServiceProvider:77-91). All 5 POSTs hit 429 → success path never executed → WB-R1-03 modalHide heal cannot be verified at runtime.
- **WB-R1-03 modalHide heal NOT runtime-verified** (WP-R1-03, P2). DOM on every `*-05-receipt.dom.html` confirms `#orderpayment .active` retained and `#receiptModal` not active — but this is the **expected** 429-error UI (toast "Trop de requêtes" visible). Fastest closure: jsdom unit test asserting `modalHide('#orderpayment')` is invoked when `paymentSucceeded=true`, no rate-limit dependency.

## Minor

- **WP-R1-04 P3** — `00-pos-v4-ready.png` shows the grid partially occluded by an auto-opened "Commandes borne" sidebar (~40% right viewport). `S6-01-pos-empty.png` is the cleaner grid-render proof.
- **WP-R1-05 P3** — Cart counter renders "1 Articles" (singular qty + plural noun); use `trans_choice` / pluralization.

## Next steps

1. Tighten `waitForResponse` predicate to `endsWith('/api/admin/pos')` AND log every POST regardless of status.
2. Replace md5-based `clearFoodKingRateLimits` with `Cache::flush()` between scenarios OR introduce `pos-rate-limit-bypass` test header.
3. Add jsdom unit test for `modalHide('#orderpayment')` invocation (closes WB-R1-03 verification gap without infra).
4. Then re-run rush-pos round-2 — expect 5/5 HTTP 200 + 5 new orders + `fiscal_sequence_no` advancing.
