# Massive Audit POS + Kiosk Global Post-Orchestration - 2026-04-26

GLOBAL_AUDIT_VERDICT: HOLD_NOT_RELEASE_READY
TECHNICAL_VERDICT: PASS_WITH_M13_SCHEMA_GATE
GOVERNANCE_VERDICT: FAIL_OPEN_PHASE_A_AND_DIRTY_WORKTREE
EXECUTE_DELEGATION_OBSERVED: codex-extension
AUDIT_SCOPE: POS, Kiosk, KDS, outbox K-09B, payment, pricing SSOT, branch isolation, quote HMAC, idempotency, loyalty, frontend tests, release gates, memory/governance.

## Executive Verdict

Les corrections POS + Kiosk non-schema sont techniquement proches du vert : les suites JS, E2E, outbox, quote, loyalty, idempotency et branch isolation passent. Le blocage dur restant cote code est volontaire et documente le gate M-13 : il manque encore une contrainte DB unique couvrant `(branch_id, queue_number)`.

Ce depot n'est pas release-ready. Le worktree reste massif et non auditable en production : 140 fichiers modifies/staged, 447 untracked, Phase A non close, quote subsystem partiellement untracked, memory episodes non versionnes, et deux cycles actifs dans `.cursor/ACTIVE_CYCLE.md`. La conclusion correcte est donc : code local majoritairement stabilise, release en HOLD.

## Sources Et Preuves Lues

| Surface | Evidence |
| --- | --- |
| Cycle actif | `.cursor/ACTIVE_CYCLE.md:7-13` declare W10 comme `ACTIVE_PRIMARY`, puis `.cursor/ACTIVE_CYCLE.md:29-37` declare aussi `CAISSE_V1_MASTERPLAY (ACTIVE)` |
| Queue masterplay | `plans/masterplay/MASTERPLAY_QUEUE.md:78-91` garde release en HOLD / freeze / FR-03 warnings |
| Statut anterieur Codex | `reports/audit/POS_KIOSK_PLAN_COMPLETION_STATUS_2026-04-26.md:1-69` |
| Branch/worktree | branch `cycle/CV1-FIX-ORDERQUOTE-BRANCH-FORGED-IGNORE`, HEAD `f7694563a`; status = 140 modified/staged, 447 untracked |
| Quote/HMAC | `app/Services/Order/OrderQuoteService.php:34-98`, `app/Services/Order/OrderQuoteService.php:294-319`, `app/Services/Order/OrderQuoteService.php:356-402` |
| Kiosk branch force | `app/Http/Requests/OrderRequest.php:29-37`, `app/Services/FrontendOrderService.php:164-179`, `app/Services/Order/OrderQuoteService.php:137-149` |
| Idempotency branch scope | `app/Services/OrderService.php:1046-1058`, `app/Services/OrderService.php:2195-2204`, `app/Services/FrontendOrderService.php:591-600` |
| Loyalty kiosk | `app/Services/FrontendOrderService.php:692-824` |
| POS show branch guard | `app/Http/Controllers/Admin/PosOrderController.php:51-56`, `app/Services/OrderService.php:1369-1380`, `app/Services/OrderService.php:2133-2143` |
| Payment confirm | `app/Http/Controllers/Frontend/OrderController.php:88-170` |
| Route quote kiosk | `routes/api.php:903-907`, `app/Http/Controllers/Admin/PosController.php:42-63` |
| Queue number sentinel | `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php:16-21` |
| Kiosk machine schema | `database/migrations/2025_02_21_110459_create_kiosk_machines_table.php:17-31` |

## Validation Locale Fraiche

| Commande | Resultat |
| --- | --- |
| `php artisan test` | 1075 passed, 8 skipped, 1 failed. Seul fail : `QueueNumberUniquenessSentinelTest` manque l'index unique `(branch_id, queue_number)` |
| `npx vitest run` | 126 files passed, 853 tests passed |
| `npx vitest run tests/js/kiosk*.spec.js` | 55 files passed, 398 tests passed |
| `npx playwright test` | 35 passed |
| `bash scripts/lint-fk-bundle-legacy.sh strict` | exit 0, warnings release sur `public/js/kiosk.js` et `public/js/kiosk-wizard.js` |
| `git diff --check ...` | PASS sur surfaces POS/Kiosk/audit ciblees |

## Matrice Mission POS + Kiosk

| Mission | Etat technique | Etat git/gouvernance | Verdict |
| --- | --- | --- | --- |
| R4 kiosk offline queue idempotency | Vert : 398 kiosk Vitest pass; localKey/offlineKey separes | Merge observe via `a8052f681` dans l'historique | PASS |
| R6 kiosk machine forced branch | Vert : branch_id payload force depuis machine | Merge observe via `272393d4b`; code dans `OrderRequest` + `FrontendOrderService` | PASS |
| #3 idempotency recovery branch scope | Vert : full PHP inclut `IdempotencyRecoveryBranchScopedTest` 4 pass | Commit `096aaab7d` dans la chaine | PASS |
| #4 loyalty double redeem | Vert : pending redeem attache sans second debit | Commit `f7694563a` | PASS |
| #5 loyalty ledger atomic | Vert : ledger failure rollback par transaction existante | Commit `f7694563a` | PASS |
| #6 order quote branch forged ignore | Vert fonctionnel : quote kiosk force branch machine | `OrderQuoteService.php` est untracked | TECH_PASS / GOV_HOLD |
| #7 kiosk quote token required | Vert fonctionnel : `OrderRequest` + service exigent token/signature | quote subsystem/migration untracked | TECH_PASS / GOV_HOLD |
| #8 queue-number unique migration | Non execute | Gate humain D-M13 absent | BLOCKED_P0 |
| #9 POS quote-binding legacy tests | Vert : full PHP POS quote-binding passe | plusieurs tests/helper non stabilises governance | TECH_PASS / GOV_HOLD |
| #10 outbox fixtures K-09B | Vert : outbox/Event/KioskRealtime passent | tests locaux non tous persistables tant que Phase A ouverte | TECH_PASS / GOV_HOLD |

## Invariants FoodKing

| Invariant | Verdict | Details |
| --- | --- | --- |
| Backend pricing SSOT | PASS | POS/Kiosk passent `PricingService`; quote canonicalise les totaux; tests `PricingService*`, `PosPricingSsotProofTest`, `PosKioskPricingParityTest`, `QuoteTamperTest` passent |
| branch_id isolation | PASS_WITH_RESIDUALS | Store kiosk force `KioskMachine.branch_id`; POS show re-garde apres `withoutGlobalScope`; KDS/OSS branch tests passent. Residuals : quote endpoint kiosk ne verifie pas explicitement `abilities:kiosk:order` ni status machine |
| Idempotency | PASS | Recovery POS + Kiosk scope par `branch_id`; migration composite `orders_branch_id_idempotency_key_unique` existe; sentinels passent |
| Quote HMAC / pinning | PASS_WITH_GOV_HOLD | Token/signature requis sur POS + kiosk commit; replay expire en 410. Mais service/model/migration quote sont encore untracked |
| Loyalty kiosk | PASS | Double redeem evite; ledger creation rollback dans transaction; tests loyalty passent |
| Outbox K-09B | PASS | Contrat `_origin`, `payment_method`, `queue_number` valide; EventContract/outbox tests passent |
| Dispatch after commit | PASS | `AfterCommitDispatchTest` et `DispatchAfterCommitTest` passent |
| OrderStatus enum | PASS | State machine tests passent; pas de regression observee dans flux KDS/POS |
| Payment | PASS_WITH_RESIDUAL | Kiosk payment-confirm protege ability/cross-branch/duplicate TPE; PaymentService generique reste idempotent par order_id, pas par `transaction_no` global |
| Frontend Kiosk/POS | PASS_WITH_WARNINGS | Vitest + Playwright verts; warnings de harnais Vuex/i18n/fetch et legacy bundle kiosk restent a nettoyer avant release stricte |

## Findings Bloquants Et Residuals

### P0-HOLD-01 - Queue number sans contrainte DB

`php artisan test` echoue uniquement sur `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php:18`. Les migrations actuelles ajoutent `queue_number` (`database/migrations/2026_03_06_170846_add_queue_number_to_orders_table.php`) mais aucune contrainte unique `(branch_id, queue_number)` n'est presente. Tant que D-M13 n'est pas signe, la protection concurrente reste applicative et non garantie par la base.

Action requise : signer D-M13, choisir index partial/full + locking strategy + backfill/rollback, puis implementer la migration et rerun `php artisan test`.

### P0-GOV-02 - Phase A non close / worktree non auditable

Etat observe : 140 modified/staged, 447 untracked. Les artefacts quote critiques sont untracked : `app/Models/OrderQuote.php`, `app/Services/Order/OrderQuoteService.php`, `database/migrations/2026_04_25_190000_create_order_quotes_table.php`. Un clone propre ou une CI sans ces fichiers ne peut pas reproduire le comportement local.

Action requise : Phase A persistence bucket par bucket, puis `CLOSED_VS_GIT` jusqu'a `REWORK_NOT_PERSISTED: 0`.

### P0-GOV-03 - Cycles actifs contradictoires

`.cursor/ACTIVE_CYCLE.md:13` dit `ACTIVE_PRIMARY: CYCLE_W10_EXECUTION_CLOSEOUT`, mais `.cursor/ACTIVE_CYCLE.md:29` garde aussi `CAISSE_V1_MASTERPLAY (ACTIVE)`. Ce n'est pas un bug produit, mais c'est un bug d'orchestration : les outils peuvent router une mission vers le mauvais contexte.

Action requise : un seul primary actif; archiver explicitement l'autre dans `.cursor/ACTIVE_CYCLE_ARCHIVE.md`.

### P1-SEC-04 - Quote kiosk route moins stricte que store kiosk

`routes/api.php:903-907` expose `/api/frontend/order/quote` avec `auth:sanctum` et throttle, sans middleware `abilities:kiosk:order`. `OrderQuoteService::resolveBranchId()` force la branche machine, mais ne verifie que l'existence d'une `KioskMachine` (`app/Services/Order/OrderQuoteService.php:139-148`), pas l'ability du token ni le status actif. Le commit store est plus strict via `OrderRequest.php:95-109`.

Impact : une machine inactive ou un token sanctum non conforme peut potentiellement obtenir une quote, meme si le commit final est bloque. Ce n'est pas une fuite de commande, mais c'est une incoherence auth surface.

Action recommandee : sentinel `KioskQuoteRequiresAbilityAndActiveMachineTest`, puis enforcement ability/status cote quote.

### P1-SCHEMA-05 - `kiosk_machines` sans unicite `(branch_id, machine_id)`

La migration `database/migrations/2025_02_21_110459_create_kiosk_machines_table.php:17-31` declare `machine_id`, mais pas d'index unique par branche. Le login/token flow fonctionne, mais l'identite machine reste duplicable au niveau DB.

Action recommandee : gate schema leger ou migration avec backfill pour dedupliquer avant unique `(branch_id, machine_id)`.

### P2-CONCURRENCY-06 - Lock quote sans transaction explicite sur endpoint `/quote`

`OrderQuoteService::resolveReplay()` et `findOpenQuote()` utilisent `lockForUpdate()` (`app/Services/Order/OrderQuoteService.php:294-330`). Sur le commit POS/Kiosk, ces appels sont couverts par une transaction externe (`OrderService` / `FrontendOrderService`). Sur l'endpoint quote direct (`app/Http/Controllers/Admin/PosController.php:42-63`), il n'y a pas de transaction explicite autour de `quote()`. Risque limite : doublons de quote ouverte ou course de consommation si `consume` est appele hors commit.

Action recommandee : envelopper `quote()` dans `DB::transaction()` au niveau service pour rendre le verrou vrai partout, avec test de replay concurrent.

### P2-VALIDATION-07 - Kiosk commit bypass la validation variation FormRequest

`OrderRequest.php:111-113` retourne avant `validateOrderItemVariationsAfter()` pour les commandes kiosk/takeaway sous token kiosk. Le pricing/quote pinning et `PricingService` rejettent les incoherences critiques, mais la symetrie de validation FormRequest reste moins stricte que web/POS.

Action recommandee : deplacer `validateOrderItemVariationsAfter()` avant le return kiosk, ou prouver par sentinel que le couple quote + PricingService couvre tous les cas min/max/repeat.

### P2-PAYMENT-08 - Idempotence gateway generique partielle

`PaymentService::payment()` deduplique par `order_id` (`app/Services/PaymentService.php:19-32`) mais pas par `transaction_no`. Le chemin kiosk TPE a sa propre protection cross-order (`Frontend/OrderController.php:142-149`) et les tests passent. Le service generique reste une dette si une integration gateway publique le reutilise.

Action recommandee : decision produit avant migration unique globale `transaction_no` ou guard applicatif par gateway/surface.

### P2-UX-09 - Reorder POS expose les prix historiques

`PosOrderController::reorderItems()` renvoie `unit_price`, `total_price`, variation/extras prices historiques (`app/Http/Controllers/Admin/PosOrderController.php:130-147`). C'est acceptable si la UI re-quote avant commit, mais dangereux si un chemin front affiche ces valeurs comme actuelles.

Action recommandee : sentinel front/back garantissant que reorder est display-only et que le commit repasse toujours par quote SSOT.

### P2-RELEASE-10 - Warnings JS et legacy bundle

Vitest est vert, mais les sorties montrent encore des warnings de harnais : getters/actions Vuex inconnus, cles i18n kiosk promo absentes, fetch localhost en happy-dom, baseline-browser-mapping obsolete. Le lint legacy strict sort 0, mais avertit que le mode release `FK_LEGACY_STRICT_POS_WIZARD=1` bloquera encore `public/js/kiosk.js` et `public/js/kiosk-wizard.js`.

Action recommandee : corriger le harnais de test et trancher humainement shim vs purge legacy kiosk JS avant release.

## Release Gate Matrix

| Gate | Etat | Preuve |
| --- | --- | --- |
| Full PHP | BLOCKED_EXPECTED | 1 fail M-13, 1075 pass |
| Full Vitest | PASS | 853 pass |
| Kiosk Vitest | PASS | 398 pass |
| Playwright | PASS | 35 pass |
| Legacy bundle strict | PASS_WITH_WARNING | exit 0; release mode plus strict encore a trancher |
| D-M13 queue unique | NOT_SIGNED | sentinel rouge |
| Phase A persistence | NOT_CLOSED | 140 modified/staged, 447 untracked |
| Quote subsystem persistence | NOT_CLOSED | service/model/migration untracked |
| Memory policy | NOT_CLOSED | 14 tracked JSONL vs 27 untracked memory episodes observes |
| Active primary unique | NOT_CLOSED | W10 + Caisse V1 actifs |
| Hardware/UAT borne physique | NOT_PROVEN_THIS_AUDIT | Playwright OK, pas de trace lab physique relue ici |

## Conclusion Technique

Le code local couvre tres largement les deux mega-plans POS + Kiosk : les fixes majeurs R4, R6, idempotency branch-scope, loyalty double-redeem, ledger atomic, quote forged branch ignore, quote token required, POS quote-binding et outbox K-09B sont verifies par tests locaux. Le seul fail backend restant est le fail attendu du gate M-13.

La conclusion release reste HOLD. Pour passer de "machine locale stabilisee" a "machine de guerre fonctionnelle livrable", l'ordre dur est :

1. Signer D-M13 et implementer l'unicite `(branch_id, queue_number)`.
2. Fermer Phase A : persister ou purger les 140 modified/staged + 447 untracked.
3. Versionner ou rollback explicitement le subsystem quote (`OrderQuote`, service, migration).
4. Corriger quote route ability/status machine.
5. Trancher active primary unique et memory episodes policy.
6. Rerun `php artisan test`, `npx vitest run`, `npx playwright test`, lint legacy release-mode.

FINAL_AUDIT_POSITION: 100_PERCENT_LOCAL_AUDIT_DONE_BUT_RELEASE_HELD_BY_M13_AND_GOVERNANCE.
