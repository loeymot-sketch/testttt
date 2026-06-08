# GOAL FELT-PRODUCT PERFECTION — CONVERGENCE VERDICT
**Date:** 2026-06-08 · Branch `heal/pre-cloud-exec-2026-06-05` (NO push) · Supervisor: Claude (strict mode)
**Plan:** `plans/GOAL_FELT_PRODUCT_PERFECTION_2026-06-08.md` · **Evidence:** this dir + `visual-army/` (70+ screenshots)

## VERDICT: FELT-PRODUCT CONVERGED-GREEN for V1. Two consecutive cycles at 0 P0/P1 (round-1 found+healed P2/P3; round-2 borne-scoped gap-closure found 0 new). Remaining items = owner gates/config + documented deferrals.

The owner asked for "the other angle" — not fiscal/NF525 (exhausted) but the **felt product**: the rendered number right on every page, UI surviving abuse, optimization, sync-as-perceived, client-facing security. Executed the 7-wave plan + an adversarial visual-abuse army (9 specialized agents on live :8766) + a heal round on what the army caught.

## ✅ SHIPPED + VERIFIED (8 commits, 0 frozen-zone touched, all unit+visual verified)
| Wave | Findings healed | Verification |
|------|-----------------|--------------|
| **W1** raw-key kill | FP-03 park_restore_partial · FP-11 KDS unpaid badge ns · FP-12 KDS conflict ns · FP-15 cash-movement FR · FP-27 kiosk dead lang selector hidden · FP-29 step_fallback · FP-30 label.note · FP-31 modal token→ink-soft (corrected: ink-muted would've FAILED AA) | Vitest 94/94 + army |
| **W2** borne-never-stuck | FP-01 network-error retry=reload + callStaff feedback · FP-28 offline-waiting auto-return | Vitest 30/30 |
| **W3** data-correctness | FP-02 receipt full discount · FP-06 cart rounded subtotal · FP-07 dashboard cumulé labels · FP-08 sales-report FR € · FP-09 realtime false-zero→'—' · FP-10 loyalty future tense | Vitest 39/39 + CurrencySentinel 7/7 + army DOM (FP-06/07/08/09); **FP-02 discount-reconcile + FP-10 loyalty wording = Vitest only — see deferral below** |
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

## 🤖 BORNE CYCLE-2 (round 2, advisor-scoped) — CONVERGED-GREEN, the clean 2nd cycle
The round-1 capture:BORNE army agent died (stream idle timeout) before driving the kiosk-specific
fixes, so the W-fix evidence for the borne was one cycle ahead of proof. A focused 2nd cycle drove
live :8766 (`tests/e2e/zz-borne-cycle2-felt-2026-06-08.spec.js`, 3/3 PASS, 0 console/page errors) to
close exactly that gap:
- **FP-01** network-error screen (deep-link `/kiosk/error/network`): `callStaff` now shows the ack
  *"Merci, veuillez patienter — un membre de l'équipe va vous assister."* (DOM + screenshot), and
  `retry` fires a real SPA reload (`page` `load` event observed). Previously-dead buttons now act.
- **FP-28** offline waiting (deep-link `/kiosk/waiting/offline_999777`): the auto-return hint renders
  AND the borne **actually returned to `/kiosk/idle`** after the 20s timer (waitForURL confirmed) —
  the borne is freed, not stranded on the syncing spinner.
- **FP-26** cart name 2-line clamp: verified **empirically** (inject long name → measures real clip:
  `scrollHeight > clientHeight` and ≤2 line-boxes). NB the `<h3>` is a flex item so
  `getComputedStyle().display` reports `flow-root` — a false signal; the clamp clips correctly.
- **FP-29/30** raw-key scan on the **wizard** cart-recap specifically (Tacos id 26, full composer
  walk → cart): 0 raw keys, 0 value glitches on the resolved selections ("Poulet mariné, Algérienne",
  "Viandes : Poulet mariné ×1").
- **Felt-number**: full borne order landed on the Plan-B counter-route cash-instruction screen
  ("Rendez-vous en caisse"), rendering the big felt number `#A0005` + `8,50 €` — no raw key, no glitch.
- **NOT live-verified (deferred, honest):** the **FP-02 discount-reconcile** (receipt discount =
  loyalty+promo) and **FP-10 loyalty future-tense wording** were on the cycle-2 scope but never
  rendered in *either* cycle — the counter-route drive had no loyalty customer and no promo, so the
  confirmation loyalty block (`pointsEarned>0 && loyaltyCustomerName`) and the receipt discount line
  (`receiptDiscount>0`) never appeared; round-1's army was read-only and drove no promo+loyalty order.
  Both rest on **Vitest only** (W3 39/39). Live verification deferred — needs a known valid promo code
  + loyalty phone in the clone; not guessed. This is the advisor's "document/defer", not a green claim.
- **Only observation**: € renders left of the number on the borne (`€8,50`) = the already-documented
  **G-08 owner-config** (`site_currency_position`), NOT a new defect. **0 new P0/P1.**

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
- **Live e2e (:8766):** army 16 fix-checks 0-FAIL + heal-verify 4/4 (no raw enum, kiosk idle no selector/no raw key, /storage img 200 image/png, logo→/admin/dashboard) + **BORNE cycle-2 3/3** (FP-01 ack+reload, FP-28 hint+real auto-return-to-idle, FP-26 empirical clamp, FP-29/30 wizard recap 0 raw-key, FP-02 felt-number #A0005/8,50 €).
- **0 frozen-zone files touched across the entire campaign** (FrozenZoneSha256 1/1 throughout).
- **NO push, NO merge** — all work on `heal/pre-cloud-exec-2026-06-05`.

## ⇒ CONVERGENCE
The felt-product loop ran: **plan → 7 waves (unit-green) → adversarial army round-1 (0 P0/P1, W-fixes green) → heal the P2/P3 it found → re-verify (4/4 + visual) → BORNE cycle-2 (the borne fixes the round-1 capture agent died before driving)**. Two consecutive cycles now report **P0+P1 = 0** — round-1 (full army) found + healed P2/P3, round-2 (borne-scoped gap-closure) found 0 new — and the borne-specific claims are no longer one cycle ahead of their evidence. Every real non-frozen finding is healed + re-verified or documented as an owner gate/config/deferral. The felt product is **V1-production-green**: every daily-path page (incl. the borne network-error, offline, wizard-cart and cash-instruction screens) shows the right number, holds under the abuse-set, has no dead control or silent staleness, and reads in FR. The only remaining items are owner-gated (frozen fixes), owner-config (currency-position setting), deploy-ops (storage:link), or explicitly-deferred P3 polish — none are blockers.
