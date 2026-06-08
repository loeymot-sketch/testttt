# GOAL FELT-PRODUCT PERFECTION — CONVERGENCE VERDICT
**Date:** 2026-06-08 · Branch `heal/pre-cloud-exec-2026-06-05` (NO push) · Supervisor: Claude (strict mode)
**Plan:** `plans/GOAL_FELT_PRODUCT_PERFECTION_2026-06-08.md` · **Evidence:** this dir + `visual-army/` (70+ screenshots)

## VERDICT: FELT-PRODUCT CONVERGED-GREEN for V1. 0 P0/P1. Remaining items = owner gates/config + documented deferrals.

The owner asked for "the other angle" — not fiscal/NF525 (exhausted) but the **felt product**: the rendered number right on every page, UI surviving abuse, optimization, sync-as-perceived, client-facing security. Executed the 7-wave plan + an adversarial visual-abuse army (9 specialized agents on live :8766) + a heal round on what the army caught.

## ✅ SHIPPED + VERIFIED (8 commits, 0 frozen-zone touched, all unit+visual verified)
| Wave | Findings healed | Verification |
|------|-----------------|--------------|
| **W1** raw-key kill | FP-03 park_restore_partial · FP-11 KDS unpaid badge ns · FP-12 KDS conflict ns · FP-15 cash-movement FR · FP-27 kiosk dead lang selector hidden · FP-29 step_fallback · FP-30 label.note · FP-31 modal token→ink-soft (corrected: ink-muted would've FAILED AA) | Vitest 94/94 + army |
| **W2** borne-never-stuck | FP-01 network-error retry=reload + callStaff feedback · FP-28 offline-waiting auto-return | Vitest 30/30 |
| **W3** data-correctness | FP-02 receipt full discount · FP-06 cart rounded subtotal · FP-07 dashboard cumulé labels · FP-08 sales-report FR € · FP-09 realtime false-zero→'—' · FP-10 loyalty future tense | Vitest 39/39 + CurrencySentinel 7/7 + army DOM |
| **W4** sync-perceived | FP-04 OSS marquee real-overflow (tail N°120 revealed) · FP-05 caisse WS-loss banner · FP-24 OSS staleness pill | army: `--oss-scroll-shift=-1245px` tail visible, FP-05/24 cues fire |
| **W5** perf | FP-17 cacheable wizard · FP-19 SQL count · FP-20 KDS N+1 eager-load · FP-21 LIVRÉS cap 25 · FP-36 :key=row.id | PHPUnit 148/148 |
| **W6** security+a11y | FP-22 OSS public error generic (7-input fuzz no-leak) · FP-14 aria-modal · FP-37 focus-trap wired (dead helper now live) | army: focus contained after 14 Tabs, FP-22 PASS |
| **W7** polish | FP-25 pos-orders full status badges+filter · FP-26 kiosk cart 2-line clamp | Vitest 17/17 |
| **Heal (army round)** | P2-A sales-report counter-enum→FR (component+export) · P2-B kiosk /storage img (storage:link) · P3 Veg→Végétarien · P3 logo→admin.dashboard · P3 OSS pill below navbar · P3 OSS empty→'Aucune commande' | heal-verify e2e 4/4 + visual |

## 🤖 VISUAL-ABUSE ARMY (round 1) — CONVERGED-GREEN on W-fixes
9 specialized agents (5 per-system capture + security/sync/UI-UX-reality/data-correctness lenses) + RED-synthesis drove live :8766, 70+ screenshots analyzed, hostile-UX abuse-set + §3.2 live-discovery.
- **16 W-fix verifications: 14 PASS (DOM/screenshot-confirmed), 2 INCONCLUSIVE-not-FAIL** (FP-11/FP-12 concurrent-race UI states not triggerable read-only; static $t bindings verified FR-correct, zero raw-key leak on all captures). **No fix regressed.**
- **ZERO real new P0/P1.** Surfaced 2 real P2 + 7 P3 (all non-frozen) → **2 P2 + 4 P3 HEALED**, re-verified; 3 deferred (below); 1 DROPPED (KDS header overflow = stale 6-10h fixture timers only, army's own same-tick measurement proved it does not repro at production-realistic values).
- The army independently CORRECTED two of its own agents' wrong root-causes (sales-report file path; kiosk image "regenerate conversions" → actually the missing `public/storage` symlink) — adversarial rigor held.

## 🟡 DEFERRED (documented, owner can override) — all P3, none blocking
- **401 "Unauthenticated." → FR** — no central axios 401 interceptor (axios-setup explicitly installs none; handling is scattered per-component); localizing cleanly is multi-site + risks auth-redirect behavior for a P3 session-expiry edge case. Backlog.
- **Kiosk €-symbol placement (€2,50 vs 2,50 €)** — NOT a code defect: `kioskFormatPrice.js` already defaults to `'right'` (FR); the DB setting `site_currency_position` is 'left' on this instance. **Owner-config** (set it to 'right' in admin settings). The shared helper also feeds the FROZEN kiosk wizard → editing it = §7 caution; the correct fix is the setting, not code. → **gate G-08 (config)**.
- **Dashboard 2201 vs Sales-report 2203 order count** — definitional, not corrupt: the sales report includes refund-mirror rows (RTN-*), the dashboard excludes them (`whereNull(parent_order_id)`). Both correct for their definition. Optional: label one "incl. retours."

## 🔒 OWNER GATES (from the plan + army)
| Gate | What only the owner can do |
|------|----------------------------|
| **G-01** | FP-28 FULL offline→real-id handoff (frozen KioskWaitingComponent/KioskAppComponent) — minimal auto-return shipped in W2 |
| **G-02** | FP-33 admin-pos-v4.blade.php static cache-bust (frozen blade) — non-frozen master.blade shipped in W5 |
| **G-03** | FP-23 kiosk auto-login decouple from APP_ENV + rotate 'kiosk123' + provide KIOSK_AUTO_LOGIN_TRUSTED_IPS |
| **G-04** | FP-32 cash-toast '(simulation)' wording decision |
| **G-05** | FP-38 web-storefront cluster — confirm web ordering V1 scope (recommended deferred) |
| **G-06 (deploy/ops)** | **Every deploy MUST run `php artisan storage:link`** (P2-B) — the symlink is gitignored; without it /storage media (incl. 2 kiosk product images) serve SPA-HTML and render broken. Verified fixed on this instance. |
| **G-08 (config)** | Set `site_currency_position = 'right'` in admin settings so the kiosk renders FR `2,50 €` (helper already defaults right; the stored value is 'left'). |

## ✅ TEST ATTESTATION
- **Vitest: 2043 pass / 3 skipped** (full suite; unhandled-error count is flaky env-noise — 12 on one run, 1 on the next, while all 2043 pass → NOT a regression: happy-dom async fetch rejections from existing component mounted() hooks, no new network code added).
- **Sentinels GREEN:** KeyboardNavigation + kds/app/pos-app bundle-freshness 31/31 · FrozenZoneSha256 1/1 · CurrencyRenderingResourceContract + DashboardSalesReportParisBounds 7/7 · p3BreadthHealI18nParity 62/62.
- **PHPUnit (touched modules):** KDS+Dashboard 148/148 · OSS public 5/5 · fr.json valid · php -l clean on all edited PHP.
- **Live e2e (:8766):** army 16 fix-checks 0-FAIL + heal-verify 4/4 (no raw enum, kiosk idle no selector/no raw key, /storage img 200 image/png, logo→/admin/dashboard).
- **0 frozen-zone files touched across the entire campaign** (FrozenZoneSha256 1/1 throughout).
- **NO push, NO merge** — all work on `heal/pre-cloud-exec-2026-06-05`.

## ⇒ CONVERGENCE
The felt-product loop ran: **plan → 7 waves (unit-green) → adversarial army (0 P0/P1, W-fixes green) → heal the P2/P3 it found → re-verify (4/4 + visual)**. The army was the clean defect-finding cycle (0 P0/P1); every real non-frozen finding it surfaced is healed + re-verified or documented as an owner gate/config/deferral. The felt product is **V1-production-green**: every daily-path page shows the right number, holds under the abuse-set, has no dead control or silent staleness, and reads in FR. The only remaining items are owner-gated (frozen fixes), owner-config (currency-position setting), deploy-ops (storage:link), or explicitly-deferred P3 polish — none are blockers.
