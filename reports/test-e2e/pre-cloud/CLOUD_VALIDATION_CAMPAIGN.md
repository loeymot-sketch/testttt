# CLOUD VALIDATION CAMPAIGN — total decomposed system validation → cloud-readiness

**Started** 2026-06-05 · **Supervisor/orchestrator**: Claude (GStack + Superpowers + adversaries).
**Owner mandate**: validate the WHOLE box, system by system, functionality by functionality, with a
dedicated intelligent agent per detail, adversary always on, looping until each is validated with
proof (technical + interface + logic + sync + visual-timing + vision), to pass to cloud. No limit on
loops. I stay orchestrator/planner/supervisor; agents carry memory+goal+disciplines+deep reasoning.

## The 6 validation dimensions (every functionality must pass ALL)
1. **Technical** — backend correctness; PHPUnit/Vitest green; 0 errors; NF525 chain intact.
2. **Interface** — visual capture analyzed (Read tool); no raw labels; layout/branding/i18n; a11y; 0 console errors.
3. **Logic / reasoning** — business rules correct (pricing SSOT, transitions, edge cases).
4. **Synchronization** — realtime cross-surface (borne→KDS→OSS→caisse); latency measured; degradation path.
5. **Visual-with-timing capture** — Playwright screenshot + timing proof, read & analyzed.
6. **Vision / direction** — aligned with V1 LOCAL Le Cayenne envelope; frozen respected; NF525; FR; no-cloud-creep.

## The systems (each = a wave; each decomposed into functionalities = 1 agent each)
- **S-CAISSE (the box / POS)**: order-taking, wizard popup, discount, park/recall, payment/encaissement
  (cash/TR/terminal-manual), drawer session, refund (pre-Z/post-Z), receipt/NF525, offline-replay, idempotency.
- **S-BORNE (kiosk)**: idle, wizard steps (mandatory-step validation), upsell, cart, payment Plan-B routing to counter, token/Sanctum.
- **S-KDS (cooking screen)**: order board, customization render, bump/recall, status transitions, sync-in.
- **S-OSS (the board)**: client status screen, allowlist (KIOSK/TAKEAWAY), no-PII, sync-in.
- **S-CENTRAL (dashboard/history/management)**: dashboard metrics (net/realized), date filters, history (snapshot frozen, no leak), catalog/stock, users/RBAC, reports/Z, settings, cash reconciliation.
- **S-SYNC (total real-time)**: OrderCreated/OrderStatusChanged/KdsRecalled events, branch.{id} channel, soketi/WebSocketService, outbox/degradation, latency budget.

## Per-functionality agent contract (memory + goal + disciplines)
Each dispatched agent receives:
- **Memory**: CONSTITUTION vision, frozen list, NF525 invariants, this campaign, relevant prior reports.
- **Goal**: validate ONE functionality across the 6 dimensions; produce structured verdict + evidence.
- **Disciplines**: read-only for audit (Explore); ANCHOR-FIRST (verify file:line vs live code); evidence
  before assertion (quote real output/screenshot); skeptical default (uncertain → not-validated).
- **Output schema**: {system, functionality, dims:{technical,interface,logic,sync,visual,vision}, verdict:
  VALIDATED|NEEDS_FIX|BLOCKED, issues[severity,desc,file_line,repro], evidence}.

## The LOOP (per system, no limit)
```
for each SYSTEM (wave):
  1. DECOMPOSE into functionalities (one agent each).
  2. DISPATCH read-only audit+capture fleet (parallel) — each agent scores the 6 dims with proof.
  3. ADVERSARIAL DISPUTE — RED agent tries to refute each "VALIDATED" + find hidden defects.
  4. SYNTHESIZE → confirmed worklist (P0/P1 first).
  5. If NEEDS_FIX: orchestrate → plan → heal (TDD, scope-minimal, frozen→LOCK+gate) → re-test → visual.
  6. CONFIRM test (re-run) → reasoning audit + max-logic test.
  7. If not validated → goto 1 (loop). Max 3 heal loops on same defect → escalate owner.
  8. When P0+P1=0 across 2 identical cycles AND all 6 dims green with proof → SYSTEM VALIDATED → next.
DONE = all 6 systems VALIDATED + NF525 chain attested + frozen-diff=0(or LOCK) + sync proven → cloud-ready.
```

## Sequencing (risk-first; reuses the 19-P1 worklist as the backend spine)
- **PHASE 1 (running)**: ultra-review + code-review of work done (4 P1 fixes + reasoning) — adversarial. Gate before E2E.
- **PHASE 2**: S-CAISSE (the box) — highest risk, holds the 19-P1 backend spine (operator-identity, refund, cash-trail, split, discount, offline). Backend heals + E2E.
- **PHASE 3**: S-SYNC + S-KDS + S-OSS + S-BORNE — realtime cross-surface E2E with timing capture.
- **PHASE 4**: S-CENTRAL — dashboard/history/management E2E.
- **PHASE 5**: frozen-gated cluster (G-H unified encaissement fusion, M6-002/S13-02 ZReport, M3-x wizard) — ONLY after owner gate-G countersign.
- **PHASE 6**: final convergence ×2 identical cycles + NF525 attestation + cloud-readiness verdict.

## Hard gates (non-negotiable, every wave)
Frozen-diff=0 unless LOCK+countersign · NF525 `fiscal:verify-chain` appended-only · visual capture analyzed ·
adversarial RED before "validated" · no push without owner · no `config:cache` on live · scope-minimal · TDD.

## Status
- PHASE 1: dispatched (wf phase1-ultrareview). Awaiting verdict → then PHASE 2.
