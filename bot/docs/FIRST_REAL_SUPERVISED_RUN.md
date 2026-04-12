# FoodKing — First Real Supervised Run

> Step-by-step guide for running a full human-assisted orchestration cycle
> using the local supervisor and the calibrated Claude orchestrator.

---

## Prerequisites

1. **Claude Project** is set up with the onboarding files uploaded (see `CLAUDE_PROJECT_UPLOAD_ORDER.md`)
2. **Python** is installed and `.\bot-cli.ps1 show-state` works
3. **Cursor IDE** is open on the FoodKing repo
4. All inbox/outbox folders exist (the script creates them automatically)

---

## Quick Start

```powershell
cd c:\Users\openc\Desktop\testttt
.\bot\scripts\run_supervised_cycle.ps1
```

The script walks you through every phase interactively. Follow the on-screen instructions.

---

## The Full Operator Loop

### Phase 1: Cycle Setup

The script asks for:
- **Task ID** (e.g. `T-001`) — identifies this unit of work
- **Goal** (e.g. `Fix doc/code status value mismatch in BUSINESS_RULES.md`) — one sentence

The script then:
1. Resets the bot to idle
2. Runs `begin-cycle` with your task-id and goal
3. Creates the cycle folder in `bot/state/handoffs/<cycle_id>/`
4. Transitions to `waiting_claude` (plan round)

### Phase 2: Claude Planning

**State**: `waiting_claude` / `claude_round=plan`

The script:
1. Runs a supervisor tick to generate `bot/outbox/claude/claude_handoff.md`
2. Tells you to copy-paste it into Claude

**What you do**:
1. Open your **Claude Project** in the browser (the one with onboarding files uploaded)
2. Start a new conversation (or continue the cycle conversation)
3. Copy-paste the entire content of `bot/outbox/claude/claude_handoff.md`
4. Claude reads it and produces a structured plan
5. Claude's response will contain a JSON block — extract it
6. Save it as a `.json` file (any name, e.g. `plan.json`) into:

```
bot/inbox/claude_plan/
```

**JSON requirements**:

```json
{
  "schema_version": 1,
  "cycle_id": "<exact cycle_id from script output>",
  "task_id": "<your task id>",
  "response_kind": "plan",
  "objective": "What Cursor should achieve",
  "suggested_next_actor": "cursor_execute",
  "verdict": null,
  "files_allowed": ["file1.php", "file2.md"],
  "risk_class": "low",
  "test_stance": "local-validation",
  "scope_non_goals": ["Do not touch X"],
  "human_decision": "GO"
}
```

Press ENTER in the script. The supervisor picks up the file, validates it, archives it, and transitions to `waiting_cursor`.

### Phase 3: Cursor Execution

**State**: `waiting_cursor`

The script:
1. Generates `bot/outbox/cursor/cursor_handoff.md`
2. Tells you to send it to Cursor

**What you do**:
1. Open **Cursor IDE**
2. Start a new Agent conversation
3. Copy-paste the entire content of `bot/outbox/cursor/cursor_handoff.md`
4. Let Cursor implement the plan
5. When Cursor finishes, create a `cursor_done.json` file in:

```
bot/inbox/cursor_result/
```

**JSON requirements**:

```json
{
  "kind": "cursor_done",
  "cycle_id": "<exact cycle_id>",
  "status": "done",
  "files_changed": ["docs/BUSINESS_RULES.md"],
  "summary": "Updated status enum values to match code"
}
```

Press ENTER. The supervisor transitions to `waiting_validation`.

### Phase 4: Validation

**State**: `waiting_validation`

**What you do**:
1. Run the relevant local tests:
   - `php artisan test` (PHPUnit)
   - `npm test` (Jest/Vitest)
   - `./vendor/bin/phpcs` (PHP linter)
   - `npx eslint` (JS linter)
2. Create a `validation.json` file in:

```
bot/inbox/cursor_result/
```

**JSON requirements**:

```json
{
  "kind": "validation_result",
  "cycle_id": "<exact cycle_id>",
  "status": "passed",
  "detail": "PHPUnit 42 tests OK, eslint clean"
}
```

Valid status values:
- `passed` — tests pass, proceed to review
- `failed` — **blocks the cycle** (must fix and restart)
- `skipped` — no tests applicable, proceed to review

Press ENTER. The supervisor transitions to `waiting_claude` (review round).

### Phase 5: Claude Review

**State**: `waiting_claude` / `claude_round=review`

The script:
1. Generates `bot/outbox/claude/claude_review_handoff.md`
2. Tells you to send it to Claude

**What you do**:
1. Go back to the **same Claude conversation** (same Project)
2. Copy-paste the content of `bot/outbox/claude/claude_review_handoff.md`
3. Claude reviews the execution evidence and produces a verdict
4. Extract the JSON from Claude's response
5. Save it as a `.json` file (any name) into:

```
bot/inbox/claude_review/
```

**JSON requirements**:

```json
{
  "schema_version": 1,
  "cycle_id": "<exact cycle_id>",
  "task_id": "<task_id>",
  "response_kind": "review",
  "objective": "Review summary",
  "verdict": "APPROVED",
  "suggested_next_actor": "none",
  "risk_class": "low",
  "test_stance": "local-validation",
  "scope_non_goals": [],
  "human_decision": null,
  "files_allowed": []
}
```

Valid verdicts:
| Verdict | Effect |
|---------|--------|
| `APPROVED` | Cycle completes successfully |
| `NEEDS_FIX` | Cycle needs corrections (new cycle required) |
| `NEEDS_PLAYWRIGHT` | E2E testing required before approval |
| `BLOCKED` | Hard stop — investigation needed |
| `MANUAL_GATE` | Human decision required |

Press ENTER. Based on the verdict:
- **APPROVED** → cycle completes, script exits with success
- **NEEDS_FIX / NEEDS_PLAYWRIGHT** → script reports and exits (start new cycle for fix)
- **BLOCKED** → script reports and exits with error
- **MANUAL_GATE** → script reports and exits (human decides)

---

## Which Claude Conversation to Use

Use a **Claude Project** conversation (not a standalone chat):
- The Project must have the onboarding files uploaded (see `CLAUDE_PROJECT_UPLOAD_ORDER.md`)
- Use **one conversation per cycle** for clean context
- Both the plan handoff (Phase 2) and the review handoff (Phase 5) go to the **same conversation**
- This way Claude has full context: it sees its own plan when reviewing execution

---

## Which Cursor Context to Use

- Open **Cursor IDE** on the FoodKing repo
- Use **Agent mode** (not Ask mode)
- Start a **new conversation** for each cycle (clean context)
- Cursor should have access to the full repo
- The cursor handoff tells Cursor exactly what to do, which files to touch, and which to avoid

---

## Response File Reference

| Phase | Inbox folder | Filename | `kind` / `response_kind` | Key fields |
|-------|-------------|----------|--------------------------|------------|
| Plan | `bot/inbox/claude_plan/` | any `.json` | `response_kind: "plan"` | `cycle_id`, `task_id`, `objective`, `files_allowed`, `suggested_next_actor` |
| Cursor done | `bot/inbox/cursor_result/` | `cursor_done.json` | `kind: "cursor_done"` | `cycle_id`, `status`, `files_changed`, `summary` |
| Validation | `bot/inbox/cursor_result/` | `validation.json` | `kind: "validation_result"` | `cycle_id`, `status`, `detail` |
| Review | `bot/inbox/claude_review/` | any `.json` | `response_kind: "review"` | `cycle_id`, `task_id`, `verdict` |

---

## Recovery: Invalid JSON

If the supervisor rejects a file:

1. **Read the error message** — it tells you exactly what's wrong
2. Common problems:
   - **UTF-8 BOM**: PowerShell's `Set-Content -Encoding utf8` adds a BOM. The bot handles this (utf-8-sig). If you use another tool, save as UTF-8 without BOM.
   - **cycle_id mismatch**: Copy the exact `cycle_id` from the script output, not from a previous cycle
   - **Wrong kind/response_kind**: Plan must be `"plan"`, review must be `"review"`, cursor must be `"cursor_done"`, validation must be `"validation_result"`
   - **Missing required field**: Check the templates above
   - **Invalid JSON syntax**: Validate with `python -c "import json; json.load(open('file.json'))"`
3. **Fix the file** in the inbox folder (or delete and recreate it)
4. Press ENTER — the supervisor retries automatically

---

## Recovery: Wrong File in Wrong Folder

If you placed a file in the wrong inbox:

1. **Delete** the file from the wrong folder
2. **Place** it in the correct folder
3. Press ENTER — the supervisor only reads from the expected folder for the current phase

The supervisor never processes files from unexpected folders.

---

## Recovery: Stuck Cycle

If the cycle gets stuck in an unexpected state:

```powershell
# Check current state
.\bot-cli.ps1 show-state

# See all cycle artifacts
.\bot-cli.ps1 show-cycle-files

# Nuclear reset (abandons the cycle)
.\bot-cli.ps1 reset-idle
```

Then start a new cycle from scratch.

---

## What "Good Completion" Looks Like

A successfully completed cycle means:

1. **Script exits with code 0** and prints "CYCLE COMPLETED"
2. `bot/state/cycle_state.json` shows `state: "completed"`
3. The cycle handoff folder (`bot/state/handoffs/<cycle_id>/`) contains:
   - `claude_intake.json` — initial intake
   - `claude_response.json` — Claude's plan
   - `cursor_execution.json` — execution metadata
   - `claude_handoff.md` — plan handoff sent to Claude
   - `cursor_handoff.md` — execution handoff sent to Cursor
   - `claude_review_handoff.md` — review handoff sent to Claude
   - `supervisor_inbox_archive/` — archived inbox files
4. Claude's review verdict was `APPROVED`
5. All inbox folders are **empty** (files were archived)

---

## Folder Map

```
bot/
  inbox/
    claude_plan/         <- drop plan JSON here (Phase 2)
    claude_review/       <- drop review JSON here (Phase 5)
    cursor_result/       <- drop cursor_done.json (Phase 3) and validation.json (Phase 4)
  outbox/
    claude/              <- script generates claude_handoff.md and claude_review_handoff.md
    cursor/              <- script generates cursor_handoff.md
  state/
    cycle_state.json     <- current FSM state
    handoffs/
      <cycle_id>/        <- all artifacts for this cycle
  scripts/
    run_supervised_cycle.ps1  <- this runner
```

---

## Timing Expectations

| Phase | Typical duration | Depends on |
|-------|-----------------|------------|
| Setup | 30 seconds | Operator typing |
| Claude planning | 2–5 minutes | Claude thinking + operator copy-paste |
| Cursor execution | 5–30 minutes | Complexity of the task |
| Validation | 1–10 minutes | Test suite size |
| Claude review | 2–5 minutes | Claude thinking + operator copy-paste |
| **Total** | **~15–50 minutes** | Task complexity |

---

## First Recommended Task

For your first supervised run, pick a **low-risk, high-value task** from the priority queue:

> **T-001: Fix doc/code status value mismatch**
>
> Goal: Update `docs/BUSINESS_RULES.md` and `docs/DATABASE_SCHEMA_CORE.md` to use the correct `OrderStatus` enum values from `app/Enums/OrderStatus.php`.
>
> Why: Zero code risk (docs only). Removes a known trap that could cause wrong orchestrator decisions. Already identified in `ORCHESTRATOR_STABLE_MEMORY.md` as a critical doc/code mismatch.

```powershell
.\bot\scripts\run_supervised_cycle.ps1
# Task ID: T-001
# Goal: Fix doc/code OrderStatus enum value mismatch in BUSINESS_RULES.md and DATABASE_SCHEMA_CORE.md
```
