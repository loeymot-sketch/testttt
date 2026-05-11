# Wave E — Round 4 Verification Cycle

**Date:** 2026-05-11
**Wave:** E (rush-hour-50x50 PHASE 2)
**Round:** 4 (verification — no fix wave)
**Spec:** `tests/e2e/test-e2e-rush-hour-50x50-2026-05-10-wave-E.spec.js`
**REPORTS_DIR bump:** round-3 → round-4 (preserves round-3 sidecars)

---

## 1. Spec Exit

- **Result:** 1 passed
- **Wallclock:** 133s (spec internal) / 2:18 total invocation
- **PNGs:** 21/20 captured
- **No retries, no fix attempts needed**

---

## 2. Round-3 Findings — Verification Status

### E-001 (P0 — `unexpected_4xx` admin toggle item 362) — **STILL CLOSED**

`wave-E-toggle-responses.json` round-4:
```
[1] /api/admin/menu/availability/toggle         status=200 ts=1778462565836  (state04)
[2] /api/admin/menu/availability/extra/toggle   status=200 ts=1778462567157  (state05)
[3] /api/admin/menu/availability/toggle         status=200 ts=1778462651192  (state18 restore)
[4] /api/admin/menu/availability/extra/toggle   status=200 ts=1778462652300  (state19 restore)
```
**4/4 toggles HTTP 200.** Cluster-8 fix (commit 8c3594bf3, `PersistCatalogChangedToOutbox.php:97-115`) holds across rounds. Visual state04 confirms admin row 362 toggled successfully (`toggle_visible=true`, `elapsed=107ms`).

**Status:** PASS (no regression).

### E-002 (P1 — `silent_error` admin UI no toast) — **STILL CLOSED (side-effect)**

Closes by-side-effect of E-001: when toggle returns 200, the admin UI updates correctly without need for an error toast. State04 captures admin row 362 with rupture state confirmed (`toggle_visible=true`).

**Status:** PASS (no regression).

### E-003 (P2 owner-gated — `audit_integrity` POS wizard supplement 175) — **SAME OBSERVATION**

Round-4 instrumentation at state07:
```
{"panel_present":true,"collapsed_class":true,"suppl_grid_present":true,"suppl_options_count":6}
```
Jambon de dinde button DOM probe:
```
{"found":true,"wizard_mounted":true,"disabled":false,"ariaDisabled":null,
 "pointerEvents":"auto","opacity":"1","hasUnavailableClass":false,"hasUnavailableText":false}
```
Identical to round-3: `pos-wizard.js` (FROZEN §7) has no `extra.is_available` rendering branch. Spec instrumentation is correct and empirically captures the absence of any disabled signal. Owner-gated per `OWNER_GATE_DECISIONS.md#E-003-deeper`. Does NOT block convergence.

**Status:** PASS (same observation, P2 owner-gated, instrumentation present).

### E-004 (P2 informational — KDS kiosk_instruction_matches=0) — **SAME OBSERVATION**

Round-4 state17 KDS observation:
```
{"card_count_total":20,"pos_token_matches":10,"kiosk_instruction_matches":0,"rupture_leak_count":0}
```
20/20 KDS cards present, 10/10 POS tokens visible, 0 kiosk special_instructions surfaced, 0 rupture leak. Identical to round-3.

**Status:** PASS (same informational observation, no fiscal/payment regression).

---

## 3. P0 Cascade Assertions — Round-4

| Assertion | Round-3 | Round-4 | Status |
|-----------|---------|---------|--------|
| E1 — POS item 362 cascade ÉPUISÉ | PASS (5ms) | PASS (5ms) | NO REGRESSION |
| E2 — Kiosk item 362 cascade filtered-out | PASS (4ms) | PASS (2ms) | NO REGRESSION |
| E4 — Kiosk wizard supplement 175 ÉPUISÉ | PASS (opacity 0.93) | PASS (opacity 0.906196, ÉPUISÉ text) | NO REGRESSION |
| E5a — POS API bypass item 362 | PASS (422) | PASS (422 stage=quote) | NO REGRESSION |
| E5b — POS API bypass extra 175 | PASS (422) | PASS (422 stage=quote) | NO REGRESSION |
| E5c — Kiosk API bypass item 362 | PASS (422) | PASS (422 stage=quote) | NO REGRESSION |
| E6 — Cascade lag p95 | 5ms | 6ms (n=4) | NO REGRESSION |
| E7 — Reversibility POS+Kiosk | PASS (2-3ms) | PASS (2ms POS / 6ms Kiosk) | NO REGRESSION |
| E8 — Numeric grid integrity | 5/5 | 5/5 (orders 1227-1231, fiscal_seq 284-288) | NO REGRESSION |
| Branch isolation | 0/5 off-branch | 0/5 off-branch | NO REGRESSION |
| Burst 10 POS + 10 Kiosk | 10/10 + 10/10 | 10/10 + 10/10 | NO REGRESSION |

---

## 4. Set-Equality Verdict

Round-3 finding set: `{E-001 closed, E-002 closed, E-003 open P2 owner-gated, E-004 open P2 informational}`
Round-4 finding set: `{E-001 closed, E-002 closed, E-003 open P2 owner-gated, E-004 open P2 informational}`

Round-4 `wave-E-findings.json`:
```
{"count_p0": 0, "count_p1": 0, "findings": [E-003-extras-no-is-available-render P2 owner-gated]}
```

**SET-EQUALITY: CONVERGED.**

- open_P0 round-3 = 0, open_P0 round-4 = 0
- open_P1 round-3 = 0, open_P1 round-4 = 0
- open_P2 round-3 = 2 (E-003, E-004 both owner-gated/informational), open_P2 round-4 = 2
- All round-3 closures (E-001, E-002) hold in round-4 with identical evidence pattern (4×200 toggles, visual state04 confirmation)

---

## 5. Recommendation

**DECLARE PHASE 2 GREEN — CONVERGENCE ACHIEVED.**

Per `CONVERGENCE_RULES.md`:
- 2 consecutive GREEN rounds with set-equality: SATISFIED (round-3 GREEN + round-4 GREEN, identical finding set)
- open_P0 = 0 in both rounds
- open_P1 = 0 in both rounds
- Only owner-gated P2 (E-003) and informational P2 (E-004) remain — explicitly non-blocking per `OWNER_GATE_DECISIONS.md`

No further audit rounds required. Wave E rush-hour-50x50 PHASE 2 may be declared GREEN.

Outstanding owner-track items (do not block Phase 2 GREEN):
- LOCK_E-003.md (FROZEN §7 `pos-wizard.js`) — owner sign-off needed for is_available rendering
- E-004 deferred to separate kiosk-KDS audit cycle (kiosk burst special_instructions propagation)
