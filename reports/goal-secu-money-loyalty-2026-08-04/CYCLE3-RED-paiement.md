# CYCLE3-RED — PAIEMENT EN LIGNE + ANNULATION (Mollie)

**Audit adversarial READ-ONLY — 3ᵉ passe de convergence**
HEAD backend `2ce5fc113` · date 2026-08-04 · domaine : Mollie checkout / webhook / refund / cancel / DLQ
Suite `MollieStructureTest` : **20/20 GREEN** (exécutée à l'instant de l'audit — preuve de convergence).

---

## VERDICT

**P0 restants = 0 · P1 restants = 0 → CONVERGÉ.**

Le heal cycle-2 (`2ce5fc113` : dérivation `refunded` dans `handleFromStoredEvent` + garde
`REFUNDED` dans le chemin `paid` de `processFetchedPayment`) **TIENT** sous les 3 disputes
prioritaires. C'est la **2ᵉ passe propre consécutive** sur le domaine Mollie (cycle-2 P1
DLQ→PAID fermé, aucun nouveau P0/P1 en cycle-3). Le cœur money/fiscal du paiement en ligne +
annulation est **airtight**.

Deux sibling paths NON-Mollie (`reconcile-pending`, `paymentConfirm`) n'ont pas de garde
`REFUNDED` explicite → **P2 défense-en-profondeur** (non atteignables pour le zombie
web-Mollie en exploitation normale ; le chemin d'encaissement réel — comptoir — est
DOUBLEMENT protégé). N'entrent PAS dans le compte P1.

---

## DISPUTE PRIORITAIRE — le heal cycle-2

### [CORRECTIF-TIENT] Dispute #1 — dérivation `refunded` AVANT `processFetchedPayment` dans le DLQ
**Fichier** : `Mollie.php:727-737` (dérivation) → `:739` (appel).

- La dérivation `amountRefunded/amountChargedBack > 0 → 'refunded'` (`:733-737`) s'exécute
  **AVANT** `processFetchedPayment($paymentId, $status, $fresh, $event)` (`:739`). Ordre correct.
- **Un `paid` légitime SANS remboursement rejoué via DLQ n'est PAS bloqué à tort** : si
  `refundedValue === 0`, `$status` reste `'paid'` → chemin `paid` → commande UNPAID/PENDING →
  scellée PAID normalement.
- **Preuve exécutable** : `MollieStructureTest::test_dlq_redrive_seals_a_previously_failed_paid_webhook`
  (`:448-472`) — event `tr_DLQ001:paid` FAILED, re-fetch `paid` sans refund → **PAID + fiscal_seq
  alloué** (`assertSame(PAID)` + `assertNotNull(fiscal_sequence_no)`). **GREEN.** La dérivation
  ne sur-bloque donc pas le cas nominal.

### [CORRECTIF-TIENT] Dispute #2 — garde `REFUNDED` couvre TOUTES les entrées (webhook direct ET DLQ)
**Fichier** : `Mollie.php:453-464` (garde) dans `processFetchedPayment`, funnel unique.

- `processFetchedPayment` est le **point de passage unique** appelé par `handleWebhook` (`:297`)
  ET par `handleFromStoredEvent` (`:739`). La garde `if ((int) $locked->payment_status ===
  PaymentStatus::REFUNDED) → refunded_order_not_repaid` (`:453`) est **à l'intérieur** → les deux
  entrées sont couvertes par construction.
- **Preuve exécutable** : `test_dlq_redrive_never_resurrects_a_refunded_order_to_paid` (`:555-578`)
  — commande `payment_status=REFUNDED, status=PENDING`, event `paid` FAILED, re-fetch
  `paid`+`amountRefunded=11.80` → **reste REFUNDED, `fiscal_sequence_no` reste NULL**. **GREEN.**
  Test NON no-op : l'`assertNull(fiscal_sequence_no)` échouerait si la résurrection PAID
  survenait (elle allouerait un numéro fiscal).
- **« Une commande REFUNDED+status non-terminal re-payée par un autre chemin ? »** → réponse
  détaillée en section P2 ci-dessous. Résumé : les chemins Mollie natifs sont gardés ; le seul
  chemin d'encaissement RÉEL d'une commande (comptoir `confirmCounterPayment`) est **doublement
  protégé** (state-machine + marqueur différé) ; les deux résidus (`reconcile`, `paymentConfirm`)
  ne sont pas atteignables pour ce zombie en exploitation normale.

### [CORRECTIF-TIENT] Dispute #3 — ORDRE des gardes non cassable
**Fichier** : `Mollie.php:437 → :453 → :466 → :487`.

Ordre dans le chemin `paid` (sous `lockForUpdate` `:385-388`) :
1. `:437` `payment_status === PAID` → idempotent (`alreadyPaid`, re-attache transaction_id).
2. `:453` `payment_status === REFUNDED` → `refunded_order_not_repaid`.
3. `:466` `status ∈ [CANCELED, REJECTED, RETURNED]` → auto-remboursement.
4. `:487` → PAID + `finalizePaidKioskOrder`.

- La garde **REFUNDED (2) précède la garde terminale (3)** : une commande REFUNDED **ET**
  CANCELED est refusée (`refunded_order_not_repaid`) **sans** re-déclencher un 2ᵉ auto-refund.
  Correct (déjà remboursée). Pas d'ordre cassable : REFUNDED et PAID sont des états mutuellement
  exclusifs de `payment_status` ; le scellage fiscal (`finalizePaidKioskOrder`) n'est atteint
  qu'au `:487`, jamais par une commande refusée en amont.

---

## RE-DISPUTE — les correctifs antérieurs TIENNENT

- **[CORRECTIF-TIENT] `processMollieRefund` charge un `Order` (pas `FrontendOrder`)** —
  `Mollie.php:655` `Order::find` → listener `PersistOrderPaymentStatusChangedOnRefundCreated`
  passe la garde `if (! $order instanceof Order) return` et flippe `payment_status=REFUNDED`
  (`listener:102`). Idempotent : `already_refunded` (`Mollie.php:665`) + garde `oldPaymentStatus
  === REFUNDED` du listener. Prouvé par `test_webhook_refund_marks_order_refunded_and_is_not_swallowed`
  (`:480`, assertSame REFUNDED) + `test_webhook_refund_replay_is_idempotent` (`:504`). GREEN.
- **[CORRECTIF-TIENT] auto-refund terminal** — `Mollie.php:466-484` (capture) + `:500-502`
  (exécution post-commit) + `refundMolliePayment:598`. Appel HTTP **hors** `DB::transaction`,
  best-effort, aucune dépendance modèle. `test_paid_on_canceled_order_triggers_auto_refund`
  (`:524`) GREEN.
- **[CORRECTIF-TIENT] idempotency** — checkout : middleware `idempotency` (`api.php:1482`),
  `test_checkout_same_idempotency_key_creates_single_mollie_payment` `Http::assertSentCount(1)`.
  Webhook : `webhook_events` UNIQUE (provider, `paymentId:status`) → dédup `duplicate_ignored`
  (`Mollie.php:286-294`). GREEN.
- **[CORRECTIF-TIENT] inline=paid (P1-B)** — `Mollie.php:170` `inline` exige `mollieStatus === 'paid'`.
  Carte refusée synchrone → `inline=false` + `reason=refused`. `test_checkout_refused_card_is_not_reported_inline_paid` GREEN.
- **[CORRECTIF-TIENT] finalizePaidKioskOrder idempotent** — allocation fiscale gardée
  `if ($locked->fiscal_sequence_no === null)` (`FrontendOrderService.php:1437`) : le chemin
  `alreadyPaid` qui ré-appelle `finalizePaidKioskOrder` (`Mollie.php:508`) ne double JAMAIS
  un numéro fiscal.

---

## NOUVEAUX ANGLES

### [REFUTED] Refund PUIS re-paid légitime (nouvelle commande, même client)
Une nouvelle commande = **nouveau `paymentId` Mollie** + **nouvelle ligne `orders`**
(`payment_status=UNPAID`). La garde `REFUNDED` est **par-commande** (lit `$locked->payment_status`
de LA ligne verrouillée). La nouvelle commande n'est jamais impactée. `paymentId` Mollie
étant unique par paiement, aucune réutilisation de `tr_x`. → **Aucun bug** (par conception).

### [REFUTED] Course DLQ ∥ webhook direct sur le MÊME paymentId
- Le chemin `paid` prend `lockForUpdate` sur la ligne commande (`Mollie.php:385-388`) et
  **relit** `payment_status` frais sous verrou → si le refund a commité d'abord, le DLQ `paid`
  lit `REFUNDED` (`:453`) → refusé.
- Si les deux threads re-fetchent après que Mollie a peuplé `amountRefunded`, **les deux**
  dérivent `refunded` → `processMollieRefund` idempotent (`already_refunded`).
- Si le `paid` scelle légitimement AVANT que le refund n'existe chez Mollie (aucun `amountRefunded`
  au fetch), le refund arrive ensuite comme `tr_x:refunded` et flippe REFUNDED : **vente scellée
  + remboursement enregistré = comportement NF525 CORRECT** (le Z ne se réécrit pas ; contre-écriture
  = geste ops documenté). → **Aucun P0/P1** (verrou + idempotence + dédup par statut).

### [P2 connu — NON re-listé P1] amount_mismatch sans auto-refund · refund partiel = total
`Mollie.php:407-420` refuse le PAID sur montant ≠ scellé (jamais scellé) mais ne rembourse pas les
fonds éventuels ; `Mollie.php:258-261` traite tout `amountRefunded>0` comme refund TOTAL. Connus,
conservateurs (jamais de vol client). Conformes au backlog V1.0.2. **Pas P0/P1.**

---

## [P2] Défense-en-profondeur — 2 sibling paths NON-Mollie sans garde `REFUNDED`

> Réponse dure à la dispute #2 « autre chemin ». Aucun n'est atteignable pour le zombie
> web-Mollie `REFUNDED+PENDING` en exploitation normale — d'où **P2, pas P1**.

**[P2-a] `PaymentReconcileController::reconcile`** — `PaymentReconcileController.php:207` (test PAID
seul) → `:228` (set PAID). **Pas de garde `REFUNDED`, pas d'appel `PaymentStateMachine`.**
- *Scénario* : une entrée `{order_id: <commande web REFUNDED+PENDING>, transaction_id, amount_cents=total,
  payment_method=CARD}` re-scellerait la commande PAID + `fiscal_sequence_no`.
- *Pourquoi P2 et non P1* : la file de réconciliation est **peuplée côté client par l'app kiosk
  Electron** à partir des transactions **TPE réellement approuvées sur CE kiosk** (`:20-44` docstring).
  Une commande **web-Mollie n'a jamais transité par le TPE kiosk** → **jamais** dans la file en
  exploitation normale. Atteignable uniquement via un client kiosk compromis/buggé fabriquant
  l'entrée (token `kiosk:order` de confiance requis `:86-91` + branche identique `:151` + echo
  montant exact `:174-186`). Adversarial-avec-crédentiels, pas un flux naturel.
- *Preuve (inspection)* : `:189`, `:207` ne testent que `=== PAID` ; `:228` fait `payment_status = PAID`
  sans consulter `PaymentStateMachine` (contrairement au comptoir).

**[P2-b] `Frontend\OrderController::paymentConfirm`** — `OrderController.php:252` (PAID) + `:268`
(status ≠ PENDING) → `:274` (set PAID). **Pas de garde `REFUNDED`.**
- *Pourquoi quasi-REFUTED* : `:182` exige `frontendOrder->user_id === authenticatedUserId` où
  l'appelant DOIT être un `KioskMachine` (`:174-180`). Une commande **web** a `user_id = client`,
  jamais l'utilisateur kiosk → **403** avant toute mutation. Non atteignable pour une commande
  web-Mollie. Pour une commande kiosk, l'état `REFUNDED+PENDING` n'est pas atteignable (le kiosk
  n'utilise pas Mollie ; un `cashBack` post-paiement laisse le `status` avancé, pas PENDING → `:268`
  renvoie 422). Résidu théorique → durcissement futur.

**Correctif suggéré (hardening, gate owner — chemins fiscal-adjacents)** : ajouter, aux deux
chemins, la garde miroir `if (payment_status === REFUNDED) → refuse` (ou router `reconcile`/`paymentConfirm`
par `PaymentStateMachine::assertCanTransition` comme `confirmCounterPayment`). Non bloquant V1.

### Contre-preuve : le chemin d'encaissement RÉEL (comptoir) EST protégé
`PaymentService::confirmCounterPayment` — le seul chemin par lequel une commande web non-payée
s'encaisse légitimement à la caisse — est **DOUBLEMENT gardé** :
1. `PaymentStateMachine::assertCanTransition(payment_status, PAID)` (`PaymentService.php:359`) :
   `PaymentStateMachine::TRANSITIONS[REFUNDED] === []` → `REFUNDED → PAID` lève `InvalidArgumentException
   422`. Une commande REFUNDED ne peut PAS être encaissée.
2. `assertCounterDeferredOrder` (`:358`, corps `:899-927`) : exige `payment_method === CASH_ON_DELIVERY`
   **et** `pos_payment_method === COUNTER_DEFERRED`. Une commande web-Mollie **CARD** échoue → 422.

Le `OrderService` COD/livraison (`:2027`, `:2049`) exige `status === DELIVERED` + livreur → hors
d'atteinte d'une commande web PENDING. `PaymentService::pay` (`:64`) exige un `assertGatewayContext`
(pile d'appel `PaymentAbstract`) — Mollie n'y passe pas.

---

## Résumé chiffré

| Sévérité | Nombre | Détail |
|---|---|---|
| **P0** | **0** | — |
| **P1** | **0** | — (cycle-2 P1 DLQ→PAID fermé, tenu sous 3 disputes) |
| P2 | 4 | reconcile sans garde REFUNDED · paymentConfirm sans garde REFUNDED (quasi-refuted) · refund partiel=total · amount_mismatch sans auto-refund |
| CORRECTIF-TIENT | 8 | heal cycle-2 (dispute #1/#2/#3) + processMollieRefund Order + auto-refund terminal + idempotency + inline=paid + finalize idempotent |
| REFUTED | 2 | refund→nouvelle commande re-payée · course DLQ∥webhook direct |

**Convergence : OUI (P0+P1 = 0).** 2ᵉ passe propre consécutive sur le domaine Mollie.
Preuve exécutable : `MollieStructureTest` **20/20 GREEN**, dont les 2 tests de régression
cycle-2 (`test_dlq_redrive_never_resurrects_a_refunded_order_to_paid`,
`test_dlq_redrive_seals_a_previously_failed_paid_webhook`). Les 4 P2 sont des durcissements
défense-en-profondeur non atteignables pour le zombie web-Mollie ; gate owner (chemins
fiscal-adjacents), non bloquants V1.
