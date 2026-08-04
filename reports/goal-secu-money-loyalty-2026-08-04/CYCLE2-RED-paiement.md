# CYCLE2-RED — PAIEMENT EN LIGNE + ANNULATION (Mollie)

**Audit adversarial READ-ONLY — 2ᵉ passe de convergence**
HEAD backend `ae4b27033` · date 2026-08-04 · domaine : Mollie checkout / webhook / refund / cancel
Suite `MollieStructureTest` : **19/19 GREEN** (111 assertions) au moment de l'audit.

---

## VERDICT

**P0 restants = 0 · P1 restants = 1 → NON CONVERGÉ.**

Le heal cycle-1 (refund Mollie flippe VRAIMENT REFUNDED via `Order` et non `FrontendOrder`)
**TIENT** et est prouvé vert. Mais un NOUVEL angle non couvert par le cycle 1 est confirmé
par repro exécutable : le re-drive DLQ **ressuscite une commande REMBOURSÉE en PAYÉE** et la
scelle dans le Z NF525.

---

## [P1] — DLQ re-drive ressuscite une commande REFUNDED → PAID (fausse recette NF525)

**Fichier** : `app/Http/PaymentGateways/Gateways/Mollie.php:710-713` (`handleFromStoredEvent`)
croisé avec `:437-476` (`processFetchedPayment`, chemin `paid`).

**Racine** : `handleWebhook` dérive un statut effectif `refunded` quand le paiement reste
`paid` mais `amountRefunded/amountChargedBack > 0` (`:258-262`). **`handleFromStoredEvent`
n'applique PAS cette dérivation** — il passe le `status` brut (`$fresh['status']` = `'paid'`)
à `processFetchedPayment`. Or le chemin `paid` de `processFetchedPayment` **n'a aucune garde
« déjà REFUNDED »** : `:437` ne teste que `=== PAID`, `:449` ne teste que les statuts commande
terminaux `[CANCELED, REJECTED, RETURNED]`. Une commande `payment_status=REFUNDED` +
`status=PENDING` (l'état exact laissé par `processMollieRefund`, qui ne touche jamais
l'OrderStatus) tombe donc en `:470` → `payment_status=PAID` + `finalizePaidKioskOrder`
(alloc `fiscal_sequence_no`, promotion cuisine).

**Scénario reproductible** (chaque maillon est réaliste — le DLQ existe justement pour le
maillon 1) :
1. Webhook live `tr_x:paid` échoue en TRANSITOIRE (deadlock dans la `DB::transaction`
   `:373-477`) → rollback → commande reste PENDING+UNPAID, event `tr_x:paid` = **FAILED**.
2. Un remboursement/chargeback tombe chez Mollie. Webhook live `tr_x:refunded` →
   `processMollieRefund` → commande **UNPAID→REFUNDED** (event `tr_x:refunded` = PROCESSED).
   L'event `tr_x:paid` reste FAILED (ligne distincte, jamais retouchée).
3. Le cron horaire `OutboxWebhookRetryFailedCommand` (→ `ProcessWebhookEventJob` →
   `handleFromStoredEvent`) rejoue `tr_x:paid`. Re-fetch Mollie = `paid` + `amountRefunded`
   plein → statut brut `paid` (pas de dérivation) → `processFetchedPayment('paid')` →
   commande REFUNDED+PENDING → **`:470` la re-scelle PAID + fiscal_sequence_no + cuisine.**

**Impact** : une vente **remboursée** est recomptée comme **recette PAYÉE dans le Z signé
NF525** (fausse recette, immuable), et une commande fantôme repart en cuisine. Money-path +
fiscal + opérationnel.

**Preuve exécutable** (test throwaway créé, exécuté, PASSÉ, puis supprimé —
`tests/Feature/Payment/ZZDlqRefundResurrectionReproTest.php`) :
```
test_dlq_redrive_of_stranded_paid_event_resurrects_refunded_order_to_paid
  → order.payment_status : REFUNDED(20) → PAID(5)   [assertSame PASS]
  → order.fiscal_sequence_no : null → non-null       [assertNotNull PASS]
OK (1 test, 2 assertions)
```
État initial du repro (tous atteignables en prod, cf. scénario) : commande web-carte
`payment_status=REFUNDED, status=PENDING, transaction_id=null, fiscal_sequence_no=null` ;
WebhookEvent `tr_DLQREF01:paid` en `STATUS_FAILED` ; fetch fake `paid` + `amountRefunded=11.80`.

**Correctif minimal suggéré (owner)** : (a) mirrorer la dérivation `amountRefunded>0 →
'refunded'` dans `handleFromStoredEvent` avant `processFetchedPayment` ; ET/OU (b) garde
défense-en-profondeur dans le chemin `paid` de `processFetchedPayment` : si
`payment_status === REFUNDED` → `markProcessed` + `already_refunded`, ne JAMAIS re-payer une
commande remboursée. NB : chemin frozen-adjacent (NF525) → gate owner.

---

## CORRECTIF CYCLE-1 DISPUTÉ → **TIENT**

**[CORRECTIF-TIENT] `processMollieRefund` charge un `Order`** — `Mollie.php:638-661`.
- `FrontendOrder` et `Order` partagent **la même table `orders`** (`FrontendOrder.php:19`
  `protected $table = "orders"` ; `Order.php:18` idem). Donc `Order::find($metadata.order_id)`
  où `order_id` = PK du FrontendOrder frappe **la même ligne** et passe la garde
  `if (! $order instanceof Order) return` (listener `:72`). Objection « Order::find viserait
  une autre commande » → **RÉFUTÉE**.
- Le listener `PersistOrderPaymentStatusChangedOnRefundCreated` flippe bien
  `payment_status=REFUNDED` (`:102`) sous lock (`:94-103`) et **broadcast**
  `OrderPaymentStatusChanged` (`:129`). `PaymentStatus::REFUNDED=20` est une const int
  (`app/Enums/PaymentStatus.php:10`) → les comparaisons `(int) === REFUNDED` sont correctes.
- **Scellé (fiscal_seq)** : `isMutable` s'appuie sur `SealedOrderGuard::assertMutable`
  (`:42` no-op si `fiscal_sequence_no===null` ; sinon exception seulement si un Z **CLOSED**
  contient `created_at`). Pré-clôture Z → mutation OK. Post-clôture Z → pas de mutation DB
  (invariant NF525 : ligne scellée immuable), **broadcast quand même** ; la contre-écriture
  (`RefundWithCounterEntryService`) reste un geste ops V1 documenté (`:656`). Conforme NF525.
- **Double cascade au rejeu** : clé de dédup distincte `tr_x:refunded` (`:270`) → rejeu =
  `duplicate_ignored` (`:286-294`) ; + garde `already_refunded` (`:648`). Test
  `test_webhook_refund_replay_is_idempotent` vert.
- **Test durci** : `test_webhook_refund_marks_order_refunded_and_is_not_swallowed`
  (`MollieStructureTest.php:480-501`) asserte désormais **`assertSame(REFUNDED, ...)`**
  (`:500`), plus seulement le routage. Vert.

**[CORRECTIF-TIENT] Cascade refund sur un `Order` — 4 listeners, pas de double-effet.**
Ordre `EventServiceProvider.php:219-233` : Persist → ReleaseStock → ReleaseAvailability →
Clawback, **tous try/catch isolés**. Le changement FrontendOrder→Order ne modifie que le
modèle passé (même ligne `orders`), pas le comportement des 3 autres listeners (déjà
source-agnostiques). Pas de double release stock sur `cancel→refund` : le ledger
`order_items.released_qty` (incrémenté par `AvailabilityService.php:974`) borne le stock
(`StockService.php:526` `remaining = quantity - released_qty` ; `:528` skip si delta≤0).
ReleaseStock tournant AVANT ReleaseAvailability dans les deux cascades, un `refund` après un
`order_canceled` lit `remaining=0` → no-op. Objection double-effet → **RÉFUTÉE**.

**[CORRECTIF-TIENT] `cancelForFailedOnlinePayment`** — `FrontendOrderService.php:920-968`.
Idempotent (`:926` return false si déjà CANCELED) ; ne cible QUE web-carte PENDING+UNPAID
(`:929-935`) ; refund points + release stock (`:937`, `:961`). Ne touche jamais une commande
ACCEPTÉE ou PAYÉE (`test_webhook_canceled_leaves_accepted_order_untouched`,
`test_webhook_canceled_never_touches_paid_order` verts).

**[CORRECTIF-TIENT] Auto-remboursement terminal `refundMolliePayment`** —
`Mollie.php:449-467` (capture) + `:483-485` (exécution POST-commit) + `:581-612`. Appel HTTP
hors `DB::transaction`, best-effort, **sans dépendance modèle** → le fix `Order` de
`processMollieRefund` ne le casse pas. Pas de double appel (dédup `tr_x:paid` + l'event refund
utilise `tr_x:refunded`). `test_paid_on_canceled_order_triggers_auto_refund` vert.

**[CORRECTIF-TIENT] Idempotency mollie-checkout** — `routes/api.php:1482`
`->middleware(['idempotency', 'throttle:10,1'])`. `test_checkout_same_idempotency_key_creates_single_mollie_payment`
prouve `Http::assertSentCount(1)` sur double POST même clé.

**[CORRECTIF-TIENT] `inline=paid` (P1-B)** — `Mollie.php:170` `inline` exige
`mollieStatus === 'paid'`. Carte refusée synchrone (failed, sans checkout_url) → `inline=false`
+ `reason=refused` (`test_checkout_refused_card_is_not_reported_inline_paid`, commande reste
UNPAID). L'écran « payé sur carte refusée » est fermé.

**[CORRECTIF-TIENT] Scellage fiscal vente carte WEB** — `FrontendOrderService.php:1369-1372`
(`$isWebCardOrder`) unifie le chemin borne-payée : une commande carte web payée obtient un
`fiscal_sequence_no` (`test_webhook_paid_web_card_order_is_sealed_and_accepted` : `assertNotNull`).

---

## P2 connus / documentés (ne bloquent PAS la convergence)

**[P2] Refund PARTIEL Mollie traité comme refund TOTAL.** `Mollie.php:260-261` déclenche
`refunded` dès `amountRefunded>0` ; `processMollieRefund:661` dispatch `RefundCreated::dispatch($order)`
**sans `refundedItems`** (= sémantique refund TOTAL). Conséquence : `payment_status=REFUNDED`
complet + clawback fidélité PLEIN + release stock PLEIN pour un remboursement partiel.
Cohérent avec la note V1.0.2 backlog de `ClawbackLoyaltyPointsOnRefund.php:30-35` (clawback
pro-raté différé). **Conservateur envers le client (aucun vecteur de vol)** — pas de P0/P1.

**[P2] Chargeback post-clôture Z : pas de contre-écriture NF525 auto.** Documenté
(`Mollie.php:656`) — `RefundWithCounterEntryService` = geste ops V1. NF525-correct (ligne
scellée immuable). Signalé via `Log::channel('fiscal') refund_recorded`.

**[P2 connu, task-acknowledged] `amount_mismatch` sans auto-refund.** `Mollie.php:407-420`
refuse le PAID (jamais scellé) mais **ne rembourse pas** les fonds éventuellement captés chez
Mollie (l'auto-refund n'est branché que sur le cas commande terminale). Reste geste ops manuel.

---

## Résumé chiffré

| Sévérité | Nombre | Détail |
|---|---|---|
| **P0** | **0** | — |
| **P1** | **1** | DLQ re-drive ressuscite REFUNDED→PAID (fausse recette Z NF525) |
| P2 | 3 | refund partiel=total · chargeback post-Z sans contre-écriture auto · amount_mismatch sans auto-refund |
| CORRECTIF-TIENT | 7 | heal cycle-1 + cascade 4-listeners + cancelForFailed + auto-refund terminal + idempotency checkout + inline=paid + scellage carte web |
| RÉFUTÉ | 2 | « Order::find vise une autre commande » · « cascade refund double-effet » |

**Convergence : NON (P0+P1 = 1).** Le seul bloquant est le P1 DLQ ci-dessus — chemin
frozen-adjacent NF525, gate owner requis pour le correctif.
