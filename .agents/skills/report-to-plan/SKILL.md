---
name: report-to-plan
description: Convert a QA report into a structured technical plan with Claude/Kimi task routing.
disable-model-invocation: true
---

# Report to Plan Skill

Use this skill when a QA report exists and a structured technical plan must be created.

## Steps
1. Read the latest file in `reports/antigravity/`
2. Summarize the issue
3. Identify:
   - probable root cause
   - affected files or modules
   - risk level
4. Split into atomic tasks
5. Mark each task:
   - CLAUDE
   - KIMI
6. Write output to `reports/planning/`.
