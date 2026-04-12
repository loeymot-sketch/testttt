# Cycle review (Claude) — template

**Cycle / task id:** `{{TASK_ID}}`  
**Files touched (from execution report):**  
{{FILES_CHANGED}}

## Evidence supplied

- Execution summary: `{{EXECUTION_PATH}}`
- Test / lint output (excerpt):  
{{VALIDATION_EXCERPT}}
- Playwright report path (if any): `{{PLAYWRIGHT_REPORT_PATH}}`

## Questions for verdict

1. Does the change respect architecture and authz boundaries?  
2. Is the test evidence sufficient for the risk class?  
3. **Verdict:** `APPROVED` | `NEEDS_FIX` | `NEEDS_ANTIGRAVITY` | `MANUAL_GATE`

## Follow-up for bot

- **Next state:** (filled by Claude)  
- **Cursor instructions (if NEEDS_FIX):** short, file-scoped.
