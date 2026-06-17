# GOAL — VALIDATION PRODUCTION FINALE V1 LOCAL « Le Cayenne »
**Date** : 2026-06-17 · **Superviseur** : Claude (Master dev, dirige tout) · **Branche** : `goal/wizard-wysiwyg-builder-2026-06-14` @ `6efb74f74` (LOCAL) · **Mission** : valider chaque système, chaque page, chaque fonctionnalité (data-in → data-out) + toute la synchronisation, **en boucle jusqu'à validation absolue**, avant prod-réelle-sans-retour.

> **Mandat owner (verbatim, contrat)** : « test et validation de chaque système … brique d'un détail pour la CAISSE toutes les pages puis Dashboard toutes les pages … chaque fonctionnalité testée et re-testée en boucle … superpowers + gstack + adversaires … toi le superviseur dirige … test-e2e valide chaque système + chaque fonctionnalité + toute la synchronisation … abuse fonctionnalité par fonctionnalité (entrée data → output) … boucle jusqu'à validation finale absolue … audit puis re-boucle … ne retourne jamais avec un problème … pas de limite ».

---

## §0 — PRÉAMBULE

### 0.1 Working-tree decision
- Worktree DÉJÀ isolé (`.claude/worktrees/wizard-wysiwyg-2026-06-14`). HEAD `6efb74f74`, arbre propre côté source (bundles+node_modules non suivis attendus).
- **Discipline** : commit checkpoint par vague (`feat/fix/test(...)`), JAMAIS `git add -A` (chemins explicites + secret-scan), JAMAIS `--no-verify`.
- **G-PUSH** = owner gate absolu : rien n'est poussé sans accord explicite (CLAUDE.md §10).

### 0.2 Rig (vérifié 2026-06-17)
`:8766` = serveur APP_ENV=e2e sur **MySQL `foodking_e2e`** + **redis** + **queue:work** + **soketi:6001** — TOUS UP. PHPUnit = SQLite `:memory:` (phpunit.xml, APP_ENV=testing) → **lancer `php artisan test` SANS préfixe `APP_ENV=e2e`** (sinon BranchScope/scopes console se désactivent — leçon 2026-06-17). Concurrence réelle (FOR UPDATE) → bursts process-OS contre :8766.

### 0.3 Critère de convergence (Axis 6 test-e2e)
**VALIDÉ = 2 cycles consécutifs avec P0+P1 = 0 ET set de findings identique.** Tout trigger de rejet (label brut, layout cassé, erreur console, 4xx silencieux, diff frozen, P0 non traité, test rouge non documenté, « presque bon ») = REJET → heal → re-test. Max 3 heal-loops/cluster → sinon escalade (Axis 3).

### 0.4 Pipeline par tâche
Chaque tâche s'exécute via `ultra-audit-profond` (14-step) ou `superpower-gstack` (7-step LOOP) ; l'abuse par `test-e2e` (dual-team) ; override frozen via `lock-plan`. Ce GOAL ne re-décrit pas ces pipelines.

### 0.5 État de départ (acquis cette série, NE PAS refaire)
SOLIDE & prouvé : fiscal seq gap-free **sous 8-way MySQL** · pricing SSOT never-trust-client · isolation branche+RBAC (+P1 /profile healé) · idempotency L7 · stock oversell (1OK/7REJECT réel) · sync claim-once · UI/UX FR + i18n + glyphes · NF525 day-lifecycle (Z=somme exacte, chaîne HMAC, 6 ans) · borne offline replay-once · appsec 0-P0/P1. **53 tests Abuse verts, NF525 CHAIN OK, frozen diff 0.** Ce GOAL = **validation EXHAUSTIVE page-par-page + feature-par-feature** au-dessus de cet acquis.

---

## §1 — MAP PRINCIPAL (5 systèmes, maturité + ancres vérifiées)

| # | Système | Maturité | Ancres vérifiées (find/ls 2026-06-17) | Tests existants |
|---|---|---|---|---|
| S1 | **CAISSE/POS** | haute (cœur frozen) | `Admin/PosController.php`, `AdminPosV4Controller.php`, `PosOrderController.php`, `EncaissementController`, `CashOverviewController.php`, `CashSessionReportController.php`, `Pos/PosReceiptPrintController.php` · `public/js/pos-{app,shell,wizard}.js` · 14 `.vue` sous `admin/pos/` | `tests/Feature/Pos/` (17) |
| S2 | **BORNE/kiosk** | haute (frozen) | `resources/js/components/frontend/kiosk/` (48 `.vue`) · `FrontendOrderService.php` | `tests/Feature/Kiosk/` (5) |
| S3 | **KDS + OSS** | haute | `admin/kitchenDisplaySystem/` (7 `.vue`) + `admin/orderStatusScreen/` (3 `.vue`) · `KDSOrderDetailsResource.php` | `tests/Feature/KDS/` (13) |
| S4 | **CENTRAL/admin + Dashboard** | haute | `admin/dashboard/` (15 `.vue`) + `DashboardController.php` (16 endpoints) + 37 dirs `admin/*` | `tests/Feature/` (large) |
| S5 | **SYNC (bus partagé)** | haute | `Events/Order*.php` (6) · `Listeners/Persist*ToOutbox.php` · `Jobs/DispatchDomainEventsJob.php` · `SYNC_CONTRACT.md` | `tests/Feature/Sync/`, `Abuse/SyncChaosOutboxAbuseTest` |

### §2 — Systèmes séparés (HORS scope V1, ne pas wirer)
- Mobile RN (`mobile/`) + Web standalone (`/Downloads/web/`) = STANDALONE, no-API-wireup V1 (owner mandate). **Exclus de ce GOAL.**

---

## §3 — SYSTÈME 1 : CAISSE/POS  *(BRIQUE ULTRA-DÉTAILLÉE — priorité owner #1)*

### Contract
Caisse staff fast-food FR : commande (wizard) + paiement + tiroir + encaissement borne + suivi + Z NF525. **Lane argent = backend SSOT.**
### Frozen zones (strict-no-touch, audit-only)
`public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php`, `PaymentComponent.vue`, `PosV5TrancheRow.vue` (CLAUDE.md §7). Toute correction frozen = `lock-plan` + owner gate.

### LES 8 PAGES CAISSE (chaque page = sous-tâche : load → chaque bouton → data-in/data-out → abuse → e2e → re-test loop)

#### Sub 1.1 — POS Wizard `/admin/pos` (+ floorplan `/admin/pos/floorplan`)
**Anchors** : `PosComponent.vue`, `pos-wizard.js` (FROZEN), `FloorplanComponent.vue`, `PosController.php`, `Admin/PosController.php`
**Tasks** :
- T-1.1.1 Audit chaque bouton de l'operator-bar (À encaisser, Suivi, Écran client, Réduction fidélité, Ouvrir tiroir, Caisse) — handler lié + endpoint réel + état.
- T-1.1.2 Wizard composition (item+variation+extras+suppléments) : 0 label brut, prix par étape = backend, quote binding.
- T-1.1.3 Abuse data-in/out : payload trafiqué (option_ids cross-item, qty négative/énorme, item soft-deleted), totaux client ignorés → recalcul SSOT.
- T-1.1.4 Floorplan : sélection table → commande liée, libération post-paiement.
**Acceptance** : `tests/Feature/Pos/QuoteBindingTest`, `PosOrderRequestNoClientTotalsTest`, `PosPricingSsotProofTest`, `Abuse/PricingAdversarialTest` PASS + E2E :8766 wizard 4 templates GREEN + visual 0 défaut.

#### Sub 1.2 — Encaissement `/admin/encaissement` + Paiement
**Anchors** : `EncaissementController`, `PaymentComponent.vue` (FROZEN), `PosLoyaltyRedeemModal.vue`, `ReceiptComponent.vue`
**Tasks** :
- T-1.2.1 Encaisser borne (À encaisser N) → PAID + **fiscal_sequence_no alloué** (FISCAL-CPS-01 healed) → entre au Z.
- T-1.2.2 Modes paiement (espèces/CB simulée/TR) : montant reçu ≥ total, rendu, tiroir.
- T-1.2.3 Fidélité redeem : preview + toast FR (`PosLoyaltyRedeemModal`), pas de stack coupon, solde décrémenté once.
- T-1.2.4 Abuse : double-encaissement (idempotency), encaissement off-shift, refund.
**Acceptance** : `Fiscal/ChangePaymentStatusFiscalAllocTest`, `Abuse/IdempotencyMiddlewareAbuseTest`, `refundReceiptMarkerSentinel` PASS + E2E borne→encaissement→PAID+ticket NF525 GREEN.

#### Sub 1.3 — Suivi & Commandes `/admin/pos-orders` (list+show+tracker `/admin/pos-orders-tracker`)
**Anchors** : `PosOrderController.php`, `PosOrdersTrackerComponent.vue`, `posOrders/*.vue`
**Tasks** :
- T-1.3.1 Liste : pagination/filtre/recherche/export ; money FR (`currency_*`), dates FR 24h.
- T-1.3.2 Show : line-items composition snapshot immuable, montants FR, statut.
- T-1.3.3 Tracker kanban : transitions live (PREPARING→PREPARED→…) via sync, reprint 409-guard.
- T-1.3.4 Abuse : transition hors-ordre (OrderStateMachine), reprint dupliqué.
**Acceptance** : `tests/Feature/Pos/*` statut/tracker PASS + E2E live transition reflétée GREEN.

#### Sub 1.4 — Caisse mgmt : Cash-overview `/admin/cash-overview` + Sessions-report `/admin/cash-sessions-report` + Caisse Livreur
**Anchors** : `CashOverviewController.php`, `CashSessionReportController.php`, `DeliveryBoyCashSessionController.php`, `CashOverviewComponent.vue`, `CashSessionReportListComponent.vue`
**Tasks** :
- T-1.4.1 Cash-overview : encaissements espèces sans session (bannière régularisation), SSOT cash-attendu.
- T-1.4.2 Sessions-report : ouverture/clôture/variance, money FR Intl (healed), totaux exacts.
- T-1.4.3 Caisse livreur : COD escrow, variance reconcile, fiscal alloc COD (FISCAL-DELIV-COD-01 healed).
- T-1.4.4 Abuse : variance forcée, session double-open.
**Acceptance** : `Fiscal/FiscalCashAtCounterLifecycleTest`, `DeliveryBoyChangeStatusFiscalAllocTest` PASS + visual money FR GREEN.

---

## §4 — SYSTÈME 4 : CENTRAL / DASHBOARD  *(BRIQUE ULTRA-DÉTAILLÉE — priorité owner #2)*

### Contract
Pilotage : tableau de bord temps-réel + CRUD catalogue/users/settings + rapports. **FR-locked.**
### Frozen zones : aucune directe (mais `PricingService`/`BranchScope` consommés).

#### Sub 4.1 — Dashboard `/admin/dashboard` (15 widgets-pages)
**Anchors** : `admin/dashboard/{DashboardComponent,OverviewComponent,OrderStatisticsComponent,SalesSummaryComponent,OrderSummaryComponent,CustomerStatsComponent,TopCustomersComponent,FeaturedItemsComponent,MostPopularItemsComponent,RealtimeReportComponent,ChannelStatsComponent,AuditTrailComponent,SlaAlertsComponent,StockLowAlertsWidget,LastZReportWidget}.vue` · `DashboardController.php` (16 endpoints)
**Tasks** (chaque widget validé data-in→data-out) :
- T-4.1.1 Cartes overview (Total ventes/commandes/articles) + Suivi-direct (CA jour/commandes/ticket moyen) : data = backend agrégé, money FR, contraste AA.
- T-4.1.2 SLA alerts : durées humanisées (« 6 j 4 h », healed), glyphe horloge OK, refresh 15s.
- T-4.1.3 Stats (orderStatistics/salesSummary/orderSummary/channelStats) : graphes alimentés, 0 NaN/undefined, % cohérents.
- T-4.1.4 Widgets data (topCustomers/featured/mostPopular/realtimeReport/auditTrail/stockLowAlerts/LastZReport) : empty-states, dates FR 24h, Z widget = dernier Z réel.
- T-4.1.5 Abuse : données vides, gros volumes, branche scoping ; PDF clôture jour (`eodPdf`).
**Acceptance** : `tests/Feature/Dashboard*` (ou *(à créer : `tests/Feature/Abuse/DashboardWidgetsDataTest.php`)*) PASS + E2E :8766 dashboard 0 erreur console + visual 15 widgets GREEN.

#### Sub 4.2 — Catalogue & stock (items/catalogue-studio/ingredients/attributes/stock)
**Anchors** : `admin/items/{ItemListComponent,ItemShowComponent,CatalogStudioComponent,...}.vue`, `admin/stock/*`, `ItemController.php`
**Tasks** : CRUD produit, money FR (`currency_price` healed), glyphes (alias healed), stock hiérarchique sync-live, abuse (prix négatif rejeté, image upload).
**Acceptance** : `tests/Feature/Stock/*`, `Abuse/StockOversellAbuseTest` PASS + visual catalogue GREEN.

#### Sub 4.3 — Promos & users & settings (coupons/offers/loyalty · customers/employees/roles · settings)
**Anchors** : `admin/{coupons,offers,customers,employees,settings}/*.vue`, `CouponController.php`, `OfferController.php`
**Tasks** : coupon type-aware %/€ (healed), offre % (healed), CRUD users + RBAC, settings (Branch/Tax/Currency/Language), abuse (coupon expiré/>subtotal, RBAC escalade).
**Acceptance** : `Abuse/PricingAdversarialTest`, `BranchScopePrefixIsolationAbuseTest`, `FormRequestAuthzDriftSentinelTest` PASS.

#### Sub 4.4 — Rapports (sales/items/credit-balance/transactions/order-history)
**Anchors** : `admin/{salesReport,itemsReport,creditBalanceReport,transactions,orderHistory}/*.vue`
**Tasks** : money FR (healed `SimpleOrderResource` siblings), dates FR 24h (healed), totaux = somme exacte, export, abuse (filtres date, gros volume).
**Acceptance** : `Abuse/Nf525FiscalDayLifecycleTest` (cohérence ventes↔Z) PASS + visual rapports GREEN.

---

## §5 — SYSTÈME 2 : BORNE/kiosk

### Contract : self-order client FR-locked, paiement TPE/Plan-B caisse. ### Frozen : `Kiosk{Wizard,App,Upsell}Component.vue`.
#### Sub 2.1 — Wizard borne : composition, prix backend, allergènes, upsell. **Acc** : `tests/Feature/Kiosk/*`, `kioskPricingPreview.spec` + E2E borne idle→commande GREEN.
#### Sub 2.2 — Paiement + offline : file offline replay-once, reconcile-pending TPE, idempotency. **Acc** : `Abuse/BorneOfflineReplayAbuseTest`, `Kiosk/PaymentReconcileTest` PASS.
#### Sub 2.3 — Borne→KDS sync : OrderCreated push, dégradation poll. **Acc** : E2E borne→KDS live GREEN (cross-surface).

## §6 — SYSTÈME 3 : KDS + OSS

### Contract : écran cuisine (bump) + mur public commandes. ### Frozen : aucune directe.
#### Sub 3.1 — KDS board : bump/recall, release-guard (UNPAID non-bumpé), 5s freshness budget (W6), empty-state. **Acc** : `tests/Feature/KDS/*` (13) PASS + visual KDS GREEN.
#### Sub 3.2 — OSS mur : empty-state (healed SVG+msg), couleurs en-tête contraste-correct, durées FR. **Acc** : E2E OSS GREEN + visual.
#### Sub 3.3 — KDS↔OSS↔tracker sync : statut identique cross-surface. **Acc** : cross-surface E2E GREEN.

## §7 — SYSTÈME 5 : SYNC (bus partagé)

### Contract (SYNC_CONTRACT.md) : outbox `domain_events` → `DispatchDomainEventsJob` → soketi `private-branch.1` ; dégradation = poll, no-loss.
#### Sub 5.1 — Claim-once : 8-way dispatch même event → broadcast 1× (PROUVÉ). **Acc** : burst MySQL R1 + `Abuse/SyncChaosOutboxAbuseTest`.
#### Sub 5.2 — Chaos no-loss : kill worker/soketi mid-burst → replay-once, 0 orphelin perdu. **Acc** : recipe R2/R3 vs :8766.
#### Sub 5.3 — Rescue re-broadcast P3 : `delivered_at` marker (déféré V1.0.X — owner gate G-SYNC-DELIVERED).

---

## §A — ARMÉE D'AGENTS (rôles × outils × fan-out)

| Rôle | subagent | Outils | Mission |
|---|---|---|---|
| Architect | Plan/general | Read | cohérence, patterns, ancres |
| Security/RED | general | Read | RBAC, branch, secrets, abuse |
| UX/A11y | general | Read+Playwright | WCAG, ARIA, flat-design, visual |
| DBA | general | Read | schema, FK, N+1, BranchScope/20 |
| SRE/Sync | general | Read | outbox, soketi, poll, queue, chaos |
| Implementer | general | Edit+Write+Bash | TDD-first, scope-minimal |
| Fiscal | general | Read | NF525 chain, Z, seq, alloc |
| QA Visual | general | Read+Playwright | capture + analyse screenshot |
| RED Visual | general | Read | re-analyse screenshots, dispute |

**Fan-out** : frontend-page = Architect+Security+UX+Implementer+RED+QA-Vis+RED-Vis ; backend/fiscal = Architect+Security+DBA+Fiscal+Implementer+RED ; sync = +SRE. **5 read-only en parallèle (1 message), implementer JAMAIS parallèle implementer.** Adversaire APRÈS commit, AVANT « done ». Rapports persistés disque `reports/test-e2e/prod-finale-2026-06-17/<wave>/`.

---

## §X — VAGUES DE CONVERGENCE (8 vagues, checkpoint + interrupt-resume)

| Vague | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| **W0** | Pré-vol : rig + baselines (phpunit count, audit_logs hash, NF525 chain) | séquentiel | rig UP + baselines capturés |
| **W1** | **CAISSE brique** (§3 Sub 1.1–1.4, 8 pages) page-par-page : load→boutons→data-in/out→abuse→e2e | séquentiel (cœur frozen) + audit fan-out | 8 pages PASS, 0 P0/P1, frozen diff 0 |
| **W2** | **DASHBOARD brique** (§4.1, 15 widgets) data-in→data-out chaque widget | séquentiel + audit fan-out | 15 widgets PASS, 0 console err |
| **W3** | CENTRAL reste (§4.2–4.4 catalogue/promos/users/settings/rapports) | 2-parallèle (domaines disjoints) | CRUD+RBAC+money+dates PASS |
| **W4** | BORNE (§5) + KDS/OSS (§6) | 2-parallèle (disjoints) | wizard+offline+bump+OSS PASS |
| **W5** | **SYNC cross-surface E2E** (§7) : borne→KDS→OSS→caisse→encaissement live + chaos | séquentiel | claim-once + no-loss + statut identique 5 écrans |
| **W6** | **ABUSE feature-par-feature** (data-in→data-out) sur TOUTES les features cataloguées W1-W5 | fan-out adversaire | chaque feature : input connu → output vérifié |
| **W7** | **AUDIT FINAL + re-boucle** : full suite + 2 cycles convergence identiques + NF525 attestation | séquentiel | 2 cycles P0+P1=0 set-identique |

**Checkpoint (6 points/vague)** : tasks PASS|documenté · frozen diff 0 · NF525 chain append-only · visual gate tiré · RED dispute close · BRAIN §2/§3 maj. **Interrupt** : commit WIP + manifest `reports/.../INTERRUPT_<wave>.md` + BRAIN. **3 heal-loops/cluster max** → Plan-agent + STUCK doc + escalade owner.

---

## §G — OWNER GATES (WHO/WHAT/WHERE)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G-PUSH | Push remote | Owner | accord explicite | commit/PR | PENDING |
| G-FROZEN | Toute correction frozen (pos-wizard/PaymentComponent/fiscal services) | Owner | LOCK doc countersign | `lock-plan` §10 | PENDING si requis |
| G-SYNC-DELIVERED | `delivered_at` marker (rescue re-broadcast P3) | Owner+Claude | décision V1.0.X vs maintenant | BRAIN §4 | PENDING |
| G-DEADLOCK | retry-on-deadlock fiscal (frozen) | Owner | LOCK + sign-off | `lock-plan` | PENDING |
| G-PROD | Bascule prod réelle (boot-guards, storage:link, npm run production, env) | Owner | déploiement validé | deploy report | PENDING (final) |

**Protocole** : gate PENDING ne bloque que SA vague ; les autres tournent. La validation FINALE (W7) tourne SANS push (G-PUSH reste owner).

---

## §R — RÉFÉRENCES
CLAUDE.md §§4-13 · PROJECT_BRAIN.md §2 · SYNC_CONTRACT.md · SYSTEM_MAP.md · memory/reference_frozen_zones.md · skills `ultra-audit-profond`/`superpower-gstack`/`test-e2e`/`lock-plan` · reports session 2026-06-17 (uiux-deep, abuse-backend, integration-resilience).

## §F — RÈGLE FINALE (DONE = production-perfect, pas « presque »)
Le GOAL est DONE quand : (1) les 8 vagues fermées ; (2) **2 cycles de convergence consécutifs P0+P1=0 set-identique** ; (3) CAISSE 8 pages + Dashboard 15 widgets + chaque feature **abuse-validée data-in→data-out** ; (4) cross-surface E2E (borne→KDS→OSS→caisse) + sync chaos GREEN ; (5) frozen diff 0 sur tout le range ; (6) NF525 CHAIN OK + Z=somme exacte ; (7) full suite verte. **Ne jamais retourner avec un problème : heal-loop jusqu'au vert, audit, re-boucle. Reste = owner gates (G-PUSH/G-PROD).**
