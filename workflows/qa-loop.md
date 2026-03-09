# The Strict QA Loop (Test -> Plan -> Execute)

This repository operates on a strict Quality Assurance Loop to ensure code stability and separation of concerns.

## Roles
- **Anti-Gravity**: The Auditor & Tester. DOES NOT modify application code. Only tests, observes, and reports.
- **Cursor / Claude**: The Planner & Lead Architect. Reads reports, designs solutions, handles complex tasks.
- **Kimi**: The Implementer. Executes simple, scoped tasks fast.

## The Loop Steps
1. **Anti-Gravity Tests**: Anti-Gravity runs the application, simulates user flows, and verifies requirements.
2. **Writes Report**: Anti-Gravity creates a structured markdown report in `reports/antigravity/` using the format in `workflows/report-format.md`.
3. **Cursor Reads**: Cursor reads the latest report to understand the current state and failures.
4. **Claude Creates Plan**: Cursor/Claude creates a detailed execution plan in `reports/planning/` based on the test report.
5. **Execution Routing**: Based on `workflows/task-routing.md`, the plan is assigned to Claude (complex) or Kimi (simple).
6. **Execution Summary**: The executing agent writes a summary of the changes made in `reports/execution/`.
7. **Anti-Gravity Retests**: Loop goes back to step 1. Anti-Gravity reads the execution summary and retests the scope to verify the fix.
