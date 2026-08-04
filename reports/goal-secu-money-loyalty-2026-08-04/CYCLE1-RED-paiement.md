# CYCLE1 — RED adversarial : PAIEMENT EN LIGNE + ANNULATION (Mollie)

- **Repo backend** : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` HEAD `0649cb40d`
- **Mode** : READ-ONLY (aucune modif tracked ; 1 test throwaway écrit, exécuté, **supprimé**).
- **Posture Mollie observée** : `MOLLIE_ENABLED=true` + `MOLLIE_API_KEY` non vide → `isMollieConfigured()===true` ⇒ **le webhook Mollie traite réellement les events** (le funnel `web_payment_v1.enabled=false` gèle la CRÉATION de paiement web, mais PAS le webhook de remboursement/chargeback).

---

## VERDICT GLOBAL

**P0 restants = 0 · P1 restants = 1 · (P2 = 3)** → **NON CONVERGÉ.**

Le P1 **casse le cœur du correctif #2/#3** (remboursement/chargeback) : `payment_status` n'est **jamais** mis à `REFUNDED` et **aucun broadcast** n'est émis, car `processMollieRefund` dispatche `RefundCreated` avec un **`FrontendOrder`** alors que le listener qui porte la mutation exige un **`Order`**. Prouvé exécutablement.

---

## P1 — [NEW + RÉFUTE correctif #2/#3] Le remboursement/chargeback Mollie laisse la commande PAID (no-op silencieux)

- **file:line**
  - `app/Http/PaymentGateways/Gateways/Mollie.php:633` — `$order = FrontendOrder::withoutGlobalScope(BranchScope::class)->find($orderId);`
  - `app/Http/PaymentGateways/Gateways/Mollie.php:656` — `\App\Events\RefundCreated::dispatch($order);` (dispatch d'un **FrontendOrder**)
  - `app/Listeners/PersistOrderPaymentStatusChangedOnRefundCreated.php:72` — `if (! $order instanceof Order) { return; }` (garde qui **no-ope** sur FrontendOrder)

- **scénario**
  Chez Mollie, un remboursement/chargeback laisse le paiement `status=paid` et fait évoluer `amountRefunded`/`amountChargedBack`. Le webhook (Mollie.php:258-261) dérive correctement un statut effectif `refunded` sous une clé de dédup distincte `tr_x:refunded` (ce leg **tient**), puis route vers `processMollieRefund` (Mollie.php:622). Celui-ci **charge un `FrontendOrder`** (633) et dispatche `RefundCreated` avec cette instance (656).
  Or `FrontendOrder extends Model` et `Order extends Model` sont **frères** (tous deux `$table = "orders"`, PAS d'héritage). Le listener qui porte la mutation `payment_status=REFUNDED` + le broadcast `OrderPaymentStatusChanged` (`PersistOrderPaymentStatusChangedOnRefundCreated`) fait `return;` immédiat à la ligne 72 car `$frontendOrder instanceof Order === false`.
  **Résultat** : la commande **reste `payment_status=PAID`**, **aucun broadcast** temps-réel (POS/caisse/OSS n'apprennent jamais le remboursement, et un refetch la montre PAID), et si la commande était **scellée** (`fiscal_sequence_no` alloué via la branche web-card de `finalizePaidKioskOrder`) le **Z reste surévalué sans même un marqueur REFUNDED** — pire que le « Z faux » redouté : ni contre-écriture, ni statut, ni alerte, seulement un log `fiscal` `refund_recorded` **mensonger**.
  Effet secondaire aggravant (incohérence) : les 3 autres listeners du cascade **fonctionnent** sur un FrontendOrder — `ReleaseStockOnRefundCreated` (typehint `Model $order`, StockService.php:52), `ReleaseAvailabilityOnRefundCreated` (`method_exists($order,'orderItems')`), `ClawbackLoyaltyPointsOnRefund` (champs génériques) — donc stock/dispo/points **sont** repris pendant que `payment_status` reste PAID ⇒ commande « à moitié remboursée » qui se lit VALIDE/PAYÉE côté staff.

- **preuve**
  - Asymétrie Stripe↔Mollie = smoking gun : `Stripe.php` (branche `charge.refunded`) fait `$order = Order::find($orderId);` **puis** `if (! $order instanceof Order)` → dispatch d'un **Order** ⇒ listener actif (fonctionne). Mollie fait `FrontendOrder::find()` ⇒ listener no-ope.
  - Preuve exécutable de l'instanceof (autoload app réel) :
    ```
    FrontendOrder table: orders | Order table: orders
    FrontendOrder instanceof Order ? FALSE
    => PersistOrderPaymentStatusChangedOnRefundCreated:72 NO-OPs on FrontendOrder — payment_status NEVER set to REFUNDED
    ```
  - **Test déployé qui ENCODE le bug** (piège CLAUDE.md « un test vert peut encoder un no-op ») : `tests/Feature/Payment/MollieStructureTest.php:480` `test_webhook_refund_marks_order_refunded_and_is_not_swallowed` est **VERT** mais n'assert **jamais** `payment_status===REFUNDED` (il ne vérifie que le JSON `refund_recorded` + la clé de dédup `tr_x:refunded`, lignes 494-500). Le commentaire 497-498 *affirme* `payment_status→REFUNDED` sans l'asserter.
  - **Repro RED exécuté** (test throwaway, puis supprimé) : même fixture que `webCardOrder` (Order::factory sur table `orders`, PAID, `transaction_id='mollie:tr_REDPROOF1'`), webhook `paid` + `amountRefunded=11.80` →
    ```
    ⨯ mollie refund does NOT actually mark order refunded
    BUG: la commande reste payment_status=5 (PAID=5), PAS REFUNDED=20
    Failed asserting that 5 is identical to 20.
    ```

- **repro (rejouable)**
  1. `Config::set('payment.mollie.enabled', true)` + `api_key` factice.
  2. `Order::factory()->create([...source_surface=web, payment_method=CARD, payment_status=PAID, transaction_id='mollie:tr_X', total=11.80])`.
  3. `Http::fake(['.../payments/tr_X' => payload(status=paid, amount=11.80, metadata.order_id, amountRefunded=11.80)])`.
  4. `POST /api/webhook/mollie {id:tr_X}` → 200 `refund_recorded`.
  5. `assertSame(PaymentStatus::REFUNDED, (int)$order->fresh()->payment_status)` → **ÉCHOUE** (reste `PAID`).

- **sévérité** : **P1** aujourd'hui (le funnel de création web est gelé `web_payment_v1.enabled=false`, donc peu/pas de ventes web-card Mollie PAID en prod ⇒ peu de remboursements à traiter). **Devient P0** dès qu'une vente carte web Mollie réelle peut être remboursée/chargeback (Mollie live + funnel web activé G-W5) : chargeback ⇒ livres montrent PAID, cash-trail/Z faux, POS aveugle. Correctif attendu : charger `Order::find($orderId)` (comme Stripe) OU élargir la garde du listener — **décision owner (touche le cascade fiscal)**.

---

## Correctifs DISPUTÉS qui TIENNENT

### CORRECTIF-TIENT — #1 `cancelForFailedOnlinePayment`
`app/Services/FrontendOrderService.php:920-968`. Verrou `lockForUpdate` + idempotent (early-return si déjà `CANCELED`), gardes strictes `source_surface==='web'` **ET** `payment_method===CARD` **ET** `status===PENDING` **ET** `payment_status===UNPAID`. Pas de cashBack (aucun argent encaissé — cohérent). Impossible d'annuler une commande ACCEPTÉE/PAYÉE via un webhook retardataire. Non contournable observé.

### CORRECTIF-TIENT — #2/#3 (legs partiels) routage + auto-refund + reprise stock/points
Le **routage** refund (statut effectif `refunded`, clé de dédup distincte `tr_x:refunded` Mollie.php:258-262, évite l'ancien `duplicate_ignored` empoisonné), l'**auto-refund POST /refunds** (Mollie.php:581-612, terminal-order), et la **reprise stock/dispo/fidélité** fonctionnent. **SEUL** le leg `payment_status=REFUNDED` + broadcast est cassé → voir **P1** ci-dessus.

### CORRECTIF-TIENT — #4 R1 centralisé (accept carte web UNPAID → 422)
`app/Services/OrderService.php:2241-2246`. Chokepoint partagé : `pos-order`, `online-order`, `table-order` délèguent tous à `OrderService::changeStatus` (vérifié : PosOrderController, OnlineOrderController, TableOrderController injectent `OrderService`). Les **routes sœurs ne sont PAS un bypass** :
- **KDS** (`KitchenDisplaySystemOrderService::changeStatus:534`) : n'autorise QUE `ACCEPT→PREPARING→PREPARED` (`KitchenReleaseRule::canTransition:41-49`, jamais `PENDING→ACCEPT`) **et** exige `orderIsReleasedForBoard` (KitchenReleaseRule.php:100-121) qui exclut une carte web UNPAID (ni PAID ni PENDING_COUNTER, ni POS-cash) → 422 (ligne 589).
- **delivery-boy** (`OrderService::deliveryBoyOrderChangeStatus:1898`) : n'accepte pas dans la cuisine (commande assignée post-acceptation ; transitions livraison).
Vecteur « ACCEPT une carte web UNPAID » couvert partout.

### CORRECTIF-TIENT — #5 idempotency `mollie-checkout` REQUISE
`config/idempotency.php:48` (`api/frontend/order/*/mollie-checkout`) + `routes/api.php:1482` (`->middleware(['idempotency','throttle:10,1'])`). Middleware `IdempotencyKeyMiddleware:50-57` : `isRouteRequired()` (via `$request->is(pattern)`, ligne 176) + header vide → `MissingIdempotencyKeyException` (**422**). Header omis ⇒ plus de bypass silencieux (le double-débit `cardToken` = création=encaissement est fermé).

### CORRECTIF-TIENT — #6 P1-6 (client ne peut plus auto-annuler une commande PAID)
`app/Services/FrontendOrderService.php:839-841` : dans la transaction verrouillée, `if ((int)$locked->payment_status === PaymentStatus::PAID) throw 422`. Sur le status FRAIS verrouillé. Le remboursement en ligne redevient un geste ops (jamais un self-cancel sans remboursement).

### CORRECTIF-TIENT — #7 `inline=paid` seulement
`app/Http/PaymentGateways/Gateways/Mollie.php:170` : `'inline' => $cardToken !== '' && $checkoutUrl === '' && $mollieStatus === 'paid'`. Une carte refusée (`status=failed`, sans checkout_url) ⇒ `inline=false` → plus d'écran « payé » sur carte refusée. Le PAID reste scellé par le webhook.

---

## Nouveaux angles — findings P2

### P2 — [NEW] `amount_mismatch` sur `paid` : PAS d'auto-refund (asymétrie), argent capturé gardé
`app/Http/PaymentGateways/Gateways/Mollie.php:407-420` : sur montant≠total scellé, `markFailed` + `refusal='amount_mismatch_refused'` + 200, **sans** `refundMolliePayment` (contrairement au cas terminal-order qui, lui, auto-rembourse). Si l'event est capturé chez Mollie, l'argent est **gardé** (commande UNPAID, invisible caisse via R1, jamais remboursée).
**Reachability faible** : `$order->total` est scellé à la création (recalcul SSOT `PricingService`, pas d'édition post-création pour une commande web PENDING) → le montant Mollie (créé pour ce total) égale toujours le total au fetch. Défensif. **Recommandation** : symétriser (auto-refund sur mismatch comme sur terminal-order). P2.

### P2 — [NEW] Auto-refund terminal-order : échec silencieux non ré-essayé (argent gardé)
`app/Http/PaymentGateways/Gateways/Mollie.php:463` (`$event->markProcessed(...)` **dans** la transaction, AVANT le refund) + `581-612` (`refundMolliePayment` best-effort, catch-all, log `fiscal` seulement). Un échec **transitoire** du `POST /refunds` (blip réseau/5xx Mollie) est loggé puis avalé ; l'event étant `markProcessed` (≠ `failed`), le **cron DLQ** (`handleFromStoredEvent`, qui ne rejoue que les `failed`) **ne le ré-essaie jamais** → l'argent d'une commande annulée reste non remboursé, sans file de retry, seulement un log. **Recommandation** : enfiler un retry (ou marquer l'event pour re-drive) au lieu de logger seul. P2.

### P2 — [NEW/logique] Remboursement PARTIEL traité comme TOTAL
`app/Http/PaymentGateways/Gateways/Mollie.php:258-261` : tout `amountRefunded>0` (même 0,01 €) ⇒ statut `refunded` ⇒ intention `payment_status=REFUNDED` **totale** + **clawback fidélité total** + **release stock total** (un €1 remboursé sur €20 rembourse « tout » côté système). Déjà connu (backlog V1.0.2, commentaire `ClawbackLoyaltyPointsOnRefund:30-35`). Aggravé par le P1 (le leg payment_status est actuellement no-op, mais les legs points/stock sur-reprennent sur un partiel). P2.

---

## Angles DISPUTÉS puis RÉFUTÉS (tiennent)

- **REFUTED — double auto-refund via rejeu webhook** : le rejeu du `paid` retombe sur `tr_x:paid` déjà créé → `duplicate_ignored` (Mollie.php:286-294) ; le cas terminal fait `markProcessed` (≠ failed) → pas de re-drive DLQ. Un seul `POST /refunds`. La dédup `tr_x:paid` protège bien (comme demandé).
- **REFUTED — double cascade refund** : `processMollieRefund` idempotent (`already_refunded` 643-647) + dédup `tr_x:refunded`. (NB : le garde `already_refunded` ne se déclenche jamais puisque payment_status ne devient jamais REFUNDED — cf. P1 — mais la dédup webhook suffit à empêcher le double.)
- **REFUTED — course `cancelForFailedOnlinePayment` ∥ `finalizePaidKioskOrder`/paid** : les trois chemins prennent `lockForUpdate` sur la même ligne `orders` (Mollie.php:385, FrontendOrderService.php:924 & 1394) → sérialisés. Quel que soit l'ordre : soit le cancel voit PAID→ne fait rien, soit le paid voit CANCELED→auto-refund. État cohérent.
- **REFUTED — bypass R1 par KDS/table/livreur** : voir CORRECTIF-TIENT #4.

---

## Ce qu'il reste (owner / cycle suivant)
1. **P1** : aligner `processMollieRefund` sur Stripe (`Order::find`) OU élargir la garde `instanceof` — **gate owner** (cascade fiscal/NF525) + **ajouter l'assertion `payment_status===REFUNDED`** au test `MollieStructureTest:480` (sinon le bug reste encodé vert).
2. **P2** : symétriser l'auto-refund (amount_mismatch), enfiler un retry pour l'auto-refund transitoire, prorata partiel (V1.0.2).
