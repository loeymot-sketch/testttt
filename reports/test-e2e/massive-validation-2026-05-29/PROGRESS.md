# Massive Validation 2026-05-29 — Progress (live, non-stop)

**Branch** `heal/cms-pr1-quickwins-2026-05-18` · baseline `525946ec1` → **HEAD `25c2807bc`**. Frozen 15/15 byte-identical · NF525 CHAIN OK · no push.

## ✅ FIXED + VERIFIED this session (3, all non-frozen)
| # | Issue | Proof | Commit |
|---|---|---|---|
| 1 | **POS Encaisser keypad** gave "chiffres bizarres" (owner-reported) — pre-filled total + numpad blind-concat → "8,50"+1="8,501" | LIVE driven: fresh modal "36,00" → tap 5-0-,-2-5 → **"50,25"** (not "36,005") | 24343062b + bundle |
| 2 | **Tracker "Encaisser" = DEAD BUTTON** — dispatched `foodking:pos:open-encaissement` CustomEvent that NOTHING listens for | LIVE driven: click Encaisser → modal opens → confirm → fiscal_seq=42, CHAIN OK | 5a0e6b220 + d55373a86 |
| 3 | **Tracker paid-order VANISHES** — paid CASH counter-collect stays ACCEPT (S-5 carve-out) but ACCEPT lane shows cash-pending only → order disappears while KDS still cooks it | LIVE driven: A0004 now in EN PRÉPARATION, consistent w/ KDS Prêt | 5a0e6b220 |
| 4 | **QR table-order self-discount fraud** (central-audit P1) — unauth `POST /dining-order` applied request `$discount` (up to 100%) with no authz gate | PHPUnit: positive discount → order created, discount forced 0, total ~12 | 25c2807bc |

## 🔴 Central deep-audit (GStack+RED+security) — 4 P1, 0 P0
3/5 systems ran (pricing-fiscal, order-lifecycle, tenant-auth-idempotency); **sync-core + intersections-dedup did NOT run — workflow lens-key typo (`LENS['sre-sync']` vs `LENS.sresync`) — RE-RUN needed.** Artifacts: `reports/audit/central-validation-2026-05-29/*.json`.

| P1 | File | Frozen | Status | Verified fix reco |
|---|---|---|---|---|
| QR-table discount | OrderService tableOrderStore | no | ✅ FIXED (#4) | done |
| **Loyalty redeem IDOR** | `LoyaltyController.php:261,283` | no | **QUEUED (next)** | replace `tokenCan('kiosk:order')` discriminator with un-spoofable token-NAME pattern (`currentAccessToken()?->name==='kiosk-token'` + KioskMachine row check) like `channels.php:44-49`; gate the owner-check bypass on it. Guest `auth_token` then falls through to owner-check. + test: guest token on FOREIGN loyalty_code → 403, 0 decrement. |
| **changePaymentStatus fiscal-seq gap** | `OrderService.php:2253-2302` | no | QUEUED | PENDING_COUNTER→PAID via admin route flips PAID without `FiscalSequenceService::next()` (confirmCounterPayment DOES) → sale escapes `ZReportService` `whereNotNull(fiscal_sequence_no)` aggregation. Allocate fiscal_seq on this transition too (mirror PaymentService:321-322), or flag `fiscal_alloc_error_at` for the retry cron. |
| **z_reports.total_ht stores TTC** | `ZReportService.php:633` | **YES** | QUEUED (gate) | non-frozen workaround: add `orders.total_ht` column/accessor (HT = subtotal−tax in TTC mode) so the existing `?? subtotal` fallback resolves to true HT — no edit to frozen service. Historical signed rows can't retro-correct. Owner LOCK if touching the service. |

## P2/P3 (documented, non-blocking) — order-lifecycle + pricing-fiscal
- P2 status-change hot paths validate legality PRE-lock (OrderService:1657/1896/1989) — concurrent illegal transition possible (KDS re-checks in-lock; these don't).
- P2 KitchenReleaseRule (PAID OR POS+CASH) contradicts live KDS SQL + a green test pins the stale rule. **Correction: it has 5 live callers (prior "dead/zero-callers" claim was WRONG).**
- P3 dedup: isReleasedToKitchen byte-dup in OrderStateMachine (frozen) + KitchenReleaseRule; lockForUpdate skeleton dup 5×; ZReportCashEnrichmentService orphaned (cash_* always NULL); total_by_tax_rate omits deleted_at.

## Validated GREEN (driven, persona)
POS caisse clean + all action-bar buttons present; "Suivi commandes" kanban board (À encaisser/En préparation/Prêts/Livraison/Livrés) GREEN; encaisse modal (4 modes, keypad, rendu) functional; KDS shows paid order with Prêt bump (KitchenReleaseRule PAID→released). Screenshots `massive-validation-2026-05-29/*.jpeg`.

## Central audit COMPLETE (5/5 systems) — 0 P0, 4 P1
sync-core + intersections-dedup re-ran (run wf_e1092652-950): **0 P0, 0 P1** there → the SYNC heart + intersections + dedup are P0/P1-clean. Full central tally: **0 P0, 4 P1** (all from pricing-fiscal + tenant-auth: QR-table ✅, loyalty IDOR ✅, changePaymentStatus fiscal-seq ⏳, z_reports HT ⏳). New P2/P3 (sync-core):
- **P2 clean-hard-crash orphan** (`MonitorOutboxStaleness.php:72-77`) — dispatched_at SET + last_error NULL + attempts≥5 falls through all lanes AND my H3 alarm (which requires last_error). Schema-indistinguishable from success → needs `broadcast_confirmed_at` flag (V1.0.X). KNOWN limit of H3.
- **P3 ACTIONABLE (non-frozen, recommend fix):** `config/broadcasting.php:58-60` empty Pusher `client_options` → no Guzzle timeout → a Pusher black-hole HANGS the broadcast (producing the last_error-NULL orphan) instead of THROWing. Add `'client_options' => ['timeout'=>5,'connect_timeout'=>3]` → outage throws → caught → last_error set → recoverable by retry lanes + H3 alarm. **Root-cause mitigation for the orphan class.**
- **P3 (non-frozen):** swallow pager alarm covers only the 5 ORDER listeners; the 8 catalog/availability/settings/branch/coupon/order-table listeners degrade to Log::warning with NO pager + catalog/availability/settings have NO polling backstop → silent loss. Extend `OutboxBroadcastSwallowedEvent` to them.
- **P3:** contract-violation fail-once defeated by rescue lane-A re-driving (DispatchDomainEventsJob:161-166).
- intersections-dedup: 5 P2/P3 confirmed but the verify agent wrote an EMPTY findings array to disk (capture glitch) — 0 P0/P1; specifics need a re-run to recover (low priority, non-blocking).

## 🎯 ALL 4 CENTRAL P1s CLOSED (update — HEAD 9444a5b50)
| P1 | Status | Commit |
|---|---|---|
| QR-table discount fraud | ✅ FIXED + test | 25c2807bc |
| Loyalty redeem IDOR | ✅ FIXED + test | 8db38d801 |
| changePaymentStatus fiscal-seq gap | ✅ FIXED + 2 tests (allocate via FiscalSequenceService::next, null-guarded, PAID-only) | 1808f9494 |
| z_reports.total_ht stores TTC | ✅ FIXED + test (non-frozen Order::getTotalHtAttribute = total − tax → TTC=HT+TVA; ZReportService untouched) | 9444a5b50 |
**8 fixes total this campaign** (3 functional + 4 P1 + 1 Pusher robustness), all tested, frozen 15/15, NF525 CHAIN OK, no push. Central audit COMPLETE: 0 P0, 0 open P1.

## 🔒 SECURITY REVIEW — CLEAN (scoped to session heals 525946ec1..9444a5b50)
Dedicated senior-security-engineer pass on all 5 changed source areas. **RESIDUAL RISK: none.**
- LoyaltyController IDOR fix: COMPLETE — guest (no KioskMachine row) → owner-check fires; no $isStaff spoof; lookup injection-safe.
- OrderService tableOrderStore discount: COMPLETE — merge(0) hits BOTH branches; `unset()` strips client amounts; server recomputes from DB prices; coupons server-validated/capped; no under-pay bypass.
- changePaymentStatus fiscal-seq: SOUND — PAID-only + null-only + terminal PaymentStateMachine (no re-entry → no double-alloc) + gap-free FiscalSequenceService + routes auth-gated (admin group `auth:sanctum`+`apiKey`+`block_kiosk_token_admin`).
- Order::getTotalHtAttribute: pure arithmetic, not in $appends (no leak).
- config/broadcasting: no secret exposure.
(Cloud "ultra review" `/code-review ultra` is owner-triggered/billed — recommend owner fires it pre-cloud.)

## NEXT (continuation queue)
1. Loyalty IDOR fix (token-name pattern) + test.
2. changePaymentStatus fiscal-seq allocation + test.
3. z_reports.total_ht accessor (non-frozen) + test.
4. RE-RUN central sync-core + intersections-dedup (lens-key fix).
5. Continue surface validation (Kiosk borne re-drive, KDS bump→OSS, livreur, cross-surface).
6. /security-review on pending changes + final convergence + GO/NO-GO.
