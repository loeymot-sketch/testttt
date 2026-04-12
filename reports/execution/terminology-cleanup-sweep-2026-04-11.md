# Terminology cleanup sweep — retired QA vocabulary (2026-04-11)

## Replacements applied

| Retired term | Replacement (semantic) |
|--------------|-------------------------|
| `NEEDS_ANTIGRAVITY` | `NEEDS_PLAYWRIGHT` |
| `Kimi-test` | `local-validation` (bulk; PHPUnit/Jest-style plans) |
| `Anti-Gravity` | `Playwright / E2E verification` (bulk prose); operational docs refined to **Playwright / E2E** where redundant |

**Unchanged by design:** directory path `reports/antigravity/` (and filenames such as `guide-test-e2e-antigravity.md`, `AntiGravityTest.php`) — paths and PHP class names kept to avoid breaking structure or code.

**Intentional remaining mentions of old labels:** `reports/README.md` § “Legacy mapping” documents **Kimi-test** and **Anti-Gravity** as historical labels for readers of old plans.

## Follow-up edits (post-bulk)

- `reports/README.md` — corrected legacy-mapping sentence after bulk replace.
- `.cursor/rules/playwright.mdc` — fixed table row duplicated by bulk replace; aligned to `playwright-*` vocabulary.
- `workflows/task-routing.md` — section heading shortened (“Use Playwright / E2E for”).
- `reports/antigravity/AUDIT_BLOCAGE_COMMANDES_20260312.md` — de-awkwarded “Gemini/…” and headings.
- `tests/Feature/MenuSeederTest.php`, `tests/Feature/PosPriorityApiTest.php` — docblock terminology.

## Files touched (operational priority first)

**Rewritten / heavily updated**

- `AGENTS.md`
- `reports/README.md`
- `reports/review/README.md`
- `workflows/qa-loop.md`
- `workflows/task-routing.md`
- `workflows/report-format.md`
- `workflows/REPORT_RULES.md`
- `.cursor/BUGBOT.md` (targeted lines)

**Bulk-updated** (same three string replacements where present)

All paths printed by the bulk pass (excluding `vendor` / `node_modules` / `.git`), plus the follow-up files above if not already listed:

`.agents/skills/qa-loop/SKILL.md`  
`.cursor/commands/plan-next.md`  
`.cursor/commands/prepare-retest.md`  
`.cursor/rules/global-operating-principles.md`  
`.cursor/rules/playwright.mdc`  
`docs/ops/CLAUDE_SCORING_RUBRIC.md`  
`docs/roles/03_DEEP_AUDIT_ROLE.md`  
`docs/AI_CHANGE_GATES.md`  
`docs/CONTRIBUTING_QA_BOTS.md`  
`docs/DEBUG_GUIDE.md`  
`docs/GATES_DOCTRINE.md`  
`kiosk_implementation/README_INSTALLATION.md`  
`kiosk_implementation/STRUCTURE_BASE_COMPLET.md`  
`prompts/claude/plan-task.md`  
`prompts/claude/planning-prompt.md`  
`prompts/cursor/read-and-plan.md`  
`prompts/kimi/execute-simple-task.md`  
`reports/antigravity/*` (all `.md` reports and audits under that folder that contained matches)  
`reports/execution/*` (matched execution archives)  
`reports/guides/guide-test-e2e-antigravity.md`  
`reports/knowledge-transfer/05-ROADMAP.md`  
`reports/planning/**` (matched plans, kimi-plans, sprint docs, etc.)  
`reports/review/sprint_22_review.md`  
`reports/ARCHITECTURE_BASE_REFONDE.md`  
`reports/audit-menu-grillhouse.md`  
`reports/PLAN_ACTION_GLOBAL.md`  
`reports/SYNTHESE_COMPLETE_PHASE_1_2.md`  
`PLAN_IMPLEMENTATION_MENU_FINAL.md`  
`README.md` (root — one line role list)

## Unrelated content

No product logic, pricing, or auth code paths were modified except **PHP docblocks** in two Feature tests for terminology alignment.
