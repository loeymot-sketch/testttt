# AUDIT READ-ONLY — Liaison commande↔caisse↔cuisine, commandes WEB carte (post-fix a80643441)

Date : 2026-08-03 · Contexte : owner a annulé un 3DS → commande restait PENDING en caisse.
Fix audité : `a80643441` (`cancelForFailedOnlinePayment` — webhook Mollie failed/canceled/expired
→ annulation web+carte+PENDING+UNPAID uniquement). Audit sur HEAD, zéro modification.

---

## Q1 — Une commande web CANCELED (16) disparaît-elle PARTOUT côté staff ? **[OK]** (+1 P3)

Aucun écran staff ne liste PENDING+CANCELED ensemble. Prédicats par surface :

| Surface | Prédicat statut | Preuve |
|---|---|---|
| Caisse — file « à encaisser » (backend) | `payment_status=PENDING_COUNTER` **ET** `whereNotIn('status',[CANCELED,REJECTED,RETURNED])` | `routes/api.php:854` + `:862` |
| Caisse — file « web à traiter » (backend) | `where('source_surface','web')->where('status', PENDING)` → 16 exclu | `routes/api.php:925-927` |
| Tracker caisse (frontend bucketing) | Aucune lane pour 16 (buckets = accept/preparing/prepared/onTheWay/delivered) ; la voie cash-pending est en plus gardée par `isTerminalStatus` | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:663-732`, garde `:691`, terminal set `:1269-1274` |
| Tracker — bandeau « anciennes à encaisser » | Compteur tiré de `admin/pos/counter-collect/pending` (exclut CANCELED, cf. ligne 862) | `PosOrdersTrackerComponent.vue` (`_refreshOlderPendingCount`) |
| KDS board V1/V2 (même endpoint list) | `whereIn('status', KitchenReleaseRule::visibleStatuses())` = [ACCEPT, PREPARING, PREPARED] | `app/Services/KitchenDisplaySystemOrderService.php:79-80` + `app/Domain/Kds/KitchenReleaseRule.php:16-23` |
| KDS delta-sync (poll incrémental) | CANCELED est poussé dans `deleted_ids` → **retrait actif** de la tuile côté store | `app/Services/KdsSyncService.php:51` (`$inactiveStatuses=[DELIVERED,CANCELED,REJECTED]`) + `:161-176` |
| KDS « historique du jour » | `whereIn([PREPARED, OUT_FOR_DELIVERY, DELIVERED])` — 16 exclu | `KitchenDisplaySystemOrderService.php:373-376` |
| OSS (admin + mur public) | `whereIn('status', [PREPARING, PREPARED])` — rémanence max 3 s (micro-cache) | `app/Services/OrderStatusScreenOrderService.php:63` et `:248` ; cache `OrderStatusScreenController.php:111-115` |
| Panneau « Commandes web » de PosComponent | Même endpoint `web-orders/pending` (PENDING only) | `PosComponent.vue:3847-3852` |

Cas précis du fix : la commande web carte annulée par webhook était PENDING+UNPAID → elle n'a
**jamais** été sur KDS/OSS (double barrière : statut hors `visibleStatuses` ET non payment-released
`KitchenReleaseRule::applyBoardReleaseFilter` `:130-140`). Elle disparaît du panneau web caisse et
de la lane « À encaisser » au prochain poll — et immédiatement sur event (le tracker refetch sur
`OrderStatusChanged`, `PosOrdersTrackerComponent.vue:896-908`).

- **[P3]** `stats.todayCount` (tracker `:833-837`) compte les CANCELED du jour dans « X aujourd'hui »
  alors que l'intention D-2 était « ne compter que le représentable sur le board » (une 16 n'a aucune
  lane). Cosmétique, aucun impact opérationnel.

## Q2 — Event dispatché SANS session HTTP (webhook → queue → broadcast) ? **[OK]**

- `OrderStatusChanged` est un plain domain event `DispatchableAfterCommit`, constructeur = (order,
  old, new), **zéro** Auth (`app/Events/OrderStatusChanged.php:15-24`). Idem `OrderCanceled`
  (`app/Events/OrderCanceled.php:17-23`).
- Listener unique : `PersistOrderStatusChangedToOutbox` (`app/Providers/EventServiceProvider.php:155-156`).
  Aucune référence `auth()`/`Auth::` ; la corrélation utilise `Log::sharedContext()` puis
  `request()?->header(...)` **null-safe** (`app/Listeners/PersistOrderStatusChangedToOutbox.php:120-136`).
  Le webhook Mollie EST un contexte HTTP (guest) donc `request()` existe ; en re-drive queue
  (`outbox:retry-failed`) le job ne relit jamais la request.
- Broadcast : DomainEvent persisté puis `DispatchDomainEventsJob` post-commit (`:74-117`) — canaux
  `private-branch.{id}` + `private-customer.{user_id}` pour une commande web (`:153-163`) → la caisse
  ET le client reçoivent l'annulation en live.
- `OrderStateMachine::recordTransition(..., null, $reason)` accepte l'acteur null (`actor_type` null)
  et `request()` null-safe (`app/Domain/Order/OrderStateMachine.php:148-171`).
- Grep `auth()|Auth::|request()` sur toute la cascade (trait DispatchableAfterCommit,
  DispatchDomainEventsJob, SendOrderMailNotification, SendOrderPushNotification, les 3 listeners
  stock) : **0 occurrence** → aucun crash possible en contexte webhook/worker.

## Q3 — Côté CLIENT : « Annulée », pas « en préparation » ? **[OK]**

- Backend : `OrderDetailsResource:91` expose `status` + `status_name = trans('orderStatus.'.status)` ;
  FR 16 = **« Annulée »** (`lang/fr/orderStatus.php:12`). Historique : `UserOrderResource:41` idem,
  et `FrontendOrderService::myOrder` (`:99-108`) n'exclut **aucun** statut → la commande annulée
  reste dans l'historique.
- Web déployé (`/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`) :
  - **TrackingPage** `funnel.jsx:1113-1125` : poll `/order/show/{dbId}` toutes les 20 s ;
    `st ∈ [16,19,22]` → `setCanceled(true)` + arrêt du poll. Rendu `:1193` bandeau « ANNULÉE »,
    `:1209-1211` « Cette commande a été annulée. Contacte-nous si besoin. », ETA masquée (`:1198`),
    jamais « en préparation ».
  - **OrdersPage** `orders.jsx:8-15` : `status_name` matché `/annul/` → catégorie `cancelled` →
    onglet « Annulées » (`:97`) + badge (`:123`).
  - Retour navigateur 3DS annulé : écran honnête « Paiement annulé — rien n'a été débité …
    commande NON envoyée » + panier restauré (`funnel.jsx:856-860`, commit web `66cf7e9`).

## Q4 — Fenêtre AVANT webhook (client au 3DS, PENDING+UNPAID carte) ? **[2× P2]**

**Le caissier PEUT accepter** : `web-orders/pending` liste PENDING sans filtre `payment_method`
(`routes/api.php:925-927`) ; le CTA « Accepter » du tracker ne teste que surface+PENDING
(`isWebPending`, `PosOrdersTrackerComponent.vue:1284-1288`) ; `OnlineOrderController::changeStatus`
ne bloque pas l'ACCEPT d'une carte — seul le flip `PENDING_COUNTER` est gaté COD
(`app/Http/Controllers/Admin/OnlineOrderController.php:162-165`) → la commande devient **ACCEPT+UNPAID**.

Croisements ensuite :

1. **Accept puis client annule le 3DS** → le webhook cancel REFUSE (garde `status===PENDING`,
   `app/Services/FrontendOrderService.php:918-923` ; prouvé par
   `tests/Feature/Payment/MollieStructureTest.php:350` accepted-untouched, `:364` paid-untouched —
   design assumé « décision humaine »). Résidu : **zombie ACCEPT+UNPAID** — invisible du board KDS
   (non payment-released, `KitchenReleaseRule:130-140`) mais affichée « EN PRÉPARATION » sur le
   tracker (bucket ACCEPT→preparing `PosOrdersTrackerComponent.vue:695-710`) et « Acceptée » côté
   client. Sortie uniquement manuelle : cancel staff (`online-order/change-status` CANCELED — non
   gaté refund car UNPAID, `OnlineOrderController.php:127-137`) ou self-cancel client (TAKEAWAY
   annulable jusqu'à PREPARING, `FrontendOrderService.php:820-828`). **[P2]** Signal incohérent
   (tracker dit cuisine, cuisine n'a rien) + aucune notification au caissier que le paiement a
   échoué. Reco : masquer/griser « Accepter » quand `payment_method=CARD` et paiement en vol, ou
   badge « paiement en ligne en cours ».

2. **Accept puis client PAIE** → webhook paid marque PAID (garde terminal-only
   `Mollie.php:421-435` ne bloque pas ACCEPT) → board release via PAID. Pas de double encaissement
   possible par le sceau comptoir : `assertCounterDeferredOrder` exige COD+COUNTER_DEFERRED
   (`app/Services/PaymentService.php:920-927`) → 422 sur une carte web ; et `confirmCounterPayment`
   refuse aussi les statuts terminaux (`:369-373`). **OK.**

3. **« Marquer payé » manuel PENDANT le 3DS** : arête UNPAID→PAID légale
   (`app/Domain/Order/PaymentStateMachine.php:10-11`) via `changePaymentStatus` — fiscal_seq alloué
   (`app/Services/OrderService.php:2761-2773`), pas de ligne tiroir pour une carte (garde
   `collect_counter_cash` COD-only `:2790-2797`). Si le client termine ENSUITE son 3DS : le webhook
   paid tombe sur PAID → branche `alreadyPaid` (`Mollie.php:409-419`) attache `transaction_id`
   mollie **en silence — aucun log** → la carte a réellement débité + l'encaissement manuel est déjà
   scellé = **double perception sans aucune alarme**. **[P2]** course étroite (2 actions humaines
   simultanées) mais money-path : reco = `Log::channel('fiscal')->warning` dans la branche
   `alreadyPaid` quand `transaction_id` était blank (paiement carte arrivé sur commande déjà payée
   par un autre moyen). Sens inverse (webhook d'abord) : PAID terminal (`PaymentStateMachine:17`) +
   no-op `:2654-2656` → rien à signaler.

## Q5 — Stock/BOM : release idempotent sur commande jamais acceptée ? **[OK]**

- Cascade `OrderCanceled` → `[ReleaseStockOnOrderCanceled, ReleaseAvailabilityOnOrderCanceled,
  ReverseRawMaterialsOnOrderCanceled]` (`app/Providers/EventServiceProvider.php:202-209`), chaque
  listener isolé try/catch (jamais de cascade coupée).
- **Jamais décrémentée ⇒ release NO-OP** : le release physique exige l'existence du mouvement
  `order_created` originel (`app/Services/Stock/StockService.php:546-549`, et
  `requireOriginalDecrement` `:123-125`) → zéro sur-libération pour une commande dont le stock n'a
  jamais été décrémenté.
- **Idempotence** : cap `deltaLineQty = min(demandé, quantity − released_qty)`
  (`StockService.php:526-530` ; `app/Services/Menu/AvailabilityService.php:905-908`) ; dédup
  `movementKey` (`StockService.php:551-559`) ; ledger `released_qty` incrémenté
  (`AvailabilityService.php:972-977`) sous UNIQUE ; rejeu webhook déjà coupé en amont
  (`webhook_events` UNIQUE `Mollie.php:249-267` + `cancelForFailedOnlinePayment` idempotent sous
  `lockForUpdate` `FrontendOrderService.php:910-917`).
- Pièges connus couverts : `withTrashed` sur le chemin destroy (`StockService.php:493-498`) ;
  remise en vente auto restreinte au 86-quota (`unavailable_reason='out_of_stock'`,
  `AvailabilityService.php:940-948`) — un 86 manuel n'est pas écrasé par l'annulation.

## Q6 — Fidélité : refundPoints au bon porteur ? **[OK]** (+1 P3)

- Le webhook cancel appelle `refundPoints($locked, 'kiosk')` AVANT la transition
  (`FrontendOrderService.php:925`) — même chemin et même tag que le cancel client canonique
  (`:840`) → parité totale.
- Grand-livre = source de vérité (P0-2 `d2ab26c48`) : remboursement **par ligne redeem à SON
  porteur** `loyalty_transactions.user_id` (`app/Services/LoyaltyService.php:35-42`), jamais en bloc
  au dernier code scanné. Symétrie de statut avec le débit — porteur identifié par ID, aucun filtre
  de statut destructeur (`:54-63`, P0-1). `lockForUpdate` (`:59-62`), NOOP idempotent via existence
  `manual_add` (`:89-100`) + UNIQUE(user_id, order_id, type).
- **[P3]** le reversal d'une commande WEB est tracé `source_surface='kiosk'` (tag hérité du miroir
  client-cancel) — imprécision d'audit-trail uniquement, les points vont au bon porteur.

---

## Observations adjacentes (liaison, hors périmètre strict)

- **[P1 — connu, gaté G-W5, à surfacer owner avant go-live carte]** Une commande WEB carte payée
  par webhook devient PAID **sans `fiscal_sequence_no`** : `finalizePaidKioskOrder` est gaté
  kiosk-machine (`FrontendOrderService.php:1334-1348` → no-op pour un user web) ; le webhook le
  logge fail-loud (`Mollie.php:463-475`, `fiscal_finalize_noop`) et le test l'acte
  (`MollieStructureTest.php:379` `..._leaves_fiscal_gap_flagged_for_gw5`). Depuis Mollie LIVE
  (2026-08-03), chaque paiement carte réel produit une vente PAID hors chaîne Z jusqu'à
  l'activation G-W5 (décision owner documentée, PAS un nouveau bug).
- **[P3]** Une web carte payée reste `status=PENDING` (aucune promotion au webhook) → toujours
  listée dans la lane « À encaisser » avec CTA « Accepter ». Flux voulu (l'opérateur accepte pour
  lancer la cuisine) mais le libellé de lane est trompeur pour une commande déjà payée.

## Verdict

**CONTINUE.** La chaîne d'annulation webhook est saine et complète : Q1/Q2/Q3/Q5/Q6 = **OK avec
preuves** (disparition partout côté staff, event sans dépendance session, client voit « Annulée »,
stock idempotent no-op si jamais décrémenté, points rendus au bon porteur du grand-livre).
Q4 = **2 P2 réels** à traiter en heal ciblé : (a) zombie ACCEPT+UNPAID après accept-pendant-3DS puis
annulation bancaire (bouton « Accepter » à gater/badger sur les cartes en vol) ; (b) branche
`alreadyPaid` du webhook silencieuse = double perception possible sans alarme (ajouter un warning
fiscal). Plus le **P1 adjacent connu** (fiscal noop web carte, activation G-W5) à re-surfacer à
l'owner maintenant que Mollie est LIVE.
