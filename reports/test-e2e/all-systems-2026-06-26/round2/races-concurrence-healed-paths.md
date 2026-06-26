# Round 2 — Attaque : Races / concurrence sur les chemins healés

**Lane** : Re-attaque concurrence sur les chemins touchés par les heals (10e462149 + 4fe7c2a7f).
**Mode** : READ-ONLY strict — aucun burst réel, aucune commande placée. Raisonnement code + Read + SELECT live `foodking_e2e` + schéma.
**Date** : 2026-06-26
**Verdict global** : 3 HOLD (a,b,d) + **1 NEW_FINDING P1** (c — collect sur commande terminale).

---

## (a) Double counter-collect simultané (même PENDING_COUNTER) → HOLD

**Défense prouvée.** `PaymentService::confirmCounterPayment` (`app/Services/PaymentService.php:219-330`) :
- `DB::transaction` + `Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail()` (l.220-223) → sérialise les deux caissiers au niveau row MySQL.
- Après acquisition du lock, relit `payment_status` : si `=== PAID` (l.278) → discrimine via la row audit `order.counter_payment_confirmed` : même caissier = no-op 200 (l.293-298), caissier différent = `PaymentAlreadyCollectedException` → 409 `error_code: payment_already_collected` (route closure `routes/api.php:858-875`, catch typé AU-DESSUS du fallback 422).
- Intégrité : un seul fiscal_seq, une seule `Transaction(payment)` (`firstOrCreate`, l.376), une seule row audit, un seul `cash_movement`.

**Preuve test** : `tests/Feature/Payment/PosCounterCollectRaceProtectionSentinelTest.php` (4 tests) — service throws typé, route 409 + payload, jamais 200/resource pour le perdant, `fiscal_sequence_no` reste == 1, zéro row ajoutée par le caissier B. **Tient.**

## (b) Double bump KDS concurrent → HOLD

**Défense prouvée.** `KitchenDisplaySystemOrderService::changeStatus` (`app/Services/KitchenDisplaySystemOrderService.php:392-423`) :
- `DB::transaction` + `lockForUpdate()->firstOrFail()` (l.398-402).
- Garde optimiste `expected_status` : `$expectedFrom = (int)$request->input('expected_status')` comparé à la row LOCKÉE → si désaccord, `abort(409, 'Order status was updated elsewhere — please refresh the KDS.')` (l.423).
- Le recall (l.294-338) suit le même patron lockForUpdate + 409 `kds_recall_already_recalled`.

Miroir côté OrderService (`changeStatus` else-branch, `OrderService.php:2117-2137`) : lock + gate idempotent `(int)$locked->status === $toStatus`, prouvé par `tests/Feature/Orders/OrderServiceChangeStatusRaceSentinel.php` (exactement 1 transition + 1 ActionLog sous double clic). **Tient.**

## (d) Double allocation fiscale sous burst → HOLD

**Défense triple prouvée.** `FiscalSequenceService::next` (`app/Services/Fiscal/FiscalSequenceService.php:57-114`) :
1. `Cache::lock('fiscal_seq_b{branch}', 5)->block(3)` (l.65-74) — sérialise les checkouts concurrents.
2. À l'intérieur : `transaction()` + `Order::withoutGlobalScope(BranchScope)->withTrashed()->where('branch_id')->lockForUpdate()->max('fiscal_sequence_no') + 1` (l.97-103) — FOR UPDATE serialise au storage engine même si le cache tombe ; `withTrashed` empêche la ré-utilisation d'un n° soft-deleted (gap-free NF525).
3. Garde ultime DB : index UNIQUE `orders_branch_fiscal_seq_unique (branch_id, fiscal_sequence_no)` — **confirmé live** : `SHOW INDEX FROM orders` → `Non_unique=0`, migration `2026_04_22_000001`. **Tient.**

---

## (c) [P1] `app/Services/PaymentService.php:312-330` — collect sur commande terminale (CANCELED/RETURNED) : encaissement d'une commande annulée → vente fiscalisée fantôme

### Titre
`confirmCounterPayment` n'a **aucune garde sur le `status` de la commande**. Une commande passée en statut terminal (CANCELED=16 / RETURNED=22 / REJECTED=19) via le chemin **générique** `OrderService::changeStatus` garde `payment_status = PENDING_COUNTER` (15) → elle **reste dans la file « à encaisser »** ET **reste encaissable** → on alloue un `fiscal_sequence_no`, on enregistre du cash et on injecte la « vente » au Z, pour une commande que la cuisine traite comme annulée.

### Root cause — asymétrie entre les DEUX chemins d'annulation
- **Chemin propre** `PaymentService::cancelCounterPayment` (`PaymentService.php:621-682`) : pose `payment_status = REFUNDED` (l.652) **et** `status = CANCELED` (l.653). → quitte la file (filtrée sur PENDING_COUNTER) **et** bloque toute collecte ultérieure (au re-collect, `PaymentStateMachine::assertCanTransition(REFUNDED, PAID)` jette 422 — `PaymentStateMachine` : `REFUNDED => []`).
- **Chemin générique** `OrderService::changeStatus → CANCELED/RETURNED` (`OrderService.php:2185-2200`) : touche **uniquement** `status` (+ `reason`, + `cashBack` si une `transaction` existe). **`payment_status` reste PENDING_COUNTER.** → la garde sur laquelle `confirmCounterPayment` s'appuie (payment_status=REFUNDED) n'est jamais posée.

`confirmCounterPayment` ne vérifie que : branche (`assertCounterOrderVisible`), marqueurs deferred (`assertCounterDeferredOrder` = source_surface + CASH_ON_DELIVERY + COUNTER_DEFERRED, `PaymentService.php:700-718` — **pas le status**), et `payment_status` (`PaymentStateMachine`). Le `status` de commande n'est lu nulle part dans la méthode.

La file « à encaisser » (`routes/api.php:811-822`) filtre **uniquement** `payment_status = PENDING_COUNTER` (+ marqueurs source). **Aucun filtre sur `status`** → les commandes terminales y restent visibles.

### Repro (endpoints réels — NON exécuté, read-only respecté)
1. Commande borne Plan B → `PENDING_COUNTER`, `status=ACCEPT`, `source_surface=kiosk`, `payment_method=CASH_ON_DELIVERY`, `pos_payment_method=COUNTER_DEFERRED`.
2. `POST /api/v1/admin/pos-order/{id}/change-status {status:16 CANCELED, reason}` (générique, gate `permission:pos-orders` ; `ACCEPT→CANCELED` autorisé sans permission spéciale — `OrderStateMachine.php:52`). → `status=CANCELED`, `payment_status` **reste 15**, pas de cashBack (rien de payé).
   - Variante refund : `POST /api/admin/pos-order/{id}/refund-with-counter-entry` → non scellé → `refundPreZ → changeStatus(RETURNED)` (`ACCEPT→RETURNED` autorisé pour `pos-refund`, `OrderStateMachine.php:48`). Même résultat avec `status=RETURNED`. **Ce chemin RETURNED vient d'être touché par le heal 10e462149** (ajout du gate `can('pos-refund')`) — la couture de collectabilité en aval n'a pas été traitée.
3. `GET /api/admin/pos/counter-collect/pending` → la commande **apparaît toujours** (filtre payment_status seul).
4. `POST /api/admin/pos/counter-collect/{id}/confirm {mode:1 CASH, received:12}` → `confirmCounterPayment` : payment_status=15 (ni PAID ni REFUNDED) → `assertCounterDeferredOrder` passe → `PaymentStateMachine::assertCanTransition(15→5)` OK → **PAID + fiscal_seq alloué + Transaction(payment) + cash_movement + `OrderPaidAtCounter` dispatché**, `status` reste CANCELED/RETURNED.

**Résultat** : commande terminale = PAID, avec n° fiscal réel, cash enregistré, **incluse dans le Z signé**. Cuisine ne produit rien (annulée). Client débité sans service / dérive de caisse / pollution revenue+Z.

### Angle concurrence (ma lane — scénario c)
`confirmCounterPayment` ET `changeStatus` prennent tous deux `lockForUpdate` → les écritures **sérialisent** (pas de torn-write). Mais le lock ne protège **pas** l'incohérence inter-opération : ordre « cancel gagne puis collect » → état final CANCELED+PAID+fiscal_seq, **sans aucune garde**. Le heal lockForUpdate (a/b/d) ne couvre pas (c) : il manque une **précondition de status** dans `confirmCounterPayment`.

### Evidence
- `PaymentService.php:312-330` : aucune lecture de `$locked->status` avant l'allocation fiscale (Read intégral 219-448).
- `PaymentService.php:652-653` vs `OrderService.php:2199-2200` : asymétrie payment_status REFUNDED (posée seulement par le chemin propre).
- `routes/api.php:811-822` : file filtrée payment_status seul, pas de status.
- **Live `foodking_e2e`** : la file PENDING_COUNTER ne filtre pas le status (statuts présents 4/7/8/13 dont 35× DELIVERED=13 non collectées). Commandes `status=16 CANCELED & payment_status=5 PAID & fiscal_sequence_no NOT NULL` présentes (ex. id 946 fiscal 2445, 266 fiscal 2070, 265 fiscal 2069, … source kiosk) — cohérentes avec la couture (annulé-puis-encaissé ou inverse) ; donnée historique non attribuable à 100 % mais l'état « terminal + fiscalisé » est matérialisé en base.
- Aucun test ne garde le cas : `tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php` couvre branche/permission/cancel-propre, **pas** collect-sur-terminal. `PosCounterCollectRaceProtectionSentinelTest` couvre double-collect, pas collect-après-cancel.

### Lentille
Couture d'intégration entre deux chemins d'annulation (asymétrie de garde) + couture en aval du heal refund RETURNED (10e462149). Jumeau systémique : tout consommateur de la file PENDING_COUNTER qui fait confiance au seul `payment_status`.

### Sévérité — P1
Argent + cohérence-commande + cohérence-fiscale (vente fiscalisée pour une commande annulée/retournée, incluse au Z, client débité sans service / dérive caisse). Atteignable via endpoints réels, sans garde ni test. **Pas P0** : la chaîne NF525 n'est pas corrompue (le paiement encaissé est intégralement enregistré — fiscal_seq + audit + Z cohérent, aucun gap/tamper) ; nécessite une séquence à deux endpoints (cancel/refund puis collect).

### Reco (heal sûr, non-frozen)
`PaymentService.php` n'est PAS frozen (les frozen sont `PaymentComponent.vue` front + services fiscaux). Heal additif :
1. Dans `confirmCounterPayment`, AVANT l'allocation fiscale (après `assertCounterDeferredOrder`, ~l.312) : rejeter les statuts terminaux —
   `if (in_array((int)$locked->status, [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED], true)) { throw new \InvalidArgumentException('Commande clôturée — encaissement impossible.', 422); }`
2. Filtrer la file : `routes/api.php:811` ajouter `->whereNotIn('status', [16,19,22])` (hygiène queue).
3. Sentinel `tests/Feature/Payment/CounterCollectTerminalStatusGuardTest.php` : cancel-générique puis collect → 422 + 0 fiscal_seq + 0 Transaction ; idem RETURNED.

Fiscal-adjacent (la méthode alloue le fiscal_seq) → mérite le sentinel + revue avant merge, mais la garde est purement défensive/additive (throw avant toute mutation), sans toucher la logique de chaîne NF525.

---
## ⚖️ VERDICT SUPERVISEUR (verify-before-report sur la réfutation)
Le workflow-verify a RÉFUTÉ (c) comme « pas P1 NF525 » — CORRECT sur le Z (ZReportService:350-356 exclut les terminaux → 0 pollution revenue). MAIS le gap défensif est RÉEL : confirmCounterPayment ne lit pas status → une commande annulée reste encaissable (client débité + fiscal consommé). Re-vérifié moi-même (code 312-330 sans garde status ; 34 CANCELED+PAID+fiscal en base). **Re-classé P2 robustesse cash (pas P1, V1-LOCAL caissier-confiance + Z propre) et HEALÉ** : garde terminale dans confirmCounterPayment, TDD 7/7, régression 71/71, frozen 0. Leçon : une réfutation « pas P1 » ne vaut pas « pas un finding » — garder le gap réel.
