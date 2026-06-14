# ULTRA-REVIEW + TEST-E2E + MAX-AUDIT — Sequence verdict (2026-06-14)

**Branch:** `heal/massive-2dot0-2026-06-14` · base `2477ff9fc` · HEAD after heals `a9c7aed0b`.
**Method:** Workflow `wf_9d4d80db-8c2` (40 agents) — 9 adversarial discovery lanes (5 refuting the 17 campaign heals with the twin-sibling lens + 4 max-auditing reactive/hidden/fiscal-edge), every finding refute-verified by 2 independent skeptics (refute-by-default) + a completeness-critic. Live test-e2e confirming pass done in-loop (single browser). Plan: `wf_104506a9-f50`.

## Heal-review verdict — the 17 campaign heals
All 17 **correct**; 4 flagged **incomplete** (a missed sibling), all caught + closed here:
- H11/H12 (delivery) correct+complete; H10/H4/H8 (netting) correct+complete; H7/H9/H1/H3/H16/H5/H6/H2/H15 correct.
- H13 retry-cron **incomplete** → DELIV-NF525-01.
- H10/H14 netting fixed the on-screen card but **not the PDF blade** → R-NET-PDF-01.
- H15 forgot-password closed the status/body oracle but **not the mail-timing oracle nor the resetPassword 404/422 sibling** → SEC-H15-TIMING-01 / SIBLING-02.

## Confirmed findings (2/2 skeptics) — 9 + 3 critic. HEALED 6, GATED 3.

### ✅ HEALED this session (TDD red→green, frozen 0, full PHPUnit 3314/0)
| # | Sev | Title | Fix | Commit |
|---|---|---|---|---|
| DV-T1 | **P0** | non-COD delivery flips PAID with no seq → escapes Z (twin of G-DELIV-FISCAL; the method's own comment admits "late card capture" reaches it) | else-branch allocates seq, mirrors COD; `DeliveryNonCodFiscalAllocTest` RED→green | `60a464ef0` |
| FISC-EXH-CPS-01 | **P1** | changePaymentStatus lets a PAID flip through on a trace with seq=NULL → escapes Z (defense-in-depth) | `allocateFiscalSequenceIfPaidAndMissing` on both branches, no-op when seq exists | `60a464ef0` |
| REACT-A-1/NEW-1/NEW-2 | **P1** | the 3 first-in-cascade Persist*ToOutbox have an unguarded sync firstOrCreate → a DB throw halts the after-commit cascade (oversell / lost loyalty / POS 500) | shared `GuardsOutboxPersistence` trait; **real test drops domain_events** to force the throw, RED-proven | `e919da62d` |
| R-NET-PDF-01 | P2 | PDF sales-report Total over-counts refunded discount/delivery vs the healed card | blade mirrors `salesReportOverview` netting exactly; render test RED-proven | `b1e96a026` |
| SEC-H15-TIMING-01 | P2 | forgot-password leaks existence via synchronous-SMTP timing on the known path | `SendResetPasswordNotification` → ShouldQueue (SMTP out of band) | `c9ab5d2ef` |
| SEC-H15-SIBLING-02 | P2 | resetPassword 404 (unknown email) vs 422 (bad token) status oracle | collapsed to identical 422; RED-proven | `c9ab5d2ef` |

(+ mail-queue baseline sentinel updated to the healed state `a9c7aed0b`.)

### 🔒 GATED → deferred to the ultra-plan (`GOAL_2DOT0_HARDENING_*`)
- **FISC-EXH-01 [P1, FROZEN]**: retry-cron can allocate a seq AFTER the day's Z closed → realized sale enters no Z. Fix touches **frozen** `ZReportService` (late-arrival window symmetric to the post-Z negative-adjustment block) → owner LOCK gate. *Forward exposure now small (most paths allocate-or-flag); the window edge remains.*
- **DELIV-NF525-01 [P2, owner-gate]**: legacy/unflagged realized-paid+seq=NULL rows escape the retry-cron. Backfill = retroactive fiscal-seq allocation on historical sales = NF525-sensitive owner gate. *Forward exposure mitigated by DV-T1/CPS-01.*
- **AUTHZ-CFG-01 [P2, UNI-03/#16 bundle]**: `env('CURRENCY')` + other request-time env() reads break under `config:cache` (prod). Must be done as ONE coherent env→config pass → cloud-prep.
- **DASH-AVGTICKET-01 [P3]**: avg-ticket denominator counts the refunded parent (numerator nets it) → small skew. Correct fix needs refund-window semantics → plan (not a one-liner).

### ❌ REFUTED (skeptics killed — empirical > inference)
- SYNC-FS-01 (allergen triple-encode): the depth-3 encoding has no producer in any write path / live row.
- REACT-NEW-2 (availability outbox halt): downstream kiosk-cache invalidation is upstream-guarded by a live-DB read.
- DV-T2 / DV-T3 / FISC-EXH-02 / FISC-EXH-03 (gateway / changePaymentStatus / PaymentService seq gaps): the trigger paths are config-gated OFF in the V1 LOCAL envelope (CASH-only, gateways disabled) — structurally true but not live-reachable. *(FISC-EXH-CPS-01 was healed anyway as cheap, reachability-independent defense-in-depth.)*

## Test-e2e (live, in-loop) — clean
6 surfaces (dashboard, sales-report, KDS, OSS, encaissement incl. Caisse Livreur, historique) render with **0 console errors**; cross-surface revenue cent-exact (**37 072,37 €** dashboard == sales-report) re-confirmed AFTER the backend heals are live (artisan serve loads them fresh). No new live P0/P1.

## BILAN
**6 new heals** (1 P0, 3 P1, 2 P2 + sentinel), **full PHPUnit 3314/0**, frozen 0, NF525 chain intact. Campaign tally **23 heals**. Every fix TDD red→green (3 RED-proven non-vacuous). Remaining = 4 gated/owner items + the open campaign gates → next-goal ultra-plan (`GOAL_2DOT0_HARDENING_CLOUD_CUTOVER_2026-06-14.md`).
