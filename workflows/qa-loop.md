# QA Loop

This repository uses a strict AI-assisted development cycle.

## Loop
1. Anti-Gravity performs QA testing
2. Anti-Gravity writes a report in `reports/antigravity/`
3. Cursor reads the latest report
4. Claude analyzes the report and writes a plan in `reports/planning/`
5. Tasks are routed according to `workflows/task-routing.md`
6. Implementation is executed
7. Execution summary is written in `reports/execution/`
8. Anti-Gravity retests
9. Human validates before the next cycle

## Human role
The human developer remains the final authority and decides whether to continue, revise, or stop a cycle.

## Important rule
No cycle is complete until:
- a report exists
- a plan exists
- execution notes exist
- retest is completed.

## Multi-agent operating model

This repository follows a strict multi-agent operating loop.

### Official role sequence
1. Claude thinks
2. Kimi builds
3. Claude reviews
4. Anti-Gravity tests
5. Human validates and decides the next cycle

### Report flow
- Anti-Gravity writes QA reports to `reports/antigravity/`
- Claude reads the latest Anti-Gravity report and writes plans to `reports/planning/`
- Kimi reads the latest approved planning file and implements only scoped tasks
- Kimi or the orchestrating agent writes execution notes to `reports/execution/`
- Claude reviews the execution against docs, business rules, architecture, and risks
- Anti-Gravity retests based on the latest approved implementation

### Critical rule
Agents must not skip the report chain.
Every important change must be traceable through:
1. a QA report
2. a planning file
3. an execution summary
4. a retest

### Responsibility boundaries
- Claude does not perform broad blind implementation when a local task can be delegated
- Kimi does not make architecture decisions
- Anti-Gravity does not modify application code
- The human developer approves continuation of the loop.
