Now I have sufficient context. Producing the consolidated deep single report below.

# RAPPORT FINAL DEEP SINGLE — FoodKing V1 — 2026-04-25

## 1) Résumé exécutable

V1 opérationnelle minimale (POS cash/card · Borne TPE · KDS PREPARING/PREPARED · branch isolation · outbox/Echo) reste bloquée par les 7 P0 du rapport consolidé, avec **3 amplifications hidden-path qui élargissent fortement le blast radius** et **1 P0 supplémentaire indirect** non exposé jusqu’ici. Le LIKE `OrderService::list` n’est pas un seul P0 isolé : il est consommé par 7 surfaces admin/export simultanément (pos-order, online-order, table-order, sales-report index/export/pdf, transaction-via-relation), donc le correctif est ponctuel mais l'évidence doit couvrir chaque surface. Le `payment-confirm` borne reste critique : route `auth:sanctum` + ownership user uniquement, **aucun `abilities:kiosk:order`** alors que le précédent existe sur `/kiosk-event` (api.php:986,1032) — **drift de discipline**. La cleanup stale 15 min auto-rejette PENDING borne sans coordination avec un éventuel `payment-confirm` retardé : interaction silencieuse avec offline queue. `PersistOrderStatusChangedToOutbox` n’a **aucun garde-fou identité** : un `oldStatus===newStatus` génère bruit outbox + Echo + notifications. POS cash via `admin/kds-order/change-status` (DELIVERED) devient **P0 hard** dès que la whitelist KDS est appliquée. NF525 sealed-Z = scope humain, hors V1 op. NEEDS_EVIDENCE prédominant : 4 tests HTTP/feature manquants pour basculer le verdict en READY_TO_PLAN.

## 2) Carte des chemins cachés / indirects

| Surface (route ou trigger) | Fichier(s) clé | Service / Code partagé | Invariant exposé | Couverture actuelle |
|---|---|---|---|---|
| `GET admin/pos-order` (`api.php:662`) | `PosOrderController.php:43` | `OrderService::list` (LIKE branch_id `:151`) | branch isolation | aucune (P0) |
| `GET admin/pos-order/show/{order}` (`:663`) | `PosOrderController.php:53` | `OrderService::show($auth=false)` (`:1330-1346` retourne sans contrôle) | branch isolation | aucune (P0 implicite) |
| `GET admin/pos-order/export` (`:665`) | `PosOrderController.php:78` → `OrderExport.php:27` | `OrderService::list` | branch isolation | aucune |
| `GET admin/online-order` + `/show` + `/export` + `/pdf` (`:677-681`) | `OnlineOrderController.php:48,57,66,77` | `OrderService::list` + `show` | branch isolation | aucune |
| `GET admin/table-order` + `/show` + `/export` (`:688-691`) | `TableOrderController.php:39,48,57` | idem | branch isolation | aucune |
| `GET admin/sales-report` index/export/pdf (`:753-755`) | `SalesReportController.php:43,52,64` → `SalesReportExport.php:27` | `OrderService::list` | branch isolation, fiscal aggregat | aucune |
| `GET admin/transaction` (`:785`) | `TransactionService::list:23-66` | `branch_id` filtre **optionnel via `isset($requests['branch_id'])`** | branch isolation | aucune |
| `POST admin/{pos,online,table}-order/change-status/{order}` | `*OrderController::changeStatus` → `OrderService::changeStatus($auth=false)` (`:1545-1648`) | `OrderStatusRequest::authorize` Chef/POS/Cashier indistinct (`:23-31`) ; cashback/refund avant save (`:1567-1574`) sans no-op guard | RBAC par surface, no-op idempotence | aucune (P0) |
| `POST admin/{pos,online,table}-order/change-payment-status/{order}` | `*OrderController::changePaymentStatus` → `OrderService::changePaymentStatus` (`:1672-1714`) | `PaymentStatusRequest::authorize` Admin/BM/POS Operator (`:19`) ; **aucune state machine paiement**, **aucun no-op guard** | NF525 fiscal, idempotence | aucune (P1) |
| `POST admin/kds-order/change-status/{order}` (`:811`) | `KitchenDisplaySystemController::changeStatus` → `KitchenDisplaySystemOrderService::changeStatus:117-188` | `OrderStateMachine::allows` autorise `ACCEPT/PREPARING→DELIVERED` si user a `pos` permission (`OrderStateMachine.php:38-39,45-46`), `*→CANCELED` (`:42,49`) | KDS whitelist surface | partielle (concurrency 409 testé) |
| `POST admin/kds-order/change-status` utilisé par **POS** | `PosComponent.vue:1414-1421` (`collectKioskCashOrder`) | endpoint KDS partagé pour DELIVERED | discipline route surface | aucune (P0 conditionnel si P0.2 livré) |
| `GET admin/oss-order` (`:825`) | `OrderStatusScreenOrderService::list:35-75` | `if ($userBranchId > 0)` else **toutes branches** (`:66-67`) | branch isolation pour admin `branch_id=0` | aucune |
| `POST frontend/order/{id}/payment-confirm` (`:895`) | `OrderController.php:77-115` | `auth:sanctum` + ownership user uniquement ; **pas** d'`abilities:kiosk:order`, pas de `KioskMachine` resolver, pas de check `payment_method∈{CARD,TR}`, pas de `status∈{PENDING,UNPAID}` | parcours TPE confidentiel | aucune (P0) |
| `POST frontend/order/change-status/{id}` (`:893`) | `FrontendOrderService::changeStatus:659-734` | `OrderStatusRequest` admet Admin/BM/Chef/POS/Cashier sur n'importe quel statut alors qu'ici on est sur `FrontendOrder` | RBAC par surface | aucune (P0 lié à OrderStatusRequest) |
| `POST frontend/order` checkout borne (`:892`) | `OrderRequest.php:35-68` accepte `kiosk_promo_code` (whitelist large) → `FrontendOrderService::myOrderStore:216-227` → `PricingRequest::forKiosk:90-107` **n'expose pas `kiosk_promo_code`** | preview ≠ checkout | aucune (P0) |
| `POST frontend/pricing/preview` (`:1003`) | `PricingPreviewRequest:38` exige item.quantity, `:42` accepte variation.quantity | `PricingPreviewService::toObject:146-162` **drop variation.quantity** alors que `PricingService:127-128,312` la consomme côté checkout | preview UX vs SSOT serveur | aucune (P2) |
| `POST frontend/loyalty/scan` (`:1025`) | route avec `auth:sanctum` seul, **sans** `abilities:kiosk:order` middleware | `LoyaltyController::scan:579` vérifie `tokenCan('kiosk:order')` au code uniquement | drift defense-in-depth | aucune (P2) |
| `Broadcast branch.{branchId}` | `routes/channels.php:25-39` | kiosk → KioskMachine.branch_id ; admin `branch_id=0` ⇒ retourne `true` pour toute branche (`:32-35`) | privilege admin global | acquis mais **aucun garde sur tokens admin volés** |
| Cleanup stale → `OrderStateMachine::apply` REJECTED (`CleanupStalePendingKioskOrders.php:42-47`) | + `OrderCanceled::dispatch` | **aucune coordination avec `payment-confirm` retardé** ; `withoutGlobalScope(BranchScope)` traverse toutes branches | race TPE accepté ↔ cleanup | aucune (P1 hidden) |
| Outbox identity flood | `PersistOrderStatusChangedToOutbox.php:15-41` | aucun garde `oldStatus===newStatus` | bruit outbox / Echo / notifs | aucune (P2) |
| `OrderRequest::authorize() == true` (`OrderRequest.php:23`) | toute requête frontend authentifiée crée commande | délégué à `Auth::id()` dans service ; `kiosk_promo_code` accepté mais ignoré | trust boundary | partielle |

## 3) Findings deep P0 / P1 / P2

| Priorité | Thème | Preuve code (chemin:lignes) | Risque concret | Preuve manquante | Action de plan |
|---|---|---|---|---|---|
| P0 | `payment-confirm` sans garde borne | `routes/api.php:889-895`, `OrderController.php:77-115` (auth:sanctum + ownership user) ; précédent `abilities:kiosk:order` existe `api.php:986,1032` | promotion artificielle `PAID` par tout client Sanctum propriétaire | test PHPUnit Sanctum non-kiosk → 403 | route + middleware ability + `KioskMachine` resolver + check `payment_method∈{CARD,TR}` + `status∈{PENDING/UNPAID}` |
| P0 | KDS whitelist transitions | `OrderStateMachine.php:42,49,60-67` ; `KitchenDisplaySystemOrderService.php:150` ; `OrderStatusRequest.php:23-31` | Chef peut envoyer `PREPARING→CANCELED` | feature test rôle Chef → 422 | whitelist par surface dans service KDS + couloir RBAC dans request |
| P0 | `OrderStatusRequest` policy par surface | `OrderStatusRequest.php:23-31` autorise Admin/BM/Chef/POS/Cashier sur tout statut numérique | KDS peut DELIVERED, POS peut PREPARING, etc. | matrice rôle×route×status | discriminer `authorize()` selon route group ou créer requests dédiées |
| P0 | LIKE branch_id `OrderService::list` | `OrderService.php:151` `'%' . escapeLike . '%'` ; consommé par `PosOrderController:43`, `OnlineOrderController:48,77`, `TableOrderController:39`, `SalesReportController:43,64` + `OrderExport:27`, `SalesReportExport:27` | branch=1 voit branch 10/11/12/100 ; export Excel/PDF leak ; sales-report agrégats faussés | tests cross-branch sur **chaque** surface (7 routes) | `=` strict + tests par surface + verrou actor.branch quand `branch_id=0` |
| P0 | `OrderService::show($auth=false)` retourne sans branche | `OrderService.php:1330-1346` ; appelé par `*OrderController::show($order, false)` (Pos/Online/Table) | accès direct par ID cross-branch si BranchScope contourné | test admin branch=2 GET show order branch=10 | check explicite branche actor vs `$order->branch_id` |
| P0 | Promo borne preview ≠ checkout | preview `PricingPreviewRequest.php:47` + `PricingPreviewService.php:66-97` ; payload `kioskCart.js:32` ; `OrderRequest.php:35-68` (pas de règle `kiosk_promo_code`) ; `FrontendOrderService.php:216-227` → `PricingRequest::forKiosk:90-107` (pas de paramètre promo) | total affiché ≠ facturé ; SSOT prix violé | test contractuel preview total === checkout total | trancher V1 : câbler bout-en-bout (params + consommation usage_count) **ou** retirer du payload |
| P0 | No-op identity side-effects financiers | `OrderService::changeStatus:1567-1574` (cashback/refund avant save sans `oldStatus===newStatus`) ; `OrderStateMachine::allows:29-31` (`$from === $to` retourne true) ; `PaymentService::cashBack:31-71` (crédite balance + crée transaction sans dédoublonnage par `transaction_no`) | retry staff = double cashback + double balance crédité | test double cancel/retry sur même order → un seul cashback | garde no-op au début de `changeStatus`/`changePaymentStatus` ; idempotency par `transaction_no` côté `cashBack` |
| P0 | Symétrie OrderService / FrontendOrderService | `PricingRequest::forPos:50-67` (manuel/coupon) vs `forKiosk:90-107` (sans manuel/promo) ; `OrderService::posOrderStore:1013-1018` catch idempotency non scopé branch vs `FrontendOrderService:616-620` scopé | écart promo / coupon manuel / idempotency | tableau diff exhaustif + tests miroir | livrable plan obligatoire pré-implémentation |
| P0 (cond.) | POS cash via endpoint KDS | `PosComponent.vue:1414-1421` `axios.post('admin/kds-order/change-status/...DELIVERED')` ; `OrderStateMachine.php:38-39,45-46` permet via `pos` permission | dès que P0.2 (whitelist KDS) est appliquée, le bouton encaissement POS casse | grep + intégration POS | route POS dédiée + service POS dédié pour DELIVERED |
| P1 | `expected_status` requis (couplé) | `KitchenDisplaySystemOrderService.php:122-148` lit `expectedFrom` depuis modèle bindé, pas du client | UX 2 onglets pas de 409 explicite | test 422 missing + 409 stale | request rule `expected_status` obligatoire |
| P1 | `TransactionService::list` branch optionnel | `TransactionService.php:33-36` filtre seulement si `isset($requests['branch_id'])` | admin `branch_id=0` peut requêter sans filtre, voit toutes branches | test admin staff `branch_id=2` GET transaction sans param | injection `branch_id` actor par défaut |
| P1 | OSS `branch_id=0` voit toutes branches | `OrderStatusScreenOrderService.php:38,66-68` | écran statut admin global au lieu de branche active | test rôle staff branch=2 vs admin | discrimer admin → exige header `X-Branch` |
| P1 | `changePaymentStatus` sans state machine | `OrderService.php:1672-1714` set `payment_status` directement, **aucune** validation transition `PaymentStatus` | `PAID→UNPAID→PAID` rebroadcast Z-impact | test PHPUnit transitions invalides → 422 | machine d'état paiement (mini) + AuditLog déjà OK |
| P1 | TPE accepted + payment-confirm fail | `KioskPaymentComponent.vue:447-454,562-577` confirmBackendPayment throw après 3 retries (faux positif R3 pour navigation) ; **mais aucune UI reprise opérateur** | TPE encaissé, commande backend toujours PENDING/UNPAID | Vitest état + Playwright reprise | endpoint `payment-confirm-retry` côté staff + UI |
| P1 | Catch idempotency POS non scopé branche | `OrderService.php:1013-1018` ; precheck `:580-586` scopé ; index DB composite OK | race admin `branch_id=0` même clé deux branches | test concurrent admin | scoper catch par `(branch_id, idempotency_key)` |
| P1 | Cleanup stale 15 min vs payment-confirm retardé | `CleanupStalePendingKioskOrders.php:19,42-47` REJECTED puis `payment-confirm` arrive | order REJECTED + TPE encaissé sans réconciliation ; offline queue + retry TPE = race | test simulant TPE retard >15 min | mini-saga : `payment-confirm` doit refuser si REJECTED + journaliser réconciliation |
| P1 | `FrontendOrderService::changeStatus` cashback no-op manquant | `:684-691` cashBack si transaction présente, pas de garde `oldStatus===newStatus` | côté borne (rare en V1) double cashback possible si retry | test double cancel kiosk | symétrie avec correctif OrderService |
| P2 | `loyalty/scan` route sans `abilities:kiosk:order` | `routes/api.php:1025-1027` (Sanctum + throttle uniquement) ; `LoyaltyController::scan:579` check au code | drift discipline ; defense-in-depth absente | grep route | ajouter `abilities:kiosk:order` au middleware |
| P2 | Outbox identity flood | `PersistOrderStatusChangedToOutbox.php:19-35` aucune garde `old===new` | si garde no-op P0.6 manquée → outbox + Echo + Mail/SMS/Push noisy | test dispatch identity | early return si `oldStatus === newStatus` |
| P2 | `eventContract.js` parité backend | `eventContract.js:23-61` ne **rejette pas** absence `branch_id`/`correlation_id` (`?? null` line 56-58) | dedup et branch isolation côté front partielles | test rejet enveloppe sans correlation | aligner sur `EventContract.php:81-129` |
| P2 | `PricingPreviewService::toObject` perd variation.quantity | `:152-155` map `id` only ; checkout `PricingService:127,312` la consomme | preview UX trompeuse multi-quantités | Vitest preview vs checkout | mapper `quantity` dans `toObject` |
| P2 | Outbox claim séparé `dispatched_at` | `DispatchDomainEventsJob:65-86,140-151` reset en exception ; `OutboxRetryFailedCommand:21-35` rescue | crash après claim avant broadcast non détecté en monitoring | dashboard stuck | `claimed_at` séparé + alerte |
| P2 | NF525 sealed-Z status/payment | `OrderService.php:1804-1823` (destroy scellé) vs `:1489-1656,1661-1714` (status/payment libres) | violation immutabilité Z | conditionnel scope | hors V1 op ; **P0 V1 fiscale** |
| P2 | KDS sync version `status_changed_at` TODO | `KdsSyncService.js:126-142` | versioning sync fine | dette | suivi P2 |
| P2 | Channels admin `branch_id=0` accès tout canal | `routes/channels.php:32-35` | privilege admin valide mais token volé = écoute toutes branches | accepté V1 op | observabilité tokens admin |

## 4) P0 V1 evidence-first — ordre exact

| # | P0 | Test à créer/exécuter avant patch | Test post-patch |
|---|---|---|---|
| 1 | `payment-confirm` blindé borne | PHPUnit : Sanctum **non-kiosk** propriétaire → attendu 403 actuel **probable 200** | Sanctum kiosk + KioskMachine matched → 200 PAID ; non-kiosk → 403 ; mauvaise branche → 403 |
| 2 | KDS whitelist | PHPUnit feature : rôle Chef `PREPARING→CANCELED` → attendu 422 actuel probable 202 | Chef PREPARING→PREPARED 200 ; CANCELED/DELIVERED/RETURNED 422 |
| 3 | `OrderStatusRequest` policy par surface | PHPUnit matrice rôle × route × status | 403 hors couloir DEVICE_FLOW |
| 4 | LIKE → `=` `OrderService::list` | PHPUnit cross-branch sur **7 surfaces** (pos/online/table-order index+show+export, sales-report index/export/pdf, transaction) | aucune ligne hors branche actor |
| 5 | `OrderService::show($auth=false)` branche stricte | PHPUnit admin branch=2 GET pos-order/show/{order branch=10} → 403 | passing only same branch |
| 6 | Promo borne `kiosk_promo_code` (décision scope) | PHPUnit : preview `total` vs checkout `total` avec promo valide | si support : preview === checkout + usage_count incrémenté ; si retrait : 422 sur `kiosk_promo_code` au store |
| 7 | No-op identity + cashback idempotent | PHPUnit double cancel/retry sur même order → un seul row `cash_back` ; PHPUnit `PAID→PAID` ne crée pas de transaction | OK |
| 8 | Symétrie POS/Kiosk | livrable plan = tableau diff complet ; tests miroir prix/coupons/idempotency | parité validée |
| 9 | POS cash route dédiée | grep `kds-order/change-status` côté `PosComponent.vue` ; design `admin/pos-order/collect-cash/{order}` | POS DELIVERED via route POS, KDS strict |
| 10 | Cleanup stale ↔ payment-confirm | PHPUnit simulant `payment-confirm` après auto-REJECTED (>15 min) | refus + log réconciliation, ne marque pas PAID |

## 5) Tests exécutés cette passe — couverture réelle vs angles morts

| Test | Status | Couvre vraiment | Ne couvre PAS |
|---|---|---|---|
| `KioskPaymentStateMachineTest` (5) | PASS | lifecycle borne PENDING→PAID via `finalizePaidKioskOrder`, idempotence `payment-confirm` x2 | **garde non-kiosk** sur la route ; rôle Sanctum non-borne avec ownership ; transitions `payment_method` non CARD/TR |
| `KioskPhase1/KioskEndpointsTest` (15) | PASS | `auth:sanctum + abilities:kiosk:order` sur `kiosk-event` (alias slash + tiret) ; menu/preview/promo lieu | route `loyalty/scan` n'a **pas** ability middleware — non couvert |
| `Admin/KdsSyncControllerTest` (8) | PASS | adaptive polling, branch filter sync | whitelist transitions Chef CANCELED ; POS via KDS endpoint ; `expected_status` HTTP |
| `OutboxTest` (6) | PASS | persist + claim + retry + dispatched_at | identity flood (`old===new`) ; race claim/dispatched_at au crash ; `claimed_at` séparé |
| `EventContractTest` (6) | PASS | backend strict `branch_id`/`correlation_id` (`EventContract.php:81-129`) | front laxiste (`eventContract.js:23-61`) — **non testé** |
| `Orders/CleanupStalePendingOrdersTest` (2) | PASS | auto-rejection après 15 min | race avec `payment-confirm` retardé ; offline queue replay ; coordination ledger stock |

Aucun test **HTTP feature** n’a été exécuté pour : `payment-confirm` Sanctum non-kiosk, KDS Chef→CANCELED, cross-branch LIKE sur 7 surfaces, double cashback retry, preview=checkout `kiosk_promo_code`, transition POS/Cashier interdites par couloir.

## 6) Faux positifs / risques abaissés après lecture deep

- **« KDS LIKE branch_id »** — Faux : `KitchenDisplaySystemOrderService.php:84-90` est `=` strict ; le P0 LIKE concerne `OrderService::list`, pas KDS. Confirmé.
- **« Front borne navigue après échec `payment-confirm` »** — Faux : `confirmBackendPayment:562-577` throw + `processCardPayment:447-460` await + `confirmPayment:341-390` catch sans nav. Le résiduel est P1 reprise opérateur.
- **« Catch idempotency POS = erreur Codex »** — Faux : `OrderService.php:1013-1018` reste non scopé. Maintenu P1.
- **« Outbox stuck = P0 V1 »** — Faux : reset exception + `OutboxRetryFailedCommand`. P2.
- **« DB idempotency branch-scope cassé »** — Faux : index composite + test PASS.
- **« Variation quantity preview = P0 financier »** — Faux : checkout serveur SSOT (`PricingService:127,312`). P2 UX.
- **« `expectedFrom` HTTP race passante »** — Faux : `lockForUpdate` + abort 409 traçé, test PASS. P1 contrat client.

## 7) Zones gelées / gates

| Fichier ou zone | Pourquoi gate humain requis si patché |
|---|---|
| `app/Services/OrderService.php:1489-1714` (`changeStatus`/`changePaymentStatus`) | frozen-zone V1, double impact NF525 + cashback/loyalty + outbox ; AGENTS.md §non-négociable |
| `app/Services/OrderService.php:1804-1823` (`destroy` + sealed-Z) | NF525 immutabilité ; bascule scope V1 fiscale |
| `app/Domain/Order/OrderStateMachine.php:27-72` | invariant central OrderStatus + tous appelants (POS/Kiosk/KDS/cleanup) |
| `app/Services/Pricing/PricingService.php`, `PricingRequest.php` | SSOT prix ; toute évolution `forKiosk` doit traverser le tableau symétrie |
| `app/Listeners/PersistOrderCreatedToOutbox.php`, `PersistOrderStatusChangedToOutbox.php`, `app/Jobs/DispatchDomainEventsJob.php` | outbox SSOT → après-commit, branch_id, correlation_id |
| `routes/channels.php:25-39` | privilege escalation kiosk → admin |
| `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php` | contrainte DB déjà déployée ; ne pas relâcher |
| `app/Http/Requests/OrderStatusRequest.php`, `OrderRequest.php`, `Kiosk/PricingPreviewRequest.php`, `PaymentStatusRequest.php` | porte d’entrée RBAC + whitelist payload V1 |
| `app/Jobs/CleanupStalePendingKioskOrders.php` + `app/Console/Kernel.php` | seul auto-rejection légal ; doit rester documenté + coordonné `payment-confirm` |
| `resources/js/components/admin/pos/PosComponent.vue:1414-1421` (collectKioskCashOrder) | dépendance directe avec P0.2 KDS whitelist |
| `app/Services/PaymentService.php:31-71` (`cashBack`) | financier + audit + balance utilisateur |

## 8) PRIOR_CONTEXT (à coller dans futur PLAN_*)

```
PRIOR_CONTEXT_V1_DEEP_2026-04-25
Scope V1 = opérationnelle minimale (POS cash/card · Borne TPE · KDS PREPARING/PREPARED · branch isolation · outbox/Echo). NF525 sealed-Z = HORS V1 op (P0 V1 fiscale séparée, gate humain).

P0 confirmés (10) :
1. payment-confirm sans garde borne (routes/api.php:889-895 ; OrderController.php:77-115). Action : abilities:kiosk:order + KioskMachine resolver + check method/status/branch.
2. KDS whitelist transitions stricte (OrderStateMachine.php:42,49 ; KitchenDisplaySystemOrderService.php:150).
3. OrderStatusRequest policy par surface (OrderStatusRequest.php:23-31) — couloir RBAC route group.
4. OrderService::list LIKE branch_id (OrderService.php:151) → blast 7 surfaces (pos-order/online-order/table-order index+show+export, sales-report index/export/pdf, OrderExport, SalesReportExport, TransactionService).
5. OrderService::show($auth=false) sans branche (OrderService.php:1330-1346).
6. Promo borne preview≠checkout (OrderRequest.php:35-68 ne valide pas kiosk_promo_code ; PricingRequest::forKiosk:90-107 ne le consomme pas ; payload kioskCart.js:32). Décision V1 : support OU retrait.
7. No-op identity + cashback idempotent (OrderService.php:1567-1574 ; OrderStateMachine.php:29-31 ; PaymentService.php:31-71).
8. Symétrie OrderService/FrontendOrderService (livrable plan tableau diff exhaustif).
9. POS cash via endpoint KDS (PosComponent.vue:1414-1421) — devient hard P0 quand P0.2 livré.
10. Cleanup stale 15 min ↔ payment-confirm retardé (CleanupStalePendingKioskOrders.php) — saga réconciliation.

P1 :
- expected_status client requis (KDS).
- TransactionService::list branch_id optionnel (cross-branch).
- OSS branch_id=0 voit toutes branches.
- changePaymentStatus sans state machine paiement.
- TPE accepté + payment-confirm fail = pas de reprise opérateur.
- Catch idempotency POS non scopé branche.
- FrontendOrderService::changeStatus pas de garde no-op cashback.

P2 :
- loyalty/scan route sans abilities middleware.
- Outbox identity flood (PersistOrderStatusChangedToOutbox sans garde old===new).
- eventContract.js front laxiste vs backend EventContract strict.
- PricingPreviewService perd variation.quantity.
- Outbox claimed_at séparé + monitoring.
- KDS sync version status_changed_at TODO.
- Channels admin branch_id=0 accès tout canal.

Routing : codex-extension pour tous P0 ; fallback foodking-complex-implementer si CLI Codex indisponible.
Frozen-zones : OrderService::changeStatus/changePaymentStatus/destroy ; OrderStateMachine ; PricingService ; outbox listeners/jobs ; channels.php ; migrations idempotency ; OrderStatusRequest/OrderRequest/PaymentStatusRequest/Kiosk/PricingPreviewRequest ; CleanupStalePendingKioskOrders + Kernel ; PosComponent.collectKioskCashOrder ; PaymentService::cashBack.

Tests à exécuter avant tout patch :
- HTTP : payment-confirm Sanctum non-kiosk → 403.
- HTTP : Chef PREPARING→CANCELED → 422 (KDS whitelist).
- HTTP : matrice OrderStatusRequest par surface.
- HTTP cross-branch : 7 surfaces consommatrices d'OrderService::list + show.
- HTTP double cancel/retry : un seul cashback row.
- HTTP : preview total === checkout total avec kiosk_promo_code (ou refus 422 si retrait).
- HTTP : payment-confirm après auto-REJECTED → refus.

Décision humaine pendante : scope V1 fiscale NF525 (sealed-Z status/payment) → si inclus, P2.NF525 bascule P0.
```

## 9) DEEP_SINGLE_VERDICT

DEEP_SINGLE_VERDICT: NEEDS_EVIDENCE
