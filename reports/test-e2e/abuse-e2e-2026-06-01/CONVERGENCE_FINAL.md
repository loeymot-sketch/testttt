# abuse-e2e-2026-06-01 — CONVERGENCE FINAL

**Date:** 2026-06-03 · **Branch:** `heal/cms-pr1-quickwins-2026-05-18` · **HEAD:** `e67df4553`
**Mandate:** abuse / adversarial E2E across the whole V1 surface; loop until **0 open P0+P1** with each
fix independently re-proven; frozen zones + NF525 untouchable; **no push**.

## VERDICT: ✅ GO (V1 LOCAL Le Cayenne) — 0 open P0 / 0 open P1 across all 16 waves

- **16 waves** captured + adversarially reviewed (A–F core surfaces, G–P expansion coverage).
- **5 P1 found → 5 P1 fixed + empirically re-proven.** 0 P0 ever.
- **1 near-P0 candidate (G-001) root-caused as a prod-safe dev-env override** → reclassified P2 go-live item.
- **NF525 chain CHAIN OK** (all branches) · **frozen-zone source diff = 0** · pre-commit hook clean.
- Residue = **P2/P3 only** (test-strength, coverage gaps, env config, customer-web cosmetics) → backlog.

> Honesty note (per §13 + the recurring "never claim false convergence" lesson): this is **not** "16 waves
> re-run twice byte-identical." It is **every P1 fixed and independently re-verified**, every wave's latest
> verdict GREEN, and the remainder triaged to P2/P3. The convergence claim is "0 open P0/P1 with proof,"
> not "infinite re-run stability."

---

## P0 / P1 LEDGER (all closed)

| ID | Sev | Wave | Defect | Fix | Commit | Proof |
|----|-----|------|--------|-----|--------|-------|
| A-001 | P1 | A kiosk | idle subtitle cream-on-light hero 1.009:1 < 4.5:1 | dark scrim behind title block | `aeaf0f046` | re-measured **6.067:1** |
| E-001 | P1 | E admin | dashboard raw i18n key leak | i18n key added | `aeaf0f046` | 0-leak / 0-warn capture |
| B-001 | P1 | B pos | cash drawer expectedTotal showed opening-float only (movements never hydrated) | hydrate movements once per open session | `8a41cbacf` | active dialog 142 mvts / 1727,40€ (was 0 / 50,00€) |
| G-002 | P1 | G auth | admin breadcrumb rendered raw `menu.change_password` (intlify "Not found") | add `menu.change_password` to fr.json + rebuild | `e67df4553` | change-pw capture: "Changer le mot de passe" ×3, **0 raw key, console clean** |
| K-001 | P1 | K receipt | `print-receipt` is in idempotency.required_routes but UI sent no key → **422, reprint BROKEN in prod** | fresh-per-click `X-Idempotency-Key` header | `e67df4553` | Wave K green: print1 count=1 → reprint count=2 `is_duplicata=true`, **same fiscal_sequence_no=1999**, audit chained |

### G-001 — brute-force lockout (reclassified P1 near-P0 → **P2 go-live config gap**)
- **Observed:** 13+ bad logins (raw curl, valid x-api-key, redis-backed limiter) → all 400, **0× 429**. Reproduced, not a test artifact at the HTTP layer.
- **Root cause:** limiter is **correctly wired** (`RouteServiceProvider:247-265`, `Limit::perMinutes(decay,max)->by(email|ip)->429`). The cause is **dev `.env:80 LOGIN_LOCKOUT_MAX_ATTEMPTS=500`** — an intentional E2E-convenience override **documented in `.env.example:34,46`** (heavy Playwright login churn must not self-DoS).
- **Prod-safe:** `config/auth.php` default = **10**; `.env.example` template = **10**.
- **Real residual risk (why it's recorded, not dismissed):** the `AppServiceProvider` production boot guard asserts `POS_SIMULATION_HARDWARE` / `APP_DEBUG` / `CACHE_DRIVER` / `APP_URL` / idempotency — but **NOT** `LOGIN_LOCKOUT_MAX_ATTEMPTS`. A prod `.env` carrying a high value would ship brute-force protection neutered with nothing catching it.
- **Disposition:**
  1. **GO-LIVE CHECKLIST:** prod `.env` must set `LOGIN_LOCKOUT_MAX_ATTEMPTS=10` (or unset → default 10).
  2. **BACKLOG (UNI-03 style):** add a `LOGIN_LOCKOUT_MAX_ATTEMPTS<=N` ceiling to the production boot guard so a misconfigured prod `.env` hard-fails at boot.

---

## PER-WAVE VERDICTS (latest)

| Wave | Surface | Verdict | Open P0/P1 | Notable residue |
|------|---------|---------|-----------|-----------------|
| A | Kiosk plan-A journey | GREEN | 0/0 | A-001 fixed; 4-surface + DB integrity clean |
| B | POS cash/drawer | GREEN | 0/0 | B-001 fixed; B-002/003 env/coverage P3 |
| C | KDS | GREEN | 0/0 | a11y batch (C-003/004) shipped; timer trunc P2 |
| D | OSS sync | GREEN | 0/0 | cross-surface numeric integrity clean |
| E | Admin | GREEN | 0/0 | E-001 fixed |
| F | Notif cascade | GREEN | 0/0 | OSS aria P2 |
| G | Auth/password | GREEN | 0/0 | **G-002 fixed**; G-001 P2 go-live; G-003 EN-validation-msg P2; G-005/006 P3 |
| H | Fiscal Z/X (NF525) | GREEN | 0/0 | DB chain gap-free/monotonic/dup-free (1..1994, n=1994); H-001 P2 per-order assertion not tightened |
| I | Refund netting | GREEN | 0/0 | 422-block leg correct; success/mirror leg coverage gap P2 |
| J | Livreur cash | GREEN | 0/0 | 3×P2, 1×P3 |
| K | Receipt DUPLICATA | GREEN | 0/0 | **K-001 fixed**; same-seq-no structurally guaranteed (controller only UPDATEs count) but not re-asserted post-reprint K-002/003 P2 |
| L | Customer auth/tracker | GREEN | 0/0 | no cross-customer leak (scoped by user_id); **L-001 P2 customer-web cart btn dark-on-dark + no aria-label**; L-002 mixed-locale P2 |
| M | Kiosk offline | GREEN | 0/0 | 3×P2 |
| N | Stock auto-86 cascade | GREEN | 0/0 | silent-oversell CLEARED both surfaces; cascade-on-KDS/OSS un-asserted P2 |
| O | Network errors / graceful degrade | GREEN | 0/0 | 4×P2 (recovery/overlay timing, 401→customer-login), 1×P3 |
| P | Idempotency / double-charge | GREEN | 0/0 | dedup **DB-count-hard** (after−before===1 + orderId equality), dual-layer (redis replay + DB UNIQUE); concurrent-TOCTOU path un-exercised P2 |

---

## EVIDENCE (gates)
- `php artisan fiscal:verify-chain --all` → **CHAIN OK on every active branch (1 total)**.
- Frozen §7 source diff (PaymentComponent, PosV5TrancheRow, Kiosk Wizard/App/Upsell, pos-wizard.js/css, admin-pos-v4.blade, Fiscal services, BranchScope, IdempotencyKeyMiddleware, PricingService, OrderStateMachine) = **0 lines**. ReceiptComponent.vue is NOT frozen.
- K-001 fix verified in the correct bundle: `pos-shell.js` (ReceiptComponent compiles into the PaymentComponent/POS chunk — the prior admin-shell.js rebuild had MISSED it; caught + corrected).
- Wave K spec green (12.6s), Wave P spec green (2/2, 8.2s), Wave G change-password + logout pass standalone.
- IdempotencyKeyMiddleware observed-only (frozen); idempotency.enabled=true on this box.

## P2/P3 BACKLOG (non-blocking, recorded)
- **Go-live config:** G-001 (LOGIN_LOCKOUT_MAX_ATTEMPTS=10 in prod + boot-guard ceiling).
- **i18n sweep:** G-003 hardcoded English validation messages in FR product (`*Request.php`, `lang/fr/validation.php`); L-002 "Useful Liens".
- **Customer-web a11y (LIVE surface — `STAFF_ONLY_MODE=false` in .env AND .env.example):** L-001 header cart button dark-on-dark (invisible on every customer page) + missing aria-label. Most actionable live-surface P2; not a money/data/security defect so non-blocking, but it IS customer-facing — owner may want a quick a11y pass.
- **Test-strength tightening:** H-001 per-order gap-free assertion; K-002/K-003 same-seq-no + audit_emitted hard-assert; P-001 persist decisive evidence to artifacts; P-003 exercise concurrent TOCTOU; I-002 sealed-parent refund-success leg.
- **Capture-settle:** G-006/O-002/O-001 add wait-for-animation/networkidle before snap (mid-fade frames); this is also the cause of the forget-password 20s-timeout step (form renders, input type=email confirmed — not a product defect).

## What was NOT done (scope honesty)
- No push (owner gate). No prod `.env` change. No frozen-zone code change.
- G-001 boot-guard hardening is **backlog**, not applied (would touch AppServiceProvider boot logic — owner gate territory; V1 LOCAL single-box is safe with the documented checklist).
