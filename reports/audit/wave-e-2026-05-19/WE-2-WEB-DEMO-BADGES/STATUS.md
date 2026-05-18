# WE-2 — Web Standalone "DÉMO V1" Badges — STATUS

**Wave**: E (CMS PR1 quickwins / G-WEB-1 Path A heal)
**Branch**: heal/cms-pr1-quickwins-2026-05-18
**Date**: 2026-05-19
**Verdict**: GREEN — 12/12 new + 40/40 existing = 52/52 cases pass
**Frozen-zone touch**: 0
**LOC delta**: ~9 lines (3 inline badge inserts, 0 CSS file edits)
**Wall-clock**: ~45 min (orientation 8min, advisor 4min, specialists 8min, TDD spec 8min, build 5min, test 7min, capture 4min, status 1min)

---

## Owner decision context

2026-05-19 reframe of G-WEB-1 LCS-A-002 (earlier "no loyalty UI" finding): owner clarified the web loyalty wallet UI **is** present at `/Users/1millnonstop/Downloads/web/screens.jsx::WebLoyalty` (181 LOC: QR + balance + redeem + tiers + history + achievements). The gap is API wireup (intentional V1 standalone).

**Customer-trust risk**: customer sees signup success modal "+25 points" → loyalty page shows "347 pts" + QR + "Présente ton QR à la caisse pour cumuler" → presents QR at POS → POS denies (disconnected). Trust failure.

**Owner chose Path A** (this task) over Path B (full API wireup, deferred V1.0.x roadmap): add explicit "DÉMO V1" disclosure badges on the 3 customer-trust surfaces, surface the demo state visibly.

---

## Surfaces healed

### S1 — Loyalty wallet head (screens.jsx line 569)
Inserted `<span>DÉMO V1</span>` between `lc-wallet-mark "LE CAYENNE · CLUB"` and `lc-wallet-tier "★ Pepper Club"`.
- **Color**: yellow chip + ink text (mirrors `lc-wallet-mark` palette already in row)
- **Size**: 11px mono uppercase, 3×8 padding, radius 6
- **A11y**: `aria-label="Mode démonstration version 1 — points non synchronisés avec la caisse"` + `title` attribute (tooltip)

### S2 — Wallet QR area (screens.jsx around line 583)
Inserted `<span>DÉMO V1</span>` inside `lc-wallet-code-body`, directly below `LECAY-347-A9F2C` ID. This anchors the demo state to the QR code itself — the artifact the customer presents at the POS scanner.
- **Color**: same yellow/ink chip
- **A11y**: `aria-label="Mode démonstration version 1 — ce QR code n'est pas connecté à la caisse"`

### S3 — Signup success modal (account-v2.jsx line 224)
Inserted `<span>DÉMO V1</span>` between `// Bienvenue au club` eyebrow and `+25 POINTS` headline.
- **Color**: ink chip + yellow text (inverted — readable on yellow modal background)
- **Size**: 13px mono 700, 4×10 padding, radius 6
- **A11y**: `aria-label="Mode démonstration version 1 — points fictifs, non utilisables en caisse"`

---

## Test evidence

### New spec (RED→GREEN)
`tests/e2e/test-e2e-web-z7-demo-badges-2026-05-19.spec.js` — 3 cases × 4 viewports = 12 GREEN.

| Case | What it asserts |
|------|-----------------|
| WE2-LOY.head | After signup OTP flow, loyalty wallet head contains `DÉMO V1` substring + at least 1 element with `aria-label` containing "démonstration" |
| WE2-LOY.qr | After signup, `.lc-wallet-code` parent contains `DÉMO V1` substring |
| WE2-MOD.success | Signup success modal (still open) contains both `DÉMO V1` and `+25` |

**Initial run (TDD RED)**: 3/3 fail on mobile project (as expected — badges don't exist).
**Post-implementation**: 12/12 GREEN × 4 viewports.

### Regression check
Existing `tests/e2e/test-e2e-web-z7-gaps-2026-05-18.spec.js` re-run post-implementation: **40/40 GREEN**. No regression.

### Visual evidence
12 screenshots captured at `tests/e2e/__screenshots__/test-e2e-web-z7-demo-badges-2026-05-19/`:
- `{mobile,tablet,desktop,wide}-LOY-head.png` — wallet head badge visible inline with brand mark + tier
- `{mobile,tablet,desktop,wide}-LOY-qr.png` — QR area badge visible below identifier
- `{mobile,tablet,desktop,wide}-MOD-success.png` — success modal badge between eyebrow and +25 headline

Of the 12 captures, only 8 are byte-unique: each `*-LOY-head.png` and `*-LOY-qr.png` pair is identical (same DOM, same scroll position, `fullPage:false` snapshot taken seconds apart). All 8 unique images read via Read tool — confirmed visually:
- No layout break (flex row holds, no overflow on 390px mobile)
- Badges visually unavoidable (placed in customer-attention hotspots)
- Color contrast strong (yellow#FFD93D on ink#0A0A0A or inverted ≈ 16:1 — passes WCAG AAA)
- Site stays in French (badge label `DÉMO V1` is owner-locked per task brief)
- All 12 captures programmatically asserted via Playwright `.innerText` substring match on the correct DOM container at the correct viewport.

---

## Files touched

| File | Tree | Status |
|------|------|--------|
| `/Users/1millnonstop/Downloads/web/screens.jsx` | live web tree | +6 LOC (2 inserts: wallet head badge + QR area badge) |
| `/Users/1millnonstop/Downloads/web/account-v2.jsx` | live web tree | +3 LOC (1 insert: success modal badge) |
| `tests/e2e/test-e2e-web-z7-demo-badges-2026-05-19.spec.js` | repo | NEW spec 132 LOC |
| `reports/audit/wave-e-2026-05-19/WE-2-WEB-DEMO-BADGES/STATUS.md` | repo | NEW this file |
| `reports/audit/wave-e-2026-05-19/WE-2-WEB-DEMO-BADGES/specialists/UX-A11Y.json` | repo | NEW specialist report |
| `reports/audit/wave-e-2026-05-19/WE-2-WEB-DEMO-BADGES/specialists/RED-ADVERSARIAL.json` | repo | NEW specialist report |

The live web tree at `/Users/1millnonstop/Downloads/web/` has no separate `.git`. Per prior Z-7 commit pattern (commit `00b9651a3`), source edits are documented in commit body — they do not appear in `git diff` of the repo.

---

## Specialist consensus

- **UX/A11y** verdict: GO. 3-placement strategy maps to customer-attention hotspots. Inline style sufficient (no CSS file edit). Plain `<span>` with `aria-label` is correct ARIA APG static-content pattern (NOT `role="status"` which is for live updates).
- **RED-team adversarial** verdict: GO with residual risks documented. Path A bounded by owner gate; stronger alternatives (full banner / explicit "TEST — non utilisable en caisse" copy) explicitly deferred to Path B / V1.0.x roadmap.

Both specialist JSONs at `specialists/UX-A11Y.json` and `specialists/RED-ADVERSARIAL.json` (≈1.5KB and ≈3KB respectively).

---

## Out-of-scope items surfaced for owner

1. **Unauth marketing copy** (screens.jsx line 548) — "+25 pts à l'inscription" is in marketing pitch, not presented as user balance. Did NOT add badge here. Path A scope minimal preserved. If owner wants exhaustive coverage, add 4th badge here in a follow-up.
2. **`loyalty-v2.jsx`** — out per task brief (profile chrome surface, not in scope WE-2).
3. **i18n** — site is FR-only V1; badge text `DÉMO V1` hardcoded. When site goes multi-lang, badge becomes one of the items to translate. Documented as acceptable V1 debt.
4. **Tooltip on mobile** — `title` attribute surfaces on hover (desktop) and long-press on some mobile browsers; not all. Accepted limitation V1; aria-label remains primary signal.
5. **Path B (API wireup)** — V1.0.x backlog item per owner gate decision 2026-05-19. Not in this PR.

---

## Risks deferred

| Risk | Severity | Status |
|------|----------|--------|
| Customer ignores all 3 chips and still believes balance is real | LOW (statistically 3 attention hotspots, must miss all 3) | Accepted V1 |
| Tooltip not surfaced on mobile long-press | LOW (aria-label is primary signal for SR users) | Accepted V1 |
| FR-only badge ambiguous for non-FR speakers | LOW (single-restaurant FR market V1) | Accepted V1 |
| POS denial UX itself (POS-side) not in scope here | n/a — POS surface owned by separate POS-A4 LOC track | Out of scope WE-2 |

---

## STOP gate self-check

- [x] Scope-minimal — 9 LOC heal across 2 source files
- [x] 0 frozen-zone files touched (web standalone has no frozen-zone files)
- [x] Existing tests not broken (40/40 Z-7 still GREEN)
- [x] New tests added before implementation (TDD RED first proven)
- [x] Visual evidence captured (12 screenshots, all viewports × all contexts)
- [x] Visual evidence READ via tool (verified layout intact, no raw labels, palette intact)
- [x] A11y considered (aria-label + title; plain span semantic correct)
- [x] No new console errors (assertCleanConsole on all 3 cases passes)
- [x] Specialists ran in parallel (UX + RED, both read-only)
- [x] Anti-fiction: 0 fabricated content — every assertion traceable to file:line or test:line

---

## Commit ready

Commit message:
```
feat(web-demo-badges wave-E-2): DÉMO V1 disclosure badges on loyalty surfaces (G-WEB-1 Path A)
```

Body covers: 3 surfaces, 9 LOC, 12/12 + 40/40 GREEN, frozen-zone=0, owner Path A decision context, web standalone tree edits documented inline (no separate git).
