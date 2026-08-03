# MASTER CHECKLIST DEEP SINGLE — FoodKing — 2026-04-25

## 0. Verdict exécutif

**V1 fonctionnelle visée** : FoodKing doit pouvoir encaisser en POS cash/card, créer et finaliser une commande borne avec TPE/cash, pousser la commande vers KDS, afficher OSS, synchroniser les surfaces par outbox/Echo, et maintenir l'isolation stricte `branch_id`. La V1 opérationnelle n'inclut pas un durcissement NF525 complet post-clôture Z sur tous les changements de statut/paiement, sauf décision humaine qui transforme ce point en P0 fiscal. Le backend reste la seule source de vérité pour prix, statuts, disponibilité, fiscalité et événements.

| Axe | Score | Verdict | Cause principale |
|---|---:|---|---|
| V1 opérationnelle | 5/10 | **NEEDS_EVIDENCE** | P0 encore sans tests sentinelles : `payment-confirm`, KDS whitelist, branch leaks, no-op cashback, promo borne, cleanup race |
| POS + KDS production | 4/10 | **NOT_READY** | gates frozen ouverts, discount POS bloqué, mutation props paiement, couverture POS/KDS insuffisante |
| Sync/outbox/Echo | 6/10 | **PARTIAL** | socle after-commit solide, mais fallback WS, event table, identity flood, bump local et observability incomplets |
| Fiscal/NF525 | 5/10 | **GATE** | Z/audit logs existent, mais status/payment après clôture Z restent hors garde complète |
| Tests | 5/10 | **NEEDS_EVIDENCE** | beaucoup de tests unitaires/feature existent, mais les chemins P0 cachés ne sont pas couverts |

**Synthèse** : le dépôt a un socle robuste (OrderStateMachine, PricingService, outbox, BranchScope, tests nombreux), mais les chemins indirects restent le vrai risque : routes admin qui réutilisent `OrderService::list/show`, POS qui utilise l'endpoint KDS pour encaisser, borne qui preview une promo non consommée au checkout, et lifecycle paiement/statut qui déclenche des effets financiers sans idempotence métier suffisante.

**MASTER_DEEP_VERDICT: NEEDS_HUMAN_GATES**

## 1. Méthode et périmètre

**Audit réalisé** : lecture statique locale par Codex, consolidation des rapports/plans existants, inspection des chemins clés et reprise des preuves déjà produites. Tentative d'appel Claude terminal relancée dans ce tour via `scripts/foodking-claude-orchestrate.sh audit`, mais le CLI a répondu `Not logged in · Please run /login`; ce rapport est donc le **fallback local unique** demandé, dans le même fichier cible.

**Sources lues / consolidées** :

| Type | Chemins |
|---|---|
| Contrat / invariants | `AGENTS.md`, `.cursor/ACTIVE_CYCLE.md`, `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`, `docs/orchestration/MEMORY_MATRIX.md` |
| Docs métier | `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `docs/BUSINESS_RULES.md`, `docs/API_MAP.md` |
| Plans globaux | `plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_v2_2026-04-23.md`, `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` |
| Audits consolidés | `reports/audit/CHALLENGE_RAPPORT_FINAL_DEEP_SINGLE_2026-04-25.md`, `reports/audit/AUDIT_MASSIF_POS_KIOSK_KDS_SYNC_2026-04-23.md`, `reports/audit/AUDIT_KDS_POS_SYNCHRONISATION_PROFONDE_2026-04-24.md`, `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`, `reports/audit/AUDIT_FINAL_W0_CROSSCHECK_2026-04-26.md` |
| Code inspecté | routes API/web/channels, controllers admin/frontend/auth, requests, services order/pricing/kiosk/KDS/outbox/fiscal, frontend POS/Kiosk/KDS/OSS stores/helpers, tests Feature/js pertinents |

**Règle de preuve** : le code et les tests gagnent contre les rapports. Les rapports servent à retrouver les angles morts, pas à les valider. Les conclusions ci-dessous utilisent le format `preuve -> inférence -> risque -> action/test`, sans exposer de raisonnement interne non vérifiable.

**Limites** :
- Aucun test nouveau n'a été exécuté pendant cette relance après l'échec Claude. Les tests PASS mentionnés viennent du rapport deep précédent et des fichiers de tests existants.
- Les fichiers datés `2026-04-26` existent localement dans le dépôt malgré la date courante du contexte `2026-04-25`; ils sont utilisés comme artefacts de repo, pas comme faits calendaires externes.
- Les line numbers cités sont issus des lectures précédentes et des extraits inspectés; si un patch concurrent a modifié les fichiers, relancer `rg -n` avant exécution.

## 2. Master Checklist Globale

| ID | Domaine | Fonctionnalité | Statut | Prio | Preuves | Risques | Action suivante | Test / gate attendu |
|---|---|---|---|---|---|---|---|---|
| A1 | Gouvernance | Cycle FoodKing / Active cycle | PARTIAL | P1 | `.cursor/ACTIVE_CYCLE.md` cycle `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22` actif | Travail audit hors cycle peut se mélanger au closeout si non nommé | Garder ce rapport comme audit/pre-cycle sous `reports/audit` | Aucun patch produit depuis ce rapport |
| A2 | Gouvernance | Gates frozen P0 | GATE | P0 | `PLAN_MASTER_FINITIONS...` LOT-1; `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` en attente | Toucher OrderService/PaymentService/Pricing sans gate invalide l'audit | Décision humaine TL+BE+QA NF525 | Entrée `GATE_LOG.md` Approved/Rejected |
| A3 | Gouvernance | Terminal Claude audit | BLOCKED | P1 | `claude -p` via wrapper retourne `Not logged in` | Perte second cerveau terminal autonome | Relogin humain `claude /login` côté terminal, puis rerun audit | `bash scripts/foodking-claude-orchestrate.sh smoketest` |
| B1 | Auth | Kiosk login Sanctum ability | VALIDATED | P1 | `KioskMachineLoginController.php:76-88` crée ability `kiosk:order`; tests KioskPhase1 | Bon socle token borne | Conserver ability comme garde centrale | `KioskEventAbilityTest`, `KioskLoginApiTest` |
| B2 | Auth | `payment-confirm` | FLAGGED | P0 | `routes/api.php:889-895`, `Frontend/OrderController.php:77-115` auth Sanctum + ownership, pas `abilities:kiosk:order` | Tout client Sanctum propriétaire peut promouvoir `PAID` | Ajouter middleware ability + KioskMachine resolver + méthode/statut | Test non-kiosk owner -> 403 |
| B3 | Auth | `loyalty/scan` | FLAGGED | P2 | route `auth:sanctum` seule; controller check `tokenCan` au code | Defense-in-depth incohérente | Ajouter route middleware `abilities:kiosk:order` | Feature route sans ability -> 403 |
| C1 | Branch | `OrderService::list` | FLAGGED | P0 | `OrderService.php:108-171`, filtre `branch_id` en LIKE; utilisé par POS/online/table/sales/export | branch 1 voit 10/11/100, exports contaminés | `branch_id` exact + actor branch default | Tests cross-branch sur 7 surfaces |
| C2 | Branch | `OrderService::show($auth=false)` | FLAGGED | P0 | `OrderService.php:1330-1346`, controllers show passent false | Accès direct par ID cross-branch | Guard explicite order branch vs actor | GET show order autre branche -> 403 |
| C3 | Branch | `TransactionService::list` | FLAGGED | P1 | `TransactionService.php:23-66`, filtre branch optionnel | Transactions cross-branch sans param | Injecter branch actor par défaut | Transaction sans branch_id staff -> scoped |
| C4 | Branch | Channels Echo | PARTIAL | P2 | `routes/channels.php:25-39`, admin 0 écoute tout | Token admin volé = écoute multi-branches | Observabilité token + session invalid | Test channel kiosk/staff/admin |
| D1 | Pricing | Backend SSOT prix | VALIDATED | P1 | `PricingService.php`, `PricingRequest.php`, tests Pricing/POS | Socle fort | Garder frontend sans prix métier | `PricingIntegrityTest`, `PosPricingSsotProofTest` |
| D2 | Pricing | Promo borne preview vs checkout | FLAGGED | P0 | Preview accepte `kiosk_promo_code`; `OrderRequest`/`PricingRequest::forKiosk` ne le consomment pas | Total affiché != total facturé | Support bout-en-bout OU retirer du payload | Preview total == checkout total |
| D3 | Pricing | `formatKioskPrice` EUR/fr-FR hardcodé | FLAGGED | P1 | `kioskFormatPrice.js:31-32` fallback `fr-FR`/`EUR` | Branches non EUR affichent mauvais prix | Injecter locale/currency depuis store | Vitest locale/currency |
| D4 | Pricing | POS discount reason | FLAGGED | P0 | `PosComponent.vue` data `discountReason`, guard `.trim`, pas de `v-model` trouvé par audit | Remises POS bloquées | Ajouter binding + test | `posDiscountReason.spec.js` |
| E1 | Kiosk | Checkout order creation | PARTIAL | P0 | `FrontendOrderService::myOrderStore`, PricingService, idempotency branch-scoped | Bon socle, mais promo/offline/payment race | Tests contractuels bout-en-bout | Kiosk full flow + promo/offline |
| E2 | Kiosk | TPE accepted + confirm backend fail | FLAGGED | P1 | `KioskPaymentComponent.vue:447-577` attend confirm, mais pas de reprise opérateur | TPE encaissé, order pending | Endpoint/staff retry + reconciliation | Vitest + Feature delayed confirm |
| E3 | Kiosk | Cleanup stale pending | FLAGGED | P0/P1 | `CleanupStalePendingKioskOrders.php:19-58` auto REJECTED 15 min | Race TPE retardé vs auto-reject | Refuser confirm après REJECTED + log reconciliation | Payment-confirm après cleanup |
| E4 | Kiosk | Offline queue | PARTIAL | P1 | `kioskOfflineQueue.js`, `kioskCart.js` queue sur network/500 | Replay tardif + idempotency + payment state | Ajouter tests replay avec payment/cleanup | Vitest offline + Feature idempotency |
| E5 | Kiosk | Receipt privacy | FLAGGED | P2 | audit massif F-19 `kioskReceiptPersistence` last receipt | Reçu visible client suivant | TTL/purge sur delivered/reset | Vitest receipt TTL |
| F1 | POS | Cash/card order | PARTIAL | P0 | POS service/tests existent, mais payment props mutation gate | Risque state fantôme paiement | Gate PaymentComponent puis refactor | Vitest 401 retry + Feature submit |
| F2 | POS | POS cash via endpoint KDS | FLAGGED | P0 | `PosComponent.vue:1414-1421` poste `admin/kds-order/change-status/...DELIVERED` | Tighten KDS casse encaissement POS | Route POS dédiée collect cash | Integration POS collect |
| F3 | POS | Payment props mutation | GATE | P0 | `PaymentComponent.vue` 16+ mutations direct props; gate pending | State non déterministe, double submit | Décision Option A emit ou B local copy | Gate `GATE_PAYMENT_PROP_MUTATION` |
| F4 | POS | Parked orders | PARTIAL | P1 | plan master FIND-11 expires_at absent; cross-check branch filter commenté | Parked sans TTL explicite / branch ambiguity | `expires_at`, branch filter decision | Gate schema + tests parked resume |
| F5 | POS | NFC/cash drawer/void tests | FLAGGED | P1 | `tests/Feature/Pos` faible couverture | Flux shift non protégés | Créer tests Feature POS | Void/CashDrawer/NFC/ParkedResume |
| G1 | KDS | List branch filtering | VALIDATED | P1 | `KitchenDisplaySystemOrderService` exact `branch_id`; `KdsBranchFilterExactTest` | Staff KDS OK | Maintenir exact filter | Test anti LIKE |
| G2 | KDS | Status whitelist | FLAGGED | P0 | `OrderStateMachine` global permet cancel/POS delivered; service KDS délègue global | Chef peut sortir du couloir cuisine | Whitelist KDS PREPARING/PREPARED | Chef cancel/delivered -> 422 |
| G3 | KDS | `OrderStatusRequest` surface policy | FLAGGED | P0 | `OrderStatusRequest.php:23-31` rôles larges, statut numeric | Same request trop large pour routes différentes | Requests dédiées par surface | Matrix rôle x route x status |
| G4 | KDS | Bump localStorage multi-screen | FLAGGED | P0/P1 | `kds` store local, audit KDS §3.5 | Deux écrans cuisine divergent | Décider serveur ou single KDS documenté | E2E 2 onglets |
| G5 | KDS | Limit 50 | FLAGGED | P1 | KDS list cap 50 dans audit | Commandes invisibles en rush | Alerte cap / pagination / tri | Load test >50 active |
| G6 | KDS | RTL / Bengali | FLAGGED | P1/P2 | Swiper `dir=ltr`, `bn.json` 0 clés kds | Surface non exploitable RTL/bn | Dynamic dir + 27 clés | i18n audit + Vitest |
| H1 | OSS | Read-only | VALIDATED | P1 | `OSSReadOnlyTest`, only GET routes | Surface passive OK | Maintenir no POST/PUT | Route list no mutation |
| H2 | OSS | Admin branch 0 | FLAGGED | P1 | `OrderStatusScreenOrderService` admin 0 all branches | Ecran public multi-branch erroné | Branch explicit required | OSS admin branch pin |
| I1 | Lifecycle | OrderStateMachine | VALIDATED/PARTIAL | P0 | unit 77 cases, apply no-op; docs `ORDER_FLOW` | Global machine OK, surface-specific missing | Ne pas confondre global vs surface | Surface tests |
| I2 | Lifecycle | No-op cashback/status | FLAGGED | P0 | `OrderService::changeStatus` side effects before effective no-op; `PaymentService::cashBack` non dedupe | Double cashback/balance | Early return + transaction_no unique/idempotent | Double cancel -> one cashback |
| I3 | Lifecycle | PaymentStatus | FLAGGED | P1/P0 fiscal | `changePaymentStatus` direct numeric status | PAID/UNPAID toggles, Z impact | Mini PaymentStateMachine + sealed guard | Invalid transitions -> 422 |
| J1 | Availability | Decrement on order | VALIDATED/PARTIAL | P1 | `DecrementItemAvailabilityOnOrder`, AvailabilityService tests | One-way OK | Keep after-commit | Order reject unavailable |
| J2 | Availability | Release cancel/refund | FLAGGED | P0 | audit massif F-01; release listeners exist/need verification | Auto-86 never restored / over-release | Idempotent release per line qty | Cancel/refund partial/idempotent |
| J3 | Availability | ItemAvailabilityChanged payload | FLAGGED | P0 | plan v2 F-04bis | Front prune wrong/no event | Payload complete + defensive front | duplicate delivery + fan-out |
| K1 | Outbox | After commit domain events | VALIDATED | P1 | `DispatchableAfterCommit`, Persist listeners, `OutboxTest` | Socle solide | Keep job outside DB tx | Outbox concurrent dedupe |
| K2 | Outbox | Identity flood | FLAGGED | P2 | `PersistOrderStatusChangedToOutbox` no old==new guard | Echo/notif bruit si no-op | Early return old==new | Dispatch identity no event |
| K3 | Front event contract | FLAGGED | P2 | backend EventContract strict, `eventContract.js` tolerant null | Front dedupe/branch weak | Align strictness | JS reject missing correlation |
| K4 | Echo auth/reconnect | FLAGGED | P1 | audit massif F-12, plan 1.E | Events perdus silencieusement | subscription_error + refresh + banner | WS auth expired tests |
| K5 | Observability | sync_metrics purge | FLAGGED | P2 | master plan FIND-10 | Table grows unbounded | Purge job + retention config | SyncMetricsPurgeJobTest |
| L1 | Fiscal | Z close/signature | PARTIAL | P0 fiscal | `ZReportService`, fiscal tests | Good base | Keep immutable logs | Fiscal suite |
| L2 | Fiscal | Sealed status/payment | GATE | P0 fiscal if in scope | `destroy` guarded, status/payment not equivalent | Post-Z mutation possible | Human decision: V1 op vs fiscal | Gate NF525 |
| M1 | UX/a11y | Focus trap POS | FLAGGED | P1 | import dead, no activation | Keyboard escapes modals | Implement traps | A11y tests |
| M2 | UX/perf | POS v4 W0 | PARTIAL | P1 | Cross-check AMEND: bundle baseline, binding map weak | W1 opens with unresolved P0 | Close P0-CC items | npm build + ADR/signoff |
| N1 | Tests | Existing backend coverage | PARTIAL | P1 | many tests exist (Pricing/Kiosk/KDS/Outbox/Fiscal) | P0 gaps not covered | Run focused suite after fixes | Feature tests listed §7 |
| N2 | Tests | E2E | FLAGGED | P1 | e2e POS/Kiosk/KDS smoke only | No evening service scenario | Add critical E2E flows | Playwright multi-device |
| O1 | Memory | Graphiti/JSONL discipline | PARTIAL | P2 | Memory matrix OK; active closeout ongoing | Decisions from this audit not ingested | After human adoption, write JSONL | `after-execute-memory.sh` |

## 3. Breakdown fonctionnel un par un

### A — Gouvernance, cycle, gates

**Fonctionnement attendu** : toute tâche produit passe par `PLAN -> EXECUTE -> VALIDATE -> AUDIT -> GATE/CLOSE`. Les audits externes sous `reports/audit` peuvent exister hors cycle, mais ne doivent pas modifier le code produit ni fermer un gate.

**Chemin réel** : `AGENTS.md` impose `ACTIVE_CYCLE`, `run-cycle`, terminal Claude pour audit, Codex extension pour exécution complexe. `.cursor/ACTIVE_CYCLE.md` indique un cycle closeout mémoire/CI/prod encore `IN_PROGRESS`, ce rapport doit donc rester un artefact d'audit, pas un nouveau cycle fantôme.

**Coins cachés** :
- Les fichiers `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md` et audits `2026-04-26` sont présents localement et influencent le plan global, même si la date système est `2026-04-25`.
- Terminal Claude n'est pas authentifié dans cette invocation; la méthode primaire d'audit indépendante est bloquée tant que `claude /login` n'est pas rétabli.
- Plusieurs gates humains conditionnent les zones frozen : P0 consolidated, Payment prop mutation, schema parked expires_at, KPI/cutover, fiscal NF525 si inclus.

**Validé** : la discipline documentaire est claire; les stores autorisés sont A code/tests, B Graphiti/JSONL, C missions, D reports/cycle.

**Flags** :
- P0 gate : ne pas toucher `OrderService`, `PaymentService`, `OrderStateMachine`, pricing/fiscal/outbox frozen sans entrée gate.
- P1 orchestration : reconnecter Claude terminal pour retrouver le second cerveau automatisé.

### B — Auth, Sanctum, tokens kiosk

**Attendu** : un token kiosk doit avoir `kiosk:order`; les endpoints borne sensibles doivent exiger cette ability et résoudre la branche via `KioskMachine`, pas via le payload client.

**Chemin réel** :
- `POST /api/auth/kiosk-login` crée un token Sanctum limité `kiosk:order` dans `KioskMachineLoginController.php:76-88`.
- Les routes `frontend/kiosk-event` utilisent `abilities:kiosk:order`.
- `frontend/order/{id}/payment-confirm` est seulement sous `auth:sanctum` et vérifie ownership utilisateur dans `Frontend/OrderController.php:77-115`.

**Coins cachés** :
- `ValidateKioskLocale` valide la locale, pas l'ability; une route avec `kiosk.locale` n'est pas protégée par elle-même.
- `loyalty/scan` vérifie `tokenCan` dans le controller mais pas au niveau middleware.
- Les routes frontend mixtes client/kiosk utilisent souvent le même groupe Sanctum; il faut distinguer client web, kiosk machine et staff.

**Flags** :
- P0 `payment-confirm`: ajouter `abilities:kiosk:order`, resolver KioskMachine, vérifier `order_type`, `branch_id`, `payment_method`, `status`, `payment_status`.
- P2 `loyalty/scan`: ajouter middleware ability pour cohérence.

### C — Branch isolation, API, exports, reports, transactions

**Attendu** : aucune fuite inter-branches. Les filtres d'ID doivent être exacts, jamais `LIKE`. Les exports/PDF/rapports doivent hériter du même verrou.

**Chemin réel** :
- `BranchScope` protège plusieurs modèles mais court-circuite admin `branch_id=0`.
- `OrderService::list` filtre `branch_id` via logique générique `LIKE`, consommée par POS, online, table, sales-report, exports.
- `OrderService::show($auth=false)` retourne l'ordre sans branch policy explicite quand appelé par controllers admin.
- `TransactionService::list` applique `branch_id` seulement si la requête le fournit.

**Coins cachés** :
- Corriger seulement `GET admin/pos-order` ne suffit pas : `OrderExport`, `SalesReportExport`, online/table/pdf et dashboard/reporting réutilisent le service.
- Les URLs `show/{order}` peuvent être atteintes avec un ID direct et contourner la liste.
- `branch_id=0` admin est légitime mais doit être distingué d'un écran public OSS ou d'une caisse épinglée à une branche.

**Flags** :
- P0 exact filter + tests cross-branch sur index/show/export/pdf.
- P1 transactions : branch actor par défaut.
- P1 OSS admin 0 : exiger branche active pour écran public.

### D — Pricing SSOT, coupons, promos, loyalty, remises

**Attendu** : le frontend envoie intentions/IDs, le backend calcule tout. Les coupons/promos doivent être validés côté serveur et produire le même total preview/checkout.

**Chemin réel** :
- `PricingService` calcule lignes, variations, extras, taxes, discounts.
- `PricingRequest::forPos` inclut manuel/coupon; `forKiosk` ne prend pas `kiosk_promo_code`.
- `PricingPreviewService` accepte et applique `kiosk_promo_code` en preview, mais checkout `FrontendOrderService` ne le consomme pas.
- `kioskCart.js` envoie `kiosk_promo_code` tout en retirant les champs money du payload.

**Coins cachés** :
- La preview borne est crédible visuellement; si checkout ignore promo, le client voit un prix puis paie un autre.
- `PricingPreviewService::toObject` perd `variation.quantity`, donc la preview peut être fausse pour multi-quantités.
- `formatKioskPrice` peut afficher EUR/fr-FR même si le backend calcule correctement dans une autre devise; ce n'est pas une corruption backend, mais une corruption UX/prix affiché.
- Loyalty/cashback peut être déclenché par status changes no-op si les side effects précèdent les guards.

**Flags** :
- P0 promo preview/checkout : supporter ou supprimer.
- P0 no-op cashback.
- P1/P2 format price, variation.quantity, loyalty scan defense.

### E — Kiosk checkout, TPE, cash, offline queue, privacy

**Attendu** : borne crée une commande branch-scoped, TPE ne finalise que si paiement prouvé, cash doit rester dans un couloir POS, offline queue doit rejouer idempotemment sans créer d'état fiscal ambigu.

**Chemin réel** :
- `FrontendOrderService::myOrderStore` crée commande avec branch depuis `KioskMachine`.
- Card/TPE reste pending jusqu'à `payment-confirm`, puis `finalizePaidKioskOrder` promeut paid/pending vers ACCEPT.
- `CleanupStalePendingKioskOrders` rejette automatiquement les pending stale.
- `kioskCart.js` queue offline en cas de network/500 avec idempotency key.

**Coins cachés** :
- TPE peut accepter côté terminal mais `payment-confirm` échouer après 3 retries; l'UI ne navigue pas à tort, mais il manque une reprise opérateur.
- Cleanup peut auto-REJECT une commande avant un confirm retardé; sans reconciliation, on obtient TPE encaissé + order rejeté.
- Offline replay tardif peut croiser cleanup et idempotency.
- Receipt persistence peut exposer le reçu au client suivant sans TTL/purge stricte.

**Flags** :
- P0/P1 payment-confirm race/cleanup reconciliation.
- P1 staff retry endpoint.
- P2 receipt TTL privacy.

### F — POS cash/card/table/parked/NFC/void/discount/cash drawer

**Attendu** : POS est la surface opérateur pour encaisser, livrer, void/refund, park/resume, NFC lookup, cash drawer, discount avec justification, sans logique prix métier hors backend.

**Chemin réel** :
- POS crée via `OrderService`.
- Payment UI `PaymentComponent.vue` mute directement des props.
- `PosComponent.vue` utilise `admin/kds-order/change-status` pour collecter une commande borne cash en `DELIVERED`.
- Le plan master signale `discountReason` non bindé.
- Tests Feature POS spécifiques restent faibles.

**Coins cachés** :
- Si KDS est corrigé pour n'autoriser que PREPARING/PREPARED, POS cash casse car il utilise l'endpoint KDS.
- Muter les props paiement peut garder des valeurs fantômes entre retry/annulation.
- POS idempotency key basée localStorage peut collisionner si reset/même compte/même jour.
- Parked branch filter commenté dans W0 cross-check doit être clarifié avant W1.

**Flags** :
- P0 discount reason + route POS collect cash + payment props gate.
- P1 POS tests void/cash drawer/NFC/parked.

### G — KDS list/items/sync/status/bump/stations/allergens/limit 50/RTL

**Attendu** : KDS liste commandes ACCEPT/PREPARING/PREPARED par branche, autorise seulement les transitions cuisine, garde concurrence, affiche lignes compréhensibles, sync par Echo/poll fallback.

**Chemin réel** :
- `KitchenDisplaySystemOrderService::list` filtre branch exact et status.
- `changeStatus` lock row, compare stale, délègue `OrderStateMachine`.
- KDS front utilise Echo, polling, store `kds` pour bump local.
- `orderItems()` agrège séparément les lignes.

**Coins cachés** :
- Global `OrderStateMachine` autorise cancel et POS delivered; ce n'est pas une whitelist de surface KDS.
- Bump localStorage diverge entre deux écrans cuisine.
- `list` et `orderItems` n'ont pas exactement le même périmètre de statuts; c'est cohérent fonctionnellement mais non évident.
- Limit 50 peut cacher des commandes en rush.
- Admin branch 0 a une fraîcheur moindre.
- RTL Swiper hardcodé LTR et Bengali KDS non traduit.

**Flags** :
- P0 whitelist KDS + `OrderStatusRequest` surface.
- P0/P1 décision bump serveur ou single KDS.
- P1 limit 50/cap alert; tests transitions/stations/concurrency.

### H — OSS / Order Status Screen

**Attendu** : OSS est lecture seule, public, scoped à une branche, affiche queue PREPARING/PREPARED.

**Chemin réel** :
- Routes admin OSS sont GET-only.
- `OrderStatusScreenOrderService` filtre branch si user branch >0, sinon admin 0 voit tout.

**Coins cachés** :
- Une session admin 0 sur écran public peut afficher toutes les branches si pas de branche active.
- OSS popular items et queue partagent surfaces de lecture qui peuvent révéler agrégats cross-branch.

**Flags** :
- P1 exiger branch pin pour OSS admin/global.
- Garder no mutation route invariant.

### I — Order lifecycle, payment status, idempotence, cashback

**Attendu** : transition par `OrderStateMachine`, reason obligatoire pour terminal, no-op sans side effects, paiements avec machine dédiée, cashback/refund idempotent.

**Chemin réel** :
- `OrderStateMachine::allows` et `apply` existent et sont bien testés.
- Chemins historiques `OrderService::changeStatus` et `FrontendOrderService::changeStatus` ont leurs propres side effects.
- `changePaymentStatus` pose un entier, audit log, sans payment state machine.
- `PaymentService::cashBack` crédite balance et crée transaction.

**Coins cachés** :
- La machine d'état autorise identity no-op; si le service déclenche cashback avant de constater le no-op, l'idempotence de la machine ne protège pas les effets.
- `PaymentStatusRequest` autorise Admin/BM/POS Operator sur tout numeric payment_status.
- Status/payment après Z closed est plus sensible que destroy.

**Flags** :
- P0 no-op guards et cashback idempotency.
- P1 payment state machine.
- P0 fiscal si NF525 complet inclus.

### J — Availability stock, 86, release, cancel/refund, menu cache

**Attendu** : commande décrémente disponibilité par branche; cancel/refund restaure exactement ce qui doit l'être; events after-commit mettent à jour POS/Kiosk/KDS.

**Chemin réel** :
- `AvailabilityService` gère disponibilité branch, max daily, auto-86.
- `DecrementItemAvailabilityOnOrder` existe.
- Plan v2 demande release cancel/refund idempotent et payload global complet.

**Coins cachés** :
- Sans release, un item remboursé reste indisponible jusqu'au reset.
- Partial refund est plus risqué que full cancel : qty line-level nécessaire.
- Global item availability events doivent fan-out par branches ou payload défensif; sinon les fronts prunent à tort ou ne reçoivent rien.

**Flags** :
- P0 release idempotent.
- P0 ItemAvailabilityChanged payload/dedupe/fan-out.

### K — Outbox, events, Echo, event contract, reconnect, observability

**Attendu** : événements persistés après commit, validés par EventContract, dispatch hors transaction, dedupe/correlation côté front, recovery réseau explicite.

**Chemin réel** :
- `OrderCreated`/`OrderStatusChanged` utilisent after commit.
- `Persist*ToOutbox` crée `DomainEvent` puis `DispatchDomainEventsJob`.
- `EventContract.php` exige `branch_id` et `correlation_id`.
- Front `eventContract.js` est plus tolérant.

**Coins cachés** :
- `oldStatus===newStatus` peut générer outbox/notifs si les services dispatchent malgré no-op.
- `dispatched_at` est utilisé comme claim; reset exception existe, mais monitoring `claimed_at` serait plus précis.
- Echo auth expiry peut rendre la surface zombie sans bannière.
- Dedupe LRU front peut être trop petite en burst service.
- `sync_metrics` sans purge croît sans borne.

**Flags** :
- P1 Echo auth/reconnect.
- P2 front contract strictness, identity flood, metrics purge, DLQ/observability.

### L — Fiscal NF525, Z, audit logs, sealed immutability

**Attendu** : fiscal sequence, audit HMAC, Z close signed, immutabilité après clôture.

**Chemin réel** :
- `ZReportService` ouvre/ferme et signe.
- `AuditLogService` append-only HMAC.
- `OrderService::destroy` bloque ordre scellé.
- `changeStatus`/`changePaymentStatus` ne sont pas au même niveau de garde post-Z dans les preuves lues.

**Coins cachés** :
- Une V1 opérationnelle peut sortir sans NF525 complet si gate humain le décide; une V1 fiscale ne peut pas.
- Payment toggles après Z changent le sens financier même si destroy est bloqué.

**Flags** :
- Gate humain : inclure ou exclure sealed-Z status/payment de V1.

### M — Frontend UX, a11y, i18n, perf POS v4/Kiosk

**Attendu** : surfaces opérateur utilisables en vrai shift, keyboard/focus correct, RTL/i18n cohérent, perf mesurée.

**Chemin réel** :
- Kiosk a beaucoup de tests JS a11y/UX.
- POS/KDS master review trouve focus trap absent, RTL KDS hardcodé, Bengali KDS vide.
- W0 cross-check trouve bundle baseline manquant, ADR couleur absent, binding map trop faible.

**Coins cachés** :
- Un backend correct ne suffit pas si la cuisine lit mal les instructions/allergènes.
- Price display wrong currency est une rupture de confiance même si backend calcule juste.

**Flags** :
- P1 focus trap, KDS RTL/bn, KPI/bundle baseline, binding map threshold.

### N — Tests, CI, E2E, performance

**Attendu** : sentinelles P0 d'abord, puis full test suite, then E2E flows multi-device.

**Couverture existante utile** :
- `OrderStateMachineTest`, `OrderStateMachineApplyTest`
- `PricingServiceTest`, `PricingIntegrityTest`, `PosPricingSsotProofTest`
- `KioskPaymentStateMachineTest`, `KioskPhase1/KioskEndpointsTest`
- `Admin/KdsSyncControllerTest`, `KdsChangeStatusConcurrencyTest`, `KdsBranchFilterExactTest`
- `OutboxTest`, `EventContractTest`
- `Fiscal/*`, `Availability/*`, `BranchIsolationTest`, `OSSReadOnlyTest`

**Gaps** :
- payment-confirm non-kiosk.
- KDS Chef -> CANCELED/DELIVERED forbidden.
- Cross-branch show/export/sales-report/transaction.
- Double cashback no-op.
- Preview vs checkout promo.
- Cleanup stale vs delayed payment-confirm.
- POS void/cash drawer/NFC/parked resume.
- KDS 2 screens bump, limit 50, station routing.

### O — Memory, orchestration, Graphiti, multi-agent

**Attendu** : décisions durables en JSONL/Graphiti après audit/gate, rapports comme preuve, code comme vérité.

**Chemin réel** :
- `MEMORY_MATRIX.md` interdit les stores sauvages.
- Ce rapport est un store D (`reports/audit`), pas une décision durable tant qu'un humain ne l'adopte pas.

**Action** :
- Après adoption humaine, écrire un épisode court `memory/episodes/12_decisions_log.jsonl` résumant les P0 retenus et verdict, puis `scripts/after-execute-memory.sh`.

## 4. Findings Consolidés P0 / P1 / P2

| ID | Prio | Finding | Preuve | Blast radius | Correction | Test avant patch | Test après patch | Gate/frozen | Owner |
|---|---|---|---|---|---|---|---|---|---|
| F-P0-01 | P0 | `payment-confirm` sans garde borne | `routes/api.php:889-895`, `OrderController.php:77-115` | Paiement borne artificiel par client Sanctum propriétaire | Ability middleware + resolver KioskMachine + guard method/status/branch | non-kiosk owner -> 200 probable | non-kiosk -> 403, kiosk matched -> 200 | Request/controller sensible | codex-extension |
| F-P0-02 | P0 | KDS transitions trop larges | `OrderStateMachine`, `KitchenDisplaySystemOrderService`, `OrderStatusRequest` | Chef peut cancel/deliver selon permissions globales | Whitelist KDS service + request dédiée | Chef PREPARING->CANCELED | 422 forbidden | OrderStatus frozen | codex-extension + gate |
| F-P0-03 | P0 | `OrderStatusRequest` non surface-aware | rôles Admin/BM/Chef/POS/Cashier sur numeric status | Tous endpoints status partagent politique trop large | Requests dédiées route group | matrix actuelle | matrix route/status/role | Frozen request | codex-extension |
| F-P0-04 | P0 | `OrderService::list` branch LIKE | `OrderService.php:151` + POS/online/table/sales/export | Data leak + exports/PDF + agrégats faux | `=` strict + actor branch default | branch 1 vs 10 | 7 surfaces no leak | OrderService frozen | codex-extension + gate |
| F-P0-05 | P0 | `OrderService::show($auth=false)` sans branch guard | `OrderService.php:1330-1346` | Accès direct ID cross-branch | Guard branch actor | show cross branch | 403/404 | OrderService frozen | codex-extension + gate |
| F-P0-06 | P0 | `kiosk_promo_code` preview != checkout | Preview service vs `PricingRequest::forKiosk` | Prix affiché différent du prix final | Support bout-en-bout ou retirer | preview total != checkout | equality ou 422 | Pricing/OrderRequest | codex-extension |
| F-P0-07 | P0 | No-op status/payment side effects | `changeStatus`, `PaymentService::cashBack` | Double cashback/refund/balance | Early no-op return + idempotent transaction_no | double cancel | one cashback | OrderService/PaymentService frozen | codex-extension + gate |
| F-P0-08 | P0 | OS/FOS symmetry non prouvée | `OrderService`/`FrontendOrderService` divergences | POS/kiosk prix/idempotency divergent | Rapport symétrie + tests miroir | no evidence | parity suite | SYMMETRY_NOTE/gate | Claude plan + codex |
| F-P0-09 | P0 | POS cash utilise KDS endpoint | `PosComponent.vue:1414-1421` | Whitelist KDS casse POS collect cash | Route POS collect dédiée | grep endpoint | POS route works, KDS strict | routes/service | codex-extension |
| F-P0-10 | P0 | Cleanup stale vs payment-confirm | `CleanupStalePendingKioskOrders` | TPE encaissé + order rejected | Reconciliation/refusal logged | delayed confirm after cleanup | refused + audit | lifecycle/payment | codex-extension |
| F-P0-11 | P0 | POS discount reason not bound | master review FIND-01 | Discounts bloqués | `v-model=discountReason` | Vitest failing | Vitest pass | none | routine |
| F-P0-12 | P0 | PaymentComponent prop mutation | `PaymentComponent.vue` 16+ sites, gate pending | Paiement state fantôme | Option A emit or B local copy | current direct mutation | zero prop mutation | GATE_PAYMENT_PROP_MUTATION | codex-extension |
| F-P0-13 | P0 | Stock release cancel/refund | audit massif F-01 | Auto-86 reste bloqué, refund incohérent | release idempotent line qty | cancel no release | full/partial idempotent | availability lifecycle | codex-extension |
| F-P0-14 | P0 | ItemAvailabilityChanged payload/fan-out | plan v2 F-04bis | Front prune à tort / pas d'event | complete payload + branch fan-out/dedupe | global event shape | N branches N events | event contract | codex-extension |
| F-P0-15 | P0/P1 | KDS bump localStorage multi-screen | audit KDS §3.5 | Cuisine multi-écrans divergente | Serveur bump ou policy single screen | 2 tabs diverge | server sync or documented | product decision | human + codex |
| F-P1-01 | P1 | `TransactionService::list` branch optionnel | `TransactionService.php:23-66` | financial cross-branch reports | actor branch default | staff no branch param | scoped | service | routine/codex |
| F-P1-02 | P1 | OSS admin 0 all branches | `OrderStatusScreenOrderService` | public screen wrong branch | branch pin/header | admin OSS | explicit branch | product | routine |
| F-P1-03 | P1 | PaymentStatus no machine | `changePaymentStatus` direct | PAID/UNPAID toggle | mini state machine | invalid toggles | 422 | fiscal gate maybe | codex |
| F-P1-04 | P1 | TPE accepted, backend fail no recovery | KioskPaymentComponent retry throws | support manual gap | staff retry/reconciliation | accepted no backend | retry works | product | codex |
| F-P1-05 | P1 | POS idempotency localStorage collision | audit massif F-05 | commande fantôme | crypto random component | reset seq collision | no collision | none | routine |
| F-P1-06 | P1 | Floorplan transfer no event | audit massif F-02/plan 1.D | KDS stale table | `OrderTableChanged` after commit | transfer no event | KDS refresh | event contract | codex |
| F-P1-07 | P1 | Echo auth expiration | audit massif F-12/plan 1.E | zombie sync | subscription_error + refresh/banner | token expired | session_invalid UI | auth/sync | codex |
| F-P1-08 | P1 | KDS polling fallback/version gate | plan 1.C | stale/lost events | adaptive polling + version | disconnect | no regression | frontend sync | codex |
| F-P1-09 | P1 | KDS limit 50 invisible | audit KDS §3.1 | hidden orders in rush | cap warning/pagination | >50 orders | visible warning | product | routine |
| F-P1-10 | P1 | KDS tests missing | master plan LOT-7 | status/stations not protected | add feature tests | no files | 3 tests pass | post gate | routine |
| F-P1-11 | P1 | POS tests missing | master plan LOT-3 | void/NFC/drawer regressions | add feature tests | missing | tests pass | none | routine |
| F-P1-12 | P1 | Kiosk price display hardcoded | `kioskFormatPrice.js` | wrong currency display | inject locale/currency | default EUR | branch currency | none | routine |
| F-P1-13 | P1 | Focus trap absent | master review FIND-06 | keyboard a11y fail | focus trap modal | axe/focus fail | pass | none | routine |
| F-P2-01 | P2 | `loyalty/scan` route lacks ability middleware | route/controller mismatch | defense-in-depth | add middleware | route no ability | 403 | none | routine |
| F-P2-02 | P2 | Outbox identity flood | Persist status outbox | noisy sync | guard old==new | identity dispatch | no event | outbox | routine |
| F-P2-03 | P2 | Front EventContract too lax | `eventContract.js` | dedupe/branch weak | strict required keys | missing correlation accepted | rejected | frontend sync | routine |
| F-P2-04 | P2 | sync_metrics no purge | master FIND-10 | DB growth | purge job | table grows | retention | none | routine |
| F-P2-05 | P2 | parked `expires_at` absent | master FIND-11 | stale parked | schema + purge | no TTL | TTL | schema gate | routine+gate |
| F-P2-06 | P2 | KDS RTL/Bengali | master FIND-05/09 | operator usability | dynamic dir + translations | i18n audit | pass | none | routine |
| F-P2-07 | P2 | Receipt privacy TTL | audit massif F-19 | previous client receipt leak | TTL/purge | persisted receipt | purged | privacy | routine |

## 5. Dépendances et Ordre d'Exécution

```text
HUMAN GATES
  -> G1 frozen consolidated (OrderService/PaymentService/Pricing/Routes)
  -> G2 PaymentComponent prop mutation option
  -> G3 NF525 scope: V1 op vs V1 fiscal
  -> G4 schema parked expires_at
  -> G5 product KDS bump multi-screen

QUICK WINS WITHOUT GATE
  -> POS discountReason
  -> KDS RTL/Bengali
  -> kioskFormatPrice display
  -> focus trap
  -> loyalty/scan middleware
  -> outbox identity guard if no frozen conflict

P0 BACKEND SENTINELS FIRST
  -> write failing tests for payment-confirm, branch leaks, KDS forbidden transitions, double cashback, promo parity, cleanup race
  -> only then patch

P0 BACKEND PATCHES
  -> payment-confirm hardening
  -> branch exact list/show/export/report/transaction
  -> status request surface policy + KDS whitelist
  -> no-op cashback/payment guards
  -> promo checkout decision
  -> stock release + item availability event payload

P0/P1 FRONT PATCHES
  -> POS collect-cash route migration after KDS whitelist
  -> PaymentComponent refactor after gate
  -> KDS bump decision
  -> Echo auth/polling/version gates

VALIDATE
  -> focused PHP Feature suites
  -> Vitest focused POS/KDS/Kiosk suites
  -> full `npm run verify:boucle`
  -> Claude terminal audit after login restored
```

**Routing** :
- `codex-extension`: P0 backend, lifecycle, pricing, sync/outbox, payment, branch leaks, KDS whitelist, stock release.
- `foodking-routine-implementer`: quick UI/i18n/a11y tests, middleware small route, Vitest/Feature test additions when no product code frozen.
- `human gate`: frozen consolidated, payment props mutation, schema migrations, NF525 scope, KDS bump product policy.
- `claude-terminal`: final audit after each lot once CLI login is restored.

## 6. Plan d'Exécution V1 Détaillé

| Lot | TASK_ID proposé | Scope | Fichiers write probables | Off-limits | Tests | Succès |
|---|---|---|---|---|---|---|
| LOT-0 | `V1_DEEP_GATES_2026-04-25` | Décisions humaines gates | `docs/gates/GATE_LOG.md` seulement après humain | code produit | aucun | gates status clairs |
| LOT-1 | `V1_SENTINELS_P0_2026-04-25` | Tests failing P0 avant patch | `tests/Feature/V1P0/*` | code produit | payment-confirm, KDS, branch, cashback, promo, cleanup | tests rouges ciblés |
| LOT-2 | `V1_AUTH_PAYMENT_CONFIRM_2026-04-25` | Hardening borne payment confirm | routes, frontend order controller/service | pricing/fiscal hors scope | KioskPayment + new auth tests | non-kiosk 403 |
| LOT-3 | `V1_BRANCH_EXACTNESS_2026-04-25` | list/show/export/report/transaction branch | `OrderService`, `TransactionService`, controllers if needed | unrelated filters | cross-branch 7 surfaces | zero leak |
| LOT-4 | `V1_STATUS_SURFACE_POLICY_2026-04-25` | OrderStatusRequest/KDS whitelist/POS collect route | requests, KDS service, POS route/service, PosComponent | pricing | role x route matrix | KDS strict, POS works |
| LOT-5 | `V1_PRICING_PROMO_SYMMETRY_2026-04-25` | promo preview/checkout + OS/FOS symmetry | PricingRequest, FrontendOrderService, tests | manual POS discount unless scoped | preview==checkout | SYMMETRY_NOTE |
| LOT-6 | `V1_IDEMPOTENCE_CASHBACK_2026-04-25` | no-op status/payment/cashback | OrderService, PaymentService | Z fiscal full if not gated | double cancel/payment no-op | one side effect |
| LOT-7 | `V1_AVAILABILITY_RELEASE_EVENTS_2026-04-25` | release cancel/refund + ItemAvailabilityChanged | AvailabilityService, events/listeners/outbox | pricing | full/partial/idempotent | exact daily qty |
| LOT-8 | `V1_SYNC_ECHO_KDS_RECOVERY_2026-04-25` | Echo auth, polling fallback, table event | WebSocket/KDS/DiningTable/event contract | order pricing | WS expiry, transfer, stale poll | no zombie sync |
| LOT-9 | `V1_POS_KDS_QUICKWINS_2026-04-25` | discountReason, RTL, bn, focus trap, format price | Vue/i18n/helpers/tests | backend | Vitest targeted | UI usable |
| LOT-10 | `V1_TEST_COVERAGE_POS_KDS_2026-04-25` | POS/KDS Feature coverage | tests only unless SCOPE_PRESSURE | product code | void/drawer/NFC/parked/KDS transitions | tests green |
| LOT-11 | `V1_FISCAL_SCOPE_DECISION_2026-04-25` | decide NF525 sealed status/payment | gates + maybe OrderService | no patch without gate | fiscal suite | explicit V1 op/fiscal |
| LOT-12 | `V1_FINAL_VALIDATE_AUDIT_2026-04-25` | full validate + Claude terminal audit | reports only | code | verify, php, vitest, e2e selected | `AUDIT_VERDICT: PASS` |

**PRIOR_CONTEXT à coller dans un futur `PLAN_*`** :

```text
PRIOR_CONTEXT_MASTER_DEEP_2026-04-25
Verdict: MASTER_DEEP_VERDICT NEEDS_HUMAN_GATES.
V1 op = POS cash/card + Borne TPE/cash + KDS PREPARING/PREPARED + OSS + branch isolation + outbox/Echo.
Non-negotiables: backend pricing SSOT, OrderStatus enum, branch_id exact, after-commit events, frozen gates, OS/FOS symmetry.
P0 execution order:
1 gates frozen/payment/NF525/KDS bump
2 tests sentinelles failing
3 payment-confirm ability + KioskMachine guard
4 branch exact list/show/export/report/transaction
5 KDS whitelist + OrderStatusRequest per surface + POS collect route
6 kiosk promo preview/checkout decision + OS/FOS symmetry
7 no-op cashback/payment idempotence
8 cleanup stale vs delayed payment-confirm reconciliation
9 stock release cancel/refund + ItemAvailabilityChanged payload/fan-out
10 final validate + Claude terminal audit after CLI login restored
Do not patch product code from this report directly; open bounded run-cycle with TASK_ID.
```

## 7. Couverture de Tests et Commandes Recommandées

| Commande / test | Couvre | Ne couvre pas | Prio |
|---|---|---|---|
| `php artisan test tests/Unit/Domain/Order/OrderStateMachineTest.php` | Matrice globale statuses | Surface KDS/POS-specific | P1 |
| `php artisan test tests/Feature/Domain/OrderStateMachineApplyTest.php` | apply transaction/no-op/audit | `OrderService::changeStatus` side effects | P1 |
| `php artisan test tests/Feature/KioskPaymentStateMachineTest.php` | Kiosk paid/pending/finalize | non-kiosk payment-confirm, delayed cleanup | P0 add |
| `php artisan test tests/Feature/KioskPhase1/KioskEndpointsTest.php` | kiosk ability endpoints | loyalty/scan route middleware | P2 add |
| `php artisan test tests/Feature/Admin/KdsSyncControllerTest.php` | KDS sync/poll basics | KDS forbidden transitions | P0 add |
| `php artisan test tests/Feature/KdsChangeStatusConcurrencyTest.php` | stale 409 | Chef cancel/delivered whitelist | P0 add |
| `php artisan test tests/Feature/KdsBranchFilterExactTest.php` | KDS exact branch filter | OrderService list/show leaks | P0 add |
| `php artisan test tests/Feature/OutboxTest.php` | persist/claim/retry | identity old==new | P2 add |
| `php artisan test tests/Feature/EventContractTest.php` | backend envelope strict | frontend contract strictness | P2 add |
| `php artisan test tests/Feature/Orders/CleanupStalePendingOrdersTest.php` | cleanup stale | delayed payment-confirm race | P0 add |
| `php artisan test tests/Feature/PricingIntegrityTest.php tests/Feature/PosPricingSsotProofTest.php` | backend pricing SSOT | kiosk promo preview/checkout parity | P0 add |
| `npx vitest run tests/js/kioskPricingPreview.spec.js tests/js/kioskCartPromo.spec.js` | preview/cart promo UI | backend checkout consumption | P0 add |
| `npx vitest run tests/js/kds*.spec.js` | KDS frontend helpers/sync | two physical screens unless E2E | P1 |
| `npx vitest run tests/js/pos*.spec.js` | POS helpers/components | backend POS feature paths | P1 |
| `npm run verify:boucle` | orchestration/preflight | business P0 specifics | always |
| `php artisan test` | full backend regression | frontend/e2e | final |
| `npx vitest run` | full JS regression | Laravel feature | final |
| `npx playwright test tests/e2e/02-pos-cash.spec.js tests/e2e/03-kiosk-wizard.spec.js tests/e2e/04-kds-status.spec.js` | smoke E2E surfaces | payment-confirm race/multi-device | final |

**Tests à créer en priorité P0** :
- `PaymentConfirmAbilityTest`: non-kiosk Sanctum owner -> 403; kiosk matched -> 200; branch mismatch -> 403.
- `KdsSurfaceTransitionPolicyTest`: Chef can ACCEPT->PREPARING->PREPARED only; CANCELED/DELIVERED/RETURNED -> 422/403.
- `OrderBranchExactnessTest`: POS/online/table index/show/export, sales report index/export/pdf, transaction no leak.
- `OrderStatusNoopSideEffectsTest`: double cancel/return/payment no-op creates one audit/cashback/outbox max.
- `KioskPromoCheckoutParityTest`: preview total equals persisted checkout total or store rejects promo.
- `CleanupPaymentConfirmRaceTest`: stale rejected order cannot later become paid without reconciliation.

## 8. Zones Frozen / Gates / Décisions Humaines

| Zone | Gate | Pourquoi | Déblocage |
|---|---|---|---|
| `app/Services/OrderService.php` changeStatus/changePaymentStatus/list/show | GATE_VERIFY_P0_FROZEN_CONSOLIDATED | branch, fiscal, cashback, lifecycle | TL+BE+QA approve |
| `app/Services/PaymentService.php::cashBack` | même gate | argent + balance + transaction | approve + idempotency test |
| `app/Domain/Order/OrderStateMachine.php` | frozen lifecycle | tous les statuts | avoid if service whitelist enough |
| `app/Services/Pricing/*`, `PricingRequest.php` | pricing SSOT | totals/taxes/discounts | SYMMETRY_NOTE + tests |
| `FrontendOrderService.php` | frozen order lifecycle | kiosk checkout/payment | gate if patch deep |
| `routes/api.php` status/payment/frontend sensitive routes | route auth/business surface | access control | plan exact + tests |
| `PaymentComponent.vue` | `GATE_PAYMENT_PROP_MUTATION_2026-04-26` | payment state/refactor 16+ mutations | choose Option A/B |
| migrations `pos_parked_orders.expires_at` | schema gate | DB migration | gate schema approved |
| NF525 status/payment post-Z | fiscal human gate | V1 op vs V1 fiscal | explicit scope decision |
| KDS bump server vs single screen | product gate | kitchen operations policy | product+ops decision |
| POS v4 pricing allowed block/signoff | TL+BE signoff before deadline | frontend pricing display exception | signoff or remove |

## 9. Risques Résiduels Après P0

| Risque | Pourquoi il reste | Mitigation |
|---|---|---|
| Admin `branch_id=0` global privileges | Design SaaS nécessaire pour super-admin | branch pin pour surfaces publiques, audit tokens |
| KDS cognitive errors | Backend sync correct ne garantit pas lisibilité cuisine | design KDS instructions/allergens/variations |
| Offline queue edge cases | Réseau/TPE/store-and-forward complexe | telemetry + manual reconciliation |
| Event storms / dedupe cap | Service réel peut dépasser tests unitaires | load tests + metrics + DLQ |
| Fiscal V1 vs op V1 ambiguity | Scope humain non tranché | gate NF525 avant lancement fiscal |
| Multi-agent governance | Claude terminal indisponible dans cette session | relogin CLI + verify smoke + cross-audit |
| Performance POS v4 | baseline gzip non mesurée dans cross-check | `npm run build` + budget |

## 10. Annexes Auditables

### Index chemins clés par domaine

| Domaine | Chemins |
|---|---|
| Routes | `routes/api.php`, `routes/web.php`, `routes/channels.php` |
| Auth kiosk | `app/Http/Controllers/Auth/KioskMachineLoginController.php`, `app/Http/Middleware/ValidateKioskLocale.php` |
| Front order | `app/Http/Controllers/Frontend/OrderController.php`, `app/Services/FrontendOrderService.php`, `app/Http/Requests/OrderRequest.php` |
| Pricing | `app/Services/Pricing/PricingService.php`, `PricingRequest.php`, `DiscountCalculator.php`, `resources/js/helpers/kioskPricingPreview.js`, `kioskFormatPrice.js` |
| Promo/loyalty | `app/Services/Kiosk/PricingPreviewService.php`, `KioskPromoService.php`, `CouponService.php`, `Frontend/LoyaltyController.php` |
| Admin orders | `PosOrderController.php`, `OnlineOrderController.php`, `TableOrderController.php`, `SalesReportController.php`, `OrderService.php`, `OrderExport.php`, `SalesReportExport.php` |
| Transactions | `app/Http/Controllers/Admin/TransactionController.php`, `app/Services/TransactionService.php`, `app/Exports/TransactionExport.php` |
| KDS | `KitchenDisplaySystemController.php`, `KitchenDisplaySystemOrderService.php`, `KdsSyncController.php`, `KitchenDisplaySystemComponent.vue`, `kitchenDisplaySystemOrder.js`, `kds.js` |
| OSS | `OrderStatusScreenController.php`, `OrderStatusScreenOrderService.php`, `orderStatusScreenOrder.js` |
| Lifecycle | `OrderStateMachine.php`, `ValidStatusTransition.php`, `OrderStatusRequest.php`, `PaymentStatusRequest.php`, `PaymentService.php` |
| Availability | `AvailabilityService.php`, `DecrementItemAvailabilityOnOrder.php`, `ReleaseAvailabilityOnOrderCanceled.php`, `ReleaseAvailabilityOnRefundCreated.php`, `ItemAvailabilityChanged.php` |
| Outbox/sync | `PersistOrderCreatedToOutbox.php`, `PersistOrderStatusChangedToOutbox.php`, `PersistItemAvailabilityChangedToOutbox.php`, `DispatchDomainEventsJob.php`, `EventContract.php`, `eventContract.js`, `WebSocketService.js`, `KdsSyncService.js` |
| Fiscal | `ZReportService.php`, `AuditLogService.php`, `ZReportController.php`, fiscal tests |
| POS frontend | `PosComponent.vue`, `PaymentComponent.vue`, `ParkedOrdersComponent.vue`, `FloorplanComponent.vue`, POS store/modules/tests |
| Kiosk frontend | `KioskPaymentComponent.vue`, `KioskCartComponent.vue`, `kioskCart.js`, `kioskOfflineQueue.js`, `kioskReceiptPersistence.js` |

### Faux positifs / risques abaissés

| Sujet | Décision |
|---|---|
| KDS branch LIKE | Faux positif pour KDS : KDS service utilise exact branch filter. Le LIKE P0 reste dans `OrderService::list`. |
| Kiosk navigue après échec payment-confirm | Faux positif : le composant attend/throw; le risque réel est absence de reprise opérateur. |
| Outbox complètement cassé | Abaissé : outbox persist/claim/retry testé; risques restants = identity flood, monitoring, burst. |
| DB idempotency branch-scope | Abaissé : index composite existe/testé; catch POS/admin peut rester à vérifier. |
| Variation quantity preview | P2 UX/pricing preview, pas corruption checkout serveur si backend SSOT applique quantité. |
| OSS mutation | Abaissé : routes OSS read-only; risque restant = branch admin 0. |

### Glossaire rapide

| Terme | Sens |
|---|---|
| V1 op | Version exploitable restaurant sans prétendre couvrir tout NF525 post-Z |
| V1 fiscale | Version où toute mutation financière/statut après Z close est strictement verrouillée |
| KDS | Kitchen Display System, surface cuisine |
| OSS | Order Status Screen, écran client passif |
| SSOT prix | backend seul calcule le prix final |
| No-op identity | transition `from === to`, qui ne doit produire aucun effet métier |
| Outbox | table `domain_events` + job dispatch après commit |
| Frozen zone | fichier/zone dont la modification exige gate humain |
| Branch pin | sélection explicite de branche active pour admin global |

MASTER_DEEP_VERDICT: NEEDS_HUMAN_GATES
