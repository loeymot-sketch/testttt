# Cursor validation wrapper — template

**Task id:** `{{TASK_ID}}`  
**Post-change validation only** — no feature work unless the plan explicitly includes fixes.

## Commands to run (from plan)

```text
{{VALIDATION_COMMANDS}}
```

## Record

- Paste relevant stdout/stderr excerpts into `reports/execution/latest.md`.  
- If any command fails, set bot state to **blocked** or **waiting_claude** per operator playbook (implementation TBD).
