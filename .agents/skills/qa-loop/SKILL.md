---
name: qa-loop
description: Read the latest Playwright / E2E verification report, identify the next planning action, and prepare the repository for the next QA-fix cycle.
disable-model-invocation: true
---

# QA Loop Skill

Use this skill when:
- a new Playwright / E2E verification report exists
- the next planning cycle must start
- the user asks what to do next after QA

## Steps
1. Read the newest report in `reports/antigravity/`
2. Read relevant docs in `docs/`
3. Identify the likely affected domain
4. Route tasks using `workflows/task-routing.md`
5. Produce a planning file in `reports/planning/`
6. Recommend the next action.
