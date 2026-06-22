# Axis A4 — POS Vanilla Wizard (FROZEN) FINAL Verdict

**Date** : 2026-05-13 04:22 CEST
**Verdict** : GO-CONDITIONAL (frozen-zone intact, 1 P0 LOCK plan needed)
**Score** : 72/100
**Frozen-zone diff** : 0 lines vs HEAD~6 ✓

---

## §1 Round played

Single round, READ-ONLY (frozen-zone). No round 2 needed — no code change to validate.

## §2 Findings final state

| ID | Severity | Title | Status | Action |
|----|----------|-------|--------|--------|
| A4-P0-01 | P0 | Menu addon role mirror missing (A03-1 backlog persists) — 1.20-1.80€/order silent overcharge | **LOCK plan required** | Defer to FINAL_VERDICT recommendation. Frozen-zone touch needs LOCK + 2/3 adversarial validation per autonomy doctrine §20.4. NOT heal in this goal. |
| A4-P1-02 | P1 | Cayenne Sandwich fake Pain/Galette fallback (lines 698-703) | Defer Phase 13 live test | If composer-aware path consumes correctly, fallback never reached. Verify in mass E2E. |
| A4-P2-03 | P2 | Stale sauce list 17 vs 13 canonical | Defer V1.0.1 | Low risk (DB primary, fallback only when DB empty) |
| A4-P2-04 | P2 | No 'custom' template case in getAllowedSteps | Defer V1.0.1 | Composer-aware gate ensures bols flow works; explicit case would be defense-in-depth. LOCK plan. |
| A4-P3-05 | P3 | Idempotency-Key header emit unconfirmed | Defer Phase 13 | Verify in live E2E (curl + DB check). |

## §3 PASSING checks (8)

1. ✓ Frozen-zone integrity — 0 lines diff vs HEAD~6 across all 3 files
2. ✓ Composer-aware gate properly injected + consumed
3. ✓ `.env FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true` active
4. ✓ `buildStepsFromComposerProfile()` logic intact
5. ✓ `COMPOSER_STEP_KEY_MAP` + `COMPOSER_ADDON_ROLE_MAP` correct
6. ✓ Viande regex matches new attribute names
7. ✓ Sauce regex matches new attribute names
8. ✓ POS_WIZARD_CONFIG fallback prices align with kiosk

## §4 Decision rationale (autonomy §20.4)

The A03-1 P0 (menu addon role mirror) is a real bug with measurable financial impact (€1.20-1.80/order silent overcharge). However:

- Heal requires touching `public/js/pos-wizard.js` (FROZEN per CLAUDE.md §7)
- LOCK plan + 2/3 adversarial validation required
- The fix needs careful diff analysis to mirror kiosk E-001 (KioskWizardComponent.vue:1571-1610) into POS wizard
- Risk : a wrong heal could break the legacy wizard which is `parfait selon owner`

**Decision** : DEFER to FINAL_VERDICT.md as **P0-RECOMMENDED-LOCK** for owner review. Owner can :
- (a) Authorize LOCK plan with adversarial validation
- (b) Migrate Cayenne items to composer profile (then POS wizard never reaches the buggy code path)
- (c) Add backend price guard that rejects POS orders missing role=menu_* on menu addons

Path (b) is the cleanest — same approach as the new bols/frites which use composer profile + don't trigger the legacy menu-addon code path.

## §5 JSON FINAL verdict

```json
{
  "axis": "A4",
  "verdict": "GO-CONDITIONAL",
  "final_score": 72,
  "frozen_zones_diff_lines": 0,
  "p0_remaining_lock_required": 1,
  "p0_detail": "A03-1 menu addon role mirror — recommend backend price guard OR migrate Cayenne items to composer profile (instead of frozen-zone touch)",
  "p1_deferred_phase13": ["A4-P1-02 Cayenne fake Pain fallback verify"],
  "p2_deferred_V1_0_1": ["A4-P2-03 sauce list cleanup", "A4-P2-04 custom template case"],
  "p3_deferred_phase13": ["A4-P3-05 Idempotency-Key header verify"],
  "heals_applied_in_this_axis": 0,
  "lock_plans_pending_owner": 1
}
```

## §6 RESUME_TOKEN_AXIS_A4_FINAL_20260513-0422
