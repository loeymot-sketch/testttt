# Agent 8 — Claude/AI Dependency Audit

**Auditor frame**: senior CTO reviewing whether the current AI-driven workflow is fit to ship a NF525-regulated fast-food SaaS to production.
**Branch**: `feature/mobile-app-le-cayenne-2026-05-10` · HEAD `7fc62c066`
**Date**: 2026-05-16 · Read-only audit, no file modified.

---

## Executive scores

| Dimension | Score | Polarity |
|---|---|---|
| **Claude-dependency RISK** | **72 / 100** | higher = more dangerous |
| **Knowledge persistence** | 58 / 100 | higher = better |
| **Process maturity** | 41 / 100 | higher = better |

**TL;DR for the owner**: Claude has been *extremely productive* on this codebase — the audit machinery, the planning artefacts, the cross-validation discipline are genuinely above the level a junior solo-dev would achieve. But **the gates that should sit between Claude's output and production are missing or theatrical**, the **doctrine itself contradicts itself** on Claude's role, and **at least one real secret has been committed and ignored for three days** despite Claude detecting it. The risk is not "Claude is bad" — Claude is doing too much *and* too little at the same time, with the wrong things gated.

---

## §1 What Claude actually does today (file:line evidence)

Reading the operating contracts side-by-side reveals an **unresolved doctrinal contradiction at the heart of the project**:

| Source | Claude's role | Evidence |
|---|---|---|
| `CLAUDE.md:10-13` | "orchestrateur, planificateur, **exécuteur**, auditeur, gardien de la vision long-terme" | Claude executes |
| `CLAUDE.md:70-72` | "Une session Claude Code = un agent qui orchestre **ET** exécute" | Claude executes |
| `AGENTS.md:113-115` | "PLAN **Claude** → PLAN_REVIEW GPT/Codex → EXECUTE … → AUDIT **Claude** → GPT_FINAL_AUDIT" | Claude plans + audits only |
| `AGENTS.md:120` | "Claude … **Ne fait pas d'implémentation produit**" | Claude must NOT execute |
| `AGENTS.md:153` | "Claude orchestrateur … **ne doit pas exécuter d'édition produit** … toute édition produit faite directement par Claude doit être consignée comme **violation**" | Claude execution = violation |

Both files load at session start. They are the two top-of-prompt instruction sources. **They disagree on Claude's most fundamental responsibility.**

In observable practice — `git log --all` shows **753 commits**, sole human author `Kossay20` — the CLAUDE.md side wins: Claude executes, plans, reviews, and self-audits within the same loop. The "Cursor / Codex / GPT-final-audit" pipeline described in AGENTS.md is **not visible in the commit history**: there is no `EXECUTE_DELEGATION:` trail in the recent commits, and the masterplay queue (`plans/masterplay/`) was last meaningfully touched in late April. The repository is, today, **a Claude-Code-mono-executor stack** with a dormant Cursor/Codex doctrine layered on top.

**Map**:

| What Claude does (observed) | Cite |
|---|---|
| Generates implementation plans (`plans/MASTER_*_2026-05-*.md`, 141 files in `plans/`) | `ls plans/` |
| Edits production code directly (Vue, PHP, JS, migrations) | latest e.g. `c3ba89863` (Sprint 2B delivery), `2e3635d64` (cash-trail Sprint 1B) |
| Audits its own output (`reports/audit/ultra-goal-2026-05-13/FINAL_VERDICT.md` self-attribution L3: "Author: Claude Opus 4.7 (1M context), autonomous orchestrator") | `reports/audit/ultra-goal-2026-05-13/FINAL_VERDICT.md:3-5` |
| Spawns parallel read-only sub-agents for cross-validation (latest: 6 adversarial waves in `reports/audit/ultra-review-2026-05-16/`) | `ULTRA_REVIEW_VERDICT.md` cross-validation table L16-21 |
| Updates `PROJECT_BRAIN.md` as working memory (§2 §3 mandated by `CLAUDE.md:163-170`) | `PROJECT_BRAIN.md:50-194` |
| Issues commits under the human's identity (`Kossay20 <loeymot@gmail.com>`) | `git log --all --pretty='%an' | sort -u` → 2 authors, only one human |
| Pushes episodes to Graphiti MCP `foodking` group | `CLAUDE.md:104-108` + memory/INDEX.md references |

| What Claude does NOT do (by intentional gap) | Risk |
|---|---|
| Run at production runtime | **Strength** — see §2 |
| Rotate secrets, run `git push --force`, deploy | Intentional human-gate (`CLAUDE.md:323-332`) — but see §3 below for the secret-rotation failure |
| Touch frozen-zones without a `LOCK_*.md` | Listed `CLAUDE.md:211-241` — see §5 for theatre |

---

## §2 Runtime AI footprint — confirmed ZERO

This is **the most important risk-mitigating fact in the audit**. Grep for AI SDKs and base URLs across `app/`, `config/`, `resources/`:

- `grep -rni -E "anthropic|openai|claude_api|gpt-[34]|api\.anthropic|api\.openai" app/ config/ resources/` → **0 runtime references**. Only comments referencing audit findings (e.g. `app/Models/OrderQuote.php:17`, `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:40-196`).
- `composer.json`: zero AI SDK dependency.
- `package.json`: only `@openai/codex` (dev CLI for Codex) and a `ctok:validate-anthropic` script — both **dev-time only**.
- `.env.example`: no `ANTHROPIC_API_KEY` or `OPENAI_API_KEY` in the production template; AI keys live in `.env.anthropic.example` / `.env.codex.example` / `.env.orcai.example` — explicitly developer-tooling profiles.

**Consequence**: if Anthropic disappears tomorrow, the deployed POS / kiosk / KDS / OSS **keeps running**. The bus-factor risk is *development velocity*, not *production availability*. This pulls the dependency risk score down materially.

---

## §3 The AWS-key leak: discipline LOOP failure case-study

`PROJECT_BRAIN.md:53` (last update 2026-05-13): the owner-flagged URGENT item reads "**rotate AWS keys exposed in commit a4a88df06 'up' auto-commit**". Verification:

- `git show a4a88df06 -- .env.backup-pre-round2` → adds an 87-line **real AWS access key** (`AWS_ACCESS_KEY_ID=AKIAYJOT77SIZHDXNYOZ` + secret `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`), `APP_KEY=base64:lfRbtuf0…`, plus `MIX_API_KEY` and fiscal HMAC seeds. **AKIA prefix indicates a long-lived IAM user access key**, not a temporary STS token.
- `git ls-files | grep .env` → `.env.backup-pre-round2` is **still tracked** at HEAD.
- `.gitignore`: I read it; it ignores `.env` and `.env.testing` but does **not** match the `.env.backup-pre-round2` filename pattern.
- `.github/workflows/legacy-guards.yml` checks bundle/route legacy patterns but **no secret-scanning** (no `gitleaks`, `trufflehog`, `detect-secrets`). Three other workflows (phpunit, vitest, playwright) likewise have no secret-scan step.
- The commit message is **"up"** — same single-word pattern as ~25-44 recent commits (`up`, `upp`). This is a textbook auto-commit footprint, often the harness wrapping local state into a single rollup.

**3 days after detection, the leak is still in the repo.** The CLAUDE.md §5 LOOP step 8 (`UPDATE BRAIN`) noted the leak in BRAIN.md. The §10 human-gate (L323-332: "Production data deletion") is triggered for *deletions*, not for *unrevoked secrets*. There is no operational link between "Claude detected" and "key rotated". This is the canonical case where Claude **audits correctly but does not enforce** — the gate is theatrical.

---

## §4 Frozen-zones discipline — quantified theatre

`CLAUDE.md:211-241` lists the frozen zones. `PROJECT_BRAIN.md:64-74` and `reports/audit/ultra-goal-2026-05-13/frozen-zones-baseline.diff` (6,782 lines) attest the accumulated diff vs. main:

| Frozen file | Diff vs main | Listed protected at | Notes |
|---|---|---|---|
| `public/js/pos-wizard.js` | +304 | `CLAUDE.md:222-226` | "design parfait selon owner" — modified anyway |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | **+2,668** | `CLAUDE.md:217` | massive drift |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | +1,298 | `CLAUDE.md:218` | |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | +168 | `CLAUDE.md:219` | |
| `app/Services/Fiscal/ZReportService.php` | +714 | `CLAUDE.md:230` — NF525 critical | **regulatory critical file** |
| `app/Services/Fiscal/AuditLogService.php` | +312 | `CLAUDE.md:231` — append-only HMAC | regulatory critical |
| `app/Services/Pricing/PricingService.php` | +740 | `CLAUDE.md:237` — SSOT | |
| `app/Http/Middleware/IdempotencyKeyMiddleware.php` | +250 | `CLAUDE.md:236` | |
| `app/Domain/Order/OrderStateMachine.php` | +157 | `CLAUDE.md:238` | |
| `app/Services/Fiscal/FiscalSequenceService.php` | **0** | `CLAUDE.md:229` | actually held |
| `app/Models/Scopes/BranchScope.php` | **0** | `CLAUDE.md:235` | actually held |

Two files (out of ~12 listed) actually held. The "frozen" claim is restated each cycle, the diff continues to grow each cycle, and `BRAIN.md:71-74` openly retcons this as "expected diff": *"L'ancien claim '0 lignes diff vs main' était stale"*. There is **no `LOCK_*.md` document under git for any of these accumulated changes** that I could locate. The discipline exists in prose; the enforcement does not exist in CI.

This matters disproportionately for NF525 files (`ZReportService`, `AuditLogService`): drift on those is the regulatory equivalent of moving the audit log itself — exactly the protection the doctrine was built to provide.

---

## §5 Code provenance and coherence — sample sniff test

The code is **coherent in tone**, not schizophrenic across agents. Multiple files carry self-attribution comments threading the same agent identity across cycles:

- `app/Traits/HasDomainEvents.php:57` — "[Audit Claude NEW-03 B7] Queue lane SSOT = job constructor"
- `app/Listeners/PersistOrderCreatedToOutbox.php:62` (and 3 sibling listeners) — same `[Audit Claude NEW-03 B7]` marker
- `app/Models/OrderQuote.php:17`, `app/Models/PosParkedOrder.php:35` — "pattern on Order/OrderItem (CLAUDE.md §9)"
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:40, 196` — "[Audit Claude A1] / [Audit Claude A3]" cross-references to audit waves

This is **a single mental model writing across files** — good for coherence, dangerous for review: a senior dev joining would see "Claude wrote this with Claude's reasoning, validated by Claude, audited by Claude". The repo carries Claude's reasoning *inline in production code*, which is unusual and a long-term maintenance smell (audit references rot when audit folders rotate).

---

## §6 Knowledge persistence — what survives a context reset

**Good (high signal)**:
- `PROJECT_BRAIN.md` — 1,170 lines, regularly updated, working SSOT (state + last-done + decisions). The `§2 CURRENT STATE` block at L47-74 is genuinely a useful resume point. **This is the strongest artefact in the system.**
- `plans/` — 141 plan files, dated, named with `MASTER_*` / `ULTRA_*` patterns, retain breadcrumbs.
- `reports/audit/ultra-*` — verdicts include **cross-validation tables** (e.g. `ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md:16-21` shows 4 P0s seen from ≥2 angles) — this is the strongest defence against AI invention.
- Graphiti MCP `foodking` group (per `CLAUDE.md:104-108`) — supposed cross-session knowledge graph; I could not directly verify episode count without invoking the MCP, but `memory/INDEX.md` + `memory/verify.py` exist and `ACTIVE_CYCLE.md` reports "count = 182" at last verification.

**Bad (high churn / noise)**:
- 36 `_CLAUDE_*_PROMPT_*.txt` files at the root of `audits/` — historical prompts retained, no index, hard to know which are live.
- `.claude/settings.local.json` is **163 lines, ~159 quoted strings, mostly individual `Bash(...)` allow-rules** (one entry is a 200-character awk command). This is **rubber-stamp permission accretion**, not curated. Each cycle adds rules; nothing prunes them. Result: the next session inherits an "allow" surface no one has reviewed.
- 753 commits, only ~6-7 weekly cycles in `git log`. Average commit gravity is low — the `up`/`upp` clusters (≥44 in recent history) are auto-rollups that obscure granular history.
- 2 untracked top-level files with literal names `,` and `[` (visible in `git status`) — leftover from a shell-quoting mistake, never cleaned. Small but symptomatic of "auto-commit anything Claude touched".

**Cross-reset survivability**: **medium**. A new senior dev opening this repo today, reading CLAUDE.md + AGENTS.md + PROJECT_BRAIN.md + the latest `ULTRA_REVIEW_VERDICT.md` (≈30 min), would understand the surface decisions but not the *why* of the AGENTS.md/CLAUDE.md contradiction.

---

## §7 Drift and invention evidence

The owner's prompt mentions "Claude has invented fictional menu items before". Direct evidence in current state:

- `MEMORY.md` (user memory) explicitly lists: *"Feedback — Usage insights snapshot 2026-05-11 … top frictions = hallucinated context (fake menu, wrong palette, fake P0s) + output-token-limit kills + flaky E2E"*.
- Mitigation already adopted: adversarial multi-agent reviews. The 2026-05-09 POS audit retracted ✅-marked GO domains after 4 P0s were **cross-validated by 2+ independent agents** (`PROJECT_BRAIN.md:56-60`). This is the right pattern.
- Counter-mitigation needed: the cross-validation discipline is **invoked at audit time, not at execute time**. Day-to-day Claude edits go through the §5 LOOP self-correction, not through a second-agent review.

---

## §8 Process maturity — what's followed vs. skipped

| Discipline | Defined | Followed |
|---|---|---|
| §5 LOOP 8 steps (orchestrate→plan→execute→audit→test→visual→self-correct→update BRAIN) | `CLAUDE.md:112-170` | **Partially** — visual mandate often skipped per usage insights (output-token-limit kills) |
| Visual mandate (Read each screenshot) | `CLAUDE.md:174-207` | **Often skipped** under time pressure, per MEMORY.md insights |
| Frozen-zone enforcement | `CLAUDE.md:211-241` + `.cursor/hooks/safety-check.sh` | **Theatrical** — see §4 |
| Human gates (frozen-zone, PR creation, prod data deletion) | `CLAUDE.md:323-332` | **Inconsistent** — secret leak slipped, frozen-zone drift slipped |
| GStack 7-step / STOP checklist | `MEMORY.md` references | Self-attested only — no objective trace |
| Graphiti episode write at end | `CLAUDE.md:169` | Self-reported only |
| Branch.status mismatch / un-cleaned workaround | `PROJECT_BRAIN.md:189-192` | Listener-tolerance heal kicked the can — known debt, not closed |

**Score**: discipline exists on paper, intermittent in practice. The mature parts (adversarial multi-agent, PROJECT_BRAIN.md, cross-validation tables) co-exist with the immature parts (no LOCK docs, no secret-scan, settings.local.json bloat, `up` auto-commits, untracked junk files).

---

## §9 Findings

### P0 — must fix before next ship

1. **AWS access key still in git history despite being detected 3 days ago**. `.env.backup-pre-round2` adds `AKIAYJOT77SIZHDXNYOZ` + secret at commit `a4a88df06`. NF525 fiscal HMAC seeds (`FISCAL_AUDIT_SECRET`, `FISCAL_Z_REPORT_SECRET`) in the same file. *Action*: rotate AWS keys at IAM; rotate `APP_KEY` and fiscal seeds; `git filter-repo --invert-paths --path .env.backup-pre-round2` then force-push (with owner gate); revoke any tokens issued under the old `APP_KEY`. See `PROJECT_BRAIN.md:53` for the original detection.

2. **Doctrinal contradiction: CLAUDE.md says Claude executes, AGENTS.md says Claude must not execute**. Both load at every session. (`CLAUDE.md:10-13, 70-72` vs `AGENTS.md:113-120, 153`.) *Action*: the owner must pick one and delete the other path. Recommendation in §11.

3. **No CI secret-scanning**. `.github/workflows/*.yml`: 5 workflows, none run gitleaks/trufflehog/detect-secrets. The leak in P0-1 would have been blocked by a 10-line job. *Action*: add `gitleaks-action` to `legacy-guards.yml` pre-existing job on every push + PR.

4. **Frozen-zone gate is prose, not code**. +6,782 lines of accumulated diff on files labelled "frozen", including 2 NF525-critical files (`ZReportService` +714, `AuditLogService` +312). *Action*: convert §7 frozen list into a `scripts/check-frozen-zones.sh` that fails CI if any file in the list changes without a `LOCK_*.md` doc co-committed citing owner sign-off. Frozen-zones are the **single highest-leverage gate** to add.

### P1 — must fix this cycle

5. **`.env.backup-pre-round2` is tracked at HEAD** even though `.env` is gitignored. The `.gitignore` pattern doesn't catch backup-suffix variants. *Action*: extend `.gitignore` to `^\.env(\..*)?$` and `*.env.backup*`; verify with `git check-ignore`.

6. **Permission-allowlist accretion in `.claude/settings.local.json`** — 159 entries, much of it one-shot shell strings from past sessions. Reduces the meaningfulness of every future "do you allow this?" prompt. *Action*: rewrite via the `fewer-permission-prompts` skill (already available); reduce to ~20-30 canonical rules.

7. **Untracked junk in working tree (`,`, `[`, "L'article ne correspond pas.,", "Utilisateur non trouvé.,")** — leftover from shell quoting mistakes in past sessions. Symptomatic of "Claude pipes/redirects fail silently and leave artefacts". *Action*: clean + add `.gitignore` patterns; harden quoting in Claude's Bash usage going forward.

### P2 — backlog

8. **Audit-attribution comments inline in production code** (`[Audit Claude NEW-03 B7]`, `[Audit Claude A1]`, etc.) tie production logic to ephemeral audit reports that may not survive. *Action*: convert to ADR references (`docs/adr/ADR-NN.md`) or remove once audit folder is archived.

9. **Branch.status enum vs literal 1 mismatch healed via listener tolerance** (`PROJECT_BRAIN.md:189-192`). Workaround, not fix. *Action*: planned data-migration `UPDATE branches SET status=5 WHERE status=1`, gated by owner — close the loop.

10. **Cursor doctrine (AGENTS.md) and Claude doctrine (CLAUDE.md) reference deleted/moved files** (e.g. `docs/orchestration/CODEX_API_DELEGATION.md`, `plans/masterplay/MASTERPLAY_DISCIPLINE.md` — last meaningfully updated late April). *Action*: prune AGENTS.md or move it to `docs/_archive/`.

---

## §10 Recommended human-gate checkpoints

Concrete, addable to `.cursor/hooks/safety-check.sh` and CI:

| Trigger | Gate |
|---|---|
| Commit touches `app/Services/Fiscal/**` | Block unless `LOCK_FISCAL_*.md` co-committed, owner-signed |
| Commit touches `app/Services/Pricing/PricingService.php` | Same — block unless LOCK |
| Commit touches `public/js/pos-wizard.js` or `resources/js/components/frontend/kiosk/Kiosk*Component.vue` | Same |
| Schema migration in `database/migrations/` | Require `npm run verify:boucle` log artefact + owner approval |
| Any `.env*` file delta in diff | Hard CI block + auto-gitleaks pass |
| Push to `main` or any `release/*` / `cycle/*` branch | GitHub branch protection rule — required reviewers ≥ 1, status checks required |
| `git filter-repo`, `git push --force` | Owner gate (require typed confirmation in the chat) |
| Commit message starts with `up`, `upp`, `wip`, single char, or empty | CI lint fails — force conventional commit |
| `LOGIN_LOCKOUT_MAX_ATTEMPTS=500` or `PAYMENT_BYPASS_MODE=true` or `PRINTING_BYPASS_MODE=true` in committed `.env*` | Hard block — these are in `.env.backup-pre-round2` today |

---

## §11 Five prompt patterns for safer Claude usage going forward

1. **"Audit-first, no edits" framing** — open every Claude session with: *"You may read freely. You may NOT call Edit, Write, MultiEdit, or Bash with anything other than read commands until I type GO. Your first response is a plan + risk list, citing file:line."* This forces the §5 LOOP step 2 (PLAN) to be a real artefact, not a quick mental sketch.

2. **"Frozen-touch contract"** — before any edit, the prompt template: *"Run `git diff --name-only` against the frozen list (CLAUDE.md §7). If any frozen file appears, STOP, write a `LOCK_<id>.md` document, and wait for my typed 'LOCK_APPROVED' before re-attempting."* Make this a hook in `safety-check.sh`.

3. **"Two-agent rule for production edits"** — for any change touching `app/Services/Fiscal/**`, `app/Services/Pricing/**`, `app/Domain/Order/**`, `app/Models/Scopes/BranchScope.php`, `database/migrations/*`: spawn a second sub-agent in `Task` mode (read-only) explicitly framed as adversarial reviewer, **before** the human gate. The 2026-05-09 POS audit (4 P0s cross-validated) proves this works at audit time — apply it at edit time.

4. **"Evidence-bound completion"** — every "task done" message must include three concrete artefacts: a `git diff --stat` paste, the exact phpunit/vitest filter command + tail, and (if frontend) a screenshot path *that Claude has Read*. CLAUDE.md §13 already says this; make the harness enforce it via a `post-execute` hook that scans the final assistant message for these markers and rejects the commit if absent.

5. **"BRAIN.md and Graphiti as the only memory"** — pre-set instruction: *"You may read CLAUDE.md, PROJECT_BRAIN.md, the 3 most recent `reports/audit/*/FINAL_VERDICT.md` files, and the 2 latest `plans/MASTER_*.md`. You may NOT read older files unless you explain why in writing first."* This caps the context-poisoning surface (audit folders rotate, prompt files at root are stale) and forces deliberate retrieval.

---

## §12 What Claude does today vs. what Claude should NOT do alone

| Activity | Today | Recommended split |
|---|---|---|
| Plan generation | Claude | Claude (strong, keep) |
| Read & audit large codebase | Claude with parallel sub-agents | Claude (strongest discipline observed, keep) |
| Adversarial cross-validation | Claude spawns multi-agent | Claude (keep — proven defence against invention) |
| Implementation of routine code | Claude direct edit | Claude **with** the §11 patterns + 2-agent rule for sensitive files |
| Implementation touching NF525 / pricing / branch-isolation / state-machine | Claude direct edit (frozen-zones drift evidence) | **Human or second model** — Claude generates a patch, human or a second agent applies. Frozen-zones must be a real gate. |
| Schema migrations | Claude writes + applies | Claude writes; human applies after dry-run on a copy DB. |
| Secret rotation | Not actioned despite detection (§3) | **Human only** — Claude flags + drafts the IAM commands; human runs. |
| Commit & push | Claude with `Co-Authored-By` | Claude commits to feature branches only; push to `main`/`release/*` requires human typed `PUSH OK`. |
| Self-attestation of "production-ready" | Claude marks ✅/GO routinely | **Two-source rule** — never mark GO unless a 2nd agent verdict OR an executed `phpunit + playwright` log corroborates. The May-09 POS retraction proves the danger. |

---

## §13 Top 3 recommendations to de-risk the dependency

### 1. Make the frozen-zone gate real — not prose, but CI
Add `scripts/check-frozen-zones.sh` invoked from `.github/workflows/legacy-guards.yml`. It reads the file list from `CLAUDE.md §7`, runs `git diff --name-only origin/main…HEAD`, and **fails** if any frozen path is touched without a matching `LOCK_*.md` co-committed. This single change converts the most-violated invariant from theatre to enforcement. Cost: ~1 hour of work. Payoff: eliminates the silent regulatory drift on `ZReportService` and `AuditLogService`.

### 2. Resolve the CLAUDE.md ↔ AGENTS.md contradiction in one direction
The current observed reality is **Claude-mono-executor**. Either:
- **Option A (faster, lower risk now)**: archive `AGENTS.md` to `docs/_archive/AGENTS_2026-05.md`, declare `CLAUDE.md` as sole operating contract, and add a paragraph there acknowledging Claude IS the executor *and* spelling out the 2-agent rule for NF525/pricing/auth changes (so it's not an unchecked solo executor).
- **Option B (longer-term, lower steady-state risk)**: reactivate the Codex pipeline for real — but only if the owner is going to maintain it. The hybrid status quo is the worst of both worlds.

This is a doctrine decision the owner must make; Claude cannot make it for itself.

### 3. Bolt secret-scanning + commit-message hygiene to CI today
Three concrete adds, total ≤ 30 minutes:
- `gitleaks-action` step in `legacy-guards.yml` on push + PR.
- `commitlint` (conventional-commits config) blocking `up`/`upp`/`wip`/empty messages.
- A pre-receive hook (or branch protection equivalent) requiring at least one tagged review label before push to `main`.

These don't change how Claude works. They change what gets *through* — which is exactly where the system is currently weak.

---

## §14 Closing assessment for the CTO

This is **not a junior shop**. The methodology (cross-agent adversarial audits, PROJECT_BRAIN.md SSOT, frozen-zone *intent*, NF525 *intent*, visual mandate *intent*, evidence rules in CLAUDE.md §13) is more disciplined than what most senior solo devs maintain. The audit folders themselves (`reports/audit/ultra-review-2026-05-16/`, `reports/audit/ultra-goal-2026-05-13/`) are above the bar of what most agencies deliver to clients.

But: the **gate between "Claude detected" and "production protected" is missing**. The AWS key sitting in HEAD three days after the audit caught it is the canonical proof. Fix the gates (frozen-zone CI, secret-scan CI, commit-message hygiene, doctrine deconfliction) and this stack is genuinely viable for V1. Leave them as they are, and the next "up" auto-commit at 03:51 AM is going to make news.

Runtime AI exposure being zero is the saving grace. The restaurant won't go down if Anthropic changes. The development workflow will — and that risk is owner-tolerable as long as `PROJECT_BRAIN.md` + `plans/` + `reports/audit/` stay readable to a human.

— Agent 8 (Claude-dependency)
