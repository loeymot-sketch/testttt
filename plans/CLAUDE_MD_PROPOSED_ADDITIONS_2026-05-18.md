# CLAUDE.md — 4 sections proposées (owner review)

> **Statut** : DRAFT — owner countersign requis avant insertion dans `CLAUDE.md` (master operating memory project-tracked).
> **Source** : `/insights` 2026-05-18 — analyse cross-session 263h/50 sessions/89 commits/82% satisfaction.
> **Justification** : codifier les patterns récurrents pour éviter friction (29 buggy first-pass + 16 wrong approach + long sessions hit limits).

---

## Section 1 — `## Audit & Review Workflow` (insertion suggérée near top of CLAUDE.md, après §4)

```markdown
## Audit & Review Workflow

- Always run E2E tests (Playwright) after multi-file changes before declaring convergence
- For audits: deliver structured GO/NO-GO verdicts with P0/P1/P2/P3 findings and file:line citations
- **Verify findings against actual files before reporting** — read each cited file:line, drop unverified findings, log "unverified" table at report end
- Use parallel sub-agents for independent audit dimensions (architecture, UX, a11y, security, data-integrity, NF525-fiscal)
- Cross-validation : promote a finding to P0 only if ≥2 independent agents confirm
- Adversarial RED dispute mandatory post-commit (try direct/indirect/hidden/visible/security vectors)
```

**Pourquoi** : 16+ sessions ont produit des hallucinated P0 findings (non-existent config files, fabricated products). Force-verify-before-report supprime cette friction.

---

## Section 2 — `## Data Source of Truth (SSOT)` (insertion suggérée before any implementation guidance, après §7 Frozen Zones)

```markdown
## Data Source of Truth (SSOT)

### FoodKing / Le Cayenne menu
- SSOT = `kiosk config` + `config/menu.php` + DB seed commands (`MenuResetLeCayenneCommand`, `MenuHealLightV2Command`)
- **NEVER invent products / categories / wizards** — read the SSOT first, confirm exact items before generating code referencing menu
- Mobile + Web frontend canonical mirror : `mobile/data/menu.js` + `web/data/menu.js`
- Verify `item_branch_availability` coverage across variations / extras / addons, not just base products

### Mobile + Web frontend zones
- `mobile/` (frontends/foodking-web/web/testttt/mobile/) — STANDALONE per owner instruction. NO API/MCP wireup unless explicitly requested.
- `/Users/1millnonstop/Downloads/web/` — Web site STANDALONE. Same rule.
- Frozen-zone scope: data layer mirror DB shape pour wireup mécanique futur, mais aucun trust frontend pricing/composition.

### Pricing SSOT
- 100% backend authoritative via `PricingService::calculateOrder` (FROZEN)
- Frontend sends only `item_id, quantity, option_ids`
- composition_snapshot frozen at order creation, 5 INSERT-only write sites, 0 UPDATE
```

**Pourquoi** : Claude a inventé "Box Familiale/Solo/Nashville" + custom skills + wrong palette (Cayenne red vs black/orange/yellow) faute de SSOT explicite. Front-loading l'évite.

---

## Section 3 — `## Environment Safety` (insertion suggérée near bottom of CLAUDE.md)

```markdown
## Environment Safety

### Commandes dangereuses (à éviter)
- **NEVER `composer dump-autoload` against a running dev server** — stale autoload state + platform_check mismatches breaking PHP-FPM workers
- **NEVER commit `.env` files** — check `git status` before every commit, explicitly exclude `.env`, `.env.local`, secrets
- **Pin PHP version explicitly** (project = PHP 8.4+) — do not rely on system default which may shadow

### Anti-patterns observés
- AWS keys committed to .env then quietly untracked (pregnancy app autonomous YC-style audit) → mandate "AWS rotation owner-physique only"
- composer dump-autoload mid-session broke dev server requiring restart

### Bonnes pratiques
- Use `php artisan` commands plutôt que raw composer pour preserve autoload state
- `git add <specific-files>` plutôt que `git add -A` ou `git add .` qui peut inclure .env
- Before any heavy operation : `git status` + `git diff --cached` pour vérifier scope
```

**Pourquoi** : 2 incidents documentés. Hook PreToolUse pourrait bloquer ces commandes (cf. plans/HOOK_PRE_TOOL_USE_DANGEROUS_CMDS_PROPOSED.md à créer).

---

## Section 4 — `## Execution Mode` (insertion suggérée near top of CLAUDE.md, après §1 Identity)

```markdown
## Execution Mode

### Carte-blanche / autonomous / continue
Quand l'owner dit :
- "autonomous"
- "continue"
- "carte-blanche"
- "don't turn me back"
- "just execute"
- "ne me retourne pas"

→ Execute without clarifying questions. Report **results, not plans**. Use `TaskCreate` to track progress visibly, but do NOT block on user input. Do NOT call `AskUserQuestion` mid-flight.

### Default to action over planning
Quand le scope est clair (single bug fix, scope-minimal change, well-defined refactor) :
- Skip the planning meta-discussion
- Edit file directly (inline ≤30 LOC) OR dispatch Implementer subagent (>30 LOC ou >3 files)
- Use Graphiti memory + `PROJECT_BRAIN.md` to recover context, pas demander à l'owner

### Quand pause + ask (exception)
- Frozen-zone touch needed (CLAUDE.md §7) → write LOCK plan, ask owner countersign
- Architectural decision uncertain (multiple valid approaches, irreversible)
- Push to remote / public PR / production data deletion → human gate
- Evidence too weak / contradiction surface → surface to owner before proceeding

### Pattern « hostile review checkpoints »
Owner grants broad autonomy but expects adversarial validation at each gate. Match the pattern : dispatch RED-team sub-agent post-commit even when owner didn't explicit ask. Multi-team mandate déjà documenté (`feedback_massive_team_orchestration_e2e_per_system.md`).
```

**Pourquoi** : Multiples sessions montrent Claude posant questions clarification quand owner voulait exec. "82% satisfaction with 29 buggy events" = owner tolère friction mais frustré par hesitation.

---

## Tableau récapitulatif

| Section | Where to insert | Priorité | LOC ajoutées |
|---|---|---|---|
| Audit & Review Workflow | Après §4 Architecture | HAUTE (16+ sessions audit) | ~10 |
| Data SSOT | Après §7 Frozen Zones | HAUTE (drift fictional products) | ~25 |
| Environment Safety | Près de §15 Project Documents | MOYENNE (2 incidents) | ~15 |
| Execution Mode | Après §1 Identity | HAUTE (frustration pattern) | ~25 |

**Total** : ~75 LOC additions à CLAUDE.md (actuellement ~600 LOC).

---

## Recommandation owner

Reviewer les 4 sections, décider :
- **A**) Accept toutes — insérer dans CLAUDE.md, commit `docs(claude-md): codify 4 patterns from /insights cross-session`
- **B**) Accept partiel — choisir sections + ajustements
- **C**) Defer — laisser comme reference doc only

Mon vote (par poids friction prevention) : **A** avec léger phrasing edit possible.

---

## Related
- `reports/sessions/SESSION_HANDOFF_2026-05-18_FULL.md` §6 Insights
- `memory/feedback_insights_full_2026-05-18.md` — données /insights complètes verbatim
- `memory/feedback_insights_snapshot_2026-05-18.md` — summary version
- `memory/feedback_massive_team_orchestration_e2e_per_system.md` — déjà existant, complémentaire
- `memory/feedback_no_cloud_until_owner_initiates.md` — déjà existant, mandate critique

---

*Draft généré 2026-05-18 par Claude post Critical Focus convergence session. Owner countersign requis avant insertion CLAUDE.md.*
