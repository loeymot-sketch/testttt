# Audit adversaire — SORTIE d'une commande SITE (web) : encaissement / clôture / refund

Portée : LOCAL, lecture seule, analyse statique + `git show` + tests. Frozen cités, jamais modifiés.

## Parcours confirmé
- Web TAKEAWAY COD créée `PENDING/UNPAID`, `source_surface='web'`. Accept (`OnlineOrderController::changeStatus`, l.162-184) flippe `PENDING_COUNTER` + pose le marqueur `pos_payment_method=COUNTER_DEFERRED` (atomique).
- Encaissement comptoir : `POST /api/admin/pos/counter-collect/{order}/confirm` (routes/api.php:932) → `PaymentService::confirmCounterPayment` **(chemin A, primaire)**. Alias : `collect-kiosk-cash/{order}` (:996 → `OrderService::collectKioskCash`:2953 → même service).
- Annulation : `counter-collect/{order}/cancel` (:976 → `cancelCounterPayment`:769).
- Chemin B (CASH-01, commit `246434458`) : « Encaisser & Valider » online = `OrderService::changePaymentStatus` (:2593) + `collect_counter_cash=true`, pour une web `UNPAID`→`PAID` jamais accept-flippée.
- DELIVERY web : scellée au doorstep (`deliveryBoyOrderChangeStatus`:2024-2048, escrow livreur) — hors tiroir comptoir, correcte.

## Verdict : 0 P0 · 0 P1 · 1 P2 · 1 P3

---

### [P2] PaymentService.php:389 (+ 92-215 / 673-700) — refund tiroir SANS entrée correspondante (asymétrie que CASH-01 a fermée sur le jumeau mais laissée ouverte ici)
**Cause.** `confirmCounterPayment` pose `pos_payment_method = $mode` **inconditionnellement** (l.389) *avant* d'écrire la ligne tiroir, qui est post-commit et **best-effort** (l.524-526 → `recordCashOrderMovement`, `strict=false`). Sans session tiroir ouverte : la vente passe `PAID` + `fiscal_seq` alloué + `Transaction` créée + `pos_payment_method=CASH`, mais **0 `CashMovement` IN** (`flagCashMovementSkipped`, l.657-660 ; le caissier est averti via `cash_movement_skipped`).
Au remboursement (`OrderService::changeStatus`→RETURNED, l.2414-2427) : `$locked->transaction` existe → `cashBack(gateway='cash')` car `pos_payment_method===CASH` → `recordCashBackMovement($order->total)` → **sortie tiroir = total, sans IN correspondant**. Le côté refund (`cashBack`:196-213, `recordCashRefundMovement`:688-699) ne vérifie **jamais** l'existence d'une ligne IN — la « symétrie refund (ne sort du tiroir que si une entrée existe) » annoncée dans le message de commit CASH-01 n'est PAS implémentée comme garde ; elle n'est vraie sur le chemin B que parce que B conditionne `pos_payment_method` (l.2788-2795 : `pos_payment_method=CASH` posé UNIQUEMENT si l'IN a été écrite → refund `gateway='credit'`, 0 sortie tiroir). Le chemin A (confirmCounterPayment) n'a pas ce conditionnement.
**Repro.** (1) web TAKEAWAY COD → Accept (`PENDING_COUNTER`+COUNTER_DEFERRED). (2) `confirmCounterPayment(CASH)` **sans session tiroir** → `PAID`, `pos_payment_method=CASH`, `Transaction`, `cash_movement_skipped=true`, 0 IN. (3) `changeStatus` RETURNED (avec `pos-refund`) **avec session** → `cashBack('cash')` → CASHBACK OUT = total. Session courante : `-total` sans IP jumelle → variance négative de rapprochement.
**Calibrage.** Le bug CASH-01 d'origine (P1) frappait CHAQUE encaissement web (ventilation `pos_payment_method=NULL` inconditionnelle). Le résidu ici est **conditionné** à une anomalie opérationnelle (encaissement hors session) + un refund ultérieur → strictement plus étroit ⇒ P2. Aucun vol / double-compte ; variance de tiroir honnête mais asymétrique.
**Correctif scope-minimal (non-frozen, `PaymentService`).** Garder la sortie tiroir sur l'existence d'un IN apparié — implémenter la symétrie annoncée : dans `recordCashRefundMovement` **et** la branche tiroir de `cashBack`, n'émettre le OUT que si
`CashMovement::withoutGlobalScopes()->where('order_id',$order->id)->where('type',TYPE_ORDER_PAYMENT)->where('direction',DIRECTION_IN)->exists()`.
Alternative équivalente : mirrorer CASH-01 dans `confirmCounterPayment` (poser `pos_payment_method=CASH` seulement si l'IN a réellement été écrit). La 1re option couvre aussi tout futur chemin no-IN.

---

### [P3] routes/api.php:976 `counter-collect/{order}/cancel` — gate `pos` et non `pos-refund` (note de cohérence, PAS un défaut)
`cancelCounterPayment` n'agit QUE sur `PENDING_COUNTER` (state machine : `REFUNDED` n'est légal que depuis `PENDING_COUNTER`), c.-à-d. une commande **jamais encaissée** (0 cash, `fiscal_seq=NULL`). Aucun mouvement d'argent ⇒ l'absence de `pos-refund` est **cohérente** avec la règle « gate seulement si `payment_status===PAID` » (cf. `OnlineOrderController::changeStatus`:127-137). Documenté comme conforme ; aucun correctif requis. Surveiller si un jour `cancelCounterPayment` devenait atteignable sur `PAID`.

---

## Airtight (vérifié — code + test)
- **Double-encaissement impossible.** `confirmCounterPayment` : `lockForUpdate` + court-circuit `payment_status===PAID` + discrimination par la ligne audit `order.counter_payment_confirmed` (même caissier → no-op 200 ; autre/inconnu → `PaymentAlreadyCollectedException`→409, non caché par idempotency 2xx). PaymentService:317-350. `confirm` et `collect-kiosk-cash` convergent sur le MÊME verrou.
- **Montant au centime = total scellé.** Transaction + CashMovement + contrôle `received<total` utilisent `$order->total` (DB) ; le front n'envoie que `mode/received/note` (routes:936-949). Chemin B idem (`round($order->total,2)`).
- **Clôture fiscale.** `fiscal_seq` alloué à CHAQUE scellage `PAID` (confirmCounterPayment:375-386, changePaymentStatus:2759-2771, doorstep:2053+), monotonic + unique. `ZReportService::aggregate` filtre `whereNotNull('fiscal_sequence_no')` **et** `payment_status != UNPAID` (ZReportService:425-426) → une web non encaissée (`fiscal_seq=NULL`) est **exclue** du Z, aucun gap/double. Ventilation tender `pos_payment_method ?: payment_method ?: 'unknown'` (:792) sûre (`CASH=1`, truthy ; `CASH_ON_DELIVERY=1` collapse même bucket). `PENDING_COUNTER→PAID` direct **bloqué** (changePaymentStatus:2735-2739 — anti vente off-book).
- **Refund d'une commande PAYÉE = chemin POS.** `PaymentStateMachine` : `PAID => []` terminal → `PAID→REFUNDED` impossible via change-payment-status. Le refund passe par `changeStatus`→RETURNED/CANCELED/REJECTED, gaté `pos-refund` (OnlineOrderController:116-137 + :205-211) + `SealedOrderGuard::assertMutable` (post-Z) + `cashBack`. Prouvé : `OnlineOrderRefundRequiresPosRefundTest` (403 sans droit, mutation nulle).
- **Chemin B (CASH-01) totalement symétrique** IN/OUT en session ET sans session (`pos_payment_method` conditionné → refund `credit`, 0 sortie fantôme). Anti-double 4 couches (no-op + idempotency + garde existence + cache).
- **Isolation branche.** Route-model binding sous `BranchScope` (staff→404 hors branche ; admin b0 bypass) + `assertCounterOrderVisible` (PaymentService:863-869) + garde branche `changePaymentStatus`:2625-2630.
- **Races collect/cancel/changeStatus.** Toutes sérialisées sur `lockForUpdate` de la même ligne ; le perdant échoue proprement (collect après cancel → `assertCanTransition(REFUNDED→PAID)` 422 ; cancel après collect → `assertCanTransition(PAID→REFUNDED)` 422 ; collect après CANCELED → garde statut terminal 422, PaymentService:363-367).
- **File / board release cohérents.** `counter-collect/pending` exclut CANCELED/REJECTED/RETURNED (routes:859) ; après `PAID` ou `REFUNDED` la commande quitte le filtre `PENDING_COUNTER`.
- Tests corroborants : `WebOrderCounterCollectableTest` (visible+encaissable+fiscal_seq), `PosCollectKioskCashRouteTest`, `QueueNumberConcurrencyTest` (séquence gap-free 50 créations).

## Frozen touchés par une cause (cités, LOCK owner requis si modif) 
`ZReportService.php` (ventilation:792, filtre:425), `PaymentStateMachine.php`, `AuditLogService`, `FiscalSequenceService` — aucun défaut trouvé, aucun changement proposé. Le correctif P2 est **hors frozen** (`PaymentService`).
