# Inventaire des tests + harnais qualité — FoodKing V1 LOCAL Le Cayenne

> Vague 01-structure — lecteur-cartographe `tests-inventory`. Session 2026-07-02.
> Tout ce qui est cité ci-dessous a été vu via ls/grep/Read dans cette session.
> Read-only : aucun fichier projet modifié.

---

## 1. Vue d'ensemble du harnais

| Couche | Framework | Config | Volume observé (cette session) |
|---|---|---|---|
| Backend Feature | PHPUnit (Laravel) | `phpunit.xml` | **651 fichiers** `*Test.php` sous `tests/Feature/` (83 sous-dossiers) |
| Backend Unit | PHPUnit | `phpunit.xml` (suite `Unit`) | **29 fichiers** sous `tests/Unit/` |
| Load/stress | PHPUnit (suite `Load`) | `phpunit.xml` | **1 fichier** : `tests/load/RushMidiSimulationTest.php` (tag `group=stress`) |
| Frontend unit | Vitest + happy-dom | `vitest.config.mjs` | **301 fichiers** spec sous `tests/js/` ; **~2067 `it(`/`test(`** comptés (cohérent avec les ~2092 tests attendus) |
| E2E navigateur | Playwright | `playwright.config.js` | **300 specs** `tests/e2e/`, **20** `tests/Playwright/`, **22** `tests/mobile-e2e/` + `tests/web-e2e/` (configs propres) |
| Soak | commande artisan | `app/Console/Commands/E2ESoakCommand.php:77` | `php artisan foodking:e2e:soak --hours=N` |
| Invariants greps | bash | `scripts/check-invariants.sh` (alias `composer invariants`, composer.json scripts) | 6 greps fail-fast POS |

Autres dossiers vus sous `tests/` : `captures/`, `Support/` (`MysqlOnly.php`), `Feature/Concerns/` (`HasPosQuoteBinding.php`), `CreatesApplication.php`, `TestCase.php`.

---

## 2. phpunit.xml — environnement de test (DB-safe prouvé)

Fichier lu intégralement. Points load-bearing :

- **`DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`** → la suite PHPUnit **ne touche JAMAIS la DB dev MySQL**. `php artisan test --filter=X` est donc DB-safe par construction.
- 3 testsuites : `Unit`, `Feature`, `Load` (tests/load, tag stress, commentaire F-017 Suite 7 « rush midi »).
- `<groups><exclude><group>manual</group></exclude></groups>` — les tests annotés `@group manual` (préconditions V1 non réunies, ex. mapping allergènes signé chef) sont exclus du run CI (décision owner GOAL-D10 2026-05-23, commentée dans le XML).
- `memory_limit=512M` (la suite Feature complète déborde les 128M par défaut).
- Env figé : `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`, `BROADCAST_DRIVER=log`, `SESSION_DRIVER=array`, `PUSHER_APP_KEY/SECRET/ID=""` (court-circuite DispatchDomainEventsJob, commentaire POS-9.4.BL), `TELESCOPE_ENABLED=false`.
- Secrets fiscaux de test 48 chars : `FISCAL_AUDIT_SECRET` / `FISCAL_Z_REPORT_SECRET` — hors `fiscal.dev_sentinels`, donc rejetés en prod par `assertProductionSafe()` (commentaire in-file).
- Kiosk : `KIOSK_MACHINE_USERNAME=kiosk-lecayenne` / `KIOSK_MACHINE_PASSWORD=kiosk123`, `KIOSK_USE_POS_WIZARD=false`.

`tests/TestCase.php` : injecte `x-api-key` (aligné `MIX_API_KEY=test-api-key`) + `Accept: application/json` sur toutes les requêtes ; `seedMinimalSettings()` évite le crash « faviconLogo on null ». **581 fichiers Feature utilisent `RefreshDatabase`** (grep -rl).

`tests/Support/MysqlOnly.php` existe (trait pour tests nécessitant MySQL réel, ex. triggers).

### Commandes de run réelles
- Backend complet : `php artisan test` (sqlite :memory:, safe).
- Filtre ciblé par système : `php artisan test --filter=Pos` / `--filter=Fiscal` / `--filter=BranchScopeCoverageSentinelTest` — **safe pour la DB dev** (connexion forcée sqlite :memory: par phpunit.xml).
- Frontend : `npm test` = `vitest run` (package.json scripts) ; watch = `npm run test:watch`.
- E2E : `npm run test:e2e:full` (tout Playwright) / `npm run test:e2e:smoke` (5 specs : 01-auth-refresh, 02-pos-cash, 03-kiosk-wizard, 04-kds-status, stock-rupture-sync).
- Invariants : `composer invariants` → `scripts/check-invariants.sh` (6 greps POS, source POS_INVARIANTS_AND_GATES.md §3).
- Lint métier : `npm run pos:lint:pricing`, `pos:lint:status`, `i18n:audit`, `perf:bundle-check` (package.json).
- Soak : `php artisan foodking:e2e:soak --hours=4 --output-dir=...` (`E2ESoakCommand.php:45-51`, RSS growth ≤ 200MB surveillé :24).

⚠️ Les specs Playwright, elles, tournent contre le serveur réel (`php artisan serve` :8000, DB dev/e2e réelle) — NON DB-safe, à distinguer du filtre PHPUnit.

---

## 3. Suites Feature par système (comptages ipath cette session)

| Système | Fichiers matchés | Dossiers/suites clés vus |
|---|---|---|
| POS | 100 | `tests/Feature/Pos/` (25 fichiers : PosCashTrailTest, SplitPaymentEndToEndTest, PosOrderRequestNoClientTotalsTest, CounterCollectQueueRobustTest, PosTicketBytesEndpointTest, TerminalIdWireInTest, PosSimulationHardware4ScenariosTest…) + racine PosPricingSsotProofTest.php, PosParkedOrderTest.php |
| Kiosk | 64 | `Kiosk/` (5), `KioskPhase1/5/7/`, `KioskSecurity/`, `KioskMultiBranch/`, racine KioskAuthTest, KioskBundleLockdownTest, KioskLoyalty*… |
| KDS | 32 | `KDS/` (11) + racine Kds* (KdsTransitionWhitelistTest, KdsChangeStatusConcurrencyTest, KdsRecallCapNTest…) |
| Branch/isolation | 48 | `Branch/` (6 : BranchScopeCoverageSentinelTest, OrderBranchIsolationTest, BranchDeactivationTokenRevokeTest…), BranchIsolationTest.php, BranchScopeTest.php racine |
| Cash | 31 | `Cash/` (12 : CashAuditLogChainTest, CashDrawerConcurrentSessionTest, CashMovementsDeleteForbiddenTest, CashVarianceGateTest…) |
| Fiscal/NF525 | 58 | `Fiscal/` (21+ : AuditLogHashChainTest, AuditLogImmutabilityTest, FiscalSealingHmacTest, FiscalAllocOrphanRetryTest, FiscalArchiveVerifyChainTest, CompositionSnapshotImmutabilityTriggerSentinel…) |
| Composer | 25 | `Composer/` (19 : ItemWizardStepVersionImmutabilityTest, ProfilePublishMidCartRejectionTest, ComposerPublishSyncTest…) |
| Boot | 1 | `Boot/ProductionBootGuardsCompletenessSentinelTest.php` |
| Uber | 1 | `Uber/UberIntegrationTest.php` (fondation OAuth/webhook HMAC) |

Autres dossiers notables (ls maxdepth 1) : Idempotency/, Loyalty/, Delivery/, Frontend/, Outbox/, Sync/, Webhooks/, Reconciliation/, Refund/, Security/, Stock/, Pricing/, OrderPipeline/, Deploy/, ProdLike/, Migration(s)/, TimeZone/, I18n/.

---

## 4. Sentinelles baseline-lock (garde-fous anti-régression)

`grep -rl Sentinel tests/` → **~230 fichiers PHP** (liste complète vue). Les plus load-bearing :

- **`tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`** — baseline-lock BranchScope : « count GROWS → CI fails » (:41). Exemptions `BASELINE_V1_2026-05-18` listées :56-62+ (FrontendDiningTable, ZReport, AuditLog, OrderDiscountLog, Message, DiningTableAuditLog, KioskPromo…) = backlog V1.0.2 heal C-P0-D.
- **`tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:65`** — `private const RETURN_TRUE_BASELINE = 66;` (⚠️ CLAUDE.md §9 dit encore « baseline 69 » — le code réel est déjà ratcheté à 66 ; historique 77→74→69→66 commenté :33-38).
- **`tests/Feature/Sentinels/FrozenZoneSha256BaselineSentinelTest.php`** — hash SHA-256 de chaque fichier frozen vs `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` (:33), refuse update sans `LOCK_*.md` owner (:27), exige ≥14 entrées (:79, anti-neutering).
- **`tests/Feature/Boot/ProductionBootGuardsCompletenessSentinelTest.php`** — complétude des boot guards prod.
- Famille F001–F014 (`tests/Feature/Sentinels/F00*…`) : invariants fiscal-sequence kiosk, cash reconciliation, cancel-reason, idempotency parity POS, counter-deferred kiosk cash, finalize state guard…
- `Sentinels/CommittedSecretsScanSentinelTest.php`, `PosSimulationHardwareProductionGuardSentinelTest.php`, `TpeSimulationDepthSentinelTest.php`, `ClientTotalWriteForbiddenSentinelTest.php`, `PosSubtotalForgerySentinelTest.php`, `ClaudeMdBranchScopeCountSentinelTest.php` (le CLAUDE.md lui-même est sentinellisé).
- Unit : `tests/Unit/Listeners/KioskCacheInvalidationWiringSentinelTest.php`.

### Sentinelles JS (Vitest) — `tests/js/sentinels/` (56 fichiers, ls complet vu)
- **Bundle freshness** (anti-bundle-stale) : `appBundleFreshnessSentinel.spec.js` (compare mtime sources → `public/js/app.js`), + jumeaux `posAppBundleFreshnessSentinel`, `kdsBundleFreshnessSentinel`, `adminShellBundleFreshnessSentinel`, `adminReportsBundleFreshnessSentinel`. Fix documenté in-file : `npm run development`.
- Frozen-adjacent : `PaymentComponentPropMutationSentinelTest.spec.js`, `paymentComponentEmitsJsdocList.spec.js`.
- f002/f004/f008/f014 kiosk payment/cancel sentinels, `posCounterCollectModalSentinel`, `counterCollectFrDecimalSentinel`, `counterCollectKeypadFreshEntrySentinel` (récents, encaissement), `telemetryAllowlistSentinel`, `cspMigratedToHttpHeader`.

### Sentinelles Playwright
- `tests/e2e/sentinels/cv1-fraunces-loaded-correctly-2026-05-08.spec.js` ; `tests/Playwright/sentinels/kioskCbTrOfflineRefused.spec.js`.

---

## 5. Vitest — config et périmètre

`vitest.config.mjs` (lu intégralement) :
- `environment: 'happy-dom'`, `globals: true`, include **`tests/js/**/*.spec.js`** uniquement (PAS `resources/js/tests/` — ce dossier est ABSENT (vérifié : grep/ls vides)).
- `setupFiles: ['./tests/js/kioskRtl-require-context-polyfill.js']` (polyfill `require.context` webpack pour i18n.js).
- `resolve.extensions` élargi (`.vue` etc.) pour matcher la résolution webpack (commentaire L5.1).
- Plugin `@vitejs/plugin-vue`.

Sous-dossiers `tests/js/` : `sentinels/` (56), `a11y/`, `quickwins/`, `__fixtures__/` + ~245 specs racine couvrant kiosk (80+), pos (50+), kds (25+), composer, ws/realtime, oss.

---

## 6. Playwright — config et corpus

`playwright.config.js` (60 premières lignes lues) :
- `baseURL` = `PLAYWRIGHT_BASE_URL` ou `http://localhost:8000` ; webServer auto : `php artisan serve --host=127.0.0.1 --port=8000` avec `reuseExistingServer: true` (désactivable `PLAYWRIGHT_NO_WEB_SERVER=1`).
- `testDir: './tests'`, testMatch `e2e/**`, `playwright/**`, `Playwright/**` ; `globalSetup: tests/Playwright/global-setup.js`.
- **`workers: 1`** (anti login-lockout 429, commenté), `timeout: 600_000`, `retries: 1`.
- Configs séparées : `tests/mobile-e2e/playwright.config.js`, `tests/web-e2e/playwright.config.js`.

Corpus `tests/e2e/` (300 specs) = mélange de **specs stables** (01–09 smoke, kiosk-happy-path, pos-happy-path, multi-branch-isolation, zone1-fiscal-convergence…zone7, test-e2e-abuse-A→P) et de **~150+ specs datées jetables** (préfixe `_`, `wave-*`, `audit-*`, `iter15-*`, datées 2026-05-06→06-04) — archéologie de sessions passées. Sous-dossiers : `__fixtures__/`, `__screenshots__/`, `helpers/`, `design/`, `kiosk-full-process/`, `pos-full-process/`, `max-test-2026-05-28/`, `scripts/`, `super6-seed.php`.
- `tests/mobile-e2e/` : 22 specs loyalty (01–15 + adversarial A1–A5).
- Nettoyage fixtures : `tests/Feature/Sentinels/PlaywrightFixtureCleanupCommandTest.php` (commande de cleanup testée).
- `.playwright-mcp/` : 126 entrées (captures YAML de sessions MCP ; le git status montre des suppressions massives en cours sur cette branche).

---

## 7. Artefacts / rapports

- `tests/captures/` : 1 seul dossier vu — `abuse-J-livreur-cash-2026-06-02`.
- `reports/test-e2e/` : nombreux dossiers ; les plus récents par mtime : `validation-profonde-2026-06-10`, `pre-cloud`, `dashboard-excellence-2026-06-08`, `dashboard-deep-2026-06-08`, `client-web-mobile-deep-2026-06-08`, `frontends-abuse-2026-05-30`. → dernier cycle e2e formellement rapporté = **2026-06-10** ; le travail récent (2026-06-23→07-01, encaissement/tickets/Uber) est rapporté ailleurs (reports/handoff, memory), pas dans reports/test-e2e.

---

## 8. Risques préliminaires (observations à vérifier par les vagues suivantes — PAS des findings)

1. **Drift doc↔code** : CLAUDE.md §9 dit sentinel FormRequest baseline=69 ; le code dit 66 (`FormRequestAuthzDriftSentinelTest.php:65`). Doc à rafraîchir (le ratchet a déjà été fait).
2. **Corpus e2e pollué** : ~150+ specs datées jetables dans `tests/e2e/` ; `npm run test:e2e:full` les exécute toutes (testMatch `e2e/**`) → run complet probablement très long/fragile ; seul `test:e2e:smoke` est un signal fiable.
3. **Couverture PHP Uber minimale** : 1 seul fichier (`Uber/UberIntegrationTest.php`) pour toute la fondation Uber Eats — jeune, à densifier.
4. **tests/load** : 1 seul test stress (RushMidiSimulationTest) ; suite `Load` incluse par défaut dans `php artisan test` (phpunit.xml).
5. **Écart sqlite/MySQL** : suite en sqlite :memory: ; les invariants MySQL-only (triggers DELETE audit_logs) reposent sur `tests/Support/MysqlOnly.php` + `Migration/SqliteMysqlParitySentinel.php` — vérifier qu'ils tournent réellement quelque part.
6. **Freshness bundles** : 5 sentinelles bundle-freshness = symptôme récurrent connu (bundles gitignorés/stale VPS, cf. leçons mémoire) ; le harnais le couvre mais uniquement en local.

## 9. Questions ouvertes

- Où/quand tourne la CI complète (GitHub Actions référencé par check-invariants.sh — `.github/workflows/ci.yml` non lu cette session) ?
- Les specs Playwright stables vs jetables : existe-t-il une liste canonique au-delà de `test:e2e:smoke` ?
- Le count Vitest exact au run (~2092 attendu, 2067 `it(` grep) — 1 fail focus-visible pré-existant connu (memory).
