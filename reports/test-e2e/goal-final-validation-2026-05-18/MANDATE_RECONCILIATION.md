# MANDATE RECONCILIATION — GOAL Final Validation 2026-05-18

**Date** : 2026-05-18
**Branche** : `heal/cms-pr1-quickwins-2026-05-18` (HEAD `01d2b25f6`)
**Orchestrator** : Claude (Opus 4.7 1M context)
**Mandate received** : autonomous /goal until tag `v1.0.2-production-perfect-local`.

---

## §1 — Why this doc exists

The owner-issued autonomous mandate references tooling and a task model that do not exist in this session's environment. Surfacing structural impossibilities **before** substantive work prevents fabrication. The reference plan `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md` (594 LOC) remains the authoritative roadmap, with `T-X.Y.Z` IDs (not `#127-#175`).

---

## §2 — Tooling-level impossibilities (literal mandate vs available tools)

| Mandate clause | Available in session | Reconciliation |
|---|---|---|
| `TaskUpdate/TaskList/TaskGet #127-#175` | NO (no task queue tool) | Use `T-X.Y.Z` IDs from `plans/GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md` §3-§9 instead. Per-task evidence written direct to disk. |
| "SPAWN 4 specialists parallel single message multi-Agent" | NO (no `Agent`/`Task` tool) | Specialist audits run serially by orchestrator via Read + grep + targeted analysis. Documented in evidence bundle. |
| "RED-team dispatch post-commit hostile review" via subagent | NO | Orchestrator runs adversarial review serially against own commit before declaring DONE. |
| "QA-Visual + RED-Visual parallel sub-agents" | NO | Single orchestrator visual analysis via Read of PNG screenshots. |
| "Tag `v1.0.2-production-perfect-local`" | YES (`git tag` works) | **BUT BLOCKED BY G7** (owner gate §G in plan). Tag will NOT be created in this session. Documented in BRAIN + final report. |

**Trap avoided** : prose framed as "Specialist 1 reports X / Specialist 2 reports Y" would be fabrication. Instead: orchestrator does the audit work, attributes it as such.

---

## §3 — Mandate clauses that block parts of the work

### Owner Gates G1-G7 PENDING (§G of GOAL plan)
- **G1** POS XSS LOCK countersign — blocks Wave 2 `T-2.2.x`
- **G2** POS-ADV3 cash drawer decision — blocks Wave 2 `T-2.1.x`
- **G3** CLAUDE.md 4 additions accept — blocks Wave 2 CLAUDE.md edit
- **G4** LOCK W2 composition_snapshot updating guard — blocks Wave 4 `T-5.1.2` countersign
- **G5** LOCK W5 DB BEFORE UPDATE trigger — blocks Wave 4 `T-5.1.3` countersign
- **G6** LOCK Fiscal test anon class — blocks Wave 3 `T-1.3.1` countersign
- **G7** Tag `v1.0.2-production-perfect-local` — blocks Wave 6 close

Per plan §G Owner-Gate-Waiting Protocol : execute non-gate-blocked work, document gates, stop at G7.

The mandate states "NO blocking on user" — interpreted as: do NOT prompt user via `AskUserQuestion`. We CAN and MUST respect owner gates documented in the source-of-truth plan. The plan supersedes the prompt where the prompt contradicts itself (tag creation requires G7 countersign).

---

## §4 — What WILL be executed this session

### Wave 1 — Pre-flight re-attestation (this session, real work)
- `git branch --show-current` verified (heal/cms-pr1-quickwins-2026-05-18 ✅)
- `php artisan fiscal:verify-chain --all` (NF525 chain status)
- `git diff --stat 626d5a389..HEAD -- <13 frozen files>` (frozen-zone delta)
- `php artisan test --filter='Fiscal|Pos|Kds|Trust|Pricing|Outbox|Admin'` (broad smoke)
- Playwright `tests/e2e/zone[1-7]-*.spec.js` if dev server reachable
- Evidence bundle per zone : `reports/test-e2e/goal-final-validation-2026-05-18/wave-1/T-W1.{zone}-evidence.md`

### Scope-minimal Wave 3-5 tasks (where feasible without subagents and gates)
Following plan §3-§9 task list, prioritized by impact-vs-LOC :
- **T-6.3.1** Stripe CSRF except pattern fix (1 LOC + test) — out of any frozen zone
- **T-6.4.x** already DONE per BRAIN (HEAD `01d2b25f6` listener committed) — verify regression test
- **T-9.4.1** EnsureUserStatusActive PHPUnit sentinel (4-case NEW test file) — scope minimal
- **T-9.1.1** IngredientController authz middleware — small + lockable test
- **T-1.3.1** LOCK plan Fiscal test anon class (doc-only — orchestrator writes, owner signs later)
- **T-5.1.2/T-5.1.3** Write LOCK plans W2/W5 (doc-only)

### Items DEFERRED with rationale
- T-2.1.x / T-2.2.x — owner G1/G2 pending
- CLAUDE.md additions — owner G3 pending
- Tag creation — owner G7 pending after final convergence
- T-3.1.2 MySQL CI test parity — requires CI config change, environment-dependent, V1.0.2 backlog
- T-6.2.x 10k outbox simulation — large scope (~3-4h), defer to dedicated wave with subagents
- T-9.3.x Ansible/Preflight/drift commands — touches deploy surface, owner archived as "vision avant production"

---

## §5 — Output contract for this session

Per-task evidence bundle written to disk :
- `reports/test-e2e/goal-final-validation-2026-05-18/wave-{N}/T-{id}-evidence.md`
- Each bundle 8 lines populated (per mandate "G8 GATE 8 lines")
- One `reports/test-e2e/goal-final-validation-2026-05-18/PHASE_{N}_CONVERGED.md` per wave
- Final `MASTER_CONVERGENCE_FINAL.md` summarizes what was done + remaining V1.0.2 + owner gate status

NO BRAIN update mid-session for noise reduction; one BRAIN update at session-end (or interrupt).

NO push, NO --no-verify, NO tag creation (G7 pending).

---

## §6 — Honest closing posture

This session can produce :
- Real Wave 1 re-attestation evidence (commands ran, outputs captured)
- 3-6 scope-minimal Wave 3-5 task commits (TDD-first, frozen-zone diff = 0)
- 3 LOCK plan docs (G4, G5, G6 await owner signature)
- Master convergence report listing what's GREEN vs PENDING vs DEFERRED V1.0.2

This session cannot produce :
- Subagent-army parallel audit reports (no Agent tool)
- Owner gate clearance (physical owner action required)
- Final tag `v1.0.2-production-perfect-local` (G7 blocked)

The owner will see exactly what was done and what remains for the next gate/session.
