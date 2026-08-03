# Chasse READ-ONLY — Parité de synchronisation BORNE vs WEB → KDS / OSS

Date : 2026-07-18 · Mode : READ-ONLY (aucune modification) · DB : foodking_e2e (tinker lecture seule) · Serveur :8000

Question owner : « synchronisation unifiée et connectée avec KDS ». Où les commandes **borne** et **web** n'arrivent-elles PAS de façon cohérente au KDS/OSS ?

Verdict global : **le cœur de la synchro EST cohérent** — une fois une commande web *acceptée + encaissée*, elle atteint le KDS et l'OSS **à l'identique** d'une borne (prouvé DB : web #5728 == kiosk #5689). Deux divergences réelles subsistent : **(F1) l'ENTRÉE sur le board** (borne = automatique, web = accept manuel caisse) et **(F2) l'impression serveur** (gatée `source_surface='kiosk'`, web jamais). Le reste (OSS, temps-réel, recall/bump) est en parité.

---

## Cartographie du cycle de vie (source de vérité)

| Étape | BORNE (`source_surface='kiosk'`) | WEB (`source_surface='web'`) |
|---|---|---|
| Création | `order_type` KIOSK(25)/TAKEAWAY(10), **auto-accept** si cash Plan B → `status=ACCEPT` + `ps=PENDING_COUNTER` | `order_type=TAKEAWAY(10)`, `source=WEB(5)`, **`status=PENDING` + `ps=UNPAID`** (myOrderStore:287,290) |
| Event création | `OrderCreated` → board direct (ACCEPT+PENDING_COUNTER) | `OrderCreated` fire mais **NON libérée** (UNPAID non-cash) → invisible cuisine |
| Entrée board | **Automatique** (création borne, ou post-TPE via `finalizePaidKioskOrder`) | **Manuelle** : caisse « Commandes web » → *Accepter* → `OnlineOrderController::changeStatus:146` flip `ps=PENDING_COUNTER` (SYNC-WEB-KDS-01) |
| Encaissement | `confirmCounterPayment` (Plan B) ou TPE | `confirmCounterPayment` (P1-3 : marqueur `COUNTER_DEFERRED` pour TAKEAWAY COD) |

Preuve DB (tinker) :
```
#5728 surf=web    ot=10 st=7(PREPARING) ps=5(PAID)  -> KDS_board=YES  OSS_wall=YES
#5739 surf=web    ot=10 st=1(PENDING)   ps=10(UNPAID)-> KDS_board=no   OSS_wall=no   (attend accept caisse)
#5689 surf=kiosk  ot=10 st=7(PREPARING) ps=5(PAID)  -> KDS_board=YES  OSS_wall=YES
```
Distribution `order_type × source_surface` : web = ot10(TAKEAWAY)×70, ot5(DELIVERY)×2. Kiosk = ot10×1199, ot25×11.

---

## F1 — [P2] Divergence LIVE : la commande web n'atteint la cuisine QUE sur accept manuel caisse ; la borne y arrive automatiquement

**Fichiers :**
- `app/Services/FrontendOrderService.php:213-221` — `$shouldAutoAcceptAfterCreate = $isCounterDeferredKioskCash` ; `$isKioskOrderType = $isKioskMachineOrder && ...`. Sans **KioskMachine** liée au token (= tout web/mobile), `$isKioskOrderType=false` → **jamais auto-accepté**.
- `app/Services/FrontendOrderService.php:287,290` — web créé `status=PENDING`, `payment_status=UNPAID`.
- `app/Http/Controllers/Admin/OnlineOrderController.php:146-168` — le SEUL chemin qui libère une web : bouton *Accepter* (PENDING→ACCEPT) qui bascule `payment_status=PENDING_COUNTER` (heal SYNC-WEB-KDS-01, 2026-07-15).
- `app/Domain/Kds/KitchenReleaseRule.php:130-140` (`applyBoardReleaseFilter`) — exige `PAID | PENDING_COUNTER | POS-cash`. Une web `PENDING+UNPAID` est **exclue** du board.

**Repro :** web #5739 (`PENDING/UNPAID`, file A0034) est **absente** du KDS et de l'OSS alors qu'elle est « passée » côté client. Une borne au même point de cycle est déjà `ACCEPT+PENDING_COUNTER` **sur le board**. V1 = paiement en ligne OFF (Stripe 503) → **100 % des commandes web** exigent ce clic manuel : si la caisse est absente/occupée, **la cuisine ne voit jamais la commande web**.

**Nuance (honnêteté) :** en partie *by-design* (workflow accept/reject des commandes en ligne) et récemment construit (heals SYNC-WEB-KDS-01 + P1-3). Une fois acceptée, la web est traitée **identiquement** à une borne Plan B. La question ouverte pour l'owner : **auto-accepter la web COD** comme le Plan B borne (→ cuisine instantanée) OU garder le filet accept manuel. C'est LA réponse à « web n'arrive pas de façon cohérente au KDS ».

---

## F2 — [P3 latent] L'impression serveur (ticket cuisine + copie comptoir) est gatée `source_surface='kiosk'` → web jamais imprimée côté serveur

**Fichiers :**
- `app/Listeners/PrintKioskKitchenTicketOnOrderCreated.php:35` — `if ((string)($order->source_surface ?? '') !== 'kiosk') return;` (ticket cuisine, station `kitchen_hot`).
- `app/Listeners/PrintKioskOrderToCounter.php:44` — même garde exacte (copie comptoir, station `receipt`).

Réponse directe au Lens 2 owner (« PrintKioskKitchenTicketOnOrderCreated — le nom dit Kiosk, couvre-t-il web ? ») : **NON, il exclut explicitement web** (et tout non-kiosk). Ces listeners écoutent `OrderCreated` (création) — or la web ne re-fire PAS `OrderCreated` à l'accept (seulement `OrderStatusChanged`), donc web = **zéro impression serveur**, jamais.

**État actuel = dormant, mais réel :**
- Ticket cuisine : la table `printers` n'a **aucune** ligne `kitchen_hot/kitchen/kitchen_cold` → no-op **même pour la borne** aujourd'hui. `PRINT_DRIVER` non défini → transport Null → no-op global.
- Copie comptoir : printer **#2 station=receipt ACTIVE existe** → dès que `PRINT_DRIVER` est câblé (durcissement prod), **la borne imprime une copie comptoir à la création, la web jamais**.
- Chemin cuisine ACTIF = **pont KDS** (`resources/js/helpers/kitchenLocalPrinter.js` + `KitchenDisplaySystemComponent.vue:2007 autoPrintNewKitchenTickets`) qui imprime **toutes sources sans distinction** → la web EST imprimée via le pont une fois sur le board. La borne a donc une **redondance** (serveur + pont) que la web n'a pas (pont seul).

**Impact :** asymétrie de code qui devient une **panne live au câblage matériel** — si la cuisine s'appuie sur l'imprimante serveur sans écran KDS ouvert, la borne imprime, la web disparaît. À corriger en durcissement prod (élargir la garde à `web`/`online`, ou déplacer sur `OrderPaidAtCounter`).

---

## Parité CONFIRMÉE (aucun défaut — rassurance)

- **Lens 1 (aval board-release)** ✓ : `KitchenReleaseRule::applyBoardReleaseFilter` est **paiement-only, source-agnostique**. Une web `PENDING_COUNTER` libère le board **exactement** comme une borne Plan B. Filtre partagé SSOT sur les 4 chemins (KDS `list`/`orderItems`/`sync` + guard `changeStatus`) et l'OSS. Prouvé : web #5728 KDS+OSS = kiosk #5689.
- **Lens 3 (mur client OSS)** ✓ : allowlist `whereIn('order_type',[KIOSK,TAKEAWAY])` (`OrderStatusScreenOrderService.php:59,227`). Web à emporter = **TAKEAWAY(10) → dans l'allowlist**, avec `queue_number` → s'affiche comme une borne TAKEAWAY. Web DELIVERY(5) correctement **exclue** (pas sur le mur retrait). N° de file/affichage/« prêt » identiques.
- **Lens 4 (temps-réel)** ✓ : `subscribeEcho` (`KitchenDisplaySystemComponent.vue:2257-2263`) écoute `OrderStatusChanged` + `OrderCreated` + `OrderPaidAtCounter`. Le filtre `_statusChangeAffectsKds:1731` inclut `ACCEPT(4)` → l'accept web (PENDING→ACCEPT) **déclenche un refresh immédiat**. Le delta-poll `KdsSyncService` applique le même board-release filter. Borne (`OrderCreated`) et web (`OrderStatusChanged 1→4`) rafraîchissent tous deux en temps-réel. `PersistOrderStatusChangedToOutbox` broadcast sans gate de source.
- **Lens 5 (statuts cuisine)** ✓ : le KDS lit `status` **sans branche de source** ; aucun impact sync distinct au-delà du P2-r horodatage (exclu). Le KDS ne voit pas d'état incohérent selon la source.
- **Lens 6 (recall/bump)** ✓ : `KitchenDisplaySystemOrderService::changeStatus` + `recall` opèrent sur `Order` **uniformément**, aucune branche source → bump/recall web == borne. Le badge source (`kdsSource.js`) mappe `web→ONLINE` (chip vert globe), `kiosk→KIOSK` (chip bleu) — étiquetage correct et distinct.

---

## Écartés (avec raison)

- **Auto-print USB pont** : exclu par le mandat (livré) ; confirmé sans gate de source (imprime web).
- **Horodatage FrontendOrder/Order (P2-r)** : exclu ; aucun impact sync distinct trouvé (KDS source-agnostique sur les timestamps).
- **Zombie board web non encaissé** : couvert par `CleanupStalePendingKioskOrders.php:195` (`source_surface='web'`, janitor STOCK-01) — pas un nouveau trou.
- **Escpos route sans permission par-route** (`routes/api.php:981`) : la route `GET orders/{order}/escpos` n'a pas de garde par-route mais est dans le groupe admin ; le pont KDS la joint pour toute source → pas de divergence web/borne. (Non un trou de parité.)
- **OSS statut ACCEPT non affiché** : web ET borne en `ACCEPT` sont sur KDS mais pas sur OSS (OSS = PREPARING/PREPARED) — comportement **identique** aux deux, by-design.

---

### Synthèse priorisée
| # | Sévérité | Titre | Live ? |
|---|---|---|---|
| F1 | P2 | Web → cuisine seulement sur accept manuel caisse (borne = auto) | **LIVE** (semi-intentionnel) |
| F2 | P3 | Impression serveur ticket cuisine/comptoir gatée `kiosk` → web jamais | Latent (live au câblage `PRINT_DRIVER` pour la copie comptoir) |

Cœur de synchro (board-release, OSS, temps-réel, recall) : **parité prouvée**.
