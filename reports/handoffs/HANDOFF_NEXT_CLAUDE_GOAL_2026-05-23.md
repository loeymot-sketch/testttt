# Handoff → NEW Claude Code session · GOAL MODE · 2026-05-23

## §0. INSTRUCTIONS DE BOOTSTRAP (à exécuter EN PREMIER, avant tout autre travail)

Tu es une **nouvelle session Claude Code** qui hérite de cette mission. La session PRÉCÉDENTE reste **vivante** comme cerveau-orateur (max contexte/mémoire/vision) — toi tu es le **chef de développement exécuteur** avec discipline maximale.

**Lis dans cet ordre, sans sauter :**

1. **`CLAUDE.md`** (505 lignes) — règles opérantes du projet, frozen-zones §7, NF525 §8, discipline LOOP §5
2. **`PROJECT_BRAIN.md`** (1619 lignes) — état projet actuel, §2 CURRENT STATE, §4 NEXT TO DO
3. **`/Users/1millnonstop/.claude/projects/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/memory/MEMORY.md`** — index auto-mémoire owner, ligne 1-50 START HERE
4. **`reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md`** — état Wave Final 7 systèmes (la wave avant ton GOAL)
5. **`reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md`** — Wave Polish précédente (contexte)
6. **`plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md`** — LOCK exception encore active

**Active la mémoire long-terme** : utilise Graphiti MCP avec `group_id="foodking"` au début de chaque tâche significative. Push épisodes à la fin.

**Active Playwright MCP** : `.mcp.json` est déjà créé à la racine du projet (`{ "mcpServers": { "playwright": { ... } } }`). Au premier prompt de demande d'approval, accepte. Vérifie avec `/mcp` que "playwright" est en `connected`.

Une fois ces lectures faites, **acknowledge la mission au user en 3 lignes** + propose ton plan atomique avant de lancer quoi que ce soit.

---

## §1. Cycle state

- **Phase** : EXECUTE (GOAL Mode)
- **Cycle name** : `GOAL_2026-05-23` (post Wave Final 7-system convergence)
- **Active plan** : ce document + référence à `reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md`
- **Latest commit** : `d601fdd34 docs(wave-final 2026-05-23): convergence report — 7 systèmes test-e2e MAX reasoning`
- **Branch** : `heal/cms-pr1-quickwins-2026-05-18` (820 commits ahead of main)
- **Working tree** : **DIRTY** (15+ fichiers `public/js/*.js` rebuild artifacts + `CLAUDE.md` + worktree gitlinks) — voir §11 anti-patterns
- **RUNNER_MODE** (per `.cursor/ACTIVE_CYCLE.md`) : `single-session`
- **ACTIVE_PRIMARY** : `CAISSE_V1_MASTERPLAY`

---

## §1.5 Background you should read (mandatory, ~10 min)

| Ordre | Fichier | Pourquoi |
|-------|---------|----------|
| 1 | `CLAUDE.md` | Règles opérantes — §5 LOOP discipline · §7 frozen-zones · §8 NF525 invariants · §12 anti-drift |
| 2 | `PROJECT_BRAIN.md` | État projet — §1 NORTH STAR · §2 CURRENT STATE · §4 NEXT PLAN |
| 3 | `~/.claude/projects/.../memory/MEMORY.md` | Index mémoire owner — toutes les `feedback_*.md` et `project_*.md` |
| 4 | `reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md` | État GREEN des 7 systèmes pré-GOAL · les 3 findings owner-gate |
| 5 | `reports/test-e2e/wave-polish-final-2026-05-21/CONVERGENCE_FINAL.md` | Wave Polish 14/14 Q1-Q14 owner decisions livrées |
| 6 | `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` | LOCK encore actif sur FiscalSequenceService:88 |
| 7 | `reports/handoff/HANDOFF_CLAUDE_DESIGN_DASHBOARD_2026-05-21.md` | Dashboard redesign délégué à Claude Design (out of scope ici) |

**Time budget pour cette lecture** : 10-15 min. **Ne saute pas** — le downstream foirera sans le contexte.

---

## §2. Mission

**Mandate owner verbatim (2026-05-23)** :
> "Lance le GOAL : applique les corrections que j'ai validées (D1-D10), teste en boucle avec max sub-agents en parallèle, 1 par système (Borne + POS + KDS + OSS + Cash + Stock + Admin), avec adversarial + GStack + Superpowers. Boucle screenshot + analyze + reason + fix jusqu'à ce que tout soit vert."

Le owner a fait **10 choix décisifs** via la page `public/goal.html` :

| ID | Décision | Choix owner |
|----|----------|-------------|
| **D1** | Toast 429 télémétrie borne | `fix-now` |
| **D2** | Format € popup Encaisser | `fix-now` |
| **D3** | Format € PaymentComponent (zone FROZEN) | `lock-fix` (LOCK doc + countersign + fix) |
| **D4** | Nom de domaine | `lecayenne-fr` |
| **D5** | Fournisseur cloud | `hetzner` (CX22 4€/mo) |
| **D6** | Push GitHub | `push-now` |
| **D7** | Préparer script deploy | `prepare-now` |
| **D8** | TPE matériel | `senangpay` |
| **D9** | Activer MCP Playwright | `restart-now` (déjà fait — tu es la nouvelle session) |
| **D10** | Fix Q2 AllergenSentinel CI | `fix-now` |

**Success criteria** (binary, observable) :

- [ ] D1 fix appliqué : `bootstrap.js` axios 429 interceptor allowlist `/kiosk/event` et endpoints télémétrie similaires → pas de toast 429 sur télémétrie
- [ ] D2 fix appliqué : `PosCounterCollectModal.vue` MONTANT REÇU utilise `formatMoneyEuro` global
- [ ] D3 LOCK_PAY doc écrit + owner countersign demandé + (sur approval) fix `PaymentComponent.vue` hero `4.90€` → `4,90 €`
- [ ] D6 : `git push origin heal/cms-pr1-quickwins-2026-05-18` réussi
- [ ] D7 : `scripts/deploy/server-setup.sh` + `scripts/deploy/deploy.sh` + `scripts/deploy/CRONTAB_PROD.md` créés, idempotents, prêts pour Hetzner Ubuntu 22.04
- [ ] D10 : `phpunit.xml` ajout `<groups><exclude><group>manual</group></exclude></groups>` → CI Allergen redevient vert
- [ ] Wave Final round-2 : 7 surfaces re-testées post-fixes, convergence GREEN avec P0+P1=0 stable 2 cycles consécutifs identical sets
- [ ] NF525 chain : `php artisan fiscal:verify-chain` → CHAIN OK pré ET post toutes opérations
- [ ] Frozen-zone diff : 0 ligne sauf D3 LOCK explicite si owner countersign
- [ ] Tous commits avec `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>`

---

## §3. Scope — what to do

**In scope (touch these)** :
- `resources/js/bootstrap.js` (D1, ~10 LOC allowlist)
- `resources/js/components/admin/pos/PosCounterCollectModal.vue` (D2, ~5 LOC formatMoneyEuro)
- `resources/js/components/admin/pos/PaymentComponent.vue` (D3 SEULEMENT après LOCK_PAY countersigné)
- `phpunit.xml` (D10, 3 LOC `<groups><exclude>`)
- `scripts/deploy/` (D7 — création scripts)
- `plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md` (D3 LOCK doc)

**Out of scope (do NOT touch)** :
- Dashboard redesign — Claude Design a son handoff dédié (`reports/handoff/HANDOFF_CLAUDE_DESIGN_DASHBOARD_2026-05-21.md`)
- Multi-tranche split counter-collect — backlog V1.0.2 NF525 LOCK requis
- KDS revert PREPARED→PREPARING — backlog V1.0.2 frozen-zone LOCK requis
- Cash drawer count input feature — backlog V1.0.2 nouvelle feature
- EN/AR catalogs — owner explicite "FR only V1"
- Cloud déploiement actif — `D7=prepare-now` = script PRÊT sur disque seulement, ne lance PAS le déploiement

**Plan reference** : ce document + `reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md` §7 (3 owner-gate items)

**Tasks ordonnées atomiques** :

### Phase A — Apply fixes (parallèle scope-minimal, 1-2h)
1. **A1 (D10)** Fix Q2 AllergenSentinel : ajouter `<groups><exclude><group>manual</group></exclude></groups>` dans `phpunit.xml`. Verify : `php artisan test --filter=AllergenCoverage` → 0 fail (au lieu de 2).
2. **A2 (D1)** Fix télémétrie 429 : `resources/js/bootstrap.js:176-200` ajouter allowlist endpoints `^\/api\/frontend\/kiosk\/event$` (et autres télémétrie) qui bypass toast user-facing 429. Test : burst 5×POST /api/frontend/kiosk/event → 0 toast visible.
3. **A3 (D2)** Fix € format `PosCounterCollectModal.vue` MONTANT REÇU input : utiliser `formatMoneyEuro(value)` helper existant (déjà importé via Wave Polish Phase 2A). Test : ouvre modal, vérifie hero `8,50 €` (virgule + nbsp + €).
4. **A4 (D3 LOCK)** Écris `plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md` via skill `/lock-plan`. Mention : scope (1 ligne hero formatter), justification (cosmétique owner-mandated D3), rollback. **STOP ici** et attends countersign owner explicite. NE TOUCHE PAS `PaymentComponent.vue` avant countersign.
5. **A5** Rebuild bundles : `npx mix` (8-15s). Verify bundles `public/js/admin-shell.js`, `pos-app.js`, `pos-shell.js` mtime > sources mtime.
6. **A6** Commit Phase A : `fix(goal-D1+D2+D10): telemetry 429 allowlist + counter-collect € format + phpunit manual exclude` (ne commit pas D3 tant que pas countersigné).

### Phase B — Test loop massif parallel (single-message, ~30-50 min wall-clock)
Dispatch **7 sub-agents en PARALLÈLE single-message** (1 par système). Chaque agent suit le protocole MAX REASONING :
- Capture quartet (PNG + DOM + console + network) per state via `tests/e2e/helpers/mega-audit-snap.js`
- Read PNG multimodally
- Reason against PROJECT GOAL (V1 single-resto FR + sync + NF525 + frozen-zone + "no useless complexity")
- Adversarial DISPUTE inline (challenge every finding)
- Classify CRITICAL / IMPROVEMENT / INFO
- Apply IMPROVEMENT only if scope-minimal ≤30 LOC FR-only
- Surface CRITICAL avec evidence pour owner-gate
- Write findings JSON to `reports/test-e2e/goal-2026-05-23/round-N/SX-system-findings.json`

**Les 7 systèmes** (mêmes que Wave Final) :
- **S1 Borne kiosk** `/kiosk/idle` — verify D1 fix (no telemetry toast on cash-instruction screen)
- **S2 Caisse POS** `/admin/pos` — verify D2 fix (PosCounterCollectModal € format)
- **S3 KDS** `/kds` — no fix changes, sanity verify
- **S4 OSS** `/order-status-screen` — no fix changes, sanity verify
- **S5 Cash Overview** `/admin/cash-overview` — no fix changes, sanity verify
- **S6 Stock** `/admin/stock/rupture` — no fix changes, sanity verify
- **S7 Admin** `/admin` — no fix changes, sanity verify

Chaque agent **prompt SELF-CONTAINED** comme dans `reports/test-e2e/wave-final-2026-05-23/round-1/SX-*-findings.json` predecessors.

**Convergence rule** : 2 consecutive rounds with `open_P0=0 AND open_P1=0 AND identical findings set`. Si fixed-induced regression → round-N+1.

### Phase C — Push GitHub (D6, ~2 min)
```
git push origin heal/cms-pr1-quickwins-2026-05-18
```
Verify : `git log --oneline origin/heal/cms-pr1-quickwins-2026-05-18 -1` matches local HEAD.

### Phase D — Deploy script Hetzner (D7, ~45 min)
Crée `scripts/deploy/` avec :
- `server-setup.sh` — Ubuntu 22.04 setup : PHP 8.x + MySQL 8 + Nginx + Composer + Node 18 + Supervisor + Certbot + UFW firewall
- `deploy.sh` — clone repo, `.env` config, `composer install`, `npm install && npx mix --production`, `php artisan migrate --force`, supervisor restart
- `nginx.conf.template` — Nginx config Laravel + WebSocket upgrade for soketi + Let's Encrypt SSL
- `supervisor.conf.template` — queue:work + soketi
- `CRONTAB_PROD.md` — `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` + backup rotation cron
- `README_DEPLOY.md` — owner instructions step-by-step pour Hetzner CX22 + lecayenne.fr DNS

**DO NOT execute the deployment** — script PRÊT sur disque seulement. Owner décide quand lancer.

### Phase E — Synthesis report (final, ~15 min)
Écris `reports/goal-2026-05-23/GOAL_FINAL_REPORT.md` :
- Phase A fixes commits + LOC delta
- Phase B convergence verdict (round count + final P0/P1 counts + findings JSON pointers)
- Phase C GitHub push verify
- Phase D scripts created (path list + size)
- D3 LOCK_PAY status (DRAFT awaiting countersign / SIGNED+APPLIED / ABANDONED)
- NF525 chain pre+post
- Frozen-zone diff verify
- Update `PROJECT_BRAIN.md` §2 §3 §4
- Push épisode Graphiti `foodking` group

---

## §4. Frozen zones — files the receiver must NOT touch

| File | Reason | LOCK if override needed |
|------|--------|-------------------------|
| `resources/js/components/admin/pos/PaymentComponent.vue` | §7 design protected (V1 validé owner 2026-05-06) | `plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md` (TO BE WRITTEN for D3) |
| `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` | §7 design protected | none — no override |
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | §7 V1 production-ready | none |
| `resources/js/components/frontend/kiosk/KioskAppComponent.vue` | §7 | none |
| `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue` | §7 | none |
| `public/js/pos-wizard.js` + `public/css/pos-wizard.css` | §7 Vanilla JS wizard parfait selon owner | none |
| `app/Services/Fiscal/FiscalSequenceService.php` | §8 NF525 chain integrity | `plans/LOCK_FISCAL_WGS_Z6_P1_2026-05-19.md` (existant active) |
| `app/Services/Fiscal/ZReportService.php` | §8 NF525 | none |
| `app/Services/Fiscal/AuditLogService.php` | §8 NF525 append-only | none |
| `app/Models/Scopes/BranchScope.php` | §9 multi-tenant locked | none |
| `app/Http/Middleware/IdempotencyKeyMiddleware.php` | §7 V1 validated | none |
| `app/Services/Pricing/PricingService.php` | §8 SSOT pricing | none |
| `app/Domain/Order/OrderStateMachine.php` | §7 transitions controlled | none |
| Migrations applied (any in `database/migrations/` dated ≤ 2026-05-22) | Cannot edit after applied | none |

**Si tu dois toucher un de ces fichiers** : STOP, écris LOCK_<id>.md via skill `/lock-plan`, demande countersign owner, attends approval explicite, JAMAIS bypass `safety-check.sh`.

---

## §5. Files to touch (with line ranges)

**Read first (context — don't modify)** :
- `reports/test-e2e/wave-final-2026-05-23/round-1/S1-kiosk-findings.json` — S1-002 evidence exact
- `reports/test-e2e/wave-final-2026-05-23/round-1/S2-pos-findings.json` — S2-001 + S2-002 evidence
- `tests/e2e/helpers/mega-audit-snap.js` — quartet recorder
- `tests/e2e/helpers/login.js` — admin auth
- `tests/e2e/helpers/kiosk-order.js` — kiosk seeding

**Modify** (with intent) :
- `resources/js/bootstrap.js` — lines ~176-200 : ajouter check `endpoint not in TELEMETRY_ALLOWLIST` avant fire toast 429
- `resources/js/components/admin/pos/PosCounterCollectModal.vue` — ligne ~XX (grep `MONTANT REÇU` ou `received-amount`) : wrap value avec `formatMoneyEuro()`
- `phpunit.xml` — section `<phpunit>` ajouter `<groups><exclude><group>manual</group></exclude></groups>`

**Create** :
- `plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md` (D3 LOCK)
- `scripts/deploy/server-setup.sh` + `deploy.sh` + `nginx.conf.template` + `supervisor.conf.template` + `CRONTAB_PROD.md` + `README_DEPLOY.md` (D7)
- `tests/e2e/goal-D1-telemetry-allowlist.spec.js` (verify A2 fix)
- `tests/e2e/goal-D2-counter-collect-euro.spec.js` (verify A3 fix)
- `reports/test-e2e/goal-2026-05-23/` (Phase B output dir)
- `reports/goal-2026-05-23/GOAL_FINAL_REPORT.md` (Phase E synthesis)

**Tests to pass (verify after change)** :
- `php artisan test --filter=AllergenCoverage` (D10 — expect 0 fail post-A1)
- `npx playwright test tests/e2e/goal-D1-telemetry-allowlist.spec.js`
- `npx playwright test tests/e2e/goal-D2-counter-collect-euro.spec.js`
- `npx vitest run tests/js/sentinels/posCounterCollectModalSentinel.spec.js` (no regression D2)
- `npx vitest run tests/js/sentinels/posShortcutsSentinel.spec.js` (no regression)
- `npx vitest run tests/js/sentinels/paymentComponentEmitsJsdocList.spec.js` (FROZEN sentinel D3)
- `npx vitest run tests/js/sentinels/kdsHistoryDrawerSentinel.spec.js`
- `npx vitest run tests/js/sentinels/kdsBundleFreshnessSentinel.spec.js` (Q12 bundle freshness)
- `php artisan fiscal:verify-chain` (NF525 chain pre + post)

---

## §6. Acceptance criteria (verifiable, not judgmental)

Avant chaque commit, vérifier TOUTES :

- [ ] `php artisan test --filter=AllergenCoverage` passes (post-A1)
- [ ] `npx mix` succeeds (no webpack error)
- [ ] `public/js/admin-shell.js` + `pos-app.js` + `pos-shell.js` mtime > sources mtime (bundles fresh)
- [ ] Visual check post-A2 : burst 5× POST `/api/frontend/kiosk/event` → 0 user-facing toast
- [ ] Visual check post-A3 : PosCounterCollectModal MONTANT REÇU shows `8,50 €` (comma + space + €), not `8.50`
- [ ] D3 NOT modified PaymentComponent.vue unless `plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md` exists AND has owner countersign line
- [ ] `git diff main -- resources/js/components/admin/pos/PaymentComponent.vue resources/js/components/admin/pos/v5/PosV5TrancheRow.vue app/Services/Fiscal/ app/Models/Scopes/BranchScope.php app/Http/Middleware/IdempotencyKeyMiddleware.php app/Services/Pricing/PricingService.php app/Domain/Order/OrderStateMachine.php public/js/pos-wizard.js public/css/pos-wizard.css` = empty (frozen-zone discipline)
- [ ] `php artisan fiscal:verify-chain` says "CHAIN OK" (NF525 integrity)
- [ ] No new console errors (filter vendor/Pusher noise)
- [ ] No 4xx/5xx silently swallowed in network captures
- [ ] Phase B convergence : 2 consecutive rounds P0+P1=0 identical set

**If ANY criterion fails → DO NOT COMMIT. Surface back to owner.**

---

## §7. Commit + push instructions

**Commit message format (project convention)** — exemples extraits de `git log -5` :

```
fix(kiosk-cart): pre-flight prune unavailable lines before Valider quote — closes owner bug 2026-05-21

test(wave-final-S2 round-4): full PaymentComponent flow + amend findings (GREEN)

docs(wave-final 2026-05-23): convergence report — 7 systèmes test-e2e MAX reasoning
```

**Format à suivre** :
```
<type>(<scope>): <subject ≤72 chars>

<body — what + why, max 5-10 lines>

Source: owner D<N> decision 2026-05-23

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
```

**Branch operations** :
- Stay on `heal/cms-pr1-quickwins-2026-05-18` — do NOT switch / NOT merge to main
- After commit : `git status --short` should be clean (sauf worktree gitlinks ` m` qui restent)
- **D6 push** : explicit `git push origin heal/cms-pr1-quickwins-2026-05-18` (owner authorized)
- Do NOT amend — create new commits

---

## §8. Rollback plan

Si un test fail post-commit OU régression observée :

1. **Diagnose first** : `git diff HEAD~1` lire ce qui a changé
2. **Soft revert** (préféré) : `git revert <sha>` — préserve l'historique
3. **Hard reset** (uniquement local pré-push) : `git reset --hard HEAD~1` — confirm avec owner

**Files most likely to need rollback** :
- `resources/js/bootstrap.js` (D1) — risk : break axios interceptor pour autres surfaces si allowlist regex trop large
- `resources/js/components/admin/pos/PosCounterCollectModal.vue` (D2) — risk : testid casse si réorganisé
- `phpunit.xml` (D10) — risk : exclude trop large peut masquer d'autres groupes

**Wave Polish/Final reference** : `git log --oneline e7278a91f..d601fdd34` montre la pattern de rollback safe via revert.

---

## §9. Open questions / decisions deferred to receiver

- **D3 PaymentComponent LOCK** — owner a dit `lock-fix` (autorise) mais le LOCK_PAY doc n'est pas écrit. Tu écris le LOCK doc en premier (Phase A4), tu surface au owner pour countersign, et SEULEMENT après countersign explicite tu modifies `PaymentComponent.vue`. Si owner change d'avis (skip LOCK) → respecte.
- **Phase D scripts détail** — tu choisis l'organisation interne des scripts deploy (un fichier ou plusieurs) mais respecte les critères : idempotent, bulletproof, owner instructions claires step-by-step.
- **Phase B convergence loop count** — tu décides combien de rounds (target ≤3, max 5). Si 5 rounds sans convergence → STOP et surface au owner.
- **D9 MCP Playwright** — owner a dit `restart-now`. Tu ES la nouvelle session avec MCP. Vérifie `/mcp` que playwright est connected. Si non connecté → utilise scripts Playwright classiques (fallback).

---

## §10. Reasoning hints (for an LLM receiver)

- **Owner mantra hard constraint** : `feedback_no_cloud_until_owner_initiates.md` — pas d'action cloud production tant que tu n'as pas reçu "go production" explicite. Phase D = scripts SUR DISQUE seulement.
- **Owner mantra** : `feedback_pos_simulation_hardware_pattern.md` — POS_SIMULATION_HARDWARE=true reste actif tant que TPE pas branché (D8 senangpay est pour V1.0.1, pas maintenant).
- **Pattern récurrent** : `feedback_adversarial_audit_pattern.md` — quand tu fais Phase B, dispatch 7 agents en parallèle SINGLE-MESSAGE (un seul message avec 7 tool_calls Agent). Ne PAS séquentiel.
- **Pattern récurrent** : `feedback_massive_team_orchestration_e2e_per_system.md` — un agent dédié par système, GStack + Superpowers + adversarial inline.
- **Frozen-zone discipline** : `feedback_frozen_zone_override_2026-05-06.md` — owner a autorisé override SI LOCK doc countersigné. Sinon ZÉRO touche.
- **Pattern bundle staleness** : `Q12 KDS bundle freshness sentinel` au commit `3122214175`. Si tu modifies un fichier Vue, **REBUILD MANDATORY** via `npx mix` AVANT commit, sinon CI rouge.
- **Graphiti MCP** : group_id=`foodking`. Search "wave-final" / "kiosk-valider" / "Q9-S1" pour épisodes récents. Push épisode à la fin de la mission.
- **Disk constraint** : owner disque souvent à 99-100% utilisé. Cleanup screenshots/logs anciens si nécessaire avant captures massives. **DEMANDE permission avant `rm` destructif.**

---

## §11. Anti-patterns the receiver should avoid

- **Don't bypass `safety-check.sh`** même si LOCK approuvé — le hook donne un filet déterministe
- **Don't silence symptoms** — root-cause it. Owner explicit "no caveats, no companion-spec attribution"
- **Don't restart Claude Code mid-GOAL** — tu dois rester actif jusqu'à Phase E complete
- **Don't dispatch 7 agents en séquence** — single-message parallèle obligatoire (sinon ~4h au lieu de 50 min)
- **Don't commit dirty bundles** sans rebuild si Vue source touché — Q12 sentinel pète
- **Don't restore item availability sans Cache::forget** — Q9-S1 fix dépend de cache invalidation explicite
- **Don't claim convergence GREEN après 1 seul round** — convergence rule = 2 cycles consécutifs identical findings
- **Don't update PROJECT_BRAIN.md** prématurément (avant Phase E) — c'est le dernier step
- **Don't push to main** — `heal/cms-pr1-quickwins-2026-05-18` only
- **Don't merge sub-agent commits sans review** — chaque sub-agent commit reste atomic, tu agrégates en synthesis Phase E

---

## §12. Skills + tools to use

**Skills disponibles (vérifie au démarrage avec listing)** :
- `superpower-gstack` — pipeline 7-step Think→Plan→Build→Review→Test→Ship→Reflect
- `superpowers:brainstorming` — exploration intent avant code (Phase A planning)
- `superpowers:subagent-driven-development` — Phase B dispatch
- `superpowers:dispatching-parallel-agents` — Phase B dispatch (7 agents single-message)
- `superpowers:systematic-debugging` — si Phase B trouve bug
- `superpowers:verification-before-completion` — avant chaque commit
- `test-e2e` — Phase B convergence loop with adversarial team
- `superpowers:writing-plans` — Phase A planning (avant code)
- `superpowers:executing-plans` — Phase A execution
- `lock-plan` — Phase A4 D3 LOCK doc
- `cycle-status` — au démarrage pour situation rapide

**MCPs disponibles** :
- `playwright` (D9 activé via `.mcp.json`) — pilote browser live
- `graphiti` group_id=`foodking` — mémoire long-terme épisodique

---

## §13. Owner mandate (verbatim, garde-le précieusement)

> "Je veux pas la perdre [cette session] pour cela, je demande qu'on démarre une nouvelle session avec le goal et qu'on transfère le maximum. Ça veut dire d'ordre et de discipline et d'intelligence et de mémoire etc. pour avoir la meilleure vision. Le bien discipliné jusqu'à elle est légale. Je veux aussi utiliser le mode danse, ça veut dire insister sur le mode /test-e2e et /goal et utiliser les teams gstack et superpowers et le maxi Sub agent lancer avec ses skills qui seront spécialistes de ce qu'ils ont et ils ont à faire la tâche tout l'ancien en parallèle pour aller plus vite. Voilà c'est ça le ce que je demande là. Maintenant faudra bien avoir le maximum d'intelligence pour crier tout le goal et lancer dans une nouvelle session qu'il demande vraiment de lire le maximum de contexte maximum de référence, mais via même notre MCP graffiti via les dossiers de les nommer de mémoire, de vraiment de savoir la vision d'être vraiment toi. Actuellement tu sois vraiment l'orateur, le planificateur et le cerveau du projet et on va transférer une nouvelle session pour qu'il fera le but avec un chef d'équipe, un chef de développement qui a tout le mémoire il Serreau puis tu vas le déléguer, il va lancer toutes les autres Sub agent pour atteind soit le maximum intelligent. Je t'explique le test comme le skills le dit cuit bien que il fera un test en boucle, capture d'écran, analyse raisonnement For affichage et logique et technique côté technique même le code et synchronisation s'il peut déterminer avec les agents adversaires et les agents principal des problématiques il va corriger tout au long de ce parcours, et il va refaire la boucle pour chaque système, ça lancer un agent dédié pour faire ça pour chaque système. Tout en parallèle comme j'ai dit voilà c'est ça le goal."

**Traduction structurée** :
1. Cette session = brain qui reste vivant
2. Nouvelle session = chef développement exécutant le GOAL
3. Max contexte transféré (CLAUDE.md + PROJECT_BRAIN + MEMORY + reports + Graphiti)
4. Mode `/test-e2e` + `/goal` + GStack + Superpowers
5. Max sub-agents parallèle (7 systèmes, 1 dédié chacun)
6. Discipline : test boucle → screenshot → analyse → raisonnement → fix → boucle
7. Adversarial team + main team
8. Chaque système son agent
9. Tout en parallèle (single-message)

---

## §14. Quick boot reference card

```
1. Read CLAUDE.md + PROJECT_BRAIN.md + MEMORY.md (10-15 min)
2. Read reports/test-e2e/wave-final-2026-05-23/CONVERGENCE_FINAL.md
3. /mcp → verify playwright connected
4. Graphiti search "wave-final goal" group=foodking
5. Acknowledge mission en 3 lignes au owner
6. Phase A : apply D1, D2, D10 (parallèle) + D3 LOCK doc only
7. Phase B : 7 sub-agents single-message parallèle Wave Final round-2
8. Phase C : git push (D6) après convergence Phase B
9. Phase D : create scripts deploy Hetzner (D7) — NO execute
10. Phase E : synthesis report + BRAIN update + Graphiti episode push
```

---

## §15. Quel agent ES-tu ?

**Tu n'es pas l'orateur** — c'est l'ancienne session.
**Tu es le chef de développement exécutant** — discipline maximum, ne dévie pas du plan.

Ton job :
- Lire le contexte (10 min mandatory)
- Acknowledge la mission au user
- Exécuter Phases A-E dans l'ordre
- Dispatch les sub-agents en parallèle (jamais en séquence)
- Boucler test-e2e jusqu'à GREEN
- Surface au user les owner-gates (D3 countersign, critical findings Phase B)
- Synthèse finale + commits + push

Si tu hésites entre 2 options → consulte `advisor()` ou demande au owner. Ne décide pas seul sur les architecturaux.

---

**Owner mandate verbatim conservé en §13. Cette mission est ton seul focus. Lance-toi.**
