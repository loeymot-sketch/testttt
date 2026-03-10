# Task Status

Use these statuses in plans and execution summaries:

- TODO
- IN_PROGRESS
- BLOCKED
- READY_FOR_REVIEW
- READY_FOR_RETEST
- DONE

## Rules
- A task cannot be DONE before review or retest if required
- A task that changes business logic should usually become READY_FOR_RETEST after implementation
- Use BLOCKED when docs, code, or behavior are contradictory.
