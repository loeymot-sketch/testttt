# PK-3 — Contract Integrity (POS-creates ↔ KDS-reads) — STATUS

**Wave** : Intersection POS×KDS Round 1
**Date** : 2026-05-18
**Master sub-agent** : PK-3
**Parallel siblings** : PK-1 DATA-FLOW, PK-2 STATUS-SYNC, PK-4 RESILIENCE-TZ

---

## TL;DR

- **Z-1 finding CONFIRMED** : `KDSOrderItemsResource:17-26` was silently dropping `allergens_snapshot` while `OrderItemResource:37` (used by `KDSOrderDetailsResource` for today-orders cards) shipped it. Two divergent payloads for the same chef-facing surface.
- **HEAL APPLIED** : 1-line addition to `KDSOrderItemsResource::toArray` + 1 new sentinel test file (`tests/Feature/KDS/KdsOrderItemsResourceAllergenExposureTest.php` — 3/3 GREEN).
- **GDPR `customer.name`** : flagged as P2 V1.0.X owner-decision per task brief — NOT healed in this round.
- **Branch isolation** : verified solid via 7 adversarial attack vectors (RED-team). All blocked.
- **No V1 blocker found**.

---

## 4-LIST (P0 / P1 / P2 / INFO)

### P0 (block V1)
*(none)*

### P1 (must-fix, healed inline here OR V1.0.X)
- **PK-3-ARCH-01 / RED-01** — KDSOrderItemsResource omits allergens_snapshot — **HEALED inline** (see Heal Commit Plan below)
- **PK-3-ARCH-03** — Items-board groupBy hash mixes JSON+sha1 encoding levels (architectural smell; sentinel tests pass) → **V1.0.X** refactor candidate
- **PK-3-SEC-02** — KDS branch isolation defense-in-depth verified — *no action*, documented
- **PK-3-SEC-03** — Items-board read endpoint has no per-route rate limit (global mitigation only) → **V1.0.X** (`kds:read` throttle 60/min)
- **PK-3-DBA-04** — In-PHP groupBy memory bounded at V1 scale, fragile at multi-tenant scale → **V2 backlog**
- **PK-3-RED-01** — Same as PK-3-ARCH-01 (Z-1 confirmation) — **HEALED**

### P2 (V1.0.X backlog)
- **PK-3-ARCH-02** — Three serializers without shared base class — extract trait or delegate items-board to a thin OrderItemResource subset
- **PK-3-ARCH-04** — Wire payload triple-encodes composition (snapshot + flat fields) — bandwidth optimization once all clients prefer snapshot
- **PK-3-SEC-01** — **GDPR customer.name still ships unconditionally** (Sprint 5A minimized only phone) — **OWNER-DECISION V1.0.X** per task brief
- **PK-3-SEC-04** — Allergen hash defensive normalize works (verified by sentinels) — no action
- **PK-3-DBA-01** — Items-board service does not eager-load `orderItem` (Item model) → **V1.0.X N+1 fix**
- **PK-3-DBA-03** — Composite `(branch_id, status, order_datetime)` index — V2 optimization at multi-tenant scale
- **PK-3-RED-03** — Whitespace in allergen codes splits lines spuriously (food-safety-positive direction) → V1.0.X trim() in `normalizeAllergensForHash`

### INFO (acknowledged, no action)
- **PK-3-ARCH-05** — customer.name minimization deferred to owner V1.0.X
- **PK-3-DBA-02** — Index coverage on hot KDS query: verified correct (idx_orders_branch_status + idx_orders_datetime)
- **PK-3-DBA-05** — Cast contract verified (`composition_snapshot`, `allergens_snapshot` both cast to `array`)
- **PK-3-RED-04** — Missing snapshot on legacy orders gracefully degrades via backfill command + null-safe normalize
- **PK-3-RED-05** — Composition snapshot tampering blocked by Pricing SSOT (NF525 invariant)
- **PK-3-RED-06** — Soft-deleted Item still resolves item_name via `withTrashed()` + null-safe op
- **PK-3-RED-07** — Replay attack on /change-status blocked by optimistic lock + lockForUpdate + 409 contract

---

## HEAL COMMIT PLAN

### Scope
- **1 file modified** : `app/Http/Resources/KDSOrderItemsResource.php` (NOT frozen — verified against `.cursor/hooks/safety-check.sh` FROZEN_ZONES list)
- **1 file added**    : `tests/Feature/KDS/KdsOrderItemsResourceAllergenExposureTest.php`

### Diff Summary
```diff
+ 'allergens_snapshot' => $this->safeJsonDecodeArray($this->allergens_snapshot),
```
(plus 11 lines of inline justification comment referencing Z-1 + service-layer allergen-hash split + cast contract)

### Verification
- **Sentinel test** : `KdsOrderItemsResourceAllergenExposureTest` — **3/3 GREEN** (`php vendor/bin/phpunit tests/Feature/KDS/KdsOrderItemsResourceAllergenExposureTest.php` → `OK (3 tests, 9 assertions)`)
- **No regression** : Sister `KdsSnapshotImmutableTest` shows 3/4 pass; the 1 failure (`test_kds_items_board_keeps_distinct_addon_choices_unmerged`) is the **pre-existing TZ regression flagged in task brief**, NOT introduced by this heal. Same TZ failure also visible in `KdsAllergenAggregationSplitTest` (5 pre-existing failures per task brief). Out-of-scope for PK-3.
- **Frontend impact** : `admin-kds.js` line 4054 already calls `Array.isArray(item.allergens_snapshot)` — the bundle gracefully handled absent field (returned `false`, never rendered). Adding the field as `[]` (legacy null path) or `["gluten"]` (with data) is a strict superset — no Vue change, no rebuild required for items-board column.
- **Backward compat** : Adding a key to JSON response is consumer-tolerant (admin-kds.js + Vue templates ignore unknown keys; presence is what matters).

### Commit Message (proposed)
```
fix(kds): expose allergens_snapshot on items-board resource (PK-3 / Z-1)

KDSOrderItemsResource was silently omitting allergens_snapshot while
OrderItemResource (used by KDSOrderDetailsResource for today-orders cards)
exposed it. Two divergent contracts for the same chef-facing surface —
confirmed during Wave 1 Intersection POS×KDS audit (PK-3).

The service-layer (KitchenDisplaySystemOrderService::orderItems lines
297-326) already splits merged KDS lines by allergen-hash for food
safety; the data WAS present in the model row but dropped at
serialization. OrderItem model casts allergens_snapshot to 'array',
so safeJsonDecodeArray handles both already-decoded and legacy
JSON-string shapes defensively.

Sentinel: tests/Feature/KDS/KdsOrderItemsResourceAllergenExposureTest.php
(3/3 GREEN). No frozen-zone touch. No NF525 chain impact.
```

---

## OUT-OF-SCOPE / DEFERRED (per task brief)

| Concern | Severity | Disposition |
| --- | --- | --- |
| `KDSOrderDetailsResource.customer.name` GDPR redaction | P2 | OWNER-DECISION V1.0.X (Sprint 5A minimized only phone) |
| 5 pre-existing `KdsAllergenAggregationSplitTest` failures (TZ) | P1 | V1.0.X (sister of Wave 3b commit `148dbebce`) |
| 1 pre-existing `KDSAllergenVisibilityTest` failure | P1 | V1.0.X (same TZ regression family) |
| Service file `KitchenDisplaySystemOrderService.php` in dirty list | n/a | NOT TOUCHED — read-only audit honored |
| `BranchScope.php` FROZEN | n/a | NOT TOUCHED — read-only confirmed |

---

## DELIVERABLES

- `reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-3-CONTRACT-SERIALIZERS/architect.json`
- `reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-3-CONTRACT-SERIALIZERS/security.json`
- `reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-3-CONTRACT-SERIALIZERS/dba.json`
- `reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-3-CONTRACT-SERIALIZERS/red.json`
- `reports/audit/intersection-pos-kds-2026-05-18/round-1/PK-3-CONTRACT-SERIALIZERS/STATUS.md` (this file)
- **HEAL** : `app/Http/Resources/KDSOrderItemsResource.php` (modified — +1 wire field + 11 lines of inline doc)
- **SENTINEL** : `tests/Feature/KDS/KdsOrderItemsResourceAllergenExposureTest.php` (new — 3 tests, 9 assertions, all GREEN)

---

## VERDICT

**Contract integrity HEAL-CLEAN.** Z-1 finding closed. No V1 blocker. P2 GDPR concern surfaced as owner-decision V1.0.X. Branch isolation verified solid across 7 adversarial vectors.
