# AGENT-1-ARCHITECT — Mobile Loyalty: Endpoint Map + State Machines

**Audit date**: 2026-05-10
**Scope**: FoodKing mobile (React/JSX prototype) vs Laravel backend
**Mode**: Read-only, adversarial

---

## §0 — Ground truth (verified, not from upstream)

- `loyalty_transactions.type` enum = `earn | redeem | manual_add | manual_deduct | expire` (`database/migrations/2026_03_26_075918_create_loyalty_transactions_table.php:25`)
- UNIQUE `(user_id, order_id, type)` named `loyalty_transactions_user_order_type_unique` (`database/migrations/2026_03_26_075919_add_unique_to_loyalty_transactions.php:29`)
- Default rate: **10 pts/€** earn, **100 pts = 1€** discount (`AwardLoyaltyPointsOnDelivery.php:84`, `LoyaltyController.php:290,413-414`)
- `min_redeem_points` drift inside backend: controller default `50` (line 415) vs kiosk default `100` (KioskLoyaltyComponent:335).
- QR format accepted: `FK:<loyalty_code>` / raw 8-char / E.164 phone fallback. **No HMAC verification** (`LoyaltyController.php:609-635`).
- **Critical**: `LoyaltyReward` model **does NOT exist**. Only `LoyaltyConsent` + `LoyaltyTransaction` in `app/Models/`. References in `mobile/CONNECTION_PLAN.md:29,195,295` to `loyalty_rewards` + `GET /loyalty/rewards` are **aspirational**. Mobile `REWARDS` array (`loyalty.js:22-29`) is 100% local mock.

---

## §1 — Endpoint mapping (mobile method → backend)

| Mobile method | Backend endpoint | Auth | Idempotency mechanism |
|---|---|---|---|
| `lookupByCode(code)` | `POST /api/v1/frontend/loyalty/check` | `auth:sanctum` + `throttle:10,1` | None (read-only) |
| `register(phone,name,email)` | `POST /api/v1/frontend/loyalty/register` | Public + `throttle:5,1` | Phone uniqueness + email 409 |
| `optIn(...)` RGPD | `POST /api/v1/frontend/loyalty/opt-in` | Public + `throttle:5,1` | Delegates to `register()` |
| `getBalance()` | `GET /api/v1/frontend/loyalty/balance` | `auth:sanctum` | None (read-only) |
| `getHistory(page)` | `GET /api/v1/frontend/loyalty/history` | `auth:sanctum` | None (read-only) |
| `getConfig()` | `GET /api/v1/frontend/loyalty/config` | Public | None (read-only) |
| `redeem(code,points)` | `POST /api/v1/frontend/loyalty/redeem` | `auth:sanctum` | `lockForUpdate` + DB tx (LoyaltyController.php:273-323) |
| `addPoints(code,pts)` staff-only | `POST /api/v1/frontend/loyalty/add-points` | `auth:sanctum` + role check | DB tx + atomic increment |
| `scan(method,raw_data)` | `POST /api/v1/frontend/loyalty/scan` | `auth:sanctum` + `kiosk:order` ability | Idempotent (issues opaque token) |
| **`earn` via order app** | **NONE — listener-driven** | n/a (event-driven on `OrderStatusChanged`) | **Sentinel `-1` on `orders.loyalty_points_awarded`** (`AwardLoyaltyPointsOnDelivery.php:52-57`) |
| `getRewards()` | **MISSING** — `mobile/CONNECTION_PLAN.md:295` references `/loyalty/rewards` but route DOES NOT EXIST | — | — |
| `refundOnCancel()` | Internal — `App\Services\LoyaltyService::refundPoints()` invoked from cancel paths | — | Reads `redeem` txns by `order_id`, writes a `manual_add` reversal |

**Total backend-resolvable methods: 10** (incl. earn-listener and refund-service). `/loyalty/rewards` is the only mock-only path.

---

## §2 — 10 earn methods catalog

All 10 share **`type='earn'`** (enum-bound, §0). Differentiation lives in
`source_surface` + `description`, NOT in a new `type` value.

### 2.1 — `purchase_app` (mobile order earn)
- **Trigger**: `OrderStatusChanged` event when `newStatus ∈ {PREPARED, DELIVERED}` (kiosk/takeaway)
  or `DELIVERED` (other types) — `AwardLoyaltyPointsOnDelivery.php:39-43`.
- `source_surface`: `'mobile'` (must add to listener line 113 — currently only emits `kiosk`/`web`)
- **Idempotency V0 (localStorage)**: not applicable — server-side sentinel handles it.
- **Idempotency Phase 6**: existing sentinel `-1` on `orders.loyalty_points_awarded` (line 52-57).
  Mobile MUST attach `Idempotency-Key` header on order POST (separate concern).
- **Description format**: `"Commande #{order_serial_no ?? id}"` (listener line 133)
- **Wiring**: mobile order creation → `loyalty_customer_code` propagated via
  `OrderService.php:899-904` or `FrontendOrderService.php:516` → listener fires on status change.

### 2.2 — `purchase_kiosk_phone` (kiosk customer entered phone/code)
- **Trigger**: same listener; differentiator = `loyalty_customer_code` populated AND order_type=KIOSK.
- `source_surface`: `'kiosk'`; `description`: `"Commande #{serial}"`.
- **Wiring**: `KioskLoyaltyComponent` → `/loyalty/check` → cart `loyalty_customer_code` → order POST persists on `orders.loyalty_customer_code` → listener resolves user (line 66-67).

### 2.3 — `purchase_pos_phone` (POS cashier enters customer code)
- **Trigger**: same listener for non-kiosk; `newStatus === DELIVERED`. `description`: `"Commande #{serial}"`.
- `source_surface`: `'pos'` *expected* — but listener line 113 falls back to `'web'` when order's `source_surface` is null. **DRIFT**: POS-originated earns mislabel as `'web'` unless OrderService sets it explicitly.
- **Wiring**: `OrderService.php:899-904` sets `loyalty_customer_code` from request or user lookup.

### 2.4 — `qr_scan_kiosk` (kiosk QR/NFC scan path)
- **Trigger**: scan → cart → order; earn listener fires at status change. `description`: `"Commande #{serial}"`.
- `source_surface`: `'kiosk'`
- **Pre-earn flow**: `/loyalty/scan` (`LoyaltyController.php:575-671`) returns opaque `customer_token` = `'lt_' + sha256(user_id|ts|app.key)[:32]` (line 640). Scan is a lookup, not a transaction.
- **Drift**: mobile `generateMockQR` (loyalty.js:54-58) emits `LECAY-LOYALTY-{uid}-{hmac}` which backend rejects — only `FK:<code>` / raw 8-char / E.164 phone accepted (line 611-626). **HMAC is never verified** server-side.

### 2.5 — `qr_scan_pos` (POS reads customer QR via webcam/scanner)
- **Trigger**: not implemented end-to-end. POS today reads loyalty via `/loyalty/check`. Earn = same DELIVERED listener.
- `source_surface`: `'pos'` (forward-looking); `description`: `"Commande #{serial}"`.

### 2.6 — `plastic_card_scan` (linked plastic card scanned at terminal)
- **Trigger**: same path as 2.4/2.5 — plastic card carries `loyalty_code` (QR or barcode); no special code path. `description`: `"Commande #{serial}"`.
- `source_surface`: depends on terminal (kiosk/pos).
- **V0 mobile**: `ACCOUNT.plastic_card_linked: false` (loyalty.js:40) — UI exists, no backend link.
- **Phase 6**: either reuse `loyalty_code` printed on card (no wiring) OR add explicit link endpoint.

### 2.7 — `welcome_bonus` (sign-up gift)
- **Trigger**: NOT implemented on backend. Mobile `CONFIG.welcome_bonus: 25` (loyalty.js:19) is
  pure mock — `register()` (LoyaltyController.php:111-179) does NOT seed any
  `loyalty_transactions` row, only creates user with `loyalty_points = 0` (line 163).
- **V0 mobile**: write `loyalty_transactions` row of `type='manual_add'` (NOT `earn` — UNIQUE
  constraint considerations don't apply since `order_id=null`).
- **CONSTRAINT**: § hard constraint #2 says "Welcome + first-purchase on same order is a real conflict" —
  irrelevant here because welcome bonus has **no order_id** so the UNIQUE collision is
  between welcome (null) and a manual_add for the same order_id later — not an earn collision.
- **Idempotency V0**: localStorage flag `lc_welcome_bonus_granted_<user_id>=true` before backend call.
- **Idempotency Phase 6**: backend should add `welcome_bonus_granted_at` column on `users`
  (NEW migration) OR check `loyalty_transactions WHERE user_id=? AND description LIKE 'Bienvenue%'`.
- `source_surface`: `'mobile'`, `description`: `'Bienvenue · Bonus inscription'`.

### 2.8 — `manual_cashier` (staff grants pts manually)
- **Trigger**: `POST /loyalty/add-points` (LoyaltyController.php:185-247). Already implemented.
- **Type written**: `manual_add` (line 224) — not `earn`. User-facing "I got points from the cashier" maps here.
- `source_surface`: `'admin'` (line 227); `description`: `"Ajout manuel par staff #{caller_id}"` (line 228).
- **Auth**: role check (line 188-190) — kiosk/customer cannot self-credit.

### 2.9 — `referral` (forward-looking)
- **Trigger**: NOT implemented.
- **Phase 6 design**: trigger event when referred customer's first paid order DELIVERED.
- **Constraint check**: referrer earns `type='earn'` with `order_id=<referee's order>` and
  `user_id=<referrer>`. **UNIQUE (user_id, order_id, type)** = (referrer, referee-order, earn) —
  fine, never collides with referee's own earn (different `user_id`).
- `source_surface`: `'mobile'`, `description`: `"Parrainage · {referee_first_name}"`.
- **Phase 6 schema**: NEW table `loyalty_referrals (referrer_id, referee_id, referee_first_order_id,
  status, created_at)` — gate exactly-once via UNIQUE (referrer_id, referee_id).

### 2.10 — `birthday` (forward-looking)
- **Trigger**: cron daily — credit users whose `users.date_of_birth` = today.
- `source_surface`: `'mobile'` or `'system'`.
- **Idempotency**: UNIQUE (user_id, order_id=NULL, type='earn') would BLOCK after first birthday
  (collision with welcome_bonus if both `order_id=null` + same `user_id` + same `type`). **Use
  `type='manual_add'` for birthday** to avoid the welcome+birthday collision under same
  `user_id, NULL order_id, earn` triple.
- **Phase 6**: better — add a NEW migration for partial unique index that excludes NULL `order_id`,
  OR add `subtype` column. Decision: **stick to `manual_add`** for both welcome_bonus and birthday.
- `description`: `"Anniversaire · +{N} pts"`.

### Catalog summary table

| # | Method | enum `type` | `source_surface` | `order_id` | Already wired backend? |
|---|---|---|---|---|---|
| 1 | purchase_app | earn | mobile (NEW) | yes | listener — needs `'mobile'` added |
| 2 | purchase_kiosk_phone | earn | kiosk | yes | yes |
| 3 | purchase_pos_phone | earn | pos | yes | partial — listener fallback writes `'web'` |
| 4 | qr_scan_kiosk (earn) | earn | kiosk | yes | yes (scan resolves, listener earns) |
| 5 | qr_scan_pos (earn) | earn | pos | yes | partial |
| 6 | plastic_card_scan (earn) | earn | kiosk\|pos | yes | yes (uses `loyalty_code`) |
| 7 | welcome_bonus | manual_add | mobile | null | **NOT wired** |
| 8 | manual_cashier | manual_add | admin | null | yes |
| 9 | referral | earn | mobile | yes (referee's) | **NOT wired** |
| 10 | birthday | manual_add | mobile\|system | null | **NOT wired** |

---

## §3 — State diagram — point lifecycle

```
                           ┌───────────────────────────────────────┐
                           │           NO_ACCOUNT (anonymous)       │
                           └───────────────┬───────────────────────┘
                                           │ POST /loyalty/register
                                           │ (or /opt-in with RGPD consent)
                                           ▼
                           ┌───────────────────────────────────────┐
                           │  USER_CREATED  loyalty_points=0       │
                           │  loyalty_code='A1B2C3D4' (md5 trunc)  │
                           └───────────────┬───────────────────────┘
                                           │ first-paid order DELIVERED
                                           │ AwardLoyaltyPointsOnDelivery
                                           │ atomic claim sentinel -1
                                           ▼
       ┌──────────────────────────────────────────────────────────────────────┐
       │                  EARN PIPELINE (per order)                            │
       │                                                                       │
       │   orders.loyalty_points_awarded:                                      │
       │     NULL ─────► -1 (claimed, in-flight) ─────► N (final, exactly-once) │
       │              ▲                              │                          │
       │              │ revert on error/no-user      │ tx commit                 │
       │              │ (line 76-80, 86-90, 154-160) │                           │
       │                                                                       │
       │   loyalty_transactions INSERT (type=earn, points=+N,                  │
       │     balance_after=snapshot, source_surface, order_id=O)               │
       │                                                                       │
       │   users.loyalty_points += N (atomic increment, line 117)              │
       └──────────────────────────────────────────────────────────────────────┘
                                           │
                                           ▼
                           ┌───────────────────────────────────────┐
                           │   BALANCE = users.loyalty_points       │
                           │   History = loyalty_transactions       │
                           │   (paginated via /loyalty/history)     │
                           └───────────────┬───────────────────────┘
                                           │ POST /loyalty/redeem
                                           │ {code, points} (multiple of 100)
                                           │
                                           ▼
       ┌──────────────────────────────────────────────────────────────────────┐
       │             REDEEM PIPELINE (pre-order, no order_id yet)              │
       │                                                                       │
       │   DB::transaction:                                                   │
       │     User::lockForUpdate (line 274)                                   │
       │     validate points % rate == 0  (line 294-297)                      │
       │     validate balance >= points    (line 298-300)                     │
       │     users.loyalty_points -= P     (line 302-304)                     │
       │     loyalty_transactions INSERT (type=redeem, points=-P,             │
       │       balance_after, source_surface=kiosk|pos, order_id=NULL)        │
       │                                                                       │
       │   Then cart sets discount_value=P/100 ; on order POST                │
       │   loyalty_customer_code persisted on the order row.                  │
       └──────────────────────────────────────────────────────────────────────┘
                                           │
                            ┌──────────────┴──────────────┐
                            │                             │
                  order DELIVERED                  order CANCELLED
                            │                             │
                            ▼                             ▼
                  EARN fires on remaining   ┌──────────────────────────┐
                  paid amount               │ LoyaltyService::         │
                  (independent — no link    │ refundPoints($order):    │
                  back-reference to        │   read txns where        │
                  redeem row)               │   order_id=$id, type=    │
                                            │   'redeem' → re-credit   │
                                            │   abs(sum) as            │
                                            │   type='manual_add'      │
                                            │   description='Rembour-  │
                                            │   sement … #serial'      │
                                            │   (LoyaltyService.php:   │
                                            │   62-71)                 │
                                            └──────────────────────────┘
```

**Critical gap visible in diagram**: redeem txn has `order_id=NULL` at write time (line 312)
because redeem is pre-order. **`refundPoints` finds it by `order_id=$order->id` (line 27)** —
but those redeem rows have `order_id=null`. So refund **only works if** somewhere between
redeem write and order persistence the redeem txn's `order_id` is back-filled. Searching code:
no such backfill exists. **This is a latent bug** beyond §1 scope but noted for §5.

---

## §4 — Reward state machine proposal

V0 reality: `mobile/data/loyalty.js:22-29` lists 6 hard-coded rewards (`free_item`, `discount`,
`percent_discount`). No backend table exists. Mobile `ScreenLoyalty` (screens-main.jsx:846-973)
renders reward tiles in 3 tabs (Points/Rewards/History) and `UTILISER` button calls `go('redeem')`
which jumps to a redeem screen but **does not persist any state** between sessions.

### Proposed state machine (V0 localStorage, Phase 6 backend)

```
                                    ┌────────────┐
                                    │   LOCKED   │ balance < reward.points_cost
                                    └─────┬──────┘
                                          │ balance crosses cost (any earn)
                                          ▼
                                    ┌────────────┐
                                    │  UNLOCKED  │ balance >= reward.points_cost
                                    └─────┬──────┘
                                          │ user taps "Utiliser"
                                          ▼
                                    ┌────────────┐
                                    │  SELECTED  │ in cart preview ; not yet persisted
                                    └─────┬──────┘
                                          │ checkout confirm
                                          ▼
                                    ┌─────────────────────┐
                                    │ APPLIED_NEXT_ORDER  │ redeem written ;
                                    │ (== redeem txn)     │ order pending
                                    └─────┬───────────────┘
                                          │
                          ┌───────────────┴───────────────┐
                          │                                │
                  order DELIVERED                  order CANCELLED
                          │                                │
                          ▼                                ▼
                   ┌────────────┐                  ┌────────────┐
                   │  CONSUMED  │                  │  REVERSED  │ refundPoints
                   └────────────┘                  └────────────┘
                          ▲
                          │
                          │ (alt) expiry cron — Phase 6
                          ▼
                   ┌────────────┐
                   │  EXPIRED   │
                   └────────────┘
```

### Per-state fields & validation

| State | V0 localStorage fields | Validation entering | Backend Phase 6 mapping |
|---|---|---|---|
| LOCKED | derived: `balance < reward.points_cost` | none | computed from `users.loyalty_points` + `loyalty_rewards` row |
| UNLOCKED | derived: `balance >= cost && reward.is_active && !period_consumed` | check reward.is_active (mock: always true in V0) | `loyalty_rewards.is_active` + per-period join on `loyalty_transactions` (CONNECTION_PLAN.md:195 schema not built — needs migration) |
| SELECTED | `lc_selected_reward_id` (localStorage, single value) | `balance >= cost` re-checked AT entry | no persistence (UI-only) |
| APPLIED_NEXT_ORDER | `lc_pending_redemption = {reward_id, points, txn_id, created_at}` | redeem POST 200 OK | `loyalty_transactions WHERE type='redeem' AND order_id IS NULL AND user_id=?` |
| CONSUMED | derived from history: redeem txn with non-null `order_id` AND order status DELIVERED | order DELIVERED event | `loyalty_transactions.order_id` set + `orders.status = DELIVERED` |
| EXPIRED | `lc_expired_rewards_<user_id>=[reward_id...]` (localStorage cache) | cron daily — Phase 6 | NEW migration `loyalty_transactions WHERE type='expire'` — enum already supports it |
| REVERSED | derived from history: `manual_add` row with description starting `'Remboursement'` | `LoyaltyService::refundPoints` writes it | already wired (LoyaltyService.php:62-71) |

### Key validation rules

1. **Balance check** at every transition INTO `SELECTED` or `APPLIED_NEXT_ORDER` — server enforces
   atomically via `lockForUpdate` (LoyaltyController.php:274). Client MUST NOT trust local
   balance for the final guard.
2. **`reward.is_active`** — V0: all true. Phase 6: read from `loyalty_rewards.is_active`.
3. **`not_already_consumed_period`** — V0: localStorage check `lc_reward_<id>_lastUsed_<userId>`
   vs `reward.period_days` (currently undefined in mock). Phase 6: server-side count of
   redeem txns with description matching reward in last N days. **Requires schema extension** —
   currently `loyalty_transactions` has no `reward_id` column.
4. **Single SELECTED at a time** — V0: a single `lc_selected_reward_id` key. UI must clear it
   on cart change.

### Critical V0 gap

There is **no `reward_id` foreign key** on `loyalty_transactions` today (migration line 20-33).
Per-reward consumption tracking requires either:
- Adding `reward_id` nullable column (NEW migration in Phase 6), OR
- Parsing `description` field (fragile, not recommended).

Recommendation for V0: store `lc_pending_redemption.reward_id` in localStorage only; persist
real link in Phase 6 via new column.

---

## §5 — Drift findings vs backend

| # | Severity | File:line | Mobile says | Backend says | Impact |
|---|---|---|---|---|---|
| D-1 | **P0** | `mobile/data/loyalty.js:15` | `earn_ratio: 1` (1€ = 1 pt) | `loyalty_points_per_euro = 10` (listener line 84) | **10× under-display of earned points**. User sees 25 pts on a 25€ order; backend credits 250. |
| D-2 | **P0** | `mobile/data/loyalty.js:54-58` | QR = `LECAY-LOYALTY-{user_id}-{hmac}` | Accepts `FK:<code>` / raw 8-char / E.164 phone (controller line 611-626). No HMAC verified. | Mobile QR will NEVER scan successfully on kiosk. |
| D-3 | **P0** | `mobile/data/loyalty.js:32-41` | `ACCOUNT` hard-coded `{balance: 347, lifetime_earned: 1247, …}` | Only `users.loyalty_points` exists. No `lifetime_earned`, `lifetime_redeemed`, `next_threshold`, `progress_to_next` columns. | These fields must be **computed client-side** from `/history` aggregations or stay mock. |
| D-4 | **P0** | `mobile/data/loyalty.js:22-29` | 6 hard-coded REWARDS array | **No `loyalty_rewards` table or model exists**. `CONNECTION_PLAN.md:295` `/loyalty/rewards` route does NOT exist. | Rewards is 100% mock; Phase 6 requires NEW migration + NEW controller method. |
| D-5 | **P1** | `mobile/data/loyalty.js:16` | `redeem_ratio: 100` ✓ matches | `loyalty_points_for_1_euro_discount=100` ✓ | OK |
| D-6 | **P1** | `mobile/data/loyalty.js:17` | `min_redeem_points: 100` | Backend default `50` (controller line 415); kiosk default `100` (KioskLoyaltyComponent line 335). | Drift inside backend (controller vs kiosk); mobile should read from `/config` not hard-code. |
| D-7 | **P1** | `mobile/data/loyalty.js:19` | `welcome_bonus: 25` | NOT implemented (controller `register` line 111-179 never seeds points). | Either remove config OR implement listener on user create. |
| D-8 | **P1** | `mobile/data/loyalty.js:18` | `expires_after_days: 365` | NOT implemented. `loyalty_transactions.type='expire'` enum supports it, but no cron exists. | Mobile must not show expiry UI until backend cron lands. |
| D-9 | **P1** | `mobile/screens-main.jsx:846-973` | `points = 347; goal = 500` hardcoded; all history `[{d:'8 mai',a:'+25',...}]` hardcoded | Real data lives in `users.loyalty_points` + `/history` paginated. | Component is 100% mock; no API integration. |
| D-10 | **P1** | `mobile/data/loyalty.js:7` | `history ↔ /api/v1/frontend/loyalty/history (LoyaltyTransaction)` | Endpoint exists (controller line 453-548) but **DOES NOT require auth in current routes/api.php** — wait, **it does** (line 1208-1213, `auth:sanctum` group). | OK. |
| D-11 | **P1** | mobile lacks any reference to `source_surface='mobile'` | Listener line 113 falls back to `'web'` for non-kiosk orders if `source_surface` is null on the order row. | Mobile orders will appear as `source_surface='web'` in `loyalty_transactions`, breaking per-channel analytics. |
| D-12 | **P2** | `mobile/data/loyalty.js:9` doc comment claims `qr ↔ HMAC signé backend ; LoyaltyController::generateQr` | **No `generateQr` method exists** on backend. | Doc lies. |
| D-13 | **P2** | `mobile/data/loyalty.js:40` | `plastic_card_linked: false` flag | No backend mechanism to link plastic card to account exists (no endpoint, no column). | Phase 6 — decide: either reuse `loyalty_code` print on card (no extra wiring) or add explicit link table. |
| D-14 | **P2** | `mobile/screens-main.jsx:932,943` | redeem button calls `go('redeem')` — no points value, no reward_id transmitted | Backend `/redeem` requires `{code, points}` (controller line 47-50). | Mobile redeem screen MUST collect/derive these or fail. |
| D-15 | **P0 CONFIRMED** | `LoyaltyService.php:27` vs `LoyaltyController.php:312` | refund queries `loyalty_transactions WHERE order_id=$order->id AND type='redeem'` | redeem write sets `order_id=NULL` (line 312). Verified by exhaustive grep `app/**` — **zero** backfill code path exists. | **Backend bug**: refund **never** finds the redeem row → cancelled orders silently lose user's discounted points. Out of mobile commit-2 scope but blocks Phase 6 reliability. |

---

## §6 — Recommendations for commit-2 (data refactor)

Ordered by risk-reduction value. Each item is a single self-contained change.

### R-1 (P0, lowest risk) — Fix `earn_ratio`
`mobile/data/loyalty.js:15` → change `earn_ratio: 1` → `earn_ratio: 10`. Add comment citing
`config('loyalty_setup.loyalty_points_per_euro')` default 10. Long-term: load from `/loyalty/config`
on app mount.

### R-2 (P0) — Mark mock fields explicitly
In `mobile/data/loyalty.js:32-41` add `// MOCK ONLY — not in backend schema:` comments on
`lifetime_earned`, `lifetime_redeemed`, `next_threshold`, `progress_to_next`, `plastic_card_linked`.
Compute `lifetime_*` client-side via `/history` aggregation OR omit until Phase 6.

### R-3 (P0) — Rewards: keep mock, document migration plan
Add comment block at top of `REWARDS = […]` (line 22): "MOCK — no `loyalty_rewards` table exists
backend. Phase 6 introduces migration + `GET /loyalty/rewards`. Until then, point-cost values
here are NOT authoritative."

### R-4 (P0) — QR format
Replace `generateMockQR` (loyalty.js:54-58) to emit `FK:<loyalty_code>` (no HMAC, since backend
doesn't verify). Add TODO: Phase 6 HMAC requires backend to expose `generateQr` endpoint that
returns server-signed token with 5min TTL — does NOT exist today (`LoyaltyController` has no
`generateQr` method).

### R-5 (P1) — Stop using mocked ACCOUNT in `ScreenLoyalty`
Replace `mobile/screens-main.jsx:846-973` `points = 347; goal = 500` with reads from the
loyalty store (TBD in commit-3 + ScreenLoyalty refactor). Pass via prop or React context.

### R-6 (P1) — Earn methods catalog as JSDoc inline
Document each of the 10 methods in `mobile/data/loyalty.js` as a JSDoc constant `EARN_METHODS`
enumerating: `{code, surface, requires_account, status: 'wired'|'mock'|'planned'}` — exposes
ground truth to other agents without spelunking.

### R-7 (P1) — Add `source_surface: 'mobile'` propagation
When mobile creates orders (commit-N, separate from this audit) it MUST set
`source_surface='mobile'` on order POST payload. Backend `AwardLoyaltyPointsOnDelivery.php:113`
will then write `'mobile'` on the earn txn rather than fall back to `'web'`.

### R-8 (P1) — Welcome bonus deferral
Remove `welcome_bonus: 25` from CONFIG (loyalty.js:19) OR clearly mark `MOCK — not granted`.
If keeping mock, V0 grants via localStorage flag only and a fake history row, never hits
backend. Phase 6: add backend listener on User::created OR add `welcome_bonus_granted_at`
column to `users` with cron grant.

### R-9 (P2) — Reward state machine in code
Add reducer file `mobile/data/loyaltyRewardState.js` implementing the 7-state FSM from §4
with pure functions `(state, action) => state`. Test coverage in commit-3.

### R-10 (P2) — Note backend latent bug D-15
Add comment in `mobile/data/loyalty.js` near refund references warning that backend
`LoyaltyService::refundPoints` currently has a latent bug (queries redeem by `order_id` but
redeem rows have `order_id=null`). Out-of-scope for mobile commit-2 but consumers must know
their points may NOT auto-refund on order cancellation today.

---

## §7 — Open questions for other agents

1. **AGENT-4-DBA**: add `subtype` or `reward_id` column to disambiguate welcome/birthday/referral within `manual_add`? (Today only `description` text differentiates — fragile for analytics.)
2. **AGENT-2-SECURITY**: `/loyalty/scan` issues `customer_token = sha256(user_id|ts|app.key)[:32]` (controller:640). 128-bit truncation + `app.key` reuse — sufficient?
3. **AGENT-5-DRIFT**: D-15 is now **confirmed** (exhaustive grep, no backfill path). Flag for Phase 6 hot-fix.

---

**EOF — 01_architect.md**
