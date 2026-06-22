# A7 — BRAIN + Memory + Docs drift (RED-team audit)

**Cycle**: V1 Cloud-Prep insights round-1
**Date**: 2026-05-18
**Auditor**: A7 (RED-team — documentation drift + memory hygiene + project continuity)
**Repo HEAD**: `1235e3e1a` (branch `v1-0-1-hardening-2026-05-17`)
**Scope**: PROJECT_BRAIN.md, MEMORY.md, CLAUDE.md §7, plans/v1-0-1-hardening/, reports/test-e2e/, docs/decisions/, untracked file cleanup, task list integrity.

---

## Verdict global

**Status**: DRIFT (4 MAJOR + 3 MINOR drifts identified). Docs lag execution by 2 commits (5H, 5I). MEMORY.md missing V1 Cloud-Prep entry. Frozen-zone list count mismatch CLAUDE.md vs memory ref. Owner sign-off blocks never filled. Untracked shell-artifact garbage in repo root.

**Documentation matches reality**: ~70% (BRAIN §2/§3 captures Wave 5G but stale on 5H/5I; §7 has V1.0.1 items 17-37 but NO V1 Cloud-Prep entries; §10 master plan loop not closed).

---

## Item 1 — PROJECT_BRAIN.md §2 CURRENT STATE accuracy

**Status**: DRIFT (stale on last 2 commits)

- §2 line 49 claims HEAD `155ddbde8 post Wave 5G V1 Cloud-Prep session`. Actual repo HEAD = `1235e3e1a` (Wave 5I).
- §2 line 56 claims "6 commits Phase C local + Wave 5D-5G, 67 files +3983/-23 LOC". Real cumulative since `4fc4c3b86`: 8 commits (adds `46fb4ef2d` Wave 5H + `1235e3e1a` Wave 5I), expanded LOC > +4000.
- §2 line 56 explicitly says "Wave 5H pending (PhpSpreadsheet RCE upgrade + FormRequest authz refactor 88 endpoints)" — BUT commit `46fb4ef2d` (2026-05-18 00:09) closed 5 PhpSpreadsheet CVEs and added FormRequest authz × 5. Wave 5H is **done**, not pending.
- Last-update date "2026-05-18" — present.
- V1 Cloud-Prep cycle IS named at line 56 with date 2026-05-18 (not V1.0.1 only). 

**Fix needed**: §2 line 49 HEAD bump → `1235e3e1a`. §2 line 56 reword: "8 commits Phase C local + Wave 5D-5I". Remove "Wave 5H pending" claim (now done). Optionally add Wave 5I summary (3 RED-team heals from Ultra Review FINAL).

---

## Item 2 — PROJECT_BRAIN.md §3 LAST DONE accuracy

**Status**: DRIFT (5H + 5I commits absent from §3 narrative)

- §3 (lines 86-99) describes V1 Cloud-Prep at HEAD `155ddbde8`. Cites 6 commits, 13 P0 closed, 5 V1.0.2 P1 closed, ends with "Wave 5H pending".
- Commit `46fb4ef2d` Wave 5H is missing from §3 (5 PhpSpreadsheet CVEs CVE-2026-34084/40902/40863/40296/35453 closed + 5 FormRequest authz: Currency/Tax/Branch/Role/Administrator).
- Commit `1235e3e1a` Wave 5I is missing from §3 (3 RED-team heals: POS IDOR 403/404 timing leak, POS_SIMULATION_HARDWARE template doc, Ansible pre-migrate mysqldump).
- V1.0.1 entry (line 102+) is internally consistent with `CONVERGENCE_V1_0_1.md`.
- No duplicate "Last done" headers — proper ordering V1 Cloud-Prep (top) → V1.0.1 → prior cycles.

**Fix needed**: Insert short Wave 5H + 5I bullets at top of §3, or extend the V1 Cloud-Prep entry to cover 8 commits.

---

## Item 3 — PROJECT_BRAIN.md §7 VERIFICATION CHECKLIST

**Status**: MISSING entries 38+ for V1 Cloud-Prep heals

- §7 table ends at item 37 (POS test debt cleanup trait, V1.0.1 H6 2026-05-17).
- V1 Cloud-Prep wave 5D-5I added 13 P0 + 5 V1.0.2 P1 + 3 Wave 5I heals = **18-21 production-ready domains NOT recorded** in §7.
- Notable missing entries:
  - LanguageController RCE `permission:settings` gate (5E)
  - PosOrderController IDOR cross-branch fiscal leak (5E + 5F + 5I align)
  - Outbox pruning + WebhookEvents pruning Kernel daily (5E)
  - POS offline FULL stack (queue + IndexedDB + state hook + Vue integration) (5F)
  - RefundCreated event production dispatch (5F)
  - POS Split-payment phantom CARD cash theft fix (5F)
  - Ansible playbook + nginx/supervisor templates (5D + 5E)
  - PhpSpreadsheet 1.30.0 → 1.30.4 (5H)
  - FormRequest authz × 5 (5H)
  - bcrypt 10→12 + auto-rehash (5G)
  - Settings/Branch fanout (5G)
  - OSS wakeLock TV walls (5G)
- All 37 existing items appear correctly marked ✅ — no false-positive ✅ detected (spot-checked items 24 EnsureUserStatusActive + 28 terminal_id wire-in present at file:line as claimed).

**Fix needed**: Append items 38-50 (or merge into a "V1 Cloud-Prep" sub-section).

---

## Item 4 — MEMORY.md index integrity

**Status**: MISSING entry for V1 Cloud-Prep cycle

- `/Users/1millnonstop/.claude/projects/.../memory/MEMORY.md` has 47 indexed entries.
- Entry for V1.0.1 Hardening 2026-05-17 present (line 44 → `project_v1_0_1_hardening_2026-05-17.md` confirmed exists on disk).
- **NO entry for V1 Cloud-Prep cycle 2026-05-17/18** (Wave 5D-5I). Expected: `project_v1_cloud_prep_2026-05-17.md` or `project_v1_cloud_prep_2026-05-18.md`.
- `grep -i "cloud.prep" MEMORY.md` returns 0 results.
- Cross-check disk files in `~/.claude/projects/.../memory/` — confirmed no `project_v1_cloud_prep_*.md` file exists.
- All 47 indexed entries resolve to existing files on disk (no broken links spot-checked; `project_pos_payment_fix_2026-05-18.md` exists despite being most recent addition).

**Fix needed**: Create `memory/project_v1_cloud_prep_2026-05-17.md` summarizing waves 5D-5I + index it in MEMORY.md.

---

## Item 5 — V1.0.1 CONVERGENCE_V1_0_1.md vs V1 Cloud-Prep CONVERGENCE_FINAL.md

**Status**: DRIFT (CONVERGENCE_FINAL stale on Wave 5H/5I)

- Both files exist:
  - `reports/test-e2e/v1-0-1-2026-05-17/CONVERGENCE_V1_0_1.md` (V1.0.1)
  - `reports/test-e2e/v1-cloud-prep-2026-05-17/CONVERGENCE_FINAL.md` (V1 Cloud-Prep)
- V1 Cloud-Prep `CONVERGENCE_FINAL.md` line 14 says "**Wave 5H pending (NOT done)**: PhpSpreadsheet RCE upgrade ... + FormRequest authz refactor 88 endpoints". This is **stale** — Wave 5H commit `46fb4ef2d` exists and the commit message itself claims "CONVERGENCE_FINAL doc" was updated. Yet the doc still says pending.
  - Conflict resolution: either the Wave 5H commit edited a section other than line 14, or the §1 verdict text wasn't refreshed.
  - Spot-checked the doc grep — Wave 5H mentioned in 7 lines but always as "pending" or "begins" or "5H scope". No "✅ Wave 5H closed" line found.
- Wave 5I (commit `1235e3e1a`) NOT mentioned at all in `CONVERGENCE_FINAL.md`. The 3 Ultra Review FINAL heals (POS IDOR timing, POS_SIMULATION_HARDWARE template, Ansible pre-migrate snapshot) have no doc trace in CONVERGENCE_FINAL.
- Internal consistency between the two CONVERGENCE files: V1.0.1 doc predates V1 Cloud-Prep correctly (HEAD `4fc4c3b86` baseline match § identical to V1 Cloud-Prep pre-session HEAD).

**Fix needed**: Refresh CONVERGENCE_FINAL.md §1 verdict + add §X for Wave 5H + Wave 5I, or rename current doc to `CONVERGENCE_5G.md` and create new `CONVERGENCE_FINAL.md` covering all 8 commits.

---

## Item 6 — MASTER plan V1.0.1 §10/§11 (final convergence + handoff)

**Status**: PARTIAL — §10 executed; §11 handoff bypassed by direct continuation

- `plans/v1-0-1-hardening/MASTER_V1_0_1_HARDENING_2026-05-16.md` lines 895 (§10) and 911 (§11) exist.
- §10 "Final convergence (post-H6)" was executed → produced `reports/test-e2e/v1-0-1-2026-05-17/CONVERGENCE_V1_0_1.md` (dated 2026-05-17). Owner-merge gate is line 7 of acceptance criteria but the gate is still PENDING (task #58 MERGE still pending — see Item 9).
- §11 "Executor handoff options" was **bypassed**. The V1 Cloud-Prep cycle (Wave 5D-5I) started on TOP of V1.0.1 cycle on the same branch without an explicit owner-merge gate between cycles. Per CLAUDE.md §10 (Decision Framework), this is borderline — V1.0.1 was a closed deliverable awaiting merge; chaining V1 Cloud-Prep on the un-merged branch creates a single mega-cycle that owner now has to bless atomically.
- Acceptable per CLAUDE.md §10? Marginally. The "human gate" is only fully tripped if a frozen-zone touch occurs (none did) OR push to protected release branch (didn't happen). But the spirit of MASTER §10 acceptance criteria #7 (OWNER_GATES sign-off) is **not** met for either cycle (see Item 7).

**Fix needed**: Add a §10.bis "V1 Cloud-Prep extension" note in MASTER plan OR write a separate plan `plans/v1-cloud-prep/MASTER_V1_CLOUD_PREP_2026-05-17.md` retroactively documenting the cycle continuation rationale.

---

## Item 7 — OWNER_GATES.md sign-off state

**Status**: DRIFT (sign-off blocks never filled in)

- `plans/v1-0-1-hardening/OWNER_GATES.md` contains 4 sign-off blocks (G1, G2, G3, G4) all with template `Owner: ____________________`.
- `grep "Owner:" plans/v1-0-1-hardening/OWNER_GATES.md` returns 4 underscored placeholders, **zero filled**.
- §3 LAST DONE narrative at line 105 claims G1=B Deprecate / G2=B Accept reactive UX / G3=B Config aliases / G4=A Every-request middleware were "résolus".
- Decisions WERE encoded in implementations (e.g., `EnsureUserStatusActive` middleware exists matching G4=A, `DEPRECATED_KDS_V2_ITEMS_BOARD.md` exists matching G1=B, `ACCEPTED_POS_WIZARD_CASH_TILE_REACTIVE_UX.md` matching G2=B). So decisions DID flow into code.
- BUT the formal sign-off doc was never updated with owner name+date+decision. This is a paper-trail drift, not a logic drift.
- `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` Section 6.2 signatures table — only `LOCK requestor` (Claude) row signed. 3 owner countersign rows still empty:
  - Owner countersign (pre-patch) — empty
  - Implementer subagent (post-patch) — empty (acceptable, not yet executed)
  - Owner final approval (pre-merge) — empty
- This LOCK plan is **pending** per BRAIN §2 line 56 "1 LOCK plan owner-gate authored ... pending owner countersign". Consistent.

**Fix needed**: Either retroactively fill OWNER_GATES.md with owner sign-off, OR mark the doc as "decisions migrated to docs/decisions/*.md, retain for archival".

---

## Item 8 — CLAUDE.md frozen-zone list completeness

**Status**: DRIFT (memory reference outdated vs CLAUDE.md)

- CLAUDE.md §7 lists **13 frozen files** across Frontend (3 Kiosk Vue + POS wizard JS/CSS + admin-pos-v4.blade) + Backend NF525-critical (3 fiscal services + 2 migration triggers) + Backend multi-tenant (4 services/middleware/scope/state-machine).
- `memory/reference_frozen_zones.md` lists only **6 backend files**:
  - `app/Services/Orders/OrderService.php`
  - `app/Services/Payments/PaymentService.php`
  - `app/Services/Pricing/PricingService.php`
  - `app/Services/FrontendOrderService.php`
  - `resources/js/components/admin/pos/PaymentComponent.vue`
  - `resources/js/components/admin/pos/ItemComponent.vue`
- **3 files appear in the memory ref that are NOT in CLAUDE.md §7**: OrderService.php, PaymentService.php, FrontendOrderService.php, PaymentComponent.vue, ItemComponent.vue. (Older CV1 cycle scope, never reconciled.)
- **CLAUDE.md §7 lists 11 files that the memory ref doesn't**: Kiosk Vue trio, pos-wizard.js/css, admin-pos-v4.blade, FiscalSequenceService, ZReportService, AuditLogService, BranchScope, IdempotencyKeyMiddleware, OrderStateMachine, migration triggers.
- The memory ref also has a system-reminder of "15 days old" warning baked in.
- V1 Cloud-Prep added fiscal-adjacent files NOT yet protected:
  - `config/pos.php` — controls `POS_SIMULATION_HARDWARE` (which Wave 5I documented as Phase D risk). Should be CONFIG-frozen (no edit without LOCK) but NOT in §7.
  - `RefundCreated` event dispatch wired into `RefundWithCounterEntryService.php:229` + `PaymentService.php:134`. PaymentService.php IS in memory ref but NOT CLAUDE.md §7 — opposite drift.

**Fix needed**: Reconcile memory `reference_frozen_zones.md` ↔ CLAUDE.md §7. Add `config/pos.php` to §7 (or document explicitly as NF525-adjacent). Decide if PaymentService.php / FrontendOrderService.php deserve §7 promotion.

---

## Item 9 — TaskList sanity (Claude Code)

**Status**: DRIFT (task list scope = V1.0.1 only; V1 Cloud-Prep not in tasks)

- Current Claude session has 44 tasks (#15-#58) ALL labeled V1.0.1 hardening (H1-H6 sprints + CONVERGE + MERGE).
- 43/44 = completed. Task #58 MERGE — pending.
- **NO tasks for V1 Cloud-Prep waves 5D-5I** despite 8 commits + 67+ files modified across them. This work was executed entirely outside the task-tracking system.
- `git log main..HEAD --oneline | wc -l` = **517 commits** on branch since main. Cumulative since `main` is huge — full mobile + menu reset + Wave Z + V1.0.1 + V1 Cloud-Prep all on this branch.
- **No V1.0.1 commit has landed in main** (517 commits divergence confirms). Owner merge gate fully outstanding.

**Fix needed**: Mark task #58 as in-progress or completed once merge happens, but more importantly: future cycles should use TaskCreate for ALL waves, not just V1.0.1.

---

## Item 10 — CTO audit reports orphan

**Status**: DRIFT (untracked but relevant, no commit decision)

- `reports/audit/cto-global-2026-05-16/` directory exists, fully untracked.
- File `00_FINAL_CTO_VERDICT.md` mtime = 2026-05-17 18:34:20. Content dated 2026-05-16 (cycle date).
- Contents: 8 agent reports (architect/security-red/dba-saas/sre-production/qa-testing/frontend-ux/competitive-benchmark/claude-dependency) + 4 strategic docs (00_FINAL + EXECUTION_ROADMAP_V1 + OWNER_GATES_REGISTRY + QUICK_WINS_EXECUTED + AGENT_DISPATCH_PACK) + subdirectories `sql-prep/` + `ultra-plans/`.
- Verdict claims "32/100 global, V1 single-resto NO-GO en l'état, GO-CONDITIONAL sous 4-6 semaines de hardening".
- MEMORY.md line 42 indexes this as `project_cto_audit_global_2026-05-16.md` → memory file exists.
- **P0 from CTO audit cross-check vs V1 Cloud-Prep heals**:
  - CTO P0 #1: "Clés AWS leakées commit `a4a88df06`, non rotées" → STILL OPEN (BRAIN §2 lines 56+96 confirm "AWS rotation" is owner-physique pending). Not closed by V1 Cloud-Prep.
  - CTO P0 #2: "Primitive RCE pré-auth-light via LanguageService" → CLOSED by Wave 5E commit `dec9aec5a` (LanguageController `permission:settings` middleware).
  - CTO #3-#8: Mixed (Sanctum wildcard tokens `['*']` closed by Wave Z 5D + V1.0.1 H1.1 Z6-02; ZReport withTrashed already closed iter12).
- The CTO audit is reference-only — used to inform V1 Cloud-Prep RED-team session scope. Not meant to be committed (no acceptance criteria), but the orphan untracked state is a hygiene drift.

**Fix needed**: Either commit CTO audit reports under `reports/audit/cto-global-2026-05-16/` (squash into V1 Cloud-Prep narrative as input artifact) OR add to `.gitignore` with rationale. Currently it's "Schrödinger's report" — visible in working tree but not version-controlled.

---

## Item 11 — Decision docs index

**Status**: PASS (with 1 minor)

- `docs/decisions/` lists 5 files:
  - `ACCEPTED_POS_WIZARD_CASH_TILE_REACTIVE_UX.md` (V1.0.1 G2=B)
  - `D-M13-QUEUE-NUMBER-UNIQUE.md` (older)
  - `D-PH2-DATA-OWNERSHIP.md` (older)
  - `DEFERRED_AUTO_DISPATCH_V1_0_2.md` (V1.0.1 DEL-9)
  - `DEPRECATED_KDS_V2_ITEMS_BOARD.md` (V1.0.1 G1=B — untracked)
- `DEPRECATED_KDS_V2_ITEMS_BOARD.md` is in `git status --short` as untracked `??` — **not yet committed**. Content references "Owner Gate G1 = Option B" but the OWNER_GATES.md sign-off block (Item 7) is empty. No contradiction with another decision doc — pattern intact.
- No DEPRECATED→RESTORED contradiction found.
- No decision doc with completely empty sign-off block ─ each file's content claims owner decision but the sign-off footer pattern is inconsistent (some have it, some don't).

**Fix needed**: Commit `docs/decisions/DEPRECATED_KDS_V2_ITEMS_BOARD.md` (last paragraph still references "Owner / restaurant manager to brief chefs..." but file not in git).

---

## Item 12 — Untracked files cleanup

**Status**: DRIFT (shell-artifact garbage in repo root + legitimate work uncommitted)

`git status --short | grep "^??"` reveals 40+ untracked entries. Categorized:

### Garbage (shell-artifact zero-byte files in CWD root, 2026-05-17 18:34)
- `,` (0 bytes)
- `[` (0 bytes)
- `L'article ne correspond pas.,` (0 bytes)
- `Utilisateur non trouvé.,` (0 bytes)

These look like accidental redirects from a misformatted shell command. They are zero-byte files in the project root. The 18:34 timestamp matches the CTO audit verdict mtime, suggesting both were created during a parallel session. Should be `rm`'d.

### Legitimate code/config (commit candidates)
- `config/pos.php` — POS_SIMULATION_HARDWARE config (Wave 5I documented)
- `database/seeders/AlignProfile85ChickenBurgerSeeder.php` — data fix
- `docs/decisions/DEPRECATED_KDS_V2_ITEMS_BOARD.md` (Item 11)
- `mobile/assets/menu/generated_nuggets-x6.png` — image asset
- `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` — test
- `tests/Unit/Payment/` — test dir
- `tests/e2e/_*.spec.js` (3 debug specs from 2026-05-17/18)
- `tests/e2e/test-e2e-website-realignment-2026-05-16.spec.js`
- `tests/web-e2e/` — playwright config dir
- `tests/e2e/__screenshots__/test-e2e-website-realignment-2026-05-16/`

### Reports (commit-later candidates)
- `reports/audit/cto-global-2026-05-16/` (Item 10)
- `reports/audit/goal-systems-2026-05-17/`
- `reports/audit/longterm-goal-2026-05-17/`
- `reports/audit/massive-logic-2026-05-17/`
- `reports/audit/mobile-realignment-2026-05-16/`
- `reports/data-repair/MULTI_VARIATION_AUDIT_2026-05-17.md`

### Plan artifacts
- `plans/GOAL_LONGTERM_LECAYENNE_FRONTENDS_2026-05-16.md`
- `plans/v1-0-1-hardening/frozen-baseline.diff`
- `plans/v1-0-1-hardening/nf525-baseline.txt`

### Storage/backups (committed by convention?)
- `storage/backups/v1-0-1-pre/` — pre-V1.0.1 DB dump dir (5.9 MB, gitignore?)

**Fix needed**:
1. `rm ',' '[' "L'article ne correspond pas.," "Utilisateur non trouvé.,"` — garbage cleanup.
2. Commit legitimate code/config + tests in V1.0.1 / V1 Cloud-Prep retroactive batch.
3. Decide policy on reports/ and storage/backups/ (commit vs .gitignore).

---

## Summary table

| # | Item | Status | Severity |
|---|------|--------|----------|
| 1 | §2 CURRENT STATE accuracy | DRIFT | MAJOR (HEAD stale + Wave 5H wrong-pending claim) |
| 2 | §3 LAST DONE accuracy | DRIFT | MAJOR (2 commits absent from narrative) |
| 3 | §7 VERIFICATION CHECKLIST | MISSING | MAJOR (13-18 missing entries) |
| 4 | MEMORY.md index integrity | MISSING | MAJOR (V1 Cloud-Prep file not created/indexed) |
| 5 | CONVERGENCE_FINAL vs CONVERGENCE_V1_0_1 | DRIFT | MAJOR (Wave 5H/5I never doc'd in convergence) |
| 6 | MASTER §10/§11 final convergence | PARTIAL | MINOR (V1 Cloud-Prep on top without explicit gate) |
| 7 | OWNER_GATES.md sign-off | DRIFT | MINOR (paper-trail empty, decisions encoded in code) |
| 8 | CLAUDE.md §7 frozen-zone list completeness | DRIFT | MAJOR (memory ref out of sync, config/pos.php not protected) |
| 9 | TaskList sanity | DRIFT | MINOR (Cloud-Prep entirely outside tasks) |
| 10 | CTO audit reports orphan | DRIFT | MINOR (untracked but used; commit-or-ignore decision pending) |
| 11 | Decision docs index | PASS+1 | MINOR (1 file untracked) |
| 12 | Untracked files cleanup | DRIFT | MAJOR (garbage in root + legitimate work uncommitted) |

---

## Top 5 fix priorities

1. **BRAIN §2 + §3 refresh** (Items 1+2): bump HEAD to `1235e3e1a`, remove "Wave 5H pending" claim, add Wave 5H/5I bullets to §3. Effort: 0.5h.
2. **MEMORY.md V1 Cloud-Prep entry** (Item 4): create `memory/project_v1_cloud_prep_2026-05-17.md` + index in MEMORY.md. Effort: 0.5h.
3. **CONVERGENCE_FINAL.md refresh** (Item 5): add §X for Wave 5H + 5I, fix §1 verdict text. Effort: 0.5h.
4. **CLAUDE.md §7 reconciliation** (Item 8): align with memory ref + decide on `config/pos.php` protection. Effort: 1h (requires owner input).
5. **Garbage cleanup + legit commits** (Item 12): `rm` 4 shell-artifact files; stage + commit Wave 5H/5I retroactive trail. Effort: 0.5h.

---

## Anti-fabrication evidence trail

- HEAD verified via `git rev-parse HEAD` = `1235e3e1ae750c4d4f46255e873a45797df04c82`.
- Wave 5H commit `46fb4ef2d` verified via `git show --stat 46fb4ef2d` (mentions PhpSpreadsheet 1.30.0 → 1.30.4 + 5 FormRequests).
- Wave 5I commit `1235e3e1a` verified via `git show --stat 1235e3e1a` (mentions 3 RED-team heals + Ultra Review FINAL).
- OWNER_GATES.md grep `Owner:` returned 4 underscored placeholders only.
- LOCK XSS sign-off section 6.2 read directly — only Claude row signed.
- MEMORY.md grep `cloud.prep` returned 0 matches — confirms V1 Cloud-Prep absent from index.
- Garbage files `ls -la ',' '['` returned 0-byte files dated 2026-05-17 18:34.
- CTO audit `stat 00_FINAL_CTO_VERDICT.md` mtime = May 17 18:34 (matches garbage file mtime).

**End of A7 audit.**
