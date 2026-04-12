# Cursor execution wrapper — template

You are executing a **bounded** FoodKing change per the approved plan.

**Task id:** `{{TASK_ID}}`  
**Plan reference:** `{{PLANNING_PATH}}`  
**Files allowed (from plan):**  
{{FILES_ALLOWED}}

## Rules

- Stay inside **files_allowed** and plan scope.  
- Do not weaken server-side pricing, authz, or order state rules.  
- After edits, run validations specified in the plan (tests, lint).

## Deliverables

1. Short summary of what changed.  
2. List of commands run + pass/fail.  
3. Update `reports/execution/latest.md` per `workflows/report-format.md` / project rules.
