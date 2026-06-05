# TOTAL decomposed adversarial validation sweep — all 6 systems (the "total" the owner asked for)

**Date** 2026-06-05 · **Branch** `heal/pre-cloud-exec-2026-06-05` · **Method** multi-agent workflow
(`pre-cloud-total-validation`, run `wf_91714cef-e4d`): one adversarial prober per system (read-only + test-only,
`agentType: Explore`), each decomposing its system into functionalities and hunting NEW non-frozen P0/P1, then a
**skeptic verifier per finding** (defaults to refute; confirms only on file:line + reproduction). Opponent agent
always on. 10 agents, ~581k subagent tokens, 642 tool-uses, ~25min. I (orchestrator) stayed supervisor and
adjudicated every result — I did NOT trust the count blind.

## Coverage
| System | Functionalities probed | Raw findings | Confirmed NEW non-frozen P0/P1 |
|---|---|---|---|
| CAISSE-POS (the box) | 13 | 0 | 0 |
| BORNE-kiosk | 13 | 1 | 0 |
| KDS (cooking screen) | 11 | 1 | 0 |
| OSS (the board) | 6 | 1 | 0 |
| CENTRAL (dashboard/history/mgmt) | 8 | 1 | 0 |
| SYNC (total realtime) | 12 | 0 | 0 |
| **TOTAL** | **63** | **4** | **0** |

## Adjudication of the 4 raw findings (all correctly resolved — no false-negative on a real P0/P1)
1. **BORNE — "Plan-B card bypass not enforced server-side" (claimed P0)** → **NOT_A_DEFECT.**
   `FrontendOrderService.php:186` (truth table :215-236) documents it as intentional: a kiosk CARD order has
   `shouldDispatchNewOrderSignals=FALSE` (never reaches KDS), no `fiscal_sequence_no` at create, and stale
   UNPAID is auto-rejected after 180min (`CleanupStalePendingKioskOrders`). A UI-bypassed CARD order is a
   harmless PENDING/UNPAID with no kitchen release, no fiscal gap, no money flow. Consistent with the owner's
   binding terminals=manual / Plan-B-route-to-counter direction.
2. **KDS — "changeStatus lacks release-rule guard, allows bumping unreleased orders" (claimed P1)** →
   **NOT_A_DEFECT.** `KitchenDisplaySystemOrderService.php:386` has no explicit `isReleasedToKitchen()` check,
   but the precondition is unreachable: orders reach ACCEPT only when PAID/PENDING_COUNTER (POS
   `OrderService:783`; kiosk gated by `finalizePaidKioskOrder:1161`). M8-01/M3-01 mitigated-at-earlier-layer
   pattern. **Optional P3** defense-in-depth (add the explicit guard) — non-blocking, non-frozen.
3. **CENTRAL — "LicenseController::index ungated, leaks API key" (claimed P1)** → **P3.**
   `LicenseController.php:18` is behind the `x-api-key` middleware (route file:295) + `auth:sanctum`; the
   `license_key` returned IS the `MIX_API_KEY` you must already hold to pass that middleware → not a privilege
   leak. Real **P3** consistency nit (Mail/SmsGateway gate their index with `permission:settings`) — non-blocking.
4. **OSS — "public wall leaks all orders when zero active branches exist" (claimed P1)** → **confirmed REAL,
   downgraded P2.** `OrderStatusScreenController.php:88` — a zero-active-branches edge. **Unreachable in V1**
   (single-branch Le Cayenne always has ≥1 active branch), so not a V1 cloud blocker; **genuinely relevant to the
   multi-branch cloud future** → logged as a cloud/multi-tenant hardening item.

## Verdict
**Across 63 functionalities in all 6 systems, the independent decomposed adversarial sweep found ZERO new
non-frozen P0/P1.** The 4 raw findings were soundly adjudicated (2 intentional/mitigated, 1 P3 consistency,
1 real-but-P2 multi-branch edge). This **hardens** the 16/19 + live-SYNC validation to a full "validated all well"
for everything outside the §7 frozen wall. The discipline held (verify-before-report killed every speculative
finding; the same scrutiny that earlier disproved M8-01/M3-01).

## Non-blocking hardening backlog surfaced (NOT cloud-P1, logged not healed — scope discipline)
- **OSS zero-active-branches order exposure** (`OrderStatusScreenController.php:88`) — P2, multi-branch/cloud.
- **KDS explicit release guard** (`KitchenDisplaySystemOrderService.php:386`) — P3 defense-in-depth.
- **License index permission gate** (`LicenseController.php:18`) — P3 consistency with Mail/SmsGateway.

## Cloud-readiness (unchanged by this sweep, now better-evidenced)
**GO outside the frozen wall (16/19 + live SYNC + 63-functionality adversarial certification).** Remaining = the
3 reality-verified FROZEN P1 (M6-002/S13-02 ZReport, M3-02 frites under-billing, G-H PaymentComponent) needing
owner gate-G countersign. No non-frozen engineering remains.
