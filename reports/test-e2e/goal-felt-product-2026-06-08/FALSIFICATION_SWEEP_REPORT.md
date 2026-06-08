# FALSIFICATION SWEEP — production-readiness verdict (V1 LOCAL "Le Cayenne")
**Date:** 2026-06-08 · Branch `heal/pre-cloud-exec-2026-06-05` (NO push) · Supervisor: Claude (strict mode)
**Method:** 24 specialized finder agents (6 systems × 4 lenses) on live :8766, each P0/P1 independently refuted, then synthesis. Full machine output: `falsification-sweep/RESULT.json` (33 agents, ~3.5M tokens, 1152 tool calls).

## WHY THIS RAN
The felt-product campaign declared CONVERGED-GREEN, but the deep treatment had focused on the **kiosk/borne**. A strict supervisor does not trust a single campaign's self-report on the *other* systems. This sweep's mandate was to **falsify** green across POS / KDS / OSS / Kiosk / Admin / cross-system-Sync. It succeeded: it broke green. My earlier "CONVERGED-GREEN for V1" was **over-certified** for the non-kiosk systems.

## VERDICT: GO-WITH-OWNER-GATES (after the heals below)
The daily **fiscal/payment core is genuinely robust** — it survived adversarial falsification:
counter-collect double-encaissement (double-tap + two-cashier race, held under `lockForUpdate`),
refund-mirror money netting (SALES-NET-01 sentinel), ZReport discount-netting, kiosk→counter branch
isolation + payment amount-echo, KDS cross-branch IDOR + kiosk-token→admin escalation, idempotent
offline-replay, `OrderStatusChanged` envelope contract, `APP_DEBUG=false` query scrubbing.
**1 security/GDPR blocker** had to be closed first; it is now closed.

## ✅ HEALED THIS SESSION (commit `d27ebb56d`, 0 frozen-zone, each verified at source + regression-tested)
| Sev | Finding | Fix | Test |
|-----|---------|-----|------|
| **P1 BLOCKER** | Unauthenticated `/loyalty/register` (+`/opt-in`) echoed a **third party's phone + loyalty_code** in the `EMAIL_EXISTS` 409 (`LoyaltyController.php:144`) — PII oracle on a public endpoint | Stripped PII from the 409; register stays public for walk-in signup (no `auth:sanctum` — would break signup) | `LoyaltyApiTest::test_register_email_conflict_does_not_leak_third_party_pii` (6/6) |
| **P1** | POS `print-receipt` had **no permission gate** → any staff (Chef/Waiter) could write the NF525 audit-chain + flip DUPLICATA (`routes/api.php:911`) | Added `permission:pos` (mirrors `PosOrderRequest::authorize`'s `can('pos')`) | `ReceiptPrintControllerTest::test_non_pos_staff_chef_is_forbidden_and_writes_no_audit_row` (11/11) |
| **P1** | Cash-drawer close POSTed `{}` to `/reconcile`, silently dropping the cashier's mandatory variance reason (and stranding an over-2€ close CLOSED-not-RECONCILED) (`CashDrawerService.js`) | Forwards `variance_reason` in the reconcile body | `cashDrawerCloseVarianceReason.spec.js` (3/3) |
| **P1** | Kiosk promo **false-zero**: store read `data.discount` but backend returns `discount_amount` → "Code appliqué" shown, total never reduced (`kioskCart.js:585`) | Read `discount_amount` (defensive `?? discount` fallback) | `kioskCartPromoDiscountField.spec.js` (2/2) |

**Bundle:** `pos-app.js` rebuilt (`npm run dev`, cash fix); `public/js/app.js` is **gitignored** (rebuilt on deploy) and carries the kiosk promo fix via source. Sentinels: FrozenZone 1/1, AuthzDrift 1/1, Vitest sentinels 486/486.

## 🟡 FIX-SOON BACKLOG — confirmed P2/P3, none fiscal/none crash, none a V1 blocker (30 items)
Source of truth: `falsification-sweep/RESULT.json`. Triaged below by class.

### Owner-config (no code) — decidable now
- **FR-locale 12-hour AM/PM time** on POS order list, kitchen receipt, sales report, historique (`12:37 AM` instead of `00:37`). Root cause: `TIME_FORMAT="h:i A"`. **Config flip to a 24h format** (`H:i`). [P2 ×2]

### TPE-gated (V1 LOCAL routes payment to counter → screen unreachable until real terminal)
- Refused-payment screen dead CTAs ('Payer en caisse' / 'Réessayer'). Promote to P1 + heal **when `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER` flips false** (real TPE activation). [P2]

### NF525-adjacent — needs owner sign-off before touching netting (do NOT blind-heal)
- Sales-report `total_discounts` does not net refunds while `total_earnings` does (netting asymmetry). [P2]
- Dashboard "Total commandes" / "Ticket Moyen" netting vs sales-report definitional divergence (AOV understated when a paid order is cancelled). [P2 + P3]

### Sync hardening (real, bounded harm — backend rejects the duplicate; UX/stale nits)
- POS order board `fetchOrders()` last-write-wins (no AbortController/seq token → stale snapshot can clobber fresher). [P2] + per-row `_delivering` guard lost on refresh [P3]
- Admin/central KDS (`branch_id=0`) gets neither push nor fast-poll when WS up (≤60s blind). [P2]
- OSS 3 uncoordinated hydration triggers, no generation guard (double chime/flash). [P2]
- KDS `list()` N+1 on media for an image the KDS never renders. [P2]
- Shared Echo channel torn down by `KioskWaitingComponent` unmount kills the kiosk shell's realtime push for the session. [P2]
- Misc P3: 1s version-stamp collapse, SR false "prête" on double-tap, KDS poll branch_id override, OSS "connexion lente" on transport-loss-not-stale.

### Felt-number / UX polish
- Kiosk confirmation receipt shows "0 points" (rate never exposed to frontend) — false-zero. [P2]
- Kiosk promo code never consumed at order creation (uses_count not incremented) — pairs with the healed display fix; complete before discounts go live. [P2]
- Loyalty `/check` returns full name + code + allows phone enumeration. [P2]
- Menu-unavailable 'Réessayer' dead button (FP-01-style reload fix, V1-reachable on 503). [P2]
- Admin dashboard/report controllers leak `$exception->getMessage()` (model class names) — same class as the healed OSS FP-22. [P3]
- P3 cosmetics: empty-column purple loading bar, staleness pill overlaps navbar, SLA "15402 minutes", "MFS" jargon vs "Mobile", catalogue TOCTOU, no refetch-on-refocus, raw "Unauthenticated." to FR cashier.

## 🔒 OWNER GATES for actual production DELIVERY (human actions — not code defects, I cannot cross these)
- **Push** the worktree branch + **deploy**.
- `php artisan storage:link` on the box (gitignored symlink; /storage media else 404→SPA-HTML).
- Confirm **currency-position + TIME_FORMAT** config to FR before go-live.
- Frozen-zone fixes (ZReportService / pos-wizard.js / PaymentComponent) — **none surfaced as new blockers here**.
- Real-hardware **TPE activation** — the one event that promotes the refused-payment screen to a daily-path P1.

## BOTTOM LINE
The falsification sweep did its job — it broke an over-certified green and found a real PII blocker plus 3 real P1s, all now healed + regression-tested with 0 frozen-zone drift. The fiscal/payment core is verified robust. **V1 LOCAL is GO once the owner clears the deploy/config gates.** The 30-item fix-soon backlog is real hardening, none of it a halt-the-line blocker.
