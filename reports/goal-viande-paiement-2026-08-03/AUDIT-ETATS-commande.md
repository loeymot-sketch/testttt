# AUDIT ÉTATS — Cycle de vie commande WEB + transitions (READ-ONLY)

Date : 2026-08-03 · Branche : `pos/category-first-caisse-2026-06-23` · HEAD audité : `a80643441`
Contexte : owner trapé par « paiement annulé à la banque mais commande validée ». Le fix `a80643441`
(`cancelForFailedOnlinePayment`) ferme le chemin zéro-action. Cet audit cartographie TOUT le chemin
d'état web et prouve ce qui reste ouvert.

---

## 0. Cartographie de référence

### Machine à états commande — `app/Domain/Order/OrderStateMachine.php:30-91` (SSOT, miroir de `app/Rules/ValidStatusTransition.php:30-36`)

| From (val) | To autorisés |
|---|---|
| PENDING (1) | ACCEPT (4), CANCELED (16), REJECTED (19) — :37-38 |
| ACCEPT (4) | PREPARING (7), CANCELED (16) ; + DELIVERED si perm `pos` (:41) ; + RETURNED si perm `pos-refund` (:48) |
| PREPARING (7) | PREPARED (8), CANCELED (16) ; + DELIVERED/`pos`, RETURNED/`pos-refund` (:55-63) |
| PREPARED (8) | OUT_FOR_DELIVERY (10), DELIVERED (13) ; + RETURNED/`pos-refund` (:65-71) |
| OUT_FOR_DELIVERY (10) | DELIVERED (13) — :74 |
| DELIVERED (13) | RETURNED (22) — :77 |
| CANCELED/REJECTED/RETURNED | rien, sauf rôle Admin (:79-86) — mais résurrection re-bloquée par `OrderService::assertNotResurrectingTerminalOrder` (`app/Services/OrderService.php:2207-2215`, re-checkée sous lock :2370) |

**Point structurel : la machine n'a AUCUNE dimension paiement.** `allows()` ne lit jamais
`payment_status`. PENDING+UNPAID → ACCEPT est **légal** pour tout staff. La barrière paiement
n'existe que côté cuisine (`KitchenReleaseRule`), pas côté acceptation.

### Machine à états paiement — `app/Domain/Order/PaymentStateMachine.php:9-19`

| Valeur | Sens |
|---|---|
| PAID = 5 | payé (carte en ligne confirmée webhook, TPE borne, encaissement comptoir) |
| UNPAID = 10 | non payé — **état d'une commande web CARTE pendant tout le paiement en ligne** ET d'une web comptoir avant accept |
| PENDING_COUNTER = 15 | dû au comptoir (borne Plan B à la création ; web/téléphone COD **après** accept) |
| REFUNDED = 20 | remboursé |

Transitions déclarées : UNPAID→[PAID] ; PENDING_COUNTER→[PAID, REFUNDED] ; PAID→[] ; REFUNDED→[].

### Création d'une commande web — `app/Services/FrontendOrderService.php:284-296`
Toute commande web (carte OU comptoir) naît **`status=PENDING` + `payment_status=UNPAID`**
(`$isCounterDeferredKioskCash` exige une KioskMachine → toujours false pour le web, :219-221).
Seul discriminant : `payment_method` = CARD(4) pour carte en ligne (`MolliePaymentController.php:54-60`)
vs CASH_ON_DELIVERY(1) pour « payer au comptoir » (flip accept `OnlineOrderController.php:162-165`).
Signaux `OrderCreated` + Mail/Sms/Push : dispatchés **À LA CRÉATION** pour tout non-kiosk
(`FrontendOrderService.php:250` → :686-688, :706-716) — la borne carte les diffère au paiement, le web carte NON.
Stock : décrémenté à la création (:600).

---

## 1. Findings

### [P1] F1 — La fenêtre « caissier accepte pendant le 3DS » reproduit ENCORE le trap owner
**Scénario prouvé bout à bout :**
1. T0 — client web choisit carte → commande `PENDING+UNPAID+payment_method=CARD` créée
   (`FrontendOrderService.php:284-296`) ; signaux immédiats (:686-688) ; la caisse est **notifiée
   et beepe** (`PosComponent.vue:3855 _notifyPolledNewOrders`) pendant que le client tape sa carte.
2. T1 — la commande apparaît avec CTA **« Accepter »** dans le panneau caisse
   (`PosComponent.vue:546-570`, endpoint `routes/api.php:920-935` — filtre `status=PENDING` seul)
   ET dans le tracker (`PosOrdersTrackerComponent.vue:720` + CTA :305-311). `isWebPending`
   (`PosOrdersTrackerComponent.vue:1284-1288`) = `source_surface='web' && status=PENDING` —
   **aucun test de `payment_method`/`payment_status`**.
3. T2 — le caissier clique Accepter → `OnlineOrderController::changeStatus` : le flip
   PENDING_COUNTER est **sauté** pour une carte (gate COD :162-165, voulu) mais **l'ACCEPT lui-même
   n'est PAS bloqué** → `OrderService::changeStatus` valide PENDING→ACCEPT (machine sans dimension
   paiement) → **ACCEPT+UNPAID+CARD**.
4. T3 — client clique « annuler » au 3DS → webhook Mollie `canceled` →
   `cancelForFailedOnlinePayment` (`FrontendOrderService.php:919-923`) exige
   `status=PENDING` → **return false** → webhook ack, commande INTOUCHÉE (comportement documenté
   « décision humaine » — mais AUCUN humain n'est prévenu : ni toast, ni badge, ni file).

**État final : ACCEPT+UNPAID, paiement annulé à la banque.**
- Client : suivi = « acceptée » → **le trap exact de l'owner** (paiement annulé, commande validée), version « caissier a agi dans la fenêtre ».
- Cuisine : ne la voit JAMAIS (`KitchenReleaseRule::isReleasedForBoard` `app/Domain/Kds/KitchenReleaseRule.php:100-112` — UNPAID ∉ {PAID, PENDING_COUNTER, POS+CASH}).
- Tracker caisse : la carte glisse dans la voie **« EN PRÉPARATION »** (`PosOrdersTrackerComponent.vue:695-710`) — la voie MENT, la cuisine n'a rien reçu.
- Encaissement : impossible — pas de marqueur COUNTER_DEFERRED, `assertCounterDeferredOrder` rejette (`app/Services/PaymentService.php:358`) ; absente de la file counter-collect (`routes/api.php:854-895`).
- Sortie : uniquement le janitor 6 h (`app/Jobs/CleanupStalePendingKioskOrders.php:187-212`, lane web TTL 360 min) → ACCEPT→CANCELED + release stock + refund points. Le client reçoit « annulée » **6 h après** « acceptée ».

C'était un P0 zéro-action avant `a80643441` ; il reste un **P1 haute vraisemblance** : la fenêtre
est exactement la durée du paiement (minutes), et le système **invite** le caissier à agir pendant
cette fenêtre (beep + CTA).

### [P1] F2 — La caisse est aveugle au paiement web : carte-en-cours ≡ comptoir, indiscernables
- `routes/api.php:920-935` (`/pos/web-orders/pending`) : `source_surface='web' AND status=PENDING`,
  **aucun filtre ni exposition d'intention de paiement**. Le commentaire :913-914 date la route de
  l'époque « paiement carte en ligne OFF (mandat owner) » — hypothèse **caduque** depuis Mollie LIVE
  (`7d62c2198`), la route n'a pas suivi.
- Panneau caisse (`PosComponent.vue:546-570`) : rend N° + prix + « Accepter »/« Détails ». Rien d'autre.
- Tracker (`PosOrdersTrackerComponent.vue:163-315`) : seul badge paiement existant = 🔔 cash-pending
  (:173-181, PENDING_COUNTER+COUNTER_DEFERRED). Une web PENDING n'a **ni badge « paiera au
  comptoir », ni « paiement carte EN COURS », ni « PAYÉE »** — alors que `SimpleOrderResource.php:58-59`
  ship déjà `payment_method` + `payment_status` (l'UI les ignore).
- Le CTA affirme même le faux : tooltip « Accepter la commande web — **encaissement au comptoir** »
  (`PosOrdersTrackerComponent.vue:309`) et toast succès identique (`PosComponent.vue:3886`,
  tracker :1306) — affichés aussi pour une commande CARTE (qui ne sera jamais encaissable au
  comptoir) et pour une commande déjà PAYÉE en ligne (risque humain de double encaissement au retrait).

Réponse Q3/Q6 : une carte « en cours de paiement » = **UNPAID(10)**, indistincte d'une comptoir
avant accept (UNPAID aussi) ; il n'existe **aucun état « paiement en ligne en cours »** ; le seul
discriminant (`payment_method` 4 vs 1) n'est rendu nulle part en caisse.

### [P1] F3 — Web carte PAYÉE : jamais promue, jamais fiscalisée (gate kiosk) — actif depuis Mollie LIVE
Webhook `paid` → `payment_status=PAID` (`Mollie.php:438-441`) puis `finalizePaidKioskOrder`
(`Mollie.php:459-460`) qui **no-op pour une commande web pure** : gate `$isKioskMachineOrder`
(`FrontendOrderService.php:1334-1348`). Conséquences :
1. La commande reste **PENDING+PAID** (pas d'auto-accept) — dépend d'un accept manuel caisse, sans
   badge « PAYÉE » (F2) et avec le toast mensonger « encaissement au comptoir ».
2. **Aucune allocation `fiscal_sequence_no`** (l'auto-alloc est DANS le bloc gaté kiosk,
   :1404-1410). `Mollie.php:463-475` le trace en warning `fiscal_finalize_noop` : « l'élargissement
   du gate est un point d'activation G-W5 (owner) ». Depuis `7d62c2198` (carte LIVE), chaque
   paiement carte web réel encaisse de l'argent **hors chaîne fiscale** (n'entrera dans aucun Z
   signé) jusqu'à l'activation G-W5. Gate owner documenté, mais désormais **atteignable en prod** —
   à escalader owner, pas un simple backlog.
3. Le janitor exclut PAID (`CleanupStalePendingKioskOrders.php:194`) — correct (argent pris, on ne
   purge pas), mais rien d'autre ne pousse cette commande vers la cuisine si la caisse tarde.

### [P2] F4 — Flip `UNPAID→PENDING_COUNTER` : transition ILLÉGALE selon la machine paiement déclarée, écrite en direct
`OnlineOrderController.php:166` écrit `payment_status=PENDING_COUNTER` sur une commande UNPAID.
`PaymentStateMachine::TRANSITIONS` (`PaymentStateMachine.php:10-12`) déclare UNPAID→[PAID]
**uniquement**. Le flip (et le `UNPAID→PAID` direct du webhook `Mollie.php:438`) contournent la
machine (asserts seulement dans `PaymentService.php:359,817` et `OrderService.php:2688,2745`).
La « SSOT » paiement est donc fausse par omission : tout futur code s'appuyant sur
`canTransition()` refusera un edge pratiqué en prod. À aligner (ajouter UNPAID→PENDING_COUNTER à la
machine OU faire passer le flip par un chemin assert).

### [P2] F5 — Flip accept web : read-modify-write NON verrouillé sur `payment_status`
`OnlineOrderController.php:162-183` lit le modèle **route-bound** (pas de `lockForUpdate`) et save
AVANT que `OrderService::changeStatus` ne prenne son lock (:2334). Fenêtre étroite : un
`changePaymentStatus→PAID` concurrent (2ᵉ opérateur) peut être écrasé par le flip →
PAID régresse en PENDING_COUNTER → la commande redevient collectable (double encaissement
théorique). Improbable en V1 single-box, mais le pattern viole la discipline lock-first appliquée
partout ailleurs dans ce même fichier.

### [P2] F6 — Client web carte : notifications « commande reçue » AVANT paiement
`FrontendOrderService.php:250` + :686-688 + :706-716 : pour le web, `OrderCreated` + Mail/Sms/Push
partent à la création même en carte — la borne carte, elle, diffère tout au paiement (truth table
:228-249). Le client reçoit « reçue/en attente » avant d'avoir payé, puis « annulée » si le
paiement échoue. Cohérent post-fix mais trompeur, et c'est cette dispatch précoce qui alimente F1/F2
(visibilité caisse pré-paiement).

### [P2] F7 — Jobs mail/push dispatchés DANS la transaction d'annulation
`cancelForFailedOnlinePayment` dispatch `SendOrderMail/SendOrderPush` à l'intérieur du
`DB::transaction` (`FrontendOrderService.php:946-947`) : un worker rapide peut lire l'état
pré-commit. Même pattern que le miroir cancel client (:869-871) — dette homogène, pas une
régression du fix.

### [P2] F8 — Stock gelé par les paniers carte abandonnés (borné)
Stock décrémenté à la création (`FrontendOrderService.php:600`) pour une commande carte qui ne sera
peut-être jamais payée. Bornes de sortie : webhook `expired/canceled/failed` (cancel + release via
`OrderCanceled`, :948-952) sinon janitor 6 h. Fenêtre pendant laquelle un faux « 86 » peut priver
borne/caisse d'un produit disponible. Design assumé (réservation), signalé pour cohérence dispo.

### [SAFE] S1 — `cancelForFailedOnlinePayment` est cohérent avec la machine à états
PENDING→CANCELED **légal** (`OrderStateMachine.php:37-38`) ; `requiresReason(CANCELED)` satisfait
(reason :927, `recordTransition` :933-940 écrit une transition légale, acteur système null) ;
lock + idempotence rejeu (:914-916) ; ne touche JAMAIS une ACCEPT ou une PAID (:919-923) ; refund
points par porteur (`LoyaltyService.php:21-43`, symétrie P0 déjà healée) ; release stock idempotent ;
events board. Pas de `SealedOrderGuard` nécessaire : PENDING+UNPAID ⇒ `fiscal_sequence_no` null ⇒
jamais dans un Z. Verdict : le fix est bon sur son périmètre.

### [SAFE] S2 — Webhook `paid` : garde-fous solides
Montant re-fetché vs total scellé au centime (`Mollie.php:377-392`) ; transaction déjà rattachée
ailleurs → refus (:398-407) ; commande TERMINALE (CANCELED/REJECTED/RETURNED) → **jamais PAID**,
warning fiscal + remboursement manuel documenté (:421-435) — pas de résurrection d'une commande
annulée par l'argent qui arrive en retard ; idempotence `webhook_events` par `(payment_id:status)`
(:249-277).

### [SAFE] S3 — Course inverse (webhook cancel AVANT le clic caissier)
`OrderService::changeStatus` re-valide la transition sous lock (:2362) + anti-résurrection
re-checkée sous lock (:2370, bloque même Admin) → accepter une commande fraîchement CANCELED = 422
propre, toast d'erreur affiché (`PosComponent.vue:3894-3896`). Les DEUX ordres d'arrivée laissent
une DB cohérente ; seul l'ordre « accept d'abord » laisse le zombie F1.

### [SAFE] S4 — Janitor web
Lane web (`CleanupStalePendingKioskOrders.php:187-212`) : PENDING/ACCEPT/PREPARING +
UNPAID/PENDING_COUNTER + `fiscal_sequence_no IS NULL`, TTL 6 h priorité `order_datetime`,
transitions légales (PENDING→REJECTED, ACCEPT/PREPARING→CANCELED via `OrderStateMachine::apply`),
refund + release + casse du marqueur sous lock. Borne correctement tous les zombies UNPAID.

---

## 2. Réponses directes aux 6 questions

1. **Machine à états** : table §0. Oui, PENDING+UNPAID est acceptable en caisse — la machine n'a
   pas de dimension paiement et aucun contrôleur ne bloque l'ACCEPT d'une carte non aboutie
   (`OnlineOrderController.php:162-186` saute seulement le flip). Badge UNPAID : **inexistant** (F2).
   Perte cuisine évitée par `KitchenReleaseRule` (la cuisine ne la voit pas) — mais au prix d'une
   voie tracker mensongère et d'un client « accepté-non-payé » (F1).
2. **Affichage caisse** : oui, une PENDING+UNPAID+carte s'affiche AVANT paiement dans les 2 surfaces
   (panneau `web-orders/pending` sans filtre paiement + tracker `isWebPending`), avec CTA Accepter
   actif. C'est le cœur de la plainte (F1/F2).
3. **payment_status** : PAID=5, UNPAID=10, PENDING_COUNTER=15, REFUNDED=20. Carte en cours = UNPAID,
   identique au comptoir pré-accept ; distinction = `payment_method` 4 vs 1, jamais affichée (F2).
4. **Cohérence création** : web carte naît PENDING+UNPAID, **immédiatement visible et acceptable**,
   signaux + stock + mails à la création. Elle devrait être invisible/non-acceptable en caisse tant
   que le paiement carte n'a pas abouti (ou marquée « paiement en ligne en cours », CTA désactivé) —
   recommandation R1 ci-dessous.
5. **cancelForFailedOnlinePayment** : conforme (S1). PENDING→CANCELED valide dans
   ValidStatusTransition/OrderStateMachine ; recordTransition n'écrit pas de transition illégale.
6. **Comptoir fallback** : web comptoir = PENDING+UNPAID+payment_method=1 ; web carte =
   PENDING+UNPAID+payment_method=4. **Oui, dangereusement identiques** à l'écran (F2) — seule
   la carte NE DOIT PAS être acceptée avant paiement, mais c'est le même bouton, le même rendu,
   le même toast.

---

## 3. Verdict

**HEAL (P1×3).** Le fix `a80643441` est correct et ferme le chemin principal (zéro-action), mais le
cycle web carte reste incohérent sur trois arêtes :

- **R1 (ferme F1+F2, non-frozen)** : gater l'acceptation — dans `OnlineOrderController::changeStatus`,
  refuser (422 explicite) ACCEPT d'une commande `source_surface='web' + payment_method=CARD +
  payment_status=UNPAID` (« paiement en ligne en cours — attendez la confirmation ou faites-la
  re-commander au comptoir ») ; et/ou filtrer `payment_method=CARD AND payment_status=UNPAID` de
  `/pos/web-orders/pending` + `isWebPending`. Ceinture+bretelles : les deux.
- **R2 (F2/F3 UI)** : badge paiement sur cartes web (panneau + tracker) à partir des champs déjà
  shippés par `SimpleOrderResource` : « 💳 paiement en ligne en cours » (CARD+UNPAID, CTA off),
  « ✅ PAYÉE en ligne » (PAID — et corriger le toast « encaissement au comptoir »), « 💶 à encaisser »
  (COD). 
- **R3 (F3 fiscal — GATE OWNER)** : activer G-W5 (élargir `finalizePaidKioskOrder` ou chemin dédié
  web-paid : auto-accept + allocation fiscale) — depuis Mollie LIVE, chaque paiement carte web réel
  est encaissé hors chaîne NF525 (warning `fiscal_finalize_noop` à surveiller en prod dès
  maintenant : `grep fiscal_finalize_noop storage/logs/fiscal-*.log`).
- P2 (F4, F5, F6, F7, F8) : backlog.
