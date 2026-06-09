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

### H3 — G-DEC-1 totalPending honest caveat (executed on `lance le plan`, the plan's Claude-executable recommendation)
- **Change:** `EncaissementComponent.vue` — added `pendingCapped` computed (`orders.length >= 200`, mirroring the server `limit(200)`) and a small caveat **"200 plus récents — total partiel"** shown under the amount ONLY when the list is at the cap. Below 200 (the real single-restaurant case) the banner stays a true "Total". +`encaisser_total_capped` key in fr.json **and** en.json (parity), +CSS.
- **Proof:** Vitest **2077 pass / 0 fail**; rebuilt `admin-shell.js` (caveat compiled ×3). **LIVE** `:8769` /admin/encaissement → "Total en attente d'encaissement 5 767,00 €" + caveat "200 plus récents — total partiel" (clone exceeds 200) — `round-1/captures/v-gdec1-capnote.jpeg`. Commits `a657f79f8` (source) + `d05a1f0d5` (bundle). 0 frozen lines.

---

## §3 — OWNER GATES (decision required — NOT actioned)
> **G-DEC-1 is now DONE** (executed on `lance le plan` per the recommendation below). G-DATA-1 + G2 remain.

| Gate | Item | WHO | WHAT (unblocks) | WHERE | Sev |
|---|---|---|---|---|---|
| **G-DATA-1** | Live deployed-box `site_time_format` still 12h until a settings-save | Physical owner | Open Admin → Réglages → save once with 24h (`H:i`), OR a one-off `Setting` update on the live box | deployed box (not this clone); code/seeder does NOT retro-migrate | P3 |
| **G-DEC-1** | Encaissement "Total en attente d'encaissement" sums only the 200-capped fetched list | Owner (Claude can execute) | Decide: (a) **relabel "Total (200 plus récents)" — RECOMMENDED** (one-line, removes the false "Total" claim; the literal "Total" on a capped subtotal silently under-reports money-owed on a busy day — 5 767 € shown vs 43 291 € on the clone), (b) add a server `sum` endpoint for the true total, or (c) accept (vision: single-restaurant rarely exceeds 200 uncollected) | `EncaissementComponent.vue` totalPending + route `api.php:836` | P2 |
| **G2** | Brand-color sweep `#696cff`/`#ff006b` → `#F4501E` (appService ×6 SweetAlert confirm, eod PDF ×3) | Owner | Confirm intentional (Cayenne palette) or revert | `appService.js`, `eod_synthesis.blade.php` | RISK |

**Gate-waiting protocol:** none of these block the branch from being correct. G-DATA-1 is a deployed-box data action only.

---

## §4 — BACKLOG / OPTIONAL (no action without owner)

| Ref | Item | Recommendation |
|---|---|---|
| Fbe3 | `'N° fiscal'` XLSX heading is a hardcoded FR stopgap (no `all.label.fiscal_number`) | **DONE** (on `continue the goal`): added `all.label.fiscal_number` to lang/{fr,en}/all.php, `OrderHistoryExport` now uses `trans('all.label.fiscal_number')`. php -l clean, key resolves (fr "N° fiscal" / en "Fiscal No"). Backend-only, no rebuild |
| D9 | Historique export dropdown UI gate `pos-orders` narrower than backend `pos-orders\|pos` | **SKIPPED (deliberate):** moot — clone DB shows no role holds `pos` without `pos-orders` (POS Operator + Branch Manager hold both), so widening changes nothing for any real user; not worth a bundle rebuild on a near-full disk |
| D7 | `rail_ruptures`/`usage_none` added to fr.json but not en.json → intlify warnings in en-default test harness | V2 (i18n parity); V1 is single-locale FR (ADR-007), both resolve live |
| D9 | Historique export dropdown UI gate `pos-orders` narrower than backend `pos-orders\|pos` | Optional: widen UI gate to match backend (read-only data, single-operator V1-moot) |
| F-T3 | W1 receipt `.toLowerCase()` is a harmless no-op (footer keys already lowercase) | Optional revert (no benefit either way; not a defect) |
| B2 | Encaissement aging badge shows raw hours ("269h") on artificially-old clone rows | No action — real-world pending are minutes/hours; faithful port |
| DATA-30 | `order_type=30` (1338 clone orders) has no enum mapping → renders "—" via the new fallback | Pre-existing dirty data, out of scope here. The W3 fallback is strictly better (was nothing), but the "—" now silently masks 1338 unknown-type orders → data-quality backlog: trace/clean the legacy `order_type=30/4` values (`OrderType.php`) |

---

## §5 — RECOMMENDATION TO OWNER

1. **Branch is now correct and audit-clean** (2 self-regressions healed + proven, 0 P0/P1, frozen=0, NF525 chain untouched). It is safe to **push / open PR / merge into `pre-cloud-exec`** at your discretion — that remains your gate (no auto-push).
2. **G-DEC-1 + G2** are quick decisions; my recommendation is **relabel G-DEC-1** to "Total (200 plus récents)" (the literal "Total" silently under-reports money-owed on a busy day — a one-line fix removes the false claim) and **keep G2** (palette-aligned). Say the word and I'll apply the relabel or revert the colors.
3. **G-DATA-1** is a 30-second settings-save on the live box — only you can do it there.

## §6 — Evidence index
- `SUPERVISOR_AUDIT.md` — the 30-row decomposed table (verdict + smart proof + remediation per correction).
- `round-1/wave-{A..F}-findings.json` — the 6 adversaries' structured findings.
- `round-1/orchestrator-repro.json` — my execution-repro of the W4 backend (authz 302, BranchScope, fiscal).
- `round-1/captures/v-{dashboard,encaissement,historique,posorder-token-healed}.jpeg` — live proof.

## §7 — Operating-DB integrity note (tripwire moved during execution — investigated)
During G-DEC-1 the operating `foodking` tripwire moved **2674→2675 rows**, head `daf60671`→`4a0a9255`.
Investigated read-only and **cleared**: the new row (id 2675) is a single `user.login` by admin (user_id=1,
ip 127.0.0.1, 2026-06-08 23:40 Paris) — **independent live activity, not my write**. All of my `:8769`
logins landed in the CLONE `foodking_dash_e2e` (rows 2677–2681); every browser navigation I issued targeted
`127.0.0.1:8769`. Chain integrity intact: row 2675 `prev_hash` == row 2674 `current_hash` (`daf606710a6afb93`)
= **LINKED-OK** clean append (the 1-id span gap is pre-existing/historical, not a deletion). New canonical
anchor for future sessions: **operating `foodking` head = `4a0a9255` (id 2675)**.

## §F — Final rule
Production-perfect, not "almost there." This branch reaches that bar for the dashboard scope: every
correction is either HOLDS-with-proof or HEALED-with-proof; the remainder are explicit owner decisions,
not hidden risk.
