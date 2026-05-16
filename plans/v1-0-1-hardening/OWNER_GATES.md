# OWNER GATES — V1.0.1 Hardening (4 blocking decisions)

> These 4 gates BLOCK their respective sprint tasks until owner sign-off. Pre-queue all 4 at cycle kickoff so decisions arrive within first 2 days (per MASTER plan §5 risk register).

**Date**: 2026-05-16
**Cycle**: V1.0.1 Hardening
**Branche**: `v1-0-1-hardening-2026-05-16`
**Source plan**: `plans/v1-0-1-hardening/MASTER_V1_0_1_HARDENING_2026-05-16.md`

---

## Gate G1 — V2 KDS Items Board restore vs deprecate

**Blocks**: Sprint H4 (KDS V2 finalize) Task H4.1

### Question
Should V2 KDS layout get back the legacy "Items Board" 5th column (aggregating items across all visible orders for batch prep — e.g., "5 fries + 3 cheeseburgers pending"), OR is its removal in V2 final?

### Context
- Legacy KDS had a 5th "Items Board" column that aggregated `item.name + variations` across visible orders. Chef could see batch-prep opportunities ("prepare 5 fries at once").
- V2 redesign (Wave Z baseline) shipped without it — `KdsV2Grid.vue` has 0 aggregation hits.
- Wave Z RED-team flagged as P0 regression. Downgraded to V1.0.1 owner-gate because it's a **feature decision**, not data correctness.

### Options

| Option | Action | Effort | Pros | Cons |
|--------|--------|--------|------|------|
| **A — RESTORE** | Add 5th column "Items Board" in `KdsV2Grid.vue` with aggregation logic | 3-5j | Returns batch-prep efficiency for chefs used to legacy workflow | More complex UI ; potential CPU cost on 50+ orders ; another surface to maintain |
| **B — DEPRECATE** | Document in `docs/CHANGELOG_V1.md` as removed feature. Train chefs on V2 unified-queue per-order view | 0.5j (doc) | V2 unified queue is more efficient than batch-prep for fast-food rush ; less code | Existing chef workflow change ; potential pushback |
| **C — DEFER V1.0.2** | Accept V2 as-is. Revisit post-V1.0.1 ship based on chef feedback | 0j | Ship V1.0.1 faster ; data-driven decision | Risk of escalation if chefs complain at restaurant level |

### Recommendation: **Option B (deprecate)**

Reasoning :
- V2 unified queue surfaces all items per order in order — chef reads top-down per ticket. Batch-prep aggregation made sense in legacy 4-column-per-status view where items were scattered ; V2 collapses status into one stream.
- Items Board would add another column to a screen already tight on horizontal space (KDS targets 10-15" displays).
- Train-cost of Option B < restoration-cost of Option A.
- Owner can override post-deployment based on chef feedback (Option C is essentially "Option B with explicit revisit milestone").

### Sign-off

```
Owner: ____________________
Date: ____________________
Decision: [ ] A   [ ] B   [ ] C
Notes: ____________________
```

---

## Gate G2 — F-12 LOCK plan for `pos-wizard.js` CASH tile

**Blocks**: Sprint H2 Task H2.5

### Question
Approve a LOCK plan to edit frozen `public/js/pos-wizard.js` (CLAUDE.md §7 frozen zone) to add a defensive CASH tile disable when no `CashDrawerSession` OPEN?

### Context
- Backend `PosController::store` already throws `CashDrawerSessionNotOpenException` → 422 (Sprint 1B + Wave Z 5B). Invariant is fiscal-grade enforced.
- F-12 is purely defensive UX: visually disable the CASH tile in the POS wizard so user can't click → get 422 → toast.
- `public/js/pos-wizard.js` is the POS Vanilla JS wizard — frozen per CLAUDE.md §7 (design parfait selon owner 2026-05-06).

### Options

| Option | Action | Effort | Pros | Cons |
|--------|--------|--------|------|------|
| **A — LOCK + IMPLEMENT** | Create `LOCK_pos_wizard_cash_tile_f12.md` with scope, files, justification, rollback. Implement scope-minimal toggle (~15 LOC). Owner reviews diff before merge | 1j impl + 0.5j review | Tightens UX (no failed-click cycle) ; pattern-test for future LOCKs | Frozen-zone discipline breach precedent ; review-cycle overhead |
| **B — ACCEPT REACTIVE UX** | Document accepted "user clicks CASH → backend 422 → toast 'no session open'". No LOCK | 0.5j (doc) | Preserves frozen discipline ; fiscal-grade backend is sole enforcement | 1 wasted click per error event |

### Recommendation: **Option B (accept reactive UX)**

Reasoning :
- The defect here is UX friction (1 wasted click), not data correctness. Backend enforcement is unchanged.
- Frozen-zone discipline has more long-term value than the 1-click savings.
- If owner later finds the friction significant in prod, Option A can be re-opened with telemetry data (e.g., "X% of POS sessions hit the 422 toast in week 1").

### Sign-off

```
Owner: ____________________
Date: ____________________
Decision: [ ] A   [ ] B
Notes: ____________________
```

---

## Gate G3 — K-004 LOCK for kiosk wizard template inference

**Blocks**: Sprint H1 Task H1.6

### Question
Approve a LOCK plan to edit frozen `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:907-947` (`detectTemplateFromName` method) to require explicit `item.wizard_template` field?

### Context
- Current code: substring inference on `item.name` (e.g., contains "tacos" → template=tacos).
- Risk: item rename = silent wizard break.
- Already-existing fallback : `item.wizard_template` DB column populated by the menu reset command (Cayenne 2026-05-13).

### Options

| Option | Action | Effort | Pros | Cons |
|--------|--------|--------|------|------|
| **A — LOCK + EXPLICIT** | Edit `detectTemplateFromName` to require `item.wizard_template` field, log warning if NULL, fall back to substring only when explicitly enabled | 1j impl + 0.5j review | Tightens correctness ; silent break risk gone | Frozen edit |
| **B — CONFIG ALIASES** | Add `config/kiosk.php` `wizard_template_aliases` map. `KioskWizardComponent` reads aliases first BEFORE substring (still 1-line read in frozen file = inline-edit exception) | 0.5j | Preserves discipline ; fixes 90% of rename incidents | Still substring-based for edge cases |
| **C — DEFER V1.0.2** | Document risk in CHANGELOG. Monitor for incidents | 0.25j | Ship faster | Risk if rename happens before V1.0.2 |

### Recommendation: **Option B (config aliases)**

Reasoning :
- Preserves frozen-zone discipline (1-line config read is inline-edit exception, not source change).
- Fixes the 90% case (admin renames "Sandwich Cayenne" → "Sandwich Royal", aliases map preserves template).
- Substring fallback still catches edge cases.

### Sign-off

```
Owner: ____________________
Date: ____________________
Decision: [ ] A   [ ] B   [ ] C
Notes: ____________________
```

---

## Gate G4 — Z6-06 user status revalidation middleware aggressiveness

**Blocks**: Sprint H1 Task H1.3

### Question
How aggressive should the per-request `users.status` check be on `auth:sanctum` routes?

### Context
- Currently: Sanctum tokens survive `users.status = INACTIVE` change for the full TTL (480min). Admin disabling a user has up to 8h delay.
- Wave Z Z6-06 flagged as P1.

### Options

| Option | Action | Effort | Pros | Cons |
|--------|--------|--------|------|------|
| **A — EVERY REQUEST** | `EnsureUserStatusActive` middleware on every `auth:sanctum` route. ~1ms overhead | 0.5j | Security event handled instantly | DB hit per request (mitigatable via Eloquent's loaded-relations cache) |
| **B — PERIODIC (5min cache)** | Cache user status, check every 5min | 0.5j + cache complexity | Lower DB load | 5min stale window |
| **C — TOKEN-LIFETIME REDUCTION** | Reduce sensitive-ops Sanctum TTL 480→60min ; status takes effect on next relogin | 0.5j config | Simplest implementation | Doesn't actually fix the instant-revocation requirement |

### Recommendation: **Option A (every request)**

Reasoning :
- A disabled user is a security event. The 1ms cost per request is worth instant revocation.
- DB hit is from already-loaded `$request->user()` (Sanctum's auth middleware already queried it).
- Middleware adds 1 conditional: `if ((int) $user->status !== Status::ACTIVE) { 401; }`. Negligible.

### Sign-off

```
Owner: ____________________
Date: ____________________
Decision: [ ] A   [ ] B   [ ] C
Notes: ____________________
```

---

## Sign-off process

1. Owner reads this doc + the MASTER plan
2. Owner makes 4 decisions and writes them in the Sign-off sections
3. Owner commits OR sends back to Claude via chat
4. Claude updates MASTER plan §4 Owner gates with final decisions
5. Claude proceeds to sprint execution

If any gate refused (e.g., G3 = Option C "defer V1.0.2"), the corresponding sprint task is documented as deferred and removed from the cycle scope.
