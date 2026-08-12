# Gate Brief — Global Operations Reliability

**Gate ID:** `HG-GLOBAL-OPS-RELIABILITY-2026-08-11`  
**Date drafted:** 2026-08-11  
**Status:** `APPROVED_WITH_OWNER_CONSTRAINTS`  
**Source audit:** `reports/audit/AUDIT_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11.md`  
**Execution plan:** `reports/planning/PLAN_GLOBAL_OPERATIONS_CAISSE_KDS_WEB_MOBILE_2026-08-11.md`

**French human decision form:** `docs/gates/GATE_GLOBAL_OPS_HUMAN_DECISION_FORM_FR_2026-08-12.md`

**Full owner decision packet (Q1–Q29):** `docs/gates/GATE_GLOBAL_OPS_OWNER_DECISION_PACKET_FR_2026-08-12.md`

## Why a human decision is required

The audit found safe containment work, but the complete correction changes persistence and/or frozen business surfaces:

- durable operator attention delivery/seen/claim/resolution across devices;
- immutable mono/split/refund payment ledger;
- leased print jobs tied to branch, device and station;
- distinct stock and availability release proofs;
- historical-order retention/remediation;
- payment/kiosk controllers and order/payment services already governed by frozen gates.

The active Caisse V1 masterplay also retains a correction freeze. The worktree contains unrelated and overlapping modifications, including POS, printing bridges/listeners and Uber. This gate does not authorize overwriting them.

## Decision 1 — Immediate containment scope

**Recommended: Option A.**

- **Option A — Approve bounded containment:**
  - kiosk card fails closed without a trusted bridge/proof;
  - POS mono-card remains manual/external: FoodKing never contacts the TPE, records CARD for fiscal/management, prints normally, and sends no terminal identifier until the ledger persists it;
  - drawer treats only pre-write failure as safely retryable and never presents spool acceptance as physical opening;
  - existing print path no longer treats `false`, `null` or enqueue-only 202 as delivery;
  - KDS WebSocket contract and false-green test are corrected;
  - no migration, no ledger claim, no commercial GO.
- **Option B — Audit only:** preserve the current product and proceed only with design/tests.
- **Option C — Defer:** no code execution.

**Constraint:** each file must be reserved; any collision with current dirty changes stops that mission and returns a reconciliation diff to the owner.

## Decision 2 — Operator attention ledger

**Recommended: Option A.**

- **Option A — Leased responsibility claim:** delivery/seen remain informational; an authorized claim temporarily suspends the matching audio for `branch + attention kind + station/responsibility`, expires/fails over, and only a canonical business action resolves the alert. Actor/device/lease/timestamps remain auditable.
- **Option B — Permanent responsibility ACK:** a click silences the matching responsibility without a lease or canonical resolution.
- **Option C — No durable attention state:** retain status-derived/one-shot notification.

Option A avoids repeated alarms on several tills without letting a kitchen claim silence caisse or boissons permanently. Attention delivery/seen/claim remains distinct from `OrderStatus` and payment; resolution is derived atomically from the canonical permitted business action.

## Decision 3 — Printing authority and delivery semantics

**Recommended: Option A.**

- **Option A — One server queue, one active lease per logical printer:** immutable branch/order/ticket/revision/station identity, primary or standby claimant, fencing, expiry, attempts, dead-letter and worker spool-result ACK. Device is a claimant, not immutable job identity.
- **Option B — Browser remains authority:** add only a lease around the current listener.
- **Option C — KDS local authority only:** remove the global admin listener.

Option A is the only choice that simultaneously handles wrong branch, hidden/closed tabs, restart and failover. It must use `SPOOL_ACCEPTED/UNKNOWN_AFTER_SUBMIT`, because Winspool acceptance is not proof of paper. It requires schema, bridge and cutover work.

## Decision 4 — Stock release convergence

**Recommended: Option A.**

- **Option A — One saga, separate durable proofs:** reservation/release intent is common and atomic; physical stock and availability/quota keep distinct idempotent effect proofs, with lifecycle-aware consume/waste rules and one reconciler.
- **Option B — Keep `released_qty`, add an error flag:** smaller migration but preserves ambiguous partial quantities.
- **Option C — Keep current behavior:** accept possible permanent physical divergence.

Option A is recommended because the current shared counter makes a failed physical release undiscoverable after the sibling succeeds. A prepared/consumed item must not be returned automatically to `on_hand` merely because it was refunded.

## Decision 5 — Payment ledger

**Recommended: Option A after containment and fiscal review.**

- **Option A — Reopen full ledger pilot:** every mono/split/counter/refund remise produces an immutable branch-scoped entry. Manual external CARD is always identifiable as CARD for fiscal/management; a terminal identifier/label is optional and may appear only if actually persisted and branch-validated.
- **Option B — Keep restricted pilot:** correct labels/UI only; explicitly accept incomplete terminal/Z attribution for mono-tender.
- **Option C — Defer all payment changes:** leaves the UI/backend contradiction.

This decision supersedes or amends `GATE_PAYMENT_LEDGER_V1_2026-04-25` Option B only if explicitly approved. It does not approve a live gateway/TPE integration.

## Decision 6 — Historical orders and health

**Recommended: Option A.**

- **Option A — Classify before mutation:** expose current/actionable, scheduled, janitor candidate and historical orphan; generate a human-reviewed repair set.
- **Option B — Auto-cancel all old non-terminal rows:** destructive and unsafe for paid/fiscalized orders.
- **Option C — Ignore history:** health remains polluted.

No automated mutation of the observed 484 old rows is authorized by this brief.

## Existing hardware gate

No new choice replaces `HG-HARDWARE-LAB-SIGNOFF`. It remains pending **execution**. Before commercial release, the acceptance grid must be run on the real Windows/POS/kiosk/KDS network with actual TPE, printers and drawer and signed by a human.

The hardware run must also apply `reports/hardware/GLOBAL_OPS_HARDWARE_PROTOCOL_GAP_ANALYSIS_2026-08-12.md`: current TPE tests assume an integration that does not exist and cannot be marked PASS from a manual external declaration.

## Decision 7 — CSP observability and rate-limit isolation

**Recommended: Option A.**

- **Option A — Dedicated CSP bucket:** parse native CSP media types, aggregate duplicates and remove the endpoint from the effective 120/min business/public bucket while keeping a dedicated high-volume limiter.
- **Option B — Keep nested throttles:** retain `throttle:api` followed by `throttle:1000`; the effective ceiling remains the lower global limit.
- **Option C — Disable CSP reporting:** removes the request storm but also removes the security signal.

Option A is recommended. The 2026-08-12 browser measurement observed 37 CSP POSTs per POS reload and every report was logged malformed. The change must not increase the global API ceiling or weaken mutation/login/PIN limiters.

## Frozen/schema areas potentially touched

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/PaymentService.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `routes/api.php`
- new database migrations for attention, print jobs, payment ledger and stock compensation
- payment, fiscal Z, stock reconciliation and order lifecycle tests

## Non-negotiable constraints

- Backend pricing remains authoritative.
- `OrderStatus` remains the only order state enum; attention/print/TPE have separate state machines.
- Every resource/job/claim/resolution is strictly branch-scoped, including global administrators.
- Events and external dispatch happen after DB commit.
- OrderService/FrontendOrderService parity is planned and audited together.
- No existing dirty change is overwritten.
- No hardware success is inferred from a software test.

## Human approval

Decision 1: `APPROVED — OPTION A — OWNER CONSTRAINT: POS CARD MANUAL EXTERNAL, NEVER DISABLED OR CLAIMED AS INTEGRATED`  
Decision 2: `APPROVED — OPTION A`  
Decision 3: `APPROVED — OPTION A`  
Decision 4: `APPROVED — OPTION A`  
Decision 5: `APPROVED — OPTION A — FISCAL REVIEW REQUIRED; CARD METHOD RECORDED EVEN WITHOUT TERMINAL ID`  
Decision 6: `APPROVED — OPTION A`  
Decision 7: `APPROVED — OPTION A`  
Approver: `User / owner — explicit chat decision`  
Date: `2026-08-12`  
Constraints/notes: `CB POS = saisie déclarative d'un paiement déjà effectué sur TPE externe déconnecté. FoodKing doit enregistrer CARD, imprimer et poursuivre sans appeler le TPE. Le tiroir cash doit être diagnostiqué/testé physiquement. Exécution confiée à une mission Claude orchestrée car le chantier dépasse le budget souhaité. Cette décision n'est pas un GO matériel/commercial et ne self-approve aucune migration ou gate fiscal/hardware spécifique.`

> An assistant must not fill the approval fields. After a human decision, the decision must be transcribed to `docs/gates/GATE_LOG.md` before execution resumes.
