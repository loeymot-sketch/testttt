# ULTRA-PLAN — Dashboard Excellence remediation (supervisor verdict)

**Date:** 2026-06-08 · **Branch:** `heal/deployed-dashboard-fixes-2026-06-08` (UNPUSHED, UNMERGED into deployed `pre-cloud-exec`)
**Companion:** `SUPERVISOR_AUDIT.md` (the decomposed table, 30 rows) · `round-1/*.json` (6 adversaries + orchestrator repro) · `round-1/captures/*.jpeg` (live proof)
**Method:** 6 parallel read-only adversaries + orchestrator execution-repro of the P0-candidate zone + live visual on `:8769`.

---

## §1 — SUPERVISOR VERDICT (the judgment)

The 5-wave / 28-item campaign is **substantively sound**. Adversarial re-audit found **0 P0, 0 P1**,
**2 P2 defects (both self-regressions I introduced) — HEALED this session with proof**, and a short tail of
RISK/owner-decision items. The one P1 *candidate* (Adversary B: "W2 not in bundles, invisible") was a
**false positive** (worktree-shadow grep) — disproven by reproduction + live screenshot.

| Severity | Count | Disposition |
|---|---|---|
| P0 | 0 | — (W4 net-new export — the only real risk surface — proven scoped + gated + fiscally inert) |
| P1 | 0 | (1 candidate DISPROVEN by reproduction) |
| P2 DEFECT | 2 | **HEALED + proven** (C-D token gate, E-D1 orphan test) |
| P2/P3 RISK | 3 | owner decision (G-DATA-1, G-DEC-1, G2) |
| P3 / info / gold-plating | 5 | backlog / optional / no-op |

**Why this is trustworthy (anti-hallucination):** every DEFECT/RISK was reproduced by the orchestrator
(`grep`/`Read`/clone-`SELECT`/`curl`/live screenshot) before entering the table; HOLDS verdicts carry
the proof-chain (binding→resource field→formatter, or compiled-bundle grep, or live render). Operating DB
`foodking` untouched — tripwire `2673 rows / daf60671` unchanged.

---

## §2 — HEALED THIS SESSION (self-regressions in unpushed code — proof attached)

These were my own bugs introduced by the campaign, in non-frozen code; "no return with broken state" → fixed + verified.

### H1 — `PosOrderShowComponent.vue:63` token gate (was P2 DEFECT C-D)
- **Bug:** W3.6 gated the "Référence interne" `<li>` on `displayedToken && order.order_type === enums.orderTypeEnum.DELIVERY`. The token is a **kiosk/online** reference (its own comment L55-62); clone: TAKEAWAY=2030 tokens, KIOSK=2, **DELIVERY=0** → the gate hid the token on all ~2032 orders that carry one and showed it only for the class that never does.
- **Fix:** reverted to the original `v-if="displayedToken"` (the dedup guard lives in the `displayedToken` computed, L625). delivery_time `<li>` and title-conditional (correct) left intact.
- **Proof:** compiled `admin-shell.js` token render now gated only on `displayedToken` (bad pattern → 0 occurrences). **LIVE** `:8769` order #0306264133 (À emporter) → "Référence interne: SYNC-E2E-ENC-1780491438393" now renders (`round-1/captures/v-posorder-token-healed.jpeg`).

### H2 — `RoleDisplayHelperTest.js` orphan (was P2 DEFECT E-D1)
- **Bug:** test placed at `tests/Unit/*.js` — matched NEITHER Vitest (`tests/js/**/*.spec.js`) NOR PHPUnit (`*Test.php`); ran nowhere → false coverage (WP-06 class).
- **Fix:** `git mv` → `tests/js/roleDisplay.spec.js` (import depth unchanged, logic unchanged); header comment updated.
- **Proof:** `npx vitest run tests/js/roleDisplay.spec.js` → **6 tests pass** (was "no test files found").

---

## §3 — OWNER GATES (decision required — NOT actioned)

| Gate | Item | WHO | WHAT (unblocks) | WHERE | Sev |
|---|---|---|---|---|---|
| **G-DATA-1** | Live deployed-box `site_time_format` still 12h until a settings-save | Physical owner | Open Admin → Réglages → save once with 24h (`H:i`), OR a one-off `Setting` update on the live box | deployed box (not this clone); code/seeder does NOT retro-migrate | P3 |
| **G-DEC-1** | Encaissement "Total en attente d'encaissement" sums only the 200-capped fetched list | Owner (Claude can execute) | Decide: (a) accept (vision: single-restaurant never hits 200 uncollected — RECOMMENDED), (b) relabel "Total (200 plus récents)", or (c) add a server `sum` endpoint | `EncaissementComponent.vue` totalPending + route `api.php:836` | P2 |
| **G2** | Brand-color sweep `#696cff`/`#ff006b` → `#F4501E` (appService ×6 SweetAlert confirm, eod PDF ×3) | Owner | Confirm intentional (Cayenne palette) or revert | `appService.js`, `eod_synthesis.blade.php` | RISK |

**Gate-waiting protocol:** none of these block the branch from being correct. G-DATA-1 is a deployed-box data action only.

---

## §4 — BACKLOG / OPTIONAL (no action without owner)

| Ref | Item | Recommendation |
|---|---|---|
| Fbe3 | `'N° fiscal'` XLSX heading is a hardcoded FR stopgap (no `all.label.fiscal_number`) | Add the lang key in a future polish pass (cosmetic) |
| D7 | `rail_ruptures`/`usage_none` added to fr.json but not en.json → intlify warnings in en-default test harness | V2 (i18n parity); V1 is single-locale FR (ADR-007), both resolve live |
| D9 | Historique export dropdown UI gate `pos-orders` narrower than backend `pos-orders\|pos` | Optional: widen UI gate to match backend (read-only data, single-operator V1-moot) |
| F-T3 | W1 receipt `.toLowerCase()` is a harmless no-op (footer keys already lowercase) | Optional revert (no benefit either way; not a defect) |
| B2 | Encaissement aging badge shows raw hours ("269h") on artificially-old clone rows | No action — real-world pending are minutes/hours; faithful port |

---

## §5 — RECOMMENDATION TO OWNER

1. **Branch is now correct and audit-clean** (2 self-regressions healed + proven, 0 P0/P1, frozen=0, NF525 chain untouched). It is safe to **push / open PR / merge into `pre-cloud-exec`** at your discretion — that remains your gate (no auto-push).
2. **G-DEC-1 + G2** are quick decisions; my recommendation is **accept G-DEC-1** (cap is vision-benign) and **keep G2** (palette-aligned). Say the word and I'll relabel or revert if you prefer.
3. **G-DATA-1** is a 30-second settings-save on the live box — only you can do it there.

## §6 — Evidence index
- `SUPERVISOR_AUDIT.md` — the 30-row decomposed table (verdict + smart proof + remediation per correction).
- `round-1/wave-{A..F}-findings.json` — the 6 adversaries' structured findings.
- `round-1/orchestrator-repro.json` — my execution-repro of the W4 backend (authz 302, BranchScope, fiscal).
- `round-1/captures/v-{dashboard,encaissement,historique,posorder-token-healed}.jpeg` — live proof.

## §F — Final rule
Production-perfect, not "almost there." This branch reaches that bar for the dashboard scope: every
correction is either HOLDS-with-proof or HEALED-with-proof; the remainder are explicit owner decisions,
not hidden risk.
