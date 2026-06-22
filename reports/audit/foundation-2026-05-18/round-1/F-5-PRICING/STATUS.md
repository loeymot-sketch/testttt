# F-5 PRICING SSOT — Foundation Audit STATUS

**Date** : 2026-05-18
**Round** : 1
**Mode** : READ-ONLY (FROZEN strict)
**Distinctive angle** : duplication topology — find what bypasses SSOT, not re-verify what Z-5 attested.

---

## 1. EXECUTIVE VERDICT

- **P0 in frozen file (`PricingService.php`)** : **0**
- **Owner Gate G3 trigger** : **NO**
- **Aligned with Z-5 attest** : YES — 109/109 + 10/10 PASS, invariants preserved.
- **New angle vs Z-5** : duplication topology + SSOT bypass surfaces. Z-5 verified invariants pass; this audit found that invariants pass *because the legacy duplicate paths are pinned-off by a single env flag*. The math is currently SSOT, but the codebase still carries ~595 LOC of legacy duplicate math gated by `config('pricing.use_ssot_service')`. A single env-flip in production restores it.

The Pricing namespace itself is well-architected (immutable I/O, no env() reads, 0 writes, single entry via `calculateOrder`). All issues live OUTSIDE the namespace, in the order-creation services that pre-date the SSOT extraction.

---

## 2. THE FOUR LISTS

### 2.1 P0 — block V1
**None.** Zero P0 in frozen file. Owner Gate G3 not triggered.

### 2.2 P1 — V1.1 hardening, no V1 blocker
1. **F5-ARCH-01 / F5-SEC-02** — Legacy fallback path duplicates ~595 LOC of pricing/tax/snapshot logic across 4 sites (OrderService web/POS/table + FrontendOrderService kiosk). Gated by `config('pricing.use_ssot_service', true)` which is pinned by `PricingSsotFlagProductionStableSentinelTest` in 5 deployable artefacts. Risk = single env-flip in production restores legacy math; future SSOT-only patches create drift windows. C.5 cleanup plan already owns this (`plans/caisse-v1-ultra-finition/PHASE_C_BACKEND_SECURITY_2026-04-26.md`).
2. **F5-SEC-01** — `CouponCheckResource` trusts client-supplied `$request->total` for percentage coupon preview (`app/Http/Resources/CouponCheckResource.php:39`). Preview-only — zero fiscal impact at order creation (PricingService re-validates server-side). Leaks coupon existence, maximum_discount caps, minimum_order thresholds. Recommendation : recompute subtotal server-side from `$request->items` payload in preview path.

### 2.3 P2 — defense-in-depth gaps, V1.1 nice-to-have
3. **F5-ARCH-02** — `menuRoleAdjustedAddonPrice` duplicated between `PricingService.php:793-813` and `CompositionSnapshotBuilder.php:171-191`. Intentional — kept local for "pure transformer" snapshot builder. Locked-step via shared `config('kiosk.menu_pricing')`. V1.1 ask : extract `MenuFormulaRatioCalculator` value object.
4. **F5-SEC-03 / F5-DBA-01 / F5-RED-03** — `order_items.composition_snapshot` has NO database-level immutability guard. Plain JSON nullable column, in `$fillable`, with `'array'` cast, no boot() updating listener, no BEFORE UPDATE trigger. Immutability enforced ONLY by call-graph convention (grep `->composition_snapshot\s*=` returns 0 matches, all 6 known writes are insert/copy-forward). NF525 §V vulnerability vs. an inadvertent future PR. Hardening : add OrderItem boot() updating listener + optionally a BEFORE UPDATE MySQL trigger mirroring `audit_logs` BEFORE DELETE pattern.
5. **F5-RED-06** — SSOT flag downgrade attack (`PRICING_USE_SSOT=false` in compromised .env) bypasses CI sentinel. v1.1 ask : boot-time assertion in `AppServiceProvider` blocking the flag from being false in production.

### 2.4 P3 / INFO — observations
6. **F5-ARCH-03** — `TaxCalculator` and `CompositionSnapshotBuilder` instantiated inline (`new \App\Services\Pricing\X()`) in 4 + 4 places inside the legacy paths. Self-resolves with C.5 cleanup.
7. **F5-ARCH-04** — `PricingService::calculateOrder` is a 335-line god-method. Test coverage (119 cases) compensates; post-C.5 it could be decomposed into stage objects.
8. **F5-DBA-03** — Asymmetric `json_encode` discipline : mass-insert paths use `json_encode($snapshot)` manually (cast bypassed); refund copy-forward uses `OrderItem::create` (cast applies, reads decoded array → re-encodes on save). Correct today, fragile to future maintainer. Document in `CompositionSnapshotBuilder` docblock.

---

## 3. DUPLICATION TOPOLOGY (this audit's distinctive angle)

### 3.1 Pricing/Tax/Snapshot math outside `App\Services\Pricing`

| Pattern | Locations | Status |
|---|---|---|
| Per-item price recomputation (`$dbVar->price * $varQuantity`) | OrderService.php legacy paths × 3 (web/POS/table) + FrontendOrderService.php legacy path (kiosk) | **Duplicated × 4** under pinned-off flag |
| Tax-inclusive/HT branching (`config('pricing.tax_inclusive_prices', false)` + TaxCalculator vs inline `round($verifiedTotalPrice * $taxRate / 100, 2)`) | Same 4 legacy paths | **Duplicated × 4** under pinned-off flag |
| Composition snapshot building (`new CompositionSnapshotBuilder()->build(...)`) | Same 4 legacy paths | **Duplicated × 4** under pinned-off flag (uses the builder, but does so inline) |
| Manual coupon discount math (CouponService::calculateDiscountAmount call) | Same 4 legacy paths | **Duplicated × 4** under pinned-off flag |
| Menu role ratio (`menuRoleAdjustedAddonPrice`) | PricingService.php:793-813 + CompositionSnapshotBuilder.php:171-191 | **Duplicated × 2** intentional, lock-stepped via config |
| Coupon percentage preview (`$request->total * discount / 100`) | CouponCheckResource.php:39 | **Single instance**, client-trust bug — see F5-SEC-01 |

### 3.2 `composition_snapshot` write topology — 6 sites verified

| Site | Pattern | Builder source |
|---|---|---|
| PricingService.php:291 | INSERT (SSOT path) | `$this->snapshotBuilder->build(...)` |
| OrderService.php:466 (web legacy) | INSERT (legacy) | `(new CompositionSnapshotBuilder())->build(...)` |
| OrderService.php:821 (POS legacy) | INSERT (legacy) | `(new CompositionSnapshotBuilder())->build(...)` |
| OrderService.php:1277 (table legacy) | INSERT (legacy) | `(new CompositionSnapshotBuilder())->build(...)` |
| FrontendOrderService.php:441 (kiosk legacy) | INSERT (legacy) | `(new CompositionSnapshotBuilder())->build(...)` |
| RefundWithCounterEntryService.php:136 | COPY-FORWARD | `$item->composition_snapshot` (parent verbatim) |

All 5 INSERT sites delegate to `CompositionSnapshotBuilder::build` → snapshot logic itself is **not duplicated**. The duplication is in the *call sites + surrounding math*, not the snapshot building. Refund copy-forward is correct per NF525 §V (chain reads identical bytes).

### 3.3 SSOT bypass surfaces — searched and verified absent

| Bypass type | grep pattern | Matches outside Pricing/known services |
|---|---|---|
| Direct `OrderItem::insert` outside known services | `OrderItem::insert\|OrderItem::create` in `app/` | 11 matches, all in PricingService / OrderService / FrontendOrderService / RefundWithCounterEntryService (the 4 sanctioned services) |
| Direct `Order::create` / `FrontendOrder::create` outside known services | `(Order\|FrontendOrder)::create\(` in controllers | 0 controller-level bypass |
| Client `$request->total` trust | `request->(total\|subtotal\|total_tax)` | 2 matches — CouponCheckResource + CouponService::couponChecking (both preview-only) |
| `env()` calls in Pricing namespace | `env\(` in `app/Services/Pricing/` | **0 matches** — clean |
| Post-insert composition_snapshot reassignment | `->composition_snapshot\s*=` | **0 matches** — convention-enforced immutability |

---

## 4. V1.1 HARDENING RECOMMENDATIONS (priority-ordered)

1. **Land C.5 — Remove legacy pricing fallback path.** Eliminates F5-ARCH-01, F5-ARCH-03, half of F5-SEC-02. Net : ~595 LOC removed, SSOT becomes the only path, sentinel becomes a removal-witness test. Largest ROI.
2. **Add OrderItem boot() updating listener blocking `composition_snapshot` mutation.** Eliminates F5-SEC-03 / F5-DBA-01 / F5-RED-03. Cheap and high-confidence.
3. **Recompute subtotal server-side in CouponCheckResource preview.** Eliminates F5-SEC-01. Share preload logic with PricingService::calculateOrder.
4. **Boot-time assertion in AppServiceProvider blocking `PRICING_USE_SSOT=false` in production.** Eliminates F5-RED-06 runtime exposure. Cheap defense-in-depth.
5. **Extract `MenuFormulaRatioCalculator`.** Eliminates F5-ARCH-02. Lock-step duplication retired, no functional change.
6. **(Post-C.5)** Decompose `PricingService::calculateOrder` into stage objects (Preload / PerLine / Finalize). Improves contributor onboarding. Not load-bearing.

---

## 5. ALIGNMENT WITH Z-5 (GOAL COMPLEMENT)

| Aspect | Z-5 finding | F-5 finding | Conflict? |
|---|---|---|---|
| P0 in frozen `PricingService.php` | 0 | 0 | No |
| Tests pass | 109/109 + 10/10 | Not re-run (read-only audit) | No |
| Owner gate G3 | NOT triggered | NOT triggered | No |
| Composition snapshot 5 + 1 sites | Reconciled | Reconciled (see §3.2) | No |
| New finding angle | Invariants verified | Duplication topology + structural fragility | Complementary — Z-5 attested behavior, F-5 attested **structure** |

**Net conclusion** : F-5 confirms Z-5's verdict (no V1 blocker) and adds the structural observation that *the SSOT property is preserved by configuration, not by code shape*. C.5 cleanup converts configuration-enforced SSOT into code-enforced SSOT.

---

## 6. DELIVERABLES

- `reports/audit/foundation-2026-05-18/round-1/F-5-PRICING/STATUS.md` (this file)
- `reports/audit/foundation-2026-05-18/round-1/F-5-PRICING/architect.json` — structural duplication, SSOT topology
- `reports/audit/foundation-2026-05-18/round-1/F-5-PRICING/security.json` — bypass surfaces, client-trust, flag downgrade
- `reports/audit/foundation-2026-05-18/round-1/F-5-PRICING/dba.json` — composition_snapshot column / cast / 6 write-site reconciliation
- `reports/audit/foundation-2026-05-18/round-1/F-5-PRICING/red-team.json` — 8 adversarial attempts, 5 blocked / 1 partial / 2 unblocked-but-unexposed

---

**End STATUS — F-5 Pricing SSOT round-1 audit.**
