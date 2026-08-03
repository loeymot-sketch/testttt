# Couche 0 Foundation — Convergence FINAL (2026-05-18)

**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Baseline pre-audit HEAD** : `ec0d49241` (Phase 0 GOAL Complement)
**Final HEAD** : `d3dc4c2c6` (BroadcastableOrder typehint fix)
**Commits cumulés this session** : 17 (+ ~50 parallel session-A commits)
**Wall-clock total** : ~3-4h

## Mission

Audit ultra-profond Couche 0 Foundation (9 systèmes + 1 cross-cutting hunter) + heal one-by-one P0 fixes + nettoyage dead code/duplication + root-cause des failures détectées.

## Verdict global

✅ **Couche 0 Foundation CONVERGED**. Tous les P0 originaux trouvés par les 10 sub-agents ont été soit fixés, soit explicitement déférés V1.0.X avec rationale. 4 failures pre-existing investiguées → 3 root-causes étaient ma propre régression (commit `80fb27c48` typehint trop strict), 1 stale test (BORNE-001 EN→FR translation), tous fixés.

## Inventaire commits this session

### P0 fixes Foundation (5/6 appliqués)

| # | SHA | Description |
|---|---|---|
| Fix #1 | `5bb8c48f9` | Stock import path (DecrementStockOnOrderCreated.php:6 — wrong namespace) |
| Fix #2 | `ccc95e862` | Stock triggers BEFORE DELETE/UPDATE migration (close raw-query bypass) |
| Fix #3 | `f0cafc3b8` | PushNotification tenant isolation (branch_id filter fan-out) + 3 sentinels |
| Fix #4 | `dafb6b3c4` | Idempotency middleware production boot guard + 4 sentinels |
| Fix #5 | — | **DEFERRED V1.0.X** — Two FCM clients (legacy=kitchen/POS topics, modern=user tokens — différentes audiences, pas spam duplicate, mais legacy API Google-deprecated Juin 2024). Needs sprint dédié migration topic→token. |
| Fix #6 | `2949e92ed` | CORS APP_URL production boot guard + 4 sentinels |

### i18n + dead code cleanup

| SHA | Description |
|---|---|
| `2c0b7e606` | Delete dead event-listener pair SendEmailVerification + listener (shadowed Laravel Illuminate import) |
| `0a1a01a16` | Remove 187 dead i18n keys (240 over-claimed audit, 53 saved as dynamic-template references) |
| `86656f1d1` | Fix 3 empty-key trailing dots (fr.json lines 287/656/1459) |
| `0ca67a9b3` | i18n cleanup STATUS report |
| `a64d2f523` | Delete dead CheckoutController.php (-30 lines) |
| `5469e82ba` | Delete dead SetLocale middleware (duplicate of localization.php, -98 lines) |
| `36089973d` | Delete one-shot FixIdentityCommand (Bangladesh→Paris DB recovery 2026-05-09, job done, -92 lines) |

### Receipt NF525 + bugfix

| SHA | Description |
|---|---|
| `80fb27c48` | feat(receipt-nf525) wire ReceiptDataService into POS receipt builder (OrderDetailsResource SSOT delegation) |
| `9a93df89c` | docs(receipt) tighten STATUS doc accuracy |
| `d3dc4c2c6` | **fix(receipt-foundation)** widen typehint from `Order` to `BroadcastableOrder` — closes F1+F2+F3 (had been silently 500ing every /api/frontend/order POST since 80fb27c48) |

### Failure investigations (4 sub-agents parallel)

| Investigation | Verdict | Fix commit |
|---|---|---|
| F1 DeliveryFeeBranchWireup | Root cause = ReceiptDataService Order typehint via OrderDetailsResource serialization | `d3dc4c2c6` |
| F2 OrderAllergenSnapshotComposed | Same root cause as F1 (FrontendOrder rejected by Order typehint) | `d3dc4c2c6` |
| F3 PosKioskPricingParity (3 cases) | Same root cause as F1 (kiosk POST 500 before parity assertion) — NOT a real Pricing SSOT divergence | `d3dc4c2c6` |
| F4 KioskDineInDisabledV1Sentinel | Stale test (EN substring pinned, BORNE-001 heal translated to FR) | `d0437d391` |

## Attestations finales

| Attestation | Status |
|---|---|
| NF525 chain APPENDED-ONLY | ✅ count=29 baseline → 97 → `fiscal:verify-chain` CHAIN OK |
| Frozen-zone diff over GOAL range | ✅ 0 lines on 13 canonical files |
| My 4 P0 fixes sentinels green | ✅ Stock 79/79 + PushNotif 3/3 + Idempotency 4/4 + CORS 4/4 |
| Receipt regression resolved | ✅ ReceiptDataServiceWireIn 5/5 (incl. new FrontendOrder sentinel) |
| All 4 failure investigations closed | ✅ F1+F2+F3+F4 root-caused + fixed |
| PHPUnit baseline | 514 files (was 499 at Phase 0) — +15 |
| Vitest baseline | 426 specs (was 413) — +13 |

## 🚨 Flag pour session-A — 10 pre-existing KDS failures

Confirmed via revert+rerun on baseline `ec0d49241` : ces 10 tests failed AVANT que je démarre. NOT caused by my work. Probablement sibling commits parallel session-A.

| Test class | Cases failing |
|---|---|
| `KDSDeliveryEnrichmentTest` | 3 (delivery payload includes address+customer / dine-in omits address / eager-loaded relations) |
| `KdsAllergenAggregationSplitTest` | 5 (same id same extras NOT merged / merged / null+empty / unsorted / cross-orders) |
| `KioskFullFlowE2ETest` | 1 (kiosk order full flow to KDS with variations extras allergens) |
| `KDSAllergenVisibilityTest` | 1 (kds endpoint exposes per-item allergens snapshot) |

**Recommandation pour session-A** : investigate KDS endpoint serialization (likely `KdsSyncController` or `KDSOrderDetailsResource` impacted by parallel work). Pas une priorité Couche 0.

## Tasks restantes

1. ❓ **Owner decision sur Fix #5** : keep as-is + V1.0.X migration sprint (recommended) OR delete legacy FCM listeners (kitchen/POS lose FCM but Echo+polling cover them) OR full topic→token refactor (1-3 days)
2. ❓ **2 NF525 prison-risk operational items** (DEPLOY doc only, pas code) :
   - SQL `REVOKE TRUNCATE` on prod DB user pour audit_logs/z_reports/cash_movements/etc.
   - mysqldump quotidien off-site + 6-year rétention
3. ⏳ **Couche 1 POS Caisse** — next mandate target (11 sous-systèmes, audit ultra-profond identique à Couche 0)

## V1.0.X backlog accumulé

- Fix #5 (FCM topic→token migration)
- 253 i18n duplicate values consolidation (top: "Annuler"×10, "Menu"×7, "Réessayer"×7)
- 240→187 dead key removal continuation (subtle dynamic references)
- 80 FormRequest authz unification (5/88 Wave 5H + 3/88 sibling 0c824ddbd already done)
- ReceiptDataService C.5 cleanup (legacy fallback 595 LOC gated by use_ssot_service=true flag)
- F-8 ShouldQueue on all notification listeners
- F-8 Idempotency keys on 6 listeners restantes (Catalog/Coupon/Availability×3/Table)
- F-9 320 occurrences `Log::info($exception)` → `Log::error` proper severity
- F-9 PII leak Auth::user()->name in ActionLog
- F-9 /health/ready 503 leaks ops info to anonymous
- 12 NF525 défense en profondeur items
- 4 V1.1 fiscal hardening items
- ~50 P2/P3 items across 9 foundation systems

## Pre-existing KDS failures (flagged to session-A)

10 failures pre-existing in KDS sphere (see "Flag pour session-A" section).

---

**FIN COUCHE 0 CONVERGENCE — prêt pour Couche 1 POS Caisse sur "go".**
