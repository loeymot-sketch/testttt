# Bot architecture — FoodKing orchestration

This document describes the **intended** autonomous loop. **Runtime code** under `bot/` is scaffolding only until a worker/daemon is implemented.

## Actors

| Actor | Role |
|--------|------|
| **Human / CI trigger** | Starts or approves a cycle (feature, fix, GO/MODIFY/STOP). |
| **Bot** | Intake, state machine, file watchers, routing, Telegram notifications, invoking external CLIs. |
| **Claude** | Planning, review, escalation decisions, model-routing hints. |
| **Cursor** | Bounded implementation and local validation per plan. |
| **Playwright** | Optional E2E / critical UI evidence when the plan or review requires it. |
| **Telegram** | Alerts on **blocked**, quota, or **manual_gate** conditions. |

## Future control loop (high level)

```text
Human / trigger
      │
      ▼
┌─────────────┐
│ Bot intake  │  assemble context pack, set state → preparing_intake
└──────┬──────┘
       ▼
┌─────────────┐
│   Claude    │  plan + test type + scope (reports/planning/latest.md pattern)
└──────┬──────┘
       │ human GO (or automated gate policy — TBD)
       ▼
┌─────────────┐
│   Cursor    │  execute; write reports/execution/latest.md
└──────┬──────┘
       ▼
┌─────────────┐
│  Reports    │  sync / validate paths vs AGENTS.md workflows
└──────┬──────┘
       ▼
┌─────────────┐
│   Claude    │  review verdict (reports/review/latest.md)
└──────┬──────┘
       ├── APPROVED → completed (+ optional Playwright if already satisfied)
       ├── NEEDS_FIX → Cursor again (waiting_cursor)
       ├── NEEDS_ANTIGRAVITY → waiting_playwright / manual trigger
       └── MANUAL_GATE → notify Telegram, hold
       ▼
┌─────────────┐
│ Next action │  enqueue follow-up cycle or idle
└─────────────┘
```

## Configuration surfaces

- **`bot/config/bot_config.example.json`** — repo paths, poll interval, downstream config paths.  
- **`bot/config/model_routing.example.json`** — task-kind → model tier (implementation TBD).  
- **`bot/config/paths.example.json`** — report file conventions.  
- **`bot/config/telegram.example.json`** — alerting (secrets out of git).

## Non-goals (for the bot layer)

- No substitution for **FoodKing business logic** in `app/`.  
- The bot **coordinates**; it does not silently change pricing, authz, or order lifecycle rules.
