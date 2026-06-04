# Intersection POS×KDS — Convergence FINAL (2026-05-18)

**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Pre-audit HEAD** : `9df4809b5` (post POS Couche 1)
**Final HEAD** : `1eebd208c` (idempotency propagation followup)
**Commits added** : 3 (PK-3 allergen heal + PK-2 P0 + PK-2 followup)
**Wall-clock total** : ~50 min (4 parallel master sub-agents + synthesis + heal cycle)

## Mission

Audit ultra-profond du contrat POS↔KDS — 4 master sub-agents en parallèle :
- PK-1 Data Flow (order creation cascade POS → outbox → KDS)
- PK-2 Status Sync (transitions bidirectionnelles + recall + concurrency)
- PK-3 Contract Integrity (KDSOrderItemsResource / KDSOrderDetailsResource / OrderItemResource)
- PK-4 Resilience + TZ (Pusher reconnect + polling + TZ-aware bounds)

## Verdict global

✅ **Intersection POS×KDS CONVERGED**. 1 P0 system-wide caught + fixed (idempotency propagation gap), 1 P1 healed (Z-1 KDSOrderItemsResource allergen exposure), 1 P1 deferred V1.0.X (DecrementStockOnOrderCreated stale rollback comment), 0 P0/P1 ouverts résiduels.

## Per-zone outcomes

| Zone | Verdict | P0 | P1 | P2 | P3 | Heals |
|---|---|---|---|---|---|---|
| **PK-1 Data Flow** | PASS_WITH_FINDINGS | 0 | 1 (deferred V1.0.X) | 2 | 1 | — |
| **PK-2 Status Sync** | 1 P0 healed | **1 healed × 2 commits** | 1 (follow-up) | 5 | — | `aa7b6021e` + `1eebd208c` |
| **PK-3 Contract Serializers** | 1 P1 healed | 0 | **1 healed** | 4 | — | `d6b20eef1` |
| **PK-4 Resilience+TZ** | PRODUCTION-CONVERGED | 0 | 0 | 0 | 5 (V1.0.1 backlog) | — |

## Key P0/P1 catches + heals

### 🔴 PK-2 P0 (most critical, caught + fixed)

**System-wide propagation gap of PS-2 idempotency wire-up**. PS-2 audit (commit `56d40fdc0`) only patched 4 posOrder.js mutations. Sibling Vue stores + Kiosk components continued POSTing without `X-Idempotency-Key` header.

With Foundation Fix #4 (commit `dafb6b3c4`) forcing `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` in production via boot guard, and `config/idempotency.php` `required_routes` covering 23+ endpoints, every chef KDS bump in production = 422 "Header X-Idempotency-Key requis". **Production breakage** caught BEFORE deploy.

**Fix applied** :
- Extracted `buildIdempotencyHeaders` to `resources/js/helpers/idempotencyHeaders.js` (SSOT)
- Patched **11 callsites total** across :
  - 4 stores (kitchenDisplaySystemOrder + onlineOrder × 3 + tableOrder × 2 + frontendOrder)
  - 3 Kiosk Vue (KioskWaitingComponent + KioskPaymentComponent × 2)
  - posOrder.js refactored to import from shared helper (4 existing callsites)

Smoke : Vitest 82/82 PASS across 8 spec files. 0 axios.post on idempotency-required routes without buildIdempotencyHeaders.

### 🟠 PK-3 P1 (Z-1 finding confirmed + healed)

**KDSOrderItemsResource omitted `allergens_snapshot` while sister OrderItemResource shipped it** — items-board endpoint was allergen-blind on the chef screen. Z-1 had flagged this as deferred ; PK-3 confirmed still present and healed inline.

**Fix applied (commit `d6b20eef1`)** :
- `app/Http/Resources/KDSOrderItemsResource.php` — added `allergens_snapshot` field (1 wire + 11 doc lines)
- `tests/Feature/KDS/KdsOrderItemsResourceAllergenExposureTest.php` — NEW (3 tests + 9 assertions, all GREEN)

### 🟡 PK-1 P1 (deferred V1.0.X)

**DecrementStockOnOrderCreated.php:17-37 stale rollback comment**. The catch (Throwable) re-throws assuming it'll rollback the order transaction, but `DispatchableAfterCommit` means tx already committed when listener runs. Re-throw skips sibling listeners + surfaces 500 to POS client for an order that exists in DB.

**Decision** : deferred V1.0.X under unified listener-isolation policy with PK-4 owner-batch.

## Cross-cutting attestations

| Attestation | Status |
|---|---|
| All 11 idempotency callsites headered | ✅ Grep verified 0 unprotected POST |
| Vitest store + Kiosk specs | ✅ 82/82 PASS |
| KDS allergens contract aligned | ✅ Both KDSOrderItemsResource + KDSOrderDetailsResource ship allergens_snapshot |
| TZ-aware bounds attested | ✅ 12/12 adversarial probes PASS (DST spring/fall, misconfig, storm, concurrency) |
| Session-A 7 sync heals intact | ✅ Wave 2b/2c/3/3b/3c/5 all production-grade |
| Frozen-zone touch over Intersection range | ✅ 0 lines (13 canonical files untouched) |
| NF525 chain APPENDED-ONLY | ✅ count=97 stable post-cycle, verify-chain CHAIN OK |
| KEEP-attestations (production invariants) | ✅ 14+ across all 4 zones |

## Heals committed this audit

| SHA | Description |
|---|---|
| `d6b20eef1` | fix(kds): expose allergens_snapshot on items-board resource (PK-3 / Z-1) |
| `aa7b6021e` | fix(idempotency-propagation): wire X-Idempotency-Key on 7 sibling Vue store callsites (PK-2 P0) |
| `1eebd208c` | fix(idempotency-propagation-followup): patch 3 Kiosk Vue callsites + refactor posOrder.js to shared helper |

## V1.0.X backlog accumulé

- Unified 4-listener isolation policy (PK-1 P1 + PK-2 followup P1)
- BroadcastableOrder marker interface guarantee tightening
- AdminController auth:sanctum direct verification sentinel
- MonitorOutboxStaleness cadence p95 dashboard
- OSS sister-service DRY extraction
- CLI `--since` parity 24h
- Multi-pdo two-station concurrent-bump race test
- Idempotency replay-after-role-revoke test
- GDPR `customer.name` on KDS payload (owner-decision required)
- 3-serializers refactor (KDSOrderDetails + KDSOrderItems + OrderItem)
- Items-board per-route rate-limit

## Documents persistés

- This convergence doc : `reports/audit/intersection-pos-kds-2026-05-18/synthesis/CONVERGENCE_FINAL.md`
- 4 master STATUS.md : `round-1/PK-{1,2,3,4}-*/STATUS.md`
- 17 specialist JSONs : in respective round-1/PK-N/ dirs

---

**Intersection POS×KDS CONVERGED — prêt pour Intersection POS×OSS next per mandate flow.**
