# MEMORY_VERSIONING_DECISION_2026-04-26

TASK_ID: CV1-GOV-MEMORY-EPISODES-VERSIONING
Phase: A.3
Runner: codex-extension
Scope: decision brief only; no `.gitignore` or memory files edited.

## Current State

| Metric | Count |
| --- | ---: |
| Tracked `memory/episodes` files | 14 |
| Untracked `memory/episodes` files | 27 |
| Modified tracked `memory/episodes` files | 0 |

The untracked files include durable Caisse V1 domain memory:

- branch isolation
- fiscal Z
- hardware lab
- KDS release
- kiosk runtime
- migration safety
- order quote
- payment ledger/pilot
- POS guards
- sentinels
- Wave 2 Option B
- final readiness

## Recommended Policy

Recommended decision: `TRACK_MEMORY_EPISODES_FOR_V1`.

Reason:

- These JSONL files are not cache; they are part of the project's durable orchestration memory.
- They support cross-session correctness and explain why gates/options were chosen.
- Leaving them untracked makes CLOSED mission claims non-reproducible.

## Alternative Policy

`IGNORE_MEMORY_EPISODES` is possible only if a signed governance note explains:

- where durable memory is persisted instead;
- how a fresh agent reconstructs Caisse V1 decisions;
- which JSONL files are intentionally ephemeral.

## Human Signature Status

HUMAN_SIGNATURE: PENDING.

No `.gitignore` rule was added and no memory file was staged.
