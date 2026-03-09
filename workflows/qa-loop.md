# QA Development Loop

The development process follows a structured loop.

1. Anti-Gravity performs testing
2. A structured report is written in reports/antigravity/
3. Cursor reads the latest report
4. Claude analyzes the report and produces a plan
5. Tasks are executed:
   - Claude for reasoning-heavy tasks
   - Kimi for localized implementation tasks
6. Execution summary is written
7. Anti-Gravity runs tests again

The human developer validates each cycle.
