# CYCLE4-RED — PAIEMENT EN LIGNE + ANNULATION (Mollie)

**Audit adversarial READ-ONLY — passe de CONFIRMATION FINALE (4ᵉ cycle, indépendante)**
HEAD backend `827afae93` · date 2026-08-04 · domaine : Mollie checkout / webhook / refund / cancel / DLQ / front
Suite `MollieStructureTest` : **20/20 GREEN** (exécutée à l'instant de CE cycle — voir §PREUVE).

> Méthode : re-preuve **de zéro** des 7 invariants, sans faire confiance aux cycles 1-3.
> Chaque PAID-write de l'app a été énuméré (`grep`) et sa reachability pour une commande
> web-Mollie testée. Le **front** (repo Vercel `lecayenne-web-deploy`) a été audité — angle
> peu couvert par les cycles précédents. Scénarios NEUFS (combinaisons d'états, courses
> multi-webhooks, DLQ×refund×cancel, front vs serveur) chassés spécifiquement.

---

## VERDICT FINAL

**P0 restants = 0 · P1 restants = 0.**

C'est la **2ᵉ passe propre consécutive** sur le domaine Mollie (cycle-3 P0+P1=0, cycle-4 P0+P1=0,
audits indépendants). **CONVERGENCE MONEY-PATH PROUVÉE.** Le cœur paiement-en-ligne + annulation
est **airtight** : les 7 invariants tiennent sous des scénarios neufs, et le **front est honnête**
(jamais « payé » sans `payment_status===PAID` re-vérifié serveur).

Aucun nouveau P1 découvert. Les résidus restent strictement **P2** (défense-en-profondeur /
reachability-avec-crédentiels / backlog connu partial-refund & amount_mismatch) — NON comptés P1
conformément à la consigne.

---

## RE-PREUVE DES 7 INVARIANTS (de zéro)

### [INVARIANT-TIENT] #1 — Le webhook re-fetché est la SEULE source de PAID (jamais le body POST)
**Fichier** : `Mollie.php:203-262` (re-fetch) · `:270` (clé de dédup).
- Le POST ne porte qu'un `id` validé par regex stricte `^tr_[A-Za-z0-9]{5,64}$` (`:204`) → id malformé = 400 sans fetch (`test_..._rejects_malformed_id`).
- La vérité (status/montant/metadata) vient de `GET /v2/payments/{id}` authentifié par NOTRE clé (`:213-216`). Un id forgé/étranger → 404 chez Mollie → `unknown_payment_ignored` 200, **aucune mutation** (`:228-238`).
- **Propriété forte re-prouvée** : la clé de dédup `webhook_id = paymentId . ':' . $status` utilise le **`$status` FETCHÉ** (`:270`), pas un champ du POST. **Un attaquant ne peut donc pas forger un statut** (« canceled » sur un paiement `paid`) : le re-fetch renvoie toujours l'état réel. Aucun chemin ne marque PAID depuis le POST.

### [INVARIANT-TIENT] #2 — Refund/chargeback (amountRefunded>0) → REFUNDED (Order), jamais avalé
**Fichier** : `Mollie.php:258-262` (dérivation) · `:639-683` (`processMollieRefund`) · listener `:72-103`.
- `refundedValue = amountRefunded + amountChargedBack`; `status==='paid' && refundedValue>0 → 'refunded'` (`:260`). Clé de dédup **distincte** `tr_x:refunded` → jamais confondue avec `tr_x:paid` (fin du `duplicate_ignored` empoisonné).
- `processMollieRefund` charge un **`Order`** (`:655`, `App\Models\Order::find`) — PAS un `FrontendOrder` frère : le listener `PersistOrderPaymentStatusChangedOnRefundCreated` fait `if (! $order instanceof Order) return` (`listener:72`), donc un FrontendOrder aurait été un **no-op silencieux** (Z faux). Correct.
- `RefundCreated::dispatch($order)` (`:678`) → listener flippe `payment_status=REFUNDED` (`listener:102`) + broadcast + `ClawbackLoyaltyPointsOnRefund` + `ReleaseStock`/`ReleaseAvailability`.
- **Preuve exécutable** : `test_webhook_refund_marks_order_refunded_and_is_not_swallowed` — `assertJsonPath('refund_recorded')` + `assertDatabaseHas(webhook_id=tr_REFUND01:refunded)` + `assertSame(REFUNDED)`. **GREEN.**

### [INVARIANT-TIENT] #3 — Une commande REFUNDED n'est JAMAIS re-payée (webhook direct ET DLQ)
**Énumération EXHAUSTIVE des PAID-writes de l'app** (`grep "payment_status.*PaymentStatus::PAID"`), reachability testée pour une commande **web-Mollie REFUNDED** :

| Site | Chemin | Atteignable web-Mollie REFUNDED ? | Garde |
|---|---|---|---|
| `Mollie.php:487` | webhook paid | **OUI** (le seul) | **`:453` garde REFUNDED précède** → `refunded_order_not_repaid` ✓ |
| `PaymentService.php:394` | `confirmCounterPayment` | encaissement comptoir | **`:359` `PaymentStateMachine::assertCanTransition(_,PAID)`** → `TRANSITIONS[REFUNDED]===[]` (`PaymentStateMachine:18`) → 422 ✓ + `assertCounterDeferredOrder` exige COD (carte web=422) |
| `OrderController(Frontend):274` | `paymentConfirm` | NON — exige `KioskMachine` owner (`:174-182`) ; web order `user_id=client` | (P2-b) |
| `PaymentReconcileController:228` | reconcile TPE | NON — exige `tokenCan('kiosk:order')` (`:87`) + `KioskMachine` (`:93`) + `transaction_id` TPE réel ; web-Mollie n'a jamais transité le TPE borne | (P2-a) |
| `OrderService:835` | création | non — new order UNPAID, pas un re-pay | — |
| `OrderService:2027/2049` | COD@doorstep | NON — exige `status===DELIVERED` + livreur, carte≠COD | — |
| `PaymentService:64` | `pay()` | NON — `assertGatewayContext` (pile `PaymentAbstract`), Mollie n'y passe pas | — |
| `UberWebhookController:210` | Uber prépayé | NON — source Uber, pas web-Mollie | — |

- Le **DLQ** (`handleFromStoredEvent:693-740`) et le **webhook direct** (`handleWebhook:297`) convergent tous deux vers **`processFetchedPayment` — chokepoint unique** → la garde REFUNDED (`:453`) couvre les DEUX entrées par construction. `handleFromStoredEvent` re-dérive AUSSI `refunded` (`:735`) → double filet.
- **Preuve exécutable** : `test_dlq_redrive_never_resurrects_a_refunded_order_to_paid` — REFUNDED + event `tr_RESUR01:paid` FAILED + re-fetch paid+refund → **reste REFUNDED, `fiscal_sequence_no` NULL**. **GREEN.** (Test non no-op : l'`assertNull(fiscal_sequence_no)` casserait si résurrection.)
- **`PaymentService:466` n'est PAS une mutation** : c'est un champ `payload` d'`AuditLogService::write` (valeur de log). Faux positif écarté.

### [INVARIANT-TIENT] #4 — Paiement échoué/annulé → PENDING+UNPAID annulée ; paiement sur commande terminale → auto-remboursé
**Fichier** : `Mollie.php:331-344` → `cancelForFailedOnlinePayment` (`FrontendOrderService:920-968`) · `Mollie.php:466-484`+`:500-502`+`refundMolliePayment:598`.
- Annulation gardée : UNIQUEMENT `source_surface='web' && payment_method=CARD && status=PENDING && payment_status=UNPAID` (`:929-934`). Une commande ACCEPTÉE/PAYÉE n'est **jamais** annulée par un webhook retardataire (`test_webhook_canceled_leaves_accepted_order_untouched`, `test_webhook_canceled_never_touches_paid_order` GREEN).
- Auto-refund terminal : capture dans la transaction, exécution **post-commit** (appel HTTP hors `DB::transaction`, `:500-502`), best-effort (échec loggé canal fiscal, jamais un 500). `test_paid_on_canceled_order_triggers_auto_refund` GREEN (refund appelé au montant scellé `11.80`).

### [INVARIANT-TIENT] #5 — Idempotency sur mollie-checkout ; carte refusée jamais « payé »
**Fichier** : route `api.php:1481-1483` (`->middleware(['idempotency', 'throttle:10,1'])`) · `Mollie.php:170` (inline).
- Route porte `idempotency` : même `X-Idempotency-Key` → rejeu de la réponse 2xx cachée. `test_checkout_same_idempotency_key_creates_single_mollie_payment` → `Http::assertSentCount(1)`. GREEN.
- `inline = cardToken!=='' && checkoutUrl==='' && mollieStatus==='paid'` (`:170`) — un refus synchrone (`failed`, sans url) → `inline=false` + `reason='refused'` (`MolliePaymentController:113`). `test_checkout_refused_card_is_not_reported_inline_paid` : `inline=false`, `reason=refused`, `payment_status` reste UNPAID, `fiscal_sequence_no` NULL. GREEN.

### [INVARIANT-TIENT] #6 — Client ne peut pas auto-annuler une PAYÉE ; caissier ne peut pas accepter une carte web UNPAID
**Fichier** : self-cancel web `FrontendOrderService:839-841` · garde accept centralisée `OrderService:2241-2246`.
- Le self-cancel WEB passe par route `api.php:1470` → `FrontendOrderController::changeStatus` → `FrontendOrderService::changeStatus` : garde **`if (payment_status===PAID) throw 422`** (`:839`). Vérifié : c'est LE chemin du client web (pas OrderService).
- Accept caissier : garde **CENTRALISÉE** (`OrderService:2241`) `targetStatus===ACCEPT && source='web' && CARD && UNPAID → 422` → couvre TOUTES les routes (pos/online/table). Une carte web PAYÉE est déjà promue par le webhook, ne passe pas ce garde.
- Contre-preuve re-encaissement REFUNDED : bloqué au comptoir par `PaymentStateMachine` (`:359`) — cf. table §#3.

### [INVARIANT-TIENT] #7 — Montant scellé backend ; garde echo au centime
**Fichier** : `Mollie.php:121` (création) · `:405-420` (webhook).
- Création : `'value' => number_format((float) $order->total, 2)` — `$order->total` scellé par PricingService, jamais un montant client. `test_checkout_creates_payment_from_sealed_backend_total` : `Http::assertSent` value=`11.80` (total DB). GREEN.
- Webhook : `expectedCents = round(total*100)`, `paidCents = round(amountValue*100)`, refus si `currency!=='EUR' || paidCents!==expectedCents` (`:407`). `test_webhook_amount_mismatch_is_refused` (99.99 vs 12.00) → `amount_mismatch_refused`, UNPAID, `fiscal_sequence_no` NULL. GREEN.

---

## FRONT — confirmation vs statut serveur réel (angle NEUF, repo Vercel)

### [INVARIANT-TIENT] Le front n'affiche « payé » qu'après re-vérification serveur `payment_status===PAID`
**Fichier** : `index.html:270-316` (retour Mollie) · `funnel.jsx:634-666` (inline) · `funnel.jsx:1039-1058` (copie confirmation).
- Retour `?order=` (hosted/3DS) : `index.html:294-315` **sonde `api.getOrder(oid)` jusqu'à 8×** et ne pose `mollieReturn:'paid'` **QUE si `Number(ord.payment_status)===5`** (`:297`). `status===16` (CANCELED par webhook) → panier RESTAURÉ + retour paiement + clé idem purgée (`:302-307`). Timeout → `'unpaid'`, **jamais** « payé ». **Le redirect seul ne prouve rien** — invariant tenu côté client.
- Inline : `funnel.jsx:637` pose `paidOnline:true` uniquement sur `co.inline` (serveur = `mollieStatus==='paid'`). `reason==='refused'` (`:646`) → message « Carte refusée », **return**, jamais confirmation. `503/502/réseau` → `cardFallback:true` (`:663`) annoncé.
- Copie confirmation (`funnel.jsx:1045-1057`) : « Paiement confirmé ✓ » ssi `mollieReturn==='paid' || paidOnline` ; sinon « Vérification en cours… » ou « Tu paies sur place » + bandeau explicite « ta carte n'a pas été débitée ». **Honnête dans les 3 états.**

---

## SCÉNARIOS NEUFS — combinaisons d'états / courses (tous REFUTED ou INVARIANT-TIENT)

### [REFUTED] Forge d'un statut via le body POST (« canceled » sur un paiement `paid`)
La clé de dédup + le routage lisent le `$status` **re-fetché** (`Mollie.php:251,270`), jamais le POST. Un POST forgé `tr_x` déclenche un GET qui renvoie l'état réel. Impossible de forcer une annulation/un statut. → Aucun bug.

### [INVARIANT-TIENT] REFUNDED **ET** terminale (CANCELED) — ordre des gardes
Chemin `paid` : PAID(`:437`) → **REFUNDED(`:453`)** → terminal(`:466`). La garde REFUNDED **précède** la terminale → une commande REFUNDED+CANCELED renvoie `refunded_order_not_repaid` **sans** déclencher un 2ᵉ auto-refund. Correct (déjà remboursée). Vérifié par lecture + `test_dlq_redrive_never_resurrects...` (REFUNDED prioritaire).

### [REFUTED] PAID + `expired`/`canceled` retardataire (double-webhook, statuts divergents)
Un paiement `paid` ne peut pas « expirer » chez Mollie ; et le re-fetch renvoie l'état réel. Adversarialement, un `paid` sur commande DÉJÀ PAID → `alreadyPaid` idempotent (`:437-447`). Un `expired`/`canceled` sur commande PAID → `cancelForFailedOnlinePayment` exige UNPAID (`FrontendOrderService:933`) → `false` → `ack_*`, reste PAID. → Aucune dé-vente.

### [INVARIANT-TIENT] pending → cancel → puis `paid` en vol (client a « annulé » mais la carte est débitée)
Commande annulée (status CANCELED, payment_status UNPAID) ; `paid` arrive → amount OK → PAID/REFUNDED skip → terminal(`:466`) → **auto-refund** (`terminal_order_auto_refunded`). Argent rendu, jamais scellé. Cf. `test_paid_on_canceled_order_triggers_auto_refund`.

### [REFUTED] Double auto-refund (fuite monétaire merchant)
Après auto-refund, l'event `tr_x:paid` est `markProcessed` (`:480`) → **hors DLQ** (le DLQ ne re-drive que les FAILED). Un rejeu du même POST → `duplicate_ignored` avant traitement (`:286-294`). Aucun 2ᵉ appel `/refunds`. → Pas de double refund.

### [REFUTED] Spoof cross-commande via `metadata.order_id`
Le metadata vient du payload **re-fetché avec NOTRE clé** ; un paiement créé avec une autre clé → 404 sur notre GET → ignoré. La garde duplicate-transaction (`:426-435`) empêche d'attacher `mollie:tr_x` à 2 commandes ; la garde montant (`:407`) exige que le total de la commande visée = montant payé. → Non exploitable.

### [REFUTED] Course multi-webhooks concurrents sur le même paiement neuf
`webhook_events` UNIQUE (provider, webhook_id) au plancher DB (`migration:83 uk_webhook_provider_id`) sérialise : un seul `wasRecentlyCreated=true`, l'autre catch `QueryException`→`already_processing` (`:279-282`). Le traitement prend `lockForUpdate` sur la commande (`:385-388`). → Un seul scellage.

---

## P2 — résidus (NON comptés P1, conformément à la consigne)

- **[P2-a] `PaymentReconcileController:228`** — pas de garde REFUNDED (`:189,:207` testent PAID seul). **Non atteignable** pour un zombie web-Mollie : exige `kiosk:order` + `KioskMachine` + `transaction_id` TPE réel (web-Mollie n'a jamais transité le TPE borne). Adversarial-avec-crédentiels-kiosk. Durcissement futur (gate owner, fiscal-adjacent).
- **[P2-b] `Frontend\OrderController:274` (`paymentConfirm`)** — pas de garde REFUNDED. **Non atteignable** : exige un `KioskMachine` propriétaire (`:174-182`) ; une commande web a `user_id=client`. Quasi-REFUTED.
- **[P2] Refund partiel traité comme TOTAL** (`Mollie.php:260` `refundedValue>0`) — conservateur (jamais de vol client), backlog V1.0.2.
- **[P2] `amount_mismatch` sans auto-refund** (`:407-420`) — non atteignable pour un paiement légitime (montant fixé par nous à la création). Backlog.
- **[P2] Webhook Mollie JAMAIS délivré** (0 réception) → commande reste UNPAID+PENDING (argent capturé chez Mollie). **Pas de fausse recette Z ni double-débit** : le caissier ne peut PAS accepter une carte web UNPAID (`OrderService:2241`), le DLQ ne couvre que les events REÇUS-puis-FAILED, et aucun cron ne sonde Mollie pour les webhooks jamais reçus. Mode de défaillance = commande bloquée nécessitant résolution manuelle (dashboard Mollie), reachability faible (Mollie ré-essaie agressivement 24h+). Défense-en-profondeur, non bloquant V1.
- **[P2 cosmétique] Badge « ✓ Envoyée en cuisine »** (`funnel.jsx:1083`) affiché **inconditionnellement** sur la confirmation, même pour un repli comptoir UNPAID (la commande n'est PAS en cuisine tant qu'UNPAID). Aucun impact money/Z ; incohérence UX mineure.

---

## PREUVE (exécutée dans CE cycle)

```
$ php artisan test tests/Feature/Payment/MollieStructureTest.php
PASS  Tests\Feature\Payment\MollieStructureTest
✓ checkout creates payment from sealed backend total and returns checkout url
✓ checkout with card token pays inline without sending client away
✓ checkout refused card is not reported inline paid
✓ checkout with card token surfaces 3ds step explicitly
✓ checkout same idempotency key creates single mollie payment
✓ checkout fails closed 503 when not configured
✓ checkout refuses foreign order and non card order
✓ webhook paid marks kiosk order paid via kiosk paid path and replay is idempotent
✓ webhook amount mismatch is refused
✓ webhook failed or canceled cancels pending unpaid web card order
✓ webhook canceled replay is idempotent
✓ webhook canceled leaves accepted order untouched
✓ webhook canceled never touches paid order
✓ webhook paid web card order is sealed and accepted
✓ dlq redrive seals a previously failed paid webhook
✓ webhook refund marks order refunded and is not swallowed
✓ webhook refund replay is idempotent
✓ paid on canceled order triggers auto refund
✓ dlq redrive never resurrects a refunded order to paid
✓ webhook fails closed 503 when not configured and rejects malformed id
Tests:  20 passed
Time:   4.18s
```

---

## Résumé chiffré

| Sévérité | Nombre | Détail |
|---|---|---|
| **P0** | **0** | — |
| **P1** | **0** | — (aucun nouveau ; cycle-3 tenu) |
| P2 | 6 | reconcile · paymentConfirm · refund partiel=total · amount_mismatch · webhook-jamais-reçu · badge cuisine cosmétique |
| INVARIANT-TIENT | 10 | 7 invariants + front + ordre-gardes REFUNDED/terminal + pending→cancel→paid |
| REFUTED | 5 | forge-statut · PAID+expired · double-auto-refund · spoof metadata · course multi-webhooks |

**VERDICT FINAL : P0 + P1 restants = 0.**
**Convergence money-path Mollie PROUVÉE sur 2 passes consécutives (cycle-3 + cycle-4, indépendantes).**
Preuve exécutable : `MollieStructureTest` **20/20 GREEN** ce cycle. Front (Vercel) audité et honnête.
Les 6 P2 sont des durcissements défense-en-profondeur / backlog connu — non atteignables pour un vol
d'argent ou une fausse recette Z en exploitation normale ; gate owner, non bloquants V1.
