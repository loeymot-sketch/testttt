# AUDIT LIAISON PAIEMENT ↔ COMMANDE (Mollie) — matrice complète des états

- **Date** : 2026-08-04 (audit read-only, zéro modification de code)
- **Contexte** : plainte owner « paiement annulé = commande validée », corrigée par `a80643441`
  (`cancelForFailedOnlinePayment`). Mission : prouver qu'il ne reste AUCUN trou de liaison.
- **Fichiers pivots** :
  - `app/Http/PaymentGateways/Gateways/Mollie.php` (webhook + création paiement)
  - `app/Http/Controllers/Frontend/MolliePaymentController.php` (garde checkout)
  - `app/Services/FrontendOrderService.php` (`changeStatus` :784, `cancelForFailedOnlinePayment` :908, `finalizePaidKioskOrder` :1332)
  - `app/Jobs/CleanupStalePendingKioskOrders.php` (janitor, lane web :190-212)
  - Front déployé : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/` (`index.html`, `funnel.jsx`, `api.js`)

**Verdict global : le fix `a80643441` est SOLIDE sur sa cible** (annulation web+carte+PENDING+UNPAID,
verrouillée, idempotente, side-effects symétriques). **AUCUN P0** — pas de chemin nominal où une
commande non payée part en cuisine. **MAIS 4 P1 adjacents subsistent**, dont un qui REPRODUIT la
plainte d'origine par un autre chemin (P1-B inline).

---

## 1. LA MATRICE

Enums : OrderStatus PENDING=1 / ACCEPT=4 / PREPARING=7 / PREPARED=8 / OFD=10 / DELIVERED=13 /
CANCELED=16 / REJECTED=19 / RETURNED=22 · PaymentStatus PAID=5 / UNPAID=10 / PENDING_COUNTER=15 /
REFUNDED=20 (`app/Enums/OrderStatus.php`, `app/Enums/PaymentStatus.php`).

Routage webhook (`Mollie.php:298-325`) : `paid` = seul statut qui mute vers PAID ;
`failed|canceled|expired` → `cancelForFailedOnlinePayment` ; **tout le reste** (`open`,
`pending`, `authorized`, `unknown`…) → `ack_<status>` sans mutation.

| Statut Mollie fetché | × État commande | Traitement (file:line) | Verdict |
|---|---|---|---|
| `paid` | PENDING+UNPAID (web) | PAID + transaction_id (`Mollie.php:437-441`) ; `finalizePaidKioskOrder` **no-op web** (gate KioskMachine `FrontendOrderService.php:1334-1348`) → reste PENDING, fiscal NULL, warning `fiscal_finalize_noop` (`Mollie.php:463-475`, testé `MollieStructureTest.php:379-406`). Fiscal alloué plus tard au changeStatus caissier (`OrderService.php:2066-2069` / « marquer payé » `:2761-2765`) | **OK** (noop fiscal = comportement documenté G-W5, gaté owner) |
| `paid` | PENDING+PAID (rejeu) | `alreadyPaid` → backfill transaction_id si blank, re-ack (`Mollie.php:409-419`) | **OK** idempotent |
| `paid` | CANCELED/REJECTED/RETURNED | `paid_on_terminal_order` : PAS de PAID, warning fiscal SEULEMENT (`Mollie.php:421-434`) | **P2-B** (argent chez Mollie, zéro alerte ops — §5) |
| `paid` | même transaction sur AUTRE commande | refus `transaction_conflict` (`Mollie.php:398-407`) | **OK** |
| `paid` | montant ≠ total scellé | refus `amount_mismatch_refused`, jamais PAID (`Mollie.php:377-392`) | **OK** |
| `paid` | 2ᵉ paiement distinct, commande déjà PAID par le 1ᵉʳ | `alreadyPaid` re-ack **sans log ni alerte** de la 2ᵉ capture (`Mollie.php:409-419`) | **P3** (clé idempotence checkout déterministe `web-mollie-{id}` `api.js:706` + middleware `routes/api.php:1473` ferment quasi totalement la fenêtre) |
| `failed`/`canceled`/`expired` | PENDING+UNPAID web+carte | **ANNULÉE** (verrou+idempotent, `FrontendOrderService.php:908-956`), events board, release stock, refund points | **OK** — le fix owner. Rejeu : `:914-916` return false → ack (`MollieStructureTest.php:336-348`) |
| `failed`/`canceled`/`expired` | ACCEPT (caissier a accepté pendant le paiement) | garde `status!==PENDING` → false → `ack_` **silencieux, aucun log** (`Mollie.php:322-324`) | **P2-A** (§1 point dur — voir détail) |
| `failed`/`canceled`/`expired` | PAID / non-web / non-carte | garde `:917-923` → false → ack | **OK** (testé `:350-377` — jamais une ACCEPTÉE ou PAYÉE) |
| `open`/`pending`/`authorized`/inconnu | PENDING+UNPAID | `ack_<status>`, commande INTACTE (`Mollie.php:322-324`) | **OK avec réserve** — pas éternel : janitor lane web TTL 360 min (`CleanupStalePendingKioskOrders.php:187-212`). Réserve `authorized` → P3-a |
| refund / chargeback (webhook même `tr_`, fetch = `paid` + `amountRefunded`) | PAID (tout état) | **AVALÉ** : webhook_id `tr_x:paid` existe déjà → `duplicate_ignored` (`Mollie.php:253, 269-277`) ; même sans dedup → `alreadyPaid` re-ack. `amountRefunded`/`amountChargedBack` lus NULLE PART (seul Stripe le fait, `Stripe.php:421`) | **P1-A** |
| n'importe quoi | traitement CRASHE (deadlock…) | `markFailed` + 500 (`Mollie.php:281-292`) → le retry Mollie tombe sur la ligne existante → `duplicate_ignored` ; DLQ sans bras Mollie | **P1-C** |
| POST forgé / id inconnu | — | regex `tr_` + re-fetch authentifié = vérité ; 404 → ack forensique (`Mollie.php:197-232`) ; throttle 60/min (`routes/api.php:171`) | **OK** |

Ordre d'arrivée : le handler **re-fetche toujours l'état courant** chez Mollie → un webhook
retardataire converge vers la vérité (pas de régression paid→canceled possible côté Mollie).
Deux webhooks concurrents : TOCTOU `firstOrCreate` rattrapé par violation d'unicité →
`already_processing` (`Mollie.php:249-267`). **OK.**

---

## 2. LES 7 POINTS DURS

### Point 1 — statuts non listés, `open` éternel, fenêtre 15 min en caisse

**[OK] Pas de commande fantôme éternelle.** Tout statut hors {paid, failed, canceled, expired}
est ack sans mutation (`Mollie.php:302-325`), et le janitor a une **lane web dédiée** :
`CleanupStalePendingKioskOrders.php:190-212` — PENDING/ACCEPT/PREPARING × UNPAID/PENDING_COUNTER,
`source_surface='web'`, TTL `kiosk.stale_web_collect_ttl_minutes` (défaut **360 min**),
PENDING→REJECTED, ACCEPT/PREPARING→CANCELED, refund fidélité + release stock via le chemin
unifié (`:241-341`). Une commande PAID est immunisée (filtre payment_status) ; une commande
fiscalisée aussi (`whereNull('fiscal_sequence_no')`).

**[P3-a] `authorized`** (si l'owner active Klarna/pay-later au dashboard Mollie) : ack → la
commande attend → reaped à 6 h → REJECTED → capture Mollie ultérieure → `paid_on_terminal_order`
log-only (retombe sur P2-B). Aggravant : en mode page hébergée (sans cardToken) `createPayment`
**n'impose PAS** `method=creditcard` (`Mollie.php:107-111` — `$extra` vide) → les méthodes
offertes = config dashboard Mollie, hors contrôle du code. *Reco : forcer `'method' => 'creditcard'`
aussi en hosted.*

**[P2-A] Fenêtre 15 min : la caisse peut « Accepter » une commande dont le paiement carte est en
vol.** Preuve : la carte tracker affiche le CTA Accepter pour TOUTE web PENDING —
`PosOrdersTrackerComponent.vue:305` (`col.id==='accept' && isWebPending(order) && canProcessWebOrders`)
et `isWebPending` (`:1284-1289`) ne teste QUE `source_surface='web' && status===PENDING` — **aucune
distinction payment_method=CARD, aucun badge « paiement en ligne en cours »**. Reproduction :
1) commande web carte → checkout Mollie ouvert (jusqu'à ~15 min avant `expired`) ; 2) caissier
clique Accepter → `OnlineOrderController::changeStatus:163-165` (flip PENDING_COUNTER **réservé
COD** → la carte reste ACCEPT+**UNPAID**) ; 3) webhook `failed` → garde `status!==PENDING`
(`FrontendOrderService.php:920`) → false → `ack_failed` **sans aucun log** (`Mollie.php:322-324`).
Résultat : commande « acceptée » jamais payée, client notifié « acceptée » (mail/push du
changeStatus), puis silencieusement reaped par le janitor 6 h plus tard.
**Mitigations existantes qui la maintiennent P2 (pas P1)** : la cuisine ne la voit JAMAIS
(`KitchenReleaseRule::isReleasedForBoard:100-113` exige PAID/PENDING_COUNTER/POS-cash → risque
cuisine FERMÉ) et le janitor la nettoie. Incohérence UI résiduelle : après accept, le tracker la
range dans la voie « préparation » (`:696-711`) alors que le KDS ne l'affiche pas.
*Fix minimal : badge « 💳 paiement en ligne en cours » + désactiver Accepter quand
`payment_method===CARD && payment_status===UNPAID && source==='web'` ; et logguer un warning
fiscal quand `cancelForFailedOnlinePayment` refuse sur une commande non-PENDING.*

### Point 2 — refunded / chargeback : **[P1-A] trou money-path confirmé**

Mollie n'a **pas** de statut `refunded` : le paiement reste `status='paid'` avec
`amountRefunded`/`amountChargedBack`, et le webhook est renvoyé avec le MÊME `tr_id`.
Chez nous : webhook_id = `{payment_id}:{status_fetché}` (`Mollie.php:253`) → le re-fetch renvoie
`paid` → la ligne `(mollie, tr_x:paid)` **existe déjà** → `duplicate_ignored`
(`Mollie.php:269-277`). Défense en profondeur inexistante : même si l'event passait, le chemin
`alreadyPaid` re-ack (`:409-419`). `grep amountRefunded|amountChargedBack` sur `app/` :
**une seule occurrence, dans Stripe.php:421** — rien côté Mollie.

**Conséquence** : une commande remboursée (geste ops dashboard) ou **chargebackée (initiée par la
banque du client, ops JAMAIS prévenu)** reste PAID + scellée en caisse → Z ≠ payout Mollie, stock
non relâché, points non clawback. Le pattern correctif existe DÉJÀ dans le repo :
`Stripe.php:380-440` (`charge.refunded` → dispatch `RefundCreated` → cascade
`PersistOrderPaymentStatusChangedOnRefundCreated` + `ReleaseStockOnRefundCreated` +
`ReleaseAvailabilityOnRefundCreated` + `ClawbackLoyaltyPointsOnRefund`,
`EventServiceProvider.php:216-232`).
*Fix minimal : dans `processFetchedPayment`, si `status==='paid'` ET
`(float)($payment['amountRefunded']['value'] ?? 0) > 0 || (float)($payment['amountChargedBack']['value'] ?? 0) > 0`
→ dispatch `RefundCreated` (miroir Stripe) ; et discriminer le webhook_id
(`tr_x:paid:r{amountRefunded}:c{amountChargedBack}`) pour que l'event refund ne soit pas dédupliqué.*

### Point 3 — `cancelForFailedOnlinePayment` vs cancel client :801 : side-effects

| Side-effect | Cancel client (`:809-879`) | Mollie cancel (`:908-956`) | Verdict |
|---|---|---|---|
| cashBack | oui si `$locked->transaction` (:831-839) | non | **OK** — garde UNPAID = rien encaissé, et le webhook paid n'écrit AUCUNE ligne Transaction (seulement `transaction_id` string, `Mollie.php:438-441`) |
| refundPoints | `'kiosk'` (:840) | `'kiosk'` (:925) | **OK** — idempotent (early-detect `manual_add` + UNIQUE, `LoyaltyService.php:74-100`), remboursé AU porteur du ledger (:40-42). **P3-b** : `'kiosk'` n'est qu'un champ de trace (`LoyaltyService.php:115`) — un refund web est étiqueté « kiosk » au grand-livre. Parité exacte avec le cancel client. |
| reason + transition auditée | acteur Auth (:855-862) | acteur `null` = système (:933-940) | **OK** |
| OrderStatusChanged (board caisse/KDS) | oui | oui (:941-945) | **OK** |
| Mail / Push | oui / oui | oui / oui (:946-947) | **OK** |
| SMS | oui (:870) | **non** | **OK voulu** (provider SMS mort — cf. mémoire 2026-07-30) ; incohérence mineure : le janitor, lui, dispatch SendOrderSms (`CleanupStale:335`) |
| OrderCanceled → stock/dispo/matière | oui (:874) | oui (:949) | **OK** — s'applique bien aux commandes JAMAIS acceptées : le web décrémente le stock À LA CRÉATION (`FrontendOrderService.php:600` `decrementForOrder`) ; release idempotent `released_qty` (`StockService.php:496,526`) ; 3 listeners câblés (`EventServiceProvider.php:202-209`) |
| **Coupon** | **rien** | **rien** | **P1-D** ↓ |

**[P1-D] Quota coupon brûlé par l'annulation.** `resolveCouponById` compte les lignes
`order_coupons` SANS filtrer le statut de la commande : `limit_per_user`
(`CouponService.php:441-448`) et `max_uses_global` (`:457-460`). Aucun chemin ne supprime/exclut
la ligne d'une commande annulée (grep : seule `CleanupTestFixturesCommand:127` y touche).
Avant le fix ce n'était qu'un défaut latent ; **l'auto-cancel fait du retry le parcours NOMINAL** :
paiement refusé → commande annulée → le client recommande → coupon `limit_per_user=1` → **422
« limite atteinte »** → il paie PLUS CHER que la tentative annulée. Reproduction : commande web
carte avec coupon 1-usage → webhook `failed` (commande CANCELED, `OrderCoupon` intact) → nouvelle
commande même coupon → `CouponService.php:446-448` throw.
*Fix minimal : exclure les commandes terminales du comptage
(`whereHas('order', fn($q) => $q->whereNotIn('status', [16,19,22]))` — attention au double modèle
Order/FrontendOrder sur la même table) OU supprimer la ligne `order_coupons` dans les deux chemins
d'annulation + janitor.*

### Point 4 — course « le client repaye pendant que le webhook annule »

- **Idempotency** : la clé front est purgée dès la création RÉUSSIE (`funnel.jsx:624`) et re-purgée
  au retour status 16 (`index.html:303`) → la nouvelle commande a toujours une clé neuve. **OK.**
- **[P3-c]** `findExistingFrontendOrderForIdempotencyRecovery` (`FrontendOrderService.php:760-779`)
  n'a **aucun filtre de statut** : même onglet + même signature de panier + clé sessionStorage
  survivante (cas : réponse de création perdue) → le recovery peut re-servir une commande depuis
  ANNULÉE → `mollie-checkout` 422 « Commande annulée » → client coincé jusqu'à modification du
  panier (signature → clé neuve, `funnel.jsx:505-516`). Fenêtre très étroite. *Reco : exclure
  16/19/22 du recovery.*
- **Loyalty** : refund keyed par order_id + UNIQUE (user, order, type) → aucun cross-talk avec la
  nouvelle commande. **OK.**
- **Stock** : double-hold transitoire (ancienne pas encore release + nouvelle décrémentée) → au
  pire un faux « rupture » de quelques secondes ; release idempotent. **P3 accepté.**
- **Coupon** : c'est P1-D ci-dessus — la course la plus probable EST le retry immédiat.

### Point 5 — `paid_on_terminal_order` : **[P2-B] log-only, aucune alerte ops**

`Mollie.php:421-434` : warning canal fiscal + `markProcessed`. Aucune notification, aucun
compteur santé, aucune surface admin. **Cellule sœur découverte** : une commande web PAID encore
PENDING est **annulable par le client** (`changeStatus:821-828` — seuil PREPARING pour
TAKEAWAY/KIOSK, ACCEPT pour DELIVERY ; web à emporter = TAKEAWAY(10), `OrderType.php`) → cashBack
skipped (pas de ligne `transaction`, cf. §3) → CANCELED+PAID **sans AUCUN log** — dans les deux
ordres de la course cancel↔paid, l'argent reste chez Mollie, invisible.
**Mécanisme minimal proposé (zéro frozen-zone)** :
1. Requête sentinelle : `orders WHERE transaction_id LIKE 'mollie:%' AND status IN (16,19,22)
   AND payment_status = 5` + `webhook_events WHERE provider='mollie' AND event_type='payment.paid'
   AND order refusée` ;
2. L'exposer comme compteur « 💸 encaissé sur commande annulée (à rembourser) » dans
   `/admin/pos/system-health` (le pill `PosSystemHealthPill` existe déjà — pattern
   caisse_command_center_health 2026-07-31) en AMBRE ;
3. Un `PushNotification` admin à la détection (scheduled 15 min, `onOneServer`).

### Point 6 — garde checkout `MolliePaymentController:55-81`

Couverture prouvée : ownership 403 (`:50-52`), `payment_method!==CARD` 422 (`:55-60`), PAID 409
(`:62-67`), non-UNPAID (PENDING_COUNTER, REFUNDED) 422 (`:69-74`), **CANCELED/REJECTED/RETURNED
422 (`:76-81`) → re-checkout sur commande annulée : IMPOSSIBLE.** Route protégée
`idempotency + throttle:10,1` (`routes/api.php:1472-1474`) + clé front stable par commande
(`api.js:706`) → double-débit par re-clic fermé. **[P3-d]** États non bloqués : ACCEPT+UNPAID
(voulu — rattrapage), mais aussi OFD/DELIVERED+UNPAID → un `paid` tardif sur DELIVERED donnerait
PAID sans jamais d'allocation fiscale (plus aucun changeStatus ne passera par
`OrderService:2066-2069`) = vente hors-Z théorique. Chemin artificiel. *Reco : borner à
`status < OUT_FOR_DELIVERY`.*

### Point 7 — front : retour `?order=`, branche status 16, restore panier

- **Champ présent et numérique : OUI.** `api.getOrder` → `GET /api/frontend/order/show/{id}`
  avec unwrap `.data` (`api.js:685-688`) → `OrderDetailsResource:90` expose `status`, casté
  integer au modèle (`FrontendOrder.php:98` ; `payment_status` :94). `Number(ord.status)===16`
  est robuste même si sérialisé string. Ownership du poll : 403 si pas sa commande
  (`FrontendOrderService::show:754-756`). **OK.**
- **Restore vs garde panier-vide : OK.** `setCart(pending.cartLines)` puis `setRoute('payment')`
  dans le MÊME callback (batch React) ; la garde `:248` (`(r==='checkout'||r==='payment') &&
  !cart.length → menu`) ne s'exécute **qu'au popstate** (back), jamais au `setRoute` programmatique
  — et au back ultérieur `navRef.current.cart` est déjà rempli. Purge idem key (`index.html:303`)
  → le retry crée bien une NOUVELLE commande (cohérent avec la garde backend :76-81). **OK.**
- **[P3-e]** pending d'un bundle pré-déploiement sans `cartLines` → route `payment` avec panier
  vide (l'écran s'affiche sans lignes ; le back bounce vers menu). Fenêtre = mise en prod
  pendant un 3DS en vol.
- **[P3-f]** le poll abandonne après 8×1,5 s = 12 s (`index.html:294-306`) → webhook plus lent
  → « à régler au comptoir » affiché pour une commande sur le point d'être annulée. Mitigé :
  suivi + caisse convergent ensuite.
- **[P1-B] Écran « payé » inline SANS vérité serveur — la plainte d'origine reproduite par
  l'autre chemin.** Chaîne prouvée : `Mollie::createPayment` retourne
  `inline = cardToken!=='' && checkoutUrl===''` et le statut Mollie réel dans `status`
  (`Mollie.php:160-166`) → `MolliePaymentController:100-111` **ignore `$created['status']`** et
  renvoie `inline:true` → `funnel.jsx:637-638` `setCtx(paidOnline:true)` → l'écran confirmation
  affiche « payé » (`funnel.jsx:1030-1032`) **sans aucun poll serveur** (contrairement au retour
  `?order=`). Or un paiement cardToken refusé SYNCHRONE par Mollie = objet payment créé avec
  `status:'failed'` et **pas d'URL checkout** → `inline=true` → **écran de succès pour une carte
  refusée**, puis le webhook `failed` annule la commande — le client part avec « payé » et la
  commande meurt. Les exemptions SCA petits montants (<30 €, cœur du panier resto) rendent le
  no-3DS réaliste en prod FR. *Fix minimal : (a) le controller propage `mollie_status` et refuse
  le succès inline quand `status==='failed'` (422 explicite) ; (b) le front ne pose `paidOnline`
  qu'après un poll `getOrder` `payment_status===5` (réutiliser la boucle du retour `?order=`).*

### Bonus (hors liste) — **[P1-C] rejeux : un échec transitoire du webhook `paid` est IRRÉCUPÉRABLE**

1. Crash pendant le traitement (deadlock, blip DB) → `markFailed` + 500 (`Mollie.php:281-292`) ;
2. Mollie rejoue → `firstOrCreate` retrouve la ligne `(mollie, tr_x:paid)` **status=failed** →
   `wasRecentlyCreated=false` → **`duplicate_ignored`** (`Mollie.php:269-277`) — le retry natif
   Mollie est neutralisé par notre propre dedup ;
3. Le filet DLQ ne rattrape pas : `foodking:webhook:retry-failed` (horaire, `Kernel.php:83`)
   sélectionne TOUTES les lignes failed (`OutboxWebhookRetryFailedCommand:97-101`) →
   `ProcessWebhookEventJob::dispatchToProviderHandler` : match STRIPE/SENANGPAY,
   **`default => null` — PAS de bras Mollie** (`ProcessWebhookEventJob.php:77-91`) →
   `markFailed('No DLQ handler for provider: mollie')` → churn horaire stérile pendant 24 h puis abandon.

**Conséquence money-path** : client débité, commande UNPAID pour toujours → l'écran front conclut
« à régler au comptoir » → **double encaissement** au comptoir, aucune réconciliation.
*Fix minimal : implémenter `Mollie::handleFromStoredEvent(WebhookEvent $event)` (re-fetch par
payment_id du webhook_id, re-router vers `processFetchedPayment`) + ajouter le bras
`PROVIDER_MOLLIE` au match — le squelette existe pour Stripe/Senangpay. Attention à ne pas
re-driver les refus PERMANENTS (amount_mismatch) en boucle.*

---

## 3. SYNTHÈSE DES VERDICTS

| ID | Sév. | Cellule | Preuve pivot |
|---|---|---|---|
| P1-A | **P1** | refund/chargeback Mollie avalé par le dedup → commande reste PAID, ops aveugle | `Mollie.php:253,269-277,409-419` ; contre-exemple `Stripe.php:380-440` |
| P1-B | **P1** | inline `paidOnline` sans vérité serveur → carte refusée = écran « payé » puis commande annulée (plainte d'origine, autre chemin) | `Mollie.php:160-166` + `MolliePaymentController.php:100-111` + `funnel.jsx:637-638,1030-1032` |
| P1-C | **P1** | échec transitoire webhook `paid` : dedup empoisonné + DLQ sans bras Mollie → payé chez Mollie / UNPAID chez nous, double encaissement comptoir | `Mollie.php:269-292` + `ProcessWebhookEventJob.php:77-91` |
| P1-D | **P1** | quota coupon compté sur commandes ANNULÉES → retry après échec de paiement = coupon brûlé | `CouponService.php:441-448,457-460` |
| P2-A | P2 | « Accepter » caisse offert pendant le paiement carte en vol, sans badge ; refus d'annulation ensuite silencieux | `PosOrdersTrackerComponent.vue:305,1284-1289` + `OnlineOrderController.php:163-165` + `Mollie.php:322-324` |
| P2-B | P2 | `paid_on_terminal_order` + cancel-client-après-paid : argent capturé pour commande void, log-only voire rien | `Mollie.php:421-434` + `FrontendOrderService.php:821-839` |
| P3 a-f | P3 | `authorized`/hosted sans method forcé · recovery idempotency sans filtre statut · double-paid re-ack sans log · checkout DELIVERED+UNPAID · pending sans cartLines · poll 12 s | voir sections |
| OK | — | cœur du fix : cancel web+carte+PENDING+UNPAID verrouillé/idempotent, ACCEPT/PAID intouchées, montant scellé, janitor 6 h, garde re-checkout CANCELED, front status16 restore+idem purge | `FrontendOrderService.php:908-956`, `MollieStructureTest.php` 14/14, `CleanupStalePendingKioskOrders.php:190-212` |

**Ordre de traitement recommandé** : P1-B (reproduit la plainte owner, fix 10 lignes) → P1-C
(DLQ Mollie) → P1-A (RefundCreated miroir Stripe) → P1-D (comptage coupon) → P2-A/P2-B (badge
caisse + sentinelle « encaissé-sur-annulée » dans system-health).

*Audit read-only — aucun fichier de code modifié. Tous les file:line vérifiés par lecture directe
dans le worktree principal (hors `.claude/worktrees`).*
