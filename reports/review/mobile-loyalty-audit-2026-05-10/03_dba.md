# AGENT-3-DBA — Mobile Loyalty Schema & Concurrency Audit
**Date:** 2026-05-10 | **Scope:** loyalty_* tables, race conditions, V0/Phase-6 gap | **Mode:** read-only

---

## §1 — Exact schema tables

### 1.1 `users.loyalty_*` columns
Source: `database/migrations/2026_03_08_145926_add_loyalty_fields_to_users_table.php:15-18`

| Column | Type | Nullable | Default | Index | FK |
|---|---|---|---|---|---|
| `loyalty_code` | VARCHAR(15) | YES | NULL | UNIQUE (single-col) | — |
| `loyalty_points` | INTEGER (signed) | NO | 0 | — | — |

Notes:
- `loyalty_code` UNIQUE is the *only* way `User::where('loyalty_code', $code)` (LoyaltyController:274) hits an index — confirmed.
- `loyalty_points` is signed INTEGER: a bug-driven double-redeem can push balance negative (no DB CHECK constraint). LoyaltyController:298 guards via app-level `if ($user->loyalty_points < $pointsToRedeem)` — racy without lockForUpdate (which IS present, see §3).

### 1.2 `loyalty_transactions` (immutable ledger)
Source: `database/migrations/2026_03_26_075918_create_loyalty_transactions_table.php:20-33` + `..._075919_add_unique_to_loyalty_transactions.php:28-30`

| Column | Type | Nullable | Default | Index | FK |
|---|---|---|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | NO | — | PRIMARY | — |
| `user_id` | UNSIGNED BIGINT | NO | — | INDEX `loyalty_transactions_user_id_index` | `users.id` ON DELETE CASCADE |
| `loyalty_code` | VARCHAR(25) | YES | NULL | INDEX `loyalty_transactions_loyalty_code_index` | — |
| `order_id` | UNSIGNED BIGINT | YES | NULL | INDEX `loyalty_transactions_order_id_index` | — (no FK declared) |
| `type` | ENUM('earn','redeem','manual_add','manual_deduct','expire') | NO | 'earn' | — | — |
| `points` | INT (signed) | NO | — | — | — |
| `balance_after` | INT (signed) | NO | — | — | — |
| `source_surface` | VARCHAR(20) | YES | NULL | — | — |
| `description` | VARCHAR(255) | YES | NULL | — | — |
| `created_at`,`updated_at` | TIMESTAMP | YES | NULL | — | — |
| **Composite UNIQUE** | `(user_id, order_id, type)` | — | — | UNIQUE `loyalty_transactions_user_order_type_unique` | — |

Critical observations:
- **No FK on `order_id`** → orphan rows survive order deletion (but app uses soft delete on orders, so generally OK).
- **`type` is ENUM(5 values)** → adding `welcome_bonus`, `referral`, `app_install`, etc. as new types requires a `ALTER TABLE ... MODIFY COLUMN` migration. Drives the §2 recommendation.
- **`balance_after` is NOT atomic** with the actual `users.loyalty_points` value — it's a *snapshot at insert time*. Concurrent earns can both snapshot stale values; ledger remains correct only because the unique constraint serializes per `(user_id, order_id, type)`.
- **MySQL ENUM caveat:** the migration uses Laravel's `enum()` which becomes a native MySQL `ENUM` on MySQL but a `VARCHAR + CHECK` (or plain `TEXT`) on SQLite. Tests run on SQLite (see migration:46-52 driver branching) — production parity for ENUM expansion must be verified.

### 1.3 `loyalty_consents` (RGPD ledger)
Source: `database/migrations/2026_04_18_120008_create_loyalty_consents_table.php:29-40`

| Column | Type | Nullable | Default | Index | FK |
|---|---|---|---|---|---|
| `id` | BIGINT AUTO_INCREMENT | NO | — | PRIMARY | — |
| `user_id` | UNSIGNED BIGINT | NO | — | (part of composite below) | `users.id` ON DELETE CASCADE |
| `consent_accepted` | BOOLEAN | NO | — | — | — |
| `privacy_notice_version` | VARCHAR(20) | NO | — | — | — |
| `ip_hash` | CHAR(64) | NO | — | — | — |
| `user_agent_hash` | CHAR(64) | NO | — | — | — |
| `occurred_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | — | — |
| `created_at`,`updated_at` | TIMESTAMP | YES | NULL | — | — |
| **Composite INDEX** | `(user_id, occurred_at)` | — | — | INDEX `loyalty_consents_user_ts_idx` | — |

No UNIQUE on consents — multiple consent revocations/re-grants intentionally permitted (audit log pattern).

### 1.4 `orders.loyalty_*` columns
Source: `database/migrations/2026_03_25_003209_add_loyalty_awarded_to_orders_table.php:17` + `2026_03_26_005907_..._frontend_orders_table.php:18` (NB: file name says "frontend_orders" but migration targets `orders` per its docblock).

| Column | Type | Nullable | Default | Index | FK |
|---|---|---|---|---|---|
| `loyalty_points_awarded` | UNSIGNED INT | YES | NULL | — | — |
| `loyalty_customer_code` | VARCHAR(25) | YES | NULL | — | — |

CRITICAL — sentinel encoding:
- `NULL` → not yet awarded (claimable state)
- `-1` → in-flight (sentinel claimed by one process)
- `0..N` → finalized awarded points (or 0 = ineligible)

**Bug surface:** the column is declared `UNSIGNED INT`. The sentinel value `-1` (Listener:56) **is invalid for unsigned**. Under strict SQL mode (MySQL `sql_mode=STRICT_ALL_TABLES`) this `UPDATE ... = -1` will fail or silently coerce. Confirm production `sql_mode` does NOT block this — otherwise the entire sentinel-claim pattern is broken in prod. **HARD-REQUIRED to verify or migrate to signed.**

### 1.5 frontend_orders inheritance
`FrontendOrder` uses `protected $table = 'orders'` (per migration:10 docblock and Listener:50 comment). So all `orders.loyalty_*` columns are *physically the same columns* used by both POS and kiosk surfaces — there is no separate `frontend_orders` table.

---

## §2 — UNIQUE collision resolution

### Problem
UNIQUE `(user_id, order_id, type)` enforces one earn row per (user, order). Welcome-bonus + first-purchase earn both write `type='earn'` with the same `order_id` → constraint violation. 10 distinct earn methods (welcome, purchase, referrer, referee, daily-streak, app-install, review, birthday, level-up, social-share) cannot coexist.

### Candidate matrix

| Aspect | C1 (NULL order_id for non-order earns + source_surface) | C2 (new nullable `idempotency_key` col + relax UNIQUE) | C3 (encode variant in description + hash) |
|---|---|---|---|
| Migration needed | NO (zero schema change) | YES (V1.0.1 work, ALTER ADD COLUMN + DROP/ADD UNIQUE) | YES (DROP/ADD UNIQUE on derived expr) |
| Existing data safe | YES | YES (nullable col) | RISKY — needs backfill of hash for old rows |
| Concurrency-safe earn dedupe | YES if welcome uses (NULL, 'earn'), purchase uses (orderId, 'earn'), referral uses (NULL, 'earn', distinct source_surface) — but **two NULL-order earns of same source_surface collide** | YES — `(user_id, order_id, type, idempotency_key)` is fully orthogonal | YES if hash component well-chosen |
| Multiple NULL earns same surface | **HOLE** — e.g. two welcome bonuses (bug or replay) collide harmlessly, two referrals over time would collide | Clean — key per event | Clean |
| Reuses existing ENUM | YES — all stay `type='earn'` | YES | YES |
| ENUM expansion needed | NO | NO | NO |
| Risk to backend | LOW | MEDIUM (migration + Listener change) | HIGH (description format becomes load-bearing) |
| V0 mobile mock compatibility | EXCELLENT — V0 client constructs orderId=null with distinct source_surface | GOOD — V0 generates idempotency keys client-side | POOR — V0 must reconstruct same hash |

### Recommendation: **C1**

Rationale:
1. **Zero migration** — does not require V1.0.1 schema work, deployable today.
2. **Existing UNIQUE remains valid** — `(user_id, NULL, 'earn')` is treated as multiple rows in MySQL (because NULL != NULL in unique indexes per SQL standard); welcome-bonus + first-purchase coexist (one has `order_id=NULL`, one has `order_id=<id>`).
3. **`source_surface` already 20 chars** — encode variant: `kiosk`, `pos`, `web`, `mobile_welcome`, `mobile_referral_giver`, `mobile_referral_receiver`, `mobile_streak`, `mobile_app_install`, `mobile_review`, `mobile_birthday`. All fit.
4. **MySQL NULL semantics** — `UNIQUE (a,b,c)` with `b=NULL` is intentionally non-unique in MySQL (and Laravel default). This is the documented escape hatch.

### C1 holes that MUST be patched at Phase 6
- Two welcome bonuses for same user (bug/replay) won't collide → idempotency must be enforced at **service layer** (check `LoyaltyTransaction::where('user_id', $u)->where('source_surface', 'mobile_welcome')->exists()` before insert).
- Two referrals (giver) over time → expected behavior is one-per-friend; service layer needs to dedupe by `(referrer_id, referee_id)` in a separate `loyalty_referrals` table or via `description='referral:friend_id=42'` lookup.

### Migration debt deferred to Phase 6
If C1 service-layer dedupe becomes hairy, **promote to C2** in V1.0.1 (clean migration path: add column, populate `idempotency_key = "{source_surface}:{order_id ?? 'null'}:{event_uid}"` for existing rows via SQL, drop old UNIQUE, add new).

---

## §3 — Race condition map (concurrency matrix)

Surfaces that mutate balance: `redeem` (LoyaltyController:255-339), `earn` (AwardLoyaltyPointsOnDelivery:27-164), `refund` (LoyaltyService:21-79), `manual_add/deduct` (admin), `expire` (cron, not yet implemented).

### Concurrency table (rows = primary op | cols = concurrent op)

| Primary ↓ / Concurrent → | redeem | earn | refund | manual_add | expire |
|---|---|---|---|---|---|
| **redeem** | SAFE — both lockForUpdate same user row → serialized. LoyaltyController:274 | SAFE — earn uses `User::increment` (atomic SQL `UPDATE SET col=col+N`); redeem holds lockForUpdate; earn waits | SAFE — refund acquires lockForUpdate (LoyaltyService:42-44 except SQLite); both serialize on user row | SAFE if admin uses transaction; **GAP if not** | N/A (expire not impl.) |
| **earn** | SAFE (mirror of above) | **GAP-1 (rare):** dual-trigger (kiosk PREPARED + POS DELIVERED). Sentinel claim at Listener:52-56 serializes via `UPDATE WHERE loyalty_points_awarded IS NULL` — only 1 row affected; second update returns 0; correct. **BUT** order column is `UNSIGNED INT` while sentinel is `-1` (see §1.4) | SAFE — refund reads `WHERE type='redeem'`, earn writes `type='earn'`, no logical conflict on same order_id | SAFE — different rows in ledger; user row uses atomic increment | N/A |
| **refund** | SAFE | SAFE (no order-level conflict; sentinel for earn vs. refund is independent — refund doesn't touch `loyalty_points_awarded`) | **GAP-2:** two parallel cancellations of same order both pass `redeemTxns->isEmpty()` check then both increment user balance — **double refund**. LoyaltyService:27-58 has NO idempotency on the refund itself. UNIQUE constraint would block both `manual_add` inserts only if they share `(user_id, order_id, 'manual_add')` — and they would, so the **second INSERT fails on UNIQUE** *after* the user balance was already double-incremented. **DATA INTEGRITY BUG.** | SAFE if admin in tx | N/A |
| **manual_add** | SAFE | SAFE | See refund gap above | SAFE in tx | N/A |
| **expire** | (not impl.) | (not impl.) | (not impl.) | (not impl.) | — |

### Findings detail

**GAP-1 (sentinel `-1` on UNSIGNED column)** — likelihood: HIGH if MySQL strict mode is on, LOW otherwise (silent overflow to 4294967295). Either way the value stored is *not* `-1`. Listener:78 then queries `WHERE loyalty_points_awarded = -1` to revert, **which never matches** → orphaned in-flight sentinels. **HARD-REQUIRED to fix:** change column to signed INT or use a separate `loyalty_award_in_progress_at TIMESTAMP NULL` claim column.

**GAP-2 (refund double-increment)** — likelihood: MEDIUM (cancel button double-click + idempotency middleware bypass). Fix:
```php
// LoyaltyService::refundPoints — wrap user lookup + increment + ledger insert in DB::transaction
return DB::transaction(function() use ($order, $sourceSurface) {
  // ... lockForUpdate user, increment, insert ...
  // Use ::firstOrCreate by (user_id, order_id, type='manual_add') to block double
});
```
The UNIQUE on `(user_id, order_id, 'manual_add')` *will* protect the ledger but **the balance was already double-incremented before the INSERT** (LoyaltyService:56-58 fires first, then :62-71). Must reorder: insert ledger FIRST inside tx (UNIQUE catches double), THEN increment balance. Or use `LoyaltyTransaction::firstOrCreate(['user_id'=>x,'order_id'=>y,'type'=>'manual_add'], $payload)` and only increment if `wasRecentlyCreated`.

**Cross-cutting:** `refund` calls `lockForUpdate` only when NOT SQLite (LoyaltyService:42-44). Production is MySQL so OK; tests on SQLite skip the lock → CI cannot catch this class of race.

---

## §4 — V0 localStorage idempotency design

V0 is mobile mock with no backend earn calls — but each "Tu as gagné X pts" toast must dedupe in case the user re-mounts the screen, navigates back, or replays a deep link.

### Pseudocode extension for `mobile/api/storage.js`

```javascript
// Append to mobile/api/storage.js (V0)
// Idempotency map keyed by stable event identifier.
// Lives in localStorage under `lecayenne.idempotency` as a sorted-by-ts array.

const IDEMPOTENCY_KEY = 'idempotency';   // -> lecayenne.idempotency
const TTL_MS         = 30 * 24 * 3600e3; // 30 days — earns are post-order, beyond that we accept replay
const MAX_ENTRIES    = 500;              // hard cap for quota safety (each entry ~ 80 bytes -> ~40 KB max)

function _now() { return Date.now(); }

function _readIdemMap() {
  return get(IDEMPOTENCY_KEY, []);
}

function _writeIdemMap(arr) {
  // Sort by ts ascending so oldest is at head for cheap eviction
  arr.sort((a, b) => a.ts - b.ts);
  // TTL prune
  const cutoff = _now() - TTL_MS;
  let pruned = arr.filter(e => e.ts >= cutoff);
  // Cap prune (FIFO)
  if (pruned.length > MAX_ENTRIES) pruned = pruned.slice(-MAX_ENTRIES);
  set(IDEMPOTENCY_KEY, pruned);
}

// Compose the canonical key. order_id may be null for welcome/referral/etc.
function _composeKey(orderId, sourceSurface) {
  return `${orderId ?? 'null'}:${sourceSurface}`;
}

function hasEarned(orderId, sourceSurface) {
  const key = _composeKey(orderId, sourceSurface);
  return _readIdemMap().some(e => e.key === key);
}

function recordEarn(orderId, sourceSurface, points) {
  const key = _composeKey(orderId, sourceSurface);
  const map = _readIdemMap();
  if (map.some(e => e.key === key)) return false; // already recorded
  map.push({ key, ts: _now(), points });
  _writeIdemMap(map);
  return true;
}

// Expose
window.LC.storage.idempotency = { hasEarned, recordEarn };
```

### Caller pattern (UI level)
```javascript
function awardWelcomeBonus() {
  if (LC.storage.idempotency.hasEarned(null, 'mobile_welcome')) return;
  if (LC.storage.idempotency.recordEarn(null, 'mobile_welcome', 50)) {
    // show toast + update mock balance
  }
}
```

### Cleanup policy
- **TTL:** 30 days. Earns older than that can replay (mobile mock; no harm).
- **Cap:** 500 entries (FIFO). Each entry ~80 bytes → 40 KB max. localStorage budget is 5–10 MB → 0.4% utilization headroom.
- **Quota errors:** `set()` in storage.js:13 already swallows quota errors silently. Idempotency will degrade gracefully (replay possible) but never crash.

### Phase 6 backend mapping — honest gap
| V0 client-side concept | Phase 6 backend equivalent | Migration cost |
|---|---|---|
| `key = "${orderId}:${sourceSurface}"` | `(user_id, order_id, type)` UNIQUE — IF C1 chosen | **ZERO** if welcome/referral/etc. all use `type='earn'` with distinct `source_surface` and `order_id=NULL` |
| `ts` (client clock) | `created_at` (server clock) | Trivial — drop client ts |
| `points` (client-decided) | `Settings::group('loyalty_setup')` server-decided | **CRITICAL gap** — V0 hardcodes points (e.g. 50 welcome). Phase 6 MUST read from settings. Mobile design doc must list all 10 earn methods with their settings keys. |
| Per-device dedupe | Per-user-row dedupe | **HONEST GAP:** V0 dedupes per *device*. If user logs in on phone A then phone B, both will trigger welcome bonus. Phase 6 backend dedupes per `user_id` (UNIQUE constraint). V0 cannot simulate this fidelity without a server. **Accept this gap** in V0; document in mobile/README. |
| `mobile/api/storage.js` Map | DB UNIQUE constraint + service-layer `firstOrCreate` | Backend code only — schema already supports it under C1. |

### Hard rules for V0
1. Never persist points server-side from V0 — `loyalty_points` on `users` table MUST NOT be mutated by V0 client.
2. V0 toast is **visual only** — the server-of-record never sees these earns until Phase 6.
3. Wipe `lecayenne.idempotency` on `clearAuth()` (storage.js:35-38). Otherwise a logout/login as different user inherits the old user's "already earned" state.

---

## §5 — V0 → Phase 6 transition gap

### Hard-required (BLOCKS Phase 6 if missing)

1. **Signed `loyalty_points_awarded` OR new claim column.** Current UNSIGNED INT + sentinel `-1` is broken under strict SQL mode (see §1.4, §3 GAP-1). Migration:
   ```sql
   ALTER TABLE orders MODIFY loyalty_points_awarded INT NULL;
   -- OR
   ALTER TABLE orders ADD COLUMN loyalty_awarded_in_progress_at TIMESTAMP NULL;
   ```
2. **`description` length ≥ 80 chars** — current 255 is fine; mobile earn descriptions may include `referrer_user_id=12345` etc.
3. **Composite index `(user_id, source_surface, created_at)` on loyalty_transactions** — mobile history pagination per surface (see §6).
4. **Refund tx ordering fix** in `LoyaltyService::refundPoints` (see §3 GAP-2) — pure code, no migration, but blocks any mobile cancel/refund feature.

### Nice-to-have (V1.0.1)
5. FK on `loyalty_transactions.order_id` references `orders.id` ON DELETE SET NULL — protects against soft-delete cleanup orphans.
6. CHECK constraint `users.loyalty_points >= 0` (MySQL 8+) — defence-in-depth against the race-free-but-bug-prone decrement path.
7. Promote C1 to C2 (add `idempotency_key VARCHAR(64)`) if service-layer dedupe becomes complex for referral/streak/birthday.
8. Soft delete `loyalty_consents` — RGPD audit retention 5y; currently no delete protection (cascade on user delete deletes consents — actually correct per migration:21 docblock, but consider GDPR-of-data-controller obligation independent of user account).

---

## §6 — Top 3 missing indexes for mobile pagination performance

Mobile history screen queries (anticipated): "last 20 earns for user X", "show all transactions on this order", "per-surface aggregate (welcome bonuses given total)".

```sql
-- 1. Per-user descending history with optional surface filter
--    Covers: SELECT * FROM loyalty_transactions
--            WHERE user_id = ? AND source_surface IN (?, ?)
--            ORDER BY created_at DESC LIMIT 20 OFFSET ?;
CREATE INDEX loyalty_transactions_user_surface_ts_idx
  ON loyalty_transactions (user_id, source_surface, created_at DESC);

-- 2. Per-order audit (refund flow, dispute resolution)
--    Covers: SELECT * FROM loyalty_transactions WHERE order_id = ? ORDER BY id;
--    Note: order_id index exists (single-col, migration:24) but adding id covers ledger walk.
--    Already covered by single-col `order_id` index — SKIP unless we see slow queries.

-- 3. Type-level analytics (admin dashboard: "total points expired this month")
--    Covers: SELECT SUM(points) FROM loyalty_transactions
--            WHERE type = ? AND created_at BETWEEN ? AND ?;
CREATE INDEX loyalty_transactions_type_created_idx
  ON loyalty_transactions (type, created_at);

-- 3-bis. Welcome-bonus / referral lookups under C1 (replaces "did user X already get welcome?")
--    Covers: SELECT 1 FROM loyalty_transactions
--            WHERE user_id = ? AND source_surface = ? AND type = 'earn' LIMIT 1;
--    Top index #1 above already covers this — OK.
```

Prioritization: index #1 is the single highest-ROI for mobile UX (history pagination is the most-called read in Phase 6). Index #2 (per-order) — current single-col `order_id` index is sufficient.

---

## §7 — Atomic increment patterns reference

Three legitimate patterns are in the codebase. Each has a correct usage.

### Pattern A — Sentinel-update (claim by UPDATE WHERE)
Used at: `AwardLoyaltyPointsOnDelivery:52-58`
```php
$updated = DB::table('orders')
    ->where('id', $order->id)
    ->whereNull('loyalty_points_awarded')
    ->where('status', '!=', OrderStatus::CANCELED)
    ->update(['loyalty_points_awarded' => -1]);
if ($updated === 0) return; // someone else won
```
**Correct when:** single binary flag transition needed; row-level lock not necessary because the `WHERE` clause becomes the predicate filter. SQL guarantees `UPDATE` is atomic on a single row. Multiple concurrent callers → exactly one returns `affected_rows=1`.
**Wrong when:** the value column has type constraints incompatible with the sentinel (cf. §1.4 UNSIGNED INT vs `-1`).

### Pattern B — `lockForUpdate` then app-level decrement
Used at: `LoyaltyController::redeem` lines 274, 302-304
```php
$user = User::where('loyalty_code', $code)->lockForUpdate()->first();
// ... business validation requires reading $user->loyalty_points BEFORE deciding ...
if ($user->loyalty_points < $pointsToRedeem) return ['error' => ...];
DB::table('users')->where('id', $user->id)->decrement('loyalty_points', $pointsToRedeem);
```
**Correct when:** decision depends on the current value (check-then-act). The `SELECT ... FOR UPDATE` row lock prevents concurrent readers from seeing the same balance and both passing the `<` check. MUST be inside `DB::transaction`.
**Wrong when:** SQLite (no row-level locks). LoyaltyService:42-44 guards this — but tests can't catch races on SQLite.

### Pattern C — Raw atomic increment (`DB::increment` / `DB::decrement`)
Used at: `AwardLoyaltyPointsOnDelivery:117`, `LoyaltyService:57`, `LoyaltyController:304`
```php
User::where('id', $user->id)->increment('loyalty_points', $pointsToAward);
```
Lowers to SQL: `UPDATE users SET loyalty_points = loyalty_points + N WHERE id = ?`. Single-statement, fully atomic at DB level. No app-level read needed.
**Correct when:** the delta is known and the new value's validity doesn't depend on the prior value (e.g. earning is always safe; refunding is always safe).
**Wrong when:** you need to enforce `balance >= 0` — `UPDATE ... SET points = points - N` will silently produce negatives on a signed column or fail on unsigned. For redeem, Pattern B is required.

### Quick decision tree
- Flag transition (NULL→sentinel→final)? → **A**
- Decision depends on current value? → **B** (lockForUpdate + tx)
- Pure additive mutation? → **C** (raw increment)

---

## Summary verdict (DBA viewpoint)

| Item | Severity | Action |
|---|---|---|
| `loyalty_points_awarded` UNSIGNED vs sentinel `-1` | **P0 BLOCKER** if MySQL strict mode | Migrate column to signed INT before Phase 6 |
| Refund double-increment race (§3 GAP-2) | **P1** | Reorder service: `firstOrCreate` ledger BEFORE balance increment |
| UNIQUE collision for 10 earn methods | P1 design | Adopt C1 (NULL order_id + source_surface variant), service-layer dedupe for non-order earns |
| Missing index for mobile history pagination | P2 perf | Add `(user_id, source_surface, created_at DESC)` |
| V0 idempotency per-device only | KNOWN GAP | Document in mobile/README; clear map on `clearAuth()` |
| Tests run on SQLite (no row locks) | P2 test debt | Add MySQL CI matrix or LoyaltyService unit test stubs that simulate concurrent calls |

**Verdict:** schema is sound at the conceptual level. Two concrete data-integrity bugs (UNSIGNED sentinel, refund order-of-operations) must be fixed before Phase 6 ships. C1 makes the 10-earn-method extension achievable with zero migration.
