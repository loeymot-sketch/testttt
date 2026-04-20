# 00 — Index orchestration audit pré-production (2026-04-20)

> **Rôle de ce dossier.** 20 tâches **atomiques**, **séquençables**, à lancer **une par une**
> par sous-agent dédié. Chaque tâche = **un fichier**, **une cible**, **un rapport sortant**.
> L'utilisateur lance manuellement. L'assistant principal (toi, prochaine session) **vérifie
> le rapport**, déclenche un sous-agent de remédiation si FAIL, **n'exécute pas le travail
> à la place**.

## Conventions

- Chaque TASK file contient :
  1. Objectif unique
  2. Subagent recommandé + prompt prêt à coller
  3. Lecture obligatoire (chemins absolus)
  4. Checklist multi-points (≥ 5 vérifications)
  5. Critères PASS / FAIL
  6. Output (chemin du rapport)
  7. Action si FAIL (escalade ou remédiation)
- Tous les chemins sont **absolus**. Deux clones :
  - `testttt = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`
  - `testttt-kiosk-p93 = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93`
- Rapports sortants : `<root>/reports/audit-orchestration/REPORT_TASK<NN>_<slug>_2026-04-20.md`
- Pas de **modification** code dans les tâches **00–17** (read-only). T18–T20 peuvent
  proposer des patches via sous-agent **après** validation.

## Ordre de lancement recommandé (par criticité)

| Ordre | ID | Titre | Risque détecté |
|-------|----|-------|-----------------|
| 1 | T01 | Diff worktrees `testttt` ↔ `testttt-kiosk-p93` | **fichiers manquants** (`Kernel.php`, `sentry.js`, `kioskPerf.js`, `VERIFY_K10*`) |
| 2 | T02 | Schedule Laravel 11 (Kernel→bootstrap/app.php) | **schedule SLO/outbox/OTP perdu** ? |
| 3 | T03 | Sentry front régression K-9 | livrable K-9 supprimé |
| 4 | T04 | kioskPerf K-5 régression | budgets orphelins, plus d'instrumentation |
| 5 | T05 | Allergènes FR — chaîne complète | renommage codes, snapshot, KDS, i18n |
| 6 | T19 | LOCK_A/LOCK_B P9 fermés + frozen zones | gouvernance |
| 7 | T17 | Tests drift Vitest + PHPUnit | régressions silencieuses |
| 8 | T06 | SSOT pricing/totals | invariant cœur |
| 9 | T07 | Idempotency branch-scoped | invariant K-2/K-9 |
| 10 | T08 | Branch isolation kiosk | invariant K-6/K-8 |
| 11 | T09 | EventContract / Outbox / Broadcast | refactor K-10 |
| 12 | T10 | Audit kiosk 110 — 5 P1 (AX12-02, AX4-04, AX11-01, AX10-01, AX14-01) | dette identifiée |
| 13 | T11 | Audit POS 110 — P0/P1 | dette identifiée |
| 14 | T12 | NF525 readiness POS | conformité fiscale |
| 15 | T13 | Hardware K-4 fallback | TPE / printer / camera |
| 16 | T14 | Offline queue K-3 | IDB / SW / backoff |
| 17 | T15 | Sécurité K-6 | abilities / lockout / CSP |
| 18 | T16 | Observability K-9 fond | SLO / scrub / corrélation |
| 19 | T18 | type="button" + a11y K-7 | régression UI |
| 20 | T20 | Gate production-ready final | synthèse + verdict |

## Mode opératoire (pour chaque tâche)

1. Lire `tasks/audit-orchestration/<NN>_TASK_*.md`.
2. Copier le **prompt prêt à coller** dans une **nouvelle session** ou un **sub-agent** (`Task`).
3. Le sous-agent produit le rapport sortant.
4. L'utilisateur dit : « T01 fait » → l'assistant principal **lit le rapport**, valide ou
   relance un sous-agent de remédiation.
5. Mise à jour du **statut** dans ce fichier (manuel ou via assistant) : `PENDING / RUNNING / PASS / FAIL / FIXED`.

## Tableau de suivi

| ID | Statut | Date | Rapport | Notes |
|----|--------|------|---------|-------|
| T01 | **PASS** | 2026-04-20 | `reports/audit-orchestration/REPORT_TASK01_DIVERGENCE_WORKTREES_2026-04-20.md` | 107 A\B / 21 B\A / 151 communs divergents ; **plusieurs fichiers vidés WT non commités** (blob index intact) |
| T02 | **FAIL P0 → FIXED** | 2026-04-20 | `…REPORT_TASK02_SCHEDULE_LARAVEL11_2026-04-20.md` ; remed: `RUN_T02B_KERNEL_RESTORE_2026-04-20.md` | Laravel 9.52 ; `Kernel.php` restauré via `git restore` (T02b Composer) ; `schedule:list` 2 entrées actives (OTP purge + outbox rescue) ; SloEvaluatorJob still pending → backlog K-10.1 |
| T03 | **FAIL P0 (C) → FIXED** | 2026-04-20 | `…REPORT_TASK03_SENTRY_FRONT_2026-04-20.md` ; remed: `RUN_T03B_SENTRY_FRONT_2026-04-20.md` | `sentry.js` réimplémenté from scratch via T03b (GPT-5.4) ; 381 LoC ; 12/12 Vitest pass ; dynamic import `@sentry/vue` no-op safe ; PII scrub ADR-9 conforme |
| T04 | **FAIL P0 (C) → FIXED** | 2026-04-20 | `…REPORT_TASK04_KIOSK_PERF_K5_2026-04-20.md` ; remed: `RUN_T04B_KIOSK_PERF_2026-04-20.md` | `kioskPerf.js` réimplémenté from scratch via T04b (GPT-5.4) ; 13/13 Vitest pass (K-5.6 → K-5.9) ; 9/9 events `perf.*` ; garde-fous feature detection + idempotence + cleanup OK |
| T05 | **FAIL P0 → FIXED** | 2026-04-20 | `…REPORT_TASK05_ALLERGEN_RENAME_FR_2026-04-20.md` | Remédiation T05b/T05c : i18n codes FR + alias, `KsAllergenBadge`, JSDoc `kioskFilters` ; migration `2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot.php` |
| T06 | **FAIL → FIXED** | 2026-04-20 | `…REPORT_TASK06_SSOT_PRICING_2026-04-20.md` | Audit FAIL (resource sans `total` num.) ; T06b : `OrderDetailsResource` + garde `KioskPaymentComponent` (testttt + kiosk-p93) |
| T07 | **PASS** | 2026-04-20 | `…REPORT_TASK07_IDEMPOTENCY_BRANCH_2026-04-20.md` | Lock cache `sha1(branch.idem)` OK + index unique composite + tests E2E ; **réserve admin `branch_id=0`** (BranchScope ne filtre pas) → backlog hardening |
| T08 | **PARTIAL → FIXED** | 2026-04-20 | `…REPORT_TASK08_BRANCH_ISOLATION_2026-04-20.md` ; remed: `RUN_T08B_KIOSK_EVENT_ABILITY_2026-04-20.md` | T08b : 2 routes kiosk-event câblées sur `abilities:kiosk:order` + alias middleware enregistré dans `Kernel.php` (manquait dans testttt vs p93) + spec dédiée `KioskEventAbilityTest` (data provider 2 routes × 3 cas) + adaptation test isolation Phase 7 → **11/11 PASS** ; reste backlog T08 hors-scope canary : `/kiosk/context` formel, validation hex thème, convergence menu legacy |
| T09 | **FAIL → FIXED** | 2026-04-20 | `…REPORT_TASK09_EVENTCONTRACT_OUTBOX_BROADCAST_2026-04-20.md` ; remed: `RUN_T09B_BROADCAST_REFACTOR_2026-04-20.md` | T09b Stratégie A : `DispatchDomainEventsJob` migré de `getPusher()->trigger()` vers `Broadcaster::broadcast()` (API Laravel standard) ; mocks adaptés dans OutboxTest + EventContractTest (2 sites) → **16/16 PASS** ; AX12-02 déjà résolu par T16b ; `BROADCAST_DRIVER=log` déjà dans `phpunit.xml` |
| T10 | **PASS** (audit) | 2026-04-20 | `…REPORT_TASK10_KIOSK_P1_REMEDIATION_2026-04-20.md` | 5 P1 documentés : **AX4-04 fixed** (T06b) ; **AX12-02, AX11-01, AX10-01, AX14-01 open** |
| T11 | **PASS** | 2026-04-20 | `…REPORT_TASK11_POS_110_P0P1_2026-04-20.md` | 2 P0 fixed (F-FISC-003 chaîne HMAC, F-SM-002 OSM) ; 7 P1 (3 fixed, 1 partial Z.open, 3 open : idempotence UX, doc, KDS admin policy, couverture) ; 3 blockers POS-9.4 CLOSED |
| T12 | **PARTIAL (WARN)** | 2026-04-20 | `…REPORT_TASK12_NF525_FISCAL_2026-04-20.md` | 4 piliers NF525 MVP OK (HMAC chaîné + Z, immutabilité triggers/Eloquent, séquence par branche, archive 6 ans) ; **gaps** : `verifyChain`/`verifySignature` non câblés à `Z::open()`, pas de schedule `fiscal:archive`, pas d'export JET/PIAF, pas de marquage DUPLICATA réimpression → cycles **P11 + P-OPS-SCHEDULE + P13** |
| T13 | **PASS** | 2026-04-20 | `…REPORT_TASK13_HARDWARE_K4_2026-04-20.md` | 4 catégories (TPE, printer, camera, buzzer) avec fallback + Vitest ; 3 obs LOW (jitter printer, fallback visuel buzzer, drill K-4 doc) |
| T14 | **FAIL → PARTIAL FIXED** | 2026-04-20 | `…REPORT_TASK14_OFFLINE_QUEUE_K3_2026-04-20.md` ; remed: `RUN_T14B_OFFLINE_HARDENING_2026-04-20.md` | T14b V7 : whitelist `offline.queued/replayed/abandoned/recovered` (front + back mirror) + tracks instrumentés dans `kioskOfflineQueue.js` (saveOrder, syncQueue success, abandoned, recovered) + 3 tests Vitest spy → **5/5 PASS** ; **V1/V2/V3 reportés en backlog T14c** : code testttt = modèle simple localStorage + setInterval 30s, pas d'IDB ni de paliers backoff → nécessite convergence p93 hors-scope d'un patch |
| T15 | **PASS** | 2026-04-20 | `…REPORT_TASK15_SECURITY_K6_2026-04-20.md` | 8/8 V (abilities `kiosk:order`, branch server-only, throttle `kiosk:{user}|{ip}`, lockout, `kioskLockdown.js` 6 vecteurs, CSP Report-Only + sanitize PII, 7 events `security.*` mirror, canal Monolog 90j) |
| T16 | **FIXED** | 2026-04-20 | `…REPORT_TASK16_OBSERVABILITY_K9_2026-04-20.md` ; remed: `RUN_T16B_OBSERVABILITY_2026-04-20.md` | T16 FAIL audit → T16b : **`SloEvaluatorJob` planifié** (Kernel `everyFiveMinutes onOneServer`) + **3 listeners outbox réparés** (`X-Correlation-ID` lu via Log::sharedContext puis header puis fallback UUID — AX12-02 résolu) + `BROADCAST_DRIVER=log` phpunit.xml → **B2 levé** ; classes K-9 portées p93→testttt par T17b |
| T17 | **FIXED** | 2026-04-20 | `…REPORT_TASK17_TESTS_DRIFT_2026-04-20.md` ; remed: `RUN_T17B_TESTS_REJOUES_2026-04-20.md` | T17 audit FAIL (statique) → T17b exécution réelle : **PHPUnit 556/0/8 (+46 vs K-10) ; Vitest 407/0/0** ; 1 régression intra-T17b corrigée (SloEvaluatorJob class + service + canal log portés p93→testttt) → **B1 levé** |
| T18 | **FIXED** | 2026-04-20 | `…REPORT_TASK18_TYPE_BUTTON_A11Y_2026-04-20.md` ; remed: `RUN_T18B_A11Y_BUTTON_TYPE_2026-04-20.md` | T18 FAIL audit → T18b : **70 boutons** corrigés `type="button"` dans 14 SFC kiosk (+14 par rapport au pré-comptage 56 grâce au comptage template équilibré) ; `rg` résiduel = 0 ; Vitest 22/22 a11y → **B3 levé** ; **next T18c** : workflow CI `.github/workflows/vitest.yml` |
| T19 | **FIXED (doc)** | 2026-04-20 | `…REPORT_TASK19_LOCKS_FROZEN_ZONES_2026-04-20.md` ; remed: `RUN_T19B_POST_HOC_LOCKS_2026-04-20.md` | T19 FAIL audit → T19b doc-only : **2 POST_HOC_LOCK** créés (P1 stock_sync `b76506ae9`, P3 refund `b007c6344`), 2 LOCK_B (`OrderService`, `PaymentService`) annotés PARTIAL RELEASE 2026-04-20 ; **PricingService diff = orange** (garde dispo en pré-condition `calculateOrder`, pas de changement boucle prix/TVA/discount) — relecture humaine recommandée mais pas REQUIRES_HUMAN_REVIEW critique |
| T20 | **PASS → GO canary** | 2026-04-20 | `…REPORT_TASK20_GATE_PROD_FINAL_2026-04-20.md` | Verdict gate : initialement CONDITIONAL GO ; **B1+B2+B3 tous levés** par T16b+T17b+T18b+T19b → **GO canary 14 j** ; **T18c + T08b + T09b + T14b V7 livrés** (cf. `SYNTHESE_FINALE_REMEDIATION_2026-04-20.md`) ; **PHPUnit 562/0/8 + Vitest 410/0/0** post-remédiation, 0 régression ; reste backlog : T14c (offline K-3 v2 IDB+jitter), arbitrage NF525 P11+P13, T08 reste (`/kiosk/context`, theme hex), portage testttt depuis p93 si requis |

## Cycle post-T20 — Convergence et hygiène (2026-04-20)

> Travail mené en `RUNNER_MODE: single-session` avec `auto-remediation.mdc` actif.
> Les ID `A*`, `B*`, `C*`, `X*` correspondent à des micro-tâches lancées hors du
> plan T01–T20 d'origine. Tracées ici pour audit trail.

### Vague A — Hygiène post-canary (atomic commits + Playwright + diff convergence)

| ID | Statut | Date | Rapport / commit | Notes |
|----|--------|------|------------------|-------|
| A1 | **DONE** | 2026-04-20 | 5 commits thématiques | Découpage atomique du cycle de remédiation |
| A2 | **DONE** | 2026-04-20 | `reports/execution/PLAN_PHASE_9_KIOSK_2026-04-18.md` (annoté) | ESCALATION T04b/T14b/T09b marqués RESOLVED/PARTIAL |
| A4 | **DONE** | 2026-04-20 | `.gitignore` | Ignores Playwright (`/test-results/`, `/playwright-report/`, `/blob-report/`, `/playwright/.cache/`) |
| A5 | **DONE** | 2026-04-20 | `RUN_A5_CONVERGENCE_T08B_T09B_2026-04-20.md` | `DispatchDomainEventsJob` aligné p93 (BroadcastManager idiomatique) + Kernel/routes confirmés alignés |
| A6 | **PARTIAL** | 2026-04-20 | `RUN_A6_PLAYWRIGHT_E2E_2026-04-20.md` | Tooling fix `npm install` + `npx playwright install chromium` ; bloqué sur user POS manquant en BD → B1 |
| B1 | **HUMAN_GATE** | 2026-04-20 | `RUN_B1_DB_SEED_E2E_2026-04-20.md` | `RoleDoesNotExist` — auto-incr roles à 7+, `EnumRole::ADMIN=1` hardcoded ; `migrate:fresh --seed` destructif requis. Décision A/B/C user. |

### Vague C — Convergence ciblée (zéro-risque)

| ID | Statut | Date | Cible | Notes |
|----|--------|------|-------|-------|
| C1 | **DONE** | 2026-04-20 | `phpunit.xml` | `memory_limit=512M` inline (évite flag CLI manuel) |
| C2 | **DONE** | 2026-04-20 | `app/Http/Controllers/Frontend/CspReportController.php` + route `/api/frontend/csp-report` + `tests/Feature/Observability/CspReportEndpointTest.php` | Endpoint anonyme CSP report ; logge `observability` ; redaction PII |
| C3 | **DONE** | 2026-04-20 | audit git status | Pas de collision avec P11 |
| C4 | **DONE** | 2026-04-20 | `app/Http/Middleware/ValidateKioskLocale.php` + Kernel alias + 4 routes (`menu`, `pricing/preview`, `promo/validate`, `upsell`) + `tests/Feature/KioskMultiBranch/KioskLocaleMiddlewareTest.php` | Middleware K-8 — locale validée vs `Branch.available_locales` |
| C5 | **DONE** | 2026-04-20 | `ValidateKioskLocale` + tests étendus | Logs `observability` sur `kiosk_locale.format_invalid` / `kiosk_locale.not_allowed` |
| C6 | **DONE** | 2026-04-20 | `config/logging.php` + `tests/Feature/Observability/SecurityLogChannelTest.php` | Canal `security` dédié (daily, 90j, info) — infrastructure-only port |

### Vague X — Tests p93-uniques portés

| ID | Statut | Date | Cible | Notes |
|----|--------|------|-------|-------|
| X1 | **DONE** | 2026-04-20 | `tests/Feature/Observability/ObservabilityLogChannelTest.php` | 2/2 PASS — smoke test canal `observability` + invariants `security`/`hardware` préservés |
| X2 | **DONE** | 2026-04-20 | `tests/Feature/Observability/CorrelationIdEndToEndTest.php` | 4/4 PASS — adapté à `/api/frontend/upsell` (authed) + `/api/frontend/csp-report` (anonymous) |
| X3 | **CANCELLED** | 2026-04-20 | `tests/Feature/Observability/ObservabilityEventWhitelistTest.php` | Frontend testttt n'émet pas `ui.*`/`observability.*`/`perf.*`/`security.*` → ajouter au backend = dead code, modifier le frontend = collision P11 |
| X4 | **CANCELLED** | 2026-04-20 | `tests/Feature/Observability/KioskUiEventsWhitelistTest.php` | Idem X3 |
| X5 | **DONE** | 2026-04-20 | full PHPUnit Feature regression | 589/589 PASS, 0 régression |

### Vague C audit-only (post X5)

| ID | Statut | Date | Périmètre | Verdict |
|----|--------|------|-----------|---------|
| C9 | **AUDIT-ONLY** | 2026-04-20 | `database/migrations/` | 3 migrations p93-uniques (item_attributes.role, branches theme cols, kiosk_machines.capabilities) → **SKIP** : zéro consommateur applicatif testttt. Voir `RUN_C9_C10_AUDIT_CONVERGENCE_2026-04-20.md`. |
| C10 | **AUDIT-ONLY** | 2026-04-20 | `routes/web.php` + `app/Http/Requests/*` + `app/Providers` + `app/Enums` + seeders | Trouvaille : `RouteServiceProvider` a 2 hardenings p93-uniques **K-6.3** (kiosk-orders throttle per-machine `kiosk:{user_id}\|{ip}`) + **K-6.4** (login-lockout key `email ?: username ?: 'anon'`). Coupon* / PosPaymentMethod : testttt **AHEAD**. → **HUMAN_GATE** sur backport (zone auth). |
| C11 | **HUMAN_GATE** | 2026-04-20 | backport K-6.3 + K-6.4 dans `RouteServiceProvider.php` | Patch ~10 LOC additif, préserve configurabilité testttt + ajoute K-6.3+K-6.4. Zone `auth` → consentement requis. |
| C12 | **AUDIT-ONLY** | 2026-04-20 | `app/Console/` + `app/Listeners/` + `app/Jobs/` + `app/Observers/` + `config/*` + root configs | testttt **AHEAD partout** (Kernel scheduling, listeners T16b correlation, NF525 fiscal channel, configurable throttle/lockout, Pusher clearing, FISCAL_*_SECRET, playwright workers=1). Convergence p93→testttt épuisée hors zones critiques. |

### Backlog gates restants

- **C7** — K-6 branch_mismatch enforcement dans `KioskEventController` (~93 LOC, zone `branch_id`) → gate
- **C11** — backport K-6.3 + K-6.4 (zone `auth`) → gate
- **B1** — DB seed E2E (zone `data`, destructif `migrate:fresh --seed`) → gate
- **Seeders convergence** (4 differ + 1 unique p93 `ItemAttributeRoleSeeder`) → gate (lié B1)
- **Order/Pos/Table/KioskMachine Requests** (5 divergent, zones order/payment/branch) → gate par fichier
- **T14c** — Offline K-3 v2 (IDB + jitter + `ItemAvailabilityChanged` listener + UI conflict resolution) → cycle dédié
- **T08 reste** — `/kiosk/context` formel + validation hex thème + convergence menu legacy SSOT → cycle K-8 dédié

## Règle d'or

> **Aucune modification code en T01–T17.** Si une tâche détecte un FAIL, le rapport
> documente, l'assistant principal lance un sous-agent **de remédiation** avec un nouveau
> plan, **pas une rustine ad-hoc**.
