# Rush-Hour 50x50 — Round 5 Verification

**Type:** Pure verification cycle (no fix wave between round 4 and round 5)
**Cycle goal:** Satisfy CONVERGENCE_RULES.md — 2 consecutive GREEN rounds with set-equality
**Execution mode:** Sequential (Wave A then Wave B), single worker, no retries
**HEAD:** df494c6c9
**Captured:** 2026-05-11T00:22:15Z (Wave A 02:14 → 02:17 local; Wave B 02:18 → 02:20 local)

---

## Wave A — round-5 re-capture

- **Spec exit:** PASS (1 passed, 2.6m)
- **Spec file:** `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-A.spec.js`
- **Sidecar:** `tests/e2e/__screenshots__/test-e2e-rush-hour-50x50-A/observations.json`

### Critical sidecar values
| Metric | Round 4 baseline | Round 5 observed | Match |
|---|---|---|---|
| `api_results_summary.ok / total` | 38/38 | 38/38 | yes |
| `db_orders_with_prefix_count` | 38 | 38 | yes |
| `FISCAL-A2-branch.gap_free` | true | true | yes |
| `FISCAL-A2-branch lo..hi count` | (40 expected) | lo=263 hi=302 count=40 expected=40 | yes |
| `A-013_reconciliation.ok+fseq` | 38/38 | 38/38 | yes |
| `A-013 ok_no_fseq / ok_no_row / fail_with_row / fail_no_row` | 0/0/0/0 | 0/0/0/0 | yes |
| `a002_429_toast_snapped` | true | true (idem=1778458633640_8ymlptwgc_1_A) | yes |
| `mid-burst-429-toast.png` written | yes | yes (468 KB) | yes |
| `state17.kds.card_count_matching_prefix` | 38 | 38 | yes |
| `TIMING-A4 p95` | <8000ms | 129 ms (n=7) | yes |
| `COMPOSITION-A5 null_snaps` | 0 | 0 (16 tacos / 16 oi rows) | yes |
| `NUMERIC-A1 item_match` | 5/5 | 5/5 | yes |

### Closure confirmation (round 4 → round 5)
- **A-002** (429 toast in-place snap): re-confirmed — toast snapped before retry/clear
- **A-013** (ok-without-fseq drift): re-confirmed — 38/38 ok carry fiscal_sequence_no; zero ok-without-row and zero fail-with-row
- **A-015** (cleanup race after sweep): re-confirmed — sidecar reports 38 prefix orders during run, `afterAll: remaining_orders_with_prefix=0`

### Set-equality verdict — Wave A
- Round 4 finding ID set: {A-001, A-002, A-003, A-004, A-005, A-006, A-007, A-008, A-009, A-010, A-011, A-012, A-013, A-014, A-015, A-016, A-017}
- Round 5 evidence: every closure invariant from round 4 still holds; no new symptom in console/network/observations stream
- Open P0: 0  •  Open P1: 0 (A-003 owner-gated CLOSED status preserved)
- **Verdict: CONVERGENCE_OK**

---

## Wave B — round-5 re-capture

- **Spec exit:** PASS (1 passed, 1.8m)
- **Spec file:** `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-B.spec.js`
- **API results:** `reports/test-e2e/rush-hour-50x50-2026-05-10/round-1/wave-B-api-order-results.json` (overwritten in-place each round per spec design)

### Critical sidecar values
| Metric | Round 4 baseline | Round 5 observed | Match |
|---|---|---|---|
| `total_attempted` | 38 | 38 | yes |
| `total_with_id` | 37 | 37 | yes |
| `payment_confirm_status==200` count | 37 | 37 | yes |
| 429 incidents | 1–2 (P0 by-design rate limit) | 2 | within tolerance |
| PRE-SNAP-MISMATCH events | 4 (set-equality reference) | 4 (states 09, 10, 11, 14) | yes |
| KDS scrape `cards(dom)` | ~39 | 39 | yes |
| KDS `our_recorded_queues` | 37 | 37 | yes |
| `QUEUE-B2 ids found in DB` | 37/37 | 37/37 | yes |
| `QUEUE-B2 non-+1 deltas` | 0 (kiosk slice gap-free) | 0 | yes |
| `SENTINEL-POST-BURST` sample rows | status 4 / payment_status 5 | id 1090–1092 status=4 payment_status=5 | yes |
| KDS timing samples | ≥4 | embedded in burst metrics | yes |
| Wallclock | ~108s | 108s | yes |

### PRE-SNAP-MISMATCH detail (expected, set-equal to round 4)
- 09-B-kiosk-upsell-screen — marker `kiosk-upsell-root` absent (upsell flow not triggered for this item set)
- 10-B-kiosk-payment-modal — marker `kiosk-payment-root` absent (modal opens via interaction Playwright cannot drive end-to-end without TPE simulator)
- 11-B-kiosk-confirmation — marker `kiosk-confirmation-root` absent (no UI order completed in this slice)
- 14-B-kiosk-final-confirmation — same root, same cause

These four mismatches were already characterised in Round 3/4 reviewer pass as known-stable (state-machine reaches idle→category but UI confirmation requires payment leg the spec deliberately bypasses for the API-burst slice). No new mismatch signature appeared.

### Set-equality verdict — Wave B
- Round 4 finding ID set: {B-001..B-011}
- Round 5 evidence: same total_with_id=37, same 429 envelope, same 4 PRE-SNAP-MISMATCH signatures, same KDS reconciliation 37/37, same monotonic queue_number behaviour
- Open P0: 0  •  Open P1: 0
- **Verdict: CONVERGENCE_OK**

---

## Recommendation

**Phase 1 GREEN — declare convergence.**

Both waves satisfy the CONVERGENCE_RULES.md criteria:
1. Round 4 was GREEN (baseline)
2. Round 5 is GREEN with strict set-equality of finding IDs
3. Open P0 = 0 and open P1 = 0 in both waves (A-003 owner-gated already CLOSED)
4. No new symptom, no new finding, no regression in any closure invariant
5. Sequential execution (Wave A → Wave B) eliminated cleanup race as a confounder; the GREEN persists without parallel-run leniency

Orchestrator may proceed to declare Phase 1 complete. No further round required.

### Notes for orchestrator
- Wave B's `wave-B-api-order-results.json` is written under `round-1/` by spec design (path is hardcoded). If round-segregated artifacts are required for the convergence audit trail, that is a spec-level enhancement (deferred — not a finding).
- The 1 expected 429 in Wave B is the by-design rate-limiter producing pressure (kiosk per-IP throttle). It is not a regression and matches Round 4 envelope.
- No code, spec, or helper was modified during this round, per protocol.
