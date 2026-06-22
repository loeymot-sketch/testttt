# OWNER DECISION — POS Wave 3 Design Composition Concerns (Cash Drawer)

**Type:** OWNER-DECISION REQUIRED — V1 ship gate
**Date:** 2026-05-18
**Branch:** `v1-0-1-hardening-2026-05-17` — HEAD `f24b49c42`
**Scope:** V1 LOCAL Le Cayenne (single-resto, single-cashier / owner-staff). **NOT** SaaS / multi-resto / cloud.
**Severity:** 3 × P1 design composition — non-blocking from a security exploit standpoint, but require owner judgement before merge to `main` because they reflect intentional V1 trade-offs that should be documented, not silently shipped.

---

## 1. Context — Wave 2 heal and Wave 3 dispute

**Wave 2 (commit `5df225ffa`)** healed `POS-RED-04`: a same-branch cashier B could close cashier A's drawer with `closing_amount=0` and misattribute the variance. The heal added `assertSessionVisibleToUser()` in `CashDrawerSessionController` requiring **owner OR manager** to act on a session. This composed on top of the Sprint H2 manager-gate routine-close flag in `CashDrawerService.php:151-160`.

**Wave 3 adversarial (read-only)** accepted the heal as a net-positive but disputed three design composition issues that survive it. **No P0 found.** The three P1s flagged are all _intentional_ V1 trade-offs that need owner sign-off so that "ship as-is" is a recorded decision and not a missed gap.

Source: `reports/audit/critical-focus-2026-05-18/wave-3/adv-1-pos-heals.md`.

---

## 2. The 3 P1 design composition concerns

### Concern A — POS-ADV3-05: Role-OR-Permission divergence vs service-layer permission-only gate

**File:** `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:251-253`
**Evidence (post-heal):**
```php
$isManager = $user->can('cash.reconcile.variance.override')
    || $user->hasRole('Admin')
    || $user->hasRole('Branch Manager');
```
Service-layer counterpart at `app/Services/Cash/CashDrawerService.php:154` is **permission-only** (`actorCanOverrideVariance($actor, $permission)`).

**Risk:** If an admin revokes `cash.reconcile.variance.override` from `Branch Manager` via the permission UI, the **service** denies the close but the **controller** still admits the actor via `hasRole('Branch Manager')`. Two enforcement layers diverge in the same domain.

**Operational reality (Le Cayenne V1):** owner = Admin, no live permission management UI flips happen, BM role has the permission by default seeder (`RolePermissionTableSeeder.php:78`). The divergence is theoretical for V1.

---

### Concern B — POS-ADV3-06: Manager can still zero-close peer drawer

**File:** `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:254`
**Evidence:** Post-heal, any user with `hasRole('Branch Manager')` (or `can('cash.reconcile.variance.override')`) can `POST /api/admin/cash-drawer-sessions/{id}/close` with `closing_amount=0` against any same-branch cashier's drawer.

**Wave 2 commit message claim:** "prevents cashier B closing cashier A's drawer with `closing_amount=0` → variance mis-attribution".
**Wave 3 finding:** narrows attack surface from any-same-branch-staff → manager-or-owner. **Does not eliminate it.** Forensic trail IS improved (`closed_by_user_id` persisted at `CashDrawerService.php:188`, audit_logs HMAC chain unchanged).

**Operational reality (Le Cayenne V1):** effectively one cashier per shift (often the owner). The "manager closes peer's drawer" scenario requires a peer to exist; for single-cashier deploys it does not. NF525 audit chain logs the closing actor regardless.

---

### Concern C — POS-ADV3-07: Cascade refuses owner-cashier when `manager_gate_routine_close=true`

**File:** `app/Services/Cash/CashDrawerService.php:151-160`
**Evidence:**
```php
if (Config::get('cash.manager_gate_routine_close', false)) {
    // ...permission-only manager check, no $isOwner consideration...
}
```
The service comment (lines 140-143) explicitly states: *"Default false: single-cashier deploys keep the POS Operator self-closes own drawer UX intact. Multi-cashier / SaaS deploys flip CASH_MANAGER_GATE_ROUTINE_CLOSE=true to enforce manager approval on ALL closes."*

**Wave 3 finding:** when the flag is ON, the controller's `$isOwner` early-pass at `CashDrawerSessionController.php:250` is **overruled** by the service. Net: cashier can never close own drawer with flag ON; only managers can. Two gates cascade with different semantics, no joint comment.

**Operational reality (Le Cayenne V1):** flag defaults `false`. Le Cayenne ships with default config. Owner-cashier closes own drawer unimpeded. The "regression" only manifests in a deployment that opts into the flag — explicitly a multi-cashier mode the comment says is for SaaS/multi-cashier deploys.

---

## 3. Options matrix

### Concern A (POS-ADV3-05)

| Path | Action | Pro | Con |
|------|--------|-----|-----|
| **A** | Drop role check, permission-only in controller | Single source of truth; permission revocation now authoritative | Owner UI must always grant permission to BM seed; minor seeder discipline cost |
| **B** | Make controller permission-only, service unchanged | Same as A — controller follows service | Identical to A in net behaviour |
| **C** | Accept current state as defense-in-depth (BM-by-role assumed) | Zero code change; ships now; permission rarely revoked in single-resto | Two-layer divergence remains; documented quirk for V2 SaaS to revisit |

### Concern B (POS-ADV3-06)

| Path | Action | Pro | Con |
|------|--------|-----|-----|
| **A** | Pair-approval (owner-cashier + manager) for every close | Defeats zero-close abuse end-to-end | UX hit on single-cashier flow; new code path; out-of-scope for V1 |
| **B** | Tighten variance forensic (extra columns, alert thresholds) without changing access | Improves detectability post-event | Adds schema migration; preventive not corrective |
| **C** | Accept current state (any-manager-can-close is operational reality) | Zero code change; matches single-resto owner-supervised model; HMAC audit trail covers forensic | Trust-based; relies on Le Cayenne having ≤1 BM other than owner |

### Concern C (POS-ADV3-07)

| Path | Action | Pro | Con |
|------|--------|-----|-----|
| **A** | Flag bypasses ownership check (manager-mode = manager-only, no owner exception) | Removes ambiguity; flag means "managers only" cleanly | Hard-codes a stance; SaaS may want hybrid |
| **B** | Refine flag: owner closes own with floor variance, manager required when variance > 0 | More nuanced; matches retail intuition | New logic surface; needs config schema + tests; V1.0.1 scope |
| **C** | Accept current state — flag intended for multi-cashier deployments, Le Cayenne V1 keeps default `false`, owner-cashier closes own drawer | Zero code change; matches existing comment intent | Two-cascade behaviour documented but not unified; deferred V2 |

---

## 4. Recommendation — proposed default (C / C / C)

For V1 LOCAL Le Cayenne — single-resto, single-cashier, owner-staff — the simplest path that preserves ship readiness is **C across the board**: accept the current state and document the semantics. Rationale:

- **Concern A (C):** revocation of `cash.reconcile.variance.override` from Branch Manager is an operation that does **not occur** in a single-resto deploy where owner = Admin and BM = owner-staff. The two-layer divergence is theoretical, has no exploitable path, and would only matter in a multi-tenant SaaS where granular permission flips happen. **Backlog item: V1.0.1 hardening — unify on permission-only.**

- **Concern B (C):** the "manager closes peer's drawer with `closing_amount=0`" attack requires a peer cashier to exist. Le Cayenne runs with effectively one cashier per shift (typically the owner). The audit-log HMAC chain captures the closing actor; forensic post-event reconciliation is intact. **Backlog item: V2 SaaS pair-approval (Option A).**

- **Concern C (C):** the service-layer comment at `CashDrawerService.php:140-143` explicitly states the cascade is **intentional** — flag default `false` keeps the owner-cashier UX, flag opt-in is for SaaS / multi-cashier. Le Cayenne ships with default config. The behaviour is not a regression; it is a documented opt-in mode. **Backlog item: V2 documentation pass to add a joint comment across controller + service.**

Net code change: **zero.** Net documentation change: this file (recorded decision) + a one-line cross-reference comment in `CashDrawerSessionController.php` pointing here.

---

## 5. Decision form (owner signs)

```
Concern A (POS-ADV3-05): [ ] A drop role check    [ ] B permission-only    [X] C accept-as-is (default)
Concern B (POS-ADV3-06): [ ] A pair-approval      [ ] B forensic tighten   [X] C accept-as-is (default)
Concern C (POS-ADV3-07): [ ] A flag = manager-only [ ] B hybrid refine     [X] C accept-as-is (default)

Owner signature: ___________________________   Date: ____________

Notes / overrides:




```

If owner countersigns **C / C / C** the V1 ship gate clears on these three concerns. Any deviation triggers a follow-up cycle (estimated: A on Concern A = 30 min + 1 test; A on Concern B = 4–6 h + migration; A on Concern C = 1 h + flag-cascade tests).

---

## 6. References

- **Wave 3 dispute report:** `reports/audit/critical-focus-2026-05-18/wave-3/adv-1-pos-heals.md` (findings POS-ADV3-05 / 06 / 07)
- **Wave 2 commit:** `5df225ffa` — `fix(pos): cash drawer session owner-or-manager close (Wave 1 P1)`
- **Heal site:** `app/Http/Controllers/Admin/Pos/CashDrawerSessionController.php:234-255`
- **Service-layer manager gate:** `app/Services/Cash/CashDrawerService.php:151-160`
- **Permission seeder:** `database/seeders/PermissionTableSeeder.php` + `database/seeders/RolePermissionTableSeeder.php:78`
- **CLAUDE.md anchors:** §9 (multi-tenant + auth), §13 (evidence rules)

---

GStack Documenter — POS Owner Decision — Wave 3
