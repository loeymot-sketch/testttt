# Mémoire Graphiti — W8 + W8.5 + W9 (2026-04-21)

> **PURPOSE** : Ce fichier est conçu pour être ingéré par le MCP Graphiti
> dès qu'il est connecté à la session Cursor. Format optimisé pour
> extraction de NODES, EDGES, DECISIONS, FINDINGS et CHRONOLOGIE.
>
> Convention : chaque nœud commence par `[NODE/<TYPE>]` + nom canonique.
> Chaque relation par `[EDGE/<RELATION>]`. Chaque décision par `[DECISION]`.
> Chaque finding par `[FINDING/<SEVERITY>]`.

---

## CONTEXTE PROJET

`[NODE/PROJECT] FoodKing-SaaS` — POS / Kiosk / Web Laravel 9.52 + Vue 2 Mix
- repo path : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
- branch active : `feat/ton-sujet`
- worktree V14 sensible : `resources/js/components/admin/pos/ReceiptComponent.vue` (modifié massivement, GATE_BRIEF avant édition)
- conformité : NF525 (audit logs HMAC chained, ZReport HMAC chained, fiscal archive 13 mois)
- governance : `AGENTS.md` + `.cursor/rules/*.mdc` + `.cursor/ACTIVE_CYCLE.md`
- agents disponibles : `foodking-planner-orchestrator`, `foodking-routine-implementer`, `foodking-complex-implementer`, `explore`

---

## CHRONOLOGIE WAVES

### Wave 7 (P_MEGA_W7) — CLOSED
`[NODE/CYCLE] P_MEGA_W7` Offline queue v2 + hardware fallback + branch theming
- commits clés : `8070bc357` (synth), `c1832bf77` (REM1), `7459487ee` (B), `c1832bf77` (A-REM1)
- gate ouvert : `GATE_P_MEGA_19` branches.theme_* (HUMAN_GATE, 8 décisions UX accumulées)

### Wave 8 (P_MEGA_W8_SECURITY_NF525) — CLOSED PASSED
`[NODE/CYCLE] P_MEGA_W8` Security + NF525 readiness
- commit final : `879b41880` (W8 SYNTHESE)
- sub-cycles :
  - `[NODE/SUB_CYCLE] W8.A K-6.2` branch_mismatch enforcement → commit `d8202bc94`
  - `[NODE/SUB_CYCLE] W8.B K-6.3+K-6.4` user|ip throttle + anon fallback → commit `1350ced6d`
    - REM B3 fuzz protection : commit `50c0078d2`
  - `[NODE/SUB_CYCLE] W8.C-P1` NF525 verifyChain Z + tests → commit `fd146bb51`
    - REM F-S1 cast bool env strict (`filter_var(FILTER_VALIDATE_BOOLEAN)`) → commit `aba3c9e12`
  - `[NODE/SUB_CYCLE] W8.C-P2` Schedule fiscal:archive 02:00 → commit `893ea71fb`
  - `[NODE/SUB_CYCLE] W8.C-P3` DUPLICATA marker + migration receipt_print_count → commit `1c05d5673`
- gates ouverts post-W8 :
  - `[FINDING/HIGH-PRODUCT] C5` UI auto-call DUPLICATA marker (mécanisme MVP backend OK, intégration UX ReceiptComponent.vue déférée pour limiter conflits V14)
  - `[FINDING/LOW] C7` policy Spatie pos.receipt.reprint (manque gate fine)
  - `[FINDING/INFO] G2` verifyChain @ FiscalArchiveCommand (défense en profondeur)
  - `[FINDING/MED-OPS] B3-OPS` checklist prod TIMEZONE (déjà documenté .env.example)
  - `[FINDING/LOW-OPS] B1` CACHE_DRIVER ≠ array en prod
  - `[FINDING/LOW] B7` retry J-1 schedule fiscal:archive
  - `[FINDING/DEFER] P4` JET XML (spec DGFiP TBD)

### Hotfix CI Playwright (2026-04-21)
`[NODE/HOTFIX] HOTFIX_PLAYWRIGHT_CI_2026-04-21`
- commit : `7fb3a7528` ci(playwright): opt-in trigger via label e2e-required + diagnostic complet
- modifications :
  - `[EDGE/MODIFIES] HOTFIX_PLAYWRIGHT_CI → .github/workflows/playwright.yml`
  - trigger : `pull_request` désormais conditionné par label `e2e-required`
  - `concurrency: group: playwright-${{ github.ref }}` cancel-in-progress
  - reporter : ajout html
  - 3 artifacts uploadés if:always() : playwright-html-report, playwright-test-results, server-logs
  - diagnostic steps : mix-manifest dump, public/js listing, curl /login
- résultat : run skipped en 9s (fix validé)
- `[FINDING/PENDING] PLAYWRIGHT_E2E_VUE_SPA_BOOT_CRASH` — root cause non investiguée (déférée par décision user)
  - Symptôme : 22/25 tests fail sur `expect(page.locator('#formEmail')).toBeVisible()` avec screenshot blank
  - Hypothèse forte : Vue SPA crash silencieux au boot CI (asset compilation ou env var manquante)
  - Hypothèses secondaires : APP_URL mismatch, mix-manifest absent, throttle login

### Wave 8.5 (HOTFIX PHPUnit MySQL isolation) — IN PROGRESS
`[NODE/HOTFIX] W8.5_PHPUNIT_MYSQL_ISOLATION`
- découvert pendant VERIFY 200% W8 : CI MySQL rouge depuis 2026-04-20 22:32 (régression antérieure W8 EXECUTE)
- 11 fails / 856 tests, tous dans `MenuProjectionServiceTest`
- cause root : `OrderAllergenSnapshotComposedTest::setUp()` faisait `Schema::create('item_extra_allergens')` runtime
- mécanisme : `[EDGE/CAUSES] DDL_RUNTIME_IN_TEST → IMPLICIT_COMMIT_MYSQL → BREAKS_RefreshDatabase_TX → FIXTURE_LEAK → POLLUTES_NEXT_TEST_CLASS`
- pourquoi local SQLite passe : `:memory:` recréé par process + sqlite gère DDL en savepoints
- pourquoi pas détecté 3 jours :
  - dev local SQLite vert
  - pas de required-check `phpunit-mysql` sur main
  - focus session sur Playwright hotfix
- fix : matérialiser table en migration permanente + retirer DDL runtime du test
  - `[NODE/FILE_NEW] database/migrations/2026_04_22_300000_create_item_extra_allergens_table.php`
  - `[NODE/FILE_MODIFIED] tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php` (-15 LOC, +12 LOC commentaire)
- verify local : 14/14 PASSED (filter), 708/708 PASSED (Feature suite)
- pending : push + valider CI MySQL

---

## NODES DOMAINE FONCTIONNEL

### NF525 (compliance fiscale FR)

`[NODE/SERVICE] ZReportService` — `app/Services/Fiscal/ZReportService.php`
- HMAC chained avec `prev_hash` + `signature` + `sequence_no`
- méthode clé : `verifyChain(branchId)` ajouté W8.C-P1
- bug F-S1 corrigé : `(bool) 'false' === true` → utiliser `filter_var(FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` pour env vars stricts
- secret HMAC : `FISCAL_Z_REPORT_SECRET` (>=48 chars, sentinel test différente prod)
- `[EDGE/CALLS] ZReportService::verifyChain → AuditLogService` pour log fiscal channel

`[NODE/SERVICE] AuditLogService` — `app/Services/Fiscal/AuditLogService.php`
- HMAC chained idem pattern
- secret : `FISCAL_AUDIT_SECRET`
- channel `fiscal` log dédié
- index unique : `audit_logs(branch_id, sequence_no)` pour détection sequence_gap

`[NODE/COMMAND] FiscalArchiveCommand` — `app/Console/Commands/FiscalArchiveCommand.php`
- schedule : `dailyAt('02:00')` + `withoutOverlapping()` + `onOneServer()` toutes branches actives
- archive 13 mois rétention NF525
- `[FINDING/INFO] G2` : devrait appeler `verifyChain()` PRE-archive pour défense profondeur

`[NODE/CONTROLLER] PosReceiptPrintController` — `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php`
- POST `/admin/pos/orders/{order}/print-receipt`
- atomic increment `receipt_print_count` via `Order::increment()`
- déclenche affichage marker DUPLICATA si count >= 2
- `[FINDING/HIGH-PRODUCT] C5` : auto-call UI au mount/print pas encore branché

`[NODE/MIGRATION] add_receipt_print_count_to_orders` — `database/migrations/2026_04_20_180000_add_receipt_print_count_to_orders.php`
- ajoute colonne `receipt_print_count UNSIGNED INT DEFAULT 0`
- idempotent

`[NODE/COMPONENT] ReceiptDuplicataMarker` — `resources/js/components/admin/pos/ReceiptDuplicataMarker.vue`
- sub-component créé W8.C-P3 pour mitiger conflits V14 ReceiptComponent
- affiche bandeau "DUPLICATA" si `print_count >= 2`
- intégration ReceiptComponent.vue : 3 LOC seulement

### Throttle / Security

`[NODE/CONFIG] RouteServiceProvider` — `app/Providers/RouteServiceProvider.php`
- limiter API : `RateLimiter::for('api', fn(Request $r) => Limit::perMinute(...)->by($r->user()?->id ?: $r->ip()))` (W8.B K-6.3)
- env : `API_THROTTLE_PER_MINUTE` (default 60, 5000 en CI E2E)
- `[NODE/TEST] tests/Unit/Security/RateLimiterConfigTest.php` (W8.B 5 cas)

`[NODE/MIDDLEWARE] BranchEnforcement` — W8.A K-6.2
- bloque cross-branch access avec error 403 + log
- `[NODE/TEST] tests/Feature/Security/BranchMismatchTest.php`

### Multi-tenant

`[NODE/MODEL] Branch` — `app/Models/Branch.php`
- `theme_*` columns gated GATE_P_MEGA_19 HUMAN_GATE
- isolation via global scopes + middleware

---

## DECISIONS HISTORIQUES

`[DECISION/2026-04-19_AUDIT_INDEX]` 20 audits orchestration créés dans `tasks/audit-orchestration/`
- 20 verify rapports créés dans `reports/review/VERIFY_*_2026-04-20.md`

`[DECISION/2026-04-20_W8_PLAN]` Wave 8 = Security + NF525 readiness
- approche audit-first 3 explores parallèles
- 3 GATE_BRIEFs synthétiques

`[DECISION/2026-04-20_W8.C-P3_V14_MITIGATION]` Créer sous-composant ReceiptDuplicataMarker plutôt que modifier massivement ReceiptComponent.vue
- raison : ReceiptComponent fortement modifié sur worktree V14, conflit garanti si modif > 5 LOC

`[DECISION/2026-04-21_HOTFIX_PLAYWROGHT]` Stratégie B (stop noise + diagnostic complet) au lieu de chase root cause
- raison : 10h investigation infructueuse précédente, besoin signal CI propre d'abord

`[DECISION/2026-04-21_W8.5_OPTION_A]` Migration permanente + retirer DDL test (vs purge explicite setUp ou DatabaseMigrations)
- raison : élimine la cause root, pas juste le symptôme. Compatible avec helper `OrderItemAllergenSnapshot` qui supportait déjà absence table.

`[DECISION/2026-04-21_W9_PRIORITY]` HOTFIX W8.5 D'ABORD puis W9
- raison : CI rouge persistant masque les vrais signaux, branche pas mergeable

---

## FINDINGS ACTIFS (priorité décroissante)

| ID | Sévérité | Sujet | Source | État |
|---|---|---|---|---|
| C5 | HIGH-PRODUCT | DUPLICATA UI auto-call | W8.C-P3 verify | OPEN — attend décision UX (ouvre Wave 9) |
| C7 | LOW | policy Spatie pos.receipt.reprint | W8.C-P3 | OPEN |
| G2 | INFO | verifyChain @ FiscalArchiveCommand | W8.C-P1 | OPEN |
| B3-OPS | MED-OPS | TIMEZONE prod checklist | W8.C-P2 | MITIGATED (.env.example doc) |
| B1 | LOW-OPS | CACHE_DRIVER ≠ array prod | W8.B verify | OPEN |
| B7 | LOW | retry J-1 schedule fiscal | W8.C-P2 | OPEN |
| P4 | DEFER | JET XML | W8.C plan | DEFERRED (spec DGFiP TBD) |
| GATE_P_MEGA_19 | HUMAN_GATE | Branch theming UX | W7 carry-over | BLOCKED |
| C9 | HUMAN_GATE | dispatch-after-commit | W4 carry-over | BLOCKED |
| G14-B | HUMAN_GATE | T09+T17 audit | audit phase | BLOCKED |
| F-PLAYWRIGHT-VUE-BOOT | HIGH-CI | Vue SPA crash CI | hotfix 2026-04-21 | DEFERRED (diagnostic ready) |

---

## INVARIANTS À PROTÉGER (rules)

1. **Pas de `Schema::create/drop` dans `tests/**`** — uniquement migrations
2. **Pas de `(bool) $string`** sur env vars → `filter_var(FILTER_VALIDATE_BOOLEAN)`
3. **Pas de TRUNCATE** dans seeders en mode `testing|local` → DELETE
4. **Pas de `withoutOverlapping()`** sans `onOneServer()` sur scheduled commands multi-branches
5. **Pas de modif > 5 LOC** sur `resources/js/components/admin/pos/ReceiptComponent.vue` sans GATE_BRIEF V14
6. **Pas de modif `app/Http/Controllers/Admin/**`** sans `SUBSYSTEMS_TOUCHED` explicite dans plan
7. **HMAC fiscal secrets** doivent être ≥48 chars + différents prod/test (`fiscal.dev_sentinels`)
8. **Atomic increment Eloquent** : utiliser `->increment()` pas `$model->field += 1; $model->save()`
9. **Tests d'isolation MySQL** : si DDL nécessaire, dans migration, jamais dans test setUp

---

## OUTILS / WORKFLOW STANDARDS

`[NODE/TOOL] gh` — GitHub CLI pour run history (`gh run list --workflow=phpunit.yml`)
`[NODE/TOOL] vendor/bin/phpunit` — local SQLite par défaut (config phpunit.xml)
`[NODE/TOOL] subagent_explore` — readonly research (very thorough recommended pour root cause)
`[NODE/TOOL] subagent_complex_implementer` — pour zones critiques (NF525, fiscal, sync, lifecycle)
`[NODE/TOOL] subagent_routine_implementer` — pour edits triviaux (config, docs, copy, refactor low-risk)

`[NODE/PROCESS] AUDIT-FIRST` — toujours explore avant edit zones >50 LOC ou critiques
`[NODE/PROCESS] VERIFY 200%` — explore subagent post-execute pour valider invariants/regressions
`[NODE/PROCESS] GATE_BRIEF` — synthèse 1 page avant zones HUMAN_GATE
`[NODE/PROCESS] SYNTHESE` — rapport final cycle + ACTIVE_CYCLE update + commit propre

---

## RELATIONS-CLÉS (EDGES)

```
P_MEGA_W8 --closes--> [W8.A K-6.2, W8.B K-6.3+K-6.4, W8.C-P1, W8.C-P2, W8.C-P3]
W8.C-P3 --produces--> [PosReceiptPrintController, ReceiptDuplicataMarker, migration_receipt_print_count]
W8.C-P3 --opens_finding--> [C5 HIGH-PRODUCT, C7 LOW]
W8.5 --hotfixes--> [PHPUnit_MySQL_CI_red_since_2026-04-20]
W8.5 --root_cause--> [DDL_runtime_in_OrderAllergenSnapshotComposedTest]
W8.5 --depends_on--> [migration_item_extra_allergens]
W9 --planned_to_close--> [C5, C7, G2, B1, B3-OPS, B7]
HOTFIX_PLAYWRIGHT_CI --stops_noise_for--> [F-PLAYWRIGHT-VUE-BOOT]
HOTFIX_PLAYWRIGHT_CI --enables--> [diagnostic_artifacts_for_future_debugging]
```

---

## PROCHAINS PAS PRÉVUS

1. ✅ W8.5 fix appliqué (migration + test cleanup)
2. ⏳ W8.5 commit + push + valider CI MySQL vert
3. ⏳ Plan W9 : focus sur C5 (HIGH-PRODUCT, blocker product) + G2 (défense profondeur fiscal) + B1+B7 (ops doc)
4. ⏳ Audit-first W9 : 3 explores parallèles
5. ⏳ EXECUTE W9 par sous-cycles (routing.md)
6. ⏳ VERIFY 200% W9
7. ⏳ SYNTHESE W9 + ACTIVE_CYCLE close

---

## METADATA

- generated_by : Claude Opus 4.7 (orchestrator)
- updated_on : 2026-04-21
- ingestion_target : MCP Graphiti (mcp.json key `graphiti`)
- format_compatible_with : Graphiti node/edge schema (mots-clés [NODE/], [EDGE/], [DECISION], [FINDING/])
- next_update : après W9 SYNTHESE
