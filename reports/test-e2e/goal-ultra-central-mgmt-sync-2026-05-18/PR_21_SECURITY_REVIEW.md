# Security Review — PR #21 `heal/cms-pr1-quickwins-2026-05-18`
## Substitute for `/ultrareview` (security pass) — Claude-internal autonomous review

> **Why this doc:** `/ultrareview` is a billed user-triggered slash command — orchestrator cannot invoke. The user authorized substitute autonomous review. This document captures the `security-review` skill's verdict on the 11 heal commits.

**Date:** 2026-05-18
**Reviewer:** general-purpose sub-agent + advisor (read-only)
**Scope:** 11 heal commits on `heal/cms-pr1-quickwins-2026-05-18`
**Method:** `git show <sha>` per commit + per-file scope analysis (skip docs/tests/migrations-pure-DDL)
**Branch base:** `v1-0-1-hardening-2026-05-17`

---

## Verdict: **0 HIGH/MEDIUM-confidence security findings.** Merge-OK from security perspective.

---

## Commits assessed

| SHA | Title | Files touched | New vuln surface? |
|---|---|---|---|
| `1e7c65ecc` | M-R3-P0-C APP_DEBUG boot guard | `AppServiceProvider.php` | No — `RuntimeException` guard, no user input |
| `139ce01aa` | S-R3-P0-G+H Pusher channel-auth wildcard | `routes/channels.php` | No — server-set token name; Spatie hasRole strict compare; scoped KioskMachine lookup |
| `65f59e82f` | S-P0-A ws:heartbeat write | `DispatchDomainEventsJob.php` | No — fixed-key Cache::put, no user input |
| `f840c3ef5` | CVP0-1 NF525 Ansible REVOKE | `site.yml`, `group_vars/all.yml` | No — static YAML literals into REVOKE statements |
| `f225e63b5` | S-P0-J webhook_events FK | migration + `idempotency.php` | No — parameterized whereNotIn, FK DDL, static route strings |
| `935eaca25` | M-R3-P0-C+D+E RBAC trio | 3 files | No — see detailed analysis below |
| `6a01c71bf` | M-R3-P0-A PermissionController gate | controller | No — strict tightening (removes `->only`) |
| `4b12f678a` | C-P0-H idempotency 18 routes | `idempotency.php` | No — 18 static route patterns |
| `32395b625` | C-P0-E BranchScope sentinel | test file | Skipped (test-only) |
| `162b179cf`, `189db206b` | BRAIN docs | `.md` files | Skipped (docs) |

---

## Detailed analysis — RBAC trio commit `935eaca25`

The most security-critical of the 11. Each of the 3 sub-heals analyzed for bypass potential:

### `RoleRequest`: `Rule::notIn(['Tenant Admin'])`
- Considered case/collation bypass (`'tenant admin'`, NBSP variants)
- Even if MySQL `utf8mb4_unicode_ci` allowed creating a near-equivalent name, Spatie's runtime `hasRole('Tenant Admin')` uses PHP-strict comparison on the hydrated collection → no shadow-role hijack
- **Sound.**

### `PermissionService::update`: self-role-modification guard
- `$caller->roles->contains('id', $role->id)` uses integer FK comparison
- Pivot via "modify another role then assign to self" is not API-reachable
- Every `assignRole(...)` call in the codebase (grep verified) uses hardcoded `EnumRole` constants from service-layer code
- No user-controllable role-assignment endpoint exists
- **Sound.**

### `AdministratorRequest`: `branch_id=0` gate
- `(int) $bid === 0` covers numeric variants (`"0"`, `"00"`, `"0e0"`, `"0.0"`)
- Array bypass blocked by `numeric` rule
- **Sound** for the literal-zero attack vector the commit closes.

---

## Scoped-out (noted but pre-existing — not introduced by these commits)

These weaknesses are flagged for V1.0.2 backlog awareness but are NOT regressions introduced by the heal:

- `AdministratorRequest` does not enforce "caller's own branch" — non-super-admins with `administrators_create` can still mint Admin-role users at arbitrary non-zero branches. Pre-existing weakness; the commit narrows scope (closes branch_id=0) but doesn't introduce it.
- `AdministratorService::store` unconditionally `assignRole(EnumRole::ADMIN)` — pre-existing design; heal commit does not modify it.

---

## Recommendation

**Merge-OK from a security perspective.** The 11 commits are uniformly defense-tightening (gates, guards, allowlists, FK enforcement, privilege REVOKE) and introduce no new attacker-controlled input paths.

**Substitute review limit:** this is NOT the official `/ultrareview` (multi-agent cloud review). The user should still run `/ultrareview 21` for the canonical billed cloud review when convenient — this doc is the autonomous-orchestrator best-effort substitute per user authorization 2026-05-18.

---

**Generated 2026-05-18 by `security-review` skill + general-purpose sub-agent.**
