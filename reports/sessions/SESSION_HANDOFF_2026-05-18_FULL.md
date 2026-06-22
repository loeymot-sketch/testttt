# SESSION HANDOFF — FoodKing V1 Le Cayenne — 2026-05-18

> **Pour qui** : la prochaine session Claude (ou owner) qui reprend le projet à froid.
> **Objectif** : zéro perte de contexte — bootstrap complet, état, décisions, backlog, insights.
> **Lire en premier** : ce document, puis `CLAUDE.md`, puis `PROJECT_BRAIN.md`.

---

## ⚡ Bootstrap one-liner (copier-coller pour démarrer)

```bash
# Smoke complet en 30 secondes
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt && \
echo "=== Branch ===" && git branch --show-current && \
echo "=== HEAD ===" && git log -1 --oneline && \
echo "=== NF525 chain ===" && php artisan fiscal:verify-chain --branch=1 && \
echo "=== Frozen-zone diff (must be 0) ===" && \
git diff --stat 6908edbde..HEAD -- \
  public/js/pos-wizard.js public/css/pos-wizard.css \
  resources/views/admin-pos-v4.blade.php \
  'resources/js/components/frontend/kiosk/KioskWizardComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskAppComponent.vue' \
  'resources/js/components/frontend/kiosk/KioskUpsellComponent.vue' \
  app/Services/Fiscal/FiscalSequenceService.php \
  app/Services/Fiscal/AuditLogService.php \
  app/Services/Fiscal/ZReportService.php \
  app/Models/Scopes/BranchScope.php \
  app/Http/Middleware/IdempotencyKeyMiddleware.php \
  app/Services/Pricing/PricingService.php \
  app/Domain/Order/OrderStateMachine.php && \
echo "(empty diff = frozen-zone 0 OK)" && \
echo "=== Recent commits ===" && git log --oneline 6908edbde..HEAD | head -15
```

**Output attendu** : Branch=v1-0-1-hardening-2026-05-17 · HEAD descendant `1e7c65ecc` · NF525 = CHAIN OK · frozen-zone diff = empty · ~40 commits scope-minimal.

---

## §0 — Bootstrap rapide (lire en 60 secondes)

### État projet
- **Branche active** : `v1-0-1-hardening-2026-05-17`
- **Tag récent** : `v1.0.2-rc1-2026-05-18` (post mission GOAL)
- **HEAD** : `1e7c65ecc` (orchestrators parallel zone convergence)
- **NF525 chain** : `CHAIN OK (audit_logs + z_reports)`
- **Frozen-zone diff** : 0 lignes sur 13 fichiers protégés
- **Test status** : 800+ PHPUnit feature GREEN, 50+ Vitest sentinels GREEN, 7 zones E2E GREEN

### Verdict V1 Le Cayenne single-resto LOCAL
🟢 **GO LOCAL** — système fonctionnel, robuste, validé visuellement + techniquement par 7 orchestrators zones en parallèle massif.

### Owner mandate critique
- **NO cloud talk** — production deploy = initiative owner uniquement. Tant qu'owner ne dit pas explicitement "go production", focus 100% local.
- **Massive parallel orchestration** — pour les missions V1 robust : 3 teams parallèles obligatoires (GStack + Superpowers + Adversarial RED).
- **test-e2e per system** — visual capture + analyse + correction loop jusqu'à validation.

### À faire EN PREMIER pour reprendre

1. Lire `CLAUDE.md` (master operating memory)
2. Lire `PROJECT_BRAIN.md` §1 NORTH STAR + §2 CURRENT STATE
3. Lire ce document
4. Lire `reports/test-e2e/critical-focus-2026-05-18/MASTER_CONVERGENCE_FINAL.md` (7-zone convergence)
5. `git status` + `git log --oneline 6908edbde..HEAD | head -30`
6. `php artisan fiscal:verify-chain --branch=1` (doit retourner CHAIN OK)

---

## §1 — Cette session (2026-05-18 Critical Focus Convergence)

### Mission
Owner a demandé d'identifier les parties V1 vraiment critiques pour focus, créer tasks complexes avec disciplines, puis exécuter convergence avec 3 teams parallèles (GStack + Superpowers + Adversarial) et test-e2e per system jusqu'à validation.

### Approche évoluée pendant la session
- **Phase 1** : Plan ultra-focus 7 zones (`plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md`) — TIer 0 légal NF525 → TIer 3 admin daily
- **Phase 2** : Wave 1 audit (11 agents parallel, 6 done + 5 rate-limited)
- **Phase 3-7** : Cycles wave-by-wave Wave 2 → Wave 3 → Wave 2b → Wave 3b → Wave 2c → Wave 3c (séquentiel)
- **OWNER COURSE-CORRECTION** : « Pourquoi wave-by-wave ? Je veux du parallèle MASSIF avec sub-agents bien éduqués (GStack + Superpowers + Adversarial) qui tournent toutes les tâches en parallèle, jusqu'au test-e2e réel »
- **Phase finale** : 7 zone orchestrators parallèles single message — chacun pipeline interne complet (heal + adversarial RED + Playwright E2E + visual + loop) → 7/7 GO V1 LOCAL

### Résultats finaux 7 zones

| Zone | Verdict | Heals | Tests |
|---|---|---|---|
| 🟥 Z1 NF525 Fiscal | **GO** 1 cycle | 5 commits (verify-chain loop errors + activeBranchIds + --branch=0/--all) | 166/166 Fiscal + Playwright |
| 🟧 Z2 POS Caisse | **GO V1 LOCAL** | 0 new (déjà convergé) | E2E 10/10 + fiscal_seq 354 monotonic |
| 🟧 Z3 KDS+Kiosk | **GO** 1 cycle | 4 commits (TZ-aware Dashboard/Order/OSS/Avail/Cron + cadence cap PosSync/OssSync) | 14 PHP + 20 JS sentinels + 3/3 E2E |
| 🟨 Z4 Auth + TrustHosts | **GREEN** 2 cycles | 2 commits (anchor regex + IPv6 bracket form) | 5/5 + 6/6 + 27 attack patterns rejected |
| 🟧 Z5 Pricing SSOT | **GO** 0 code | Sentinels uniquement | 6/6 + 5/5 E2E cross-surface |
| 🟨 Z6 Sync Outbox | **GO** 1 cycle | 1 commit (lock TTL 300s + batch 500 cap) | 44/44 + 8/8 E2E |
| 🟩 Z7 Admin Daily | **GO V1 LOCAL** | 0 new | E2E 9/9 + EnsureUserStatusActive proven |

### Adversarial RED catch critique
Wave 2c heal initiale `e54368bde` (TrustHosts whitelist) avait introduit un **P0 production-exploitable** : Symfony wrap les strings comme regex unanchored `{%s}i` → `attacker-localhost.com` matche `{localhost}i` → spoof bypass. Wave 3c adversarial l'a caught. Heal Zone 4 commit `b1c50311d` + cycle 2 catch IPv6 bracket form → commit `9269f9830`. **Valeur prouvée du pattern multi-team Adversarial : ne laisse rien passer**.

### Commits significatifs cette session

```
1e7c65ecc fix(mgmt-heal-2026-05-18 M-R3-P0-C): APP_DEBUG production boot guard
139ce01aa fix(sync-heal-2026-05-18 S-R3-P0-G+H): Pusher channel-auth wildcard + Guest-Echo-Bypass
65f59e82f fix(sync-heal-2026-05-18 S-P0-A): write ws:heartbeat after successful broadcast
d397511a5 docs(zone3): CONVERGENCE_FINAL — Wave 3c GO + V1.0.2 backlog
f840c3ef5 fix(central-heal-2026-05-18 CVP0-1): NF525 fiscal-table TRUNCATE/DROP REVOKE Ansible
72e45fe59 test(zone3): E2E kiosk→KDS chronological + TZ smoke
f225e63b5 fix(sync-heal-2026-05-18 S-P0-J): add webhook_events.order_id FK to orders
935eaca25 fix(mgmt-heal-2026-05-18): close 3 RBAC privilege-escalation paths
00b9651a3 fix(web-z7): close 4 P1 coverage gaps + 2 axe P0 + 2 ARIA P2
8365a0ea5 fix(sync): cadence upper cap 60s on PosSync + OssSync (Wave 3c P1)
4905138fa fix(kds+admin): TZ-aware boundaries Dashboard/OrderService/OSS/Avail/Cron
c07acb16a fix(fiscal): --branch=0 rejected + --all sweep flag
7da06d641 fix(fiscal): activeBranchIds() honors Status::ACTIVE drift
fe595a4d6 fix(outbox): bump lock TTL 300s + batch cap 500
9269f9830 fix(security): TrustHosts IPv6 loopback must whitelist [::1] bracket form
7eeb8a04b fix(fiscal): loop all z_reports errors in verify-chain output
b1c50311d fix(security): TrustHosts anchor regex CRITICAL P0
148dbebce fix(kds): TZ-aware boundaries in KdsSyncService (Wave 3 P0)
79e214542 fix(security): TrustProxies $proxies='*' enables per-IP throttle
335b98134 fix(fiscal): verify-chain branch validation + distinct exit codes + daily cron
a1dd60f56 fix(kds): clamp polling cadence floor 250ms PHP + JS
e264be951 fix(outbox): write-then-dispatch ordering + batch continuity
c2613cab0 fix(kds+oss): TZ-aware boundaries in KitchenDisplay + OSS services (Wave 3b P0)
0f49258dd fix(fiscal): verify-chain covers z_reports + cron iterates all active branches
181abdef4 test(kds): KdsSyncService whereBetween sargable sentinel (Wave 1 P1)
048c48439 feat(fiscal): fiscal:verify-chain artisan command (Wave 1 P1)
5df225ffa fix(pos): cash drawer session owner-or-manager close (Wave 1 P1)
8dc6ec331 fix(outbox): audit_logs trail on manual DLQ replay (Wave 1 P1)
4a60a06da fix(outbox): Cache::lock concurrent retry guard (Wave 3b P1)
e54368bde fix(security): TrustHosts whitelist defense vs Host spoof (Wave 3b P1)
... + ~15 commits parallèles de missions concurrent (other Claude sessions or 06:40 routine)
```

### Deliverables cette session

- `plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md` — Plan focus 7 zones avec disciplines
- `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md` — 3 P1 cash drawer owner gate (proposé C/C/C)
- `reports/test-e2e/critical-focus-2026-05-18/MASTER_CONVERGENCE_FINAL.md` — Verdict global 7 zones
- `reports/test-e2e/critical-focus-2026-05-18/zone-{1..7}-*/CONVERGENCE_FINAL.md` — 7 rapports zone
- `reports/audit/critical-focus-2026-05-18/wave-{1,3,3b,3c}/` — Audits adversariaux multi-cycles
- `tests/e2e/zone{1..7}-*.spec.js` — 7 Playwright specs zones
- ~50+ PNG screenshots analysées via Read

### Routine programmée (en attente firing)
- **ID** : `trig_01TJYdaT5jAPZ9ZCcgScsbSL`
- **Trigger** : `2026-05-18T04:40:00Z` (06:40 Paris) — one-shot, auto-disable après firing
- **URL** : https://claude.ai/code/routines/trig_01TJYdaT5jAPZ9ZCcgScsbSL
- **Scope** : 5 systèmes (POS/Kiosk/KDS/Sync/Admin) autonomous local audit + E2E
- **Note** : si la routine fire après cette session, son travail sera complémentaire (verification pass) — pas duplicate puisque le HEAD aura avancé via les commits parallèles.

---

## §2 — Owner Mandate (verbatim, immuable session-after-session)

### Règles ABSOLUES
1. **NO cloud talk** (verbatim owner 2026-05-18) :
   > « C'est moi qui initierai cette demande, jamais répétée. Pour l'instant, on est encore sur le local, jusqu'à ce que le système soit validé, fonctionnel, bien structuré et robuste. C'est moi qui initierai cette validation quand je verrai tout prêt et bien fait. »

   - Ne PAS proposer cloud actions (AWS rotation, OVH VPS, Phase D, Ansible deploy, S3, Certbot, DR drill, production day flip)
   - Archive cloud comme "vision avant production" backlog
   - Mémoire pointeur : `memory/feedback_no_cloud_until_owner_initiates.md`

2. **Massive parallel triple-team orchestration** (verbatim owner 2026-05-18) :
   > « Ce n'est pas par un agent et pas par une opinion. On a la team Gistak, la team Superpowers, et la team surtout Adversers, qui ne laissera rien passer. »

   - 3 teams parallèles obligatoires : GStack (implementer) + Superpowers (parallel reviewer) + Adversarial RED (dispute multi-opinion direct/indirect/caché/visible/security)
   - Pas d'agent unique. Pas d'opinion unique. Cross-validation 2+ agents pour promouvoir P0/P1.
   - Mémoire pointeur : `memory/feedback_massive_team_orchestration_e2e_per_system.md`

3. **test-e2e per system** (verbatim owner 2026-05-18) :
   > « On va faire un test massif avec le skill test E2E, que tu vas déployer pour chaque système. His E2E test, qui va faire le vrai chemin sur le web... du premier page au dernier page. Avec la capture de l'écran et l'analyse de la capture. »

   - Page-by-page chronologique
   - REAL Playwright + visual capture
   - Read PNG via Read tool + analyse multimodale
   - Loop correction jusqu'à validation
   - "Don't turn me back" — pas de retour prématuré

### Discipline héritée (CLAUDE.md §5 LOOP + §7 frozen + §8 NF525)
- **Frozen zones** 13 fichiers (`memory/reference_frozen_zones.md`) — ZÉRO touch sauf LOCK plan owner-gated
- **NF525 invariants** : chain HMAC + composition_snapshot immutable + fiscal_sequence monotonic + 6y retention + DELETE/UPDATE triggers
- **Multi-tenant** : BranchScope sur 17 models, Sanctum kiosk:order strict scope
- **Pricing SSOT** : 100% backend via PricingService::calculateOrder
- **Visual mandate** : Playwright capture + Read PNG + analyse sur toute UI touchée
- **No push to remote** sans owner gate
- **No --no-verify** : pre-commit hooks must pass

---

## §3 — Sessions antérieures (chronologique, contexte)

### Récentes (mai 2026, dernières 2 semaines)

**2026-05-18 (HUI — cette session)** — Critical Focus 7-zone convergence parallèle
- 7/7 zones GO V1 LOCAL
- 30+ commits scope-minimal
- P0 TrustHosts caught par adversarial
- Voir §1 ci-dessus

**2026-05-17→18** — V1 Cloud-Prep + Insights heal Round 1
- Wave 5D-5I (9 commits) + 6-agent RED-team insights audit
- ~9 P0 verified (recalibration vs 13 owner-claim)
- POS_SIMULATION_HARDWARE production boot guard + Stripe cents fix + POS offline URL
- NF525 chain bit-identical
- Mémoire : `project_v1_cloud_prep_2026-05-17.md`

**2026-05-18 GOAL Production Readiness mission**
- 10 audit sub-agents Round 1 + 8 fix implementers Round 2 + 10 RED+visual Round 3
- 13+ P0 closed (POS×4 + OSS chime + Livreur×3 + Mobile fictional×5 + idempotency + web legal)
- Mission ~95% complete (visual reports finalisation + Owner gates pending)
- Tag `v1.0.2-rc1-2026-05-18` au HEAD `6908edbde`
- Mémoire : `project_goal_production_readiness_2026-05-18.md`

**2026-05-17** — V1.0.1 Hardening Cycle 6 sprints H1-H6
- 30/30 backlog items closed (4 deferred V1.0.2)
- 4 Owner Gates G1-G4 résolus
- 914/914 final smoke + 27 POS test debt fixed via H6 trait
- Mémoire : `project_v1_0_1_hardening_2026-05-17.md`

**2026-05-17** — Massive Logic + Image Cycle
- 5 parallel sub-agents + 5 P0 logic heals + 4 owner photos
- 69/69 E2E + 100% mobile↔web parity
- Mémoire : `project_massive_logic_image_cycle_2026-05-17.md`

**2026-05-17** — GOAL Long-term EXECUTED 2 frontends Le Cayenne
- Mobile (foodking-web/web/testttt/mobile/) + Web (Downloads/web)
- 8 waves W0→W8 ~2h30
- 44/44 E2E GREEN (12 mobile + 32 web × 4 viewports)
- Mémoire : `project_goal_longterm_executed_2026-05-17.md`

**2026-05-16** — Wave Z 10-System Parallel Convergence
- 10 sub-agents parallel Z1-Z10
- 7 P0 NEW healed + 14 P1 healed (4 sprints 5A-5D)
- V1 Le Cayenne SHIPPABLE
- Mémoire : `project_wave_z_convergence_2026-05-16.md`

**2026-05-16** — CTO Global Audit
- 8 parallel sub-agents (GStack+Superpowers+RED)
- V1 single-resto 45/100 conditional 4-6 sem hardening
- Mémoire : `project_cto_audit_global_2026-05-16.md`

**2026-05-16** — Mobile Realignment EXECUTED
- 12/12 E2E green + 0 frozen-zone touch
- composer_profile hardcoded mirror DB pour wireup futur
- Mémoire : `project_mobile_realignment_ultraplan_2026-05-16.md`

**2026-05-16** — GOAL LONG-TERM 2 frontends plan (avant exécution)
- 18 pages mobile + 23 pages web × 9 axes / 8 waves
- Mémoire : `project_goal_longterm_frontends_2026-05-16.md`

**2026-05-16** — SpinBoost ultra-review (projet séparé SaaS gamification avis)
- Group Graphiti `spinboost`
- Mémoire : `project_spinboost_ultra_review_2026-05-16.md`

**2026-05-18 (avant cette session)** — POS payment 4-scenarios + POS first-page filter
- Composition #N "n'appartient pas" root cause = wizard profile data alignment, not stale IDs
- 25/25 cumulative POS tests stable
- Mémoire : `project_pos_payment_fix_2026-05-18.md` + `project_pos_first_page_oss_filter_2026-05-18.md`

**2026-05-18** — Max Audit + Test-E2E Skill Convergence
- 4 deep-audit sub-agents + test-e2e skill 2-round convergence
- 69/69 GREEN × 2 consecutive rounds
- Mémoire : `project_max_audit_test_e2e_convergence_2026-05-18.md`

### Historique (avril-mai 2026)
- 2026-05-14 : Menu Heal-light V2 17 images + bowl 3-step
- 2026-05-13 : Menu Reset Le Cayenne 11 catégories 41 items
- 2026-05-11 : POS parallel 20-agent audit (verdict NO-GO maintenu)
- 2026-05-11 : KDS Ultra-Plan Integration
- 2026-05-09 : Recovery DB identity + ultra audit POS NO-GO V1
- 2026-05-08 : Route audit JS↔backend + Ultra Review v2 closed 17/17
- 2026-05-07 : Audit Ultra Review POS+Kiosk 14 findings
- 2026-05-02 : CV1 Foundations

---

## §4 — Snapshot état critique

### Branches
- Active V1 : `v1-0-1-hardening-2026-05-17` (HEAD `1e7c65ecc`)
- Backup pre-GOAL : `backup/pre-goal-2026-05-18` (HEAD `8966881aa`)
- Backup V1.0.1 : `backup/pre-v1-0-1-hardening-2026-05-17`
- Mobile concurrent : `feature/mobile-app-le-cayenne-2026-05-10` (HEAD `56204f052`)

### Tags
- `v1.0.2-rc1-2026-05-18` (post mission GOAL)
- `pre-goal-2026-05-18`
- `pre-v1-0-1-2026-05-17`
- `pre-menu-reset-2026-05-13`

### NF525 Chain
- `php artisan fiscal:verify-chain --branch=1` → `CHAIN OK (audit_logs + z_reports) (branch=1)`
- Baseline `audit_logs` count=26+ (croissant), last_hash extends

### Tests
- PHPUnit Feature : ~800 GREEN cumul (varies per zone)
- Vitest sentinels : ~50 GREEN
- Playwright E2E : 7 zone specs GREEN (zone1-fiscal, zone2-pos-chronological, zone3-kiosk-to-kds, zone4-auth-cross-branch, zone5-pricing-ssot, zone6-sync-resilience, zone7-admin-daily)

### Frozen zones (13 fichiers, 0 diff vs baseline)
1. `public/js/pos-wizard.js`
2. `public/css/pos-wizard.css`
3. `resources/views/admin-pos-v4.blade.php`
4. `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
5. `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
6. `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
7. `app/Services/Fiscal/FiscalSequenceService.php`
8. `app/Services/Fiscal/AuditLogService.php`
9. `app/Services/Fiscal/ZReportService.php`
10. `app/Models/Scopes/BranchScope.php`
11. `app/Http/Middleware/IdempotencyKeyMiddleware.php`
12. `app/Services/Pricing/PricingService.php`
13. `app/Domain/Order/OrderStateMachine.php`

---

## §5 — V1.0.2 Backlog (owner-gated, post-go-production-initiation)

### Sécurité / Auth
- FormRequest authz 83 endpoints restants (5 fait Wave 5H)
- Sanctum TTL 8h → 1h sensitive ops
- EmployeeController::destroy no FormRequest
- AUTHZ-E2E-STRENGTHEN (staff-actor fixtures)
- Z6-02 guest [*] ability scope
- POS XSS LOCK pos-wizard.js (Wave 5G LOCK plan `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md`)

### Fiscal monitoring
- FISCAL-ADV3B-04 alerting mail/SMS/SIEM (file-log only currently)
- FISCAL-ADV3B-05 catch-Throwable lanes (split Error vs Exception)
- FISCAL-ADV3B-06 withoutOverlapping window (1440min default)
- FISCAL-ADV3B-07 anon test class fragility
- FISCAL-ADV3C-04 audit/z verify decoupling

### KDS / Kiosk
- KDS-ADV3C-05 DST-axis test gap
- KDS-ADV3C-06 SQLite/MySQL CI gap
- KDS-ADV3C-09 KDS SLO comment-vs-code
- KDS-ADV3C-10 zero-jitter thundering herd
- KDS-ADV3C-11 runtime cadence config refresh
- KDS-ADV3C-12 DashboardService whereTime Paris-local on UTC TIMESTAMP

### Sync
- **SYNC-ADV4-N1 (P1 NEW)** : Stripe webhook CSRF except pattern mismatch `payment/stripe-webhook/*` ≠ route `payment/stripe-webhook` (1 LOC fix)
- SYNC-ADV3C-05/06/07
- **Z7-V1.0.2-P2-01 (P2 NEW)** : BranchStatusChanged NOT persisted in domain_events (asymmetric outbox persist, ~30 LOC)

### Pricing (frozen-zone, LOCK plans documented)
- W2 composition_snapshot in `fillable` without `updating` guard
- W5 No DB BEFORE UPDATE trigger on order_items.composition_snapshot

### Owner-decision pending
- **POS-ADV3-05/06/07** : 3 P1 cash drawer design composition — proposé C/C/C accept-as-is dans `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md`
- **POS XSS LOCK** : `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` — frozen-zone heal request awaiting owner countersign

---

## §6 — Insights cross-session (généré 2026-05-18 via /insights)

### Stats globales
- **206 sessions total** (50 analysées)
- **263 hours**, **514 messages**, **89 commits**
- **Date range** : 2026-04-25 → 2026-05-18

### Project areas (top 5 effort)
1. **POS/Kiosk/KDS System Audits** : 18 sessions
2. **Mobile App Development & Realignment** : 10 sessions
3. **E2E Testing & Visual Validation** : 9 sessions
4. **CI/CD, Security Audits & PR Workflow** : 8 sessions
5. **Pregnancy App** (projet séparé) : 5 sessions

### Pattern principal
> « Multi-agent execution platform. Wave-based methodology. 'Carte-blanche execution with hostile review checkpoints'. Course-correct hard when Claude drifts. Memory-backed session recovery. 82% likely-satisfied rate. »

### Ce qui marche (validé multi-session)
1. **Parallel Multi-Agent Audit Orchestration** — 5-20 sub-agents parallèles avec P0/P1/P2 verdicts GO/NO-GO
2. **Convergence-Driven E2E Validation** — push to GREEN convergence avec headed Playwright + adversarial scoring
3. **Memory-Backed Session Recovery** — Graphiti MCP + BRAIN.md = checkpoints, pas restarts

### Friction (à éviter / corriger)
1. **Buggy first-pass implementations** (29 instances) — Playwright specs, schemas, migrations. → Mitiger : require verification step (Read each cited file) avant declarer done.
2. **Wrong initial approach** (16 instances) — Claude invente fictional products / wrong palette / custom skills. → Mitiger : front-load constraints (frozen zones, SSOT files, real menu sources) en opening prompt.
3. **Long ultra-audit hits limits** — sessions 10-20 sub-agents hit usage / token / context limits. → Mitiger : checkpointed phases, commit incrémentalement, write resume-state.json.

### Recommandations actionnables
- **Codify audit methodology** comme `/ultra-audit` custom skill (recommandation #1)
- **Verify findings before reporting** — Read chaque file:line cité, drop unverified
- **Long sessions checkpoint commits** — message structuré `[WAVE-N] <summary>` + `.claude/mission-state.md`

### Bug fameux mentionné par insights
> « Claude accidentally committed live AWS keys, then quietly untracked them. During an autonomous YC-style audit of a pregnancy app, Claude was asked to just execute (not ask questions) — and in its eagerness committed a .env file containing live AWS credentials before catching itself and untracking it. »

→ Justifie owner mandate "AWS rotation owner-physique only" actuel.

### Suggestions claude_md_additions (depuis insights)
- `## Audit & Review Workflow` (audit cited file verify + GO/NO-GO + parallel sub-agents par dimension)
- `## Data Source of Truth` (FoodKing menu.php SSOT, mobile frozen zones)
- `## Environment Safety` (no composer dump-autoload running server, no .env commit, pin PHP 8.4)
- `## Execution Mode` (autonomous / continue / carte-blanche = exec without clarify)

---

## §7 — Mémoire user-level (pointeurs critiques)

Chemin : `/Users/1millnonstop/.claude/projects/-Users-1millnonstop-Downloads-projet-foodking-web-web-testttt/memory/`

### Feedback rules (immuables, lus tous les session start)
- `feedback_no_cloud_until_owner_initiates.md` ⚠️ CRITIQUE
- `feedback_massive_team_orchestration_e2e_per_system.md` ⚠️ CRITIQUE
- `feedback_wizard_popup_pos_protected.md`
- `feedback_kiosk_wizard_not_protected.md`
- `feedback_adversarial_audit_pattern.md`
- `feedback_gstack_pipeline_methodology.md`
- `feedback_orchestrator_inline_edit_exception.md`
- `feedback_design_flat_organized.md`
- `feedback_kds_modern_research_required.md`
- `feedback_v1_focus_no_saas_2026-05-08.md`
- `feedback_silent_html_masquerade.md`
- `feedback_pos_simulation_hardware_pattern.md`
- `feedback_usage_insights_2026-05-11.md`

### Reference
- `reference_graphiti.md` (group_id=foodking)
- `reference_frozen_zones.md` (13 files protégés)
- `reference_admin_e2e_creds.md` (admin@lecayenne.fr / 123456)
- `reference_superpower_gstack_skill.md`
- `reference_ultra_skills_2026-05-18.md`

### Project (chronologique récent)
- `project_ultra_plan_critical_focus_2026-05-18.md` (cette session)
- `project_goal_production_readiness_2026-05-18.md`
- `project_v1_cloud_prep_2026-05-17.md`
- `project_v1_0_1_hardening_2026-05-17.md`
- `project_massive_logic_image_cycle_2026-05-17.md`
- `project_goal_longterm_executed_2026-05-17.md`
- `project_wave_z_convergence_2026-05-16.md`
- `project_cto_audit_global_2026-05-16.md`
- (... full list dans `MEMORY.md` index)

### Index
`MEMORY.md` — toujours auto-chargé en session start, ~150 lignes index liens

---

## §8 — Graphiti (memory persistante cross-session)

### Group ID
`foodking` — knowledge graph principal pour ce projet

### Outils MCP disponibles
- `mcp__graphiti__search_nodes` — recherche entités
- `mcp__graphiti__search_memory_facts` — recherche faits/relations
- `mcp__graphiti__add_memory` — push épisode
- `mcp__graphiti__get_status` — health check

### Sessions importantes pushées en épisodes
- `Massive Parallel Convergence 2026-05-18` (cette session)
- `V1 Critical Focus Ultra-Plan 2026-05-18`
- (... épisodes antérieurs)

### Pattern
> Read Graphiti pour tâches significatives (search_nodes / search_facts). Push épisode pour decisions / iterations / verifications.

---

## §9 — Bootstrap checklist prochaine session

### Étape 1 — Read (ordre)
1. `CLAUDE.md` (auto-chargé)
2. `~/.claude/projects/.../memory/MEMORY.md` (auto-chargé)
3. `PROJECT_BRAIN.md` §1 NORTH STAR + §2 CURRENT STATE + §3 LAST DONE
4. `reports/sessions/SESSION_HANDOFF_2026-05-18_FULL.md` (ce document)
5. `reports/test-e2e/critical-focus-2026-05-18/MASTER_CONVERGENCE_FINAL.md`
6. `plans/ULTRA_PLAN_V1_CRITICAL_FOCUS_2026-05-18.md` (référence focus zones)
7. `plans/OWNER_DECISION_POS_ADV3_2026-05-18.md` (owner-gate pending)

### Étape 2 — Smoke check
```bash
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
git status
git log --oneline 6908edbde..HEAD | head -30
php artisan fiscal:verify-chain --branch=1   # CHAIN OK expected
git diff --stat 6908edbde..HEAD -- public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php 'resources/js/components/frontend/kiosk/KioskWizardComponent.vue' 'resources/js/components/frontend/kiosk/KioskAppComponent.vue' 'resources/js/components/frontend/kiosk/KioskUpsellComponent.vue' app/Services/Fiscal/FiscalSequenceService.php app/Services/Fiscal/AuditLogService.php app/Services/Fiscal/ZReportService.php app/Models/Scopes/BranchScope.php app/Http/Middleware/IdempotencyKeyMiddleware.php app/Services/Pricing/PricingService.php app/Domain/Order/OrderStateMachine.php
# Expected: empty (frozen-zone diff = 0)
```

### Étape 3 — Graphiti context (si tâche significative)
```
mcp__graphiti__search_nodes(query="FoodKing V1 critical state", group_ids=["foodking"], max_nodes=10)
mcp__graphiti__search_memory_facts(query="V1.0.2 backlog owner gate", group_ids=["foodking"], max_facts=15)
```

### Étape 4 — Quoi faire (selon ce qu'owner demande)
- Si owner demande **bug fix scope-minimal** → STOP checklist + inline edit ≤30 LOC OR Implementer subagent
- Si owner demande **nouvelle feature V1.0.2** → vérifier backlog §5, owner gate si frozen-zone touch
- Si owner demande **convergence V1 robust** → pattern massive parallel 7 zones (déjà éprouvé)
- Si owner dit **"go production"** → ALORS seulement déclencher cloud playbook (sinon refuser scope)
- Si owner demande **status / where are we** → résumer §0 + §1 + §4

### Étape 5 — Update mémoire en fin de session
- `PROJECT_BRAIN.md` §2 §3 (auto via skill LOOP)
- Push Graphiti épisode si significatif
- Update `MEMORY.md` user-level si pattern réutilisable

---

## §10 — Anti-drift garde-fous

### À NE PAS faire pour V1 (drift documenté V1.0.2)
- ❌ Refactor 88 FormRequest authz (5 fait, suffit pour V1)
- ❌ Sanctum TTL 8h → 1h sensitive (V1.0.1 backlog)
- ❌ API key versioning
- ❌ Composer upgrade 12 advisories restantes (Wave 5H closed 5 critical)
- ❌ Auto-dispatch livreur DEL-9
- ❌ KDS V2 layout polish (Wave 5G owner-gate Deprecate)
- ❌ Saga pattern Order+Payment+Stock
- ❌ Cloud / AWS / VPS / Phase D / Ansible / Production deploy

### À FAIRE quand owner demande
- ✅ Heal P0/P1 verified findings scope-minimal
- ✅ Visual mandate sur UI touchée
- ✅ Adversarial RED dispute post-commit
- ✅ Frozen-zone discipline ABSOLUTE
- ✅ NF525 invariants protection
- ✅ Memory + Graphiti update

---

## §11 — Routine 06:40 statut

**Routine ID** : `trig_01TJYdaT5jAPZ9ZCcgScsbSL`
**Trigger** : `2026-05-18T04:40:00Z` (06:40 Paris)
**Type** : one-shot (auto-disable post-firing)
**Scope** : 5 systèmes autonomous local audit + heal + E2E
**Status** : programmée, fera fire à 06:40 si pas déjà passé

Si la routine a fired :
- Voir `https://claude.ai/code/routines/trig_01TJYdaT5jAPZ9ZCcgScsbSL` pour résultats
- Sa branche : `v1-0-1-hardening-2026-05-17` (même branche, donc commits cumulés)
- Note : `ended_reason: run_once_fired` indique fired

---

## §12 — Owner-physique actions restantes (HORS Claude scope)

Pour info — Claude ne touche pas ces items, owner les gère manuellement quand il veut :

- AWS rotation (carryover commit `a4a88df06` ultra-goal 2026-05-13)
- POS XSS LOCK plan countersign
- OVH VPS-1 setup (Phase D, BACKLOG)
- DR drill (BACKLOG)
- Certbot --nginx SSL (BACKLOG)
- Branch obsolètes UPDATE status=5 (low priority)
- Stripe webhook secret production set (BACKLOG)

**Tous BACKLOG — pas urgent V1 local.**

---

## §13 — Code blocs critiques à connaître

### Production guard POS_SIMULATION_HARDWARE (AppServiceProvider:85-91)
```php
if (app()->environment('production') && config('pos.simulation_hardware')) {
    throw new \RuntimeException('POS_SIMULATION_HARDWARE must be false in production');
}
```

### NF525 verify-chain CLI
```bash
php artisan fiscal:verify-chain --branch=1     # single branch
php artisan fiscal:verify-chain --all          # all active branches sweep
php artisan fiscal:verify-chain --branch=0     # exit 2 (use --all instead)
```

### KDS+OSS TZ-aware pattern (Wave 2b/2c référence)
```php
$appTz = config('app.timezone');
$start = Carbon::today($appTz)->setTimezone('UTC');
$end = Carbon::today($appTz)->endOfDay()->setTimezone('UTC');
// ->whereBetween('order_datetime', [$start, $end])
```

### TrustHosts anchored regex (Wave 3c heal critical P0)
```php
return [
    $this->allSubdomainsOfApplicationUrl(),
    '^127\.0\.0\.1$',
    '^localhost$',
    '^\[::1\]$',      // IPv6 bracketed form (Symfony port-strip preserves)
    '^0\.0\.0\.0$',
];
```

---

## §14 — Si tu reprends le projet froid

**Dis simplement ça à l'owner** :

> « J'ai lu CLAUDE.md + PROJECT_BRAIN.md + SESSION_HANDOFF_2026-05-18. État actuel : V1 Le Cayenne local 7/7 zones GO post convergence massive parallèle 2026-05-18 (40+ commits, frozen-zone 0 diff, NF525 CHAIN OK). Owner mandate respecté : NO cloud talk, focus local. Quel est ton prochain objectif ? »

Et écoute la réponse. Tu as tout le contexte nécessaire.

---

*Handoff généré 2026-05-18 par Claude (orchestrateur Critical Focus session). Branche `v1-0-1-hardening-2026-05-17`. NF525 CHAIN OK. 7/7 zones convergent V1 LOCAL.*
