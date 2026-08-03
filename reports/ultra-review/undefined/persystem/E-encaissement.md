# Système E — ENCAISSEMENT (counter-collect / walk-in / borne Plan B)

HEAD audité : working-tree sur `61e9ea7b7` (heals de session appliqués, audités AS-IS).
Discipline : verify-before-report (file:line + repro lecture-seule). Aucune écriture DB/projet.

## Verdict : GREEN_WITH_NOTES

Le cœur d'encaissement est solide et cohérent avec l'intention V1 LOCAL. Un seul
défaut NOUVEAU (non présent dans les 22 findings triés) : incohérence entre le
heal de file (route) et le gate de scellement fiscal (service) sur `source_surface`
NULL. Latent (0 donnée actuelle), mais le heal n'atteint pas son objectif affiché.

## Invariants CONFIRMÉS tenus

1. **File counter-collect/pending** (`routes/api.php:807-853`) : exclut CANCELED
   (l.822), rattrape source_surface NULL (l.830-836), scope branch (l.840-843),
   FIFO created_at ASC, cap 200. `abort_unless(...can('pos'))` (l.808).
2. **confirm → PAID + fiscal seq** : `PaymentService::confirmCounterPayment`
   (l.193-462). `lockForUpdate` (l.220-223), allocation fiscale UNIQUEMENT ici si
   `fiscal_sequence_no === null` (l.335-337) — pas de double-alloc (borne payée =
   alloc à création ; counter-deferred = ici). Transaction + Transaction row
   `firstOrCreate` (l.390-401) + AuditLog `order.counter_payment_confirmed`
   (l.403-415) dans la même TX.
3. **Montant reçu validé** : CASH + received < total → 422 FR (l.329-332). Frontend
   `canConfirm` (PosCounterCollectModal.vue:289-290) exige `>= total` pour CASH/CARD
   → jamais de short-cash envoyé. `cashChange` (l.274-278) calcule le rendu.
4. **Modal Espèce/Carte/Mobile/Ticket** : `allowedModes` (l.203-209) = CASH/CARD/
   MOBILE_BANKING/OTHER/TICKET_RESTAURANT ; modal envoie CASH/CARD/MOBILE/TICKET
   (Modal:447-461). Mode invalide → 422 (l.211-215).
5. **Race deux caissiers** : same-cashier replay → 200 no-op (l.293-298) ;
   caissier différent / collecteur inconnu → 409 `payment_already_collected`
   (l.305-309), catché AVANT le fallback 422 (routes/api.php:874-891).
6. **Garde statut terminal** : CANCELED/REJECTED/RETURNED → 422, pas de charge ni
   de conso de séquence fiscale (l.323-327).
7. **Cash movement** : best-effort direction=in montant=total (l.456-457, 486-562) ;
   flag transient `cash_movement_skipped` si pas de session (l.587-590).
8. **cancelCounterPayment** : REFUNDED + CANCELED, aucune séquence fiscale allouée
   (jamais encaissé), rien dans le Z signé (l.635-704).

## NOUVEAU finding

### E-01 (P3) — Le heal « rattrape source_surface NULL » rend la commande VISIBLE mais PAS encaissable (incohérence file↔scellement)

- **Fichiers** : `routes/api.php:830-836` (rescue NULL dans la file) vs
  `app/Services/PaymentService.php:725-732` (`assertCounterDeferredOrder` exige
  `source_surface in ['kiosk','pos']`).
- **Repro (lecture seule, trace de code certaine)** :
  - `counter-collect/pending` surface une commande PENDING_COUNTER, non-CANCELED,
    `source_surface = NULL`, type KIOSK/TAKEAWAY (l.834-835). Elle apparaît donc
    dans la file caisse.
  - Au clic « Encaisser », `confirmCounterPayment` appelle
    `assertCounterDeferredOrder($locked)` (l.312) qui calcule
    `$surface = (string)(null) = ''` → `in_array('', ['kiosk','pos'])` = false →
    `throw new InvalidArgumentException('This order is not a pending counter payment.', 422)`
    (l.730-732). Le gate échoue AVANT même de tester `payment_method` /
    `pos_payment_method`, donc une commande NULL-source est **toujours** rejetée,
    quels que soient les autres marqueurs.
  - La route confirm catche via `catch (\Exception)` → HTTP 422 avec le message
    **anglais brut** « This order is not a pending counter payment. »
    (routes/api.php:892-894) affiché au caissier FR.
- **Preuve que le heal est incomplet** : le test
  `tests/Feature/Pos/CounterCollectQueueRobustTest.php:72-79`
  (`commande_borne_source_surface_null_reste_encaissable`) n'assert QUE la
  visibilité dans la file (`assertContains($nullSurface->id, $ids)`) — il n'appelle
  jamais l'endpoint confirm. Le nom du test (« reste encaissable ») sur-promet :
  la collecte réelle n'est jamais vérifiée et échouerait.
- **Impact** : c'est exactement le « fantôme qui 422 à l'encaissement » que le heal
  frère (exclusion CANCELED, l.820-822) visait à supprimer. Une commande borne
  héritée à source_surface NULL encombrerait la file et 422-erait (message anglais)
  à la collecte → INENCAISSABLE, contredisant le commentaire l.831-835 qui affirme
  « On la rattrape ».
- **Sévérité P3 (latent)** : `Order::where(payment_status=PENDING_COUNTER)
  ->where(status!=CANCELED)->whereNull(source_surface)->whereIn(order_type,[KIOSK,
  TAKEAWAY])->count()` = **0** sur foodking_e2e (vérifié tinker). `FrontendOrderService`
  pose `source_surface='kiosk'` à la création → seules des données héritées/migrées
  produiraient le cas. Recoupe le résidu data-hygiène NULL déjà trié, MAIS l'angle
  est différent : ici c'est l'INCOHÉRENCE CODE (file inclut ce que le scellement
  rejette), pas la donnée. À vérifier sur la donnée VPS (si des lignes NULL-source
  PENDING_COUNTER existent en prod, l'impact monterait à P2).
- **Fix suggéré (hors scope, non appliqué)** : soit accepter `''`/NULL dans
  `assertCounterDeferredOrder` avec le même filet type-kiosk que la file, soit
  retirer la clause rescue de la file. Cohérence file↔scellement requise, plus un
  message FR au lieu de l'anglais brut.

## Non re-signalés (garde-fous respectés)

- Montant CARTE non-structuré : `pos_received_amount` null pour CARD (l.341-343),
  reçu carte envoyé par le modal mais non persisté structuré → backlog compta, DÉJÀ TRIÉ.
- PosOrderRequest:117 `===` string/int → connu, laissé exprès.
- counter-collect closures dans routes → archi, DÉJÀ TRIÉ.
- Z-report by_terminal bucket NULL → FAUX-POSITIF confirmé antérieurement.
