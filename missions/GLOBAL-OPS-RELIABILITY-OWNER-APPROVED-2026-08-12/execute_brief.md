# Execute brief — Orchestration only

This umbrella mission must not be executed as one code patch.

Claude owns PLAN, sequencing, gates and AUDIT. Each product change is a child TASK_ID executed by `codex-extension` after plan review, activity reservation and preflight.

## Owner constraint

POS CARD means a manual declaration of a payment already completed on a disconnected external TPE. FoodKing records CARD and proceeds/prints. It does not call the TPE, must not require a configured terminal, and must not claim integrated approval.

Kiosk CARD is fail-closed without a trusted integration.

## First child cycle

Start with `GLOB-OPS-01-POS-CARD-MANUAL-EXTERNAL` only after checking dirty collisions and producing a Codex plan review PASS.

## Stop conditions

- dirty collision;
- missing child gate;
- schema/fiscal/hardware boundary;
- test failure or audit REWORK;
- scope expansion;
- any attempt to reduce the global objective to the first child cycle.

