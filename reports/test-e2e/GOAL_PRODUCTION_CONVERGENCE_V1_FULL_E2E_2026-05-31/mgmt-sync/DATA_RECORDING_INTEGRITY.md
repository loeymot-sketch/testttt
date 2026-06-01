# Data-Recording Integrity Sweep (owner's #2 concern: "is data well recorded, no bad organization?")
SELECT-only sweep over the live dataset (3388 orders accumulated under the soak), 2026-06-01.

## Verdict: ✅ data is well-recorded + well-organized. No live-flow recording bug.
| Check | Result |
|---|---|
| duplicate fiscal numbers | **0** |
| fiscal gap (per branch) | **0** (gap-free monotonic) |
| orphan order_items (FK) | **0** |
| order_items NULL composition_snapshot (recent) | **0** |
| NF525 chain | CHAIN OK |
| z-membership | OK (no cross-Z orphan) |
| payment_status distribution | 5(PAID):2010 · 15(counter-deferred):1243 · 10(unpaid):143 · singletons 0/1/20 (edge/refund) |

## The 3 flags — all explained, none a bug (verify-before-report)
1. **41 PAID orders without fiscal number** = pre-existing **seed/fixture artifacts** (21 pre-today + 20 from a 04:43 morning seed batch: token=NULL, identical 16.50€, factory-created bypassing the fiscal path). Documented P2 (prior audit "57 PAID fixtures"). The LIVE flow correctly allocates fiscal (the soak's PAID orders went 214→1955 gap-free). **z-membership OK → none inside a closed/signed Z → NF525-safe.**
2. **1 NULL/negative total (order 227, total −11.00, payment_status 20)** = a **REFUND** record — negative total is correct for a refund mirror, not bad recording.
3. **1 cash_movement vs order mismatch (cm #7 → order 227)** = type=**cashback** (+11.00 against the refund's −11.00) = correct refund/cash-out accounting; cashback rows are excluded from cash aggregates by design.

## Conclusion
Under sustained heavy load (3388 orders, 620 transactions, 1741 fiscal allocations during the soak), the management data layer records correctly and is well-organized: unique gap-free fiscal numbers, intact FK references, immutable composition snapshots, correct origin/payment-status classification, NF525 chain intact. The only "anomalies" are pre-existing test-seed fixtures (P2, owner cleanup) and correct refund accounting.
