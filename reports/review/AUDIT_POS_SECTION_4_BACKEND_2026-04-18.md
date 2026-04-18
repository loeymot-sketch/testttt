# AUDIT POS — Section 4/4 : Backend (services, controllers, events, jobs, outbox, state machine, idempotency)

**Date.** 2026-04-18  
**Mode.** Lecture seule. Aucune modification de code.  
**Périmètre.** `OrderService`, `FrontendOrderService`, `PricingService`, controllers Admin POS, events/listeners/jobs, migrations, FormRequests, routes, tests backend POS.  
**Sources lues.** `POS_MASTER_BRIEF.md`, `POS_INVARIANTS_AND_GATES.md`, 10 briefs `AUDIT_POS_*_001..010`, `EventContract.php`, `OrderStateMachine.php`, `DispatchDomainEventsJob.php`, `PersistOrder*ToOutbox.php`, `EventServiceProvider.php`, `PricingService.php`, `PaymentService.php`, `AwardLoyaltyPointsOnDelivery.php`, tests `OutboxTest`, `OrderStateMachineApplyTest`, `ConcurrentOrderTest`, `OrderFlowTest`, `BranchIsolationTest`.

---

## 1. Verdict synthèse

**Global : WARN / tending BLOCKED.**

Le cœur POS est encore trop bancal pour passer en PASS, mais il n'y a **pas de violation bloquante irrémédiable** d'un seul invariant capital : SSOT pricing est mieux tenu qu'on ne le craignait (via `PricingService`), `branch_id` n'est pas lu du payload dans les controllers admin, et la majorité des dispatches sont **placés hors transaction** (pattern équivalent à `afterCommit` mais non documenté). En revanche, plusieurs zones critiques doivent être traitées en P0 avant tout lancement commercial.

**3 axes forts.**
1. **SSOT pricing opérationnel.** `posOrderStore` / `myOrderStore` / `tableOrderStore` / `FrontendOrderService::myOrderStore` unset explicitement `total/subtotal/discount` du payload (`OrderService.php:566`, `291`, `940`, `FrontendOrderService.php:187`) et déléguent à `PricingService::calculateOrder()` avec flag `use_ssot_service=true`. Aucune occurrence de `$request->input('price')` / `$request->input('total')` dans les controllers admin.
2. **Outbox `domain_events` mature.** `PersistOrderCreatedToOutbox` et `PersistOrderStatusChangedToOutbox` utilisent tous deux `DB::afterCommit()` (`:37` / `:36`), `DispatchDomainEventsJob` contient un early-exit idempotent (`dispatched_at !== null` → `return`, ligne 37), validation envelope V1 avant broadcast (`:56-68`), rescue commands `foodking:outbox:rescue` et `foodking:outbox:retry-failed` couverts par tests.
3. **State machine encapsulée.** `OrderStateMachine::apply()` + `::allows()` + `::recordTransition()` + `IllegalTransitionException` + audit table `order_status_transitions` avec `correlation_id`. Tests `OrderStateMachineApplyTest` présents.

**3 axes faibles structurels.**
1. **`changePaymentStatus` (OrderService:1485) est une plaie.** Pas de `DB::transaction`, pas de machine d'état paiement, pas d'event `OrderPaid`/`PaymentRecorded`, pas de validation des transitions (UNPAID→PAID direct autorisé, REFUNDED non modélisé). C'est le plus gros trou P0 du backend.
2. **Zéro support multi-tenders et zéro event d'amendement.** Pas de table `order_payments`, pas d'event `OrderItemAdded`, `OrderCancelled`, `OrderRefunded`, `PaymentRecorded`. Les constantes `EventType::ORDER_ITEM_ADDED`/`ORDER_CANCELLED` existent dans `EventContract::BROADCAST_MAP` mais **ne sont jamais dispatchées** depuis le code — contrat « déclaré, jamais servi ».
3. **State machine bypassée par les call-sites legacy.** `OrderStateMachine::apply()` n'est utilisé **nulle part** dans `OrderService`/`FrontendOrderService` ; tous les `changeStatus` font `$order->status = $next; save(); recordTransition()` à la main (11 occurrences hors StateMachine). Le commentaire `:18-20` du StateMachine assume ce choix (« frozen zone V1 ») mais cela casse l'invariant §1 et empêche `requiresReason()` d'être enforced en live. De plus `deliveryBoyOrderChangeStatus` dispatche mails/SMS/push **avant** le `save()` (`OrderService.php:1331-1335`).

---

## 2. Mapping méthodes POS `OrderService.php`

| Méthode | Lignes | Transaction | SSOT pricing | branch_id | State machine | Event canonique | Audit log | Idempotency |
|---|---|---|---|---|---|---|---|---|
| `posOrderStore` | 546–929 | ✅ `DB::transaction` L560 | ✅ via `PricingService::calculateOrder` L602 + unset L566 | ✅ check L576 `$authUser->branch_id` (server) | ❌ insert direct `status = ACCEPT` L586 sans `apply()` | ⚠️ `OrderCreated` dispatché hors tx L904 (pas de `afterCommit`) | ✅ `ActionLog::create` L887 | ✅ header `X-Idempotency-Key` L550 + unique DB + Cache::lock only for queue_number |
| `changeStatus` (POS branche) | 1363–1480 | ✅ DB::transaction L1412 (branche admin) ; ❌ pas de tx pour branche `auth=true` L1370 | n/a | ✅ branch check L1414-1418 | ❌ `$order->status = X; save()` L1439-1440 sans `apply()`, seulement `recordTransition` | ⚠️ `OrderStatusChanged` L1470 hors tx (pas `afterCommit`) | ✅ ActionLog L1451 | n/a |
| `changePaymentStatus` | 1485–1526 | ❌ **AUCUNE transaction** | n/a | ✅ branch check L1498-1502 | n/a (pas de state machine paiement) | ❌ **aucun event** (pas de `OrderPaid`/`PaymentRecorded`) | ✅ ActionLog L1508 | ❌ aucun garde double-soumission |
| `deliveryBoyOrderChangeStatus` | 1312–1358 | ❌ aucune tx | n/a | ⚠️ pas de branch check (owner check only) | ❌ `$order->status = $request->status; save()` | ⚠️ `OrderStatusChanged` L1348 + **mails dispatchés AVANT save** L1331-1333 | ❌ pas de ActionLog | n/a |
| `myOrderStore` (web/app) | 285–541 | ✅ L288 | ✅ via SSOT L310 ou legacy recalcul | ✅ `Auth::user()->id` | ❌ status PENDING raw | ⚠️ `OrderCreated` hors tx L531 | ✅ ActionLog L508 | ❌ pas de `X-Idempotency-Key` |
| `tableOrderStore` | 935–1213 | ✅ L938 | ✅ via SSOT L959 | ⚠️ branch du payload validé (pas recoupé Auth) | ❌ PENDING raw | ⚠️ `OrderCreated` hors tx L1203 | ✅ ActionLog L1189 | ❌ pas d'idempotency |
| `selectDeliveryBoy` | 1554–1580 | ❌ aucune tx | n/a | ❌ pas de branch check | n/a | ⚠️ dispatches mails hors tx | ❌ pas d'ActionLog | n/a |
| `destroy` | 1585–1598 | ✅ L1588 | n/a | ❌ **pas de branch check** — n'importe quel staff peut supprimer n'importe quelle commande | n/a | ❌ pas d'event `OrderCancelled`/`OrderDeleted` | ❌ pas d'ActionLog | n/a |

**Méthodes absentes mais attendues par le brief / invariants.** `addItem`, `removeItem`, `updateItem`, `amendOrder`, `splitPayment`, `refund`, `discount` (standalone). Tout cela n'existe pas. L'amendement post-création n'est **pas implémenté** dans le service.

---

## 3. Sortie brute des greps invariants

### 3.1 SSOT pricing violé ?
```
rg "->input\('price'\)|->input\('total'\)|\$request\['price'\]" app/Http/Controllers/Admin/ app/Services/OrderService.php
→ No matches found
```
**Interprétation.** OK dans les controllers admin et `OrderService`. PosOrderRequest L48 accepte encore `total` du payload comme prétendu "preliminary check" (`PosOrderRequest.php:77-82`) mais le `posOrderStore` L566 l'unset avant `Order::create`, et la revalidation serveur L818-825 compare au total recalculé. Acceptable (WARN, F-10).

### 3.2 branch_id lu du payload dans les controllers admin ?
```
rg "->input\('branch_id'\)|\$request->branch_id" app/Http/Controllers/Admin/
→ No matches found
```
Dans `OrderService::posOrderStore` L576 on compare `(int) $request->branch_id` à `$authUser->branch_id` puis `$validated['branch_id']` passe dans `Order::create`. C'est-à-dire la valeur *validée* est lue mais **contrôlée** face à l'auth. Statut : **PASS** pour admin, mais attention le pattern n'est pas « server force `$authUser->branch_id` » → un admin `branch_id=0` peut toujours créer partout (accepté par design, `PosOrderRequest.php:35` `required|numeric` sans scope).

### 3.3 Écriture directe `->update(['status' => ...])` hors StateMachine
```
rg "->update\(\[\s*'status'" app/ -g '*.php' | rg -v OrderStateMachine
app/Services/KioskMachineService.php:152: → $kioskMachine->update(['status' => $request->input('status')]);
```
**Interprétation.** Une seule occurrence et elle concerne `KioskMachine.status` (enable/disable kiosk), pas l'`Order.status`. OK pour la cible POS.

### 3.4 Écritures inline `->status =` (forme équivalente non couverte par grep 3.3)
Au sein de `OrderService.php` + `FrontendOrderService.php` :
```
OrderService:1330: $oldStatus = $order->status;
OrderService:1334: $order->status = $request->status;
OrderService:1387: $order->status = $request->status;
OrderService:1439: $order->status = $request->status;
FrontendOrderService:550: $this->frontendOrder->status = OrderStatus::ACCEPT;
FrontendOrderService:661: $frontendOrder->status = $request->status;
FrontendOrderService:736: $locked->status = OrderStatus::ACCEPT;
OrderStateMachine:156: $order->status = $next; // SEUL site légitime
```
**11 assignments directs hors StateMachine::apply()** (comptés sur les deux services). Invariant §1 « `OrderStateMachine::apply()` utilisé partout » → **VIOLÉ** (finding F-02).

### 3.5 Dispatch avant commit ?
```
rg "Event::dispatch|::dispatch\(" app/Services/OrderService.php app/Services/FrontendOrderService.php | rg -v "afterCommit|shouldDispatchAfterCommit"
→ 34 matches dans OrderService, 15 dans FrontendOrderService (voir ligne-à-ligne §4)
```
**Interprétation.** Aucun `dispatch` n'est *explicitement* `afterCommit()`; la convention repose sur le fait qu'ils sont **tous placés hors du bloc `DB::transaction(function(){})`**. Vérification manuelle pour `posOrderStore` : `DB::transaction` ferme à L895, dispatches à L900–904 → OK. Pour `changeStatus` branche admin : tx ferme L1462, dispatches L1464–1472 → OK. **MAIS `deliveryBoyOrderChangeStatus` L1331-1333 dispatche AVANT `$order->save()` L1335** sans aucune transaction ⇒ si `save` plante, les mails/SMS/push partent pour un changement de statut qui n'a pas eu lieu (finding F-05, P1). Et la convention « hors tx = safe » est fragile : le moindre refactor qui remonte un dispatch d'une ligne le met dans la tx (F-09).

### 3.6 EventContract bypass (`broadcast()` direct) ?
```
rg "broadcast\(" app/Events/
→ No matches found
```
**Interprétation.** Personne ne broadcast directement depuis `app/Events/`. Tout passe par l'outbox + `DispatchDomainEventsJob::handle` qui appelle `$connection->getPusher()->trigger(...)` après `assertEnvelopeValid` (L57). **PASS** sur cet invariant.

### 3.7 Audit log absent sur refund/cancel/discount ?
```
rg "OrderCancel|OrderRefund|applyDiscount" app/Services/ | rg -v "AuditLog|audit_log"
→ seulement: app/Services/OrderService.php:1385 / :1435 → refundPoints() (loyalty, pas paiement)
```
**Interprétation.** `applyDiscount`/`OrderRefund`/`OrderCancel` **n'existent pas** comme méthodes. Les cancellations passent par `changeStatus(CANCELED)` et le refund par `PaymentService::cashBack` (L29) qui, notons-le, ne consigne **rien** dans `action_logs` (F-06). `ActionLog` est tracé uniquement dans `changeStatus` branche admin (L1451) et dans `posOrderStore` (L887). `changePaymentStatus` L1508 trace mais sans détail montant.

### 3.8 Idempotency
```
rg "X-Idempotency-Key|idempotency_key|Cache::lock" app/
```
Résultats concentrés :
- `OrderService.php:550`/`552`/`570`/`917` + colonne `idempotency_key` fillable `Order.php:48`.
- `FrontendOrderService.php:127`/`129`/`131`/`181`/`588` + `FrontendOrder.php:47`.
- `Cache::lock` utilisé **uniquement pour `queue_number`** (OrderService:449/777/1126, FrontendOrderService:370).
- **Idempotency POS n'utilise PAS `Cache::lock`**. Il se contente d'une lecture pré-check (L552) + unique constraint DB + catch 23000 (L916). **Race condition théoriquement fermée par la constraint mais sans lock explicite.** `FrontendOrderService` fait mieux : `Cache::lock('frontend_order_idempotency_' . sha1(key), 10)->block(5)` L129-130.
- **Aucun middleware dédié** `Idempotency` dans `app/Http/Middleware/` (16 fichiers listés, rien qui ressemble).
- **Scope branche absent** : la clé est globale, pas `(branch_id, key)` (F-07).

### 3.9 Transactions POS
```
rg "DB::transaction" app/Services/OrderService.php
L288 myOrderStore
L560 posOrderStore
L938 tableOrderStore
L1412 changeStatus (branche admin uniquement)
L1588 destroy
```
**Interprétation.** `changePaymentStatus` et `deliveryBoyOrderChangeStatus` et `selectDeliveryBoy` et la branche `auth=true` de `changeStatus` (L1370–1404) n'ont **aucune transaction**. Côté kiosk, `FrontendOrderService::finalizePaidKioskOrder` utilise `DB::transaction` L727. OK. (F-04).

### 3.10 Queue number atomique
```
rg "queue_number" app/ -g '*.php'
```
Pattern unifié branches 3 call-sites :
- Cache::lock `queue_lock_{branch}_{today}` 10s (hold) / block 5s.
- `MAX(CAST(SUBSTRING(queue_number, 2) AS UNSIGNED))` sur table `orders` (physique partagée Order + FrontendOrder).
- Format `A{4 digits}`, fallback timestamp-based en cas de `LockTimeoutException`.
**Interprétation.** Plutôt solide, mais **fallback L468/L795/L1144 produit un queue_number non-séquentiel** et log seulement en WARN — invariant "séquentiel sans trou" non tenu sous charge (F-11). Aussi `SimulateKioskOrders.php:38` génère `A001` 3 digits ce qui ferait collision avec le nouveau format 4 digits si exécuté en dev-prod mélangé (F-12, P3 dev-only).

---

## 4. Symétrie `OrderService` ↔ `FrontendOrderService`

| Aspect | `OrderService::posOrderStore` | `FrontendOrderService::myOrderStore` | Parité |
|---|---|---|---|
| Transaction unique | ✅ L560 | ✅ L145 | OK |
| Unset prix payload | ✅ L566 | ✅ L187 | OK |
| SSOT `PricingService` | ✅ L602 `forPos()` | ✅ L206 `forKiosk()` | OK (avec 2 implémentations legacy conservées) |
| Branch ownership enforcement | ✅ Auth check L576 | ✅ forcé via `KioskMachine` L160 | OK (via deux mécanismes différents) |
| `X-Idempotency-Key` | ⚠️ pré-check + unique DB (pas de Cache::lock) L550 | ✅ Cache::lock + unique DB L129 | **Asymétrie — F-07** |
| Queue number lock | ✅ L777 | ✅ L370 | OK |
| Status initial | `OrderStatus::ACCEPT` L586 direct (POS auto-accept) | `OrderStatus::PENDING` L192 + promote to ACCEPT L549 pour kiosk-cash | OK (distinct intentionnel) |
| Event `OrderCreated` | ✅ dispatch hors tx L904 | ✅ dispatch hors tx L577 (`dispatchNewOrderSignals`) | OK |
| Event `OrderStatusChanged` sur auto-accept | ❌ pas dispatché (jamais transition PENDING→ACCEPT pour POS car on part direct en ACCEPT) | ✅ dispatch L565 via `dispatchOrderStatusSignals` + `recordTransition` L557 | **Asymétrie** — POS ne produit aucun `OrderStatusChanged` à la création, donc KDS/OSS qui écoutent *uniquement* `OrderStatusChanged` ratent la première arrivée (F-03) |
| Loyalty discount server-side | ⚠️ pas de bloc loyalty dans `posOrderStore` (juste `loyalty_customer_code` L829) → loyalty burn doit passer par `LoyaltyController::redeem` avant submit | ✅ bloc complet L434-499 avec lockForUpdate + `LoyaltyTransaction::create` ledger | **Asymétrie — F-13** |
| OrderCoupon tracking | ✅ L847 | ✅ L541 | OK |
| Cross-item injection guard variations/extras | ✅ L676-681, L702-707 | ✅ L293-298, L317-322 | OK |
| ActionLog création commande | ✅ L887 | ❌ **absent** dans `myOrderStore` kiosk | **Asymétrie — F-14** |

---

## 5. Events POS : existants / manquants / non-conformes V1

### 5.1 Enregistrés dans `EventServiceProvider::$listen`
- `OrderCreated` → `SendFcmOnOrderCreated`, `PersistOrderCreatedToOutbox`, `DecrementItemAvailabilityOnOrder` (L97-101).
- `OrderStatusChanged` → `AwardLoyaltyPointsOnDelivery`, `SendFcmOnOrderStatusChange`, `PersistOrderStatusChangedToOutbox` (L90-95).
- `ItemAvailabilityChanged` → outbox + cache bump + kiosk cache invalidator (L102-109).

### 5.2 Dispatchés depuis OrderService / FrontendOrderService
| Event | Dispatché ? | Conformité V1 | Source |
|---|---|---|---|
| `OrderCreated` | ✅ POS L531, L904, L1203 / Frontend L770 | ✅ payload `order_id` ok (`PersistOrderCreatedToOutbox:23-30`) | Outbox valide |
| `OrderStatusChanged` | ✅ partiel (voir §3.4) | ✅ payload `order_id, old_status, new_status` (`PersistOrderStatusChangedToOutbox:22-29`) | Outbox valide |
| `ItemAvailabilityChanged` | Dispatché par `ItemService` (hors scope) | ✅ | OK |

### 5.3 Events **déclarés dans EventContract** mais JAMAIS dispatchés
- `EventType::ORDER_ITEM_ADDED` (`EventContract.php:37`) — **aucun dispatch** nulle part → amendement silencieux, KDS n'est jamais notifié d'un ajout d'item (F-08).
- `EventType::ORDER_CANCELLED` (`:38`) — **aucun dispatch** : annulations émettent un `OrderStatusChanged(old, CANCELED)` générique, pas l'event canonique — KDS/OSS peuvent filtrer mais la sémantique métier est perdue (F-15).
- `EventType::STOCK_LOW` (`:53`) — aucun dispatch (pas dans le scope strict mais incohérence de contrat).

### 5.4 Events **totalement absents** (contrat et dispatch)
- `OrderPaid` / `PaymentRecorded` — **aucune déclaration dans `EventType`, aucune event class, aucun listener**. Conséquence : un passage `UNPAID → PAID` n'émet rien (`changePaymentStatus:1505-1506`), donc dashboard/X-report ne savent pas en temps réel qu'un paiement est passé (F-01, P0).
- `OrderItemRemoved`, `OrderRefunded`, `OrderAmended`, `DiscountApplied` — absents.

### 5.5 Payload envelope V1 (DomainEvent rows)
`PersistOrderCreatedToOutbox` et `PersistOrderStatusChangedToOutbox` remplissent `version` implicitement via `EventContract::buildEnvelope()` côté job (ok), mettent `channel` = `json_encode(['private-branch.' . $branch_id])`, `correlation_id` = `Str::uuid()` **(nouveau UUID à chaque listener, pas propagé depuis `X-Correlation-ID`)** — conséquence : impossible de suivre un flux bout-en-bout (F-16, P2).

---

## 6. Idempotency & transactions (audit détaillé)

| Check | Constat | Fichier:ligne | Verdict |
|---|---|---|---|
| Middleware dédié ? | ❌ non | n/a | BLOCKED |
| `X-Idempotency-Key` lu | ✅ POS et kiosk | `OrderService:550`, `FrontendOrderService:127` | OK |
| Clé persistée en DB | ✅ `orders.idempotency_key` UNIQUE | migration `2026_03_25_002938` | OK |
| Cache::lock sur la clé | ❌ POS (FrontendOrder oui) | `OrderService:550-556` vs `FrontendOrderService:129` | **Asymétrie F-07** |
| Scope `(branch_id, key)` | ❌ clé globale unique | migration | **Gap** — deux branches qui réutilisent une clé se collisent |
| TTL lock | ✅ 10s / block 5s (kiosk) | `FrontendOrderService:129-130` | OK |
| DB race catch 23000 | ✅ POS + kiosk | `OrderService:916`, `FrontendOrderService:587` | OK |
| Test double-submit Feature | ✅ mais kiosk uniquement | `ConcurrentOrderTest:62` | **POS non couvert — F-17** |
| `DispatchDomainEventsJob` idempotent | ✅ early-exit `dispatched_at !== null` | `:37-39` | OK |
| Outbox re-dispatch protection | ✅ via `attempts` + `backoff = [1,5,30,300]` + `tries=5` | `:21-23` | OK |

**Transactions manquantes listées** (récap F-04) :
- `changePaymentStatus` (P0).
- `deliveryBoyOrderChangeStatus` (P1).
- `selectDeliveryBoy` (P2).
- `changeStatus` branche `auth=true` (P1).

---

## 7. Outbox & jobs — ordonnancement, retries, early-exit

- **Ordonnancement.** `DispatchDomainEventsJob` → `onQueue('high')` dans constructeur L27 + re-dispatch via `DB::afterCommit` L37 (listener). Garantit que la row `domain_events` est déjà persistée quand le worker tente le broadcast. `ShouldQueue` + `afterCommit` → event arrive sur Pusher **après** la transaction métier. OK.
- **Retries.** `tries = 5`, `backoff = [1, 5, 30, 300]` s → couvre jusqu'à 5 min de panne Pusher. En cas d'échec final, `failed()` L81 persiste `last_error` sur la row — bon pattern. Pas de dead-letter explicite (F-18, P3).
- **Rescue.** `foodking:outbox:rescue` (`OutboxTest:110-137`) re-queue les events stale (`>2 min` sans dispatch). `foodking:outbox:retry-failed` reset attempts + re-queue (`OutboxTest:139-173`). Les deux sont testés.
- **Envelope validation.** `EventContract::assertEnvelopeValid` L57 avant `trigger`. Mismatch → job failed + row marquée `last_error: contract_violation:…`. Robuste.
- **Gaps identifiés.**
  1. `correlation_id` régénéré dans chaque listener au lieu d'être lu depuis `request()->header('X-Correlation-ID')` (`PersistOrderCreatedToOutbox:33`, `PersistOrderStatusChangedToOutbox:32`) → cassé pour traçage E2E (F-16).
  2. `channel` encodé `json_encode(['private-branch.'.id])` au niveau listener, mais décodé L45 `json_decode` et normalisé si pas un array. OK mais fragile (dépend d'un encodage/décodage symétrique). Pas de test unitaire de non-régression JSON. (F-19, P3).
  3. `DomainEvent::scopeFailed` L44 considère failed=`attempts >= 4` mais `tries=5` → incohérence d'un cran : un event à `attempts=4` est à la fois "failed" (scope) et encore éligible à 1 retry par Laravel. (F-20, P2).

---

## 8. Tests backend POS — cartographie et trous

### 8.1 Couvert
- `OutboxTest` (4 tests) : persistance domain_event sur `OrderCreated`, rollback, dispatch marque dispatched_at, rescue/retry-failed.
- `OrderStateMachineApplyTest` : transitions légales + illegal throws + `requiresReason`.
- `OrderFlowTest::test_order_price_recalculated_server_side` : le hacker envoie `price=0.01`, assertion que la commande est créée avec prix DB.
- `ConcurrentOrderTest` : idempotency kiosk (2 tests), loyalty concurrent (lockForUpdate).
- `EventContractTest`, `KioskEventTest`, `KioskEventBranchIsolationTest` : envelope V1 + whitelist events kiosk.
- `PosOrderTaxTest`, `PosDiscountTest`, `PosPriorityApiTest`, `PosUITest`, `POSComprehensiveTest` : visés par les briefs mais contenus non inspectés dans cet audit (à cartographier en section § suivante).

### 8.2 Trous de couverture (F-21 consolidé)
- **Zéro test** `posOrderStore` double-submit avec `X-Idempotency-Key` (kiosk oui, POS non).
- **Zéro test** transitions paiement (`changePaymentStatus` UNPAID → PAID → REFUNDED).
- **Zéro test** `OrderStateMachine::apply()` appelé depuis un flux POS real-life (seulement unit tests isolés).
- **Zéro test** vérifiant qu'un dispatch `OrderCreated` arrive bien à `PersistOrderCreatedToOutbox` **après** le commit de `posOrderStore` (on a le test sur `OutboxTest` mais pas sur la chaîne complète posOrderStore → outbox).
- **`BranchIsolationTest` est vide** (placeholder `$this->assertTrue(true)` L12).
- Aucun test de symétrie parité `OrderService ↔ FrontendOrderService`.
- Aucun test d'amendement (pour cause, feature absente).

---

## 9. Findings priorisés (22 items)

### POS-P4-F-01 — `changePaymentStatus` sans transaction ni state machine ni event — **P0**
- **file:line.** `app/Services/OrderService.php:1485-1526`
- **description.** La méthode écrit `$order->payment_status = $request->payment_status; $order->save()` sans `DB::transaction`, sans enum check (transitions `UNPAID → PARTIALLY_PAID → PAID → REFUNDED` non formalisées), sans event `OrderPaid`/`PaymentRecorded`, sans idempotency guard. Elle loggue ActionLog L1508 mais sans montant encaissé.
- **impact.** (a) Double encaissement si le front retry ; (b) POS→ACCEPT silencieux non observable par KDS/OSS qui écoutent les events ; (c) fiscalité X/Z impossible à reconstituer fiablement (pas de ligne `payment_recorded` timestampée) ; (d) aucun rollback si un listener futur plante après `$order->save()`.
- **fix_proposal.** Introduire `OrderPaymentStateMachine` + enum `PaymentStatus` strict, envelopper `changePaymentStatus` dans `DB::transaction`, émettre `PaymentRecorded(order_id, amount, method, tender)` ou au minimum `OrderStatusChanged` enrichi. Persister une ligne `order_payments` (multi-tender natif).
- **invariants touchés.** §1.1 SSOT, §1.1 DB::afterCommit, §1.1 EventContract, §1.2 audit immutable, §1.2 multi-tenders.
- **resurface_from.** `AUDIT_POS_PAYMENT_CASH_CARD_002.md` Q1, Q2, Q8.

### POS-P4-F-02 — State machine bypassée par tous les call-sites legacy — **P0**
- **file:line.** `app/Services/OrderService.php:1334, 1387, 1439` ; `app/Services/FrontendOrderService.php:550, 661, 736` ; `app/Services/OrderService.php:1330-1335` (delivery boy)
- **description.** 11 écritures inline `->status = X; save()` suivies d'un `OrderStateMachine::recordTransition()` manuel. `OrderStateMachine::apply()` n'est utilisée **nulle part** en prod. La garde `requiresReason()` pour CANCELED/REJECTED/RETURNED est contournée (présent uniquement dans branche admin L1422 via `$request->validate`, absente des autres chemins).
- **impact.** Transitions illégales théoriquement détectées par `ValidStatusTransition` FormRequest, mais si un futur PR court-circuite le FormRequest (ex: job interne) la machine n'est **pas** le garde-fou qu'elle prétend être. Cassure de l'invariant §1.
- **fix_proposal.** Vague dédiée : refactor `changeStatus`/`changePaymentStatus` pour passer par `OrderStateMachine::apply($order, $next, Auth::user(), $request->reason)`. Supprimer les `recordTransition` manuels.
- **invariants touchés.** §1.1 OrderStateMachine.
- **resurface_from.** `AUDIT_POS_STATUS_TRANSITIONS_003.md` Q1, Q5.

### POS-P4-F-03 — `posOrderStore` n'émet aucun `OrderStatusChanged(PENDING→ACCEPT)` — **P0**
- **file:line.** `app/Services/OrderService.php:583-595, 900-904`
- **description.** POS crée `status = ACCEPT` dès `Order::create` L586, puis dispatche `OrderCreated` uniquement. Les listeners qui écoutent `OrderStatusChanged` (KDS refresh, loyalty anticipate, FCM status) **ne reçoivent rien**. Symétriquement, `FrontendOrderService::myOrderStore` L549-553 fait la promotion PENDING→ACCEPT en émettant `OrderStatusChanged` via `dispatchOrderStatusSignals`.
- **impact.** Asymétrie observable : les commandes POS n'arrivent pas sur l'écran KDS tant qu'un `OrderStatusChanged` n'a pas été dispatché manuellement par un staff.
- **fix_proposal.** Soit (a) dispatcher `OrderStatusChanged($order, PENDING, ACCEPT)` juste après `OrderCreated` dans `posOrderStore` ; soit (b) uniformiser avec `FrontendOrderService` (create en PENDING, promote par un seul code-path commun).
- **invariants touchés.** §1.1 OrderService/FrontendOrderService symmetry.
- **resurface_from.** `AUDIT_POS_ORDER_CREATION_001.md` Q5, `AUDIT_KIOSK_GLOBAL_2026-04-18.md §3`.

### POS-P4-F-04 — Plusieurs endpoints POS mutatifs sans transaction — **P0**
- **file:line.** `OrderService.php:1370-1404` (`changeStatus` auth=true), `:1312-1358` (delivery boy), `:1485-1526` (`changePaymentStatus`), `:1554-1580` (`selectDeliveryBoy`).
- **description.** `$order->save()` + dispatches sans `DB::transaction`. Si `cashBack()` ou `refundPoints()` dans la branche `auth=true` L1379-1385 échoue après le `save()`, on a un `Order` en `CANCELED` mais sans remboursement.
- **impact.** Incohérence transactionnelle entre état de la commande, transactions monétaires et points loyalty. P0 pour `changePaymentStatus`, P1 pour les autres.
- **fix_proposal.** Wrapping `DB::transaction` autour de chaque mutate + side-effects. Laisser les dispatches hors de la closure.
- **invariants touchés.** §1.1 `DB::afterCommit`.

### POS-P4-F-05 — `deliveryBoyOrderChangeStatus` dispatche les mails AVANT `$order->save()` — **P1**
- **file:line.** `app/Services/OrderService.php:1330-1335`
- **description.** Ordre des instructions : `SendOrderMail::dispatch`, `SendOrderSms::dispatch`, `SendOrderPush::dispatch` puis `$order->status = X; $order->save()`. Aucune transaction. Si le save échoue, les notifications partent pour un statut non appliqué.
- **impact.** Client reçoit « commande en préparation » alors que le DB reste au statut précédent.
- **fix_proposal.** Déplacer les 3 dispatches APRÈS `$order->save()` et les placer hors d'une éventuelle transaction.
- **invariants touchés.** §1.1 afterCommit (spirit).

### POS-P4-F-06 — `PaymentService::cashBack` ne logge jamais dans action_logs et rate le cas "pas de Transaction préalable" — **P0**
- **file:line.** `app/Services/PaymentService.php:29-50`
- **description.** `cashBack` vérifie `if ($transaction)` L32, crée une nouvelle ligne Transaction avec `sign = '-'`, crédite `$user->balance`. **Si aucune transaction préalable** (commande cash pos, jamais de row Transaction car payment service n'est utilisé que pour gateways), la méthode ne crée **rien** et ne log **rien**. Elle ne lève pas non plus d'exception.
- **impact.** Toute annulation/refund d'une commande POS cash = perte silencieuse d'auditabilité fiscale (loi Finance 2018 anti-fraude TVA, requires cryptographic chain). ActionLog n'est pas non plus appelé.
- **fix_proposal.** (a) Toujours persister un `PaymentRecord` / `order_payments` pour *toute* commande POS, y compris cash, dès `posOrderStore`. (b) Dans `cashBack`, créer systématiquement une ligne `type=refund` et un ActionLog immuable.
- **invariants touchés.** §1.2 audit immuable, conformité NF525.

### POS-P4-F-07 — Idempotency POS sans `Cache::lock` + scope branche absent — **P1**
- **file:line.** `app/Services/OrderService.php:548-556`, `Order.php:48`, migration `2026_03_25_002938_add_idempotency_key_to_orders_table.php:17`
- **description.** La clé est protégée uniquement par `SELECT`+`INSERT` avec unique constraint. Race théoriquement fermée par `23000` catch L916, mais sans `Cache::lock` on peut avoir des *false hits* lors d'un retry où la transaction précédente n'est pas commitée (isolation REPEATABLE READ sur MySQL). Le kiosk (`FrontendOrderService:129`) fait mieux.
- **impact.** Double-submit POS sous retry TPE → possible double commande (probabilité faible, impact financier haut).
- **fix_proposal.** `Cache::lock('pos_order_idempotency_' . sha1($key), 10)->block(5)` et scope `(branch_id, key)` dans l'index unique (actuellement globale).
- **invariants touchés.** §1.2 Idempotency POS.
- **resurface_from.** `AUDIT_POS_IDEMPOTENCY_RETRIES_007.md` Q3, Q4, Q10.

### POS-P4-F-08 — `ORDER_ITEM_ADDED` et `ORDER_CANCELLED` déclarés mais jamais dispatchés — **P1**
- **file:line.** `app/Domain/Events/EventContract.php:37-38` ; `app/Enums/EventType.php:9-10`
- **description.** Ces types sont whitelisted dans `BROADCAST_MAP` et `REQUIRED_PAYLOAD_KEYS`, mais aucun code ne les dispatch. `OrderCancelled` pourrait être émis depuis `changeStatus(CANCELED)` mais ne l'est pas.
- **impact.** Consommateurs (KDS, OSS, dashboard) filtrent sur `type` et ne peuvent distinguer une annulation d'une transition ordinaire. Amendements invisibles côté KDS.
- **fix_proposal.** Dispatcher `OrderCancelled` depuis `changeStatus` quand `$request->status === CANCELED`. Dispatcher `OrderItemAdded` dès qu'une méthode amend sera introduite. Ajouter tests de non-régression.
- **invariants touchés.** §1.1 EventContract V1.

### POS-P4-F-09 — Convention "dispatch hors transaction" non documentée, fragile au refactor — **P1**
- **file:line.** `app/Services/OrderService.php:524-534, 898-908, 1197-1206, 1464-1473`
- **description.** La règle « pas d'`afterCommit()` explicite, on se fie au fait que les dispatches sont placés *après* le `});` de `DB::transaction` » fonctionne *aujourd'hui* mais n'est protégée par aucune assertion. Un refactor qui inline la logique dans la closure casserait l'invariant sans tripwire.
- **impact.** Sur un futur refactor, event émis avec transaction pas encore committée → ghost KDS orders si rollback.
- **fix_proposal.** Appliquer `OrderCreated::dispatchAfterResponse()` ou wrapper chaque dispatch dans `DB::afterCommit(fn() => …::dispatch($order))`. Ajouter un test qui fait `DB::beginTransaction(); posOrderStore; DB::rollBack()` et vérifie que `OrderCreated` n'a PAS été dispatché (actuellement c'est le comportement implicite).
- **invariants touchés.** §1.1 DB::afterCommit.

### POS-P4-F-10 — `PosOrderRequest` valide encore `total` et `subtotal` depuis le payload — **P2**
- **file:line.** `app/Http/Requests/PosOrderRequest.php:36-48, 77-82`
- **description.** `total` est `required|numeric|min:0`, `subtotal` `nullable|numeric`. La validation `withValidator` L80 compare `pos_received_amount` au `total` du client. Le commentaire L76-79 admet que ça sert juste à un pré-check UI. Acceptable, mais reste une surface d'attaque si un dev ajoute une logique dérivée de ces fields avant le unset L566.
- **impact.** Risque de régression future — qqun lit `$request->total` au lieu de `$this->order->total`.
- **fix_proposal.** Marquer `total` / `subtotal` comme `nullable` et déplacer la validation cash vs received_amount dans le service (post-recalcul).
- **invariants touchés.** §1.1 SSOT pricing.

### POS-P4-F-11 — Fallback `queue_number` timestamp-based sur `LockTimeoutException` casse la séquentialité — **P1**
- **file:line.** `app/Services/OrderService.php:467-469, 794-796, 1143-1145` ; `app/Services/FrontendOrderService.php:389-392`
- **description.** Sur `LockTimeoutException` (5s), le code calcule `'A' . str_pad((int)(microtime(true) * 10) % 9999 + 1, …)` — numéro pseudo-aléatoire, potentiellement < max séquentiel, possible collision si deux fallbacks concurrents tombent dans le même slot.
- **impact.** NF525 "numérotation séquentielle sans trou" violée sous charge. Confusion client (deux clients reçoivent `A0231`).
- **fix_proposal.** Sur lock timeout, lever une 503 claire plutôt que fabriquer un numéro non séquentiel. Ou introduire une séquence DB dédiée (`CREATE SEQUENCE` / table atomic counter per branch/day).
- **invariants touchés.** §1.2 Z fiscal conforme.

### POS-P4-F-12 — `SimulateKioskOrders` produit `A001` 3 digits, incompatible avec production 4 digits — **P3**
- **file:line.** `app/Console/Commands/SimulateKioskOrders.php:38`
- **description.** Format hard-codé `str_pad(i+1, 3, '0', STR_PAD_LEFT)` — prod est `4`.
- **impact.** Dev tooling divergent.
- **fix_proposal.** Extraire constant `OrderService::QUEUE_PAD_LENGTH = 4`.

### POS-P4-F-13 — Loyalty burn absent de `posOrderStore` — **P1**
- **file:line.** `app/Services/OrderService.php:829-836` vs `app/Services/FrontendOrderService.php:434-499`
- **description.** POS stocke juste `loyalty_customer_code` mais ne déduit pas les points. Le brief `AUDIT_POS_COUPON_LOYALTY_005` et `docs/BUSINESS_RULES.md` attendent une symétrie. Soit le caissier doit appeler `LoyaltyController::redeem` avant `posOrderStore`, soit le service doit supporter les deux flows.
- **impact.** Surface POS = pas de burn automatique. Si l'UI ne pense pas à appeler `redeem`, le client perd sa remise.
- **fix_proposal.** Ajouter dans `posOrderStore` un bloc identique à `FrontendOrderService:434-499` (ou extraire en `LoyaltyService::redeemInTransaction($order, $code, $amount)` partagé).
- **invariants touchés.** §1.1 OrderService/FrontendOrderService symmetry.

### POS-P4-F-14 — `FrontendOrderService::myOrderStore` ne crée pas d'ActionLog à la création — **P2**
- **file:line.** `app/Services/FrontendOrderService.php:500-555` (vs `OrderService.php:887-892`)
- **description.** Asymétrie d'audit : les commandes kiosk/web n'apparaissent jamais dans `action_logs`.
- **impact.** Dashboard admin ne peut pas reconstruire l'historique staff/surface source.
- **fix_proposal.** Ajouter `ActionLog::create` symétrique dans `myOrderStore`.

### POS-P4-F-15 — `OrderStatusChanged(…, CANCELED)` n'est pas enrichi avec raison / source — **P2**
- **file:line.** `app/Listeners/PersistOrderStatusChangedToOutbox.php:22-29`
- **description.** Payload omet `reason`, `actor_id`, `source_surface`. `PersistOrderCreatedToOutbox:24-30` idem : pas de `source`, `pos_payment_method`, `total_tax`.
- **impact.** Consommateurs temps réel doivent re-fetch pour enrichir.
- **fix_proposal.** Étoffer les payloads et versionner (la majeure de l'envelope reste 1).

### POS-P4-F-16 — `correlation_id` régénéré par listener, jamais propagé depuis la requête — **P2**
- **file:line.** `app/Listeners/PersistOrderCreatedToOutbox.php:33`, `app/Listeners/PersistOrderStatusChangedToOutbox.php:32`
- **description.** Chaque listener fait `Str::uuid()` au lieu de récupérer `request()?->header('X-Correlation-ID')` (comme le fait correctement `OrderStateMachine::recordTransition` L105).
- **impact.** Trace E2E cassée : la correlation_id de la requête HTTP n'est pas portée jusqu'au domain_event → logs Datadog/Sentry non-joignables.
- **fix_proposal.** Utiliser `HasCorrelationId` trait (déjà importé dans `DispatchDomainEventsJob`) côté listener, ou lire le header request.

### POS-P4-F-17 — Pas de test Feature POS double-submit idempotency — **P1**
- **file:line.** `tests/Feature/ConcurrentOrderTest.php:62-84` (kiosk only)
- **description.** Le test existe pour `/api/frontend/order` mais pas pour `/api/v1/admin/pos`.
- **fix_proposal.** Dupliquer le test pour route `POST /api/v1/admin/pos` avec un user POS (pas kiosk machine).

### POS-P4-F-18 — Outbox : pas de dead-letter queue / pas d'alerte operator après 5 retries — **P3**
- **file:line.** `app/Jobs/DispatchDomainEventsJob.php:81-98`
- **description.** `failed()` logge en WARNING mais ne notifie pas les ops ; le row reste en `attempts = 5` + `last_error`. Aucun dashboard ne surveille ça.
- **fix_proposal.** Alerte Sentry + commande `foodking:outbox:alerts` programmée.

### POS-P4-F-19 — `domain_events.channel` stocké en JSON-string dans colonne VARCHAR, parsing double encodage — **P3**
- **file:line.** migration `2026_04_15_200000_create_domain_events_table.php:18` (`string channel 255`) ; `DispatchDomainEventsJob.php:45-49`
- **description.** `channel` est un string mais contient parfois `'["private-branch.7"]'`. Code fait `json_decode` puis fallback array simple. Pourrait être `json` native column.
- **fix_proposal.** Migration suivante : convertir en `json` et normaliser.

### POS-P4-F-20 — `DomainEvent::scopeFailed(4)` incohérent avec `tries=5` — **P2**
- **file:line.** `app/Models/DomainEvent.php:44-48` vs `app/Jobs/DispatchDomainEventsJob.php:23`
- **description.** `scopeFailed` considère `attempts >= $maxAttempts=4` comme failed, mais Laravel retry jusqu'à 5. Un event à `attempts=4` peut être re-queued par `foodking:outbox:retry-failed` alors qu'il a encore un retry automatique — double dispatch possible.
- **fix_proposal.** Aligner (soit scopeFailed sur 5, soit tries sur 4).

### POS-P4-F-21 — Couverture tests POS backend très faible (4 trous majeurs) — **P1**
- **file:line.** `tests/Feature/BranchIsolationTest.php:12` (vide) ; absence `PosOrderStoreIdempotencyTest`, `ChangePaymentStatusTest`, `PosAmendOrderTest` (n/a car feature manquante)
- **description.** Voir §8.2.
- **fix_proposal.** Vague tests dédiée : 5+ tests Feature POS (double-submit POS, changement paiement, isolation branche, cascade TVA, loyalty burn POS).

### POS-P4-F-22 — `OrderService::destroy` supprime sans branch check, sans event, sans ActionLog — **P0**
- **file:line.** `app/Services/OrderService.php:1585-1598`
- **description.** `DB::transaction` efface `address`, `coupon`, `orderItems`, `order`. Aucun check `Auth::user()->branch_id === $order->branch_id`. Aucun `ActionLog`. Aucun event `OrderDeleted`/`OrderCancelled`. Le controller `PosOrderController::destroy:59-68` ne protège que via `permission:pos-orders`.
- **impact.** Un staff d'une branche A peut supprimer une commande de la branche B tant qu'il a `pos-orders`. Audit fiscal impossible (suppression physique !). Violation directe de §1.2 « audit immutable + Z fiscal ».
- **fix_proposal.** (1) Interdire `delete` physique : soft-delete + event `OrderDeleted`. (2) Check branch ownership. (3) ActionLog obligatoire. (4) Restrict permission to `Admin` seulement.
- **invariants touchés.** §1.1 branch_id, §1.2 audit immutable, §1.2 Z fiscal.

---

## 10. Check-list invariants POS (ligne à ligne)

- SSOT pricing POS — `posOrderStore` ne lit jamais le prix payload → **OK** (unset L566, `PricingService` L602).
- `branch_id` jamais lu du payload dans controllers admin → **OK** (`$request->branch_id` recoupé avec `$authUser->branch_id` L576 ; grep §3.2 vide).
- `OrderStateMachine::apply()` utilisé partout → **KO** (F-02 : 11 call-sites inline).
- `DB::afterCommit` systématique → **KO** (convention "hors tx" implicite, F-09 ; F-04 transactions manquantes).
- `EventContract V1` respecté → **OK** via outbox + `assertEnvelopeValid` (DispatchDomainEventsJob:57).
- Idempotency POS `X-Idempotency-Key` + `Cache::lock` → **PARTIEL** (F-07 : POS sans Cache::lock, scope branche absent).
- Permissions Spatie avant cancel/refund/discount > seuil → **PARTIEL** (PaymentStatusRequest exige `Admin|Branch Manager|POS Operator` ; pas de seuil discount vérifié serveur).
- Audit log immutable → **PARTIEL** (`action_logs` présente L1 migration mais pas de colonne `hash`/chain ; cashBack ne loggue pas, destroy non plus).
- Z fiscal séquentiel signé non-supprimable → **KO** (aucune table `z_reports` / pas de migration ; fallback queue_number non séquentiel F-11).
- Tiroir-caisse ouverture loggée → **KO** (aucune table `cash_drawer` / aucune migration).
- Allergens visibles caissier → hors scope backend POS (traité kiosk).
- TVA cascade par `order_type` → **PARTIEL** (tax_id par item, cascade variation/extra non différenciée par order_type, rule manquante 10% sur-place vs 5.5% emporter).
- Multi-tenders supporté → **KO** (F-01 : colonnes uniques `pos_payment_method`, `pos_received_amount`).
- Temps réel Pusher + fallback polling → OK côté jobs (outbox + retry-failed), côté consumer hors scope.
- Dashboard tuiles scopées branche → hors scope section 4.

---

## 11. Matrice de recouvrement Kiosk ↔ POS

| Finding POS | Zone partagée | Kiosk impact |
|---|---|---|
| F-01 `changePaymentStatus` | `OrderService` — frozen zone V1 partagée | Kiosk utilise `finalizePaidKioskOrder` distinct → moins impacté, mais le refactor multi-tender affecterait le modèle `Order.payment_*` donc kiosk aussi |
| F-02 state machine bypass | `OrderStateMachine::apply` partagé | Kiosk aussi (FrontendOrderService:550, 661, 736) — à traiter en vague commune |
| F-07 idempotency scope | colonne `idempotency_key` partagée `orders`/`frontend_orders` | Kiosk déjà OK, POS à aligner |
| F-08 events absents | `EventContract::BROADCAST_MAP` partagé | Kiosk aussi consommateur — dispatch `OrderCancelled` profiterait aux deux |
| F-16 correlation_id | listeners partagés | Kiosk aussi |

---

## 12. Recommandation vagues

Si Phase POS-B est lancée, l'ordre logique serait :

1. **POS-9.1 stop-the-bleed** : F-01, F-04, F-06, F-22 (tous P0). Pas de code shared critique mais touche `Order` migration → coordonner avec Track A (Kiosk P9.1) via SYNC_PROTOCOL.
2. **POS-9.2 state-machine canonicalisation** : F-02, F-05, F-09 (refactor `changeStatus` + tests régression).
3. **POS-9.3 events canonicalisation** : F-03, F-08, F-15, F-16.
4. **POS-9.4 idempotency + tests** : F-07, F-17, F-21.
5. **POS-9.5 audit + conformité** : F-11, F-20, création tables `cash_drawers`, `z_reports`, `order_payments`.
6. **POS-9.6 parité kiosk/POS** : F-13, F-14.
7. **POS-9.7 cleanup** : F-10, F-12, F-18, F-19.

Chaque vague = gate PHPUnit + Vitest + build < 27 s + verifier indépendant.

---

**Fin du rapport.**
