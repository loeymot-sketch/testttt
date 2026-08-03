# GATE_LOG — Frozen Zone Decisions Trail

**Politique** : tout changement qui touche un fichier sous **verrou** actif (`tasks/phase9-sync/LOCK_*.md`), ou le **schéma DB**, l’**auth**, le **pricing SSOT**, le **fiscal / NF525**, la **machine à états commande**, ou un **dispatch** sensible (ordre vs commit), doit être couvert par un **Gate Brief** humain (`docs/gates/GATE_*.md`, hors ce fichier) puis **consigné ici** après décision. Procédure de brief et de reprise de boucle : `.cursor/rules/human-gates.mdc`.

Cartographie indicative **fichier frozen ↔ LOCK file ↔ cycles** : `plans/PLAN_POST_VERIFY_2026-04-20.md` §3 (tableau « Gate humain requis », env. lignes 156–173).

---

## Format d’entrée obligatoire

| Date | Gate ID | Brief file | Frozen files touched | Decision | Approver | Commit SHA / Cycle |
|------|---------|------------|----------------------|----------|----------|-------------------|
| YYYY-MM-DD | `GATE_*` | `docs/gates/GATE_*.md` | chemins relatifs repo, ou `?` si incertain | Approved / Approved-with-constraint / Rejected / Deferred / `PENDING_HUMAN_GATE` | Nom (humain) | sha7, identifiant de tâche, ou `(rétroactif — non corrélé)` |

---

## Trail rétroactif (reconstitué 2026-04-20)

_Une ligne par brief présent dans `docs/gates/` au 2026-04-20 (hors `GATE_LOG.md`). Champs non attestés dans le brief source : `(non documenté — rétroactif)`._

| Date | Gate ID | Brief file | Frozen files touched | Decision | Approver | Commit SHA / Cycle |
|------|---------|------------|----------------------|----------|----------|-------------------|
| 2026-04-14 | GATE_MULTISURF_001_2026-04-14 | docs/gates/GATE_MULTISURF_001_2026-04-14.md | `routes/api.php`, `resources/js/router/**`, `app/Http/Controllers/Auth/LoginController.php`, seeds / rôles `landing_url` (OrderService / FrontendOrderService exclus selon brief) | Approved | Kossay | (rétroactif — non corrélé) |
| 2026-04-14 | GATE_PAYMENT_SAFETY_001_2026-04-14 | docs/gates/GATE_PAYMENT_SAFETY_001_2026-04-14.md | `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php` | Approved | Kossay (human) | (rétroactif — non corrélé) |
| 2026-04-14 | GATE_SYNC_WIZARD_DEEP_001_2026-04-14 | docs/gates/GATE_SYNC_WIZARD_DEEP_001_2026-04-14.md | `app/Services/FrontendOrderService.php`, `app/Services/OrderService.php` | Approved | Kossay (human) | (rétroactif — non corrélé) |
| 2026-04-15 | GATE_BATCH_V1_APPROVAL_CHECKLIST | docs/gates/GATE_BATCH_V1_APPROVAL_CHECKLIST.md | Checklist batch renvoyant vers 4 briefs V1 : `OrderService` + `FrontendOrderService` (pricing / status machine), migration `item_branch_availability`, soft-delete + `deletion_log` | (non documenté — rétroactif) | (non documenté — rétroactif) | (rétroactif — non corrélé) |
| 2026-04-15 | GATE_V1_DATA_SOFTDELETE_001_2026-04-15 | docs/gates/GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md | `orders`, `frontend_orders`, `order_items`, `branches`, `item_categories` (`deleted_at`), table `deletion_log`, modèles + observer admin (OrderService / FrontendOrderService non modifiés selon brief) | (non documenté — rétroactif) | (non documenté — rétroactif) | (rétroactif — non corrélé) |
| 2026-04-15 | GATE_V1_MENU_86_001_2026-04-15 | docs/gates/GATE_V1_MENU_86_001_2026-04-15.md | `item_branch_availability` (migration), `ItemBranchAvailability`, `AvailabilityService`, listener `DecrementItemAvailabilityOnOrder`, `ItemController`, UI POS/Kiosk/KDS ; pas `OrderService` / `FrontendOrderService` (selon brief) | (non documenté — rétroactif) | (non documenté — rétroactif) | (rétroactif — non corrélé) |
| 2026-04-15 | GATE_V1_PRICING_SSOT_001_2026-04-15 | docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md | `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`, namespace `app/Services/Pricing/` (`PricingService`, etc.) | (non documenté — rétroactif) | (non documenté — rétroactif) | (rétroactif — non corrélé) |
| 2026-04-15 | GATE_V1_STATUS_MACHINE_001_2026-04-15 | docs/gates/GATE_V1_STATUS_MACHINE_001_2026-04-15.md | `app/Domain/Order/OrderStateMachine.php`, `IllegalTransitionException.php`, `OrderStatusTransition`, migration `order_status_transitions`, `OrderService.php`, `FrontendOrderService.php`, `KitchenDisplaySystemOrderService.php` | (non documenté — rétroactif) | (non documenté — rétroactif) | (rétroactif — non corrélé) |
| 2026-04-20 | GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md | `app/Services/OrderService.php`, `app/Services/PaymentService.php`, `routes/api.php`, `app/Services/Pricing/DiscountCalculator.php`, migrations idempotency / coupons / pricing ; périmètre détaillé §1–2 du brief (8 cycles P0) | `PENDING_HUMAN_GATE` | (non documenté — en attente humain sur le brief) | (rétroactif — non corrélé) |

---

## Trail courant

| Date | Gate ID | Brief file | Frozen files touched | Decision | Approver | Commit SHA / Cycle |
|------|---------|------------|----------------------|----------|----------|-------------------|
| 2026-04-25 | GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25 | docs/gates/GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25.md | `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`, `app/Services/PaymentService.php`, `app/Services/KitchenDisplaySystemOrderService.php`, `routes/api.php`, `app/Http/Controllers/Frontend/OrderController.php` | Approved — Option C — Partial allowlist by method/surface | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-25 | GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25 | docs/gates/GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25.md | `app/Services/FrontendOrderService.php`, `app/Http/Controllers/Frontend/OrderController.php` | Approved — Option B — POS finalize | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-25 | GATE_PAYMENT_LEDGER_V1_2026-04-25 | docs/gates/GATE_PAYMENT_LEDGER_V1_2026-04-25.md | `app/Services/PaymentService.php`, future payment migrations if Option A | Approved — Option B — Restricted pilot | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-25 | GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25 | docs/gates/GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25.md | `app/Http/Requests/OrderStatusRequest.php`, `app/Services/KitchenDisplaySystemOrderService.php`, `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | Approved — Option B — Server authority with `expected_status` | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-25 | GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25 | docs/gates/GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25.md | future schema migrations for payment, order quotes, KDS releases, fiscal Z, idempotency | Approved — Option A — All migrations with rehearsal + backup | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-25 | GATE_OFFLINE_SCOPE_V1_2026-04-25 | docs/gates/GATE_OFFLINE_SCOPE_V1_2026-04-25.md | `resources/js/helpers/kioskOfflineQueue.js`, `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`, kiosk cart/menu store surfaces | Approved — Option A — Read-only menu, paiement désactivé | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-25 | GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25 | docs/gates/GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25.md | public payment route and PaymentIntent signing surfaces if Option A | Approved — Option B — Web payment off V1 | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-25 | GATE_STRIPE_CENTS_ACTIVE_2026-04-25 | docs/gates/GATE_STRIPE_CENTS_ACTIVE_2026-04-25.md | Stripe config/payment tests if Stripe active | Approved — Option B — Stripe inactive prod V1 guard | Codex (instruction humaine explicite, 2026-04-25) | CV1-M03-GATES-DRAFT |
| 2026-04-26 | GATE_PAYMENT_PROP_MUTATION_2026-04-26 | docs/gates/GATE_PAYMENT_PROP_MUTATION_2026-04-26.md | `resources/js/components/admin/pos/PaymentComponent.vue`, `resources/js/components/admin/pos/PosComponent.vue`, `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (symétrie), backend `OrderService` / `FrontendOrderService` (vérification contrat API) | Approved — Option A — Refactor complet sous gate | Codex (instruction humaine explicite, 2026-04-25) | POS_V4_W1B_VENDOR_CHUNK (cycle d'origine du brief — refactor en cycle dédié POS_V4_W2_PAYMENT_REFACTOR si Option A approuvée) |
| 2026-04-26 | HG-W2-1 (cutover POS V4) | docs/gates/GATE_W2_CUTOVER_2026-04-26.md | `routes/web.php` (Options B/C/D), `resources/views/master.blade.php` (Option D si redirige `/admin/pos` → `/admin/pos-v4`), `app/Http/Controllers/Frontend/RootController.php` (Option C A/B branch-aware) — Options A/E/F : aucun frozen touché | `PENDING_HUMAN_GATE` (soft-blocked — attend HG-W2-3 cleared + 1 campagne LCP réel) | (en attente — Product + UX + Tech Lead) | POS_V4_W2_DEDICATED_ENTRY |
| 2026-04-26 | HG-W2-2 (vendor split `vendor-pos.js`) | À DRAFTER après HG-W2-3 (Options B/C/D pourraient le rendre inutile) | `webpack.mix.js`, `resources/views/master.blade.php`, `resources/views/admin-pos-v4.blade.php` | `BLOCKED` (HG-W2-3 KPI revision requise d'abord — si Option A/E/F retenue, ce gate est annulé) | (bloqué) | POS_V4_W2_DEDICATED_ENTRY |
| 2026-04-26 | HG-W2-3 (KPI revision 220 → 600 KB + LCP) | docs/gates/GATE_W2_KPI_REVISION_2026-04-26.md | aucun frozen — décision produit (cible de mesure, pas de code) | `PENDING_HUMAN_GATE` | (en attente — Product owner + UX) | POS_V4_W2_DEDICATED_ENTRY |
| 2026-04-26 | HG-ACTIVE-PRIMARY-SELECTION | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | `.cursor/ACTIVE_CYCLE.md` | Approved-with-constraint — Caisse V1 / POS+Kiosk devient primaire, W10 à nettoyer en secondaire/archive | Kossay / user kossayelbenna8 | PHASE2_TRAIN_A_BOOTSTRAP |
| 2026-04-26 | HG-MEMORY-EPISODES-POLICY | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | `memory/INDEX.md`, `.gitignore`, `memory/episodes/*.jsonl` policy | Approved-with-constraint — tracker mémoire V1 utile, documenter, éviter le bruit | Kossay / user kossayelbenna8 | PHASE2_TRAIN_A_BOOTSTRAP |
| 2026-04-26 | HG-PHASE-A-CLOSE-SIGNOFF | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | `docs/PHASE_A_CLOSED.md`, sentinels, quote subsystem, gates | Approved-with-constraint — close autorisé après propreté/preuves du périmètre Train A; bruit hors périmètre non bloquant si documenté | Kossay / user kossayelbenna8 | PHASE2_TRAIN_A_BOOTSTRAP + addendum 2026-04-27 |
| 2026-04-26 | HG-ORDERQUOTE-HMAC-APPKEY | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | `app/Services/Order/OrderQuoteService.php`, `tests/Feature/OrderQuoteHmacKeyRequiredTest.php` | Approved-with-validation — fail closed si APP_KEY vide, test automatisé requis | Kossay / user kossayelbenna8 | GOV-PERSIST-QUOTE-SUBSYSTEM-2026-04-27 |
| 2026-04-26 | HG-PAYMENT-V1-SIMULATED-EXTERNAL-TERMINAL | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | payment UX / payment confirmation policy, no gateway live | Approved — paiement carte manuel/simulé contrôlé jusqu'à configuration gateway réelle ; cash normal avec tiroir | Kossay / user kossayelbenna8 | PHASE2_TRAIN_A_BOOTSTRAP |
| 2026-04-26 | HG-SENANGPAY-FRANCE-UNUSED-REVIEW | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | `app/Http/PaymentGateways/Routes/senangpay.php`, payment gateway legacy code | Approved-with-audit — Senangpay probablement legacy/non-France ; auditer puis désactiver/supprimer sous mission dédiée | Kossay / user kossayelbenna8 | FUTURE_PAYMENT_GATEWAY_CLEANUP |
| 2026-04-26 | HG-DM13-QUEUE-UNIQUE-STRATEGY | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | queue-number migration, `OrderService`, `FrontendOrderService` | Approved — business-day model selected: unique `(branch_id, business_date, queue_number)` with daily reset semantics | Kossay / user kossayelbenna8 | D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28 + addendum 2026-04-27 |
| 2026-04-26 | HG-DM13-MIGRATION-SIGNOFF | docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md + docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | D-M13 migration, `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php` | Approved-with-rollout-constraints — staging/prod path allowed after backup, maintenance window, zero-duplicate preflight/backfill, rollback runbook, and business-day model alignment | Kossay / user kossayelbenna8 | D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28 + addendum 2026-04-27 |
| 2026-04-26 | HG-I18N-FR-PRIMARY | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | UI/i18n text policy | Approved — français langue V1 principale, audit des libellés techniques visibles requis | Kossay / user kossayelbenna8 | FUTURE_I18N_FR_AUDIT |
| 2026-04-26 | HG-KIOSK-BUNDLE-BUDGET-V1 | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | frontend build/performance budget | Approved-with-constraint — budget = taille JS/performance, warning V1 accepté si Playwright vert | Kossay / user kossayelbenna8 | FUTURE_KIOSK_PERF_CLEANUP |
| 2026-04-26 | HG-W2_CUTOVER_DECISION_OR_POS_WIZARD_SHIM_ACCEPTANCE | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | legacy POS wizard/kiosk shim policy | Approved — shim temporaire accepté pour V1, cleanup strict post-V1 | Kossay / user kossayelbenna8 | PHASE2_TRAIN_A_BOOTSTRAP |
| 2026-04-26 | HG-HARDWARE-LAB-SIGNOFF | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | hardware UAT policy | Approved-as-required / pending execution — tiroir, imprimante, borne, écran KDS disponibles ; UAT à exécuter avant release commerciale | Kossay / user kossayelbenna8 | FUTURE_HARDWARE_LAB_UAT |
| 2026-04-27 | HG-FROZEN-ORDER-HUNKS-TRAIN-A-2026-04-27 | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`, POS/Kiosk order parity hunks | Approved — strict hunks only for D-M13 queue allocation, POS walk-in customer, delivery-fee backend authority, and required parity | Kossay / user kossayelbenna8 | USER_TRAIN_A_UNBLOCK_2026-04-27 |
| 2026-04-27 | HG-POS-WALKIN-CUSTOMER-V1 | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | POS customer/order flow | Approved — takeaway/counter POS must auto-use `Client Comptoir`; no operator Client ID selection for normal counter order | Kossay / user kossayelbenna8 | USER_TRAIN_A_UNBLOCK_2026-04-27 |
| 2026-04-27 | HG-DELIVERY-FEE-V1 | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | delivery fee frontend estimate + backend authority | Approved — `0-5 km = 5 EUR`; above 5 km add `1 EUR` per started kilometer; backend recomputes authoritatively | Kossay / user kossayelbenna8 | USER_TRAIN_A_UNBLOCK_2026-04-27 |
| 2026-04-27 | HG-DASHBOARD-AFTER-TRAIN-A | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | dashboard catalog/category/stock control plane | Approved — dashboard write/control-plane build remains Train B after Train A/D-M13 close | Kossay / user kossayelbenna8 | USER_TRAIN_A_UNBLOCK_2026-04-27 |
| 2026-04-27 | HG-KIOSK-LOCKED-CUSTOMER-SURFACE | docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md | kiosk routes/layout/admin access | Approved — no admin route, hidden admin tap, or kiosk-to-caisse navigation on customer kiosk; staff intervention from caisse/admin only | Kossay / user kossayelbenna8 | USER_TRAIN_A_UNBLOCK_2026-04-27 |

---

## Process futur

### Quand créer une entrée

- Dès qu’un **Gate Brief** obtient une **décision humaine** (ou reste `PENDING_HUMAN_GATE`), et avant de considérer la zone comme levée pour l’exécution.
- **Systématiquement** si le diff touche :
  - un chemin **frozen** ou listé dans `plans/PLAN_POST_VERIFY_2026-04-20.md` §3 ;
  - un fichier associé à un **`tasks/phase9-sync/LOCK_*.md`** ;
  - une **migration** ou contrainte DB ;
  - l’**auth** / tokens / garde-fous API ;
  - le **calcul de prix** côté serveur ou sa symétrie POS/kiosk ;
  - **OrderStatus** / fiscal / audit immuable ;
  - un **dispatch** devant rester **après commit** transactionnel.

### Format

- Une ligne par décision (ou mise à jour explicite de statut), en reprenant les colonnes du tableau « Format d’entrée obligatoire ».

### Liste des LOCK files (référence 2026-04-20)

Fichiers sous `tasks/phase9-sync/` :  
`LOCK_A_P9_5_FrontendOrderService_2026-04-18.md`,  
`LOCK_A_P9_5_OrderItem_migration_allergens_2026-04-18.md`,  
`LOCK_A_P9_5_OrderService_2026-04-18.md`,  
`LOCK_A_P9_5_PricingService_PricingRequests_2026-04-18.md`,  
`LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md`,  
`LOCK_B_POS_9_2_FrontendOrderService_2026-04-18.md`,  
`LOCK_B_POS_9_2_OrderController_admin_2026-04-18.md`,  
`LOCK_B_POS_9_2_routes_api_2026-04-18.md`,  
`LOCK_B_POS_9_3_EventContract_2026-04-18.md`,  
`LOCK_B_POS_9_4_BL_DiscountCalculator_2026-04-18.md`,  
`LOCK_B_POS_9_2_3_OrderService_2026-04-18.md`,  
`LOCK_B_POS_9_2_3_PaymentService_2026-04-18.md`.  
*(Convention : tout nouveau verrou suit le motif `LOCK_*.md` dans ce répertoire.)*

### Self-approval interdite — `.cursor/rules/human-gates.mdc` (lignes 79–86)

Rappel des **Absolute Prohibitions** : pas de remplissage du champ d’approbation par le modèle ; pas de reprise de boucle parce qu’un gate « paraît » résolu ; pas de traitement silencieux d’un soft gate comme absence de gate ; **pas d’édition frozen sans gate approuvé et trace ici** ; pas de migration sans approbation humaine écrite ; pas de changement d’isolation `branch_id` sans revue d’isolation enregistrée.

La **reprise de boucle** reste conditionnée par le protocole §Resumption Protocol du même fichier (approbation humaine dans le brief, décision dans ce log, relecture du brief levé, plan à jour).

| 2026-05-02 | `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-05-02` | docs/gates/GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK_2026-05-02.md | app/Services/Pricing/PricingService.php | `PENDING_HUMAN_GATE` | (à approuver) | CV1-LIFECYCLE-UX-001 task 2.2 |
| 2026-05-02 | `GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE_2026-05-02` | docs/gates/GATE_SCHEMA_STOCK_MOVEMENT_IDEMPOTENCY_KEY_UNIQUE_2026-05-02.md | database/migrations (DDL) + app/Services/Stock/StockService.php (small) | `PENDING_HUMAN_GATE` | (à approuver) | CV1-LIFECYCLE-UX-001 task 2.6 |
| 2026-05-02 | `GATE_DROP_TABLE_DELIVERY_BOYS_V1_2026-05-02` | docs/gates/GATE_DROP_TABLE_DELIVERY_BOYS_V1_2026-05-02.md | resources/js/components/admin/deliveryBoys/** + tables delivery_boys | `PENDING_HUMAN_GATE` | (à approuver) | CV1-V1-CLOSEOUT-001 Lot B livraison |
| 2026-05-02 | `GATE_DROP_TABLE_TABLE_SERVICE_V1_2026-05-02` | docs/gates/GATE_DROP_TABLE_TABLE_SERVICE_V1_2026-05-02.md | 4 modules : waiters, chefs, tableOrders, diningTable + POS floorplan | `PENDING_HUMAN_GATE` | (à approuver) | CV1-V1-CLOSEOUT-001 Lot B service à table |
| 2026-05-02 | `GATE_DROP_TABLE_ONLINE_ORDERS_V1_2026-05-02` | docs/gates/GATE_DROP_TABLE_ONLINE_ORDERS_V1_2026-05-02.md | onlineOrders + frontend public + tables online_orders | `PENDING_HUMAN_GATE` | (à approuver après audit Axe 5/1/3) | CV1-V1-CLOSEOUT-001 Lot B online |
| 2026-05-03 | `GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03` | docs/gates/GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md | database/migrations + source_ref integrity surfaces | Approved-with-constraint — Option 2 staging only, prod delayed pending soak evidence | Kossay / user kossayelbenna8 | CV1-V2-REMAINING-MISSIONS-001 |
