# Gate Brief – CV1-WC-T-WC-SOURCE-FK-01 – 2026-05-03

## Trigger

Schema migration required to enforce FK integrity for `source_ref`-related paths (DDL scope).

## Affected Subsystems

- `database/migrations` (new/updated migration)
- Catalog composer/wizard source linking (`source_ref`)
- Potentially `stock_levels` polymorphic consistency if included in the same migration batch

## Invariants at Risk

- `branch_id` isolation (if migration touches branch-scoped lookups)
- Runtime availability consistency during migration rollout

## Decision Required

Authorize or reject execution of schema migration cycle for SOURCE-FK hardening.

## Options

1. **Approve now (staging first, then prod)**  
   Action: run dedicated migration cycle with rollback plan and downtime-safe steps.  
   Consequence: FK integrity strengthened quickly.

2. **Approve only staging (delay prod)**  
   Action: implement migration in staging, collect soak evidence, hold prod switch.  
   Consequence: lower risk, slower closeout.

3. **Cancel cycle**  
   Action: keep logical integrity checks only, no DB FK enforcement.  
   Consequence: residual drift risk remains.

## Approval

[x] Approved — option selected: 2  
[ ] Cancelled  
Approved by: Kossay / user kossayelbenna8  
Date: 2026-05-03
