# GOAL — Second-Degree / Indirect Functions — CONVERGENCE FINAL

**Date:** 2026-06-01 · **Branch:** `heal/cms-pr1-quickwins-2026-05-18` · **HEAD:** `6875a0d4b` (audit baseline `47970b4b7`)

Owner goal (supervisor): work on the **never-tested-in-depth, indirect/second-degree** functions —
historical summarization & calculation (all business numbers, all products historical, all
orders/commands historical), PLUS the **loyalty card** and the **delivery address**.
Concrete rules: restaurant at **437 Rue Élie Gruyelle, 62110 Hénin-Beaumont**; delivery fee
**5€ for ≤5km, +1€/km beyond**.

## Verdict: DECOMPOSED → ADVERSARIALLY AUDITED → 9 P0/P1 HEALED (TDD) + owner decisions executed.
- **Decomposition first** (owner's literal step 1): 9 sub-systems × 6 calc failure-mode lenses → `DECOMPOSITION.md`.
- **Adversarial audit** (workflow `wfaxuj9ie`, 61 agents, 4.25M tok, read-only, ×3-skeptic verify): **37 findings** (1 P0→P1, 16 P1, 12 P2, 8 P3) → `FINDINGS.md`. One claim (SALES-NET-02) refuted by the completeness critic + dropped.
- **Owner decision gate** (AskUserQuestion, 4 questions) → all 4 resolved → executed.
- **9 commits**, all TDD RED→GREEN, **frozen-zone diff = 0**, **NF525 CHAIN OK** throughout.

## Owner decisions (2026-06-01) → outcomes
| Decision | Answer | Outcome |
|---|---|---|
| CA / cash netting semantic | **Net, agree with the Z** | DASH-NET-01, DASH-SEM-03, SALES-NET-01, CASH-* healed to net-realized |
| Loyalty (fractional + partial clawback) | **Fix both** | LOY-SEM-02 healed; LOY-SEM-03 verified DORMANT (no V1 partial-refund path) → ships with that feature |
| Delivery "+1€/km" | **Whole km, rounded up** | backend formula + frontend preview + branch config aligned (ceil) |
| ZRPT-SEM-01 fiscal fix | **Author LOCK + fix + test** | LOCK authored, fix in non-frozen RefundWithCounterEntryService, two-window test — **PENDING OWNER COUNTERSIGN** |

## Commits (9)
| HEAD | Wave | Finding(s) | Test |
|---|---|---|---|
| `ae090a911` | Delivery origin/fee | DEL-ORIGIN-01, DEL-FEE-01 | DeliveryOwnerRuleHeninBeaumontSentinelTest |
| `e29220b3e` | Reports quick-wins | DASH-SEM-02, CREDBAL-NET-01 | SalesSummaryAvgPerDayDivisor + CreditBalanceExportFullFetch |
| `02f4ad54e` | Delivery whole-km | DEL-FEE (whole-km) + DEL-FEE-DISPLAY-01 | sentinel + vitest (deliveryCharge) |
| `521ade847` | Loyalty | LOY-SEM-02 | KioskRedeemWholePointSnapSentinelTest |
| `20d8d504f` | Dashboard netting | DASH-NET-01, DASH-SEM-03, eodSynthesis | DashboardRevenueNettingSentinelTest |
| `2eaef7564` | Sales report | SALES-NET-01 | SalesReportNetTotalSentinelTest |
| `f83f8022f` | Items report | ITEMS-SEM-01/02, NET-03, SEM-04 | ItemsReportUnitsSoldSentinelTest |
| `a010e29e9` | Cash reconciliation | CASH-JOIN-01, CASH-SEM-02 | CashOverviewControllerTest (+sentinel) |
| `6875a0d4b` | Fiscal TVA netting | ZRPT-SEM-01 | RefundDiscountTvaNettingTwoWindowSentinelTest |

## The central theme healed: refund/cancel netting consistency
A single net-realized semantic (`Order::scopeRealizedRevenue` + `Order::isRealizedRevenueRow`,
mirroring the signed Z's `LOCK_ZREPORT_REFUND_NETTING`) now governs **every** money surface —
dashboard CA, EOD recap PDF, sales report (card + PDF), items report, cash overview — so a
cancelled-but-paid order drops out and a refund nets to ~0, consistently and in agreement with
the signed Z. Counts exclude refund counter-entry mirrors (no longer "placed orders").

## Gate evidence
- Frozen-zone diff (13 §7 files) `47970b4b7..6875a0d4b` = **0 lines**. `ZReportService` (frozen) untouched; the ZRPT fix is in non-frozen `RefundWithCounterEntryService` under LOCK.
- `php artisan fiscal:verify-chain --all` → **CHAIN OK** (1 branch).
- Targeted-broad PHPUnit GREEN per wave; final full-suite gate: see `regression-full.txt` / commit-time runs (Fiscal 204/0, Dashboard+Reports+Pos+Order+Unit 202/0, Delivery 42/0, Cash 17/0, Loyalty+Pricing green).
- Delivery branch live-DB verified: `branches.id=1` = 50.4215667/2.9549060, base=5/per_km=1/min=5/free_km=5.

## Remaining / owner-gated (not blindly healed)
- **ZRPT-SEM-01 countersign** — owner reviews LOCK §6 + authorizes the mirror tax_amount scaling.
- **LOY-SEM-03 (partial-refund pro-rata clawback)** — dormant (no V1 partial-refund path; verified all 3 RefundCreated dispatch sites pass empty refundedItems). Ship WITH the partial-refund feature (V1.0.2).
- **DEL-GEOCODE-DEFAULT-OK-03 (P3)** — deferred: changes the order-blocking path (fail-closed could reject legacy NULL-geocode addresses); regression risk > value this cycle.
- **P2/P3 backlog** (12 P2 / 8 P3): CREDBAL-SEM-02/SOFT-03/PAR-04 (report shows all users / soft-deleted / cached balance), DASH-SEM-04 (channelStatistics mirror bucketing), SALES-PAR/SEM-03/04/05, ZRPT-SEM-02 (cross-Z orphan, detect-only), ZRPT-SEM-03 (delivery-charge 0%-VAT HT split), DEL-FEE-LEGACY-INCONSISTENT-02, topCustomers all-time unfiltered (critic). All documented in `FINDINGS.md`.

## No push (owner gate). ZRPT-SEM-01 awaits countersign.
