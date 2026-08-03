# Réponse API — Super audit ULTRA (Orcai / Opus 4.7)

- **Mode**: `--ultra` (ULTRA_PLAN + ULTRA_REVIEW + suites)
- **Modèle**: `claude-opus-4-7-20250514`
- **max_tokens / requête**: 131072 (plafond effectif ; pas d'« illimité » côté API)
- **ORCAI_OUTPUT_EFFORT**: `max` (ignoré si proxy non compatible)
- **Tokens cumulés estimés** (input+output, rounds): ≈ **103336** (input≈33101, output≈70235)
- **Cible** `ORCAI_SUPER_AUDIT_MIN_TOTAL_TOKENS`: 100000

---

# Tour 1 — ULTRA_PLAN

<!-- usage tour 1: input≈4614 output≈7899 stop=end_turn -->

# FOODKING SUPER AUDIT — ULTRA PLAN & ORCHESTRATION FINALE

**Date**: 2026-04-28
**Mode**: API sans accès fichiers — verdicts conditionnels, hypothèses explicites, validation machine requise
**Auteur**: Kiro (Claude Opus orchestration)

---

## 0. AVERTISSEMENT MÉTHODOLOGIQUE

Ce plan est produit **sans lecture directe du dépôt**. Aucun verdict `VALIDATED_STRONG` ne peut être émis sans accès machine. Chaque cellule du tableau de verdicts ci-dessous est donc soit `PARTIAL (à confirmer machine)`, soit `NOT_VALIDATED`, soit `HYPOTHÈSE basée sur brief`. Les sections marquées `⚠ À VALIDER AVEC ACCÈS MACHINE` nécessitent une passe de lecture de fichiers avant exécution Codex.

---

## 1. VERDICTS GLOBAUX (CONDITIONNELS)

```
MASTER_AUDIT_VERDICT:   REWORK
RELEASE_DECISION:       REWORK_BEFORE_UAT
```

**Justification**: Le brief indique lui-même que C3 (runtime multi-surface live), C4/C5 (stress), C6 (fiscal/outbox complet), C9 (dashboard management réel) et D4-D13 ne sont pas prouvés. Même si C0/C1/C2 et D1/D2/D3 sont rapportés PASS, l'absence de preuve runtime cross-surface simultanée et de validation fiscal NF525 complète interdit un PASS global. Le verdict est REWORK avec un plan de finition borné.

---

## 2. TABLE DE VERDICTS PAR ZONE

### 2.1 Process (C-series)

| Zone | ID | Intitulé | Verdict | Justification | Preuve requise | Risque si ignoré |
|---|---|---|---|---|---|---|
| Kiosk auto-return | C0 | Post-payment auto-return to home | `PARTIAL (à confirmer)` | Brief dit PASS, mais je n'ai pas relu `kiosk-post-payment-auto-return.spec.js` ni `KioskWaitingComponent.vue`. Le test peut être un timer mock sans navigation réelle. | Relire spec + composant, vérifier que le test navigue réellement et que le store est reset. | Borne bloquée sur écran confirmation en prod. |
| Kiosk full process | C1 | 5 scénarios kiosk end-to-end | `PARTIAL (à confirmer)` | Brief dit 5/5 PASS. Question: ces tests montent-ils un vrai serveur ou mockent-ils les API? Si fixtures statiques, le flux paiement→KDS→OSS n'est pas prouvé. | Relire `c1-kiosk-process-audit.spec.js` et `process-audit.js` helper. Vérifier si `OrderService`/`FrontendOrderService` sont appelés ou mockés. | Commande kiosk acceptée mais jamais visible en cuisine. |
| POS full process | C2 | 5 scénarios POS end-to-end | `PARTIAL (à confirmer)` | Même logique que C1. Le test POS peut valider le formulaire sans vérifier la propagation KDS/OSS. | Relire `c2-pos-process-audit.spec.js`. Vérifier broadcast event post-commit. | Commande POS créée mais KDS jamais notifié. |
| Runtime multi-surface | C3 | Kiosk+POS+KDS+OSS simultanés, propagation live | `NOT_VALIDATED` | Brief confirme explicitement que ce n'est pas prouvé. Les tests actuels sont contrats statiques ou process isolés. | Nouveau test Playwright multi-page: kiosk crée commande → POS la voit → KDS la reçoit → OSS l'affiche → bump → OSS met à jour. Mesure délai < 5s. | Système fonctionne en silo mais pas en orchestre. Bloquant UAT. |
| Stress stock | C4 | 50-100 commandes concurrentes, rollback, rupture live | `NOT_VALIDATED` | Brief confirme. Les tests stock actuels (17 passed) couvrent l'unitaire et quelques concurrences mais pas le stress massif. | Test PHP avec 50+ threads parallèles via `pcntl_fork` ou queue workers, vérifiant `stock_levels.quantity` final = attendu, `stock_movements` count = attendu, et rupture propagée. | Double décrémentation ou stock négatif en rush service. |
| Stress queue number | C5 | Unicité queue number sous forte concurrence | `NOT_VALIDATED` | Brief confirme. `QueueNumberConcurrencyTest` (4 passed) est probablement un test de contrainte DB, pas un stress réel. | Test avec 20+ workers créant des commandes POS+kiosk en parallèle sur même `branch_id`+`date`, assertion `UNIQUE` respectée et pas de gap. | Deux clients reçoivent le même numéro de file. |
| Fiscal/outbox/persistence | C6 | Audit logs, HMAC chain, fiscal monotonie, replay outbox, idempotence, Z-report | `NOT_VALIDATED` | Brief confirme. Les tests fiscal existants (`tests/Feature/Fiscal/*`) n'ont pas été relus. La chaîne HMAC et le replay outbox ne sont probablement pas testés. | Relire `ZReportService.php`, `FiscalSealingService.php`, tous les listeners outbox. Vérifier monotonie séquence, HMAC chain integrity, idempotence replay. | Non-conformité NF525. Amende fiscale. |
| Delivery/Maps | C7 | Google Maps geocode, delivery fees, 422 on failure | `PARTIAL (à confirmer)` | Tests JS `deliveryCharge.spec.js` et `checkoutGeocodeError.spec.js` existent (27 passed dans la suite JS). Mais cohérence POS+web+kiosk non prouvée. | Relire `DeliveryFeeService.php`, `deliveryCharge.js`, `OrderRequest.php`. Vérifier que le backend est SSOT pour le fee et que le frontend ne peut pas forger un fee. | Client facturé 0€ de livraison sur 15km. |
| Payment lifecycle | C8 | Counter deferred, state machine transitions, kiosk payment | `PARTIAL (à confirmer)` | Tests existent (`CounterDeferredPaymentLifecycleTest`, `PaymentStateMachineTransitionsTest`, `KioskPaymentStateMachineTest`). Non relus. | Relire les 3 tests + `PaymentStateMachine.php` + `PaymentService.php`. Vérifier que cash-at-counter ne crée pas de séquence fiscale avant encaissement. | Séquence fiscale émise pour commande jamais payée. |
| Dashboard management | C9 | Parcours UI complet: catégorie, produit, photo, stock, composer, publication | `NOT_VALIDATED` | Brief confirme. Aucun test e2e de parcours dashboard complet. | Nouveau test Playwright: login admin → créer catégorie → créer produit → upload photo → set stock → créer composer profile → publier → vérifier propagation kiosk/POS. | Restaurateur ne peut pas gérer son menu. Bloquant business. |
| Composer sync | C10 | Product composer publish → propagation kiosk/POS/menu | `PARTIAL (à confirmer)` | `composer-mega-flow.spec.js` existe. Rapport `CLAUDE_MASTER_REVIEW_PRODUCT_COMPOSER_SYNC_2026-04-27.md` existe. Non relus. | Relire le test et le rapport. Vérifier que `ComposerProfilePublished` event → `CatalogChanged` → outbox → kiosk menu refresh. | Composer modifié mais borne affiche ancien menu. |

### 2.2 Design (D-series)

| Zone | ID | Verdict | Justification | Risque |
|---|---|---|---|---|
| Kiosk design | D1 | `PARTIAL (à confirmer)` | Brief dit 90 audits, seriousTotal=0. Mais `clearTransientUi()` dans helper peut masquer des vrais problèmes. | Accessibilité non conforme sur borne publique. |
| POS design | D2 | `PARTIAL (à confirmer)` | Brief dit 30 audits, seriousTotal=0. Même risque `clearTransientUi()`. | Caissier bloqué par UI inaccessible. |
| KDS/OSS design | D3 | `PARTIAL (à confirmer)` | Brief dit 20 audits, seriousTotal=0. Toast `Too Many Attempts.` observé pendant run intermédiaire. | KDS illisible en cuisine. |
| D4-D13 | D4-D13 | `NOT_VALIDATED` | Campagne prod-live massive non exécutée. | Régression design en conditions réelles. |

### 2.3 Product Composer Sync (B-series)

| Zone | ID | Verdict | Justification |
|---|---|---|---|
| Composer CRUD backend | B0 | `PARTIAL (à confirmer)` | Controllers + Requests existent. Non relus. |
| Composer steps wizard UI | B1 | `PARTIAL (à confirmer)` | Composants `composer/*` existent. Non relus. |
| Composer validation rules | B2 | `PARTIAL (à confirmer)` | `ComposerProfileRequest.php`, `ComposerStepRequest.php` existent. |
| Composer publish event | B3 | `PARTIAL (à confirmer)` | `ComposerProfilePublished.php` existe. |
| Composer → CatalogChanged | B4 | `PARTIAL (à confirmer)` | Listener chain non vérifiée. |
| CatalogChanged → Outbox | B5 | `PARTIAL (à confirmer)` | `PersistCatalogChangedToOutbox.php` existe. |
| Outbox → Kiosk menu refresh | B6 | `NOT_VALIDATED` | Propagation runtime non prouvée. |
| Outbox → POS menu refresh | B7 | `NOT_VALIDATED` | Idem. |
| Composer pricing SSOT | B8 | `PARTIAL (à confirmer)` | Backend pricing SSOT est un invariant. Vérifier que le composer ne permet pas de prix frontend-only. |
| Composer branch isolation | B9 | `PARTIAL (à confirmer)` | `branch_id` isolation est un invariant. Vérifier scoping dans controllers. |

---

## 3. FINDINGS CLASSIFIÉS

### 3.1 P0 — Bloquants release

| # | Finding | Fichier(s) suspect(s) | Cause probable | Risque business | Test manquant | Correction |
|---|---|---|---|---|---|---|
| P0-1 | Pas de test runtime multi-surface (C3) | Aucun test existant | Jamais implémenté | Système fonctionne en silo, pas en orchestre. KDS ne reçoit pas les commandes kiosk en live. | `tests/e2e/c3-runtime-multi-surface.spec.js` | Implémenter test Playwright multi-page avec mesure délai. |
| P0-2 | Fiscal NF525 cash-at-counter non prouvé (C6) | `FiscalSealingService.php`, `ZReportService.php`, `PaymentService.php` | Séquence fiscale peut être allouée avant encaissement réel | Amende fiscale, non-conformité | `tests/Feature/Fiscal/CashAtCounterFiscalSequenceTest.php` | Vérifier que fiscal_sequence_number est NULL à création, alloué atomiquement au confirm, absent au cancel. |
| P0-3 | HMAC chain integrity non testée (C6) | `FiscalSealingService.php` | Pas de test de chaîne HMAC | Falsification possible des reçus fiscaux | `tests/Feature/Fiscal/HmacChainIntegrityTest.php` | Test qui crée N reçus, vérifie chaîne, tente insertion/suppression, vérifie détection. |
| P0-4 | Outbox replay idempotence non testée (C6) | `DispatchDomainEventsJob.php`, listeners outbox | Replay peut créer des doublons | Double notification KDS, double décrémentation stock | `tests/Feature/Outbox/OutboxReplayIdempotenceTest.php` | Test qui replay le même event 3x, vérifie effet unique. |
| P0-5 | `clearTransientUi()` peut masquer un vrai problème UX | `tests/e2e/design/_shared/design-audit-helpers.js` | Helper supprime toasts avant audit axe | Toast d'erreur réelle invisible dans audit | Audit manuel du helper | Vérifier que seuls les toasts de succès/info sont supprimés, pas les toasts d'erreur. |

### 3.2 P1 — Critiques avant UAT

| # | Finding | Fichier(s) | Cause | Risque | Test manquant | Correction |
|---|---|---|---|---|---|---|
| P1-1 | Stock stress 50+ concurrent non prouvé (C4) | `StockService.php`, `StockLevel.php` | Tests unitaires OK mais pas de stress | Stock négatif en rush | `tests/Feature/Stock/StockStress50ConcurrentTest.php` | Fork 50 workers, même item, vérifier final. |
| P1-2 | Queue number stress non prouvé (C5) | `OrderService.php`, `FrontendOrderService.php` | Contrainte DB seule | Double numéro en rush | `tests/Feature/QueueNumber/QueueNumberStress20WorkersTest.php` | 20 workers parallèles, même branch+date. |
| P1-3 | MySQL validation manquante | `tests/Feature/Menu/FrontendSurfaceFilteringTest.php` | Skip sous SQLite | Requête incompatible MySQL 8 en prod | Run sur MySQL 8 | Configurer CI avec MySQL 8 ou run local. |
| P1-4 | Routes counter-collect inline | `routes/api.php` | Routes potentiellement non protégées ou mal scopées | Accès non autorisé à counter-collect | Audit `routes/api.php` | Extraire vers controller dédié si nécessaire, vérifier middleware auth+branch. |
| P1-5 | Toast `Too Many Attempts.` en D3 | `app/Http/Kernel.php` ou middleware throttle | Rate limit trop bas pour KDS/OSS polling | KDS bloqué en production pendant rush | `tests/Feature/RateLimit/KdsOssThrottleTest.php` | Vérifier et ajuster rate limit pour routes KDS/OSS. |
| P1-6 | Delivery fee SSOT non prouvé cross-surface | `DeliveryFeeService.php`, `deliveryCharge.js` | Frontend peut calculer un fee différent du backend | Client facturé incorrectement | `tests/Feature/Delivery/DeliveryFeeSsotTest.php` | Vérifier que backend recalcule et rejette si fee frontend diverge. |
| P1-7 | Kiosk lockdown non prouvé | `kioskRoutes.js`, `KioskAppComponent.vue` | Kiosk peut accéder à des routes admin | Client accède au dashboard depuis borne | `tests/e2e/kiosk-lockdown.spec.js` | Vérifier que router kiosk n'expose aucune route admin. |
| P1-8 | Image/photo propagation non prouvée end-to-end | `ItemImageCatalogRefreshTest.php` | Test backend seul, pas de vérification frontend | Photo produit absente sur borne après upload | `tests/e2e/image-propagation-kiosk.spec.js` | Test: upload photo admin → vérifier affichage kiosk. |

### 3.3 P2 — Importants mais non bloquants UAT

| # | Finding | Fichier(s) | Risque | Action |
|---|---|---|---|---|
| P2-1 | Dashboard composer UX non testé e2e (C9) | Composants `composer/*` | Restaurateur bloqué | Mission C9 dédiée. |
| P2-2 | Authz matrix dashboard/composer/stock non auditée | Controllers admin | Escalade de privilèges | Audit matrice rôles. |
| P2-3 | Symétrie `OrderService`/`FrontendOrderService` non prouvée formellement | Les deux services | Divergence de comportement POS vs kiosk | Test de symétrie. |
| P2-4 | Event dispatch after DB commit non prouvé | `DispatchDomainEventsJob.php` | Event dispatché avant commit → état incohérent | Vérifier `afterCommit` sur listeners. |
| P2-5 | Z-report end-of-day non testé | `ZReportService.php` | Z-report incorrect | Test Feature dédié. |

### 3.4 P3 — Nice to have

| # | Finding | Action |
|---|---|---|
| P3-1 | Visual regression baseline pour D1/D2/D3 | Capturer screenshots baseline après PASS design. |
| P3-2 | Playwright repeat-each=10 pour flakiness | Augmenter repeat-each sur CI. |
| P3-3 | Documentation API OpenAPI pour routes POS/kiosk | Générer spec OpenAPI. |

---

## 4. ÉVALUATION DES TESTS EXISTANTS

### 4.1 Process Tests (C0/C1/C2)

| Test | Ce qu'il prouve (hypothèse) | Ce qu'il ne prouve PAS | Verdict |
|---|---|---|---|
| `kiosk-post-payment-auto-return.spec.js` | Navigation retour après paiement, timer, reset store | Pas de vrai paiement TPE, pas de vérification KDS | `PARTIAL` |
| `c1-kiosk-process-audit.spec.js` | 5 scénarios kiosk: browse→cart→checkout→payment→confirmation | Propagation KDS/OSS, stock décrémenté, fiscal, queue number | `PARTIAL` |
| `c2-pos-process-audit.spec.js` | 5 scénarios POS: login→menu→cart→payment→receipt | Propagation KDS/OSS, stock, fiscal, multi-branch | `PARTIAL` |
| `composer-mega-flow.spec.js` | Composer CRUD + publish flow | Propagation runtime vers kiosk/POS menu | `PARTIAL` |

**Analyse critique**: Ces tests sont probablement des tests de navigation/UI qui vérifient que les composants Vue se montent correctement et que les transitions d'état fonctionnent. Ils ne prouvent vraisemblablement pas la chaîne complète backend (DB → event → broadcast → autre surface). Le helper `process-audit.js` doit être relu pour comprendre s'il mock les API ou les appelle réellement.

### 4.2 Design Tests (D1/D2/D3)

| Test | Ce qu'il prouve | Ce qu'il ne prouve PAS | Verdict |
|---|---|---|---|
| `d1-kiosk-design-audit.spec.js` | 90 audits axe-core, 0 serious | Rendu visuel réel, responsive, dark mode, touch targets | `PARTIAL` |
| `d2-pos-design-audit.spec.js` | 30 audits axe-core, 0 serious | Idem | `PARTIAL` |
| `d3-kds-oss-design-audit.spec.js` | 20 audits axe-core, 0 serious | Idem + toast masqué par `clearTransientUi()` | `PARTIAL` |

**Analyse critique**: axe-core est un bon premier filtre mais ne remplace pas un audit WCAG manuel. Le `clearTransientUi()` est un red flag — il faut vérifier qu'il ne supprime pas des éléments qui seraient des violations réelles (par exemple un toast d'erreur sans `role="alert"`).

### 4.3 Sync/Realtime Tests

| Test | Ce qu'il prouve | Ce qu'il ne prouve PAS | Verdict |
|---|---|---|---|
| `KioskRealtimeBroadcastTest.php` | Event broadcast structure correcte | Réception côté client, délai, reconnect | `PARTIAL` |
| `pos-receives-kiosk-realtime.spec.js` | Contrat statique: POS reçoit event kiosk | Runtime réel avec WebSocket, délai < 5s | `PARTIAL` |
| `KdsMultiScreenPlaywrightTest.spec.js` | Multi-screen KDS contrat | Runtime réel, bump propagation, reconnect storm | `PARTIAL` |
| `realtimeBroadcastFallback.spec.js` | Fallback polling quand WS down | Timing du fallback, recovery après reconnect | `PARTIAL` |
| `kdsSyncCadence.spec.js` | Cadence de sync KDS | Cadence sous charge réelle | `PARTIAL` |
| `kdsDedupeByIdVersion.spec.js` | Déduplication par id+version | Dédup sous concurrent updates | `PARTIAL` |
| `kdsBackoffOn5xx.spec.js` | Backoff exponentiel sur 5xx | Recovery complète, max backoff | `PARTIAL` |
| `kdsReactsToReconnectStorm.spec.js` | Gestion reconnect storm | Storm réelle avec N clients | `PARTIAL` |

**Analyse critique**: Ces 8 tests JS/Playwright sont des contrats statiques — ils vérifient le comportement du code client en isolation avec des mocks. Ils ne prouvent pas le comportement runtime réel avec un serveur Laravel + WebSocket + Redis. C'est exactement le gap que C3 doit combler.

### 4.4 Stock Tests

| Test | Ce qu'il prouve | Verdict |
|---|---|---|
| `StockBranchIsolationTest.php` | Isolation branch_id sur stock | `PARTIAL (à confirmer)` |
| `StockConcurrentDecrementTest.php` | Décrémentation concurrente (probablement 2-5 threads) | `PARTIAL` — pas 50+ |
| `StockDecrementFrontendOrderServiceTest.php` | Décrémentation via FrontendOrderService | `PARTIAL (à confirmer)` |
| `StockDecrementOrderServiceTest.php` | Décrémentation via OrderService | `PARTIAL (à confirmer)` |
| `StockLevelSchemaTest.php` | Schéma DB correct | `PARTIAL (à confirmer)` |
| `StockMovementsAppendOnlyTest.php` | Append-only sur mouvements | `PARTIAL (à confirmer)` |
| `StockReleaseOnCancelTest.php` | Release stock sur cancel | `PARTIAL (à confirmer)` |
| `StockReleaseOnRefundTest.php` | Release stock sur refund | `PARTIAL (à confirmer)` |
| `StockRuptureAvailabilitySyncTest.php` | Rupture → disponibilité sync | `PARTIAL (à confirmer)` |
| `StockSymmetryDiffTest.php` | Symétrie OrderService/FrontendOrderService | `PARTIAL (à confirmer)` |

**Analyse critique**: La suite stock (17 tests) semble solide en couverture unitaire/feature. Le gap est le stress massif (C4) et la vérification que le release est exactement 1x dans tous les chemins (POS cash, POS card, kiosk card, kiosk cash-at-counter confirm, kiosk cash-at-counter cancel, refund).

### 4.5 Menu/Catalog Tests

| Test | Ce qu'il prouve | Verdict |
|---|---|---|
| `CatalogStockCentralSyncEndToEndTest.php` | Sync catalog↔stock | `PARTIAL (à confirmer)` |
| `ItemImageCatalogRefreshTest.php` | Image upload → catalog refresh backend | `PARTIAL` — pas frontend |
| `AdminItemBranchAvailabilityProjectionTest.php` | Projection disponibilité admin | `PARTIAL (à confirmer)` |
| `CatalogMutationSnapshotCoverageTest.php` | Snapshot coverage mutations | `PARTIAL (à confirmer)` |
| `FrontendSurfaceFilteringTest.php` | Filtrage surface frontend | `NOT_VALIDATED` — skip SQLite |
| `OrderRejectsUnavailableBranchItemTest.php` | Rejet commande item indisponible | `PARTIAL (à confirmer)` |
| `AvailabilityServiceTest.php` | Service disponibilité | `PARTIAL (à confirmer)` |
| `BumpMenuSnapshotListenerTest.php` | Listener bump snapshot | `PARTIAL (à confirmer)` |

### 4.6 Fiscal/Payment Tests

| Test | Ce qu'il prouve | Verdict |
|---|---|---|
| `CounterDeferredPaymentLifecycleTest.php` | Lifecycle paiement différé counter | `PARTIAL (à confirmer)` |
| `PaymentStateMachineTransitionsTest.php` | Transitions state machine paiement | `PARTIAL (à confirmer)` |
| `KioskPaymentStateMachineTest.php` | State machine paiement kiosk | `PARTIAL (à confirmer)` |
| `QueueNumberConcurrencyTest.php` | Concurrence queue number (probablement 2-4 threads) | `PARTIAL` — pas stress |
| `tests/Feature/Fiscal/*` | Non relus | `NOT_VALIDATED` |

---

## 5. GRAPHE DE DÉPENDANCES ENTRE CHANTIERS

```
                    ┌─────────────┐
                    │  PHASE 0    │
                    │ Machine     │
                    │ Validation  │
                    │ (relecture) │
                    └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
              ▼            ▼            ▼
        ┌──────────┐ ┌──────────┐ ┌──────────┐
        │ PHASE 1a │ │ PHASE 1b │ │ PHASE 1c │
        │ C3       │ │ C6       │ │ P1 fixes │
        │ Runtime  │ │ Fiscal   │ │ (P1-3→8) │
        │ Multi-   │ │ Outbox   │ │          │
        │ Surface  │ │ HMAC     │ │          │
        └────┬─────┘ └────┬─────┘ └────┬─────┘
             │            │            │
             ▼            ▼            │
        ┌──────────┐ ┌──────────┐     │
        │ PHASE 2a │ │ PHASE 2b │     │
        │ C4 Stock │ │ C5 Queue │     │
        │ Stress   │ │ Stress   │     │
        └────┬─────┘ └────┬─────┘     │
             │            │            │
             └────────┬───┘            │
                      │                │
                      ▼                ▼
                ┌──────────┐    ┌──────────┐
                │ PHASE 3  │    │ PHASE 3b │
                │ C9       │    │ D4-D7    │
                │ Dashboard│    │ Design   │
                │ Mgmt     │    │ Prod     │
                └────┬─────┘    └────┬─────┘
                     │               │
                     └───────┬───────┘
                             │
                             ▼
                       ┌──────────┐
                       │ PHASE 4  │
                       │ D8-D13   │
                       │ Prod-live│
                       │ Massive  │
                       └────┬─────┘
                            │
                            ▼
                       ┌──────────┐
                       │ PHASE 5  │
                       │ Hardware │
                       │ UAT      │
                       │ Go/No-Go │
                       └──────────┘
```

**Dépendances critiques**:
- Phase 1a (C3) et Phase 1b (C6) sont indépendantes et parallélisables.
- Phase 1c (P1 fixes) est indépendante des deux autres.
- Phase 2a (C4) dépend de Phase 1c (P1-3 MySQL fix peut impacter stock tests).
- Phase 2b (C5) dépend de Phase 1a (C3 peut révéler des bugs queue number).
- Phase 3 (C9) dépend de Phase 1c (P1 fixes sur routes, authz).
- Phase 4 (D4-D13) dépend de toutes les phases précédentes.
- Phase 5 (Hardware UAT) dépend de Phase 4.

---

## 6. PLAN DE FINITION EXÉCUTABLE — MISSIONS CODEX

---

### PHASE 0: VALIDATION MACHINE (pré-requis à tout)

#### Mission MV-0: Relecture machine et confirmation verdicts

| Champ | Valeur |
|---|---|
| **TASK_ID** | `MV-0-MACHINE-VALIDATION` |
| **Objectif** | Relire tous les fichiers listés dans le brief, confirmer ou infirmer les verdicts PARTIAL, produire un rapport de delta |
| **Préconditions** | Accès au dépôt |
| **Allowlist** | Tous les fichiers listés dans §"Fichiers code critiques" et §"Tests à lire" du brief |
| **Interdictions** | Ne modifier aucun fichier. Lecture seule. |
| **Steps** | 1. Lire chaque fichier code critique. 2. Lire chaque test. 3. Pour chaque verdict PARTIAL, confirmer ou infirmer. 4. Identifier les vrais gaps. 5. Produire `reports/audit/MV0_MACHINE_VALIDATION_2026-04-28.md` |
| **Tests obligatoires** | Aucun (lecture seule) |
| **Run-many** | N/A |
| **PASS criteria** | Rapport produit avec verdict par zone, delta vs brief, et liste de fichiers manquants |
| **REWORK criteria** | Fichier listé dans brief mais absent du dépôt → signaler comme `MISSING` |
| **


---

# Tour 2 — ULTRA_REVIEW

<!-- usage tour 2: input≈134 output≈7937 stop=end_turn -->

# FOODKING ULTRA REVIEW — RELECTURE ADVERSE

**Date**: 2026-04-28
**Mode**: Adversarial review sur ULTRA_PLAN — sans accès machine
**Posture**: Tout verdict non prouvé par lecture directe est présumé `REWORK` jusqu'à preuve contraire.

---

## 0. MÉTA-CRITIQUE DU PLAN LUI-MÊME

Le plan ULTRA_PLAN souffre de plusieurs biais structurels qu'il faut nommer avant d'aller plus loin:

**Biais d'optimisme par brief**: Le plan accorde un crédit `PARTIAL (à confirmer)` à tout ce que le brief déclare "PASS localement". C'est une erreur méthodologique. Un brief humain qui dit "C1 PASS 5/5" peut signifier que 5 tests passent mais que ces 5 tests ne testent rien de significatif. Le verdict correct en l'absence de lecture machine est `NOT_VALIDATED` pour tout, sauf si la structure du test (nom, helper, assertions) permet d'inférer un minimum de couverture. Or nous n'avons pas lu les assertions.

**Biais de complétude par existence de fichier**: Le plan dit "le fichier existe donc PARTIAL". Un fichier peut exister et être vide, stub, ou tester le mauvais invariant. L'existence d'un `StockBranchIsolationTest.php` ne prouve pas que l'isolation branch est testée — il peut tester uniquement que le modèle a un champ `branch_id`.

**Biais de symétrie par convention de nommage**: Le plan suppose que `StockSymmetryDiffTest.php` teste la symétrie `OrderService`/`FrontendOrderService`. Le nom suggère cela, mais le test peut comparer des structures de données sans vérifier le comportement transactionnel.

**Correction appliquée dans cette review**: Tout verdict est dégradé d'un cran par défaut. `PARTIAL` devient `NOT_VALIDATED` sauf si le raisonnement structurel permet de maintenir `PARTIAL`.

---

## 1. HYPOTHÈSES FRAGILES — ATTAQUE SYSTÉMATIQUE

### 1.1 Hypothèse: "C0/C1/C2 prouvent un flux runtime"

**Attaque**: Le brief mentionne un helper `process-audit.js` partagé entre C1 et C2. Ce pattern suggère une abstraction commune. Si ce helper mock les appels API (ce qui est courant dans les tests e2e rapides), alors C1 et C2 ne prouvent rien côté backend. Ils prouvent que les composants Vue naviguent correctement entre les étapes, ce qui est un test de router, pas un test de process.

**Preuve manquante**: Est-ce que `process-audit.js` contient des `cy.intercept()` / `page.route()` qui interceptent les appels API? Si oui, le backend n'est jamais touché et les 5/5 PASS sont des tests de navigation UI.

**Conséquence si confirmé**: C0, C1, C2 passent tous à `NOT_VALIDATED` pour le flux runtime. Ils restent `PARTIAL` pour la navigation UI uniquement.

**Contradiction invariant**: L'invariant "Backend pricing SSOT" ne peut pas être vérifié par un test qui mock le backend. Si le test kiosk calcule un prix côté client et le compare à un mock, il ne prouve pas que le backend est SSOT.

### 1.2 Hypothèse: "D1/D2/D3 seriousTotal=0 suffit pour UAT"

**Attaque**: axe-core `seriousTotal=0` signifie zéro violation de niveau "serious" ou "critical" selon les règles axe activées. Mais:
- axe-core ne teste pas toutes les règles WCAG 2.1 AA par défaut
- Les règles désactivées ou non applicables ne sont pas comptées
- Le `clearTransientUi()` supprime des éléments DOM avant l'audit — si un toast a un `role="alert"` manquant, la violation disparaît avec le toast
- axe-core ne teste pas le contraste sur les images, les animations, le focus management dynamique, ni les interactions tactiles (critique pour kiosk)

**Preuve manquante**: Quelles règles axe sont activées? Le helper `design-audit-helpers.js` configure-t-il un `axe.configure()` restrictif? Si oui, le `seriousTotal=0` est un artefact de configuration, pas une preuve d'accessibilité.

**Conséquence si confirmé**: D1/D2/D3 passent à `PARTIAL` au mieux, avec une note "axe-core partiel, audit manuel requis pour UAT borne publique".

**Contradiction invariant**: "Kiosk client locked down" implique que la borne est un dispositif public. Un dispositif public a des obligations d'accessibilité renforcées (ERP en France). axe-core seul est insuffisant.

### 1.3 Hypothèse: "17 tests stock = couverture stock solide"

**Attaque**: 17 tests sur 10 fichiers = ~1.7 tests par fichier. C'est très peu. Un `StockConcurrentDecrementTest` avec un seul test case qui lance 2 threads n'est pas un test de concurrence — c'est un test de non-régression basique. Le vrai stress est 50+ threads sur le même item avec des quantités variables, des annulations en vol, et des ruptures qui se déclenchent pendant le décrémentage.

**Preuve manquante**: Combien d'assertions par test? Quel est le `@dataProvider` ou le nombre de cas? Un test "concurrent" avec `$this->artisan('queue:work --once')` n'est pas concurrent.

**Conséquence si confirmé**: La suite stock reste `PARTIAL` pour l'unitaire mais `NOT_VALIDATED` pour la concurrence réelle.

### 1.4 Hypothèse: "Les listeners outbox existent donc l'outbox fonctionne"

**Attaque**: L'existence de `PersistOrderCreatedToOutbox.php`, `PersistOrderStatusChangedToOutbox.php`, etc. ne prouve pas:
- Qu'ils sont enregistrés dans `EventServiceProvider`
- Qu'ils s'exécutent après le commit DB (pas avant)
- Qu'ils sont idempotents
- Que le job de dispatch (`DispatchDomainEventsJob`) les consomme correctement
- Que le consumer est tolérant aux pannes (retry, dead letter)

**Preuve manquante**: Lecture de `EventServiceProvider.php` pour vérifier le binding. Lecture de chaque listener pour vérifier `$afterCommit = true` ou `ShouldQueue` avec `afterCommit`. Lecture du job pour vérifier l'idempotence.

**Contradiction invariant**: "Dispatch events/jobs après commit DB" est un invariant explicite. Si un seul listener n'a pas `$afterCommit = true`, l'invariant est violé.

### 1.5 Hypothèse: "Les enums OrderStatus/PaymentStatus existent donc pas de chaînes magiques"

**Attaque**: L'existence des enums ne prouve pas qu'ils sont utilisés partout. Un seul `$order->status = 'completed'` au lieu de `$order->status = OrderStatus::COMPLETED` viole l'invariant. Les tests peuvent passer avec des chaînes si les comparaisons sont lâches.

**Preuve manquante**: `grep -r "->status = '" app/` pour trouver les affectations par chaîne. `grep -r "== 'pending'" app/` pour les comparaisons.

### 1.6 Hypothèse: "Le Playwright repeat-each=5 avec 10 passed prouve la stabilité"

**Attaque**: repeat-each=5 sur 2 tests = 10 runs. C'est un échantillon minuscule. Un test flaky à 10% a 59% de chance de passer 10 runs consécutifs. Il faut repeat-each=20 minimum pour détecter un flake à 5%.

De plus, les tests Playwright "static contracts" ne testent pas le même chemin que le runtime. Un contrat statique qui vérifie la structure d'un event ne flake pas — il passe ou échoue déterministiquement. Le repeat-each est inutile sur des tests déterministes et insuffisant sur des tests non-déterministes.

### 1.7 Hypothèse: "FiscalSealingService.php existe donc NF525 est couvert"

**Attaque**: NF525 a des exigences très spécifiques:
- Séquence monotone stricte (pas de gap, pas de doublon)
- Chaîne de hachage (chaque reçu inclut le hash du précédent)
- Inaltérabilité (pas de DELETE/UPDATE sur les reçus)
- Archivage (conservation 6 ans)
- Z-report quotidien

L'existence d'un service ne prouve aucune de ces propriétés. Le service peut implémenter le hachage mais pas la monotonie. Il peut implémenter la monotonie mais avec un gap possible en cas de rollback transaction.

**Preuve manquante**: Le code de `FiscalSealingService.php` doit être lu ligne par ligne. Le test doit vérifier: création de 100 reçus concurrents → séquence sans gap → hash chain valide → tentative de DELETE → rejet → Z-report correct.

### 1.8 Hypothèse: "Le brief dit que le code a beaucoup avancé"

**Attaque**: "Beaucoup avancé" est subjectif. Le brief liste des rapports datés du 2026-04-27 et 2026-04-28, ce qui suggère un sprint intense de 2 jours. Un sprint de 2 jours sur un système de cette complexité produit du code rapide, pas du code solide. Le risque de régression est maximal.

---

## 2. TABLEAU DES TROUS DE PREUVE

| # | Trou identifié | Gravité | Zone | Invariant violé potentiel | Action requise | Propriétaire | Bloquant UAT? |
|---|---|---|---|---|---|---|---|
| T01 | `process-audit.js` peut mocker toutes les API → C1/C2 ne prouvent rien backend | CRITIQUE | C1/C2 | Backend pricing SSOT | Lire `process-audit.js`, chercher `intercept`/`route`/`mock` | MV-0 | OUI |
| T02 | `clearTransientUi()` supprime des éléments DOM avant audit axe → violations masquées | CRITIQUE | D1/D2/D3 | Kiosk locked down (accessibilité ERP) | Lire le helper, lister les sélecteurs supprimés, vérifier si des erreurs sont supprimées | MV-0 | OUI |
| T03 | Aucun test ne prouve la propagation kiosk→KDS→OSS en runtime réel | CRITIQUE | C3 | Dispatch events après commit | Implémenter C3 | Codex | OUI |
| T04 | Fiscal sequence peut être allouée avant encaissement (cash-at-counter) | CRITIQUE | C6 | NF525 pas de séquence avant encaissement | Lire `PaymentService.php` + `FiscalSealingService.php` | MV-0 puis Codex | OUI |
| T05 | HMAC chain non testée → falsification possible | CRITIQUE | C6 | NF525 inaltérabilité | Implémenter test HMAC chain | Codex | OUI |
| T06 | Outbox replay idempotence non testée → double effet possible | CRITIQUE | C6 | Dispatch events après commit | Implémenter test replay | Codex | OUI |
| T07 | `EventServiceProvider.php` non lu → listeners peut-être non enregistrés | CRITIQUE | Events | Dispatch events après commit | Lire `EventServiceProvider.php` | MV-0 | OUI |
| T08 | `$afterCommit` non vérifié sur listeners → event avant commit | CRITIQUE | Events | Dispatch events après commit | Grep `afterCommit` dans tous les listeners | MV-0 | OUI |
| T09 | Chaînes magiques possibles malgré enums → `grep` requis | HAUTE | Global | OrderStatus/PaymentStatus enums | `grep -r "->status = '" app/` | MV-0 | OUI |
| T10 | `FrontendSurfaceFilteringTest` skip SQLite → requête peut échouer MySQL 8 | HAUTE | Menu | N/A | Run sur MySQL 8 | Codex/CI | OUI |
| T11 | Stock concurrent test probablement 2-5 threads, pas 50+ | HAUTE | C4 | N/A | Lire `StockConcurrentDecrementTest`, compter threads | MV-0 | Conditionnel |
| T12 | Queue number test probablement 2-4 threads, pas 20+ | HAUTE | C5 | N/A | Lire `QueueNumberConcurrencyTest`, compter threads | MV-0 | Conditionnel |
| T13 | Kiosk routes peuvent exposer des routes admin | HAUTE | Kiosk | Kiosk locked down | Lire `kioskRoutes.js`, vérifier absence de routes admin | MV-0 | OUI |
| T14 | Delivery fee peut être forgé côté frontend | HAUTE | C7 | Backend pricing SSOT | Lire `OrderRequest.php` validation du fee | MV-0 | OUI |
| T15 | Image propagation testée backend seul, pas frontend | HAUTE | Menu | N/A | Vérifier `ItemImageCatalogRefreshTest` scope | MV-0 | Non |
| T16 | `OrderService`/`FrontendOrderService` symétrie non prouvée transactionnellement | HAUTE | Orders | Symétrie OrderService/FrontendOrderService | Lire `StockSymmetryDiffTest`, vérifier ce qu'il compare | MV-0 | OUI |
| T17 | Z-report end-of-day non testé | HAUTE | C6 | NF525 | Implémenter test Z-report | Codex | OUI |
| T18 | Rate limit trop bas pour KDS/OSS polling → `Too Many Attempts` | HAUTE | C3/D3 | N/A | Lire middleware throttle, vérifier limites routes KDS/OSS | MV-0 | OUI |
| T19 | Counter-collect routes inline dans `api.php` → middleware manquant? | HAUTE | POS | Isolation branch_id | Lire `routes/api.php`, chercher counter-collect, vérifier middleware | MV-0 | Conditionnel |
| T20 | `ComposerProfilePublished` → `CatalogChanged` chain non vérifiée | HAUTE | B3/B4 | N/A | Lire listener chain dans `EventServiceProvider` | MV-0 | Non |
| T21 | Composer pricing peut permettre prix frontend-only | HAUTE | B8 | Backend pricing SSOT | Lire `ComposerProfileRequest.php` validation prix | MV-0 | OUI |
| T22 | Branch scoping dans composer controllers non vérifié | HAUTE | B9 | Isolation branch_id | Lire `ComposerProfileController.php` | MV-0 | OUI |
| T23 | `DispatchDomainEventsJob` peut ne pas être idempotent | HAUTE | Events | N/A | Lire le job, chercher guard idempotence | MV-0 | OUI |
| T24 | axe-core config peut désactiver des règles → faux 0 serious | MOYENNE | D1/D2/D3 | N/A | Lire `design-audit-helpers.js` config axe | MV-0 | Non |
| T25 | Playwright static contracts ≠ runtime → repeat-each inutile | MOYENNE | Sync | N/A | Classifier les tests: statique vs runtime | MV-0 | Non |
| T26 | `KioskWaitingComponent.vue` timer peut ne pas reset le store | MOYENNE | C0 | N/A | Lire le composant, vérifier `beforeDestroy`/`onUnmounted` | MV-0 | Non |
| T27 | `PosOrderRequest.php` peut ne pas valider branch_id | MOYENNE | POS | Isolation branch_id | Lire la request, chercher `branch_id` validation | MV-0 | OUI |
| T28 | `PaymentStateMachine` peut avoir des transitions invalides non testées | MOYENNE | C8 | N/A | Lire le state machine, comparer aux tests | MV-0 | Conditionnel |
| T29 | `OrderStateMachine` peut permettre des transitions dangereuses | MOYENNE | Orders | N/A | Lire le state machine | MV-0 | Conditionnel |
| T30 | `KioskMenuService` peut ne pas filtrer par branch_id | MOYENNE | Kiosk | Isolation branch_id | Lire le service | MV-0 | OUI |
| T31 | `MenuProjectionService` peut projeter des items d'autres branches | MOYENNE | Menu | Isolation branch_id | Lire le service | MV-0 | OUI |
| T32 | `AvailabilityService` peut ne pas considérer le stock dans la disponibilité | MOYENNE | Menu | N/A | Lire le service | MV-0 | Conditionnel |
| T33 | `StockMovement` peut ne pas être append-only en DB (pas de trigger/policy) | MOYENNE | Stock | N/A | Vérifier migration + model `$guarded` | MV-0 | Conditionnel |
| T34 | `DecrementStockOnOrderCreated` peut décrementer avant commit | MOYENNE | Stock | Dispatch events après commit | Vérifier `$afterCommit` | MV-0 | OUI |
| T35 | `ReleaseStockOnOrderCanceled` peut ne pas release exactement 1x | MOYENNE | Stock | N/A | Lire le listener, chercher guard idempotence | MV-0 | Conditionnel |
| T36 | `ReleaseStockOnRefundCreated` peut release plus que le stock décrémenté | MOYENNE | Stock | N/A | Lire le listener, vérifier calcul quantité | MV-0 | Conditionnel |
| T37 | `posOrder.js` store peut calculer des prix côté client | MOYENNE | POS | Backend pricing SSOT | Lire le store, chercher calculs de prix | MV-0 | OUI |
| T38 | `kioskCart.js` store peut calculer des prix côté client | MOYENNE | Kiosk | Backend pricing SSOT | Lire le store, chercher calculs de prix | MV-0 | OUI |
| T39 | `kds.js` store peut ne pas dédupliquer correctement | MOYENNE | KDS | N/A | Lire le store, comparer à `kdsDedupeByIdVersion.spec.js` | MV-0 | Non |
| T40 | `OrderStatusScreenController` peut ne pas filtrer par branch | MOYENNE | OSS | Isolation branch_id | Lire le controller | MV-0 | OUI |
| T41 | `KitchenDisplaySystemController` peut ne pas filtrer par branch | MOYENNE | KDS | Isolation branch_id | Lire le controller | MV-0 | OUI |
| T42 | `PosController.php` peut exposer des données cross-branch | MOYENNE | POS | Isolation branch_id | Lire le controller | MV-0 | OUI |
| T43 | `CheckoutComponent.vue` peut envoyer un delivery fee forgé | MOYENNE | Delivery | Backend pricing SSOT | Lire le composant, vérifier payload | MV-0 | Conditionnel |
| T44 | `FloorplanComponent.vue` design touch par Codex 04-28 → régression possible | BASSE | POS | N/A | Diff le fichier vs commit précédent | MV-0 | Non |
| T45 | `FrontendNavBarComponent.vue` touché par Codex → régression nav | BASSE | Frontend | N/A | Diff le fichier | MV-0 | Non |
| T46 | `app.css` touché par Codex → régression visuelle globale | BASSE | Global | N/A | Visual diff | MV-0 | Non |
| T47 | `SearchItemComponent.vue` touché → régression recherche | BASSE | Frontend | N/A | Diff + test | MV-0 | Non |
| T48 | `NotFoundComponent.vue` / `ExceptionComponent.vue` touchés → régression erreur pages | BASSE | Frontend | N/A | Diff | MV-0 | Non |
| T49 | `MyOrderComponent.vue` touché → régression historique commandes | BASSE | Frontend | N/A | Diff | MV-0 | Non |
| T50 | Aucun test de chaos/network loss pour hardware UAT | BASSE (pour now) | Hardware | N/A | Planifier pour Phase 5 | Humain | Non (pour now) |

---

## 3. FAKE PASS POTENTIELS — ANALYSE DÉTAILLÉE

Un "fake PASS" est un test qui passe mais ne prouve pas ce que son nom ou son contexte suggère. C'est le risque le plus dangereux car il donne une fausse confiance.

### FAKE PASS #1: C1/C2 Process Tests

**Nom suggestif**: `c1-kiosk-process-audit.spec.js` — "process audit" suggère un audit complet du processus.

**Réalité probable**: Test Playwright/Cypress qui:
1. Ouvre la page kiosk
2. Clique sur une catégorie
3. Ajoute un item au panier
4. Va au checkout
5. Simule un paiement (mock)
6. Vérifie l'écran de confirmation

**Ce que ça prouve**: La navigation Vue Router fonctionne. Les composants se montent. Le store Vuex gère le panier.

**Ce que ça ne prouve PAS**:
- Le backend reçoit et traite la commande
- Le stock est décrémenté
- Le queue number est attribué
- L'event est dispatché
- Le KDS reçoit la commande
- L'OSS affiche la commande
- La séquence fiscale est correcte
- Le paiement est réellement traité

**Indice de fake PASS**: Le helper `process-audit.js` est partagé entre C1 et C2. Si ce helper contient des mocks API, les deux suites sont des fake PASS.

**Gravité**: CRITIQUE. Si C1/C2 sont des fake PASS, le système n'a aucune preuve de flux end-to-end.

### FAKE PASS #2: Stock Symmetry Test

**Nom suggestif**: `StockSymmetryDiffTest.php` — suggère une vérification de symétrie entre `OrderService` et `FrontendOrderService`.

**Réalité probable**: Le test peut comparer les signatures de méthodes ou les structures de données retournées, sans vérifier que les deux services produisent le même effet transactionnel (même décrémentation stock, même event dispatché, même queue number).

**Ce que ça ne prouve PAS**: Que passer une commande via POS (`OrderService`) et via kiosk (`FrontendOrderService`) produit exactement le même état DB final.

**Gravité**: HAUTE. Une asymétrie silencieuse peut causer des bugs intermittents en production (stock correct via POS mais incorrect via kiosk).

### FAKE PASS #3: Realtime Broadcast Tests

**Nom suggestif**: `KioskRealtimeBroadcastTest.php`, `pos-receives-kiosk-realtime.spec.js`

**Réalité probable**: 
- Le test PHP vérifie que l'event a la bonne structure (`broadcastOn`, `broadcastWith`)
- Le test Playwright vérifie que le composant POS réagit à un event injecté manuellement

**Ce que ça ne prouve PAS**: Que Redis/Pusher/Soketi reçoit l'event, le route au bon channel, et que le client JS le reçoit en < 5s.

**Indice de fake PASS**: Le brief dit "Playwright static contracts" — le mot "static" confirme que ce sont des contrats de structure, pas des tests runtime.

**Gravité**: CRITIQUE pour C3. Le système peut avoir des events parfaitement structurés qui ne sont jamais reçus.

### FAKE PASS #4: KDS Sync Tests

**Noms**: `kdsSyncCadence.spec.js`, `kdsDedupeByIdVersion.spec.js`, `kdsBackoffOn5xx.spec.js`, `kdsReactsToReconnectStorm.spec.js`

**Réalité probable**: Tests unitaires JS qui testent des fonctions pures (cadence timer, dédup logic, backoff algorithm, reconnect handler) avec des inputs mockés.

**Ce que ça ne prouve PAS**: Que ces mécanismes fonctionnent avec un vrai serveur WebSocket, un vrai Redis, et un vrai KDS qui reçoit 50 commandes/minute.

**Gravité**: MOYENNE. La logique est probablement correcte, mais l'intégration peut échouer.

### FAKE PASS #5: Design Audit avec `clearTransientUi()`

**Mécanisme**: Le helper supprime des éléments DOM (toasts, notifications) avant de lancer axe-core.

**Scénario fake PASS**: Un toast d'erreur `Too Many Attempts.` apparaît. Ce toast n'a pas de `role="alert"` → violation axe serious. Le helper le supprime → axe ne le voit pas → seriousTotal=0.

**Ce que ça masque**: Un vrai problème d'accessibilité ET un vrai problème de rate limiting.

**Gravité**: HAUTE. Double masquage (accessibilité + fonctionnel).

### FAKE PASS #6: Stock Branch Isolation Test

**Nom**: `StockBranchIsolationTest.php`

**Réalité probable**: Le test crée un stock pour branch A, vérifie qu'une requête scopée branch B ne le voit pas.

**Ce que ça ne prouve PAS**: Que TOUTES les requêtes stock sont scopées par branch. Un seul endpoint oublié (par exemple un endpoint d'export ou de dashboard) peut leaker du stock cross-branch.

**Gravité**: HAUTE. L'isolation branch est un invariant fondamental.

### FAKE PASS #7: Payment State Machine Tests

**Noms**: `PaymentStateMachineTransitionsTest.php`, `KioskPaymentStateMachineTest.php`

**Réalité probable**: Tests qui vérifient que les transitions autorisées fonctionnent et que les transitions interdites lèvent une exception.

**Ce que ça ne prouve PAS**: 
- Que la state machine est utilisée partout (un `$payment->status = 'paid'` bypass la machine)
- Que les side effects des transitions sont corrects (fiscal sequence, stock, events)
- Que le cash-at-counter flow utilise bien la machine

**Gravité**: HAUTE. La state machine peut être parfaite mais bypassée.

### FAKE PASS #8: Queue Number Concurrency Test

**Nom**: `QueueNumberConcurrencyTest.php` — 4 passed

**Réalité probable**: 4 tests qui vérifient la contrainte UNIQUE en DB, peut-être avec 2-3 insertions concurrentes via `DB::transaction`.

**Ce que ça ne prouve PAS**: Que sous 20+ workers parallèles, le mécanisme d'attribution (probablement `MAX(queue_number) + 1` ou une séquence) ne produit pas de doublons ou de gaps.

**Scénario de failure**: Deux workers lisent `MAX = 42` simultanément, les deux tentent d'insérer 43, un échoue, retry, lit `MAX = 43`, insère 44. Mais si le retry n'est pas implémenté, le worker crash et le client n'a pas de numéro.

**Gravité**: HAUTE en rush service.

---

## 4. CONTRADICTIONS AVEC LES INVARIANTS FOODKING

| Invariant | Contradiction potentielle | Fichier(s) suspect(s) | Vérification requise |
|---|---|---|---|
| Backend pricing SSOT | `kioskCart.js` et `posOrder.js` peuvent calculer des totaux côté client qui sont envoyés au backend sans revalidation | `kioskCart.js`, `posOrder.js`, `OrderRequest.php`, `PosOrderRequest.php` | Vérifier que le backend recalcule le total et rejette si divergence > seuil |
| OrderStatus/PaymentStatus enums | Code legacy ou nouveau code peut utiliser des chaînes | Tout `app/` | `grep -rn "->status = '" app/` et `grep -rn "->payment_status = '" app/` |
| Isolation branch_id | Controllers KDS, OSS, POS peuvent oublier le scope | `KitchenDisplaySystemController.php`, `OrderStatusScreenController.php`, `PosController.php`, `PosOrderController.php` | Vérifier que chaque query a un `where('branch_id', ...)` ou un scope global |
| Dispatch events après commit | Listeners stock/outbox peuvent ne pas avoir `$afterCommit` | `DecrementStockOnOrderCreated.php`, `PersistOrderCreatedToOutbox.php`, tous les listeners | `grep -rn 'afterCommit' app/Listeners/` |
| Symétrie OrderService/FrontendOrderService | Les deux services peuvent diverger sur: stock, queue number, events, fiscal | `OrderService.php`, `FrontendOrderService.php` | Diff structurel des deux services, vérifier que les mêmes listeners sont déclenchés |
| NF525 pas de séquence avant encaissement | `FrontendOrderService` peut allouer une séquence fiscale à la création de commande kiosk cash-at-counter | `FrontendOrderService.php`, `FiscalSealingService.php` | Tracer le flow: kiosk cash-at-counter → création commande → vérifier que fiscal_sequence est NULL |
| Kiosk locked down | `kioskRoutes.js` peut importer des routes admin par erreur | `kioskRoutes.js` | Lire le fichier, vérifier qu'aucune route `/admin/` n'est accessible |

---

## 5. ORCHESTRATION CORRECTIVE — ORDRE DE PRIORITÉ RÉVISÉ

Le plan ULTRA_PLAN propose Phase 0 (lecture) → Phase 1a/1b/1c (parallèle) → Phase 2 → etc. La review adverse modifie cet


---

# Tour 3 — SUITE ULTRA

<!-- usage tour 3: input≈76 output≈7810 stop=end_turn cumul≈28470 -->

# FOODKING C3–C5 — SCÉNARIOS STRESS & MULTI-SURFACE (EXHAUSTIF)

**Date**: 2026-04-28
**Contexte**: Développement détaillé des trois scénarios non prouvés identifiés dans l'ULTRA_PLAN et confirmés critiques par l'ULTRA_REVIEW.

---

## C3 — RUNTIME MULTI-SURFACE SIMULTANÉ

### C3.0 Définition du problème

Les tests C1 et C2 prouvent (au mieux) que chaque surface fonctionne en isolation. Aucun test existant ne prouve que:
- Une commande créée sur le kiosk apparaît sur le KDS en temps réel
- Le bump KDS met à jour l'OSS en temps réel
- Le POS voit les commandes kiosk en cours
- Deux surfaces ne créent pas de conflits d'état
- La latence de propagation est acceptable pour un service de restauration

C3 est le test qui transforme "5 applications qui marchent séparément" en "1 système qui fonctionne".

---

### C3.1 Préconditions

| # | Précondition | Vérification | Bloquant si absent |
|---|---|---|---|
| PRE-1 | Serveur Laravel fonctionnel avec DB MySQL 8 (pas SQLite) | `php artisan migrate:fresh --seed` sans erreur | OUI — SQLite ne reproduit pas les locks réels |
| PRE-2 | WebSocket server opérationnel (Soketi, Pusher, ou Laravel Reverb) | `wscat -c ws://localhost:6001` connecte | OUI — sans WS, pas de realtime |
| PRE-3 | Redis opérationnel (queue + broadcast) | `redis-cli ping` → PONG | OUI — queue workers dépendent de Redis |
| PRE-4 | Queue worker actif | `php artisan queue:work --queue=default,broadcasts` tourne | OUI — events async ne sont pas dispatchés sinon |
| PRE-5 | Seed data: 1 branch (`branch_id=1`), 1 user admin, 1 user caissier, 3 catégories, 10 items avec stock > 100 chacun, 1 composer profile publié | Seed custom ou factory | OUI |
| PRE-6 | Kiosk frontend buildé et accessible | `npm run build` + route kiosk accessible | OUI |
| PRE-7 | POS frontend accessible (auth admin/caissier) | Login POS fonctionne | OUI |
| PRE-8 | KDS frontend accessible | Route KDS accessible, filtrée `branch_id=1` | OUI |
| PRE-9 | OSS frontend accessible | Route OSS accessible, filtrée `branch_id=1` | OUI |
| PRE-10 | C1 et C2 passent en mode non-mocké (si applicable) | Run C1/C2 avec backend réel | Conditionnel — si C1/C2 sont des fake PASS, C3 les remplace |
| PRE-11 | `EventServiceProvider` vérifié: tous les listeners ont `$afterCommit = true` | Grep confirmé | OUI — sinon events avant commit |
| PRE-12 | Rate limit routes KDS/OSS suffisant (>= 120 req/min) | Vérifier middleware throttle | OUI — sinon `Too Many Attempts` pendant le test |

---

### C3.2 Architecture du test

Le test C3 utilise Playwright en mode multi-page (multi `BrowserContext`). Chaque surface est un contexte navigateur séparé, simulant 4 écrans physiques distincts.

```
┌─────────────────────────────────────────────────────────┐
│                    Playwright Test Runner                │
│                                                         │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────┐ │
│  │ Context 1│  │ Context 2│  │ Context 3│  │Context4│ │
│  │  KIOSK   │  │   POS    │  │   KDS    │  │  OSS   │ │
│  │ (anon)   │  │ (auth)   │  │ (auth)   │  │ (anon) │ │
│  └──────────┘  └──────────┘  └──────────┘  └────────┘ │
│       │              │              │             │      │
│       └──────────────┴──────────────┴─────────────┘      │
│                         │                                │
│              Laravel + MySQL + Redis + WS                │
└─────────────────────────────────────────────────────────┘
```

---

### C3.3 Scénarios détaillés

#### C3-S1: Kiosk crée commande → KDS la reçoit → OSS l'affiche

**Objectif**: Prouver la chaîne kiosk → backend → event → broadcast → KDS + OSS.

**Étapes**:

| Step | Surface | Action | Assertion | Timeout |
|---|---|---|---|---|
| 1 | KDS | Ouvrir la page KDS, noter le nombre de commandes affichées (`initialCount`) | Page chargée, WebSocket connecté (vérifier indicateur connexion si présent) | 10s |
| 2 | OSS | Ouvrir la page OSS, noter le nombre de commandes "en préparation" | Page chargée | 10s |
| 3 | KIOSK | Naviguer vers catégorie 1 | Catégorie affichée avec items | 5s |
| 4 | KIOSK | Ajouter item #1 au panier (quantité 2) | Panier affiche 2x item #1 | 3s |
| 5 | KIOSK | Ajouter item #2 au panier (quantité 1) | Panier affiche 2x item #1 + 1x item #2 | 3s |
| 6 | KIOSK | Aller au checkout | Page checkout affichée, total calculé | 3s |
| 7 | KIOSK | Sélectionner paiement carte (ou simuler paiement) | Paiement initié | 5s |
| 8 | KIOSK | Confirmer paiement (mock TPE success) | Écran confirmation affiché avec queue number `Q` | 10s |
| 9 | KDS | **Attendre** apparition d'une nouvelle commande | `count = initialCount + 1`, commande visible avec items corrects (2x item#1, 1x item#2) | **≤ 5s** |
| 10 | OSS | **Attendre** apparition de la commande dans "en préparation" | Queue number `Q` visible dans la liste | **≤ 5s** |
| 11 | — | Vérifier DB: `orders` table a une nouvelle entrée avec `status = OrderStatus::PENDING` ou `CONFIRMED`, `branch_id = 1`, `queue_number = Q` | Query DB directe ou API admin | 2s |
| 12 | — | Vérifier DB: `stock_levels` pour item#1 a diminué de 2, item#2 de 1 | Query DB | 2s |
| 13 | — | Vérifier DB: `stock_movements` a 2 nouvelles entrées (ou 1 par ligne commande) | Query DB | 2s |

**Métriques collectées**:
- `T_kiosk_confirm`: timestamp écran confirmation affiché
- `T_kds_receive`: timestamp commande visible sur KDS
- `T_oss_receive`: timestamp commande visible sur OSS
- `Δ_kds = T_kds_receive - T_kiosk_confirm` (latence propagation KDS)
- `Δ_oss = T_oss_receive - T_kiosk_confirm` (latence propagation OSS)

**Seuils**:
| Métrique | PASS | ACCEPTABLE | FAIL |
|---|---|---|---|
| `Δ_kds` | ≤ 2s | ≤ 5s | > 5s |
| `Δ_oss` | ≤ 2s | ≤ 5s | > 5s |
| Stock décrémenté correctement | Exact | — | Toute divergence |
| Queue number présent et unique | Oui | — | Non |

---

#### C3-S2: POS crée commande → KDS la reçoit → OSS l'affiche

**Objectif**: Même chaîne que S1 mais depuis le POS. Vérifie la symétrie `OrderService` / `FrontendOrderService`.

**Étapes**:

| Step | Surface | Action | Assertion | Timeout |
|---|---|---|---|---|
| 1 | KDS | Noter `count` actuel | — | 2s |
| 2 | OSS | Noter `count` actuel | — | 2s |
| 3 | POS | Login caissier | Dashboard POS affiché | 5s |
| 4 | POS | Sélectionner item #3 (quantité 1) + item #4 (quantité 3) | Panier POS correct | 3s |
| 5 | POS | Sélectionner paiement espèces | Modal paiement espèces | 3s |
| 6 | POS | Confirmer montant reçu ≥ total | Commande créée, ticket affiché avec queue number `Q2` | 5s |
| 7 | KDS | Attendre nouvelle commande | `count + 1`, items corrects | **≤ 5s** |
| 8 | OSS | Attendre queue number `Q2` dans "en préparation" | Visible | **≤ 5s** |
| 9 | — | Vérifier DB: commande POS avec `source = 'pos'`, `payment_method = 'cash'`, `payment_status = PAID` | — | 2s |
| 10 | — | Vérifier DB: séquence fiscale allouée (car paiement cash immédiat) | `fiscal_sequence_number IS NOT NULL` | 2s |
| 11 | — | Vérifier: `Q2 = Q + 1` (séquence queue number continue entre kiosk et POS sur même branch+date) | — | 2s |

**Métriques**: Mêmes que S1 + vérification séquence fiscale.

---

#### C3-S3: KDS bump → OSS met à jour → Kiosk/POS voient le statut

**Objectif**: Prouver la propagation du changement de statut après bump cuisine.

**Étapes**:

| Step | Surface | Action | Assertion | Timeout |
|---|---|---|---|---|
| 1 | KDS | Identifier la commande `Q` (créée en S1) | Commande visible, statut "en préparation" | 2s |
| 2 | OSS | Vérifier que `Q` est dans "en préparation" | Visible | 2s |
| 3 | KDS | Cliquer "bump" / "prêt" sur la commande `Q` | Commande disparaît de la liste KDS active (ou passe en "done") | 3s |
| 4 | OSS | **Attendre** que `Q` passe de "en préparation" à "prêt" | `Q` visible dans section "prêt" | **≤ 5s** |
| 5 | — | Vérifier DB: `orders.status = OrderStatus::READY` pour commande `Q` | — | 2s |
| 6 | KIOSK | Si le kiosk a un écran de suivi (KioskWaitingComponent): vérifier que le statut est mis à jour | Statut "prêt" ou équivalent | **≤ 5s** |

**Métriques**:
- `T_kds_bump`: timestamp du clic bump
- `T_oss_ready`: timestamp apparition dans "prêt" sur OSS
- `Δ_bump_oss = T_oss_ready - T_kds_bump`

**Seuils**:
| Métrique | PASS | FAIL |
|---|---|---|
| `Δ_bump_oss` | ≤ 3s | > 5s |
| DB status correct | `READY` | Autre |
| OSS section correcte | "prêt" | Reste dans "en préparation" |

---

#### C3-S4: Commandes simultanées kiosk + POS → KDS reçoit les deux → pas de perte

**Objectif**: Prouver que deux commandes créées quasi-simultanément arrivent toutes les deux sur le KDS sans perte ni fusion.

**Étapes**:

| Step | Surface | Action | Assertion | Timeout |
|---|---|---|---|---|
| 1 | KDS | Noter `count` | — | 2s |
| 2 | KIOSK + POS | **En parallèle** (Promise.all): Kiosk crée commande A, POS crée commande B | Les deux confirmations s'affichent | 15s |
| 3 | KDS | Attendre `count + 2` | Deux nouvelles commandes distinctes, avec queue numbers différents | **≤ 8s** |
| 4 | OSS | Attendre les deux queue numbers dans "en préparation" | Les deux visibles | **≤ 8s** |
| 5 | — | Vérifier DB: 2 commandes distinctes, queue numbers consécutifs ou au moins uniques, stock décrémenté pour les deux | — | 2s |

**Métriques**:
- `T_parallel_start`: timestamp début Promise.all
- `T_kds_both`: timestamp quand les 2 commandes sont visibles KDS
- `Δ_parallel = T_kds_both - T_parallel_start`
- `queue_numbers_unique`: booléen

**Seuils**:
| Métrique | PASS | FAIL |
|---|---|---|
| `Δ_parallel` | ≤ 10s | > 15s |
| Deux commandes distinctes sur KDS | Oui | Non (fusion, perte, ou doublon) |
| Queue numbers uniques | Oui | Non |
| Stock décrémenté exactement pour les deux | Oui | Non |

---

#### C3-S5: Reconnexion WebSocket → rattrapage sans perte

**Objectif**: Prouver que si le KDS perd la connexion WebSocket pendant la création d'une commande, il rattrape via polling/reconnect.

**Étapes**:

| Step | Surface | Action | Assertion | Timeout |
|---|---|---|---|---|
| 1 | KDS | Couper le WebSocket (via `page.evaluate(() => window.Echo?.connector?.socket?.close())` ou bloquer le port WS via `page.route`) | KDS déconnecté | 2s |
| 2 | KIOSK | Créer une commande C | Confirmation kiosk OK | 10s |
| 3 | KDS | Vérifier que la commande C n'apparaît PAS immédiatement | Pas de nouvelle commande (WS coupé) | 3s |
| 4 | KDS | Rétablir le WebSocket (débloquer le port ou reconnecter) | — | 2s |
| 5 | KDS | Attendre que la commande C apparaisse (via reconnect + rattrapage) | Commande C visible | **≤ 15s** (inclut backoff reconnect) |
| 6 | — | Vérifier: pas de doublon (la commande C n'apparaît qu'une fois) | `count` correct | 2s |

**Métriques**:
- `T_ws_restore`: timestamp reconnexion
- `T_kds_catchup`: timestamp commande visible
- `Δ_catchup = T_kds_catchup - T_ws_restore`

**Seuils**:
| Métrique | PASS | FAIL |
|---|---|---|
| Commande rattrapée | Oui | Non (perte définitive) |
| `Δ_catchup` | ≤ 10s | > 30s |
| Pas de doublon | 0 doublon | ≥ 1 doublon |
| KDS indicateur reconnexion visible | Oui (UX) | Non (P2, pas bloquant) |

---

#### C3-S6: Multi-branch isolation runtime

**Objectif**: Prouver qu'une commande sur `branch_id=1` n'apparaît pas sur le KDS de `branch_id=2`.

**Étapes**:

| Step | Surface | Action | Assertion | Timeout |
|---|---|---|---|---|
| 1 | — | Seed: créer `branch_id=2` avec son propre KDS/OSS | — | — |
| 2 | KDS-B2 | Ouvrir KDS filtré `branch_id=2`, noter `count` | — | 5s |
| 3 | KIOSK-B1 | Créer commande sur `branch_id=1` | Confirmation OK | 10s |
| 4 | KDS-B1 | Vérifier commande reçue | Oui | 5s |
| 5 | KDS-B2 | Vérifier que la commande n'apparaît PAS | `count` inchangé après 10s | **10s d'attente** |

**Seuils**:
| Métrique | PASS | FAIL |
|---|---|---|
| Commande visible KDS-B1 | Oui | Non |
| Commande invisible KDS-B2 | Oui (après 10s d'attente) | Apparaît → fuite cross-branch |

---

### C3.4 Script Playwright suggéré (squelette)

```javascript
// tests/e2e/c3-runtime-multi-surface.spec.js
import { test, expect } from '@playwright/test';

test.describe('C3 — Runtime Multi-Surface', () => {
  let kioskPage, posPage, kdsPage, ossPage;
  let kioskContext, posContext, kdsContext, ossContext;

  test.beforeAll(async ({ browser }) => {
    // 4 contextes isolés = 4 "écrans" physiques
    kioskContext = await browser.newContext();
    posContext   = await browser.newContext();
    kdsContext   = await browser.newContext();
    ossContext   = await browser.newContext();

    kioskPage = await kioskContext.newPage();
    posPage   = await posContext.newPage();
    kdsPage   = await kdsContext.newPage();
    ossPage   = await ossContext.newPage();

    // Auth POS
    await posPage.goto('/login');
    await posPage.fill('[data-test="email"]', 'caissier@branch1.test');
    await posPage.fill('[data-test="password"]', 'password');
    await posPage.click('[data-test="login-submit"]');
    await posPage.waitForURL('**/pos**');

    // Auth KDS (si requis)
    await kdsPage.goto('/admin/kds?branch_id=1');
    // ... auth si nécessaire

    // OSS (public, pas d'auth)
    await ossPage.goto('/oss?branch_id=1');

    // Kiosk (public)
    await kioskPage.goto('/kiosk?branch_id=1');
  });

  test.afterAll(async () => {
    await kioskContext.close();
    await posContext.close();
    await kdsContext.close();
    await ossContext.close();
  });

  // Helper: compter les commandes visibles sur KDS
  async function kdsOrderCount() {
    return kdsPage.locator('[data-test="kds-order-card"]').count();
  }

  // Helper: compter les commandes "preparing" sur OSS
  async function ossPreparingCount() {
    return ossPage.locator('[data-test="oss-preparing-item"]').count();
  }

  // Helper: extraire le queue number depuis l'écran de confirmation kiosk
  async function kioskQueueNumber() {
    const el = kioskPage.locator('[data-test="kiosk-queue-number"]');
    await el.waitFor({ timeout: 10000 });
    return el.textContent();
  }

  test('C3-S1: Kiosk order → KDS + OSS receive', async () => {
    const kdsInitial = await kdsOrderCount();
    const ossInitial = await ossPreparingCount();

    // Kiosk: browse → cart → checkout → pay
    await kioskPage.click('[data-test="category-1"]');
    await kioskPage.click('[data-test="item-1-add"]');
    await kioskPage.click('[data-test="item-1-add"]'); // qty 2
    await kioskPage.click('[data-test="item-2-add"]');
    await kioskPage.click('[data-test="go-to-checkout"]');
    await kioskPage.click('[data-test="pay-card"]');
    // Mock TPE success (dépend de l'implémentation)
    await kioskPage.click('[data-test="confirm-payment"]');

    const queueNumber = await kioskQueueNumber();
    const tConfirm = Date.now();

    // KDS: attendre nouvelle commande
    await expect(async () => {
      expect(await kdsOrderCount()).toBe(kdsInitial + 1);
    }).toPass({ timeout: 5000 });
    const tKds = Date.now();

    // OSS: attendre queue number
    await expect(
      ossPage.locator(`[data-test="oss-preparing-item"]:has-text("${queueNumber}")`)
    ).toBeVisible({ timeout: 5000 });
    const tOss = Date.now();

    // Métriques
    const deltaKds = tKds - tConfirm;
    const deltaOss = tOss - tConfirm;
    console.log(`C3-S1 metrics: Δ_kds=${deltaKds}ms, Δ_oss=${deltaOss}ms`);

    expect(deltaKds).toBeLessThan(5000);
    expect(deltaOss).toBeLessThan(5000);
  });

  test('C3-S4: Simultaneous kiosk + POS → KDS receives both', async () => {
    const kdsInitial = await kdsOrderCount();

    // Parallèle
    const [kioskResult, posResult] = await Promise.all([
      (async () => {
        await kioskPage.click('[data-test="category-1"]');
        await kioskPage.click('[data-test="item-3-add"]');
        await kioskPage.click('[data-test="go-to-checkout"]');
        await kioskPage.click('[data-test="pay-card"]');
        await kioskPage.click('[data-test="confirm-payment"]');
        return kioskQueueNumber();
      })(),
      (async () => {
        await posPage.click('[data-test="pos-item-4"]');
        await posPage.click('[data-test="pos-pay-cash"]');
        await posPage.fill('[data-test="pos-cash-received"]', '50.00');
        await posPage.click('[data-test="pos-confirm-payment"]');
        const qn = await posPage.locator('[data-test="pos-queue-number"]').textContent();
        return qn;
      })()
    ]);

    // Les deux queue numbers doivent être différents
    expect(kioskResult).not.toBe(posResult);

    // KDS doit avoir les deux
    await expect(async () => {
      expect(await kdsOrderCount()).toBe(kdsInitial + 2);
    }).toPass({ timeout: 8000 });
  });

  test('C3-S5: WS disconnect → reconnect → catchup', async () => {
    const kdsInitial = await kdsOrderCount();

    // Couper WS côté KDS
    await kdsPage.evaluate(() => {
      if (window.Echo?.connector?.pusher) {
        window.Echo.connector.pusher.disconnect();
      }
    });

    // Créer commande pendant déconnexion
    await kioskPage.click('[data-test="category-1"]');
    await kioskPage.click('[data-test="item-1-add"]');
    await kioskPage.click('[data-test="go-to-checkout"]');
    await kioskPage.click('[data-test="pay-card"]');
    await kioskPage.click('[data-test="confirm-payment"]');
    await kioskQueueNumber();

    // Vérifier que KDS n'a PAS reçu
    await kioskPage.waitForTimeout(3000);
    expect(await kdsOrderCount()).toBe(kdsInitial);

    // Reconnecter
    await kdsPage.evaluate(() => {
      if (window.Echo?.connector?.pusher) {
        window.Echo.connector.pusher.connect();
      }
    });

    // Attendre rattrapage
    await expect(async () => {
      expect(await kdsOrderCount()).toBe(kdsInitial + 1);
    }).toPass({ timeout: 15000 });

    // Pas de doublon
    expect(await kdsOrderCount()).toBe(kdsInitial + 1);
  });

  test('C3-S6: Branch isolation — B1 order invisible on B2 KDS', async () => {
    // Ouvrir un 5e contexte pour KDS branch 2
    const kdsB2Context = await kioskPage.context().browser().newContext();
    const kdsB2Page = await kdsB2Context.newPage();
    await kdsB2Page.goto('/admin/kds?branch_id=2');
    const kdsB2Initial = await kdsB2Page.locator('[data-test="kds-order-card"]').count();

    // Créer commande sur branch 1
    await kioskPage.click('[data-test="category-1"]');
    await kioskPage.click('[data-test="item-1-add"]');
    await kioskPage.click('[data-test="go-to-checkout"]');
    await kioskPage.click('[data-test="pay-card"]');
    await kioskPage.click('[data-test="confirm-payment"]');

    // Attendre 10s, vérifier que KDS B2 n'a rien reçu
    await kdsB2Page.waitForTimeout(10000);
    expect(await kdsB2Page.locator('[data-test="kds-order-card"]').count()).toBe(kdsB2Initial);

    await kdsB2Context.close();
  });
});
```

### C3.5 Vérifications DB post-test (script PHP artisan ou API)

```php
// Script de vérification post-C3 (à exécuter après le test Playwright)
// Peut être un artisan command ou un endpoint API test-only

// 1. Vérifier que toutes les commandes créées pendant C3 ont:
$orders = Order::where('created_at', '>=', $testStartTime)
    ->where('branch_id', 1)
    ->get();

foreach ($orders as $order) {
    // Status est un enum, pas une chaîne
    assert($order->status instanceof OrderStatus);
    
    // Queue number existe et est unique
    assert($order->queue_number !== null);
    $dupes = Order::where('branch_id', $order->branch_id)
        ->where('queue_number', $order->queue_number)
        ->whereDate('created_at', $order->created_at)
        ->count();
    assert($dupes === 1, "Queue number {$order->queue_number} dupliqué");
    
    // Stock movements existent
    $movements = StockMovement::where('order_id', $order->id)->get();
    assert($movements->isNotEmpty(), "Pas de stock movement pour order {$order->id}");
    
    // Outbox entries existent
    $outbox = DB::table('outbox')->where('aggregate_id', $order->id)->get();
    assert($outbox->isNotEmpty(), "Pas d'outbox entry pour order {$order->id}");
}

// 2. Vérifier stock final
foreach ([1, 2, 3, 4] as $itemId) {
    $level = StockLevel::where('item_id', $itemId)->where('branch_id', 1)->first();
    $totalDecremented = StockMovement::where('item_id', $itemId)
        ->where('branch_id', 1)
        ->where('type', 'decrement')
        ->sum('quantity');
    assert($level->quantity === 100 - abs($totalDecremented),
        "Stock incohérent pour item {$itemId}");
}
```

### C3.6 Critères PASS/FAIL consolidés

| Critère | PASS | FAIL | Conséquence FAIL |
|---|---|---|---|
| 6 scénarios S1-S6 passent | 6/6 | < 6/6 | REWORK C3 |
| Latence propagation KDS ≤ 5s | Toutes mesures ≤ 5s | Une mesure > 5s | Investiguer broadcast/queue |
| Latence propagation OSS ≤ 5s | Toutes mesures ≤ 5s | Une mesure > 5s | Idem |
| Zéro perte de commande | 0 perte | ≥ 1 perte | P0 — bug critique |
| Zéro doublon | 0 doublon | ≥ 1 doublon | P0 — bug dédup |
| Queue numbers uniques | Tous uniques | 1 doublon | P0 — bug concurrence |
| Stock cohérent | Exact | Divergence | P0 — bug stock |
| Branch isolation | Aucune fuite | 1 fuite | P0 — bug sécurité |
| Reconnect catchup fonctionne | Oui | Non | P1 — bug résilience |
| Run-many 3x sans flake | 3/3 | < 3/3 | Investiguer flakiness |

---

## C4 — STRESS STOCK MASSIF

### C4.0 Définition du problème

Les tests stock actuels (17 passed) vérifient le comportement unitaire et une concurrence légère. En production, pendant un rush service (12h-14h), un restaurant peut recevoir 50-100 commandes en 30 minutes, chacune avec 2-5 lignes. Le stock doit:
- Décr


---

# Tour 4 — SUITE ULTRA

<!-- usage tour 4: input≈529 output≈7892 stop=end_turn cumul≈36891 -->

# FOODKING C6 — FISCAL / LEDGER / OUTBOX / HMAC / Z-REPORT / NF525 (EXHAUSTIF)

**Date**: 2026-04-28
**Contexte**: Développement complet du chantier C6 identifié `NOT_VALIDATED` dans l'ULTRA_PLAN. Aucun fichier lu directement — toutes les assertions sont des exigences à vérifier puis implémenter/corriger.

---

## C6.0 Périmètre et enjeux

NF525 est une certification obligatoire en France pour tout logiciel de caisse depuis le 1er janvier 2018. Les quatre propriétés certifiées sont:

| Propriété | Signification concrète FoodKing | Conséquence si violée |
|---|---|---|
| **Inaltérabilité** | Aucun reçu fiscal émis ne peut être modifié ou supprimé | Amende 7 500 € par logiciel non conforme |
| **Sécurisation** | Chaîne de hachage HMAC-SHA256 reliant chaque reçu au précédent | Idem + présomption de fraude |
| **Conservation** | Archivage 6 ans minimum, clôtures périodiques | Idem |
| **Archivage** | Export XML/CSV normalisé pour contrôle fiscal | Idem |

Le chantier C6 couvre:
- La machine à états fiscale (quand une séquence est allouée, quand elle ne l'est pas)
- La chaîne HMAC (intégrité, monotonie, absence de gap)
- L'outbox pattern (events après commit, idempotence, replay)
- Le Z-report (clôture journalière, totaux, chaîne Z)
- Les audit logs (traçabilité des opérations)
- L'idempotence du replay outbox

---

## C6.1 Machine à états fiscale

### C6.1.1 Diagramme d'états — Séquence fiscale par commande

```
                    ┌──────────────────┐
                    │                  │
                    │  ORDER CREATED   │
                    │                  │
                    │ fiscal_seq: NULL │
                    │ fiscal_hash: NULL│
                    └────┬─────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
              ▼              ▼              ▼
     ┌────────────┐  ┌────────────┐  ┌────────────┐
     │ KIOSK CARD │  │ KIOSK CASH │  │  POS CASH  │
     │ (paiement  │  │ AT COUNTER │  │ (paiement  │
     │ immédiat)  │  │ (différé)  │  │ immédiat)  │
     └─────┬──────┘  └─────┬──────┘  └─────┬──────┘
           │               │               │
           ▼               ▼               ▼
     ┌───────────┐   ┌───────────┐   ┌───────────┐
     │ PAYMENT   │   │ PAYMENT   │   │ PAYMENT   │
     │ CONFIRMED │   │ PENDING   │   │ CONFIRMED │
     │           │   │           │   │           │
     │ → ALLOC   │   │ fiscal:   │   │ → ALLOC   │
     │   FISCAL  │   │ NULL      │   │   FISCAL  │
     │   SEQ     │   │ (INTERDIT │   │   SEQ     │
     └───────────┘   │  d'allouer│   └───────────┘
                     │  ici)     │
                     └─────┬─────┘
                           │
                ┌──────────┼──────────┐
                │                     │
                ▼                     ▼
          ┌───────────┐         ┌───────────┐
          │ COUNTER   │         │ COUNTER   │
          │ CONFIRM   │         │ CANCEL    │
          │ (caissier │         │ (timeout  │
          │ encaisse) │         │ ou refus) │
          │           │         │           │
          │ → ALLOC   │         │ fiscal:   │
          │   FISCAL  │         │ NULL      │
          │   SEQ     │         │ (JAMAIS   │
          └───────────┘         │  alloué)  │
                                └───────────┘
```

### C6.1.2 Règles formelles

| Règle | Description | Invariant code | Fichier(s) à vérifier |
|---|---|---|---|
| **F-R1** | `fiscal_sequence_number` est `NULL` à la création de toute commande, quel que soit le canal | `Order::creating` callback ou `OrderService`/`FrontendOrderService` ne doivent jamais setter `fiscal_sequence_number` à la création | `OrderService.php`, `FrontendOrderService.php`, `Order.php` |
| **F-R2** | `fiscal_sequence_number` est alloué **uniquement** quand `payment_status` transite vers `PAID` | L'allocation se fait dans un listener ou dans `PaymentService` au moment exact du `PAID` | `PaymentService.php`, `FiscalSealingService.php` |
| **F-R3** | L'allocation est **atomique**: `SELECT MAX(fiscal_sequence_number) + 1 ... FOR UPDATE` dans la même transaction que le passage à `PAID` | Pas de fenêtre entre la lecture du max et l'écriture du nouveau | `FiscalSealingService.php` |
| **F-R4** | Une commande annulée (`CANCELLED`) qui n'a **jamais** été `PAID` n'a **jamais** de `fiscal_sequence_number` | Le cancel path ne doit pas toucher au fiscal | `OrderStateMachine.php`, `PaymentStateMachine.php` |
| **F-R5** | Un remboursement (`REFUNDED`) crée un **nouveau** reçu fiscal négatif avec son propre `fiscal_sequence_number` dans la même chaîne | Le refund n'annule pas le reçu original, il en crée un nouveau | `PaymentService.php`, `FiscalSealingService.php` |
| **F-R6** | La séquence est **monotone stricte** par `branch_id`: pas de gap, pas de doublon | Contrainte DB `UNIQUE(branch_id, fiscal_sequence_number)` + `CHECK(fiscal_sequence_number > 0)` | Migration, `FiscalSealingService.php` |
| **F-R7** | Chaque reçu fiscal inclut le hash HMAC-SHA256 du reçu précédent dans la même branche | Chaîne de hachage | `FiscalSealingService.php` |
| **F-R8** | Le premier reçu d'une branche a un `previous_hash` conventionnel (ex: `SHA256("INIT")`) | Initialisation de chaîne | `FiscalSealingService.php` |
| **F-R9** | Un reçu fiscal émis ne peut **jamais** être modifié (`UPDATE`) ni supprimé (`DELETE`) | Append-only sur la table `fiscal_receipts` | Migration (pas de `ON DELETE CASCADE`), model `$guarded`, absence de méthode `update`/`delete` |
| **F-R10** | Le reprint d'un ticket ne crée **pas** de nouveau reçu fiscal | Le reprint lit le reçu existant | `PosController.php`, `FrontendOrderService.php` |

### C6.1.3 Transitions interdites — Matrice exhaustive

| État source | Action | fiscal_sequence_number attendu | Interdit? | Pourquoi |
|---|---|---|---|---|
| `CREATED` + `PAYMENT_PENDING` | Créer commande kiosk cash-at-counter | `NULL` | Allouer fiscal ici est **INTERDIT** | Pas encore encaissé |
| `CREATED` + `PAYMENT_PENDING` | Cancel kiosk cash-at-counter (timeout) | `NULL` | Allouer fiscal ici est **INTERDIT** | Jamais encaissé |
| `CREATED` + `PAYMENT_PENDING` | Caissier confirme encaissement counter | Allouer `N+1` | **OBLIGATOIRE** | Encaissement réel |
| `CREATED` + `PAYMENT_PROCESSING` | Kiosk card en cours de paiement TPE | `NULL` | Allouer fiscal ici est **INTERDIT** | Paiement pas encore confirmé |
| `CREATED` + `PAYMENT_CONFIRMED` | Kiosk card paiement TPE confirmé | Allouer `N+1` | **OBLIGATOIRE** | Encaissement réel |
| `CREATED` + `PAYMENT_CONFIRMED` | POS cash paiement immédiat | Allouer `N+1` | **OBLIGATOIRE** | Encaissement réel |
| `CONFIRMED` + `PAID` | Refund partiel | Allouer `N+2` (reçu négatif) | **OBLIGATOIRE** | Traçabilité fiscale du remboursement |
| `CONFIRMED` + `PAID` | Reprint ticket | Lire reçu existant | Créer nouveau reçu est **INTERDIT** | Duplication fiscale |
| `CANCELLED` + `NEVER_PAID` | Toute action | `NULL` | Allouer fiscal est **INTERDIT** | Aucun encaissement n'a eu lieu |
| Tout état | `DELETE FROM fiscal_receipts` | — | **INTERDIT** | Inaltérabilité NF525 |
| Tout état | `UPDATE fiscal_receipts SET ...` | — | **INTERDIT** | Inaltérabilité NF525 |

---

## C6.2 Chaîne HMAC-SHA256

### C6.2.1 Structure d'un reçu fiscal

```
fiscal_receipts
├── id                          (PK, auto-increment)
├── branch_id                   (FK → branches)
├── fiscal_sequence_number      (UNIQUE per branch, monotone strict)
├── order_id                    (FK → orders)
├── type                        ('SALE' | 'REFUND')
├── amount_ttc                  (montant TTC en centimes, signé: + pour vente, - pour remboursement)
├── amount_ht                   (montant HT en centimes)
├── tax_amount                  (montant TVA en centimes)
├── tax_rate                    (taux TVA applicable, ex: 1000 pour 10.00%)
├── payment_method              (enum: CASH, CARD, CASH_AT_COUNTER)
├── previous_hash               (HMAC-SHA256 du reçu précédent dans la branche)
├── receipt_hash                (HMAC-SHA256 de ce reçu)
├── receipt_payload             (JSON canonicalisé du contenu signé)
├── created_at                  (timestamp, immutable)
├── z_report_id                 (FK → z_reports, NULL tant que pas clôturé)
└── archived_at                 (timestamp d'archivage, NULL initialement)
```

### C6.2.2 Algorithme de hachage

```
INPUTS pour HMAC-SHA256:
  key    = FISCAL_HMAC_SECRET (env variable, jamais en code)
  
  data   = canonical_json({
    "branch_id":              <int>,
    "fiscal_sequence_number": <int>,
    "type":                   <"SALE"|"REFUND">,
    "order_id":               <int>,
    "amount_ttc":             <int centimes>,
    "tax_amount":             <int centimes>,
    "tax_rate":               <int centièmes de %>,
    "payment_method":         <string>,
    "previous_hash":          <string hex 64 chars>,
    "created_at":             <ISO 8601 UTC>
  })

OUTPUT:
  receipt_hash = HMAC-SHA256(key, data)  → hex string 64 chars
```

**Canonical JSON**: Les clés sont triées alphabétiquement, pas d'espaces, pas de trailing comma. Ceci garantit que le même contenu produit toujours le même hash.

### C6.2.3 Chaîne de hachage — Invariants

| Invariant | Formule | Vérification |
|---|---|---|
| **H-I1** Premier reçu | `receipt[1].previous_hash = HMAC-SHA256(key, "INIT_BRANCH_{branch_id}")` | Conventionnel, documenté |
| **H-I2** Chaîne | `receipt[N].previous_hash = receipt[N-1].receipt_hash` | Pour tout N > 1 dans la même branche |
| **H-I3** Intégrité | `receipt[N].receipt_hash = HMAC-SHA256(key, canonical_json(receipt[N].payload))` | Recalculable à tout moment |
| **H-I4** Monotonie | `receipt[N].fiscal_sequence_number = receipt[N-1].fiscal_sequence_number + 1` | Pas de gap |
| **H-I5** Immutabilité | `receipt[N]` ne change jamais après insertion | Pas d'UPDATE |
| **H-I6** Détection de rupture | Si un reçu est supprimé ou modifié, `receipt[N+1].previous_hash ≠ recalcul(receipt[N])` | Vérification de chaîne |

### C6.2.4 Diagramme de chaîne

```
Branch 1:

  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
  │ Receipt #1  │     │ Receipt #2  │     │ Receipt #3  │     │ Receipt #4  │
  │ SALE        │     │ SALE        │     │ REFUND      │     │ SALE        │
  │ +1250 cts   │     │ +890 cts    │     │ -890 cts    │     │ +2100 cts   │
  │             │     │             │     │ (refund #2) │     │             │
  │ prev: INIT  │────▶│ prev: H(#1) │────▶│ prev: H(#2) │────▶│ prev: H(#3) │
  │ hash: H(#1) │     │ hash: H(#2) │     │ hash: H(#3) │     │ hash: H(#4) │
  └─────────────┘     └─────────────┘
        │                                                            │
        └──────────── Z-Report Day 1 ────────────────────────────────┘
```

---

## C6.3 Outbox Pattern — Events après commit

### C6.3.1 Problème résolu

Sans outbox, le flow est:

```
BEGIN TRANSACTION
  INSERT order
  INSERT stock_movement
  UPDATE stock_level
  DISPATCH OrderCreated event  ← DANGER: si le commit échoue après,
COMMIT                            l'event a déjà été envoyé
```

Avec outbox:

```
BEGIN TRANSACTION
  INSERT order
  INSERT stock_movement
  UPDATE stock_level
  INSERT outbox_entry (OrderCreated, payload)
COMMIT
── transaction committée, état DB cohérent ──
AFTER COMMIT:
  DispatchDomainEventsJob lit outbox_entry
  Dispatch OrderCreated event
  Mark outbox_entry as dispatched
```

### C6.3.2 Structure table outbox

```
outbox
├── id                  (PK, BIGINT auto-increment)
├── aggregate_type      ('Order', 'Payment', 'Catalog', 'Stock')
├── aggregate_id        (FK polymorphe)
├── event_type          ('OrderCreated', 'OrderStatusChanged', 'OrderPaidAtCounter', 
│                        'CatalogChanged', 'StockLevelChanged')
├── payload             (JSON, contenu complet de l'event)
├── channel             (broadcast channel cible)
├── branch_id           (pour routing et isolation)
├── created_at          (timestamp insertion)
├── dispatched_at       (NULL → pas encore dispatché, timestamp → dispatché)
├── dispatch_attempts   (INT, compteur de tentatives)
├── last_error          (TEXT, dernière erreur si échec)
└── idempotency_key     (UNIQUE, format: "{event_type}_{aggregate_id}_{version}")
```

### C6.3.3 Listeners outbox — Mapping attendu

| Event Laravel | Listener Outbox | Channel broadcast | Fichier listener |
|---|---|---|---|
| `OrderCreated` | `PersistOrderCreatedToOutbox` | `branch.{branch_id}.orders` | `app/Listeners/PersistOrderCreatedToOutbox.php` |
| `OrderStatusChanged` | `PersistOrderStatusChangedToOutbox` | `branch.{branch_id}.orders` | `app/Listeners/PersistOrderStatusChangedToOutbox.php` |
| `OrderPaidAtCounter` | `PersistOrderPaidAtCounterToOutbox` | `branch.{branch_id}.orders` | `app/Listeners/PersistOrderPaidAtCounterToOutbox.php` |
| `CatalogChanged` | `PersistCatalogChangedToOutbox` | `branch.{branch_id}.catalog` | `app/Listeners/PersistCatalogChangedToOutbox.php` |
| `StockLevelChanged` | (à vérifier si existe) | `branch.{branch_id}.stock` | `app/Listeners/PersistStockLevelChangedToOutbox.php` ? |

### C6.3.4 Invariants outbox

| Invariant | Description | Vérification |
|---|---|---|
| **O-I1** | Chaque listener outbox a `$afterCommit = true` ou implémente `ShouldQueue` avec `afterCommit` | `grep -rn 'afterCommit' app/Listeners/Persist*` |
| **O-I2** | L'`idempotency_key` est UNIQUE en DB | Migration: `$table->unique('idempotency_key')` |
| **O-I3** | `DispatchDomainEventsJob` vérifie `dispatched_at IS NULL` avant dispatch | Lecture du job |
| **O-I4** | Après dispatch réussi, `dispatched_at` est set atomiquement | `UPDATE outbox SET dispatched_at = NOW() WHERE id = ? AND dispatched_at IS NULL` |
| **O-I5** | Un replay (re-exécution du job) ne produit pas de double effet | L'idempotency_key empêche la double insertion; le consumer vérifie l'idempotence |
| **O-I6** | `dispatch_attempts` est incrémenté à chaque tentative | Pour monitoring et dead-letter |
| **O-I7** | Après N échecs (ex: 5), l'entry est marquée dead-letter et une alerte est émise | Pas de retry infini |
| **O-I8** | Le payload contient toutes les données nécessaires pour reconstruire l'event sans requête DB | Self-contained event |
| **O-I9** | Le `branch_id` dans l'outbox entry correspond au `branch_id` de l'aggregate | Isolation branch |

---

## C6.4 Z-Report (Clôture journalière)

### C6.4.1 Structure

```
z_reports
├── id                      (PK)
├── branch_id               (FK → branches)
├── report_date             (DATE, la journée clôturée)
├── fiscal_seq_start        (premier fiscal_sequence_number de la journée)
├── fiscal_seq_end          (dernier fiscal_sequence_number de la journée)
├── total_sales_ttc         (somme des SALE en centimes)
├── total_refunds_ttc       (somme des REFUND en centimes, négatif)
├── total_net_ttc           (total_sales_ttc + total_refunds_ttc)
├── total_tax               (somme des taxes)
├── receipt_count           (nombre de reçus dans la période)
├── payment_breakdown       (JSON: {"CASH": 1250, "CARD": 3400, "CASH_AT_COUNTER": 890})
├── previous_z_hash         (HMAC-SHA256 du Z-report précédent)
├── z_hash                  (HMAC-SHA256 de ce Z-report)
├── z_payload               (JSON canonicalisé signé)
├── created_at              (timestamp de génération)
├── operator_id             (FK → users, qui a déclenché la clôture)
└── UNIQUE(branch_id, report_date)
```

### C6.4.2 Règles Z-Report

| Règle | Description | Conséquence si violée |
|---|---|---|
| **Z-R1** | Un Z-report est généré exactement une fois par `(branch_id, date)` | Contrainte UNIQUE. Tentative de double clôture → exception. |
| **Z-R2** | Le Z-report couvre tous les reçus fiscaux de la journée, sans exception | `fiscal_seq_start` et `fiscal_seq_end` doivent être continus. Si `seq_end - seq_start + 1 ≠ receipt_count`, il y a un gap. |
| **Z-R3** | Le Z-report ne peut être généré que si aucune commande de la journée n'est en état `PAYMENT_PENDING` | Toutes les commandes doivent être `PAID`, `CANCELLED`, ou `REFUNDED`. |
| **Z-R4** | Les totaux du Z-report doivent correspondre exactement à la somme des reçus individuels | `SUM(amount_ttc WHERE type=SALE) = total_sales_ttc` |
| **Z-R5** | La chaîne Z est indépendante de la chaîne des reçus mais suit le même principe HMAC | `z_reports[N].previous_z_hash = z_reports[N-1].z_hash` |
| **Z-R6** | Le Z-report est immutable après création | Pas d'UPDATE, pas de DELETE |
| **Z-R7** | Un jour sans vente produit quand même un Z-report avec totaux à zéro | Continuité de la chaîne Z |
| **Z-R8** | Le Z-report doit être générable même si le système a été down pendant la journée (rattrapage) | Le service lit les reçus en DB, pas en mémoire |

### C6.4.3 Algorithme Z-Report

```php
// Pseudo-code ZReportService::generate(int $branchId, Carbon $date)

public function generate(int $branchId, Carbon $date): ZReport
{
    return DB::transaction(function () use ($branchId, $date) {
        // 1. Vérifier qu'aucun Z-report n'existe déjà
        $existing = ZReport::where('branch_id', $branchId)
            ->where('report_date', $date)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            throw new ZReportAlreadyGeneratedException($branchId, $date);
        }

        // 2. Vérifier qu'aucune commande n'est en PAYMENT_PENDING
        $pendingCount = Order::where('branch_id', $branchId)
            ->whereDate('created_at', $date)
            ->where('payment_status', PaymentStatus::PENDING)
            ->count();
        if ($pendingCount > 0) {
            throw new PendingOrdersExistException($branchId, $date, $pendingCount);
        }

        // 3. Collecter tous les reçus de la journée
        $receipts = FiscalReceipt::where('branch_id', $branchId)
            ->whereDate('created_at', $date)
            ->orderBy('fiscal_sequence_number')
            ->lockForUpdate()
            ->get();

        // 4. Vérifier continuité séquence
        if ($receipts->isNotEmpty()) {
            $seqStart = $receipts->first()->fiscal_sequence_number;
            $seqEnd   = $receipts->last()->fiscal_sequence_number;
            $expected = $seqEnd - $seqStart + 1;
            if ($expected !== $receipts->count()) {
                throw new FiscalSequenceGapException($branchId, $seqStart, $seqEnd, $receipts->count());
            }
        }

        // 5. Calculer totaux
        $totalSales   = $receipts->where('type', 'SALE')->sum('amount_ttc');
        $totalRefunds = $receipts->where('type', 'REFUND')->sum('amount_ttc');
        $totalTax     = $receipts->sum('tax_amount');
        $breakdown    = $receipts->groupBy('payment_method')
            ->map(fn ($group) => $group->sum('amount_ttc'));

        // 6. Chaîne Z
        $previousZ = ZReport::where('branch_id', $branchId)
            ->orderByDesc('report_date')
            ->lockForUpdate()
            ->first();
        $previousZHash = $previousZ
            ? $previousZ->z_hash
            : hash_hmac('sha256', "INIT_Z_BRANCH_{$branchId}", config('fiscal.hmac_secret'));

        // 7. Construire payload et hash
        $payload = canonical_json([
            'branch_id'       => $branchId,
            'report_date'     => $date->toDateString(),
            'fiscal_seq_start'=> $receipts->first()?->fiscal_sequence_number,
            'fiscal_seq_end'  => $receipts->last()?->fiscal_sequence_number,
            'total_sales_ttc' => $totalSales,
            'total_refunds_ttc' => $totalRefunds,
            'total_net_ttc'   => $totalSales + $totalRefunds,
            'total_tax'       => $totalTax,
            'receipt_count'   => $receipts->count(),
            'payment_breakdown' => $breakdown,
            'previous_z_hash' => $previousZHash,
        ]);
        $zHash = hash_hmac('sha256', $payload, config('fiscal.hmac_secret'));

        // 8. Insérer
        $zReport = ZReport::create([
            'branch_id'        => $branchId,
            'report_date'      => $date,
            'fiscal_seq_start' => $receipts->first()?->fiscal_sequence_number,
            'fiscal_seq_end'   => $receipts->last()?->fiscal_sequence_number,
            'total_sales_ttc'  => $totalSales,
            'total_refunds_ttc'=> $totalRefunds,
            'total_net_ttc'    => $totalSales + $totalRefunds,
            'total_tax'        => $totalTax,
            'receipt_count'    => $receipts->count(),
            'payment_breakdown'=> $breakdown,
            'previous_z_hash'  => $previousZHash,
            'z_hash'           => $zHash,
            'z_payload'        => $payload,
            'operator_id'      => auth()->id(),
        ]);

        // 9. Marquer les reçus comme clôturés
        FiscalReceipt::where('branch_id', $branchId)
            ->whereDate('created_at', $date)
            ->update(['z_report_id' => $zReport->id]);

        return $zReport;
    });
}
```

---

## C6.5 Jeux de tests — Données minimales reproductibles

### C6.5.1 Seed data commune à tous les tests C6

```php
// tests/Feature/Fiscal/FiscalTestCase.php

abstract class FiscalTestCase extends TestCase
{
    protected Branch $branch;
    protected User $cashier;
    protected Item $item1;
    protected Item $item2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['id' => 1]);
        $this->cashier = User::factory()->create([
            'branch_id' => 1,
            'role'      => 'cashier',
        ]);

        $this->item1 = Item::factory()->create([
            'branch_id' => 1,
            'name'      => 'Burger Classic',
            'price'     => 1250, // 12.50€ en centimes
            'tax_rate'  => 1000, // 10.00%
        ]);

        $this->item2 = Item::factory()->create([
            'branch_id' => 1,
            'name'      => 'Frites',
            'price'     => 450,  // 4.50€
            'tax_rate'  => 1000,
        ]);

        StockLevel::factory()->create([
            'branch_id' => 1,
            'item_id'   => $this->item1->id,
            'quantity'   => 500,
        ]);
        StockLevel::factory()->create([
            'branch_id' => 1,
            'item_id'   => $this->item2->id,
            'quantity'   => 500,
        ]);

        // Clé HMAC pour les tests
        config(['fiscal.hmac_secret' => 'test-hmac-secret-key-for-fiscal']);
    }
}
```

---

### C6.5.2 Test F1: Fiscal NULL à la création (toutes les sources)

```php
// tests/Feature/Fiscal/FiscalNullAtCreationTest.php

class FiscalNullAtCreationTest extends FiscalTestCase
{
    /** @test */
    public function kiosk_card_order_has_null_fiscal_before_payment_confirmation()
    {
        // Simuler création commande kiosk (paiement carte en cours)
        $order = app(FrontendOrderService::class)->create([
            'branch_id'      => 1,
            'source'         => 'kiosk',
            'payment_method' => PosPaymentMethod::CARD,
            'items'          => [
                ['item_id' => $this->item1->id, 'quantity' => 1],
            ],
        ]);

        $this->assertNull($order->fiscal_sequence_number);
        $this->assertNull($order->fiscal_hash);

        // Vérifier qu'aucun re


---

# Tour 5 — SUITE ULTRA

<!-- usage tour 5: input≈2449 output≈7594 stop=end_turn cumul≈46934 -->

# FOODKING C9–C10 & MATRICE AUTHZ / RÔLES (EXHAUSTIF)

**Date**: 2026-04-28
**Contexte**: Développement complet des chantiers C9 (Dashboard Management réel) et C10 (Composer Sync propagation), plus audit exhaustif de la matrice d'autorisation par rôle. Aucun fichier lu directement — exigences à vérifier puis implémenter.

---

## C9 — DASHBOARD MANAGEMENT RÉEL

### C9.0 Définition du problème

Aucun test e2e ne prouve qu'un restaurateur peut effectuer le parcours complet de gestion de son menu depuis le dashboard admin. Les tests existants couvrent des fragments backend (controllers, requests, services) mais pas le workflow UI complet. Un restaurateur réel doit pouvoir:

1. Se connecter au dashboard
2. Créer/modifier/supprimer des catégories
3. Créer/modifier/supprimer des produits
4. Uploader/remplacer/supprimer des photos produit
5. Gérer le stock (quantités, seuils d'alerte, rupture manuelle)
6. Créer/modifier/publier des profils composer (menus composés)
7. Voir la propagation vers kiosk et POS
8. Gérer les disponibilités par branche

Si un seul maillon de cette chaîne est cassé, le restaurateur est bloqué et le système est inutilisable en production.

---

### C9.1 Rôles impliqués dans le dashboard

| Rôle | Code attendu | Accès dashboard | Périmètre |
|---|---|---|---|
| **Super Admin** | `superadmin` | Complet | Toutes branches, tous modules, configuration système |
| **Branch Admin** | `branch_admin` | Complet sur sa branche | Sa branche uniquement: menu, stock, commandes, rapports |
| **Manager** | `manager` | Partiel | Sa branche: menu (lecture+écriture), stock (lecture+écriture), commandes (lecture), rapports (lecture) |
| **Cashier** | `cashier` | POS uniquement | Pas d'accès dashboard admin. POS + counter-collect uniquement |
| **Kitchen** | `kitchen` | KDS uniquement | Pas d'accès dashboard admin. KDS uniquement |
| **Kiosk** | `kiosk` (ou anonymous) | Kiosk uniquement | Pas d'accès dashboard. Borne publique |
| **Customer** | `customer` (ou anonymous) | Frontend client | Pas d'accès dashboard. Commande en ligne uniquement |

---

### C9.2 Parcours UI — Scénarios détaillés

#### C9-S1: Gestion catégories

| Step | Action | Endpoint backend attendu | Assertion UI | Assertion DB |
|---|---|---|---|---|
| 1 | Login branch_admin | `POST /api/auth/login` | Dashboard affiché | Session créée |
| 2 | Naviguer vers "Catégories" | `GET /api/admin/categories?branch_id=1` | Liste catégories affichée | — |
| 3 | Cliquer "Nouvelle catégorie" | — | Formulaire affiché | — |
| 4 | Remplir nom "Desserts", description, ordre d'affichage | — | Champs remplis | — |
| 5 | Uploader image catégorie | `POST /api/admin/categories` (multipart) | Preview image affichée | `categories` row créée, image stockée |
| 6 | Sauvegarder | — | Toast succès, retour liste, "Desserts" visible | `categories.name = 'Desserts'`, `branch_id = 1` |
| 7 | Modifier "Desserts" → "Desserts Maison" | `PUT /api/admin/categories/{id}` | Toast succès, nom mis à jour | `categories.name = 'Desserts Maison'` |
| 8 | Réordonner catégories (drag & drop) | `PATCH /api/admin/categories/reorder` | Ordre visuel mis à jour | `categories.sort_order` mis à jour |
| 9 | Supprimer catégorie vide | `DELETE /api/admin/categories/{id}` | Toast succès, catégorie disparue | Row supprimée |
| 10 | Tenter supprimer catégorie avec produits | `DELETE /api/admin/categories/{id}` | Toast erreur "Catégorie non vide" | Row **non** supprimée, 422 |

**Vérification propagation**:
| Step | Action | Assertion |
|---|---|---|
| 11 | Ouvrir kiosk dans un autre onglet | Catégorie "Desserts Maison" visible (après refresh ou event CatalogChanged) |
| 12 | Ouvrir POS dans un autre onglet | Catégorie "Desserts Maison" visible dans le menu POS |

---

#### C9-S2: Gestion produits (CRUD complet)

| Step | Action | Endpoint | Assertion UI | Assertion DB |
|---|---|---|---|---|
| 1 | Naviguer vers "Produits" | `GET /api/admin/items?branch_id=1` | Liste produits | — |
| 2 | Cliquer "Nouveau produit" | — | Formulaire produit | — |
| 3 | Remplir: nom "Tiramisu", catégorie "Desserts Maison", prix 8.50€, TVA 10%, description | — | Champs remplis | — |
| 4 | Uploader photo principale | `POST /api/admin/items` (multipart) ou `POST /api/admin/items/{id}/images` | Preview photo | — |
| 5 | Uploader 2 photos supplémentaires | `POST /api/admin/items/{id}/images` | Galerie 3 photos | `item_images` 3 rows |
| 6 | Sauvegarder | — | Toast succès, retour liste | `items` row créée, `price = 850`, `tax_rate = 1000`, `branch_id = 1` |
| 7 | Vérifier que le prix est stocké en centimes | — | Affichage "8,50 €" | `items.price = 850` (pas 8.5, pas 8500) |
| 8 | Modifier prix → 9.00€ | `PUT /api/admin/items/{id}` | Toast succès | `items.price = 900` |
| 9 | Supprimer photo #2 | `DELETE /api/admin/items/{id}/images/{imageId}` | Galerie 2 photos | `item_images` row supprimée, fichier supprimé du storage |
| 10 | Désactiver produit (toggle disponibilité) | `PATCH /api/admin/items/{id}/availability` | Badge "Indisponible" | `items.is_available = false` ou `branch_item_availabilities` mis à jour |
| 11 | Supprimer produit sans commandes | `DELETE /api/admin/items/{id}` | Toast succès | Soft delete ou hard delete selon policy |
| 12 | Tenter supprimer produit avec commandes historiques | `DELETE /api/admin/items/{id}` | Toast erreur ou soft-delete automatique | Pas de hard delete si FK commandes |

**Vérifications critiques prix SSOT**:

| Vérification | Méthode | Attendu |
|---|---|---|
| Le frontend n'envoie pas un prix au backend | Inspecter payload `POST /api/admin/items` | Le prix est dans le body admin, mais le backend le valide et le stocke. Le frontend kiosk/POS ne peut pas forger un prix différent lors de la commande. |
| Le prix affiché sur kiosk correspond au prix DB | Après modification prix, refresh kiosk | Prix mis à jour |
| Le prix dans la commande est celui du moment de la commande | Créer commande, puis modifier prix, vérifier que la commande historique garde l'ancien prix | `order_items.unit_price` = prix au moment de la commande |

---

#### C9-S3: Gestion stock

| Step | Action | Endpoint | Assertion UI | Assertion DB |
|---|---|---|---|---|
| 1 | Naviguer vers "Stock" ou onglet stock d'un produit | `GET /api/admin/stock-levels?branch_id=1` | Tableau stock par produit | — |
| 2 | Modifier quantité "Tiramisu" → 50 | `PUT /api/admin/stock-levels/{id}` ou `PATCH` | Quantité affichée "50" | `stock_levels.quantity = 50` |
| 3 | Définir seuil alerte → 5 | `PATCH /api/admin/stock-levels/{id}` | Seuil affiché | `stock_levels.alert_threshold = 5` |
| 4 | Forcer rupture manuelle | `PATCH /api/admin/stock-levels/{id}` avec `force_rupture = true` | Badge "Rupture" | `stock_levels.is_force_ruptured = true` ou `quantity = 0` |
| 5 | Vérifier propagation rupture → kiosk | Ouvrir kiosk | "Tiramisu" grisé ou masqué | — |
| 6 | Vérifier propagation rupture → POS | Ouvrir POS | "Tiramisu" marqué indisponible | — |
| 7 | Lever la rupture manuelle | `PATCH /api/admin/stock-levels/{id}` avec `force_rupture = false` | Badge "Rupture" disparaît | `is_force_ruptured = false` |
| 8 | Vérifier que le stock se décrémente après commande | Créer commande kiosk avec 2x Tiramisu | Stock passe de 50 à 48 | `stock_levels.quantity = 48`, `stock_movements` +1 entry |
| 9 | Vérifier stock movement append-only | Tenter de modifier un mouvement | Pas d'UI de modification | Pas d'UPDATE sur `stock_movements` |
| 10 | Vérifier isolation branch | Login branch_admin branch 2 | Ne voit pas le stock de branch 1 | Query scopée `branch_id = 2` |

---

#### C9-S4: Gestion composer (menus composés)

| Step | Action | Endpoint | Assertion UI | Assertion DB |
|---|---|---|---|---|
| 1 | Naviguer vers "Composer" ou "Menus composés" | `GET /api/admin/composer-profiles?branch_id=1` | Liste profils composer | — |
| 2 | Créer profil "Menu Midi" | `POST /api/admin/composer-profiles` | Formulaire wizard | `composer_profiles` row créée |
| 3 | Ajouter Step 1: "Entrée" (choix parmi 3 items) | `POST /api/admin/composer-profiles/{id}/steps` | Step affiché dans wizard | `composer_steps` row |
| 4 | Ajouter Step 2: "Plat" (choix parmi 5 items) | `POST /api/admin/composer-profiles/{id}/steps` | Step 2 affiché | `composer_steps` row |
| 5 | Ajouter Step 3: "Dessert" (optionnel, choix parmi 3 items) | `POST /api/admin/composer-profiles/{id}/steps` | Step 3 marqué optionnel | `composer_steps.is_optional = true` |
| 6 | Ajouter Step 4: "Boisson" (addon avec supplément +2.00€) | `POST /api/admin/composer-profiles/{id}/steps` | Step 4 avec prix addon | `composer_steps.addon_price = 200` |
| 7 | Définir prix du menu: 14.90€ | `PUT /api/admin/composer-profiles/{id}` | Prix affiché | `composer_profiles.price = 1490` |
| 8 | Preview du composer | — | Simulation du wizard tel que le client le verra | — |
| 9 | Publier le profil | `POST /api/admin/composer-profiles/{id}/publish` | Badge "Publié", toast succès | `composer_profiles.published_at = NOW()`, event `ComposerProfilePublished` dispatché |
| 10 | Vérifier propagation kiosk | Ouvrir kiosk | "Menu Midi" visible avec wizard 4 steps | — |
| 11 | Vérifier propagation POS | Ouvrir POS | "Menu Midi" sélectionnable | — |
| 12 | Modifier Step 2: retirer 1 item du choix | `PUT /api/admin/composer-profiles/{id}/steps/{stepId}` | Item retiré | `composer_step_items` row supprimée |
| 13 | Republier | `POST /api/admin/composer-profiles/{id}/publish` | Nouveau timestamp | `published_at` mis à jour, nouvel event |
| 14 | Dépublier | `POST /api/admin/composer-profiles/{id}/unpublish` | Badge "Brouillon" | `published_at = NULL`, event `CatalogChanged` |
| 15 | Vérifier disparition kiosk | Ouvrir kiosk | "Menu Midi" n'est plus visible | — |

---

#### C9-S5: Upload et propagation photos

| Step | Action | Assertion | Risque si échoue |
|---|---|---|---|
| 1 | Upload photo produit via dashboard | Photo stockée (disk local ou S3), thumbnail généré | Photo absente partout |
| 2 | Vérifier URL photo accessible publiquement | `GET /storage/items/{id}/photo.jpg` → 200 | 403/404 sur kiosk |
| 3 | Vérifier photo visible sur kiosk | Image chargée dans `<img>` kiosk | Borne affiche placeholder |
| 4 | Vérifier photo visible sur POS | Image chargée dans POS | Caissier ne reconnaît pas le produit |
| 5 | Remplacer photo | Ancienne supprimée du storage, nouvelle visible | Ancienne photo en cache |
| 6 | Supprimer photo | URL retourne 404, kiosk affiche placeholder | Photo fantôme |
| 7 | Upload photo > 5MB | Rejet 422 avec message clair | Stockage saturé |
| 8 | Upload fichier non-image (.pdf) | Rejet 422 | Faille sécurité |
| 9 | Upload photo avec EXIF malicieux | EXIF strippé, image safe | XSS via EXIF |

---

### C9.3 Critères PASS/FAIL C9

| Critère | PASS | FAIL |
|---|---|---|
| 5 scénarios S1-S5 passent en e2e Playwright | 5/5 | < 5/5 |
| Propagation catégorie → kiosk + POS | Visible < 10s | Non visible ou > 30s |
| Propagation produit + prix → kiosk + POS | Visible < 10s | Non visible |
| Propagation photo → kiosk + POS | Image chargée | 404 ou placeholder |
| Propagation rupture stock → kiosk + POS | Item grisé/masqué < 10s | Item toujours commandable |
| Propagation composer publish → kiosk + POS | Wizard visible < 10s | Non visible |
| Isolation branch: admin B2 ne voit pas données B1 | Aucune donnée B1 visible | Fuite cross-branch |
| Prix stocké en centimes, affiché correctement | Cohérent | Arrondi ou format incorrect |
| Suppression catégorie avec produits → rejet | 422 | Suppression silencieuse |
| Upload non-image → rejet | 422 | Accepté |
| Run-many 3x sans flake | 3/3 | < 3/3 |

---

## C10 — COMPOSER SYNC PROPAGATION

### C10.0 Chaîne d'events complète

```
┌──────────────────┐
│ Admin: Publish   │
│ ComposerProfile  │
└────┬─────────┘
         │
         ▼
┌──────────────────────────┐
│ ComposerProfilePublished │  ← Event Laravel
│ (dispatché afterCommit)  │
└────────┬─────────────────┘
         │
         ├──────────────────────────────────┐
         │                                  │
         ▼                                  ▼
┌─────────────────────┐          ┌──────────────────────────────┐
│ Listener:           │          │ Listener:                    │
│ UpdateMenuProjection│          │ PersistCatalogChangedToOutbox│
│                     │          │                              │
│ → MenuProjection    │          │ → outbox entry               │
│   Service recalcule │          │   {CatalogChanged, payload}  │
│   le menu kiosk/POS │          │                              │
└─────────────────────┘          └──────────────┬───────────────┘
                                                │
                                                ▼
                                 ┌──────────────────────────────┐
                                 │ DispatchDomainEventsJob      │
                                 │ (queue worker)               │
                                 │                              │
                                 │ → Broadcast CatalogChanged   │
                                 │   sur channel                │
                                 │   branch.{id}.catalog        │
                                 └──────────────┬───────────────┘
                                                │
                                    ┌───────────┼───────────┐
                                    │           │           │
                                    ▼           ▼           ▼
                              ┌──────────┐ ┌────────┐ ┌────────┐
                              │  KIOSK   │ │  POS   │ │ Other  │
                              │ (Echo    │ │ (Echo  │ │ subs   │
                              │  listen) │ │ listen)│ │        │
                              └────┬─────┘ └───┬────┘ └────────┘
                                   │           │
                                   ▼           ▼
                              kioskMenu.js  posOrder.js
                              → refetch     → refetch
                              menu API      menu API
```

### C10.1 Points de vérification dans la chaîne

| # | Point | Fichier(s) à vérifier | Assertion | Risque si cassé |
|---|---|---|---|---|
| CP-1 | `ComposerProfilePublished` est dispatché | `ComposerProfileController.php` (méthode publish) | `event(new ComposerProfilePublished($profile))` appelé | Aucune propagation |
| CP-2 | Event dispatché **après** commit | `ComposerProfilePublished` ou listener | `$afterCommit = true` ou dispatch dans `DB::afterCommit()` | Event dispatché mais DB rollback → état fantôme |
| CP-3 | Listener `UpdateMenuProjection` existe et est bindé | `EventServiceProvider.php` | `ComposerProfilePublished::class => [UpdateMenuProjection::class, ...]` | Menu projection pas mis à jour |
| CP-4 | `MenuProjectionService` recalcule correctement | `MenuProjectionService.php` | Le menu inclut le nouveau composer profile avec ses steps, items, prix | Kiosk/POS affichent ancien menu |
| CP-5 | Listener outbox existe et est bindé | `EventServiceProvider.php` | `ComposerProfilePublished::class => [..., PersistCatalogChangedToOutbox::class]` | Pas de broadcast |
| CP-6 | Outbox entry créée avec bon payload | `PersistCatalogChangedToOutbox.php` | `outbox` row avec `event_type = 'CatalogChanged'`, `branch_id` correct | Broadcast avec mauvaises données |
| CP-7 | `DispatchDomainEventsJob` consomme l'entry | `DispatchDomainEventsJob.php` | Entry marquée `dispatched_at = NOW()` | Entry orpheline, pas de broadcast |
| CP-8 | Broadcast sur bon channel | Job ou event broadcastable | Channel = `branch.{branch_id}.catalog` | Mauvaise branche reçoit l'event |
| CP-9 | Kiosk écoute le channel | `kioskMenu.js` ou `KioskAppComponent.vue` | `Echo.channel('branch.1.catalog').listen('CatalogChanged', ...)` | Kiosk sourd |
| CP-10 | Kiosk refetch le menu après event | `kioskMenu.js` | Appel `GET /api/frontend/menu?branch_id=1` après réception event | Kiosk affiche ancien menu |
| CP-11 | POS écoute le channel | `posOrder.js` ou `PosComponent.vue` | Même pattern que kiosk | POS sourd |
| CP-12 | POS refetch le menu après event | `posOrder.js` | Appel API menu | POS affiche ancien menu |
| CP-13 | Le menu refetché inclut le composer profile | API menu response | JSON contient le profil avec steps et items | Composer invisible |
| CP-14 | Le kiosk wizard fonctionne avec le nouveau profil | `KioskWizardComponent.vue` | Navigation steps, sélection items, calcul prix | Wizard cassé |
| CP-15 | Le POS wizard fonctionne avec le nouveau profil | `KioskPosWizardComponent.vue` ou équivalent POS | Idem | Wizard POS cassé |

### C10.2 Tests C10

#### C10-T1: Publish → Outbox → Broadcast (backend)

```php
// tests/Feature/Composer/ComposerPublishPropagationTest.php

class ComposerPublishPropagationTest extends TestCase
{
    /** @test */
    public function publishing_composer_profile_creates_outbox_entry_and_updates_menu_projection()
    {
        Event::fake([CatalogChanged::class]); // fake seulement le broadcast, pas les listeners
        
        $branch = Branch::factory()->create();
        $profile = ComposerProfile::factory()
            ->for($branch)
            ->has(ComposerStep::factory()->count(3))
            ->create(['published_at' => null]);

        $this->actingAs(User::factory()->branchAdmin($branch)->create());

        $response = $this->postJson("/api/admin/composer-profiles/{$profile->id}/publish");

        $response->assertOk();

        // DB: published_at set
        $profile->refresh();
        $this->assertNotNull($profile->published_at);

        // Outbox: entry créée
        $this->assertDatabaseHas('outbox', [
            'aggregate_type' => 'Catalog',
            'event_type'     => 'CatalogChanged',
            'branch_id'      => $branch->id,
            'dispatched_at'  => null, // pas encore dispatché
        ]);

        // Menu projection: profil inclus
        $menu = app(MenuProjectionService::class)->getForBranch($branch->id);
        $composerInMenu = collect($menu['composer_profiles'] ?? [])
            ->firstWhere('id', $profile->id);
        $this->assertNotNull($composerInMenu);
        $this->assertCount(3, $composerInMenu['steps']);
    }

    /** @test */
    public function unpublishing_removes_from_menu_and_creates_outbox_entry()
    {
        $branch = Branch::factory()->create();
        $profile = ComposerProfile::factory()
            ->for($branch)
            ->published()
            ->create();

        $this->actingAs(User::factory()->branchAdmin($branch)->create());

        $response = $this->postJson("/api/admin/composer-profiles/{$profile->id}/unpublish");

        $response->assertOk();

        $profile->refresh();
        $this->assertNull($profile->published_at);

        // Menu projection: profil absent
        $menu = app(MenuProjectionService::class)->getForBranch($branch->id);
        $composerInMenu = collect($menu['composer_profiles'] ?? [])
            ->firstWhere('id', $profile->id);
        $this->assertNull($composerInMenu);

        // Outbox: entry créée
        $this->assertDatabaseHas('outbox', [
            'event_type' => 'CatalogChanged',
            'branch_id'  => $branch->id,
        ]);
    }

    /** @test */
    public function publish_from_other_branch_admin_is_forbidden()
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $profile = ComposerProfile::factory()->for($branch1)->create();

        $this->actingAs(User::factory()->branchAdmin($branch2)->create());

        $response = $this->postJson("/api/admin/composer-profiles/{$profile->id}/publish");

        $response->assertForbidden();
    }
}
```

#### C10-T2: End-to-end Playwright — Publish → Kiosk voit le composer

```javascript
// tests/e2e/c10-composer-sync-propagation.spec.js

test.describe('C10 — Composer Sync Propagation', () => {
  let adminPage, kioskPage;

  test.beforeAll(async ({ browser }) => {
    const adminCtx = await browser.newContext();
    const kioskCtx = await browser.newContext();
    adminPage = await adminCtx.newPage();
    kioskPage = await kioskCtx.newPage();

    // Login admin
    await adminPage.goto('/login');
    await adminPage.fill('[data-test="email"]', 'admin@branch1.test');
    await adminPage.fill('[data-test="password"]', 'password');
    await adminPage.click('[data-test="login-submit"]');

    // Kiosk
    await kioskPage.goto('/kiosk?branch_id=1');
  });

  test('Publish composer profile → visible on kiosk within 10s', async () => {
    // Admin: créer et publier un composer profile
    await adminPage.goto('/admin/composer-profiles/create');
    await adminPage.fill('[data-test="composer-name"]', 'Menu Test C10');
    await adminPage.fill('[data-test="composer-price"]', '14.90');
    // ... ajouter steps ...
    await adminPage.click('[data-test="save-composer"]');
    await adminPage.click('[data-test="publish-composer"]');
    await expect(adminPage.locator('[data-test="published-badge"]')).toBeVisible();

    const tPublish = Date.now();

    // Kiosk: attendre apparition (sans refresh manuel)
    await expect(
      kioskPage.locator('[data-test="menu-item"]:has-text("Menu Test C10")')
    ).toBeVisible({ timeout: 10000 });

    const tVisible = Date.now();
    console.log(`C10 propagation delay: ${tVisible - tPublish}ms`);
    expect(tVisible - tPublish).toBeLessThan(10000);
  });

  test('Unpublish → disappears from kiosk within 10s', async () => {
    await adminPage.goto('/admin/composer-profiles');
    await adminPage.click('[data-test="composer-row-Menu Test C10"]');
    await adminPage.click('[data-test="unpublish-composer"]');

    await expect(
      kioskPage.locator('[data-test="menu-item"]:has-text("Menu Test C10")')
    ).not.toBeVisible({ timeout: 10000 });
  });
});
```

---

## MATRICE AUTHZ — AUDIT EXHAUSTIF

### M.0 Méthodologie

Chaque endpoint de l'API admin et frontend doit être vérifié contre chaque rôle. La matrice ci-dessous liste l'attendu. Tout écart est un finding P0 (élévation de privilèges) ou P1 (fuite de données).

Convention:
- ✅ = Autorisé
- ❌ = Interdit (403 Forbidden)
- 🔒 = Interdit (401 Unauthorized)
- 🔀 = Autorisé mais scopé à sa branche uniquement
- ⚠️ = À vérifier — risque d'élévation

---

### M.1 Endpoints Catégories

| Endpoint | Method | superadmin | branch_admin | manager | cashier | kitchen | kiosk/anon | customer |
|---|---|---|---|---|---|---|---|---|
| `/api/admin/categories` | GET | ✅ all | 🔀 own branch | 🔀 own branch | ❌ | ❌ | ❌ | ❌ |
| `/api/admin/categories` | POST | ✅ any branch | 🔀 own branch | ❌ | ❌ | ❌ | ❌ |
| `/api/admin/categories/{id}` | PUT | ✅ | 🔀 own branch | ❌ | ❌ | ❌ | ❌ |
| `/api/admin/categories/{id}` | DELETE | ✅ | 🔀 own branch | ⚠️ peut-être interdit | ❌ | ❌ | ❌ | ❌ |
| `/api/admin/categories/reorder` | PATCH | ✅ | 🔀 own branch | ❌ | ❌ | ❌ | ❌ |
| `/api/frontend/categories` | GET | ✅ | ✅ | ✅ filtered branch | ✅ filtered branch |

**Risques d'élévation**:
- `branch_admin` de branch 2 appelle `PUT /api/admin/categories/{id}` où `{id}` appartient à branch 1 → doit retourner 403
- `manager` appelle `DELETE` → politique à définir (certains systèmes interdisent la suppression aux managers)
- `cashier` appelle `POST /api/admin/categories` → doit retourner 403

---

### M.2 Endpoints Produits (Items)

| Endpoint | Method | superadmin | branch_admin | manager | cashier | kitchen | kiosk/an


---

# Tour 6 — SUITE ULTRA

<!-- usage tour 6: input≈3659 output≈7997 stop=end_turn cumul≈58590 -->



# FOODKING C8 + REALTIME / BROADCAST / RECONNEXION / RÉSILIENCE (EXHAUSTIF)

**Date**: 2026-04-28
**Contexte**: Développement complet du chantier C8 (Payment lifecycle, state machines, counter deferred) et de la couche realtime (broadcast, WebSocket, reconnexion, fallback, observabilité). Aucun fichier lu directement.

---

## C8 — PAYMENT LIFECYCLE & STATE MACHINES

### C8.0 Périmètre

Le paiement dans FoodKing traverse plusieurs chemins selon la source (kiosk card, kiosk cash-at-counter, POS cash, POS card) et chaque chemin a des implications différentes sur:
- L'état de la commande (`OrderStatus`)
- L'état du paiement (`PaymentStatus`)
- L'allocation fiscale (cf. C6)
- Le stock (décrémenté quand? releasé quand?)
- La propagation KDS/OSS (quand la commande devient visible en cuisine)
- Le queue number (attribué quand?)

Un seul chemin mal câblé = commande fantôme, double encaissement, stock incohérent, ou violation NF525.

---

### C8.1 Diagramme complet — OrderStateMachine

```
                         ┌─────────────┐
                         │   CREATED   │
                         │             │
                         │ (initial)   │
                         └──┬───┬──┘
                            │   │   │
              ┌─────────────┘   │   └─────────────┐
              │                 │                 │
              ▼                 ▼                 ▼
     ┌────────────────┐ ┌────────────┐  ┌────────────────┐
     │   CONFIRMED    │ │  PENDING   │  │   CANCELLED    │
     │                │ │ _PAYMENT   │  │                │
     │ (paiement OK,  │ │            │  │ (annulé avant  │
     │  attente       │ │ (kiosk     │  │  toute action) │
     │  cuisine)      │ │  cash-at-  │  │                │
     └──┬──────┬──────┘ │  counter)  │  └────────────────┘
        │      │        └──┬─────┬──┘         ▲
        │      │           │     │            │
        │      │           │     └────────────┘
        │      │           │     (timeout/cancel
        │      │           │      → CANCELLED)
        │      │           │
        │      │           ▼
        │      │  ┌────────────────┐
        │      │  │   CONFIRMED    │
        │      │  │ (caissier      │
        │      │  │  encaisse)     │
        │      │  └──┬─────────────┘
        │      │     │
        │      └─────┤
        │            │
        ▼            ▼
   ┌────────────────────┐
   │    PREPARING       │
   │                    │
   │ (KDS a pris en     │
   │  charge)           │
   └────────┬───────────┘
            │
            ▼
   ┌────────────────────┐
   │      READY         │
   │                    │
   │ (KDS bump:         │
   │  prêt à servir)    │
   └────────┬───────────┘
            │
            ├──────────────────┐
            │                  │
            ▼                  ▼
   ┌────────────────┐  ┌────────────────┐
   │   DELIVERED    │  │   PICKED_UP    │
   │                │  │                │
   │ (livraison     │  │ (client        │
   │  confirmée)    │  │  récupère)     │
   └────────┬───────┘  └────────┬───────┘
            │                  │
            └────┬─────────┘
                     │
                     ▼
            ┌────────────────┐
            │   COMPLETED    │
            │                │
            │ (terminal)     │
            └────────────────┘
```

### C8.1.1 Transitions autorisées — Matrice formelle

| Source | Destination | Trigger | Condition | Side effects |
|---|---|---|---|---|
| `CREATED` | `CONFIRMED` | Paiement immédiat réussi (kiosk card, POS cash, POS card) | `PaymentStatus` transite vers `PAID` | Fiscal alloc, stock décrémenté, KDS notifié, queue number attribué |
| `CREATED` | `PENDING_PAYMENT` | Kiosk cash-at-counter sélectionné | Commande créée avec `payment_method = CASH_AT_COUNTER` | Stock décrémenté (réservation), queue number attribué, KDS **PAS** notifié (ou notifié avec flag "attente paiement") |
| `CREATED` | `CANCELLED` | Annulation immédiate (timeout, erreur paiement) | Aucun paiement reçu | Stock releasé si décrémenté, pas de fiscal |
| `PENDING_PAYMENT` | `CONFIRMED` | Caissier confirme encaissement counter | Caissier clique "Encaissé" | Fiscal alloc, KDS notifié |
| `PENDING_PAYMENT` | `CANCELLED` | Timeout ou caissier annule | Pas de paiement reçu | Stock releasé, pas de fiscal |
| `CONFIRMED` | `PREPARING` | KDS prend en charge | Commande visible sur KDS | — |
| `CONFIRMED` | `CANCELLED` | Annulation post-confirmation (rare, admin only) | Remboursement si déjà payé | Stock releasé, fiscal reçu négatif si payé |
| `PREPARING` | `READY` | KDS bump | Cuisine terminée | OSS notifié, client notifié |
| `READY` | `DELIVERED` | Livreur confirme | Type = livraison | — |
| `READY` | `PICKED_UP` | Client récupère ou caissier confirme | Type = sur place ou à emporter | — |
| `DELIVERED` | `COMPLETED` | Auto ou confirmation | — | — |
| `PICKED_UP` | `COMPLETED` | Auto ou confirmation | — | — |

### C8.1.2 Transitions INTERDITES — Chaque violation est un bug P0

| Source | Destination interdite | Pourquoi |
|---|---|---|
| `CREATED` | `PREPARING` | Pas de paiement, pas de confirmation |
| `CREATED` | `READY` | Skip de toute la chaîne |
| `CREATED` | `COMPLETED` | Skip total |
| `PENDING_PAYMENT` | `PREPARING` | Pas encore payé |
| `PENDING_PAYMENT` | `READY` | Pas encore payé |
| `CANCELLED` | Tout | Terminal, irréversible |
| `COMPLETED` | Tout | Terminal, irréversible |
| `READY` | `PREPARING` | Pas de retour en arrière |
| `READY` | `CONFIRMED` | Pas de retour en arrière |
| `PREPARING` | `CONFIRMED` | Pas de retour en arrière |
| `DELIVERED` | `READY` | Pas de retour en arrière |

---

### C8.2 PaymentStateMachine

```
              ┌──────────────┐
              │   PENDING    │
              │              │
              │ (initial)    │
              └──┬───┬───┬───┘
                 │   │   │
     ┌───────────┘   │   └───────────┐
     │               │               │
     ▼               ▼               ▼
┌──────────┐  ┌────────────┐  ┌────────────┐
│PROCESSING│  │   PAID     │  │  FAILED    │
│          │  │            │  │            │
│(TPE en   │  │(cash POS   │  │(TPE refusé │
│ cours)   │  │ immédiat)  │  │ ou erreur) │
└────┬──┬──┘  └──┬─────────┘  └──────┬─────┘
     │  │        │                   │
     │  │        │                   ▼
     │  │        │            ┌────────────┐
     │  │        │            │ CANCELLED  │
     │  │        │            │ (terminal) │
     │  │        │            └────────────┘
     │  │        │
     │  └────────┤ (TPE success)
     │           │
     │           ▼
     │    ┌────────────────┐
     │    │   REFUNDING    │
     │    │                │
     │    │ (remboursement │
     │    │  en cours)     │
     │    └──────┬─────────┘
     │           │
     │           ▼
     │    ┌────────────────┐
     │    │   REFUNDED     │
     │    │                │
     │    │ (terminal)     │
     │    └────────────────┘
     │
     ▼
┌──────────┐
│  FAILED  │
│(TPE fail)│
└──────────┘
```

### C8.2.1 Transitions PaymentStatus — Matrice

| Source | Destination | Trigger | Fichier attendu | Side effect |
|---|---|---|---|---|
| `PENDING` | `PROCESSING` | Kiosk initie paiement TPE | `KioskPaymentComponent.vue` → API → `PaymentService.php` | — |
| `PENDING` | `PAID` | POS cash immédiat | `PosController.php` → `PaymentService.php` | Fiscal alloc, order → CONFIRMED |
| `PENDING` | `FAILED` | Erreur immédiate (montant invalide, etc.) | `PaymentService.php` | Order → CANCELLED |
| `PROCESSING` | `PAID` | TPE callback success | `PaymentService.php` (webhook ou polling) | Fiscal alloc, order → CONFIRMED |
| `PROCESSING` | `FAILED` | TPE callback failure | `PaymentService.php` | Order → CANCELLED |
| `PAID` | `REFUNDING` | Admin initie remboursement | `PaymentService.php` | — |
| `REFUNDING` | `REFUNDED` | Remboursement confirmé (TPE ou manuel) | `PaymentService.php` | Fiscal reçu négatif, stock releasé |
| `FAILED` | `CANCELLED` | Cleanup automatique | `PaymentService.php` ou job | — |

### C8.2.2 Couplage OrderStatus ↔ PaymentStatus

Ce couplage est la source de bugs la plus probable. Chaque transition PaymentStatus doit déclencher la bonne transition OrderStatus.

| PaymentStatus transition | OrderStatus transition attendue | Invariant |
|---|---|---|
| `PENDING → PAID` (POS cash) | `CREATED → CONFIRMED` | Atomique dans même transaction |
| `PROCESSING → PAID` (kiosk card) | `CREATED → CONFIRMED` | Atomique |
| `PENDING → PAID` (counter confirm) | `PENDING_PAYMENT → CONFIRMED` | Atomique |
| `PROCESSING → FAILED` | `CREATED → CANCELLED` | Atomique |
| `PENDING → FAILED` | `CREATED → CANCELLED` ou `PENDING_PAYMENT → CANCELLED` | Atomique |
| `PAID → REFUNDING → REFUNDED` | Order reste `COMPLETED` ou passe à un état spécial | Fiscal reçu négatif créé |

**Risque critique**: Si `PaymentStatus` passe à `PAID` mais `OrderStatus` reste `CREATED` (transaction partielle ou event perdu), la commande est payée mais jamais envoyée en cuisine. Le client paie et ne reçoit rien.

---

### C8.3 Chemins de paiement — Détail par source

#### C8.3.1 Kiosk Card (paiement TPE immédiat)

```
Timeline:
─────────────────────────────────
t0: Client valide panier kiosk
    → FrontendOrderService::create()
    → BEGIN TX
       INSERT order (status=CREATED, payment_status=PENDING)
       INSERT order_items
       Decrement stock_levels (réservation)
       INSERT stock_movements
       INSERT outbox (OrderCreated) — mais PAS de notif KDS encore
       Assign queue_number
    → COMMIT
    → afterCommit: dispatch OrderCreated (outbox)

t1: Kiosk initie paiement TPE
    → PaymentService::initiate()
    → BEGIN TX
       UPDATE order SET payment_status=PROCESSING
    → COMMIT

t2: TPE callback success
    → PaymentService::confirm()
    → BEGIN TX
       UPDATE order SET payment_status=PAID, status=CONFIRMED
       FiscalSealingService::allocate() → fiscal_sequence_number
       INSERT fiscal_receipt (HMAC chain)
       INSERT outbox (OrderStatusChanged → CONFIRMED)
    → COMMIT
    → afterCommit: dispatch OrderStatusChanged
       → broadcast branch.{id}.orders
       → KDS reçoit la commande
       → OSS reçoit la commande

t3: Kiosk affiche confirmation + queue number
    → KioskConfirmationComponent.vue

t4: Timer auto-return → KioskWaitingComponent → home
```

**Points de vérification**:

| # | Check | Attendu | Risque |
|---|---|---|---|
| K-1 | Stock décrémenté à t0, pas à t2 | Oui — réservation immédiate | Si décrémenté à t2, un autre client peut commander un item en rupture |
| K-2 | KDS notifié à t2, pas à t0 | Oui — pas de commande non payée en cuisine | Si notifié à t0, cuisine prépare une commande qui ne sera peut-être jamais payée |
| K-3 | Fiscal alloué à t2, pas à t0 | Oui — NF525 | Si alloué à t0, séquence fiscale pour commande non payée |
| K-4 | Queue number attribué à t0 | Oui — le client doit voir son numéro même avant paiement | Discutable: certains systèmes attribuent après paiement |
| K-5 | Si TPE fail à t2 → stock releasé | Oui | Stock bloqué indéfiniment |
| K-6 | Si TPE timeout → cleanup job release stock | Oui, avec délai configurable | Stock bloqué |

#### C8.3.2 Kiosk Cash-at-Counter (paiement différé)

```
Timeline:
─────────────────────────────────────────────────────────────────
t0: Client valide panier kiosk, choisit "Payer au comptoir"
    → FrontendOrderService::create()
    → BEGIN TX
       INSERT order (status=PENDING_PAYMENT, payment_status=PENDING,
                     payment_method=CASH_AT_COUNTER)
       INSERT order_items
       Decrement stock_levels (réservation)
       INSERT stock_movements
       INSERT outbox (OrderCreated)
       Assign queue_number
    → COMMIT
    → afterCommit: dispatch OrderCreated
       → broadcast: POS reçoit notification "commande en attente de paiement"

t1: Kiosk affiche "Rendez-vous au comptoir avec le numéro Q"
    → KioskCashInstructionComponent.vue

t2: Timer auto-return → home

t3a: Caissier voit la commande en attente sur POS
     → POS affiche liste commandes PENDING_PAYMENT
     → Caissier clique "Encaisser"
     → PaymentService::confirmCounterPayment()
     → BEGIN TX
        UPDATE order SET payment_status=PAID, status=CONFIRMED
        FiscalSealingService::allocate()
        INSERT fiscal_receipt
        INSERT outbox (OrderPaidAtCounter)
        INSERT outbox (OrderStatusChanged → CONFIRMED)
     → COMMIT
     → afterCommit: dispatch events
        → KDS reçoit la commande (maintenant seulement)
        → OSS reçoit la commande

t3b: ALTERNATIVE — Timeout ou caissier annule
     → PaymentService::cancelCounterPayment()
     → BEGIN TX
        UPDATE order SET payment_status=FAILED, status=CANCELLED
        Release stock_levels
        INSERT stock_movements (type=release)
        INSERT outbox (OrderStatusChanged → CANCELLED)
        ⚠ PAS de fiscal_sequence_number (jamais payé)
     → COMMIT
```

**Points de vérification spécifiques cash-at-counter**:

| # | Check | Attendu | Risque si violé |
|---|---|---|---|
| CAC-1 | `fiscal_sequence_number` est `NULL` entre t0 et t3a | Oui | Violation NF525 |
| CAC-2 | KDS ne reçoit PAS la commande entre t0 et t3a | Oui — pas de préparation avant paiement | Cuisine prépare, client ne paie jamais, perte sèche |
| CAC-3 | POS voit la commande en `PENDING_PAYMENT` | Oui — le caissier doit pouvoir encaisser | Client attend indéfiniment au comptoir |
| CAC-4 | Stock est décrémenté à t0 (réservation) | Oui | Item commandé par un autre client pendant l'attente |
| CAC-5 | Stock est releasé à t3b (cancel) | Oui, exactement la quantité réservée | Stock bloqué définitivement |
| CAC-6 | Timeout configurable (ex: 10 min) | Oui | Commande en attente indéfinie, stock bloqué |
| CAC-7 | Cancel ne crée PAS de reçu fiscal | Oui | Reçu fiscal pour commande non payée |
| CAC-8 | Le broadcast `OrderPaidAtCounter` est distinct de `OrderCreated` | Oui — le KDS doit savoir que c'est un confirm, pas une nouvelle commande | KDS crée un doublon |

#### C8.3.3 POS Cash (paiement immédiat espèces)

```
Timeline:
─────────────────────────────────────────────────────────────────
t0: Caissier valide panier POS, sélectionne "Espèces", entre montant reçu
    → OrderService::create() + PaymentService::payImmediate()
    → BEGIN TX
       INSERT order (status=CONFIRMED, payment_status=PAID,
                     payment_method=CASH)
       INSERT order_items
       Decrement stock_levels
       INSERT stock_movements
       FiscalSealingService::allocate()
       INSERT fiscal_receipt
       Assign queue_number
       INSERT outbox (OrderCreated + OrderStatusChanged)
    → COMMIT
    → afterCommit: dispatch events
       → KDS reçoit immédiatement
       → OSS reçoit immédiatement

t1: POS affiche ticket + monnaie à rendre
```

**Particularité**: Tout est atomique en une seule transaction. C'est le chemin le plus simple mais aussi celui où un bug dans la transaction a le plus d'impact (tout ou rien).

| # | Check | Attendu |
|---|---|---|
| PC-1 | Monnaie à rendre = montant reçu - total | Calculé backend, pas frontend |
| PC-2 | Si montant reçu < total → rejet 422 | Pas de commande créée |
| PC-3 | Fiscal alloué dans la même TX | Atomique |
| PC-4 | Reprint ticket ne crée pas de nouveau fiscal | Lecture seule |

#### C8.3.4 POS Card (paiement TPE depuis caisse)

```
Timeline:
─────────────────────────────────────────────────────────────────
t0: Caissier valide panier POS, sélectionne "Carte"
    → OrderService::create()
    → BEGIN TX
       INSERT order (status=CREATED, payment_status=PENDING)
       INSERT order_items
       Decrement stock_levels
       Assign queue_number
    → COMMIT

t1: POS initie paiement TPE
    → PaymentService::initiate()
    → payment_status = PROCESSING

t2: TPE callback
    → Même flow que kiosk card (t2 dans C8.3.1)
```

---

### C8.4 Tests C8

#### C8.4.1 Test: Toutes les transitions autorisées

```php
// tests/Feature/Payment/OrderPaymentStateMachineFullMatrixTest.php

class OrderPaymentStateMachineFullMatrixTest extends TestCase
{
    /**
     * @test
     * @dataProvider allowedTransitionsProvider
     */
    public function allowed_transitions_succeed(
        string $fromOrder,
        string $fromPayment,
        string $toOrder,
        string $toPayment,
        string $trigger
    ) {
        $order = Order::factory()->create([
            'status'         => OrderStatus::from($fromOrder),
            'payment_status' => PaymentStatus::from($fromPayment),
            'branch_id'      => 1,
        ]);

        $machine = app(OrderStateMachine::class);
        $paymentMachine = app(PaymentStateMachine::class);

        // Exécuter la transition
        $result = match ($trigger) {
            'pay_immediate'     => $this->payImmediate($order),
            'initiate_tpe'      => $this->initiateTpe($order),
            'tpe_success'       => $this->tpeSuccess($order),
            'tpe_failure'       => $this->tpeFailure($order),
            'counter_confirm'   => $this->counterConfirm($order),
            'counter_cancel'    => $this->counterCancel($order),
            'kds_take'          => $this->kdsTake($order),
            'kds_bump'          => $this->kdsBump($order),
            'deliver'           => $this->deliver($order),
            'pickup'            => $this->pickup($order),
            'initiate_refund'   => $this->initiateRefund($order),
            'confirm_refund'    => $this->confirmRefund($order),
        };

        $order->refresh();
        $this->assertEquals(OrderStatus::from($toOrder), $order->status);
        $this->assertEquals(PaymentStatus::from($toPayment), $order->payment_status);
    }

    public static function allowedTransitionsProvider(): array
    {
        return [
            // [fromOrder, fromPayment, toOrder, toPayment, trigger]
            'POS cash immediate' => [
                'CREATED', 'PENDING', 'CONFIRMED', 'PAID', 'pay_immediate',
            ],
            'Kiosk card initiate' => [
                'CREATED', 'PENDING', 'CREATED', 'PROCESSING', 'initiate_tpe',
            ],
            'Kiosk card success' => [
                'CREATED', 'PROCESSING', 'CONFIRMED', 'PAID', 'tpe_success',
            ],
            'Kiosk card failure' => [
                'CREATED', 'PROCESSING', 'CANCELLED', 'FAILED', 'tpe_failure',
            ],
            'Counter confirm' => [
                'PENDING_PAYMENT', 'PENDING', 'CONFIRMED', 'PAID', 'counter_confirm',
            ],
            'Counter cancel' => [
                'PENDING_PAYMENT', 'PENDING', 'CANCELLED', 'FAILED', 'counter_cancel',
            ],
            'KDS take' => [
                'CONFIRMED', 'PAID', 'PREPARING', 'PAID', 'kds_take',
            ],
            'KDS bump' => [
                'PREPARING', 'PAID', 'READY', 'PAID', 'kds_bump',
            ],
            'Deliver' => [
                'READY', 'PAID', 'DELIVERED', 'PAID', 'deliver',
            ],
            'Pickup' => [
                'READY', 'PAID', 'PICKED_UP', 'PAID', 'pickup',
            ],
            'Initiate refund' => [
                'COMPLETED', 'PAID', 'COMPLETED', 'REFUNDING', 'initiate_refund',
            ],
            'Confirm refund' => [
                'COMPLETED', 'REFUNDING', 'COMPLETED', 'REFUNDED', 'confirm_refund',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider forbiddenTransitionsProvider
     */
    public function forbidden_transitions_throw(
        string $fromOrder,
        string $fromPayment,
        string $trigger
    ) {
        $order = Order::factory()->create([
            'status'         => OrderStatus::from($fromOrder),
            'payment_status' => PaymentStatus::from($fromPayment),
            'branch_id'      => 1,
        ]);

        $this->expectException(InvalidOrderTransitionException::class);

        match ($trigger) {
            'pay_immediate'   => $this->payImmediate($order),
            'kds_take'        => $this->kdsTake($order),
            'kds_bump'        => $this->kdsBump($order),
            'counter_confirm' => $this->counterConfirm($order),
        };
    }

    public static function forbiddenTransitionsProvider(): array
    {
        return [
            'Cannot prepare unpaid'         => ['CREATED', 'PENDING', 'kds_take'],
            'Cannot bump unconfirmed'       => ['CONFIRMED', 'PAID', 'kds_bump'],
            'Cannot pay cancelled'          => ['CANCELLED', 'FAILED', 'pay_immediate'],
            'Cannot confirm already paid'   => ['CONFIRMED', 'PAID', 'counter_confirm'],
            'Cannot prepare pending_payment' => ['PENDING_PAYMENT', 'PENDING', 'kds_take'],
        ];
    }
}
```

#### C8.4.2 Test: Side effects atomiques par chemin

```php
// tests/Feature/Payment/PaymentSideEffectsAtomicityTest.php

class PaymentSideEffectsAtomicityTest extends TestCase
{
    /** @test */
    public function kiosk_card_success_creates_fiscal_and_notifies_kds_atomically()
    {
        Event::fake([OrderStatusChanged::class]);

        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['branch_id' => $branch->id, 'price' => 1250]);
        StockLevel::factory()->create([
            'branch_id' => $branch->id,
            'item_id'   => $item->id,
            'quantity'   => 100,
        ]);

        // Créer commande kiosk (t0)
        $order = app(FrontendOrderService::class)->create([
            'branch_id'      => $branch->id,
            'source'         => 'kiosk',
            'payment_method' => PosPaymentMethod::CARD,
            'items'          => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $this->assertEquals(OrderStatus::CREATED, $order->status);
        $this->assertEquals(PaymentStatus::PENDING, $order->payment_status);
        $this->assertNull($order->fiscal_sequence_number);

        // Stock décrémenté à la création
        $this->assertEquals(98, StockLevel::where('item_id', $item->id)->value('quantity'));

        // Initier TPE (t1)
        app(PaymentService::class)->initiate($order);
        $order->refresh();
        $this->assertEquals(PaymentStatus::PROCESSING, $order->payment_status);
        $this->assertNull($order->fiscal_sequence_number); // toujours null

        // TPE success (t2)
        app(PaymentService::class)->confirmTpe($order, ['transaction_id' => 'TPE-123']);
        $order->refresh();

        // Vérifications atomiques
        $this->assertEquals(OrderStatus::CONFIRMED, $order->status);
        $this->assertEquals(PaymentStatus::PAID, $order->payment_status);
        $this->assertNotNull($order->fiscal_sequence_number);
        $this->assertEquals(1, $order->fiscal_sequence_number); // premier de la branche

        // Fiscal receipt créé
        $this->assertDatabaseHas('fiscal_receipts', [
            'order_id'               => $order->id,
            'branch_id'              => $branch->id,
            'fiscal_sequence_number' => 1,
            'type'                   => 'SALE',
            'amount_ttc'             => 2500, // 2 × 1250
        ]);

        // Outbox entry pour KDS
        $this->assertDatabaseHas('outbox', [
            'aggregate_id' => $order->id,
            'event_type'   => 'OrderStatusChanged',
            'branch_id'    => $branch->id,
        ]);

        // Stock inchangé (déjà décrémenté à t0)
        $this->assertEquals(98, StockLevel::where('item_id', $item->id)->value('quantity'));

        Event::assertDispatched(OrderStatusChanged::class);
    }

    /** @test */
    public function kiosk_card_failure_releases_stock_no_fiscal()
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create(['branch_id' => $branch->id, 'price' => 1250]);
        StockLevel::factory()->create([
            'branch_id' => $branch->id,
            'item_id'   => $item->id,
            'quantity'   => 100,
        ]);

        $order = app(FrontendOrderService::class)->create([
            'branch_id'      => $branch->id,
            'source'         => 'kiosk',
            'payment_method' => PosPaymentMethod::CARD,
            'items'          => [['item_id' => $item->id, 'quantity' => 2]],
        ]);

        $this->assertEquals(98, StockLevel::where('item_id', $item->id)->value('quantity'));

        app(PaymentService::class)->initiate($order);
        app(PaymentService::class)->failTpe($order, ['reason' => 'declined']);

        $order->refresh();
        $this->assertEquals(OrderStatus::CANCELLED, $order->status);
        $this->assertEquals


---

# Tour 7 — SUITE ULTRA

<!-- usage tour 7: input≈5538 output≈7744 stop=end_turn cumul≈71872 -->

# FOODKING C7 — DELIVERY / GÉO / FRAIS DE LIVRAISON (EXHAUSTIF)

**Date**: 2026-04-28
**Contexte**: Développement complet du chantier C7 identifié `PARTIAL (à confirmer)` dans l'ULTRA_PLAN. La livraison implique Google Maps, le calcul de frais, la validation backend SSOT, et la cohérence entre trois surfaces (web frontend, kiosk, POS). Aucun fichier lu directement.

---

## C7.0 Périmètre et enjeux

La livraison dans FoodKing est un flux critique car il combine:
- Un service externe non contrôlé (Google Maps Geocoding / Distance Matrix)
- Un calcul financier (frais de livraison) soumis à l'invariant Backend Pricing SSOT
- Trois surfaces qui doivent se comporter de manière identique face aux mêmes données
- Des cas limites géographiques (adresse introuvable, hors zone, limite de zone, DOM-TOM, etc.)
- Un impact fiscal (les frais de livraison sont soumis à TVA et apparaissent sur le reçu fiscal)

Un seul bug dans cette chaîne = client facturé 0€ de livraison sur 15km, ou commande bloquée parce que le geocode échoue silencieusement, ou frais différents entre le web et le POS pour la même adresse.

---

## C7.1 Architecture du flux livraison

### C7.1.1 Diagramme de séquence — Commande livraison web frontend

```
Client (browser)                    Laravel Backend                   Google Maps API
      │                                   │                                │
      │  1. Saisit adresse livraison      │                                │
      │──────────────────────────────────▶│                                │
      │                                   │  2. Geocode address            │
      │                                   │───────────────────────────────▶│
      │                                   │                                │
      │                                   │  3. {lat, lng, formatted}      │
      │                                   │◀───────────────────────────────│
      │                                   │                                │
      │                                   │  4. Distance Matrix            │
      │                                   │     (branch → client)          │
      │                                   │───────────────────────────────▶│
      │                                   │                                │
      │                                   │  5. {distance_km, duration_min}│
      │                                   │◀───────────────────────────────│
      │                                   │                                │
      │                                   │  6. Calcul fee selon barème    │
      │                                   │     branch                     │
      │                                   │                                │
      │  7. {fee, distance, duration,     │                                │
      │      zone, deliverable}           │                                │
      │◀──────────────────────────────────│                                │
      │                                   │                                │
      │  8. Affiche fee + confirme        │                                │
      │──────────────────────────────────▶│                                │
      │                                   │                                │
      │                                   │  9. REVALIDE fee côté backend  │
      │                                   │     (recalcul, pas confiance   │
      │                                   │      au fee envoyé par client) │
      │                                   │                                │
      │  10. Order created avec fee       │                                │
      │      validé backend               │                                │
      │◀──────────────────────────────────│                                │
```

### C7.1.2 Diagramme — Variante POS (caissier saisit adresse pour le client)

```
Caissier (POS)                      Laravel Backend                   Google Maps API
      │                                   │                                │
      │  1. Saisit adresse client         │                                │
      │     dans champ livraison POS      │                                │
      │──────────────────────────────────▶│                                │
      │                                   │  2-6. Même flow geocode +      │
      │                                   │       distance + calcul fee    │
      │                                   │───────────────────────────────▶│
      │                                   │◀───────────────────────────────│
      │  7. Fee affiché sur POS           │                                │
      │◀──────────────────────────────────│                                │
      │                                   │                                │
      │  8. Caissier confirme commande    │                                │
      │──────────────────────────────────▶│                                │
      │                                   │  9. Revalidation backend       │
      │                                   │     (même calcul)              │
      │  10. Order created                │                                │
      │◀──────────────────────────────────│                                │
```

### C7.1.3 Diagramme — Variante Kiosk

Le kiosk ne devrait normalement **pas** proposer la livraison (le client est physiquement dans le restaurant). Cependant, certains modèles business permettent de commander en livraison depuis une borne (ex: borne dans un hall d'entreprise). La décision doit être explicite.

| Question à valider | Impact |
|---|---|
| Le kiosk propose-t-il la livraison? | Si oui: même flow que web. Si non: le mode livraison doit être **impossible** à sélectionner depuis le kiosk. |
| Si non, est-ce que le code l'interdit explicitement? | Risque: un client navigue vers une URL de checkout livraison depuis le kiosk. |

---

## C7.2 Composants impliqués

| Composant | Rôle | Fichier attendu | Criticité |
|---|---|---|---|
| **DeliveryFeeService** | Calcul backend du fee selon distance et barème branch | `app/Services/Delivery/DeliveryFeeService.php` | SSOT — toute la logique de pricing est ici |
| **deliveryCharge.js** | Calcul frontend indicatif (preview) | `resources/js/helpers/deliveryCharge.js` | Affichage seul — ne doit JAMAIS être la source de vérité |
| **OrderRequest** | Validation de la commande côté backend | `app/Http/Requests/OrderRequest.php` | Gate — rejette si fee incohérent |
| **PosOrderRequest** | Validation commande POS | `app/Http/Requests/PosOrderRequest.php` | Gate POS |
| **CheckoutComponent** | UI checkout web frontend | `resources/js/components/frontend/checkout/CheckoutComponent.vue` | UX client |
| **PosComponent** | UI POS livraison | `resources/js/components/admin/pos/PosComponent.vue` | UX caissier |
| **Google Maps client** | Appels Geocoding + Distance Matrix | Service ou helper PHP | Dépendance externe |
| **Branch delivery config** | Barème par branche (zones, prix, rayon max) | Table `branch_delivery_settings` ou config branch | Configuration |

---

## C7.3 Barème de livraison — Modèle de données

### C7.3.1 Structure attendue

```
branch_delivery_settings
├── id
├── branch_id                (FK → branches, UNIQUE)
├── is_delivery_enabled      (boolean)
├── max_delivery_radius_km   (decimal, ex: 15.0)
├── free_delivery_threshold  (int centimes, ex: 3000 = 30.00€ — livraison gratuite au-dessus)
├── base_fee                 (int centimes, ex: 299 = 2.99€)
├── per_km_fee               (int centimes, ex: 50 = 0.50€/km)
├── min_fee                  (int centimes, ex: 299)
├── max_fee                  (int centimes, ex: 999 = 9.99€)
├── delivery_tax_rate        (int centièmes de %, ex: 2000 = 20.00% TVA)
├── estimated_time_base_min  (int, ex: 20)
├── estimated_time_per_km_min(int, ex: 3)
├── zones                    (JSON nullable — zones tarifaires avancées)
└── updated_at
```

### C7.3.2 Zones tarifaires (optionnel, avancé)

```json
{
  "zones": [
    {"name": "Zone 1", "max_km": 3.0, "fee": 299},
    {"name": "Zone 2", "max_km": 7.0, "fee": 499},
    {"name": "Zone 3", "max_km": 15.0, "fee": 799}
  ]
}
```

Si `zones` est non-null, le calcul par zone remplace le calcul `base_fee + per_km_fee × distance`. Les deux modes sont mutuellement exclusifs.

### C7.3.3 Algorithme de calcul — DeliveryFeeService

```php
// Pseudo-code DeliveryFeeService::calculate()

public function calculate(Branch $branch, float $distanceKm, int $orderTotalCents): DeliveryFeeResult
{
    $settings = $branch->deliverySettings;

    if (!$settings || !$settings->is_delivery_enabled) {
        throw new DeliveryNotAvailableException($branch->id);
    }

    if ($distanceKm > $settings->max_delivery_radius_km) {
        throw new DeliveryOutOfRangeException(
            $branch->id,
            $distanceKm,
            $settings->max_delivery_radius_km
        );
    }

    // Livraison gratuite au-dessus du seuil
    if ($settings->free_delivery_threshold > 0
        && $orderTotalCents >= $settings->free_delivery_threshold) {
        return new DeliveryFeeResult(
            fee: 0,
            tax: 0,
            distance_km: $distanceKm,
            zone: 'free',
            reason: 'order_above_threshold',
        );
    }

    // Calcul par zones si configuré
    if ($settings->zones) {
        $fee = $this->calculateByZone($settings->zones, $distanceKm);
    } else {
        // Calcul linéaire
        $fee = $settings->base_fee + (int) round($settings->per_km_fee * $distanceKm);
    }

    // Clamp entre min et max
    $fee = max($settings->min_fee, min($settings->max_fee, $fee));

    // TVA sur frais de livraison
    $tax = (int) round($fee * $settings->delivery_tax_rate / 10000);

    return new DeliveryFeeResult(
        fee: $fee,
        tax: $tax,
        distance_km: $distanceKm,
        zone: $this->resolveZoneName($settings->zones, $distanceKm),
        reason: 'calculated',
    );
}

private function calculateByZone(array $zones, float $distanceKm): int
{
    // Zones triées par max_km croissant
    $sorted = collect($zones)->sortBy('max_km');
    foreach ($sorted as $zone) {
        if ($distanceKm <= $zone['max_km']) {
            return $zone['fee'];
        }
    }
    // Ne devrait pas arriver si max_delivery_radius_km est cohérent avec les zones
    throw new DeliveryZoneConfigurationException();
}
```

---

## C7.4 Invariant SSOT — Le backend recalcule TOUJOURS

### C7.4.1 Le problème

Le frontend (`deliveryCharge.js`, `CheckoutComponent.vue`) calcule un fee indicatif pour l'affichage. Le client voit "Livraison: 4,99€" et confirme. Le payload envoyé au backend contient potentiellement ce fee. Si le backend fait confiance au fee envoyé par le client, un attaquant peut forger `delivery_fee: 0`.

### C7.4.2 Contrat backend obligatoire

```php
// Dans OrderRequest.php ou dans le service de création de commande

// Le frontend PEUT envoyer un delivery_fee (pour vérification de cohérence)
// Le backend DOIT recalculer et utiliser SA valeur

public function validated(): array
{
    $data = parent::validated();

    if ($data['order_type'] === 'delivery') {
        // Recalcul backend
        $branch = Branch::findOrFail($data['branch_id']);
        $geocode = app(GeocodingService::class)->geocode($data['delivery_address']);
        $distance = app(DistanceService::class)->calculate(
            $branch->latitude, $branch->longitude,
            $geocode->lat, $geocode->lng
        );

        $feeResult = app(DeliveryFeeService::class)->calculate(
            $branch,
            $distance->distance_km,
            $this->calculateOrderTotal($data['items'])
        );

        // Écraser le fee frontend par le fee backend
        $data['delivery_fee'] = $feeResult->fee;
        $data['delivery_tax'] = $feeResult->tax;
        $data['delivery_distance_km'] = $distance->distance_km;

        // Optionnel: vérifier la cohérence avec le fee frontend
        if (isset($data['frontend_delivery_fee'])
            && abs($data['frontend_delivery_fee'] - $feeResult->fee) > 100) {
            // Divergence > 1€ → log warning (prix a changé entre preview et submit)
            Log::warning('Delivery fee divergence', [
                'frontend' => $data['frontend_delivery_fee'],
                'backend'  => $feeResult->fee,
                'order'    => $data,
            ]);
        }
    }

    return $data;
}
```

### C7.4.3 Vérifications SSOT par surface

| Surface | Qui calcule le fee affiché? | Qui calcule le fee final? | Risque si incohérent |
|---|---|---|---|
| **Web frontend** | `deliveryCharge.js` (preview) via appel API ou calcul local | `DeliveryFeeService.php` (backend, à la soumission) | Client voit 2.99€, facturé 4.99€ → plainte |
| **POS** | Backend via API (caissier saisit adresse, backend répond avec fee) | `DeliveryFeeService.php` (backend) | Moindre risque car le caissier voit le fee backend |
| **Kiosk** | Si livraison activée: même que web frontend | `DeliveryFeeService.php` (backend) | Même risque que web |

**Question critique à valider**: Est-ce que `deliveryCharge.js` appelle une API backend pour obtenir le fee, ou est-ce qu'il calcule localement avec un barème embarqué? Si calcul local, le barème peut être désynchronisé du backend.

| Scénario | `deliveryCharge.js` appelle API | `deliveryCharge.js` calcule localement |
|---|---|---|
| Barème modifié par admin | Fee preview correct immédiatement | Fee preview incorrect jusqu'au prochain build/refresh |
| Attaquant modifie le JS | Pas d'impact (backend recalcule) | Pas d'impact (backend recalcule) |
| Google Maps down | API retourne erreur → frontend affiche erreur | Frontend calcule un fee sans distance réelle → incohérent |

---

## C7.5 Google Maps — Gestion des erreurs et cas limites

### C7.5.1 Modes de défaillance Google Maps

| Défaillance | Fréquence | Impact | Comportement attendu |
|---|---|---|---|
| **API key invalide/expirée** | Rare | Total | 500 → retry 1x → 422 "Service de livraison temporairement indisponible" |
| **Quota dépassé** | Possible en rush | Total | 429 → retry avec backoff → 422 si persistant |
| **Adresse introuvable** | Fréquent | Unitaire | `ZERO_RESULTS` → 422 "Adresse non reconnue, veuillez préciser" |
| **Adresse ambiguë** | Fréquent | Unitaire | Plusieurs résultats → prendre le premier? Demander au client de préciser? |
| **Timeout réseau** | Occasionnel | Unitaire | Timeout 5s → retry 1x → 422 |
| **Résultat dans un autre pays** | Rare | Unitaire | Geocode retourne un point hors France → vérifier country code |
| **Distance Matrix indisponible** | Rare | Total | Fallback sur distance euclidienne × facteur 1.3? Ou 422? |
| **Distance Matrix retourne 0** | Très rare | Unitaire | Même adresse que le restaurant → livraison gratuite ou refus? |
| **Coordonnées branch manquantes** | Bug config | Total pour la branch | 422 "Livraison non configurée pour ce restaurant" |

### C7.5.2 Stratégie de fallback

```
Google Maps Geocoding
        │
        ├── Success → continuer avec lat/lng
        │
        ├── ZERO_RESULTS → 422 immédiat, message clair
        │
        ├── OVER_QUERY_LIMIT → retry 1x après 1s
        │       ├── Success → continuer
        │       └── Fail → 422 "Service temporairement indisponible"
        │
        ├── REQUEST_DENIED → log critical, 500
        │
        ├── Timeout → retry 1x après 2s
        │       ├── Success → continuer
        │       └── Fail → 422 "Service temporairement indisponible"
        │
        └── Unknown error → log error, 422

Google Maps Distance Matrix
        │
        ├── Success → continuer avec distance_km
        │
        ├── Fail (any) → fallback euclidien
        │       │
        │       ├── distance_euclidean = haversine(branch, client)
        │       ├── distance_estimated = distance_euclidean × 1.35
        │       ├── Log warning "Distance Matrix fallback"
        │       └── Continuer avec distance_estimated
        │
        └── Si fallback aussi impossible → 422
```

### C7.5.3 Validation du résultat geocode

```php
// GeocodingService::geocode() — validations post-appel

public function geocode(string $address): GeocodeResult
{
    $response = $this->googleMapsClient->geocode($address);

    if ($response->status === 'ZERO_RESULTS') {
        throw new AddressNotFoundException($address);
    }

    if ($response->status !== 'OK') {
        throw new GeocodingFailedException($address, $response->status);
    }

    $result = $response->results[0];

    // Validation pays
    $country = collect($result->address_components)
        ->firstWhere('types', fn ($t) => in_array('country', $t));

    if (!$country || !in_array($country->short_name, ['FR', 'GP', 'MQ', 'GF', 'RE', 'YT'])) {
        throw new AddressOutOfCountryException(
            $address,
            $country?->short_name ?? 'unknown'
        );
    }

    // Validation précision
    if ($result->geometry->location_type === 'APPROXIMATE') {
        // Adresse trop vague (ex: juste un code postal)
        throw new AddressTooVagueException($address, $result->formatted_address);
    }

    return new GeocodeResult(
        lat: $result->geometry->location->lat,
        lng: $result->geometry->location->lng,
        formatted_address: $result->formatted_address,
        place_id: $result->place_id,
        precision: $result->geometry->location_type,
    );
}
```

---

## C7.6 Cas limites exhaustifs

### C7.6.1 Cas limites géographiques

| # | Cas | Comportement attendu | Test |
|---|---|---|---|
| GEO-1 | Adresse exactement à `max_delivery_radius_km` | Livrable (≤, pas <) | Oui |
| GEO-2 | Adresse à `max_delivery_radius_km + 0.01` | Non livrable, 422 | Oui |
| GEO-3 | Adresse à 0 km (même bâtiment) | Livrable, fee = min_fee ou 0 | Oui |
| GEO-4 | Adresse dans un DOM-TOM (Guadeloupe, Réunion) | Dépend de la config branch. Si branch en métropole → hors zone. Si branch en DOM-TOM → livrable. | Oui |
| GEO-5 | Adresse en Belgique/Suisse (frontalier) | Rejet par validation pays sauf config explicite | Oui |
| GEO-6 | Adresse avec caractères spéciaux (accents, ñ, ß) | Geocode fonctionne (Google gère l'UTF-8) | Oui |
| GEO-7 | Adresse vide ou whitespace | Rejet validation frontend + backend 422 | Oui |
| GEO-8 | Adresse = "test" ou "asdf" | `ZERO_RESULTS` → 422 | Oui |
| GEO-9 | Coordonnées branch non configurées (lat=0, lng=0) | Détection et rejet: "Livraison non configurée" | Oui |
| GEO-10 | Deux branches de la même enseigne, client entre les deux | Chaque branch calcule indépendamment. Le client commande sur une branch spécifique. | Implicite |
| GEO-11 | Adresse sur une île accessible uniquement par ferry | Distance Matrix peut retourner un temps très long. Fee peut dépasser max_fee. | Edge case |
| GEO-12 | Adresse avec complément (étage, bâtiment, digicode) | Le complément ne doit pas perturber le geocode. Stocker séparément. | Oui |

### C7.6.2 Cas limites financiers

| # | Cas | Comportement attendu | Test |
|---|---|---|---|
| FIN-1 | Commande à 29.99€, seuil gratuit à 30.00€ | Fee facturé (pas gratuit, 1 centime en dessous) | Oui — boundary |
| FIN-2 | Commande à 30.00€, seuil gratuit à 30.00€ | Fee = 0 (≥ seuil) | Oui — boundary |
| FIN-3 | Commande à 30.01€, seuil gratuit à 30.00€ | Fee = 0 | Oui |
| FIN-4 | `free_delivery_threshold = 0` (pas de seuil gratuit) | Fee toujours calculé | Oui |
| FIN-5 | `free_delivery_threshold = null` | Même que 0: pas de seuil | Oui |
| FIN-6 | Fee calculé < min_fee | Fee = min_fee | Oui |
| FIN-7 | Fee calculé > max_fee | Fee = max_fee | Oui |
| FIN-8 | `per_km_fee = 0` (fee fixe) | Fee = base_fee (clampé) | Oui |
| FIN-9 | `base_fee = 0, per_km_fee = 0` | Fee = 0 (livraison gratuite toujours) | Oui |
| FIN-10 | Distance = 0.1 km, per_km_fee = 50 → fee = base + 5 centimes | Arrondi correct (pas de demi-centime) | Oui |
| FIN-11 | Coupon de réduction appliqué → total passe sous le seuil gratuit | Fee recalculé APRÈS coupon? Ou AVANT? | **Décision business requise** |
| FIN-12 | TVA sur frais de livraison | Fee TTC = fee HT + TVA. Le reçu fiscal doit séparer la TVA livraison de la TVA produits. | Oui — fiscal |
| FIN-13 | Remboursement commande livraison | Les frais de livraison sont-ils remboursés? | **Décision business requise** |

### C7.6.3 Cas limites temporels et de concurrence

| # | Cas | Comportement attendu | Test |
|---|---|---|---|
| TEMP-1 | Client preview fee → admin modifie barème → client soumet | Backend recalcule avec nouveau barème. Client peut voir un fee différent. | Oui |
| TEMP-2 | Client preview fee → Google Maps change la distance (travaux, route fermée) | Très improbable entre preview et submit (secondes). Backend recalcule quand même. | Non |
| TEMP-3 | Deux commandes livraison simultanées, même adresse | Deux appels Google Maps indépendants. Pas de cache partagé (sauf si implémenté). | Implicite |
| TEMP-4 | Google Maps down pendant 30 min en rush | Toutes les commandes livraison échouent. Les commandes sur place/à emporter fonctionnent. | Oui — isolation |
| TEMP-5 | Admin désactive la livraison pendant qu'un client est au checkout | Submit → 422 "Livraison désactivée". Client doit choisir un autre mode. | Oui |

---

## C7.7 Cohérence multi-canal — Matrice de vérification

### C7.7.1 Tableau de cohérence

| Aspect | Web Frontend | POS | Kiosk | Cohérent? |
|---|---|---|---|---|
| **Qui saisit l'adresse** | Client | Caissier (pour le client) | Client (si livraison activée) | ⚠ UX différente, même backend |
| **Qui appelle le geocode** | Frontend → API backend | POS → API backend | Kiosk → API backend | ✅ si tous passent par le même endpoint |
| **Qui affiche le fee preview** | `deliveryCharge.js` ou API | API response | API response | ⚠ si `deliveryCharge.js` calcule localement |
| **Qui valide le fee final** | `OrderRequest.php` | `PosOrderRequest.php` | `OrderRequest.php` (ou `FrontendOrderRequest`) | ⚠ deux Request classes → deux logiques possibles |
| **Qui calcule le fee final** | `DeliveryFeeService.php` | `DeliveryFeeService.php` | `DeliveryFeeService.php` | ✅ si les deux Request classes appellent le même service |
| **Endpoint de preview** | `POST /api/frontend/delivery/estimate` ? | `POST /api/admin/delivery/estimate` ? | Même que web? | ⚠ à vérifier — deux endpoints = deux logiques possibles |
| **Endpoint de soumission** | `POST /api/frontend/orders` | `POST /api/admin/pos/orders` | `POST /api/frontend/orders` (kiosk = frontend) | ⚠ deux endpoints |
| **Barème utilisé** | `branch_delivery_settings` de la branch | Idem | Idem | ✅ si même table |
| **Google Maps API key** | Même clé serveur | Idem | Idem | ✅ |
| **Gestion erreur geocode** | 422 + message client | 422 + message caissier | 422 + message kiosk | ⚠ messages potentiellement différents |
| **Livraison désactivée** | Mode livraison masqué | Mode livraison masqué dans POS | Mode livraison masqué | ⚠ si un seul oublie de masquer |

### C7.7.2 Risques de divergence identifiés

| # | Risque | Gravité | Vérification |
|---|---|---|---|
| DIV-1 | `OrderRequest.php` et `PosOrderRequest.php` ont des règles de validation différentes pour `delivery_address` | HAUTE | Diff les deux fichiers, comparer les rules delivery |
| DIV-2 | `deliveryCharge.js` embarque un barème hardcodé qui diverge du backend | HAUTE | Lire le fichier, chercher des constantes de prix |
| DIV-3 | L'endpoint preview web et l'endpoint preview POS utilisent des services différents | MOYENNE | Tracer les deux routes dans `api.php` |
| DIV-4 | Le kiosk utilise l'endpoint frontend mais avec un flow différent (pas de saisie adresse complète) | MOYENNE | Lire `KioskAppComponent.vue`, chercher le flow livraison |
| DIV-5 | Le POS permet de forcer un fee manuel (override caissier) sans validation | HAUTE | Lire `PosOrderRequest.php`, chercher `delivery_fee` dans les rules |
| DIV-6 | Le web frontend envoie `delivery_fee` dans le payload et le backend l'utilise tel quel | CRITIQUE | Lire `OrderRequest.php`, vérifier que le fee est recalculé |

---

## C7.8 Tests d'intégration suggérés

### C7.8.1 Suite PHP Feature — DeliveryFeeService

```php
// tests/Feature/Delivery/DeliveryFeeServiceTest.php

class DeliveryFeeServiceTest extends TestCase
{
    private Branch $branch;
    private DeliveryFeeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create([
            'latitude'  => 48.8566,  // Paris
            'longitude' => 2.3522,
        ]);

        BranchDeliverySetting::factory()->create([
            'branch_id'               => $this->branch->id,
            'is_delivery_enabled'     => true,
            'max_delivery_radius_km'  => 15.0,
            'free_delivery_threshold' => 3000, // 30€
            'base_fee'                => 299,  // 2.99€
            'per_km_fee'              => 50,   // 0.50€/km
            'min_fee'                 => 299,
            'max_fee'                 => 999,
            'delivery_tax_rate'       => 2000, // 20%
        ]);

        $this->service = app(DeliveryFeeService::class);
    }

    /** @test */
    public function calculates_linear_fee_correctly()
    {
        $result = $this->service->calculate($this


---

# Tour 8 — SUITE ULTRA

<!-- usage tour 8: input≈7274 output≈7641 stop=end_turn cumul≈86787 -->

# FOODKING D3–D13 — CAMPAGNE DESIGN PROD-LIVE & DETTE UX (EXHAUSTIF)

**Date**: 2026-04-28
**Contexte**: D1/D2/D3 sont rapportés `seriousTotal=0` via axe-core. Mais axe-core ne couvre qu'une fraction des exigences design/UX production. Les campagnes D4–D13 doivent combler le gap entre "pas de violation axe serious" et "prêt pour un restaurant réel avec des clients réels sur du hardware réel".

---

## D.0 Taxonomie des risques design en restauration

Avant de détailler chaque campagne, il faut comprendre pourquoi le design d'un POS/Kiosk/KDS est fondamentalement différent d'une webapp classique.

| Contrainte | Webapp classique | FoodKing POS/Kiosk/KDS | Impact si ignoré |
|---|---|---|---|
| **Environnement lumineux** | Bureau, éclairage contrôlé | Cuisine (néons, vapeur), salle (lumière variable), vitrine (soleil direct) | Écran illisible en cuisine ou en plein soleil |
| **Propreté des mains** | Clavier/souris, mains propres | Mains grasses, gantées, mouillées (cuisine). Doigts de client (kiosk). | Touch targets trop petits → erreurs de saisie |
| **Bruit ambiant** | Silencieux | Cuisine bruyante, salle animée | Feedback sonore inaudible, feedback visuel critique |
| **Vitesse d'interaction** | Secondes acceptables | Rush service: chaque seconde compte. File d'attente au kiosk. | UI lente → file d'attente → perte de CA |
| **Compétence utilisateur** | Développeur/power user | Caissier (formation minimale), client (zéro formation), cuisinier (pas le temps) | UI complexe → erreurs, abandon, formation coûteuse |
| **Taille d'écran** | Desktop/laptop | Kiosk 22-32", POS 10-15" tactile, KDS 22-55" mural, OSS 40-65" TV | Responsive ne suffit pas: il faut des layouts dédiés |
| **Distance de lecture** | 50cm | KDS: 1-3m. OSS: 3-10m. Kiosk: 50cm. POS: 40cm. | Taille de police critique par surface |
| **Durée de session** | Minutes | POS: 8h continues. KDS: 8h continues. Kiosk: 2-5 min. | Fatigue visuelle POS/KDS, impatience kiosk |
| **Accessibilité légale** | Recommandée | Kiosk ERP: obligatoire (établissement recevant du public) | Amende, plainte, exclusion de clients |

---

## D.1–D.3 Rappel état actuel et limites

### D1 Kiosk — 90 audits axe, seriousTotal=0

| Ce que D1 prouve | Ce que D1 ne prouve PAS |
|---|---|
| Pas de violation axe serious sur les pages auditées | Rendu sur écran tactile 22" réel |
| Structure HTML sémantique minimale | Touch targets ≥ 48×48px (WCAG 2.5.5 AAA, recommandé 44×44 AA) |
| Attributs ARIA de base présents | Contraste sur fond photographique (images produit) |
| Labels de formulaire associés | Navigation clavier/lecteur d'écran complète |
| | Temps de chargement perçu (skeleton, spinner) |
| | Animation de transition entre étapes |
| | Comportement scroll sur liste longue de produits |
| | Zoom/pinch désactivé (kiosk locked) |
| | Orientation verrouillée (portrait ou paysage selon borne) |
| | Gestion du timeout inactivité (retour home) |
| | Lisibilité en plein soleil (contraste élevé) |

### D2 POS — 30 audits axe, seriousTotal=0

| Ce que D2 prouve | Ce que D2 ne prouve PAS |
|---|---|
| Même couverture axe que D1 | Layout optimisé pour écran 10-15" tactile |
| | Densité d'information adaptée (beaucoup d'items visibles sans scroll) |
| | Raccourcis clavier pour caissier expert |
| | Feedback visuel immédiat sur ajout au panier |
| | Gestion multi-commandes simultanées (split screen?) |
| | Impression ticket (preview, format) |
| | Mode nuit/jour pour longues sessions |

### D3 KDS/OSS — 20 audits axe, seriousTotal=0

| Ce que D3 prouve | Ce que D3 ne prouve PAS |
|---|---|
| Même couverture axe | Lisibilité à 2-3m (KDS) et 5-10m (OSS) |
| | Taille de police suffisante pour distance |
| | Contraste en environnement cuisine (vapeur, néons) |
| | Animation de bump (feedback visuel fort) |
| | Gestion de l'overflow (50+ commandes simultanées) |
| | Auto-scroll ou pagination |
| | Indicateur de connexion WebSocket visible |
| | Alerte visuelle sur commande ancienne (> 15 min) |
| | Mode daltonien (pas de rouge/vert seul pour statut) |

---

## D4 — KIOSK TOUCH & INTERACTION TACTILE

### D4.0 Objectif

Valider que le kiosk est utilisable par un client debout, pressé, avec des doigts de taille variable, sur un écran tactile 22-32" en orientation portrait ou paysage.

### D4.1 Critères mesurables

| # | Critère | Méthode de mesure | Seuil PASS | Seuil FAIL | Outil |
|---|---|---|---|---|---|
| D4-C1 | Touch targets ≥ 44×44px CSS | Script Playwright: mesurer `getBoundingClientRect()` de tous les éléments cliquables | 100% ≥ 44×44 | Un seul < 44×44 | Playwright + script custom |
| D4-C2 | Espacement entre targets ≥ 8px | Mesurer gap entre bounding boxes adjacentes | 100% ≥ 8px | Un seul < 8px | Playwright + script custom |
| D4-C3 | Aucun double-tap requis | Parcours complet sans double-tap | 0 double-tap nécessaire | 1 double-tap | Manuel sur device |
| D4-C4 | Aucun hover-only interaction | Audit CSS `:hover` sans équivalent `:active`/`:focus` | 0 hover-only | 1 hover-only | `grep -r ':hover' resources/css/ resources/js/` |
| D4-C5 | Scroll fluide sur liste produits (60fps) | Chrome DevTools Performance sur device | ≥ 55fps moyen | < 45fps | DevTools |
| D4-C6 | Temps entre tap et feedback visuel | Mesurer délai tap → changement visuel | ≤ 100ms | > 300ms | Manuel + slow-motion video |
| D4-C7 | Pinch-to-zoom désactivé | Tenter pinch sur device | Pas de zoom | Zoom possible | Manuel |
| D4-C8 | Orientation verrouillée | Tourner le device | Pas de rotation | Rotation | Manuel + meta viewport |
| D4-C9 | Pas de clavier système visible (sauf champs texte) | Parcours complet | Clavier uniquement sur input text | Clavier apparaît sur tap non-input | Manuel |
| D4-C10 | Retour home après inactivité | Attendre timeout (ex: 120s) | Retour home automatique | Reste sur écran | Playwright `waitForTimeout` |

### D4.2 Script d'audit touch targets

```javascript
// tests/e2e/design/kiosk/d4-kiosk-touch-audit.spec.js

import { test, expect } from '@playwright/test';

const MINIMUM_TARGET_SIZE = 44; // px CSS
const MINIMUM_GAP = 8; // px

test.describe('D4 — Kiosk Touch Target Audit', () => {

  const kioskPages = [
    { name: 'Home', url: '/kiosk?branch_id=1' },
    { name: 'Category', url: '/kiosk/category/1?branch_id=1' },
    { name: 'Cart', url: '/kiosk/cart?branch_id=1' },
    { name: 'Checkout', url: '/kiosk/checkout?branch_id=1' },
  ];

  for (const page of kioskPages) {
    test(`Touch targets on ${page.name}`, async ({ browser }) => {
      const context = await browser.newContext({
        viewport: { width: 1080, height: 1920 }, // Portrait kiosk
        hasTouch: true,
        isMobile: false,
      });
      const p = await context.newPage();
      await p.goto(page.url);
      await p.waitForLoadState('networkidle');

      const violations = await p.evaluate((minSize) => {
        const clickable = document.querySelectorAll(
          'a, button, [role="button"], [onclick], input, select, textarea, ' +
          '[tabindex]:not([tabindex="-1"]), [data-test*="add"], [data-test*="click"], ' +
          '.clickable, .btn, .card-clickable'
        );

        const results = [];
        for (const el of clickable) {
          if (el.offsetParent === null) continue; // hidden
          const rect = el.getBoundingClientRect();
          if (rect.width < minSize || rect.height < minSize) {
            results.push({
              tag: el.tagName,
              text: el.textContent?.trim().substring(0, 40),
              dataTest: el.getAttribute('data-test'),
              width: Math.round(rect.width),
              height: Math.round(rect.height),
              classes: el.className?.toString().substring(0, 60),
            });
          }
        }
        return results;
      }, MINIMUM_TARGET_SIZE);

      if (violations.length > 0) {
        console.table(violations);
      }
      expect(violations).toHaveLength(0);
    });

    test(`Touch target gaps on ${page.name}`, async ({ browser }) => {
      const context = await browser.newContext({
        viewport: { width: 1080, height: 1920 },
        hasTouch: true,
      });
      const p = await context.newPage();
      await p.goto(page.url);
      await p.waitForLoadState('networkidle');

      const gapViolations = await p.evaluate((minGap) => {
        const clickable = Array.from(document.querySelectorAll(
          'a, button, [role="button"], input, select'
        )).filter(el => el.offsetParent !== null);

        const rects = clickable.map(el => ({
          el: el.tagName + (el.getAttribute('data-test') || ''),
          rect: el.getBoundingClientRect(),
        }));

        const violations = [];
        for (let i = 0; i < rects.length; i++) {
          for (let j = i + 1; j < rects.length; j++) {
            const a = rects[i].rect;
            const b = rects[j].rect;
            // Vérifier overlap ou gap insuffisant
            const hGap = Math.max(0, Math.max(b.left - a.right, a.left - b.right));
            const vGap = Math.max(0, Math.max(b.top - a.bottom, a.top - b.bottom));
            const gap = Math.min(
              hGap > 0 ? hGap : Infinity,
              vGap > 0 ? vGap : Infinity
            );
            if (gap < minGap && gap < Infinity) {
              violations.push({
                elementA: rects[i].el,
                elementB: rects[j].el,
                gap: Math.round(gap),
              });
            }
          }
        }
        return violations.slice(0, 20); // limiter le bruit
      }, MINIMUM_GAP);

      expect(gapViolations).toHaveLength(0);
    });
  }

  test('No hover-only interactions', async ({ browser }) => {
    const context = await browser.newContext({
      viewport: { width: 1080, height: 1920 },
      hasTouch: true,
    });
    const p = await context.newPage();
    await p.goto('/kiosk?branch_id=1');

    const hoverOnlyElements = await p.evaluate(() => {
      const sheets = Array.from(document.styleSheets);
      const hoverRules = [];
      for (const sheet of sheets) {
        try {
          for (const rule of sheet.cssRules) {
            if (rule.selectorText?.includes(':hover')
                && !rule.selectorText?.includes(':active')
                && !rule.selectorText?.includes(':focus')) {
              // Vérifier si la règle change la visibilité ou le display
              if (rule.style.display || rule.style.visibility || rule.style.opacity) {
                hoverRules.push({
                  selector: rule.selectorText,
                  property: rule.style.display || rule.style.visibility || rule.style.opacity,
                });
              }
            }
          }
        } catch (e) { /* cross-origin stylesheet */ }
      }
      return hoverRules;
    });

    if (hoverOnlyElements.length > 0) {
      console.table(hoverOnlyElements);
    }
    expect(hoverOnlyElements).toHaveLength(0);
  });
});
```

### D4.3 Findings attendus et quick wins

| Finding probable | Gravité | Quick win? | Correction |
|---|---|---|---|
| Boutons "+" / "-" quantité < 44px | HAUTE | ✅ 5 min | `min-width: 44px; min-height: 44px;` |
| Liens texte dans footer < 44px hauteur | BASSE | ✅ 5 min | `padding: 12px 16px; display: inline-block;` |
| Cards produit cliquables mais gap < 8px | MOYENNE | ✅ 10 min | `gap: 12px` sur le grid |
| Dropdown/select natif trop petit | MOYENNE | ⚠ 30 min | Remplacer par custom select avec grands targets |
| Tooltip hover-only sur icône info | MOYENNE | ✅ 15 min | Ajouter `@click` toggle ou `@touchstart` |
| Pinch-to-zoom non désactivé | HAUTE | ✅ 2 min | `<meta name="viewport" content="..., user-scalable=no, maximum-scale=1">` |
| Orientation non verrouillée | HAUTE | ✅ 2 min | CSS `@media (orientation: landscape) { /* force layout */ }` + manifest |

---

## D5 — POS ERGONOMIE CAISSIER

### D5.0 Objectif

Valider que le POS est utilisable par un caissier pendant un service de 8h, sur un écran tactile 10-15", avec des interactions rapides et sans erreur.

### D5.1 Critères mesurables

| # | Critère | Seuil PASS | Seuil FAIL | Méthode |
|---|---|---|---|---|
| D5-C1 | Nombre de taps pour créer une commande simple (1 item, cash) | ≤ 5 taps | > 7 taps | Comptage manuel |
| D5-C2 | Nombre de taps pour créer une commande complexe (3 items, 1 modifier, card) | ≤ 10 taps | > 15 taps | Comptage manuel |
| D5-C3 | Temps pour créer commande simple | ≤ 15s | > 30s | Chronomètre |
| D5-C4 | Temps pour créer commande complexe | ≤ 45s | > 90s | Chronomètre |
| D5-C5 | Taille police prix/total | ≥ 24px | < 18px | Inspection |
| D5-C6 | Taille police nom produit dans grille | ≥ 16px | < 14px | Inspection |
| D5-C7 | Contraste mode jour (ratio WCAG) | ≥ 4.5:1 texte, ≥ 3:1 grands textes | < 3:1 | axe-core |
| D5-C8 | Feedback visuel ajout panier | Animation/highlight visible ≤ 100ms | Pas de feedback | Manuel |
| D5-C9 | Annulation dernier item (undo) | 1 tap | Impossible ou > 2 taps | Manuel |
| D5-C10 | Visibilité du total en permanence | Total visible sans scroll | Total masqué par scroll | Inspection layout |
| D5-C11 | Nombre d'items visibles sans scroll (grille produits) | ≥ 12 sur 10" | < 8 | Comptage |
| D5-C12 | Raccourci clavier pour paiement rapide | Existe (ex: F1=cash, F2=card) | Aucun raccourci | Inspection code |

### D5.2 Layout POS recommandé — Zones

```
┌─────────────────────────────────────────────────────────────┐
│ HEADER: Branch name │ Caissier: [nom] │ Heure │ [Déconnexion] │
├──────────────────────┬──────────────────────────────────────┤
│                      │                                      │
│   CATÉGORIES         │         GRILLE PRODUITS              │
│   (sidebar ou tabs)  │         (cards cliquables)           │
│                      │                                      │
│   [Entrées]          │   ┌──────┐ ┌──────┐ ┌──────┐       │
│   [Plats]            │   │Burger│ │Frites│ │Salade│       │
│   [Desserts]         │   │12.50€│ │ 4.50€│ │ 8.00€│       │
│   [Boissons]         │   └──────┘ └──────┘ └──────┘       │
│   [Menus]            │   ┌──────┐ ┌──────┐ ┌──────┐       │
│                      │   │Pizza │ │Pasta │ │Wrap  │       │
│                      │   │14.00€│ │11.00€│ │ 9.50€│       │
│                      │   └──────┘ └──────┘ └──────┘       │
│                      │                                      │
├──────────────────────┴──────────────────────────────────────┤
│ PANIER (toujours visible, bas de l'écran ou panneau droit)  │
│                                                             │
│  2× Burger Classic .............. 25.00€  [−] [+] [🗑]     │
│  1× Frites ...................... 4.50€   [−] [+] [🗑]     │
│                                                             │
│  Sous-total: 29.50€    TVA: 2.95€    TOTAL: 29.50€        │
│                                                             │
│  [💵 ESPÈCES (F1)]  [💳 CARTE (F2)]  [🔄 ANNULER]         │
└─────────────────────────────────────────────────────────────┘
```

### D5.3 Findings attendus et quick wins

| Finding probable | Gravité | Quick win? | Correction | Temps estimé |
|---|---|---|---|---|
| Total masqué quand panier scroll | HAUTE | ✅ | `position: sticky; bottom: 0;` sur le footer panier | 10 min |
| Pas de raccourcis clavier | MOYENNE | ⚠ | `@keydown` listener global pour F1/F2/Escape | 30 min |
| Grille produits trop espacée (< 8 items visibles sur 10") | HAUTE | ✅ | Réduire padding cards, passer en grille 4 colonnes | 20 min |
| Pas de feedback visuel ajout panier | HAUTE | ✅ | `transition: transform 0.1s` + scale bounce sur card | 15 min |
| Bouton annuler trop proche de confirmer | CRITIQUE | ✅ | Séparer visuellement (couleur + distance) | 10 min |
| Pas de confirmation avant paiement > 100€ | MOYENNE | ⚠ | Modal de confirmation pour montants élevés | 30 min |
| Mode nuit absent | BASSE | ❌ | Thème CSS complet | 2h+ |
| Floorplan non responsive sur petit écran | MOYENNE | ⚠ | Media queries pour `FloorplanComponent.vue` | 1h |

---

## D6 — KDS LISIBILITÉ CUISINE

### D6.0 Objectif

Valider que le KDS est lisible par un cuisinier à 1-3 mètres de distance, dans un environnement bruyant, lumineux, et humide.

### D6.1 Critères mesurables

| # | Critère | Seuil PASS | Seuil FAIL | Méthode |
|---|---|---|---|---|
| D6-C1 | Taille police numéro de commande | ≥ 48px (lisible à 2m) | < 36px | Inspection CSS |
| D6-C2 | Taille police nom item | ≥ 24px | < 18px | Inspection CSS |
| D6-C3 | Taille police quantité | ≥ 32px, bold | < 24px | Inspection CSS |
| D6-C4 | Contraste fond/texte | ≥ 7:1 (AAA) | < 4.5:1 | axe-core + mesure manuelle |
| D6-C5 | Couleur de statut non rouge/vert seul (daltonisme) | Icône + couleur + texte | Couleur seule | Inspection visuelle + filtre daltonien |
| D6-C6 | Timer visible par commande (temps depuis création) | Timer affiché, ≥ 24px | Pas de timer | Inspection |
| D6-C7 | Alerte visuelle commande > 15 min | Clignotement ou bordure rouge + icône | Rien | Inspection |
| D6-C8 | Nombre de commandes visibles sans scroll (écran 22") | ≥ 6 | < 4 | Comptage |
| D6-C9 | Nombre de commandes visibles sans scroll (écran 55") | ≥ 12 | < 8 | Comptage |
| D6-C10 | Bouton bump ≥ 64×64px | ≥ 64×64 | < 48×48 | Mesure |
| D6-C11 | Feedback bump (animation + son optionnel) | Animation visible ≥ 300ms | Pas de feedback | Manuel |
| D6-C12 | Indicateur connexion WebSocket | Visible en permanence (vert/rouge) | Absent | Inspection |
| D6-C13 | Alerte déconnexion WebSocket | Bannière rouge plein écran | Rien ou petit toast | Inspection |
| D6-C14 | Auto-scroll sur nouvelle commande | Scroll vers la nouvelle commande ou indicateur "N nouvelles" | Commande ajoutée hors écran sans indication | Inspection |

### D6.2 Palette daltonien-safe pour statuts

| Statut | Couleur | Icône | Texte | Accessible deutéranopie? |
|---|---|---|---|---|
| Nouvelle | Bleu `#2563EB` | 🔵 ou ⬤ | "NOUVEAU" | ✅ |
| En préparation | Orange `#D97706` | 🔶 ou ⏳ | "EN COURS" | ✅ |
| Prête | Vert `#059669` + pattern hachuré | ✅ ou ☑ | "PRÊT" | ✅ (icône + pattern) |
| En retard (> 15 min) | Rouge `#DC2626` + clignotement | ⚠ ou 🔴 | "RETARD" | ✅ (clignotement) |
| Annulée | Gris `#6B7280` + barré | ✖ | "ANNULÉ" | ✅ |

**Règle**: Jamais de distinction rouge/vert sans un second canal (icône, texte, pattern, animation).

### D6.3 Layout KDS recommandé

```
┌─────────────────────────────────────────────────────────────────────┐
│ KDS — Branch: [nom] │ 🟢 Connecté │ 14:32:07 │ [Filtre: Tous ▼]  │
├─────────────┬─────────────┬─────────────┬─────────────┬─────────────┤
│ #042        │ #043        │ #044        │ #045        │ #046        │
│ ⏱ 2:34     │ ⏱ 1:12     │ ⏱ 0:45     │ ⏱ 0:22     │ ⏱ 0:05     │
│ ─────────── │ ─────────── │ ─────────── │ ─────────── │ ─────────── │
│ 2× Burger   │ 1× Pizza    │ 3× Frites   │ 1× Menu     │ 2× Salade   │
│ 1× Frites   │ 2× Salade   │ 1× Burger   │    Midi     │ 1× Wrap     │
│ 1× Coca     │ 1× Eau      │             │ 1× Coca     │             │
│             │             │             │             │             │
│             │             │             │             │             │
│ [  BUMP ✓ ]│ [  BUMP ✓ ]│ [  BUMP ✓ ]│ [  BUMP ✓ ]│ [  BUMP ✓ ]│
│  (64×64px)  │  (64×64px)  │  (64×64px)  │  (64×64px)  │  (64×64px)  │
└─────────────┴─────────────┴─────────────┴─────────────┴─────────────┘
```

---

## D7 — OSS LISIBILITÉ CLIENT À DISTANCE

### D7.0 Objectif

L'OSS (Order Status Screen) est un écran TV 40-65" visible par les clients à 3-10 mètres. Il affiche les commandes en préparation et prêtes. Le client doit pouvoir identifier son numéro de commande en un coup d'œil.

### D7.1 Critères mesurables

| # | Critère | Seuil PASS | Seuil FAIL | Justification |
|---|---|---|---|---|
| D7-C1 | Taille police numéro de commande | ≥ 72px | < 48px | Lisible à 8m |
| D7-C2 | Taille police statut | ≥ 36px | < 24px | Lisible à 5m |
| D7-C3 | Contraste fond/texte | ≥ 7:1 | < 4.5:1 | Environnement lumineux variable |
| D7-C4 | Animation transition "en préparation" → "prêt" | Animation visible ≥ 500ms, mouvement clair | Pas d'animation (apparition instantanée) | Le client doit remarquer le changement |
| D7-C5 | Nombre de commandes visibles simultanément | ≥ 8 "en préparation" + 4 "prêt" | < 5 total | Rush service |
| D7-C6 | Pas de scroll nécessaire | Pagination automatique ou colonnes | Scroll manuel | Écran public, pas d'interaction |
| D7-C7 | Rotation automatique si > N commandes | Carousel toutes les 5-10s | Commandes hors écran invisibles | Client ne voit pas son numéro |
| D7-C8 | Section "Prêt" visuellement distincte | Fond différent + taille plus grande + animation pulse | Même style que "en préparation" | Client ne sait pas que c'est prêt |
| D7-C9 | Pas d'information sensible affichée | Numéro de commande seul, pas de nom/téléphone | Nom du client visible | RGPD |
| D7-C10 | Branding restaurant visible | Logo + nom en header | Écran générique | Identité restaurant |

### D7.2 Layout OSS recommandé

```
┌─────────────────────────────────────────────────────────────────────┐
│                    🍔 FOODKING — [Nom Restaurant]                   │
├────────────────────────────────┬────────────────────────────────────┤
│                                │                                    │
│     EN PRÉPARATION ⏳          │          PRÊT ✅                   │
│                                │                                    │
│     #042    #043    #044       │                                    │
│                                │       #039        #041             │
│     #045    #046    #047       │                                    │
│                                │       (animation pulse             │
│     #048    #049               │        sur les numéros)            │
│                                │                                    │
│                                │                                    │
├────────────────────────────────┴────────────────────────────────────┤
│  [Popular items carousel — optionnel, cf. PopularItemComponent]     │
└─────────────────────────────────────┘
```

---

## D8 — RESPONSIVE & MULTI-RÉSOLUTION

### D8.0


---

# Tour 9 — SUITE ULTRA

<!-- usage tour 9: input≈8828 output≈7721 stop=end_turn cumul≈103336 -->

# FOODKING — BACKLOG CONSOLIDÉ UNIQUE PRIORISÉ

**Date**: 2026-04-28
**Source**: Fusion tours 1–8 (ULTRA_PLAN, ULTRA_REVIEW, C3–C10, D3–D13, AuthZ, Delivery, Fiscal, Realtime)
**Convention**: Chaque ligne = 1 livrable atomique exécutable par Codex ou validable par humain.

---

## LÉGENDE

| Priorité | Signification | Conséquence si skip |
|---|---|---|
| **P0** | Bloquant release. Risque légal, financier, perte de données, ou système non fonctionnel. | Pas de go hardware UAT. |
| **P1** | Critique avant UAT. Bug probable en production, dégradation majeure d'expérience, ou faille sécurité. | UAT possible mais risque élevé de blocage pendant UAT. |
| **P2** | Important. Dette technique, UX dégradée, ou couverture insuffisante. | UAT possible, production risquée sans correction post-UAT. |
| **P3** | Nice to have. Amélioration, polish, ou couverture bonus. | Aucun impact immédiat. Planifiable post-release. |

---

## PHASE 0 — VALIDATION MACHINE (pré-requis à tout)

| ID | Priorité | Livrable | Description | Dépendance | Fichiers clés | Risque si skip |
|---|---|---|---|---|---|---|
| **MV-001** | P0 | Rapport relecture `process-audit.js` | Déterminer si le helper mock les API ou appelle le backend réel. Classifier C1/C2 comme "runtime" ou "navigation-only". | Aucune | `tests/e2e/helpers/process-audit.js` | Tous les verdicts C1/C2 sont potentiellement des fake PASS. Aucune confiance dans les tests process. |
| **MV-002** | P0 | Rapport relecture `clearTransientUi()` | Lister les sélecteurs DOM supprimés avant audit axe. Déterminer si des violations réelles sont masquées. | Aucune | `tests/e2e/design/_shared/design-audit-helpers.js` | D1/D2/D3 seriousTotal=0 peut être un artefact. Fausse confiance accessibilité. |
| **MV-003** | P0 | Rapport relecture `EventServiceProvider.php` | Vérifier que tous les listeners outbox sont enregistrés et que `$afterCommit = true` sur chacun. | Aucune | `app/Providers/EventServiceProvider.php`, tous `app/Listeners/Persist*ToOutbox.php`, `app/Listeners/DecrementStockOnOrderCreated.php` | Events dispatchés avant commit DB → état fantôme. Violation invariant fondamental. |
| **MV-004** | P0 | Rapport grep chaînes magiques | `grep -rn "->status = '" app/` et `grep -rn "->payment_status = '" app/` et `grep -rn "== 'pending'" app/`. Lister toutes les occurrences. | Aucune | Tout `app/` | Violation invariant enums. Comparaisons lâches → bugs silencieux. |
| **MV-005** | P0 | Rapport relecture `FiscalSealingService.php` | Vérifier ligne par ligne: monotonie séquence, HMAC chain, `previous_hash`, `FOR UPDATE`, absence d'UPDATE/DELETE. | Aucune | `app/Services/Fiscal/FiscalSealingService.php` | Non-conformité NF525 non détectée. |
| **MV-006** | P0 | Rapport relecture `PaymentService.php` | Tracer chaque chemin de paiement: quand `fiscal_sequence_number` est alloué, vérifier qu'il est NULL avant `PAID`. | Aucune | `app/Services/PaymentService.php`, `app/Domain/Order/PaymentStateMachine.php` | Séquence fiscale allouée avant encaissement réel. |
| **MV-007** | P0 | Rapport relecture `OrderRequest.php` + `PosOrderRequest.php` delivery fee | Vérifier que le backend recalcule le delivery fee et n'utilise pas la valeur envoyée par le client. | Aucune | `app/Http/Requests/OrderRequest.php`, `app/Http/Requests/PosOrderRequest.php` | Client forge `delivery_fee: 0` → livraison gratuite. Violation SSOT. |
| **MV-008** | P1 | Rapport relecture `kioskRoutes.js` | Vérifier qu'aucune route admin n'est accessible depuis le kiosk. | Aucune | `resources/js/router/modules/kioskRoutes.js` | Client accède au dashboard depuis la borne. |
| **MV-009** | P1 | Rapport relecture `deliveryCharge.js` | Déterminer si le calcul est local (barème hardcodé) ou via API backend. | Aucune | `resources/js/helpers/deliveryCharge.js` | Fee preview diverge du fee final → plainte client. |
| **MV-010** | P1 | Rapport relecture `kioskCart.js` + `posOrder.js` pricing | Chercher tout calcul de prix côté client (multiplication, somme, remise). | Aucune | `resources/js/store/modules/kioskCart.js`, `resources/js/store/modules/posOrder.js` | Frontend calcule un total différent du backend → incohérence affichée. |
| **MV-011** | P1 | Rapport relecture branch isolation controllers | Vérifier `where('branch_id', ...)` ou scope global sur: `KitchenDisplaySystemController`, `OrderStatusScreenController`, `PosController`, `PosOrderController`, `ItemController`, `ComposerProfileController`. | Aucune | 6 controllers listés | Fuite de données cross-branch. |
| **MV-012** | P1 | Rapport relecture `StockConcurrentDecrementTest.php` | Compter le nombre de threads/workers réels dans le test. | Aucune | `tests/Feature/Stock/StockConcurrentDecrementTest.php` | Test "concurrent" avec 2 threads = faux sentiment de sécurité. |
| **MV-013** | P1 | Rapport relecture `QueueNumberConcurrencyTest.php` | Compter le nombre de threads/workers réels. Vérifier le mécanisme (DB constraint seul ou retry logic). | Aucune | `tests/Feature/QueueNumberConcurrencyTest.php` | Queue number "concurrent" avec 2 threads = insuffisant. |
| **MV-014** | P1 | Rapport relecture `StockSymmetryDiffTest.php` | Déterminer si le test compare les signatures de méthodes ou les effets transactionnels réels. | Aucune | `tests/Feature/Stock/StockSymmetryDiffTest.php` | Symétrie OrderService/FrontendOrderService non prouvée. |
| **MV-015** | P1 | Rapport relecture `DispatchDomainEventsJob.php` | Vérifier idempotence (guard sur `dispatched_at`), retry logic, dead-letter. | Aucune | `app/Jobs/DispatchDomainEventsJob.php` | Double dispatch → double notification KDS, double stock movement. |
| **MV-016** | P1 | Rapport relecture `ZReportService.php` | Vérifier: clôture unique par (branch, date), vérification commandes pending, continuité séquence, chaîne Z HMAC. | Aucune | `app/Services/Fiscal/ZReportService.php` | Z-report incorrect → non-conformité NF525. |
| **MV-017** | P2 | Rapport relecture `OrderStateMachine.php` + `PaymentStateMachine.php` | Lister toutes les transitions autorisées/interdites, comparer à la matrice C8. | Aucune | `app/Domain/Order/OrderStateMachine.php`, `app/Domain/Order/PaymentStateMachine.php` | Transition dangereuse non bloquée. |
| **MV-018** | P2 | Rapport relecture axe-core config | Vérifier si `design-audit-helpers.js` configure `axe.configure()` avec des règles désactivées. | MV-002 | `tests/e2e/design/_shared/design-audit-helpers.js` | seriousTotal=0 est un artefact de config restrictive. |
| **MV-019** | P2 | Rapport diff fichiers touchés Codex 2026-04-28 | Diff `FrontendNavBarComponent.vue`, `FloorplanComponent.vue`, `KitchenDisplaySystemComponent.vue`, `PreparingAndReadyComponent.vue`, `SearchItemComponent.vue`, `NotFoundComponent.vue`, `ExceptionComponent.vue`, `MyOrderComponent.vue`, `app.css` vs commit précédent. | Aucune | 9 fichiers listés | Régression silencieuse introduite par sprint Codex. |
| **MV-020** | P2 | Rapport relecture `routes/api.php` counter-collect | Identifier les routes counter-collect inline, vérifier middleware auth + branch scope. | Aucune | `routes/api.php` | Routes non protégées → accès non autorisé. |
| **MV-021** | P2 | Rapport relecture `ComposerProfilePublished` → `CatalogChanged` chain | Tracer dans `EventServiceProvider`: `ComposerProfilePublished` → quels listeners → `CatalogChanged` → quels listeners → outbox. | MV-003 | `EventServiceProvider.php`, listeners | Publish composer → rien ne se passe côté kiosk/POS. |
| **MV-022** | P2 | Rapport relecture `MenuProjectionService.php` + `AvailabilityService.php` + `KioskMenuService.php` branch scoping | Vérifier que chaque service filtre par `branch_id`. | Aucune | 3 services | Menu d'une autre branche affiché. |
| **MV-023** | P2 | Rapport relecture `DeliveryFeeService.php` | Vérifier algorithme: zones, linéaire, clamp min/max, seuil gratuit, TVA livraison. | Aucune | `app/Services/Delivery/DeliveryFeeService.php` | Calcul fee incorrect. |
| **MV-024** | P2 | Rapport relecture middleware throttle routes KDS/OSS | Identifier le rate limit appliqué aux routes KDS/OSS polling. | Aucune | `app/Http/Kernel.php`, `routes/api.php` | `Too Many Attempts` en production pendant rush. |
| **MV-025** | P3 | Rapport relecture `KioskWaitingComponent.vue` timer + store reset | Vérifier `beforeDestroy`/`onUnmounted` reset le store et le timer. | Aucune | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` | Borne bloquée sur écran confirmation. |

---

## PHASE 1 — CORRECTIONS P0 (bloquants release)

| ID | Priorité | Livrable | Description | Dépendance | Fichiers allowlist | Tests obligatoires | Risque si skip |
|---|---|---|---|---|---|---|---|
| **FIX-001** | P0 | Fix `$afterCommit` sur tous les listeners | Si MV-003 révèle des listeners sans `$afterCommit = true`, ajouter la propriété. | MV-003 | `app/Listeners/Persist*.php`, `app/Listeners/DecrementStockOnOrderCreated.php`, `app/Listeners/ReleaseStockOnOrderCanceled.php`, `app/Listeners/ReleaseStockOnRefundCreated.php` | Test: dispatch event dans une transaction qui rollback → vérifier que le listener n'a PAS exécuté son side effect. | Event dispatché avant commit → état fantôme, double stock, notification KDS pour commande inexistante. |
| **FIX-002** | P0 | Fix chaînes magiques status | Si MV-004 révèle des `->status = 'string'`, remplacer par enum. | MV-004 | Fichiers identifiés par le grep | Test: chaque fichier modifié compile + tests existants passent. | Comparaison lâche → commande bloquée dans un état invalide. |
| **FIX-003** | P0 | Fix fiscal sequence allocation timing | Si MV-005/MV-006 révèle que la séquence est allouée avant `PAID`, déplacer l'allocation dans le handler de transition vers `PAID`. | MV-005, MV-006 | `app/Services/Fiscal/FiscalSealingService.php`, `app/Services/PaymentService.php` | `FiscalNullAtCreationTest` (cf. C6 tour 4) | Violation NF525. Amende 7 500€. |
| **FIX-004** | P0 | Fix delivery fee SSOT | Si MV-007 révèle que le backend utilise le fee client, modifier pour recalculer. | MV-007 | `app/Http/Requests/OrderRequest.php`, `app/Http/Requests/PosOrderRequest.php` | `DeliveryFeeSsotTest`: soumettre commande avec `delivery_fee: 0` sur 10km → backend doit recalculer et facturer le vrai fee. | Client forge livraison gratuite. Perte financière directe. |
| **FIX-005** | P0 | Fix kiosk route lockdown | Si MV-008 révèle des routes admin accessibles, les supprimer du router kiosk. | MV-008 | `resources/js/router/modules/kioskRoutes.js` | Test Playwright: naviguer vers `/admin/*` depuis contexte kiosk → 404 ou redirect home. | Client accède au dashboard admin depuis la borne publique. |
| **FIX-006** | P0 | Implémenter test HMAC chain integrity | Créer test qui génère 20 reçus fiscaux, vérifie la chaîne, tente une insertion/suppression, vérifie détection de rupture. | MV-005 | `tests/Feature/Fiscal/HmacChainIntegrityTest.php`, `app/Services/Fiscal/FiscalSealingService.php` | Le test lui-même est le livrable. Run-many 5x. | Chaîne HMAC non vérifiée → falsification possible des reçus. Non-conformité NF525. |
| **FIX-007** | P0 | Implémenter test fiscal NULL at creation (tous chemins) | 4 tests: kiosk card, kiosk cash-at-counter, POS cash, POS card. Chacun vérifie `fiscal_sequence_number IS NULL` à la création et `NOT NULL` uniquement après `PAID`. | MV-006 | `tests/Feature/Fiscal/FiscalNullAtCreationTest.php` | Le test lui-même. Run-many 5x. | Séquence fiscale pour commande non payée. |
| **FIX-008** | P0 | Implémenter test fiscal cancel sans séquence | Créer commande kiosk cash-at-counter → cancel (timeout) → vérifier `fiscal_sequence_number IS NULL` et aucun `fiscal_receipt` créé. | FIX-007 | `tests/Feature/Fiscal/FiscalCancelNoSequenceTest.php` | Le test. Run-many 5x. | Reçu fiscal pour commande annulée non payée. |
| **FIX-009** | P0 | Implémenter test fiscal refund crée reçu négatif | Créer commande → payer → refund → vérifier nouveau `fiscal_receipt` avec `type=REFUND`, `amount_ttc < 0`, séquence `N+1`. | FIX-006 | `tests/Feature/Fiscal/FiscalRefundNegativeReceiptTest.php` | Le test. Run-many 5x. | Remboursement sans trace fiscale. |
| **FIX-010** | P0 | Implémenter test outbox replay idempotence | Replay le même outbox entry 3x → vérifier qu'un seul broadcast est émis, un seul side effect. | MV-015 | `tests/Feature/Outbox/OutboxReplayIdempotenceTest.php`, `app/Jobs/DispatchDomainEventsJob.php` | Le test. Run-many 5x. | Double notification KDS, double stock movement sur replay. |
| **FIX-011** | P0 | Implémenter test Z-report complet | Créer 10 commandes (mix SALE/REFUND) → générer Z-report → vérifier totaux, continuité séquence, chaîne Z HMAC, rejet double clôture, rejet si commande PENDING. | MV-016 | `tests/Feature/Fiscal/ZReportFullLifecycleTest.php` | Le test. Run-many 3x. | Z-report incorrect. Non-conformité NF525. |
| **FIX-012** | P0 | Implémenter C3-S1: Kiosk → KDS → OSS runtime | Test Playwright multi-page: kiosk crée commande → KDS reçoit ≤ 5s → OSS affiche. Avec vérification DB (stock, queue number, outbox). | MV-003 (afterCommit vérifié), PRE-1 à PRE-12 (cf. tour 3) | `tests/e2e/c3-runtime-multi-surface.spec.js` | Le test. Run-many 3x. | Système fonctionne en silo. KDS ne reçoit jamais les commandes kiosk. |
| **FIX-013** | P0 | Implémenter C3-S2: POS → KDS → OSS runtime | Même que S1 mais depuis POS. Vérifie symétrie OrderService/FrontendOrderService en runtime. | FIX-012 | Même fichier que FIX-012 | Le test. Run-many 3x. | POS et kiosk divergent en production. |
| **FIX-014** | P0 | Implémenter C3-S4: Simultané kiosk + POS → KDS reçoit les deux | Promise.all kiosk + POS → KDS count +2, queue numbers uniques. | FIX-012, FIX-013 | Même fichier | Le test. Run-many 3x. | Perte de commande sous charge simultanée. |
| **FIX-015** | P0 | Implémenter C3-S6: Branch isolation runtime | Commande branch 1 → invisible KDS branch 2. | FIX-012 | Même fichier | Le test. Run-many 3x. | Fuite de commandes cross-branch. Données client exposées à un autre restaurant. |

---

## PHASE 2 — CORRECTIONS P1 (critiques avant UAT)

| ID | Priorité | Livrable | Description | Dépendance | Fichiers allowlist | Tests obligatoires | Risque si skip |
|---|---|---|---|---|---|---|---|
| **FIX-016** | P1 | Implémenter C3-S3: KDS bump → OSS update | Bump sur KDS → OSS passe de "en préparation" à "prêt" ≤ 5s. | FIX-012 | `tests/e2e/c3-runtime-multi-surface.spec.js` | Le test. Run-many 3x. | Client ne sait jamais que sa commande est prête. |
| **FIX-017** | P1 | Implémenter C3-S5: Reconnexion WebSocket KDS | Couper WS → créer commande → reconnecter → rattrapage ≤ 15s, 0 doublon. | FIX-012 | Même fichier | Le test. Run-many 3x. | KDS perd des commandes après micro-coupure réseau. |
| **FIX-018** | P1 | Implémenter C4: Stock stress 50 concurrent | 50 workers parallèles décrémentent le même item → stock final exact, 0 négatif, stock_movements count exact. | MV-012 | `tests/Feature/Stock/StockStress50ConcurrentTest.php`, `app/Services/Stock/StockService.php` | Le test. Run-many 3x. | Stock négatif ou double décrémentation en rush service. |
| **FIX-019** | P1 | Implémenter C4: Stock stress avec rupture live | 50 workers, stock initial = 30 → 30 réussissent, 20 reçoivent rupture, propagation `StockLevelChanged`. | FIX-018 | Même fichier ou fichier séparé | Le test. Run-many 3x. | Client commande un item en rupture. |
| **FIX-020** | P1 | Implémenter C4: Stock release concurrent (cancel + refund) | 20 commandes créées → 10 cancel + 10 refund en parallèle → stock releasé exactement. | FIX-018 | `tests/Feature/Stock/StockReleaseConcurrentTest.php` | Le test. Run-many 3x. | Stock jamais releasé → item indisponible indéfiniment. |
| **FIX-021** | P1 | Implémenter C5: Queue number stress 20 workers | 20 workers créent des commandes POS + kiosk en parallèle sur même branch+date → tous les queue numbers uniques, pas de gap, pas de crash. | MV-013 | `tests/Feature/QueueNumber/QueueNumberStress20WorkersTest.php` | Le test. Run-many 5x. | Deux clients reçoivent le même numéro. |
| **FIX-022** | P1 | Run `FrontendSurfaceFilteringTest` sur MySQL 8 | Configurer un environnement MySQL 8 (Docker ou local), exécuter le test, fixer les requêtes incompatibles si nécessaire. | Aucune | `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`, potentiellement `app/Services/Menu/MenuProjectionService.php` | Le test passe sur MySQL 8. | Requête SQL incompatible MySQL 8 → crash en production. |
| **FIX-023** | P1 | Fix rate limit KDS/OSS | Si MV-024 révèle un rate limit trop bas (< 120 req/min) sur les routes KDS/OSS, augmenter ou exempter ces routes. | MV-024 | `app/Http/Kernel.php` ou `routes/api.php` (middleware throttle) | Test: 120 requêtes en 60s sur route KDS → 0 réponse 429. | KDS bloqué par `Too Many Attempts` pendant rush. |
| **FIX-024** | P1 | Implémenter test C8: Payment state machine full matrix | Data provider avec toutes les transitions autorisées + toutes les interdites (cf. tour 6 C8.4.1). | MV-017 | `tests/Feature/Payment/OrderPaymentStateMachineFullMatrixTest.php` | Le test. Run-many 3x. | Transition dangereuse non bloquée → commande dans état invalide. |
| **FIX-025** | P1 | Implémenter test C8: Side effects atomicité par chemin | 4 tests (kiosk card, kiosk cash-at-counter, POS cash, POS card): vérifier que chaque chemin produit exactement les bons side effects (fiscal, stock, KDS notif, queue number) au bon moment. | FIX-024 | `tests/Feature/Payment/PaymentSideEffectsAtomicityTest.php` | Le test. Run-many 3x. | Commande payée mais jamais envoyée en cuisine. Ou cuisine prépare commande non payée. |
| **FIX-026** | P1 | Implémenter test C8: Cash-at-counter full lifecycle | Créer kiosk cash-at-counter → vérifier PENDING_PAYMENT + fiscal NULL → counter confirm → vérifier CONFIRMED + fiscal alloué + KDS notifié. Puis variante cancel → vérifier CANCELLED + stock releasé + fiscal NULL. | FIX-025 | `tests/Feature/Payment/CounterDeferredFullLifecycleTest.php` | Le test. Run-many 3x. | Cash-at-counter est le chemin le plus complexe et le plus fragile. |
| **FIX-027** | P1 | Implémenter test C8: Couplage OrderStatus ↔ PaymentStatus atomique | Vérifier que `PaymentStatus → PAID` et `OrderStatus → CONFIRMED` sont dans la même transaction. Simuler crash entre les deux → vérifier rollback. | FIX-024 | `tests/Feature/Payment/OrderPaymentAtomicCouplingTest.php` | Le test. Run-many 3x. | Client paie mais commande reste CREATED → jamais en cuisine. |
| **FIX-028** | P1 | Implémenter test delivery fee SSOT cross-surface | 3 tests: web frontend, POS, kiosk (si livraison activée) → même adresse → même fee final backend. Vérifier que le payload client est ignoré. | MV-007, MV-009 | `tests/Feature/Delivery/DeliveryFeeSsotCrossSurfaceTest.php` | Le test. Run-many 3x. | Fee différent selon la surface pour la même adresse. |
| **FIX-029** | P1 | Implémenter test delivery geocode failure → 422 | Adresse introuvable → 422. Google Maps down → 422. Adresse hors pays → 422. Adresse vide → 422. | MV-023 | `tests/Feature/Delivery/DeliveryGeocodeFailureTest.php` | Le test. 4 cas minimum. | Commande livraison acceptée sans adresse valide. |
| **FIX-030** | P1 | Implémenter test delivery boundary cases | Distance = max_radius → OK. Distance = max_radius + 0.01 → 422. Total = seuil gratuit → fee 0. Total = seuil - 1 centime → fee > 0. Fee < min → clamp. Fee > max → clamp. | MV-023 | `tests/Feature/Delivery/DeliveryBoundaryCasesTest.php` | Le test. 6+ cas. | Client hors zone accepté, ou client dans zone refusé. |
| **FIX-031** | P1 | Implémenter test image propagation end-to-end | Upload photo admin → vérifier URL accessible → vérifier présence dans menu API kiosk → vérifier présence dans menu API POS. | MV-021 | `tests/Feature/Menu/ImagePropagationEndToEndTest.php` | Le test. | Photo produit absente sur borne après upload. |
| **FIX-032** | P1 | Fix branch isolation controllers | Si MV-011 révèle des controllers sans scope `branch_id`, ajouter le scope. | MV-011 | Controllers identifiés par MV-011 | Test par controller: requête avec `branch_id=2` ne retourne pas de données `branch_id=1`. | Fuite de données cross-branch. |
| **FIX-033** | P1 | Fix counter-collect routes | Si MV-020 révèle des routes inline sans middleware, extraire vers controller + ajouter middleware auth + branch. | MV-020 | `routes/api.php`, nouveau controller si nécessaire | Test: requête non authentifiée → 401. Requête autre branche → 403. | Accès non autorisé à counter-collect. |
| **FIX-034** | P1 | Implémenter test kiosk lockdown e2e | Playwright: depuis contexte kiosk, tenter navigation vers `/admin/dashboard`, `/admin/pos`, `/admin/kds`, `/admin/items` → toutes redirigent vers kiosk home ou 404. | FIX-005 | `tests/e2e/kiosk-lockdown.spec.js` | Le test. Run-many 3x. | Validation que le fix FIX-005 tient. |

---

## PHASE 3 — CORRECTIONS P2 (important, post-P0/P1)

| ID | Priorité | Livrable | Description | Dépendance | Fichiers allowlist | Tests obligatoires | Risque si skip |
|---|---|---|---|---|---|---|---|
| **FIX-035** | P2 | Implémenter C9-S1: Dashboard catégories e2e | Playwright: login admin → créer catégorie → modifier → réordonner → supprimer vide → tenter supprimer non-vide → 422. | Phase 1 terminée | `tests/e2e/c9-dashboard-categories.spec.js` | Le test. | Restaurateur ne peut pas gérer ses catégories. |
| **FIX-036** | P2 | Implémenter C9-S2: Dashboard produits e2e | Playwright: créer produit → upload photo → modifier prix → désactiver → supprimer. Vérifier propagation kiosk. | FIX-035 | `tests/e2e/c9-dashboard-products.spec.js` | Le test. | Restaurateur ne peut pas gérer ses produits. |
| **FIX-037** | P2 | Implémenter C9-S3: Dashboard stock e2e | Playwright: modifier quantité → définir seuil alerte → forcer rupture → vérifier propagation kiosk (item grisé) → lever rupture. | FIX-036 | `tests/e2e/c9-dashboard-stock.spec.js` | Le test. | Restaurateur ne peut pas gérer son stock. |
| **FIX-038** | P2 | Implémenter C9-S4: Dashboard composer e2e | Playwright: créer profil composer → ajouter 4 steps → définir prix → publier → vérifier kiosk → dépublier → vérifier disparition. | FIX-037 | `tests/e2e/c9-dashboard-composer.spec.js` | Le test. | Restaurateur ne peut pas créer de menus composés. |
| **FIX-039** | P2 | Implémenter C10-T1: Composer publish → outbox → menu projection (backend) | Test Feature: publish → outbox entry créée + menu projection inclut le profil. Unpublish → outbox + menu