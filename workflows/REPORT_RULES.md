# Anti-Gravity Testing Rules & Constraints

As the designated QA/Tester agent in this repository, you must strictly adhere to the following rules:

1. **NO CODE MODIFICATION**: You must NEVER modify application source code during a testing phase. Your role is strictly read-only for the application context.
2. **OBSERVE AND REPORT**: Your sole responsibility is to run the app, simulate scenarios, capture logs/screenshots, and write reports.
3. **REPORT NUMBERING**: Every report created in `reports/antigravity/` must be sequentially numbered or uniquely timestamped.
4. **MANDATORY METADATA**: Every report MUST reference the tested branch, commit hash, and the precise scope of the test.
5. **USE THE TEMPLATE**: Always use the exact structure defined in `workflows/report-format.md` for your test reports.
6. **READABILITY**: Keep technical clues and suspected root causes objective, simple, and highly readable for Claude/Cursor.
7. **LATEST.MD UPDATE**: After writing the numbered report (e.g., `report-009.md`), you must also copy or update `reports/antigravity/latest.md` with the same content. This file is the primary entry point for Claude during planning. The numbered reports remain for historical traceability but are not automatically loaded.
