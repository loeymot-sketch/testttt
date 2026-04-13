# Bot runtime states

Finite states for the orchestration loop. Exact persistence format (JSON on disk, SQLite, or external store) is **TBD**; semantics are fixed here for operators and future code.

| State | Meaning |
|--------|--------|
| **`idle`** | No active cycle, or cycle finished and queue empty. Safe to accept a new trigger. |
| **`preparing_intake`** | Bot is gathering context (paths, last reports, optional human paste) before invoking Claude. |
| **`waiting_claude`** | Outbound request to Claude (plan or review) is in flight or awaiting response file. |
| **`waiting_cursor`** | Plan approved; waiting for Cursor to finish implementation / validation. |
| **`waiting_validation`** | Cursor reported done; bot is checking tests/lint outputs or file freshness before handoff to review. |
| **`waiting_playwright`** | E2E or browser evidence required; runner not yet completed or report not ingested. |
| **`blocked`** | Unrecoverable error, missing tool, or repeated failure — **Telegram alert** recommended. |
| **`manual_gate`** | Human decision required (STOP, scope dispute, production risk); **Telegram alert** recommended. |
| **`completed`** | Cycle closed successfully; transition to **`idle`** after archival / notifications. |

## Suggested transitions (illustrative)

- `idle` → `preparing_intake` (trigger received)  
- `preparing_intake` → `waiting_claude` (intake package ready)  
- `waiting_claude` → `waiting_cursor` (plan GO) **or** `manual_gate` (MODIFY/STOP) **or** `blocked` (tool/parse error)  
- `waiting_cursor` → `waiting_validation` (Cursor signals done)  
- `waiting_validation` → `waiting_claude` (forward to review) **or** `waiting_playwright` **or** `blocked`  
- `waiting_playwright` → `waiting_claude` (review with E2E evidence) **or** `blocked`  
- `waiting_claude` (review) → `completed` | `waiting_cursor` | `waiting_playwright` | `manual_gate` | `blocked`  
- `completed` → `idle`

Implementations may add substates or timestamps; they should not contradict the semantics above.
