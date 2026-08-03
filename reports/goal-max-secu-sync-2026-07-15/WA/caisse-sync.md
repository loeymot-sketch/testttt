# Audit SYNCHRO CAISSE — outbox → soketi → KDS/OSS
2026-07-15 — sous-agent WA (caisse-sync). Périmètre : émission d'events par les chemins caisse (création / encaissement / annulation / suppression / remboursement / redeem), placement vs commit DB, canal/branche, file `high`.

## Verdict global
Architecture outbox **saine dans l'ensemble** : `DispatchableAfterCommit` sur tous les events order, persist listeners idempotents (`firstOrCreate` sur clé sha1), claim atomique anti-double-broadcast (`DispatchDomainEventsJob` lockForUpdate + dispatched_at), canal `private-branch.{branch_id}` dérivé de la commande partout, file `high` écoutée (worker local `--queue=high,default` PID vérifié + template supervisor `deploy/ansible/templates/supervisor-foodking.conf.j2:9`), KDS/encaissement **refetch-based** → doublon d'affichage impossible, perte bornée par polling (KDS 5s/60s, encaissement 20s). 5 défauts réels trouvés, prouvés ci-dessous.

## Findings (du plus grave au plus mineur)

### F1 — P2 — Event fantôme `OrderStatusChanged(ACCEPT→PREPARING)` à CHAQUE encaissement d'une commande différée POS/téléphone déjà en PREPARING
- **Où** : `app/Services/PaymentService.php:467-473` (`confirmCounterPayment`).
- **Mécanisme** : les commandes différées POS (`phone_order=true` ou `defer_to_counter`) sont créées DIRECTEMENT en PREPARING (`OrderService.php:774-780`, `AutoPrepareOnPaidPolicy::shouldPromote('pos', null, true)`=true par défaut). À l'encaissement, la garde post-commit teste **l'état final** (`$order->status === PREPARING`) au lieu de tester si la transition a eu lieu DANS cet appel (contrairement à la garde correcte de `recordTransition` l.396-397 qui exige `$prePaidStatus === ACCEPT`). Résultat : broadcast + ligne outbox `order.status_changed old=4 new=7` alors que la commande n'a JAMAIS été en ACCEPT.
- **Preuve live (DB prod locale)** : 8 occurrences. Ex. order **5554** (origin=phone) : `domain_events` id 10405 = `order.status_changed 4→7` @16:42:23, alors que `order_status_transitions` ne contient QUE `1→7 (auto_prepare_on_paid)` @16:31:49 et que le payload `order.created` porte `status=7`. Idem 5426/5425/5402/5403/5398/5396/5395 (origin=pos).
- **Repro** : POS → commande téléphone → `/admin/encaissement` → Encaisser (espèces) → `SELECT payload FROM domain_events WHERE aggregate_id=<id> AND event_type='order.status_changed'` → old_status=4 fantôme.
- **Impact** : journal d'events corrompu (les outils de parité/debug sync reconstruisent un faux passage ACCEPT), refresh KDS/OSS + tentative FCM parasites à chaque encaissement téléphone. Pas d'impact argent (KDS refetch SSOT).
- **Fix scope-minimal** : hisser `$prePaidStatus` hors de la closure (par référence, comme `$paid`) et garder le dispatch sur `$paid && $prePaidStatus === OrderStatus::ACCEPT && (int)$order->status === OrderStatus::PREPARING`.

### F2 — P2 — `destroy()` : `OrderCanceled` dispatché AVANT la transaction de suppression (libération de stock non-transactionnelle)
- **Où** : `app/Services/OrderService.php:2885` (dispatch) vs `:2891-2935` (DB::transaction du soft-delete + audit NF525).
- **Mécanisme** : au point de dispatch, `transactionLevel()==0` → `DispatchableAfterCommit` tire IMMÉDIATEMENT ; `ReleaseStockOnOrderCanceled` + `ReleaseAvailabilityOnOrderCanceled` sont **synchrones** (aucun ShouldQueue) → stock/quota libérés + ledger `released_qty` stampé AVANT le delete. Or `AuditLogService::write()` **throw** (RuntimeException lock de chaîne l.104, QueryException re-thrown l.190 — fichier `app/Services/Fiscal/AuditLogService.php`) → si la tx échoue, la commande reste VIVANTE avec son stock déjà relâché ; le retry de destroy ne re-libère rien (idempotent) mais l'incohérence stock↔commande vivante persiste (sur-disponibilité, faux niveau).
- **Repro** : provoquer un échec de la tx (contention `Cache::lock` audit-chain, ou kill DB entre dispatch et commit) pendant `DELETE /api/admin/pos-order/{order}` sur une commande ACCEPT/PREPARING → `stock_movements`/`item_branch_availability` montrent la libération, `orders.deleted_at` reste NULL.
- **Fix scope-minimal** : déplacer le `OrderCanceled::dispatch($order)` À L'INTÉRIEUR de la `DB::transaction` (l'event est after-commit ; `$order->loadMissing('orderItems')` reste appelé avant, le modèle mémoire conserve les lignes pour les listeners post-commit).

### F3 — P3 — `destroy()` n'émet AUCUN event broadcastable → KDS/OSS/encaissement affichent une commande supprimée jusqu'au poll
- **Où** : `app/Services/OrderService.php:2828-2943` — seul `OrderCanceled` (interne, « NOT broadcast », cf. `app/Events/OrderCanceled.php` docblock + `EventServiceProvider.php:194-197` sans listener outbox) est émis.
- **Repro** : créer une vente POS (carte KDS visible), `DELETE /api/admin/pos-order/{id}` → `SELECT COUNT(*) FROM domain_events WHERE aggregate_id={id} AND occurred_at > <t_destroy>` = 0 → la carte KDS reste affichée jusqu'au poll (60s WS-up / 5s WS-down, `KitchenDisplaySystemComponent.vue:2189`), file encaissement jusqu'à 20s.
- **Impact** : borné par polling, mais incohérent avec le chemin cancel (realtime). La cuisine peut continuer un ticket supprimé ~1 min.
- **Fix** : dispatcher un `OrderStatusChanged($order, $status, CANCELED)` (ou event dédié) dans la tx de destroy.

### F4 — P3 — `OrderPaymentStatusChanged` broadcast dans le vide : AUCUN front ne l'écoute
- **Où** : `resources/js/services/eventContract.js:18-29` — `BROADCAST_MAP` ne contient pas `OrderPaymentStatusChanged` ; `grep -rn OrderPaymentStatusChanged resources/js` = 0 abonné.
- **Contexte** : le heal backend WG-1-WF6-P1-1 (`app/Listeners/PersistOrderPaymentStatusChangedOnRefundCreated.php:22-41`) promet un « realtime refund signal » aux clients POS/admin/OSS — la moitié front n'a jamais été câblée. Chemins où c'est le SEUL signal émis : « marquer payé » (`OrderService.php:2660`) et remboursement post-Z miroir (`RefundWithCounterEntryService.php:465` → bridge listener). Les autres chemins de refund (cancel) émettent aussi `OrderStatusChanged` → couverts.
- **Repro** : admin → online-order → « marquer payé » (UNPAID→PAID) → `domain_events` reçoit `order.payment_status_changed` + soketi le publie, mais un 2e écran (tracker POS / liste) ne bouge qu'au poll/refresh.
- **Fix** : ajouter l'entrée à `BROADCAST_MAP` + abonner `EncaissementComponent` / `PosOrdersTrackerComponent` / liste online-order (handler = refetch, pattern existant).

### F5 — P3 — Redeem fidélité POS mute `total`/`discount` sans émettre d'event
- **Où** : `app/Services/Loyalty/PosRedemptionService.php:245-252` (UPDATE direct orders.discount/total), aucun dispatch dans la tx ; route `POST /api/admin/pos-order/{order}/redeem-loyalty` (`routes/api.php:1063-1065`).
- **Repro** : commande téléphone 20€ visible dans la file encaissement → redeem 500 pts (−5€) → la file sur un autre onglet affiche 20€ jusqu'au poll 20s ; le montant ENCAISSÉ reste juste (serveur relit `$locked->total` — `PaymentService.php:359`), défaut d'affichage uniquement.
- **Fix** : émettre un domain event (refetch) post-commit, ou documenter la dépendance au poll.

## Attestations (vérifié, pas de défaut)
- `posOrderStore` : `OrderCreated` DANS la tx (after-commit) `OrderService.php:1364` ✓ ; rollback → zéro ghost KDS.
- `confirmCounterPayment` : `OrderPaidAtCounter` post-commit `PaymentService.php:441` ✓ ; replay même-caissier → pas de re-dispatch ($paid=false) ✓ ; caissier différent → 409 typé ✓.
- `cancelCounterPayment` : `OrderCanceled` + `OrderStatusChanged` post-commit `PaymentService.php:768-771` ✓ (+ refund points idempotent en tx).
- `changeStatus` (POS/admin) : dispatch post-tx avec état re-lu (`setRawAttributes`) `OrderService.php:2415` ✓ ; garde no-op concurrent ✓.
- `changePaymentStatus` : event en tx via after-commit `OrderService.php:2660` ✓ ; le chemin `$auth=true` sans event est **mort** (aucun caller — routes uniquement admin).
- Canal : `private-branch.{order->branch_id}` dans les 3 persist listeners ✓ (pas de fuite cross-branche).
- File `high` : job `onQueue('high')` + worker local actif (pgrep : `queue:work redis --queue=high,default`) + supervisor template + cron `foodking:outbox:{rescue,monitor,retry-failed}` planifiés (`app/Console/Kernel.php:40-68`) ✓.
- Doublon KDS impossible par construction : handlers = `_debouncedRefresh()` (refetch liste serveur), dédup `correlationId` côté `eventContract.js:425`.
