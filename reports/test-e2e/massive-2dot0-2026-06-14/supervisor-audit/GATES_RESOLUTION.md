# OWNER GATES — "tous" healing pass (max reasoning)

**Date:** 2026-06-14 · Owner opened ALL gates ("tous max raisonning"). Each healed with verify-before-heal + TDD + frozen-check, OR refuted/escalated with reasoning. **No frozen file modified** (frozen services called read-only).

## ✅ HEALED (TDD red→green, frozen 0, committed)
| Gate | Sev | Fix | Commit |
|---|---|---|---|
| **G-DELIV-FISCAL** | **P0 (NF525)** | Allocate `fiscal_sequence_no` for COD delivery at DELIVERED (kiosk pattern, `fiscal_alloc_error_at` fallback, never rolls back the physical delivery) → the collected sale now enters the Z. `FiscalSequenceService` called read-only from non-frozen `OrderService`. | `deabb4ea2` |
| **G-DELIV-ORPHAN** | P1 | Block OUT_FOR_DELIVERY dispatch of a DELIVERY order with no `delivery_boy_id` (admin/OSS path); config-gated default-on. | `edadc66b2` |

## ❌ REFUTED (empirical — no heal shipped)
| Gate | Why refuted |
|---|---|
| **G-RUPTURE** (P1) | **Empirically un-reachable.** I built the guard + TDD, but the test exposed that the **quote layer already blocks branch-ruptured items** (`PosController::quote:41` → `UnavailableItemException` → 422) AND no-quote orders are rejected (quote required). The supervisor's 2/2 finding was *code-level-true* (PricingService has no rupture check) but its "submits, **charged**" claim is false — the submit is blocked. **Empirical > inference.** Guard + config + test REVERTED (no NO-OP/redundant heal). If a no-quote order-create path is ever opened, revisit (the guard is ready in git history). |

## 🟠 REMAINING — need an OPERATIONAL/PRODUCT decision (not a code-only fix)
| Gate | Sev | Status |
|---|---|---|
| **G-DELIV-CASH** | P1 | Needs the OWNER's operational model: must a driver ALWAYS have an open cash shift (→ strict-block DELIVERED with 422 if none) OR is shift-less COD allowed (→ record-anyway / variance-probe in reconcile)? The two fixes are mutually exclusive; pick the model and I heal it TDD. |
| **G-DELIV-REFUND** | P1 | **Partially enabled by G-DELIV-FISCAL** — a COD delivery now has a `fiscal_sequence_no`, so its refund can flow through the fiscal-aware counter-entry mirror (Z-netting) instead of being rejected at `RefundWithCounterEntryService:59`. Remaining piece = post a compensating OUT movement on the driver's cash session so reconcile nets the refund. Focused follow-up (touches the refund/cash path — best with fresh budget + the G-DELIV-CASH model decided, since they share the session-movement mechanism). |

## 🟠 STANDING owner gates (owner actions, unchanged)
G-WCAG (orange #F4501E contrast brand call) · G-FROZEN #16 (`AuditLogService` env() = UNI-03 cloud-prep, frozen) · G-PUSH (push spine) · G-OVH (deploy).

## Campaign tally (post "tous" pass)
**27 commits · 12 heals** (2 food-safety, 1 security, 3 numbers, 2 sync, 1 reliability, 1 correctness, **+1 NF525 fiscal (P0), +1 delivery-dispatch**). Vitest 2513/0, PHPUnit heal-areas green, **frozen 0**, **NF525 CHAIN OK**, not pushed.

**Meta-lesson reinforced:** verify reachability EMPIRICALLY before healing — even an adversarial-supervisor 2/2-confirmed finding (G-RUPTURE) was refuted by actually running it. The same discipline turned a near-miss NO-OP into an honest refutation.
