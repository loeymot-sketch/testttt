# Z-4 LIVREUR FULLSYS — STATUS

**Zone** : Z-4 Livreur fullsys (HEAL ALLOWED)
**GOAL doc** : `plans/GOAL_PRODUCTION_READINESS_COMPLEMENT_2026-05-18.md` §5
**Round** : 1+2 convergent
**Verdict** : **VALIDATED**
**Timestamp** : 2026-05-18

---

## Final commits

| SHA | Title |
|---|---|
| `04a9454f6` | `fix(livreur-z4): branch-aware delivery fee wire-up + status whitelist + RBAC split — LIVREUR-Z4-{ARCH-01..04,SEC-01}` |

Also persisted via orchestrator's add-all batch in commit `a27721d21` :
- `tests/Feature/Delivery/DeliveryFeeBranchWireupSentinelTest.php` (NEW, 4 tests)
- `tests/Feature/Delivery/DeliveryStatusTransitionWhitelistTest.php` (NEW, 4 tests)
- `tests/Feature/Delivery/DeliveryBoyAddressPermissionSplitTest.php` (NEW, 4 tests)
- `reports/audit/goal-complement-2026-05-18/round-1/Z-4-LIVREUR/{architect,security,uxa11y,dba,red}.json`
- `reports/audit/goal-complement-2026-05-18/deferred-heal/Z-4-LIVREUR/findings.json`

---

## Heal scope (3 bundled patches, scope-minimal)

### P0 — LIVREUR-Z4-ARCH-01/02/03 — DEL-5 wire-up (4 entry points)

**Root cause** : Sprint H3 DEL-5 (commit pre-2026-05-17) added per-branch
`delivery_fee_base / delivery_fee_per_km / delivery_fee_minimum` columns
(migration `2026_05_18_100000`) and extended `DeliveryFeeService::fromDistanceKm`
to accept an optional `?Branch $branch`. However the FOUR production call
sites dropped the Branch argument :

1. `app/Services/Delivery/DeliveryQuoteService.php:63` (customer saved-address)
2. `app/Http/Requests/OrderRequest.php:117` (customer legacy fallback elseif)
3. `app/Http/Requests/PosOrderRequest.php:28` (POS walk-in FormRequest)
4. `app/Http/Controllers/Admin/PosController.php:232` (POS /quote endpoint)

Every customer + POS DELIVERY quote returned the legacy
`max(5, ceil(d/5)*5)` regardless of branch config — DEL-5 was dead code
on the production happy path. Only the direct unit-test signature
exercised the branch-aware code.

**Heal** : resolve `Branch::find` from `branch_id` and pass to `fromDistanceKm`.
Null-safe : unknown `branch_id` cleanly falls back to legacy. Backward-
compat preserved (signature has default `null`).

### P1 — LIVREUR-Z4-ARCH-04 — Driver status whitelist

**Root cause** : `Frontend/DeliveryBoyOrderController::deliveryBoyOrderChangeStatus`
validated only `['required','integer']`. Out-of-range integer values
flowed to `OrderService` and were rejected by `ValidStatusTransition`
(state-machine — defense in depth), but contract should fail at the
request boundary.

**Heal** : explicit `in:8,10,13,22` whitelist (PREPARED, OUT_FOR_DELIVERY,
DELIVERED, RETURNED) at controller line 58. Mirrors KDS/OSS conventions.

### P1 — LIVREUR-Z4-SEC-01 — Address controller RBAC split

**Root cause** : `DeliveryBoyAddressController` applied `permission:delivery-boys_show`
to ALL methods including `store/update/destroy`. A user/role with
read-only delivery-boy access could mutate addresses (privilege
escalation risk).

**Heal** : split permissions per HTTP verb mirroring `DeliveryBoyController` :
`_create` on store, `_edit` on update, `_delete` on destroy, `_show`
on read.

---

## Test counts (both rounds GREEN)

| Suite | Round 1 | Round 2 |
|---|---|---|
| New sentinels (3 files) | 12/12 PASS | 12/12 PASS |
| Existing Delivery (8 files) | 33/33 PASS | 14/14 PASS (subset re-run) |
| Vitest delivery specs (2 files) | 14/14 PASS | (covered round 1) |
| Playwright E2E (1 spec, 3 tests) | 3/3 PASS | 3/3 PASS |

**Aggregate** : 12 new sentinels (16 assertions) + 33 existing PHPUnit
+ 14 existing Vitest + 3 Playwright captures = **62 tests / 62 GREEN**
across both rounds.

---

## Visual artifact paths

| Path | Description |
|---|---|
| `reports/test-e2e/goal-complement-2026-05-18/Z-4-LIVREUR/round-1/01-admin-delivery-boys-list.png` | Admin Livreurs list (FR, flat) |
| `reports/test-e2e/goal-complement-2026-05-18/Z-4-LIVREUR/round-1/02-admin-online-orders.png` | Admin online orders index |
| `reports/test-e2e/goal-complement-2026-05-18/Z-4-LIVREUR/round-1/03-admin-pos-orders.png` | Admin POS orders index |
| `reports/test-e2e/goal-complement-2026-05-18/Z-4-LIVREUR/round-1/qa-visual.json` | QA Visual analysis |
| `reports/test-e2e/goal-complement-2026-05-18/Z-4-LIVREUR/round-1/red-visual.json` | RED Visual adversarial |
| `reports/test-e2e/goal-complement-2026-05-18/Z-4-LIVREUR/round-2/01-03-*.png` | Round-2 captures (identical green) |

Visual gate : P0 + P1 = 0 across both rounds. QA + RED converged.

---

## Specialist audit reports (Round 1, fan-out)

| Role | Path | P0 | P1 | P2/P3 |
|---|---|---|---|---|
| Architect | `reports/audit/goal-complement-2026-05-18/round-1/Z-4-LIVREUR/architect.json` | 3 (bundled) | 1 | 2 |
| Security | `reports/audit/goal-complement-2026-05-18/round-1/Z-4-LIVREUR/security.json` | 0 | 1 | 3 |
| UX/A11y | `reports/audit/goal-complement-2026-05-18/round-1/Z-4-LIVREUR/uxa11y.json` | 0 | 0 | 2 (INFO/P2) |
| DBA | `reports/audit/goal-complement-2026-05-18/round-1/Z-4-LIVREUR/dba.json` | 0 | 0 | 2 |
| RED | `reports/audit/goal-complement-2026-05-18/round-1/Z-4-LIVREUR/red.json` | 1 (dispute escalation) | 2 | 2 |

All P0 + P1 either healed (5 IDs) OR documented as deferred-heal V1.0.2 backlog.

---

## Deferred-heal backlog (9 items → V1.0.2, 0 V1 blockers)

`reports/audit/goal-complement-2026-05-18/deferred-heal/Z-4-LIVREUR/findings.json`

| ID | Severity | Title |
|---|---|---|
| LIVREUR-Z4-ARCH-05 | P2 | Driver notification listeners lack idempotency on queue retry |
| LIVREUR-Z4-ARCH-06 | P3 | Generic 422 controllers leak raw `$exception->getMessage()` |
| LIVREUR-Z4-SEC-02 | P2 | Frontend driver routes have no `role:Delivery Boy` guard |
| LIVREUR-Z4-SEC-03 | P2 | No throttle on driver status-change endpoint |
| LIVREUR-Z4-SEC-05 | P2 | Driver status-change endpoint missing idempotency middleware |
| LIVREUR-Z4-DBA-03 | P2 | Missing composite index on `orders(delivery_boy_id, ...)` |
| LIVREUR-Z4-DBA-04 | P3 | Two near-identical COUNT queries in `deliveryBoyOrderCount` |
| LIVREUR-Z4-UX-02 | P2 | E2E spec uses fixed 1500ms `waitForTimeout` (flaky pattern) |
| LIVREUR-Z4-UX-03 | INFO | Hardcoded FR delivery-minimum error string |

Estimated V1.0.2 effort : ~4.5 days. Zero gating on V1.

---

## Frozen-zone + DIRTY discipline

- Frozen-zone diff = **0** (no CLAUDE.md §7 file touched)
- DIRTY file respected : `app/Services/OrderService.php` (session-A) → **NOT touched**.
  Read-only inspection at lines 1428-1655 + 2095-2181 confirmed
  `selectDeliveryBoy` BranchScope bypass is properly fenced (LIVREUR-Z4-SEC-04 P0-avoided)
  and cash-collection escrow is audit-logged. Any future heal targeting
  OrderService is V1.0.2 deferred-heal.
- NF525 chain unchanged (no `audit_logs` / `z_reports` mutation in heal).

---

## Convergence verdict

**VALIDATED** — 2 consecutive cycles P0+P1=0, identical findings set,
all sentinels GREEN, visual gate GREEN, deferred-heal backlog persisted,
frozen-zone + DIRTY rules respected.

---

## Final HEAD

`32395b62583bdb16c9db1fce8f7ef2195bc8c6de`

Heal commit `04a9454f6` will appear in the orchestrator's Phase 2 rebase-
aware merge into the target branch.
