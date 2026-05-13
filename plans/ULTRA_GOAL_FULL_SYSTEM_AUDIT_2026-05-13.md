# ULTRA GOAL — Full System Audit + Heal + Massive E2E

**Date** : 2026-05-13
**Author orchestration** : Claude Code (orchestrator mode)
**Owner** : Sorrow / Le Cayenne
**Branche cible** : `feature/mobile-app-le-cayenne-2026-05-10`
**Target invocation** : `/goal plans/ULTRA_GOAL_FULL_SYSTEM_AUDIT_2026-05-13.md`
**Estimation** : ~25-40h agent-hours, 15-25 wall-clock heures (avec sub-agents parallèles)
**Status** : READY TO LAUNCH

---

## §0. TL;DR — Contract de mission

Claude est invoqué en mode `/goal` pour exécuter un **audit profond isolant** sur 11 axes orthogonaux, suivi d'un **heal cycle** par axe, puis d'une **convergence cross-axis**, et enfin d'un **test E2E massif** (50 commandes réelles avec captures visuelles + adversarial supervisor) **jusqu'à atteindre GREEN** sur 100% des acceptance criteria de §18.

**Pas d'arrêt avant convergence**. Maximum 5 cycles de healing par axe. Si convergence impossible, escalation explicite owner (§20).

**Discipline absolue** :
- CLAUDE.md §1-§16 applicable intégralement
- §5 LOOP 8 étapes pour CHAQUE phase
- §7 frozen-zones intouchables sans LOCK plan
- §8 NF525 invariants non-négociables
- §10 Decision Framework (continue/heal/block/escalate)
- §11 Memory discipline (BRAIN + Graphiti à chaque phase)
- §13 Evidence rules (file:line, captures Read+analyzed, JSON verdict)

**Final delivery** : 1 rapport `reports/audit/ultra-goal-2026-05-13/FINAL_VERDICT.md` + 11 rapports axe + N rapports E2E + Graphiti épisode + commit final.

---

## §1. NORTH STAR — Mission immuable

Le projet FoodKing en branche `feature/mobile-app-le-cayenne-2026-05-10` est en état post-menu-reset 2026-05-13 (9 catégories actives, composer profiles bols/frites, sandwich-split disabled). De multiples backlogs résiduels existent (cf. §17). L'objectif de ce GOAL est de :

1. **Cartographier exhaustivement** l'état actuel sur 11 axes (audit isolant)
2. **Identifier 100% des defects** P0/P1/P2/P3 avec evidence file:line vérifié
3. **Fixer 100% des P0**, **≥80% des P1**, **discuter P2/P3** avec owner-gate
4. **Garantir frozen-zones intactes** (zero ligne diff sur les fichiers protégés)
5. **Préserver NF525, BranchScope, PricingService, OrderStateMachine** sans régression
6. **Valider visuellement et techniquement** TOUS les surfaces (kiosk + POS + KDS + OSS + Admin + Mobile)
7. **Stresser le système** avec 50 commandes E2E réelles + sync + fiscal + KDS + OSS bump
8. **Livrer un système production-ready** pour la marche du restaurant Le Cayenne

**Le but n'est PAS la vitesse. Le but est correctness, coherence, reliability, quality.**

---

## §2. SCOPE — 11 axes d'audit isolants

Chaque axe est **independant** (peut être audité en parallèle par sub-agent dédié), avec un **contrat de findings** uniforme (§10).

| # | Axe | Sub-agent | Inputs | Critical zones |
|---|-----|-----------|--------|----------------|
| **A1** | **Database & Schema** | DBA | migrations + models + FK + indexes + triggers + soft-deletes | item_categories, items, order_items, audit_logs, z_reports, fiscal_sequence |
| **A2** | **Backend Services** | Architect | app/Services/, app/Domain/, contracts, interfaces | PricingService, AvailabilityService, StockService, FiscalServices |
| **A3** | **Sync / Outbox / Pusher** | SRE | app/Events/, app/Listeners/, app/Jobs/Outbox, Pusher config | CatalogChanged, ItemAvailabilityChanged, PersistCatalogChangedToOutbox |
| **A4** | **POS Vanilla Wizard (frozen)** | Architect (read-only audit) | public/js/pos-wizard.js, admin-pos-v4.blade.php | Frozen — read-only verify, no code touch |
| **A5** | **POS Vue Admin** | Architect + UX | resources/js/components/admin/pos/, posComponent.vue | POS V4 entry, sidebar cats, item add wizard binding |
| **A6** | **Kiosk Vue Wizard (frozen)** | A11y + UX (read-only) | resources/js/components/frontend/kiosk/*.vue | Frozen — Wizard + App + Upsell + Categories |
| **A7** | **KDS Display + Routing** | Tester + UX | resources/js/components/admin/kitchenDisplaySystem/, KDS API | KdsV2Grid, KdsOrderCard, station routing |
| **A8** | **OSS Display** | UX | resources/js/components/admin/orderStatusScreen/ | OSS read-only display, popular items |
| **A9** | **Admin CRUD** | Architect + Security | resources/js/components/admin/settings/, items/, orders/ | Categories CRUD, Items CRUD, Stock dashboard, Fiscal Z |
| **A10** | **Mobile App** | UX + A11y | mobile/data/menu.js, mobile/screens-*.jsx, mobile/index.html | 9-cat alignment, composer profile mirror, assets wired |
| **A11** | **Cross-Surface E2E + NF525** | Tester + Adversarial | tests/e2e/, end-to-end flows, fiscal chain | Order kiosk → DB → KDS → OSS → reprint, Z-close, audit chain |

**Règles d'isolation** :
- Aucun axe ne modifie de fichiers hors de son scope sans LOCK + owner gate
- Les findings cross-axis (ex : DB + Sync) sont reportés à **A12 cross-reconciliation**
- Les frozen-zones sont audited READ-ONLY (axes A4, A6). Si fix requis sur frozen → LOCK plan obligatoire

---

## §3. OPERATING DOCTRINE — Comment Claude doit travailler

### 3.1 Single-agent + sub-agents YC GStack

Claude principal **orchestre** ET **exécute** (single-session). Pour chaque axe : spawn 1-3 sub-agents Explore en parallèle (read-only) pour l'audit. Synthèse + fix par Claude principal en ETAPE 7 healing.

### 3.2 Adversarial à chaque étape

À la fin de chaque audit-axe + à la fin de chaque heal cycle, **spawn 1 adversarial sub-agent** (general-purpose) avec mission :
- Challenger les findings du primary agent
- Cross-valider file:line evidence
- Détecter hallucinations (fake P0s, faux fichiers, etc.)
- Output JSON verdict contract (§10)

### 3.3 Convergence loops

```
For each axis Ai (i = 1..11):
  Loop k = 1..5:
    audit(Ai) → findings_k
    adversarial_review(findings_k) → confirmed_k, hallucinated_k
    heal(confirmed_k.P0 + confirmed_k.P1)
    re-audit(Ai) → findings_k+1
    if findings_k+1 ⊆ findings_k AND |findings_k+1.P0| == 0:
      break (GREEN for Ai)
    elif k == 5:
      escalate(owner, axis=Ai, residual=findings_k+1)
```

### 3.4 Discipline §5 LOOP par phase

À CHAQUE phase :
1. ORCHESTRATE — read BRAIN + Graphiti + relevant docs
2. PLAN — decompose, spawn sub-agents if needed
3. EXECUTE — scope-minimal, frozen-zones respected
4. AUDIT — relit code modifié, side-effects check
5. TEST — PHPUnit filter + Vitest filter + relevant E2E
6. VISUAL — si frontend touché, captures + analyse Read tool
7. SELF-CORRECT — loop max 3 si fail
8. UPDATE BRAIN — section §3 LAST DONE + Graphiti episode

### 3.5 Memory discipline

- BRAIN.md §2 updated après chaque phase (HEAD, status, last done)
- Graphiti episode (`group_id: foodking`) pushé après chaque axe complet
- Plans intermédiaires dans `reports/audit/ultra-goal-2026-05-13/`

### 3.6 Token budget discipline

- Commit + Graphiti push après CHAQUE phase (pas batch)
- Print "Resume token" 1-line après chaque phase pour reprise possible si /goal interrompu
- Préférer Plan tool pour orchestration heavy
- Sub-agents pour parallel reads (économie context principal)

### 3.7 Frozen-zones absolues (CLAUDE.md §7)

Liste exhaustive — ZÉRO ligne diff autorisée sans LOCK plan owner-gated :
- `public/js/pos-wizard.js`
- `public/css/pos-wizard.css`
- `resources/views/admin-pos-v4.blade.php`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/Fiscal/AuditLogService.php`
- `app/Models/Scopes/BranchScope.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `app/Services/Pricing/PricingService.php`
- `app/Domain/Order/OrderStateMachine.php`
- Migrations `audit_logs` + `z_reports` (triggers DELETE forbidden)

**Si modification nécessaire** : (a) LOCK plan written → `plans/LOCK_*.md` (b) owner gate explicite (c) test régression triple-vert post-modification.

### 3.8 NF525 invariants (CLAUDE.md §8)

- Pricing 100% backend via `PricingService::calculateOrder`
- `composition_snapshot` JSON frozen à création — NEVER overwritten
- `fiscal_sequence_no` monotonic per-branch, gap-free
- `audit_logs` HMAC SHA-256 chain-signed (prev_hash → current_hash)
- `z_reports` HMAC chain-signed daily clôture
- DB trigger BEFORE DELETE SIGNAL '45000' (MySQL prod)
- 6 ans rétention obligatoire post-close

**Tout violation = P0 hard block.**

### 3.9 Multi-tenant invariants (CLAUDE.md §9)

- BranchScope sur 13+ models (cf. BRAIN §9)
- Kiosk Sanctum token `kiosk:order` ability only
- Pre-auth lookups `withoutGlobalScope(BranchScope::class)` explicit
- Idempotency middleware sur POST mutating

---

## §4. PHASE 0 — BOOTSTRAP (mandatory, ~30 min)

### 4.1 Pre-flight checks
```bash
# Branch + HEAD
git rev-parse --abbrev-ref HEAD  # must be feature/mobile-app-le-cayenne-2026-05-10
git log --oneline -5

# Workspace clean (no uncommitted hot work)
git status --short

# DB connectivity
mysql -h localhost -u root foodking -e "SELECT COUNT(*) FROM item_categories WHERE deleted_at IS NULL;"
# Expected: 10 (9 visible + 1 hidden cat 315 with channels='[]')

# Dev server reachable
curl -sf http://127.0.0.1:8000/health || curl -sf http://127.0.0.1:8000/kiosk/idle

# PHP + Node toolchain
php --version  # >= 8.2
node --version # >= 18
npm --version
```

### 4.2 Backup baseline
```bash
# Create goal-execution branch backup
git branch backup/pre-ultra-goal-$(date +%Y-%m-%d)

# DB dump
mkdir -p storage/backups/ultra-goal-2026-05-13
mysqldump -h localhost -u root --single-transaction --no-tablespaces foodking > storage/backups/ultra-goal-2026-05-13/foodking-pre-goal.sql

# Frozen-zones diff baseline
git diff main -- public/js/pos-wizard.js public/css/pos-wizard.css resources/views/admin-pos-v4.blade.php resources/js/components/frontend/kiosk/KioskWizardComponent.vue resources/js/components/frontend/kiosk/KioskAppComponent.vue resources/js/components/frontend/kiosk/KioskUpsellComponent.vue app/Services/Fiscal/FiscalSequenceService.php app/Services/Fiscal/ZReportService.php app/Services/Pricing/PricingService.php app/Models/Scopes/BranchScope.php > reports/audit/ultra-goal-2026-05-13/frozen-zones-baseline.diff
# Expected: 0 lines diff (or documented expected diff per BRAIN §2)
```

### 4.3 Baseline visual captures
```bash
mkdir -p reports/audit/ultra-goal-2026-05-13/baseline-visuals/
# Capture each surface BEFORE any audit work, for diff comparison post-heal
# Kiosk idle, kiosk-categories, kiosk-wizard-bols, kiosk-wizard-frites
# POS empty, POS-cat-bols, POS-wizard-bols
# Admin items list, Admin categories list, Admin POS V4
# KDS empty, KDS with-order
# OSS empty, OSS popular
# Mobile :8081 home, mobile menu, mobile wizard
```

### 4.4 Test baseline (technical)
```bash
# Save baseline test pass rate
php artisan test 2>&1 | tee reports/audit/ultra-goal-2026-05-13/phpunit-baseline.log
npm run test:unit 2>&1 | tee reports/audit/ultra-goal-2026-05-13/vitest-baseline.log
npx playwright test tests/e2e/smoke-*.spec.js 2>&1 | tee reports/audit/ultra-goal-2026-05-13/playwright-baseline.log
```

### 4.5 Acceptance criteria Phase 0
- [ ] Backup branch poussée localement
- [ ] DB dump créé + checksum
- [ ] Frozen-zones diff baseline capturé (0 ligne ou diff connu)
- [ ] 12+ captures visuelles baseline read+stored
- [ ] Baseline test logs stockés
- [ ] BRAIN §2 updated avec status "ULTRA GOAL phase 0 — bootstrap complete"
- [ ] Graphiti episode pushed : "Ultra Goal Bootstrap 2026-05-13"

---

## §5. PHASES 1-11 — Per-axis audits

> Pour chaque axe : appliquer le template ci-dessous.

### Template d'audit par axe Ai

#### 5.0 Briefing sub-agent (Explore agent)

```
You are auditing FoodKing axis Ai = "{axis_name}". Codebase root: /Users/.../testttt

Scope: {axis_scope}
Critical zones: {axis_zones}

Mission: find every P0 (blocker), P1 (significant), P2 (quality), P3 (cosmetic) defect.
Output requirements:
1. JSON contract at end of report (see §10)
2. file:line evidence for every claim
3. Severity calibrated (P0 = production breakage, P1 = degraded behavior, P2 = quality, P3 = cosmetic)
4. NO hallucinations — if uncertain, mark confidence LOW
5. Report length ~400-700 lines markdown

Known starting backlog (do not re-discover):
{axis_known_backlog from §17}

Frozen zones in your scope (READ ONLY, never modify):
{axis_frozen_zones from §3.7}

Cross-validation: if a finding touches another axis, mark it CROSS-AXIS and flag for §6 reconciliation.

Begin systematic audit. Cover:
- Architecture coherence
- Security holes (auth, authz, injection, secrets)
- Data integrity (FK, soft-delete, snapshots)
- UX issues (raw labels, empty states, error states, a11y)
- Performance issues (N+1, missing indexes, cache misses)
- Test coverage gaps
- i18n breakage
- Edge cases (empty, error, concurrent, offline)
```

#### 5.1 Audit step (sub-agent runs)

Sub-agent produit `reports/audit/ultra-goal-2026-05-13/axis-Ai-audit-round1.md` + JSON verdict.

#### 5.2 Adversarial review (separate sub-agent)

```
You are an adversarial Red-Team auditor. You receive the primary audit report
from axis Ai. Your job: dispute every finding hostilely.

For each P0/P1 finding, verify:
- file:line cited matches reality (read the file)
- Repro steps actually fail today
- Severity not inflated
- Not a duplicate of another finding
- Not a known intentional design

Output: confirmed_findings[] + disputed_findings[] + hallucinated_findings[]
+ severity_corrections[]
+ JSON verdict.
```

#### 5.3 Heal step (Claude main)

Apply fixes for confirmed P0 + confirmed P1 (≥80% target). Scope-minimal. Frozen-zones respected. Tests after each fix.

#### 5.4 Re-audit step (same sub-agent or fresh)

Re-run audit on healed code. Compare delta findings_k+1 vs findings_k. Convergence check.

#### 5.5 Per-axis Definition of Done

- [ ] 0 P0 confirmé résiduel
- [ ] ≤2 P1 confirmé résiduel (avec owner ack)
- [ ] Tests PHPUnit + Vitest impactés VERTS
- [ ] Frozen-zones diff = 0 ligne (sauf LOCK approved)
- [ ] BRAIN updated
- [ ] Graphiti episode pushed
- [ ] Audit report archived `reports/audit/ultra-goal-2026-05-13/axis-Ai-FINAL.md`

---

### A1 — Database & Schema integrity

**Sub-agent : DBA**
**Inputs** : `database/migrations/`, `app/Models/`, MySQL schema (information_schema), live data sample
**Frozen** : Migrations `audit_logs` + `z_reports` triggers

**Checks à faire** :
1. FK constraints sur 15+ tables critiques (items.item_category_id, order_items.item_id, order_payments.order_id, cash_movements.session_id, etc.). Identifier ON DELETE/UPDATE rules. CASCADE vs RESTRICT cohérent ?
2. Indexes — N+1 candidates (orders.branch_id, order_items.order_id, item_branch_availability.(item_id, branch_id), domain_events.idempotency_key UNIQUE)
3. Soft-deletes — SoftDeletes trait sur Order/OrderItem/Item/ItemCategory/ItemAddon/ItemExtra/ItemVariation
4. Schema enums consistency — item_type tinyint(VEG=5/NON_VEG=10), Status (ACTIVE=5/INACTIVE=10)
5. JSON columns — composition_snapshot, allergens_snapshot, channels, visible_on — schema validation rules ?
6. Triggers BEFORE DELETE on audit_logs + z_reports (MySQL only — SQLite test env bypass)
7. Migrations integrity — `php artisan migrate:status` clean ? Pending migrations ?
8. fiscal_sequence_no monotonic per-branch — pas de gap, lock concurrent OK
9. domain_events.idempotency_key UNIQUE constraint active
10. Branch.status = 1 vs Status::ACTIVE = 5 mismatch (P1 backlog known)
11. cat 315 channels='[]' applied post-heal — vérifier persistance
12. item_wizard_profiles XOR check (item_id OR item_category_id, not both)
13. order_items.composition_snapshot — % rows NULL ? % rows with name field ?
14. Backup-able : taille DB, time to dump, restore tested
15. Cross-validation : run `php artisan migrate:fresh --seed --pretend` to detect dead seeders

**Known backlog (do not rediscover)** :
- P1-3 : 187 order_items NULL composition_snapshot.name (display issue, NF525 chain intact)
- P2-3 : config/menu.php has archived item definitions
- Branch.status enum mismatch (workaround applied)

---

### A2 — Backend Services

**Sub-agent : Architect**
**Inputs** : `app/Services/`, `app/Domain/`, `app/Http/Controllers/`, `app/Http/Resources/`
**Frozen** : PricingService, FiscalServices

**Checks** :
1. PricingService::calculateOrder — backend authoritative ? Client total ignored ? Tax computation correct (10% TVA) ?
2. AvailabilityService — cache invalidation working ? Event dispatch right ?
3. StockService — stockable_type polymorphic correct ? requirementsForOrder() handles bols/frites new variations ?
4. CompositionSnapshotBuilder — captures variation_id, attribute_id, attribute_name, variation_name, qty, unit_price, line_total ?
5. ComposerProfileProjection — projects steps + choices correctly for 7 bols/frites profiles ?
6. ItemResource + NormalItemResource — composer_profile payload correct shape ?
7. ItemCategoryService::list + ::destroy + ::sortCategory — channels filter applied ?
8. KioskMenuService — applies channels filter on cats + items ? Cat 315 hidden ?
9. FiscalSequenceService — Cache::lock 5s + FOR UPDATE triple defense ? alloc-at-create vs alloc-at-close logic ?
10. ZReportService — close logic + chain HMAC ?
11. AuditLogService — append-only enforced ?
12. RBAC FormRequest — 88 endpoints scattered ?
13. SenangPay webhook — gateway class exists or stub ?
14. Refund service — counter-entries miroir ?
15. OrderStateMachine — apply lockForUpdate upstream ?

**Known backlog** :
- P0-12 (BRAIN 2026-05-11) : legacy state machine callers no lockForUpdate
- P0-09 : CashDrawer triple-defense applied
- P0-11 : SenangPay 501 stub

---

### A3 — Sync / Outbox / Pusher

**Sub-agent : SRE**
**Inputs** : `app/Events/`, `app/Listeners/`, `app/Jobs/Outbox*`, `config/broadcasting.php`, `routes/channels.php`, `domain_events` table

**Checks** :
1. CategoryCreated/Updated/Deleted → CatalogChanged::fromMenuMutation → PersistCatalogChangedToOutbox listener
2. ItemAvailabilityChanged → CatalogChanged similarly
3. Idempotency key on domain_events (sha1 of entity_type+id+branch_id+change_type+correlation_id) UNIQUE
4. Branch active filter `where('status', Status::ACTIVE)` — BUG known (status=1 vs 5)
5. DispatchDomainEventsJob — Pusher broadcast OK, retry on fail, dead letter queue ?
6. Channel auth `private-branch.{id}` — kiosk vs admin vs staff vs offline ?
7. KDS pull endpoint `/api/admin/kds-order/sync` — adaptive polling
8. Kiosk offline queue table `kiosk_offline_queue` — workerInterval 5s retry
9. SenangPay webhook idempotency (parité Stripe iter11)
10. Mobile app polling endpoints — fetch frequency ?
11. Pusher rate limits — 100 events/sec ?
12. Sync latency p50/p95 — capture during E2E
13. Listeners idempotency `firstOrCreate` pattern — 4 listeners done iter14, 6 remaining ?
14. Cross-surface broadcast verification — fire event, verify all surfaces receive within 2s

**Known backlog** :
- Branch.status mismatch (heal applied workaround branchId=1 explicit)
- 6 listeners remaining idempotency refactor (V1.0.1)

---

### A4 — POS Vanilla Wizard (FROZEN — read-only audit)

**Sub-agent : Architect (read-only)**
**Inputs** : `public/js/pos-wizard.js` (296 KB), `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`
**LOCK required for any code change**

**Checks** :
1. Diff vs main — 0 ligne expected (or documented)
2. Composer-aware gate `posWizardComposerAware.enabled` consumes composer_profile correctly
3. With FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true (heal applied), bols flow works ?
4. detectCategory fallback for unknown wizard_template — 'custom' falls to default ?
5. getAllowedSteps switch — sandwich/burger/tacos/assiette/salade/omelette/ojja/snacking + default. 'custom' NOT handled → composer-aware MUST be enabled
6. Hardcoded fallback Pain/Galette (lines 698-703) — Cayenne sandwich fake step risk (P1-2 backlog)
7. Sauce attribute name regex `'sauce|assaisonnement'` — matches "Sauce Cayenne (incluse)" ? "Sauce bol" ?
8. Viande detection regex `'viande|meat|proteine'` — matches "Viande 1" / "Viande 2" ?
9. ALL_SAUCES hardcoded list (65-83) — still has 15 old sauces, mismatch with new 13 canonical ? Emoji blanc visual issue
10. POS-WIZARD-DRINKS group_label detection — addons with role='drink' picked up ?
11. Menu choice logic — 'full' vs 'frites' vs 'boisson' vs 'none' — addons matched by name ?
12. POS_WIZARD_CONFIG fallback prices — sauceExtraPrice 0.50, viandeSupplPrice 2.50 — match config ?

**Live test required** :
- Open POS, click Bol Curry → wizard ouvre composer-aware path with 4 steps base/sauce/supp/drink ?
- Click Sandwich Cayenne → wizard sandwich legacy path, with fake Pain/Galette OR composer-aware skipping that ?

**Known backlog** :
- P0-15 BRAIN frozen-zone breach (+237 viande logic, +133 composer-aware logic)
- P1-2 fake Pain/Galette step for Cayenne sandwich

---

### A5 — POS Vue Admin (PosComponent.vue)

**Sub-agent : Architect**
**Inputs** : `resources/js/components/admin/pos/`, `resources/js/pos-app.js`, `routes/web.php:53-56` (AdminPosV4Controller)

**Checks** :
1. POS V4 entry route `/admin/pos-v4/{any?}` works ?
2. Sidebar cats — 9 new cats + cat 315 hidden expected
3. Item add → wizard binding — opens correct wizard per template ?
4. Cash drawer session start/close UX
5. Print receipt — ESC/POS generation correct (composition_snapshot rendering)
6. Idempotency-Key header on POST order creation
7. Voids/refunds UX
8. Branch_id sticky per session
9. POS-V4 vs legacy POS — feature parity ?
10. Real-time KDS bump notification

**Known backlog** :
- A02-1 (BRAIN POS audit) : architecture coherence findings
- A18 : Discount edge cases

---

### A6 — Kiosk Vue Wizard (FROZEN — read-only audit)

**Sub-agent : A11y + UX (read-only)**
**Inputs** : `resources/js/components/frontend/kiosk/`, `resources/js/store/modules/kioskMenu.js`, `resources/js/helpers/kioskCategoryOrder.js`

**Checks** :
1. Diff vs main — 0 ligne expected on frozen Wizard/App/Upsell
2. composer_profile consumption — line 779/887 detects + uses for bols/frites
3. resolveExplicitStepType + ADDON_ROLE_TO_TYPE — drink mapped to menu
4. getStepLabel + getQuestionLabel — i18n key override DB step.label (P1-1 backlog)
5. WCAG 2.1 AA — role/aria-* sur tablists, dialogs, progressbars, radiogroups
6. Focus management — headingRef.focus() on step transition
7. Keyboard nav — Enter/Space on ChoiceCard
8. prefers-reduced-motion override
9. Color contrast — 4.5:1 minimum
10. kioskMenu.js getter sortCategoriesForKioskDisplay uses kioskCategoryOrder helper
11. KIOSK_HIDDEN_CATEGORY_IDS — id=315 (frites-accompagnements) hidden via channels=[] now, so const can be removed ? Verify
12. Vuex store sandwichSubcolumn — null state after sandwich-split disabled

**Live test required** :
- Kiosk idle → wizard each category → 0 raw labels (Label.X / kiosk.X / 0undefined / NaN€)
- Test each new wizard : Cayenne, Galette Normale/Cayenne, Classique, Tacos, Big Tacos, 5 Bols, 2 Frites

**Known backlog** :
- P1-1 kiosk wizard "QUEL MENU?" label for drink step

---

### A7 — KDS Display + Routing

**Sub-agent : Tester + UX**
**Inputs** : `resources/js/components/admin/kitchenDisplaySystem/`, `app/Http/Controllers/KitchenDisplaySystemController.php`, `app/Http/Controllers/KdsSyncController.php`, kds_orders table

**Checks** :
1. KdsV2Grid 4×2 FIFO 8-slot max — overflow handling
2. KdsOrderCard rendering — items grouped by category (kds_station enum bar/cuisine_chaude/cuisine_froide/none)
3. KdsOrderLine — composition_snapshot rendering line-by-line via EscPosPrinterService::normalizeReceiptVariations parity
4. Bump action — PENDING → PREPARING → READY transitions
5. Undo toast 3s
6. Status banner — offline/capacity indicators
7. Adaptive polling fallback (5s if Pusher disconnected)
8. New items kds_station field populated ? Default 'none' for bols/frites ?
9. Multi-kitchen routing (multiple KDS terminals)
10. Old items pre-reset still display correctly when in pending orders

**Live test required** :
- Submit order via kiosk → KDS receives within 5s → bump to PREPARING → bump to READY → disappears after timeout
- Verify with 2 simultaneous orders (FIFO ordering)

---

### A8 — OSS Display (Order Status Screen)

**Sub-agent : UX**
**Inputs** : `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue`

**Checks** :
1. Popular items query post-reset — returns valid items (not archived)
2. Order number + status display
3. Auto-refresh interval
4. ARIA landmark `label.oss_main_aria`
5. Customer-facing UX — large fonts, high contrast, clarity

**Live test required** :
- Submit order → OSS shows "Préparation #1" → after KDS bump to READY → OSS shows "Prêt #1"
- Check polling cadence

---

### A9 — Admin CRUD (Categories + Items + Stock + Orders + Fiscal)

**Sub-agent : Architect + Security**
**Inputs** : `resources/js/components/admin/settings/ItemCategory/`, `resources/js/components/admin/items/`, `app/Http/Controllers/Admin/`, `app/Http/Requests/Admin/`

**Checks** :
1. Categories CRUD — create/show/update/destroy/sort UI works post-reset
2. Show 9 active cats + option "show archived" → 8 archived visible
3. Items CRUD — search by new categories + filter by status + edit wizard binding
4. Stock dashboard `/admin/stock-rupture-dashboard` — shows item_branch_availability rows
5. Orders list — past orders display item_name (composition_snapshot fallback for null)
6. Fiscal Z report — list, view, close
7. RBAC — admin permission gates on sensitive routes
8. Spatie permissions roles (Admin / Branch Manager / POS Operator / Chef)
9. Audit log viewer (if exists)
10. Excel export/import for items

**Known backlog** :
- A14 (BRAIN POS audit) RBAC : FormRequest authz scattered
- Stock dashboard backend 90% done, UI 5-7d (V1.x)

---

### A10 — Mobile App

**Sub-agent : UX + A11y**
**Inputs** : `mobile/data/menu.js`, `mobile/screens-*.jsx`, `mobile/index.html`, `mobile/api/storage.js`

**Checks** :
1. mobile/data/menu.js 9 cats alignment (post-rewrite 2026-05-13)
2. 4 viandes + 13 sauces + 4 crudités + 10 supp + bols + frites + supplements_bols
3. Wizard step rendering parity with kiosk (composer profile mirror manually mantained)
4. Assets present (mobile/assets/menu/) — 190 files
5. Loyalty system V0 functional (smoke 6/6 passing per BRAIN)
6. Onboarding screens preserved
7. WCAG 2.1 AA — focus management, ARIA, color contrast
8. Storage layer (localStorage) — cart, auth, loyalty state
9. Offline behavior — no API sync (V0 design)
10. PWA-style standalone

**Live test required** :
- mobile :8081 — navigate 9 cats → wizards → cart → payment screen → confirmation

**Known backlog** :
- Phase 6 API menu sync deferred
- Loyalty backend 8 P0/P1 (B-01..B-08)

---

### A11 — Cross-Surface E2E + NF525 (largest axis)

**Sub-agent : Tester + Adversarial (parallel pair)**
**Inputs** : `tests/e2e/`, fiscal chain, all surfaces

**Checks** :
1. Kiosk order flow end-to-end : idle → order setup → categories → wizard → cart → payment → confirmation
2. POS order flow : POS V4 → cat select → item wizard → cart → cash drawer payment → receipt print → Z-close cycle
3. Order persistence : composition_snapshot built correctly with all new variations + extras + addons
4. Sync propagation : kiosk_order_submitted event → KDS Echo + polling fallback → OSS update
5. Fiscal chain : audit_logs HMAC chain verifiable, z_reports daily clôture
6. fiscal_sequence allocation : create order → allocate seq → close → verify monotonic, no gap
7. Refund flow : refund order → counter-entries miroir → fiscal seq for refund
8. Multi-tenant : create 2 orders different branches (admin override) → isolation BranchScope enforced
9. RGPD : kiosk_order export/delete request
10. Sanctum kiosk:order TTL 480m + revocation
11. Idempotency : duplicate POST order with same key → 409 conflict OR replay cached 2xx
12. Webhook idempotency : Stripe + SenangPay
13. Cash drawer concurrent : 2 POS terminals same branch → only 1 open session
14. Print receipt : ESC/POS format, composition rendering, allergens

**Mass E2E** (Phase 13 below).

---

## §6. PHASE 12 — Cross-axis reconciliation

After all 11 axes audited + healed individually, run a reconciliation phase :

1. **Compile cross-axis findings** : findings flagged CROSS-AXIS during per-axis audits
2. **Identify systemic issues** : same root cause across multiple axes (e.g., Branch.status mismatch impacts A1 + A3)
3. **Re-spawn 1 systemic-issue sub-agent** per cross-axis issue
4. **Heal systemic issues** (may require coordinated fixes)
5. **Run targeted regression tests** post-heal
6. **Verify no axis regressed** by re-running per-axis verdict

Output : `reports/audit/ultra-goal-2026-05-13/cross-axis-reconciliation.md`

---

## §7. PHASE 13 — MASSIVE E2E (`/test-e2e` skill invocation)

### 7.1 Pre-requisites
- All 11 axes GREEN (0 P0, ≤2 P1)
- Cross-axis reconciliation GREEN
- DB state validated (10 cats active, composer profiles published)
- Frozen-zones diff = 0 ligne
- Tests baseline PHPUnit/Vitest/Playwright PASS

### 7.2 Invoke `/test-e2e` skill

The skill `test-e2e` runs :
- **GStack main team** : capture + reason hard + fix
- **Adversarial supervisor** : inspects each capture, disputes hidden defects, blocks delivery until perfect
- Loops until truly green — no caveats

Coverage matrix :

| Surface | URLs | Flows | Captures |
|---------|------|-------|----------|
| Kiosk | /kiosk/idle, /kiosk/order-setup, /kiosk/categories, /kiosk/cart, /kiosk/payment, /kiosk/confirmation, /kiosk/upsell, /kiosk/loyalty | Order flow × 5 baskets (single, multi-cat, bol+frites combo, sandwich+menu, all-9-cats) | ~40 |
| POS V4 | /admin/pos-v4 | Order flow × 5 + Z-close cycle | ~30 |
| KDS | /kds, /admin/kitchen-display-system | Order receive + bump × 3 states | ~10 |
| OSS | /order-status-screen | Display updates × 3 states | ~10 |
| Admin | /admin/dashboard, /admin/items, /admin/item-category, /admin/orders, /admin/stock-rupture-dashboard, /admin/z-reports | CRUD + filter + export | ~25 |
| Mobile | :8081 standalone | 9 cats + wizard + cart + confirm + loyalty | ~25 |

**Total : ~140 visual captures** read+analyzed.

### 7.3 50-order E2E réel

```
For batch_id in 1..50:
  Pick scenario from matrix (10 scenarios × 5 each = 50):
    - S1: Sandwich Cayenne + menu (frites+boisson)
    - S2: Galette Normale + sauce + menu
    - S3: Galette Cayenne (sauce locked) + supp
    - S4: Sandwich Classique + crudités + supp
    - S5: Tacos seul
    - S6: Big Tacos + menu
    - S7: Bol Curry (frites+sauce+supp+boisson)
    - S8: Bol Gratiné (full add)
    - S9: Petite Frites + Cheddar+Oignon
    - S10: Multi-cart (3 items, mixed cats)

  Capture each:
    1. Kiosk wizard flow screenshots (~5 per order)
    2. Payment screen
    3. Confirmation
    4. KDS receive (within 5s)
    5. OSS display update
    6. DB state : order row, order_items, composition_snapshot, fiscal_seq, domain_events
    7. Receipt ESC/POS render

  Verify:
    - PricingService total matches expected (compute formula in spec)
    - composition_snapshot has all fields populated
    - fiscal_sequence_no monotonic
    - 0 sync errors (Pusher delivered + KDS received)
    - 0 visual regression (no raw labels, layouts intact)

  Save:
    reports/audit/ultra-goal-2026-05-13/e2e-massive/batch-{batch_id}/
      - scenario.json
      - captures/*.png
      - db-snapshot.json
      - verdict.json
```

### 7.4 Sync stress

- Fire 50 orders within 5 minutes (10/min)
- Monitor :
  - domain_events queue depth
  - Pusher delivery latency p50/p95
  - KDS receive latency
  - DB lock contention (fiscal_sequence)
  - Cash drawer concurrent
  - Outbox poll rate

### 7.5 Acceptance criteria Phase 13
- [ ] 50/50 orders persisted DB OK
- [ ] 50/50 fiscal_sequence allocations monotonic
- [ ] 50/50 KDS receive within 5s
- [ ] 50/50 OSS updates within 3s
- [ ] 50/50 composition_snapshot complete
- [ ] 0 visual regression in ~140 captures
- [ ] 0 console error in ~140 captures
- [ ] 0 network error 4xx/5xx
- [ ] Adversarial supervisor verdict : GO

---

## §8. PHASE 14 — Visual sweep adversarial

After Phase 13 E2E, run a **dedicated adversarial visual sweep** :

1. Sub-agent : "Adversarial Visual Inspector" (general-purpose)
2. Brief : "Take EVERY captured PNG from /reports/audit/.../captures/, read each one with Read tool, list every visual defect : raw labels, broken layouts, empty states, error states, color contrast, font issues, alignment, branding, copy errors. Severity calibrated."
3. Output : `reports/audit/ultra-goal-2026-05-13/visual-sweep-verdict.md`
4. Healing : fix every confirmed visual defect (CSS, i18n, etc.)
5. Re-capture impacted surfaces post-heal
6. Re-run adversarial visual sweep
7. Loop until 0 P0/P1 visual defects

---

## §9. PHASE 15 — Convergence GREEN verification

Final verification before declaring DONE :

### 9.1 Re-run all 11 axes audits (light pass)

```
For each Ai in A1..A11:
  Run quick re-audit (sub-agent Explore)
  Verify findings_final.P0 == 0
  Verify findings_final.P1 <= 2 (with owner ack)
```

### 9.2 Re-run full test matrix

```bash
php artisan test  # all tests pass
npm run test:unit
npx playwright test
```

### 9.3 Re-run massive E2E (smoke 10 orders)

10 orders quick smoke to verify no regression introduced by visual sweep healing.

### 9.4 Final frozen-zones diff

```bash
git diff main -- ${FROZEN_FILES[@]}
# Must be 0 lines OR documented LOCK-approved diff
```

### 9.5 Final BRAIN + Graphiti update

```
- BRAIN.md §2: status "ULTRA GOAL COMPLETE 2026-05-13"
- BRAIN.md §3: comprehensive summary
- BRAIN.md §4: empty / next backlog
- BRAIN.md §7 VERIFICATION CHECKLIST: 11/11 domains validated
- Graphiti episode : "Ultra Goal Complete 2026-05-13 — 11 axes GREEN + 50-order E2E + visual sweep"
```

### 9.6 Final commit

```
git add -A
git commit -m "$(cat <<'EOF'
chore(ultra-goal): full system audit + heal + 50-order E2E + visual sweep COMPLETE

11 axes audited + healed independently. Cross-axis reconciliation GREEN.
50-order E2E mass test passed with adversarial supervisor.
~140 visual captures analyzed. 0 visual P0/P1 residual.

## Healed
[generated dynamically from per-axis FINAL reports]

## Backlog deferred (owner-gate)
[P2/P3 not blocking V1]

## Frozen-zones intact
0 lines diff on 14 protected files.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## §10. ADVERSARIAL PROTOCOL — JSON verdict contract

### 10.1 Output schema (mandatory for every sub-agent report)

```json
{
  "agent_role": "<dba|architect|sre|tester|ux|a11y|security|adversarial>",
  "axis": "<A1..A11|cross-axis|visual>",
  "round": "<integer>",
  "verdict": "<GO|NO-GO|GO-CONDITIONAL>",
  "score": "<0-100>",
  "findings": [
    {
      "id": "<Ax-N>",
      "severity": "<P0|P1|P2|P3>",
      "title": "<short>",
      "file": "<path/from/repo/root>",
      "line": "<integer or range>",
      "claim": "<one sentence>",
      "evidence": "<file content snippet OR repro steps>",
      "fix_hint": "<suggested fix>",
      "confidence": "<HIGH|MEDIUM|LOW>",
      "cross_axis": "<null OR list of other axes affected>"
    }
  ],
  "passing_checks": [
    "<short list of areas verified clean>"
  ],
  "open_questions": [
    "<things requiring owner clarification>"
  ]
}
```

### 10.2 Hallucination guard

Adversarial reviewer MUST :
1. Re-read each cited file:line and verify the claim matches
2. Reject findings where evidence doesn't match → `hallucinated_findings`
3. Reject findings where severity is inflated → `severity_corrections`
4. Maintain CONFIDENCE calibration honesty

### 10.3 Cross-validation rule

Findings flagged P0 require **2+ independent agents** confirmation to count as "confirmed". Otherwise downgrade to P1.

---

## §11. SUB-AGENT FLEET — Per role brief

### DBA agent
- Tools : Bash (mysql), Read, Grep
- Mission : Schema integrity, FK, indexes, soft-deletes, triggers
- Output : JSON verdict + axis report

### Architect agent
- Tools : Read, Grep, Glob
- Mission : Service layer coherence, contracts, patterns, dependency discipline
- Output : JSON verdict

### Security agent
- Tools : Read, Grep, WebFetch (CVE)
- Mission : Auth, authz, secrets, injection, OWASP top 10
- Output : JSON verdict

### A11y agent
- Tools : Read, Grep, preview tools
- Mission : WCAG 2.1 AA, ARIA, keyboard, focus, color contrast
- Output : JSON verdict + a11y report

### UX agent
- Tools : Read, preview tools (snapshot/screenshot)
- Mission : Visual quality, IA, copy, empty/error states
- Output : JSON verdict

### Tester agent
- Tools : Bash (artisan, npm), Read, Grep
- Mission : Coverage gaps, fake tests detection, regression suites
- Output : JSON verdict + test matrix delta

### SRE agent
- Tools : Bash (logs, queue), Read, Grep
- Mission : Deploy, CI, queue, cron, observability, monitoring
- Output : JSON verdict

### Adversarial agent
- Tools : All read tools
- Mission : Hostile review, dispute, cross-validate
- Output : confirmed_findings + hallucinated_findings + JSON verdict

---

## §12. EVIDENCE RULES (CLAUDE.md §13)

Every claim must be backed by :
- file:line citation (verified by adversarial)
- OR DB query result
- OR test output
- OR captured PNG (Read tool)
- OR API response sample
- OR git log/blame

**Never fake certainty. Never silently assume success.**

If evidence missing → downgrade confidence → prefer heal/block/human gate.

---

## §13. DECISION FRAMEWORK (CLAUDE.md §10)

Per phase verdict :
- **continue** : acceptable, proceed
- **heal** : partially acceptable, loop max 5
- **block** : unsafe, stop + escalate
- **escalate** : require owner decision
- **human** : explicit human approval

### Escalation triggers (mandatory owner gate)
- Frozen-zone touch needed
- NF525 invariant questioned
- 3+ healing cycles same axis (loops to k=5)
- Architecture direction uncertain
- Evidence weak
- Business correctness unclear
- Production data deletion required
- Force push branch protected
- Public PR creation

---

## §14. CHECKPOINTING & RESUME

After EVERY phase :
1. `git commit -m "chore(ultra-goal): checkpoint phase {phase_id} - {summary}"`
2. BRAIN.md §2 update
3. Graphiti episode push (`group_id: foodking`)
4. Print resume token : `RESUME_TOKEN_PHASE_{phase_id}` (one-line for /goal restart)
5. Save state to `reports/audit/ultra-goal-2026-05-13/checkpoint-phase-{phase_id}.json`

If /goal interrupted (rate limit, context exhaustion) → next invocation reads last checkpoint, resumes from there.

---

## §15. ROLLBACK PLANS

### Per-phase rollback
Each phase has scope-minimal git diff. If audit reveals fix wrong → `git revert HEAD` for that phase's commit.

### Full rollback
```bash
git checkout backup/pre-ultra-goal-2026-05-13
# OR
mysql restore depuis storage/backups/ultra-goal-2026-05-13/foodking-pre-goal.sql
```

### NF525 rollback (special)
If fiscal data corrupted by accident :
1. STOP all writes immediately
2. Restore from DB dump
3. Re-fire missed sync events
4. Verify audit chain integrity
5. Owner gate before resume

---

## §16. REPORTING STRUCTURE

```
reports/audit/ultra-goal-2026-05-13/
├── 00_KICKOFF.md                       # phase 0 baseline
├── frozen-zones-baseline.diff
├── phpunit-baseline.log
├── vitest-baseline.log
├── playwright-baseline.log
├── baseline-visuals/
│   ├── 01-kiosk-idle.png
│   ├── 02-kiosk-categories.png
│   └── ...
├── axis-A1-DBA-round1.md
├── axis-A1-DBA-round2.md
├── axis-A1-adversarial.md
├── axis-A1-FINAL.md
├── axis-A2-architect-round1.md
├── ...
├── axis-A11-tester-round1.md
├── axis-A11-FINAL.md
├── cross-axis-reconciliation.md
├── e2e-massive/
│   ├── batch-001/
│   ├── batch-002/
│   ...
│   └── batch-050/
├── visual-sweep-verdict.md
├── checkpoints/
│   ├── checkpoint-phase-0.json
│   ├── checkpoint-phase-A1.json
│   ...
└── FINAL_VERDICT.md                    # owner deliverable
```

### FINAL_VERDICT.md structure
- §1 Executive summary
- §2 Verdict GO/NO-GO/GO-CONDITIONAL
- §3 Per-axis verdict table
- §4 Confirmed defects healed
- §5 Backlog deferred (owner-gate)
- §6 Frozen-zones integrity attestation
- §7 NF525 chain attestation
- §8 E2E mass test results (50/50)
- §9 Visual sweep results (~140 captures)
- §10 Performance metrics (sync latency, DB locks)
- §11 Tests delta vs baseline
- §12 Recommendations for V1.0.1
- §13 Sign-off + timestamp

---

## §17. KNOWN BACKLOG INPUTS (do not rediscover, prioritize for heal)

### From menu-reset 2026-05-13
- **P1-1** Kiosk wizard "QUEL MENU?" label override for bol drink step (frozen Vue, LOCK plan needed)
- **P1-2** POS Vanilla wizard fake Pain/Galette step for Cayenne sandwich (frozen, LOCK plan needed OR migrate to composer profile)
- **P1-3** 187 order_items NULL composition_snapshot.name → blank reprint receipt (NF525 chain intact, display only — backfill or coalesce fix)
- **P2-1** MenuSeeder.php obsolete slugs cleanup
- **P2-2** Test fixtures + 36 e2e screenshots refresh
- **P2-3** config/menu.php archived item definitions cleanup
- Branch.status=1 vs Status::ACTIVE=5 listener mismatch (workaround applied)

### From POS audit 2026-05-09 (ultra adversarial)
- **P0-01/02** SoftDeletes on Order/OrderItem — NF525 archive-then-deny OR retention 6y proof
- **P0-03** MysqlOnly test variant for `z_reports` DELETE trigger
- **P0-04** FK cash_movements + order_payments cascadeOnDelete → restrictOnDelete
- **P0-05** Idempotency middleware default flag decision
- **P0-06** PosOrderController::show:108 cross-branch leak
- **P0-07** RefreshTokenController:23-27 ['*'] privilege escalation
- **P0-08** abilities:kiosk:order on frontend/order routes
- **P0-09** CashDrawer triple-defense (Cache::lock + UNIQUE + lockForUpdate)
- **P0-10** RefundWithCounterEntryService split refund Z reconciliation
- **P0-11** SenangPay gateway class restore OR remove route
- **P0-12** OrderStateMachine::apply legacy callers no lockForUpdate
- **P0-13/14** 4 fake E2E POS specs + sentinel posKioskVariationParity
- **P0-15** Frozen-zone breach governance (KioskWizard +1665, KioskApp +892, pos-wizard.js +237 lignes)
- **POS parallel audit 2026-05-11** : 12 P0 maintained from this list + 8 NEW (A05/A09/A10 cascadeOnDelete + cash flow)
- **A03-1** POS wizard FROZEN n'émet pas `role=menu_*` sur menu addons → POS-path menu formulas silently overcharge 1.20-1.80€/order (mirror E-001 fix landed kiosk only, NOT pos-wizard.js)
- **A07-4** FiscalChainValidator first-row anchor missing
- **A11-B** TransientToken session-auth bypass
- **A13-1..4** 4 POS models still missing BranchScope (OrderStatusTransition, PosParkedOrder, OrderQuote, OrderCoupon)

### V1.0.1 hardening sprint
- FormRequest authz refactor 88 endpoints
- Password policy min:12 + complexity
- Sanctum TTL 8h → 1h sensitive ops
- API key versioning
- 6 listeners idempotency restants (Catalog/Coupon/Availability×3/Table)
- Observability SLI metrics + KDS overflow flag UI

### V1.x backlog
- F-016b stock dashboard UI (Q3=A 5-7j)
- 17 advisories security composer triage
- Laravel 9→10→11
- Spatie 5→6
- ESLint v10
- Saga pattern Order+Payment+Stock
- Stripe webhook idempotency parity SenangPay

---

## §18. ACCEPTANCE CRITERIA — DEFINITION OF DONE

### Per axis (A1..A11)
- [ ] 0 P0 confirmé résiduel
- [ ] ≤2 P1 confirmé résiduel avec owner ack
- [ ] Tests PHPUnit + Vitest impactés VERTS
- [ ] Frozen-zones diff = 0 ligne (sauf LOCK approved)
- [ ] axis-Ai-FINAL.md archivé

### Cross-axis
- [ ] Cross-axis reconciliation report archivé
- [ ] 0 P0 systemic résiduel

### Massive E2E (Phase 13)
- [ ] 50/50 orders persisted DB OK
- [ ] 50/50 fiscal_sequence allocations monotonic
- [ ] 50/50 KDS receive within 5s
- [ ] 50/50 OSS updates within 3s
- [ ] 50/50 composition_snapshot complete
- [ ] 0 visual regression in ~140 captures
- [ ] 0 console error
- [ ] 0 network error 4xx/5xx
- [ ] Adversarial supervisor verdict : GO

### Visual sweep (Phase 14)
- [ ] 0 raw labels (Label.X / kiosk.X / 0undefined / NaN€) in any capture
- [ ] 0 layout breaks (overflow, debordement, broken grid)
- [ ] 0 empty states uncovered
- [ ] 0 error states uncovered
- [ ] Branding intact (FoodKing logo + Le Cayenne identity)
- [ ] i18n résolu correctement (no missing keys)

### Final convergence (Phase 15)
- [ ] All 11 axes re-audited GREEN
- [ ] Full test matrix PASS
- [ ] Smoke 10-order E2E GREEN
- [ ] Frozen-zones diff = 0 ligne
- [ ] BRAIN.md §2 §3 §7 updated
- [ ] Graphiti episode pushed
- [ ] Final commit pushed
- [ ] FINAL_VERDICT.md delivered

### NF525 attestation
- [ ] fiscal_sequence_no monotonic per-branch, gap-free
- [ ] audit_logs HMAC chain verifiable (CLI verify command run + green)
- [ ] z_reports HMAC chain verifiable
- [ ] composition_snapshot immutable on all order_items
- [ ] 6y retention preserved
- [ ] No DELETE on audit_logs / z_reports (trigger active)

### Multi-tenant attestation
- [ ] BranchScope sur 13+ models verified
- [ ] kiosk:order ability scoped
- [ ] Pre-auth lookups explicit withoutGlobalScope
- [ ] Idempotency middleware fires sur POST mutating

---

## §19. NON-SCOPE EXPLICITE

À NE PAS faire dans ce cycle :

1. **New features** beyond audit/heal scope
2. **Laravel 9 → 10 → 11 migration** (track séparé)
3. **Spatie 5 → 6 migration** (track séparé)
4. **ESLint v10 setup** (track séparé)
5. **Saga pattern Order+Payment+Stock orchestration** (track séparé)
6. **Mobile API menu sync** (Phase 6 V1.x)
7. **Stock dashboard UI** (V1.x F-016b)
8. **Loyalty backend B-01..B-08** (Phase 6)
9. **SaaS B2B 24-month roadmap** (BACKLOG long-terme)
10. **Composer dump-autoload** (NEVER during this goal — broke server before)
11. **Force-push to main** (NEVER)
12. **Production data deletion** (NEVER without explicit owner gate)
13. **.env modification beyond local config** (owner deploys to prod manually)
14. **Modification of frozen-zones without LOCK plan + owner gate**

---

## §20. OWNER GATES — Escalation points

Claude MUST stop and escalate to owner in these cases :

1. **Frozen-zone touch required** to fix P0
2. **NF525 invariant questioned** (e.g., SoftDeletes on Order/OrderItem)
3. **3+ healing cycles same axis** without convergence
4. **Architecture direction uncertain** (e.g., do we add new Service vs extend existing)
5. **Cross-branch data leak** detected
6. **Production data correction needed** (manual SQL)
7. **PR creation required** (always escalate)
8. **Backend breaking API change** (clients impacted)
9. **Migration with destructive intent** (DROP, TRUNCATE)
10. **External service config change** (Pusher, Stripe, SenangPay credentials)

Escalation format :
```
⚠️ OWNER GATE REQUIRED

Axis: <Ai>
Phase: <name>
Issue: <one-line>
Options:
  (a) ...
  (b) ...
  (c) ...
Default proposed: <(a|b|c)>
Risk if proceed default: <one-line>
Risk if escalate: <one-line>
```

Wait for owner explicit decision. **DO NOT proceed default without confirmation.**

---

## §21. EXECUTION INVOCATION

### To launch this goal
```bash
# In Claude Code session:
/goal plans/ULTRA_GOAL_FULL_SYSTEM_AUDIT_2026-05-13.md
```

The `/goal` skill will :
1. Read this entire plan
2. Bootstrap session memory (BRAIN + Graphiti)
3. Execute Phase 0 → Phase 15 sequentially
4. Run convergence loops per axis (max 5)
5. Checkpoint + Graphiti push per phase
6. Spawn adversarial sub-agents per phase
7. Invoke `/test-e2e` skill at Phase 13
8. Deliver FINAL_VERDICT.md
9. Push final commit

### To resume (if interrupted)
```bash
/goal plans/ULTRA_GOAL_FULL_SYSTEM_AUDIT_2026-05-13.md --resume
```
Reads last checkpoint from `reports/audit/ultra-goal-2026-05-13/checkpoints/` and continues.

### To force restart (lose progress)
```bash
rm -rf reports/audit/ultra-goal-2026-05-13/
/goal plans/ULTRA_GOAL_FULL_SYSTEM_AUDIT_2026-05-13.md --restart
```

---

## §22. FINAL NOTE — Discipline immuable

Claude must remember at every moment :

> **Le but n'est pas la vitesse. Le but est correctness, coherence, reliability, quality.**
>
> **Partial est meilleur que faux.**
>
> **Bloqué est meilleur que silencieusement dangereux.**
>
> **Real evidence is more important than confidence.**
>
> **Tests passing does not automatically mean the implementation is acceptable.**
>
> **Visual evidence required — un test technique vert ne prouve pas que l'UI est correcte.**
>
> **No return with broken state — si un fix échoue, loop pour corriger, pas livré tant que ce n'est pas vert.**

Claude est responsable de **préserver l'intelligence du projet**. Cela signifie :
- Protéger le projet de la dérive
- Protéger l'équipe des décisions faibles
- Protéger le codebase des régressions cachées
- Protéger la qualité produit du succès superficiel
- Protéger la continuité à travers les longs cycles
- **Livrer du code testé techniquement ET visuellement, jamais cassé**

---

**Plan auteur** : Claude Code orchestrator
**Cartographie** : CLAUDE.md §1-§16, PROJECT_BRAIN.md §1-§9, plans/MASTER_ULTRA_AUDIT_5_SYSTEMS_2026-05-09.md, plans/ULTRA_PLAN_MENU_RESET_LE_CAYENNE_2026-05-13.md, reports/audit/pos-parallel-2026-05-11/, Graphiti `foodking` group
**Validité** : 7 jours (re-bootstrap si Phase 0 lancée après 2026-05-20)
**Status** : ⚡ **READY TO LAUNCH avec `/goal plans/ULTRA_GOAL_FULL_SYSTEM_AUDIT_2026-05-13.md`**
