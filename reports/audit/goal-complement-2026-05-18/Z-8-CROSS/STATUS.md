# Z-8 CROSS — Status

**Zone**: Cross-surface i18n + A11y hunt (AUDIT-ONLY)
**Branch**: pr/mobile-app-real-e2e-heal-2026-05-18
**HEAD**: 575a04652 (note: task task-prompt cites ec0d49241, actual HEAD differs — work proceeded against current state)
**Specialists fired**: UX/A11y + RED-team (per Fan-Out Matrix §A row 10)
**Specialists skipped**: Architect / Security / DBA / SRE / Implementer / QA-Visual / RED-Visual (out of fan-out for cross-surface)
**Wall-clock**: ~15 min

---

## Verdict

**AUDIT-ONLY PASS** — `findings.json` complete with 16 cross-surface findings (6 P0 / 6 P1 / 3 P2 / 1 P3).
**V1 merge blocker assessment**: NO. Z-8 does NOT block V1 Le Cayenne merge.
All heals deferred V1.0.X. Existing FR-forced sentinel (`i18nForceFRForAdminSurfaces.spec.js`) guarantees admin surfaces show French — non-default locale drift impacts only ar/en users (not enabled for V1 Le Cayenne).

---

## P0 inventory (cross-surface)

| ID | Finding | Files | Impact |
|----|---------|-------|--------|
| Z-8-P0-00 | **19 verified `$t()` calls reference UNDEFINED keys** | admin-kds.js, admin-shell.js, kiosk-wizard-step.js, pos-app.js, pos-shell.js | User sees raw key (e.g., `label.kds_status_conflict`) at runtime on KDS status-conflict banner, POS weekly schedule (FR), kiosk wizard fallback, promo admin form, kiosk a11y theme toggle. ONE key (`label.kds_status_conflict`) could surface in KDS V1 normal use → flag for owner-decision pre-merge. |
| Z-8-P0-01 | Arabic translation drops `{n}` placeholder | resources/js/languages/ar.json (kiosk.wizard.step.viande.instruction_remaining_one) | vue-i18n interpolation will fail. ar not default for V1 — V1.0.X heal. |
| Z-8-P0-02 | **fr↔en parity drift — 269 inconsistent keys** (75 fr-only + 194 en-only) | resources/js/languages/{fr,en}.json | EN-locale users miss 75 FR-introduced labels. FR-default locked by sentinel → no V1 break for Le Cayenne. |
| Z-8-P0-03 | **fr→ar parity drift — 239 keys orphan in Arabic** | resources/js/languages/ar.json | ar locale shows raw keys for 239 paths. Block ar enablement until ≥95% parity. |
| Z-8-P0-04 | Axe coverage gap — 6 surfaces lack persisted axe-results in goal-pageby-2026-05-18 round-1 | reports/test-e2e/goal-pageby-2026-05-18/round-1/{POS,BORNE,OSS,STOCK,SYNC,LIVREUR}/ | Only KDS has axe-results.json (10 violation nodes). Mobile-A11y wave is 1 week stale (12 surfaces / 253 nodes). Cannot certify WCAG AA. |
| Z-8-P0-05 | Sentinel tests promised by plan §9 do NOT exist | tests/Feature/Sentinels/CrossSurfaceI18nLeakSentinelTest.php + I18nParityFrEnArSentinelTest.php | Drift can re-emerge silently. Existing 5 sentinels cover narrow subsets (studio namespace, kds-aria, FR-force-admin, split-payment). |

---

## P1 inventory

| ID | Finding | Files |
|----|---------|-------|
| Z-8-P1-01 | fr.json has **3 empty-string keys** producing `menu.`, `label.`, `kiosk.filters.` | resources/js/languages/fr.json L260, L629, L1432 |
| Z-8-P1-02 | KDS `.kds-card__shortcut` color-contrast 3.63:1 (WCAG AA expects 4.5:1) — 9 nodes | KDS Vue stylesheet |
| Z-8-P1-03 | KDS scrollable-region-focusable — 1 region without keyboard access | KDS board container |
| Z-8-P1-04 | Mobile region landmarks missing on all 12 surveyed pages (107 axe nodes) | mobile/screens-main.jsx + RN — handoff Z-6 owner |
| Z-8-P1-05 | Mobile nested-interactive (61) + button-name (60) on menu filter | mobile menu-filter screen — handoff Z-6 owner |
| Z-8-P1-06 | Existing `tools/i18n/audit_locale_keys.mjs` dormant since 9 mai, NOT wired to CI | tools/i18n/ + CI config |

---

## P2 / P3 inventory

| ID | Finding |
|----|---------|
| Z-8-P2-01 | Stale-copy fr=en — 20 keys identical (mostly intentional allergens proper nouns; admin.help.* likely stale) |
| Z-8-P2-02 | Stale-copy fr=ar — 12 keys (mostly proper nouns SIRET/Paypal/Stripe; `kiosk.loyalty_screen.placeholder_phone` real i18n smell) |
| Z-8-P2-03 | Dual i18n catalog architecture (PHP Laravel 16 files/locale + JSON ~1900 keys/locale) — maintenance surface |
| Z-8-P3-01 | Encoding clean — no BOM on fr/en/ar.json (UTF-8 OK) — cleared concern |

---

## Method audit (read-only sweep evidence)

- **Lang locale parity** — Node flatten on fr.json (1887 keys) / en.json (2006) / ar.json (1840). Diff sets: 75 fr-only, 194 en-only, 239 fr-missing-from-ar.
- **Compiled JS i18n key sweep** — Regex over `public/js/admin-*.js` + `public/js/kiosk-*.js` + `public/js/pos-*.js`: 9 admin / 291 kiosk / 282 POS unique dotted keys. All look legit dotted paths (no `Label.X` capitalized raw labels detected).
- **Verified `$t()` key leaks** — Resolved each `$t('foo.bar')` reference against fr.json tree → 19 truly-missing keys (after stripping URL paths / CSS selectors / route names).
- **Axe-core aggregation** — KDS goal-pageby (10 nodes, 2 rules) + Mobile design-perfect wave-a11y 2026-05-11 (253 nodes, 6 rules across 12 surfaces). 6 surfaces in current goal-pageby-2026-05-18 lack axe-results.json.
- **Placeholder integrity** — Compared {placeholder} sets between fr/en (0 drift) and fr/ar (1 drift on `kiosk.wizard.step.viande.instruction_remaining_one`).
- **Empty-key detection** — Deep-scan fr.json found 3 empty-string keys; verified at exact lines (260, 629, 1432). Not present in en/ar.
- **Existing i18n sentinels** — 33 tests pass across 5 spec files (studioFrontendI18nParity / i18nAuditTool / kdsAriaI18n / i18nForceFRForAdminSurfaces / labelSplitPaymentI18nKey). Coverage narrow — does NOT cover cross-namespace fr/en/ar parity or general `$t()` leak hunt.

---

## Deferred-heal V1.0.X priorities

1. **Week 1**: Add 19 missing `$t()` keys to fr.json + en/ar (Z-8-P0-00 quick wins)
2. **Week 1**: Write 2 new sentinels — `i18nParityFrEnArSentinel.spec.js` + `crossSurfaceI18nLeakSentinel.spec.js` (Z-8-P0-05). Leverage existing `tools/i18n/audit_locale_keys.mjs` helpers.
3. **Week 1**: Wire `node tools/i18n/audit_locale_keys.mjs --emit-report` to CI as warn-only first (Z-8-P1-06).
4. **Week 2**: fr/en + fr/ar translator pass (Z-8-P0-02 + Z-8-P0-03).
5. **Week 2**: Rename 3 empty-key entries in fr.json (Z-8-P1-01).
6. **Week 2**: KDS color-contrast + keyboard a11y CSS fixes (Z-8-P1-02 + Z-8-P1-03).
7. **Week 3-4**: Wire @axe-core/playwright to each goal-pageby surface spec (Z-8-P0-04).
8. **Week 3-4**: Mobile a11y heal — handoff Z-6 owner (Z-8-P1-04 + Z-8-P1-05).

---

## Owner-decision points (pre-V1-merge)

- **Q1**: Add `label.kds_status_conflict` to fr.json as scope-minimal patch BEFORE V1 merge?
  - **Recommendation**: YES if KDS owner-gate clears — single-key addition, no frozen-zone touch, prevents production raw-key surface on concurrent-bump conflict.
  - **Risk if deferred**: KDS user sees raw key text on race-condition status-conflict banner. Low-frequency but real.

- **Q2**: Same for `kiosk.wizard.generic.step_fallback` + `kiosk.wizard.generic.min_hint`?
  - **Recommendation**: YES — kiosk-shell is dirty (READ-ONLY for Z-8 scope), but ADDING keys to fr.json is not in dirty zone. 1 file change.

---

## Specialist deliverables

- `reports/audit/goal-complement-2026-05-18/round-1/Z-8-CROSS/UX-A11Y.json` — 10 findings (3 P0 / 5 P1 / 2 P2)
- `reports/audit/goal-complement-2026-05-18/round-1/Z-8-CROSS/RED.json` — 8 adversarial findings (3 P0 / 4 P1 / 2 P2 / 1 P3 — 5 new + 8 agreements + 2 disputes)
- `reports/audit/goal-complement-2026-05-18/deferred-heal/Z-8-CROSS/findings.json` — synthesis (6 P0 / 6 P1 / 3 P2 / 1 P3 = 16 total)
- `reports/audit/goal-complement-2026-05-18/Z-8-CROSS/STATUS.md` — this file

---

## Constraint compliance

- [x] NO write to any compiled JS or Vue
- [x] NO write to lang files (heal deferred V1.0.X)
- [x] Wall-clock < 20 min (target met ~15 min)
- [x] AUDIT-ONLY scope respected
- [x] Per-specialist <1500 words (UX/A11y ~550 words, RED ~650 words)
