# Task Routing

## Use Claude for
- architecture decisions
- debugging complex failures
- root-cause analysis
- synchronization logic
- order lifecycle logic
- risky refactors
- pricing and server-side integrity
- authorization and access boundaries
- cross-module reasoning
- planning and review

## Use Kimi for
- localized implementation
- UI changes
- CRUD endpoints
- repetitive code generation
- small and clear patches
- limited-scope file edits
- simple wiring between existing modules

## Default rule
If the task touches:
- auth
- sync
- pricing
- KDS
- OSS
- order state transitions
- or multiple domains

Then Claude must analyze first.

If the task is simple after analysis, Kimi may implement it.

## Execution chain

For normal development cycles, the default sequence is:

1. Anti-Gravity tests and reports
2. Claude analyzes and plans
3. Kimi implements localized tasks
4. Claude reviews and validates implementation quality
5. Anti-Gravity retests

This sequence must be preserved unless the human developer explicitly changes it.
