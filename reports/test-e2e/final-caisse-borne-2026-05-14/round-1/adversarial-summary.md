# Adversarial Supervisor Summary — Wave CAISSE + BORNE Round 1

**Run**: `final-caisse-borne-2026-05-14` / round 1
**Reviewer**: adversarial-supervisor (claude-opus-4-7, visual-first per CLAUDE.md §13)
**Verdict**: **GO-CONDITIONAL**

## Top-line

Wave CAISSE is production-grade GREEN. Wave BORNE has insufficient capture coverage to certify alone — 1 of 10 scenarios visually captured, and only 3 of 6+ states for that scenario. Spec walker stalled at composer-aware wizard step 1 because generic `button:has-text('Suivant')` selectors don't drive required option selection per step. **DOM at BO-S1-03 proves wizard structure intact** (all 6 composer-aware step labels present: QUELLE VIANDE / SAUCE / CRUDITÉ / SUPPLÉMENT / MENU / RÉCAP), so the absent BO-S2 through BO-S10 captures reflect a test-helper gap, not a production regression — but visual evidence for 9 of 10 scenarios is unavailable.

## Findings (0 P0, 0 P1, 2 P2, 4 P3)

| ID | Sev | Wave | Category | Summary |
|---|---|---|---|---|
| FCB-R1-01 | P3 | BORNE | test_infrastructure_gap | Spec walker only captured BO-S1 (3 states), not BO-S2-S10 — composer wizard needs per-step option selection helper |
| FCB-R1-02 | P2 | BORNE | empty_state_quality | Big Chicken kiosk tile has no product image (bare `+` placeholder next to Chicken Burger with photo) — content gap for NEW V2 items 488/489/490 |
| FCB-R1-03 | P2 | BORNE | empty_state_quality | Kiosk wizard viande tiles render without images — degrades self-service UX |
| FCB-R1-04 | P3 | CAISSE | test_infrastructure_gap | SEC-3 idempotency + KDS/OSS API polls all 401 — page.request bypasses Sanctum Bearer interceptor |
| FCB-R1-05 | P3 | CAISSE | observability_gap | ui_receipt_total regex returns null 10/10 — payment-modal vs printed-receipt format mismatch |
| FCB-R1-06 | P3 | CAISSE | observability_gap | KDS/OSS UI card not visible at navigation time — polling cadence mismatch, db_domain_events confirms propagation |

## Claims verified (13)

- 10/10 POS scenarios placed with UI cart total = DB total = expected (exact decimal match)
- Fiscal sequence 325-334 strictly monotonic, gap-free (NF525 compliant); prompt context said 324-333, actual data and tracking JSON are consistent at 325-334
- All 10 orders payment_status=5 (PAID), branch_id=1 (BranchScope intact), composition_snapshot_present=true
- POS V4 sidebar: all 12 expected aria-labels present in DOM; all truncated pills (Sandwich Cayenne/Classique, Bols Gourmands, Menu enfant) carry both aria-label AND title attributes — A11y heal e7cb4578e re-verified in production DOM
- 5 visual heals confirmed: sidebar a11y, pos-app.js stubs (0 unhandled rejections), modal defensive close (before_close_click=true 10/10), pos-wizard.js frozen-zone integrity, POS V4 12-cat list
- **Paranoid check resolved**: Big Cayenne wizard fires composer 82 profile with Viandes 0/2 plural badge (CA-S2-02.png), NOT fallback 1-viande Vanilla heuristic. composition_lines=3 (V1+V2+Sauce) in DB confirms.
- Tacos M (Viande 0/1, 1 viande) and Tacos L (Viandes 0/2, 2 viandes) renamed correctly, prices 6.90€/7.90€
- Multi-cart CA-S10: DOM contains 3 distinct `pos-v5-cart-item__body` containers (Bowl Frites Poulet curry / Petite Frites / Tiramisu), cart total 15.20€ = DB 15.20€
- BORNE kiosk DOM: Menu enfant absent (heal-light V2 hiding correct)
- audit_logs.count baseline=26, post-run=26 (no Z-report fired mid-day, expected; HMAC chain head intact)
- Zero raw i18n labels across 88 DOM files; zero non-allowlisted console errors; zero 4xx/5xx network errors

## Claims disputed

- Prompt context claim "fiscal_seq 324-333" is off-by-one — actual data is 325-334. Cosmetic context drift; tracking artifacts themselves are self-consistent.

## Frozen-zone integrity

- `pos-wizard.js` diff vs HEAD = 0; commits touching it since 2026-05-13 = 0; 304-line diff vs main is pre-existing branch baseline. **INTACT.**

## Verdict rationale

CAISSE is fiscally and visually production-ready. Zero P0/P1 surfaced. All 5 prior heals re-confirmed in production DOM (not just in the report). The Big Cayenne paranoid worry from the prompt was decisively resolved — composer-aware path is active, no fallback wizard logic in play.

BORNE has 1 captured scenario (S1) with partial state coverage (01-03 of 06+). DOM proves wizard structure intact, but 9 of 10 scenarios have no visual evidence. This is verifiably a spec-helper coverage gap (selector strategy doesn't drive composer step required-selections), not a production regression — but I cannot certify what I haven't seen.

**Conditions for unqualified GO**:
1. BORNE re-run with composer-aware step-button selectors covering S1-S10 (V1.0.1 task), OR owner explicit waiver accepting "BORNE @ scenario-1 partial + DB-only validation for S2-S10"
2. Content task: upload product images for items 488/489/490 + viande item_attributes (FCB-R1-02/R1-03) — required pre-launch, not gating fiscal correctness

No P0/P1 blockers. All P3 findings are test-infra / observability gaps already self-disclosed in wave-CAISSE-report.md. NF525 chain intact. BranchScope intact. Frozen-zone integrity confirmed.

**Recommendation**: ship Wave CAISSE; schedule BORNE re-run with fixed walker before kiosk-prod cutover.
