# NUIT Wave A2 — Intersections cross-système (na2-cross-system)

HEAD ~86e3eee22 · DB foodking_e2e · posture refute-by-default · READ-ONLY

## Cible
Intersections kiosk→KDS→OSS→encaissement→ticket : 1 commande = 1 KDS + 1 OSS + 1 seq fiscale + 1 ticket.
Cas limites demandés : commande modifiée après envoi cuisine, annulée post-KDS, borne+caisse même queue_number,
composition_snapshot identique partout, parité symboles, propagation source_surface.

## Attaques + verdicts (HELD-GREEN)

### 1. Collision queue_number borne+caisse — ROBUSTE
`OrderService::allocateQueueNumber` (2:3005) et `FrontendOrderService::allocateQueueNumber` (1:1059)
partagent le MÊME lock `queue_lock_<branch>_<businessDate>` + MÊME namespace `A%` + backstop
`orders_branch_business_date_queue_unique` avec retry (max 5) sur violation. Borne et caisse ne peuvent
pas se voir attribuer le même numéro sous concurrence.
LIVE : `dup fiscal rows: 0`. La seule collision queue en base = 3 lignes LEGACY
(id 429-431, business_date=NULL, format `A001`, 2026-05-29) antérieures à la contrainte ; NON reproductible
sous le code courant qui force toujours `business_date` via `resolveBusinessDate` (jamais NULL). REJETÉ comme bug courant.

### 2. Annulation post-KDS — ROBUSTE
`KitchenReleaseRule::visibleStatuses()`/`itemBoardStatuses()` = ACCEPT/PREPARING(/PREPARED). CANCELED n'y figure
pas → KDS `list()` (l.74) et `orderItems()` (l.508) l'excluent. OSS `list()` filtre `whereIn status
[PREPARING,PREPARED]` + branche advance `whereNotIn [DELIVERED,CANCELED]`. Une commande annulée disparaît des 2 boards.
`cancelCounterPayment` dispatche OrderCanceled + OrderStatusChanged → propagation cohérente.

### 3. Commande modifiée après envoi cuisine — NON-VECTEUR (par design)
Aucun endpoint « add-item / edit » sur commande existante (`PosOrderController` = show/index/destroy/changeStatus/
changePaymentStatus/reorderItems ; `reorderItems` construit un NOUVEAU panier, ne mute rien).
`composition_snapshot` immuable (OrderItem boot guard OrderItem.php:51 + trigger DB `add_composition_snapshot_immutability_trigger`).
Le contenu envoyé cuisine ne peut pas diverger du ticket/fiscal après création.

### 4. composition_snapshot identique partout — ROBUSTE
Ticket ESC/POS (`OrderReceiptEscPosRenderer` l.115/259/350 → `$oi->composition_snapshot`),
KDS (`KDSOrderItemsResource` l.54/69/83 → snapshot.lines/extras/addons),
OSS/POS (`OrderItemResource` l.36/77 → snapshot). Trois surfaces lisent la MÊME source immuable. Parité garantie.

### 5. Double-encaissement / double-ticket — ROBUSTE
`confirmCounterPayment` (PaymentService.php:193) : `lockForUpdate` + `assertCounterOrderVisible` +
discrimination replay-même-caissier (no-op 200) vs race-loser (`PaymentAlreadyCollectedException`→409 non-caché).
Routes counter-collect confirm/cancel sous middleware `idempotency`. OrderPaidAtCounter (ticket) dispatché 1×.

### 6. source_surface — ROBUSTE avec filet
`counter-collect/pending` (api.php:807) rattrape les commandes kiosk/takeaway PENDING_COUNTER dont
`source_surface` est NULL (donnée héritée) → jamais inencaissables. CANCELED exclu de la file.

### 7. Fiscal — ROBUSTE
LIVE : 0 doublon `(branch_id, fiscal_sequence_no)`. Allocation monotone gap-free confirmée.

## Conclusion
CONVERGENCE. 2e passage profond des intersections : 0 nouveau bug reproductible (P0/P1/P2).
Toutes les invariantes cross-système tiennent dans les cas limites testés.
1 note data-hygiène P3 (legacy NULL business_date pré-contrainte, non reproductible, non-actionnable sans backfill).
