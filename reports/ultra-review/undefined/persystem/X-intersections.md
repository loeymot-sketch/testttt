# X — INTERSECTIONS (chaîne bout-en-bout) — Ultra-review re-audit

HEAD `61e9ea7b7` · 2026-07-02 · verify-before-report STRICTE · lecture seule (0 écriture DB/projet)
Serveur live 127.0.0.1:8766 (foodking_e2e).

## Verdict : GREEN (production-perfect V1 LOCAL)

La chaîne borne→caisse→KDS→OSS→encaissement→fiscal est cohérente et sans doublage.
Aucun NOUVEAU défaut code. Tous les écarts observés = résidus de données e2e ou code retiré
(exclus par garde-fous).

## Invariants confirmés

1. **composition_snapshot = source unique, jamais recalculé cross-surface.**
   - Construit UNE fois à la création (`OrderService.php:526,990,1527` + `FrontendOrderService`),
     colonne immuable (`CompositionSnapshotBuilder`, docblock NF525 « NEVER re-written »).
   - Lu (jamais recalculé) par TOUTES les surfaces : `OrderItemResource.php:77-103` (API/POS),
     `KDSOrderItemsResource.php:54,69,83` (KDS items-board), KDS today-cards via
     `KitchenDisplaySystemOrderService`. Fallback legacy raw seulement si snapshot NULL.
   - Divergence cross-surface **impossible par construction** : une seule colonne DB, N lecteurs.

2. **Events via outbox → queue `high` → soketi, canal `private-branch.{id}`.**
   - `PersistOrderCreatedToOutbox` / `...StatusChanged` / `...PaidAtCounter` : `channel =
     ['private-branch.' . $order->branch_id]` (lignes 44 / 53 / 41). Live : 0 event avec
     canal non-`private-branch`.
   - Queue lane SSOT = constructeur du job (`DispatchDomainEventsJob:47 onQueue('high')`).
   - `broadcast_as` = OrderCreated / OrderStatusChanged / OrderPaidAtCounter — les 3 sont dans
     la whitelist `EventContract` V1 → passent `assertEnvelopeValid` (live : 0 contract_violation
     sur ces 3 types).

3. **Zéro doublage.**
   - Idempotency-key outbox : OrderCreated/PaymentConfirmed = `sha1(type|order_id)` (one-shot) ;
     StatusChanged = scopé correlation_id (revert admin légitime). Live : **0 order avec >1
     OrderCreated**, **0 order avec >1 PaymentConfirmed**.
   - Broadcast at-most-once : `DispatchDomainEventsJob` phase 1 = claim atomique sous
     `lockForUpdate` + garde `dispatched_at` → worker concurrent no-op silencieux.
   - 1 seq fiscal / encaissement : `confirmCounterPayment` alloue `fiscal_sequence_no` UNIQUEMENT
     si NULL (`PaymentService.php:335-336`), sous `Cache::lock`+`FOR UPDATE`, gap-free `withTrashed`
     (`FiscalSequenceService::next`). Replay même caissier = no-op 200 ; caissier concurrent = 409.
     Live : branche 1 = 2587 seq, **0 doublon**.
   - 1 ligne KDS / (item+compo) : merge par hash item_id+variations+extras(raw, écrites à la
     création)+addons(snapshot)+instruction+allergens_hash ; raw ET snapshot peuplés ensemble à
     la création → merge et affichage restent d'accord.

4. **Dégradation poll = no-data-loss.**
   - Broadcast best-effort (try/catch afterCommit) : la DomainEvent row est persistée AVANT le
     broadcast → cron `outbox:retry-failed` + poll par surface rattrapent. Prouvé live sur
     commande #5399 (créée aujourd'hui) : OrderPaidAtCounter+StatusChanged non-broadcastés
     (pas de worker dev) MAIS l'état est scellé (status=7, payment_status=PAID, fiscal_seq=2590,
     card) → le poll caisse/KDS le voit. Aucune perte.

## Écarts observés — NON reportés (garde-fous / résidu)

- **Gap seq fiscale branche 1** (2587 présents, max 2590 = 3 manquants) : orders e2e hard-deleted.
  Le CODE garantit gap-free (MAX+1, pas de consommation au rollback). Data-hygiène P3.
- **1 idempotency_key NULL dupliqué (23 rows)** : injections synthétiques
  `E2EStress/E2ESoakCommand` (aggregate_id 900001-950001). MySQL n'égalise pas les NULL ; les
  vrais listeners settent TOUJOURS la clé. Résidu test.
- **16 events `loyalty.balance_changed` contract_violation permanent** (2026-06-12/14) : émis par
  du CODE DEPUIS RETIRÉ — `grep balance_changed|LoyaltyBalanceChanged` sur app/config/database =
  **0 occurrence**, aucune classe `LoyaltyBalanceChanged` dans app/Events. Aucun code actuel ne
  produit ce type → pas un défaut live. Terminés via `$this->fail()` (dans failed_jobs, pas
  retentés). Data-hygiène P3.
  - NOTE (non-finding) : si un futur commit réintroduit un broadcast loyalty, il DEVRA être ajouté
    à la whitelist `EventContract` V1 sinon échec silencieux (fail-closed correct, mais muet).
- **#5399 = 0 order_items** : commande e2e sans items rattachés. Résidu test, pas un chemin code.

## Preuves (lecture seule)
- `tinker` : intégrité seq/branche, dédup domain events, format canal, undispatched split
  synthétique/réel.
- Reads : CompositionSnapshotBuilder, DispatchDomainEventsJob, Persist*ToOutbox (3),
  PrintFiscalReceiptAndOpenDrawerOnCounterPaid, PaymentService::confirmCounterPayment,
  FiscalSequenceService::next, KDSOrderItemsResource, KitchenDisplaySystemOrderService merge.
