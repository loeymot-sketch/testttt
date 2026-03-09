# Anti-Gravity Test Report Format

This template MUST be used by Anti-Gravity after each test session.

```markdown
# Anti-Gravity Test Report <ID_OR_TIMESTAMP>

## Scope tested
- [Describe what was tested (e.g., POS multi-meat order, Kiosk STO selection)]

## Environment
- branch: [Current Git Branch]
- commit: [Current Commit Hash]
- app version: [Version if applicable]
- local/staging: [Environment]

## Steps executed
1. [Step 1]
2. [Step 2]
3. [Step 3]

## Passed
- [List of successful verifications]

## Failed
- [List of bugs, crashes, or unfulfilled requirements]

## Technical clues
- [Any logs, stack traces, or console errors found during testing]

## Suspected root cause
- [Anti-Gravity's hypothesis on why it failed]

## Priority
- [Low / Medium / High / Critical]

## Suggested next tasks
1. [Actionable task for Claude or Kimi]
2. [Actionable task 2]

## Attachments
- screenshots: [Paths to any captured screenshots]
- logs: [Paths to any saved log files]
- videos: [Paths to any recorded videos]
```
