# PROPOSAL — POS Wizard frozen-zone audit summary

- **Date**: 2026-05-23
- **Phase**: B.5 — Proposal-only frozen-zone scan
- **Target files**:
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/pos-wizard.js` — **5964 LOC** (~290 KB Vanilla JS, S25-SinglePage, non-Mix compiled)
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/css/pos-wizard.css` — **1987 LOC** (~40 KB pure CSS)
  - **Total: 7951 LOC scanned end-to-end**

---

## 1. Mission

Read both files integrally to verify everything still works as expected (no drift), identifying ONLY true regressions / security issues per CLAUDE.md §7 "design parfait selon owner" mandate. Cosmetic findings = REFUTED (KEEP-AS-IS).

---

## 2. Verdict — short version

| Category | Outcome |
|----------|---------|
| Design / UX / CSS regressions | **NO-CHANGE-OWNER-PROTECTED** (zero findings) |
| Architecture / pattern coherence | Clean (IIFE-scoped, payload SSOT respected, no global leak) |
| NF525 fiscal invariants | Untouched — file does not allocate fiscal_sequence_no, write audit_logs, or close z_reports |
| Composition SSOT | Verified compliant (payload `item_id, quantity, item_variations, item_extras` per L4143-L4171, no client-trusted pricing) |
| Multi-tenant (BranchScope) | Out of scope — file is client-side; branch scoping is server-side |
| Stored XSS — innerHTML sinks | **DEFECT FOUND** — see separate proposal `PROPOSAL_pos-wizard_001_xss-sinks-lock-pending.md` |

---

## 3. Concerns hunted vs found

| # | Hunt | Method | Found? | Notes |
|---|------|--------|--------|-------|
| 1 | XSS via `innerHTML` writes | `grep -n "innerHTML"` → 12 matches, traced upstream interpolations | **YES** | Documented LOCK exists since 2026-05-17, owner-gate pending. **+2 new sites L3180 (`instructionText` reflection in `<textarea>`) and L3187 (ticket-preview) not in original LOCK.** Full evidence in `PROPOSAL_pos-wizard_001_*.md`. |
| 2 | XSS via attribute injection (`data-name="' + g.name + '"`) | `grep -n "data-name="` → L1701, L1801 | YES | Subset of #1 — attribute-context escape required (LOCK helper covers it: escapes both `"` and `'`). |
| 3 | `eval` / `Function()` / `setTimeout(string,...)` | `grep -n "eval\|new Function\|setTimeout('\|setInterval('"` | NO | Clean. |
| 4 | `document.write` / `outerHTML` | grep | NO | Clean. |
| 5 | Inline `onclick=` injecting user-controlled data | L1255, L1642 | NO | Static string interpolation only (`hiddenCount` is `parseInt`-derived). |
| 6 | `escapeHtml` helper presence | `grep -n "function escape\|function safe\|function sanitiz"` | NO | **Absent** — confirms LOCK §2.1 scope still applies. |
| 7 | Sanctum token / localStorage leaks in error paths | Read XHR/fetch monkey-patches (L170-213) | NO | Patches are read-only payload capture for restore logic, no exfil path. |
| 8 | Payload SSOT compliance (composition snapshot) | Read `buildWizardPosLineAddonsPayload` L4077-L4209, `addonToPayload` L4136-L4172 | OK | Sends `item_id`, `quantity`, `item_variations`, `item_extras`, `instruction`, `parent_addon_id`. No client-trusted pricing. CLAUDE.md §8 SSOT respected. |
| 9 | Hardcoded prices that should be in `POS_WIZARD_CONFIG` | L85-91 | NO | Properly read from `window.POS_WIZARD_CONFIG` (server-injected via `master.blade.php`) with safe numeric fallbacks. |
| 10 | Frontend-computed totals shipped to backend | L4212 `wizardTotalBeforeSubmit` + L4215 `setAttribute('data-wizard-total')` | NO | This attribute is consumed by the surrounding Vue cart for **display** only. Backend `PricingService::calculateOrder` recomputes server-side per CLAUDE.md §8. |
| 11 | Composition snapshot mutation client-side | grep for `composition_snapshot` | NO | Term doesn't appear in pos-wizard.js — snapshot is created server-side at order creation per CLAUDE.md §8. |
| 12 | Branch leakage via missing `branch_id` filter | N/A — client-side file | NO | BranchScope is server-side global scope (`app/Models/Scopes/BranchScope.php`); wizard cannot bypass. |
| 13 | NF525 fiscal-chain writes | grep for `fiscal_sequence\|audit_log\|z_report` | NO | None — file purely renders a UI flow and emits an item-line payload. |
| 14 | Cache::lock bypass / race conditions | grep for `Cache::lock\|setTimeout.*POST\|debounce` | NO | No cache primitives; rendering races are CSS-animation only. |
| 15 | Idempotency-key bypass | grep for `X-Idempotency-Key` | NO | Wizard submits via the parent Vue cart, which goes through `IdempotencyKeyMiddleware`. No client-side bypass. |
| 16 | Spatie permission bypass | N/A — client-side file | NO | Authorization is enforced server-side. |
| 17 | CSS-level attack (`javascript:` URI, `expression(...)`, `url(data:text/html...)`) | `grep -n -i "javascript:\|expression(\|data:text\|<script" pos-wizard.css` | NO | CSS file is purely declarative styling. Clean. |
| 18 | Accessibility regressions (focus traps, tab order, ARIA) | Read CSS structural rules + JS event bindings | NO P0 | Out of scope for security audit; deferred to dedicated a11y audit if owner requests. |
| 19 | Memory leak (unbounded handler accumulation) | Read `bindEvents()` / `bindSinglePageEvents()` callsites | NO | Each `refreshWizard()` re-renders via `innerHTML = renderSinglePage()`, which detaches old DOM + handlers via browser GC. Fresh handlers attached after. Acceptable. |
| 20 | XHR/fetch monkey-patch correctness | Read L170-213 | OK | `_xhrOpen`/`_xhrSend` apply with `arguments` (handles all signatures). `fetch` clones response before parsing JSON (correct, original consumer not blocked). No side effects on non-item URLs. |

---

## 4. Files NOT touched

- `public/js/pos-wizard.js` — **no edits, no comment changes**
- `public/css/pos-wizard.css` — **no edits, no comment changes**
- `resources/views/admin-pos-v4.blade.php` — out of scope (Blade host)

Owner mandate per CLAUDE.md §7 + `feedback_wizard_popup_pos_protected.md` **respected**.

---

## 5. Summary verdict

- **Visual / UX / design**: NO-CHANGE-OWNER-PROTECTED. Owner mandate intact.
- **Architecture / SSOT / NF525 / multi-tenant**: clean, no regression introduced or hidden.
- **Security**: **NOT clean** — pre-existing stored-XSS defect documented since 2026-05-17 is still unpatched (owner-gate pending). **One follow-up proposal filed**: `PROPOSAL_pos-wizard_001_xss-sinks-lock-pending.md`. Two **new sites** (L3180 `instructionText` `<textarea>` reflection, L3187 ticket-preview innerHTML) **discovered today** and added to the recommended LOCK scope extension.

The XSS-001 proposal disputes KEEP-AS-IS on Security grounds with strong counter-arguments (per mission brief: "Cosmetic findings = REFUTED, security/bug = file proposal").

---

## 6. Cross-references

- `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` — existing pending LOCK (Wave 5G)
- `plans/PLAN_TASK_V1_SEC_XSS_001_2026-04-15.md` — earlier XSS plan
- `feedback_wizard_popup_pos_protected.md` — owner design mandate
- `CLAUDE.md` §7 (frozen zones), §8 (NF525 + composition SSOT), §10 (human gate)
- `feedback_pos_kiosk_wizard_unify_logic_keep_ui.md` — backend unification OK, UI separate
- Companion proposal this cycle: `proposals/PROPOSAL_pos-wizard_001_xss-sinks-lock-pending.md`
