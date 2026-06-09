# RUNBOOK NOTES (supervisor audit — operational guidance)
2026-06-09. Owner-facing notes that are operational policy, not code fixes.

## SPINE-01 (P3) — KDS/OSS operator accounts
**Operate the KDS and OSS screens under `chef@lecayenne.fr` or `pos@lecayenne.fr` (branch_id=1), NEVER habitually under `admin@lecayenne.fr` (branch_id=0).**
- Why: `KitchenDisplaySystemComponent.vue:1950` early-returns `if (branchId <= 0)` — admin (branch_id=0) spans all branches, so it does NOT bind the `private-branch.1` Echo channel and falls back to the ~60s admin poll (a *deliberate* KDS-03 design, visibly flagged in FR: "Mode admin centralisé : rafraîchissement automatique toutes les 60 s").
- The seeded operator accounts `chef@`/`pos@` are branch_id=1 and DO receive sub-second WebSocket push — so the kitchen sees orders/recalls in ~1s.
- Action: confirm the physical KDS/OSS terminals log in as chef@/pos@, not admin@. (If admin-on-KDS is ever the real practice on a single-branch install, a single-branch fast-path is a small follow-up — `channels.php:56` already authorizes admin on `branch.{id}`.)

## GATE reminders (owner decisions — see GOAL §G)
- GATE-INT-1: branch integration target (manifest ready: `branch-merge-manifest.md`).
- GATE-FROZEN-1: CAISSE-01 fix route (frozen `pos-wizard.js` LOCK vs server-side `PricingService`).
- GATE-LOYALTY-1 / PUBLISH-1 / DATA-1: mobile ratio · web publish · operating-DB clean-state.
