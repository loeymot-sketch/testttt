# RED — Cohérence d'état cross-surface (audit adversarial READ-ONLY)

**Domaine** : le même fait (statut, 86, stock, prix, fidélité, table) doit être vu IDENTIQUEMENT sur borne / caisse / KDS / OSS / web / mobile.
**HEAD** backend `1bf7aad5e` · web `lecayenne-web-deploy/Site lecayenne`.
**Méthode** : cartographie émetteurs (`*::dispatch`) ↔ listeners outbox (`broadcast_as`) ↔ abonnés client (`onEvents` / `channel.listen`). Chaque finding = `file:line` + repro. Angle : (A) mutation d'état SANS event ⇒ surface périmée ; (B) event émis IGNORÉ par les surfaces (pas d'abonné).

---

## Matrice event → broadcast_as → abonnés (le cœur du problème)

| Event (broadcast_as) | Émis par | Abonnés client réels (`onEvents`) |
|---|---|---|
| `OrderCreated` | OrderService, FrontendOrderService, Uber | Kiosk-wait, OSS, Encaissement, PosTracker, PosComponent, **KDS** |
| `OrderStatusChanged` | OrderService(2179/2309/2573), PaymentService, FrontendOrderService, KDS bump, Uber | Kiosk-wait, OSS, Encaissement, PosTracker, PosComponent, **KDS** |
| `OrderPaidAtCounter` | PaymentService | Encaissement, PosTracker, PosComponent, KDS |
| `ItemAvailabilityChanged` | ItemService, StockService, AvailabilityService(item) | **KioskApp (borne)**, **KDS**, StockDashboard |
| `CatalogChanged` / `CouponChanged` / `ComposerProfileChanged` | Item*/Catalog listeners | KioskApp, useCatalogChangeNotifier |
| `ItemExtraAvailabilityChanged` | AvailabilityService:810 | **StockDashboard SEUL** |
| `ItemVariationAvailabilityChanged` | AvailabilityService:821 | **StockDashboard SEUL** |
| `OrderTableChanged` | DiningTableService:269/436 | **KDS SEUL** |
| `OrderPaymentStatusChanged` | refund + admin flip + Stripe drain | **ZÉRO abonné** |
| `SettingsUpdated` | Currency/Tax/Company/OrderSetup/Site | **ZÉRO abonné** |
| `BranchStatusChanged` | BranchController:72/99 | **ZÉRO abonné** |

Source abonnés : `resources/js/services/eventContract.js:18-29` (BROADCAST_MAP) + tous les `onEvents(...)` (KioskApp `:546`, KioskWaiting `:272`, OSS PreparingAndReady `:287`, Encaissement `:165`, PosOrdersTracker `:894`, PosComponent `:3456`, KDS KitchenDisplaySystemComponent `:2450`, StockRuptureDashboard `:592`).

---

## FINDINGS

### [P1] Le web IGNORE le 86 des EXTRAS et VARIATIONS — la borne/caisse les bloquent, le web les vend
- **Preuve émission** : passer un supplément en rupture (ex. « Sauce en plus », « Viande en plus ») = `AvailabilityController::toggleExtra` → `AvailabilityService::toggleStockable` → `dispatchStockableEvent` → `event(new ItemExtraAvailabilityChanged(...))` (`app/Services/Menu/AvailabilityService.php:810`) ; idem variation `:821`.
- **Borne = OK** : `EventServiceProvider.php:251-272` bridge ces events vers `PersistCatalogChangedToOutbox` + invalidation cache kiosk → la borne reçoit `CatalogChanged` (`KioskAppComponent.vue:554`), refetch le menu, **grise** l'extra.
- **Web = AVEUGLE** : le miroir de dispo web ne poll QUE l'item-level : `refreshAvailability()` lit `/api/frontend/item` → `is_available` par **item** (`api.js:803-817`) ; `unavailableMap()` opère sur `window.W_ITEMS` = items (`api.js:831-839` ; boucle `index.html:326-330`, 25 s). **Aucune** notion de dispo extra/variation. Le supplément 86 reste sélectionnable.
- **Backend ne rattrape PAS** : à la soumission web, `FrontendOrderService.php:367-369` appelle `AvailabilityService::assertItemsOrderableForBranch()` qui ne valide QUE `Item` + `ItemBranchAvailability` par `item_id` (`AvailabilityService.php:349-395`) — **jamais** `ItemExtra`/`ItemVariation` ni leur `StockLevel.manual_unavailable_reason`.
- **Repro** : 86 « Sauce en plus » en caisse → borne grise la sauce → sur lecayenne.fr le client l'ajoute → submit accepté → commande web avec un supplément en RUPTURE. Divergence **permanente** borne(grisé)↔web(vendu) jusqu'au dé-86.
- **Blast radius** : opérationnel/fulfillment (cuisine ne peut servir le supplément). **NF525-safe** : le prix reste correct, `expected_total` matche (le 86 ne change pas le prix, donc la garde prix ne mord jamais). C'est une violation de cohérence d'état, pas money-path.

### [P2] `OrderPaymentStatusChanged` (remboursement / flip paiement) diffusé mais AUCUNE surface ne l'écoute
- **Émission** : `broadcast_as='OrderPaymentStatusChanged'` (`app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php:61`), dispatché au refund (`PersistOrderPaymentStatusChangedOnRefundCreated.php:129`), au flip paiement admin (`OrderService.php:2882`) et au drain Stripe.
- **Zéro abonné** : absent de `BROADCAST_MAP` (`eventContract.js:18-29`) ; aucun `onEvents` ne le liste (grep confirmé). **Le code prétend explicitement l'inverse** : `EventServiceProvider.php:220-222` (« MUST run first so POS / admin / OSS clients still receive the broadcast »), `OrderService.php:2881` (« Domain event for outbox / KDS / Z-report »).
- **Repro** : rembourser une commande PAID encore en cours (statut PREPARED, paiement PAID→REFUNDED). `REFUNDED(20)` n'est pas poussé : KDS (poll 60 s quand WS up), OSS, PosTracker, Encaissement attendent leur **poll**. Le tracker client web (poll statut 20 s, `funnel.jsx`) ne voit RIEN car le refund ne touche pas `status` → il reste sur « prête ».
- **Sévérité** : latence, **pas** divergence permanente — au poll suivant le filtre paiement du board (`KitchenReleaseRule::applyBoardReleaseFilter`, `KitchenReleaseRule.php:130-140` : PAID|PENDING_COUNTER|POS-cash) exclut REFUNDED. Mais la garantie temps-réel documentée est non tenue sur POS/OSS/KDS.

### [P2] Transition ACCEPT→PREPARING (auto-prepare on paid) SANS `OrderStatusChanged`
- **Preuve** : dans `changePaymentStatus`, `OrderService.php:~2820-2839` flippe `status` ACCEPT→PREPARING (`AutoPrepareOnPaidPolicy`) + `recordTransition`, mais ne dispatch QUE `OrderPaymentStatusChanged` (`:2882`). Les seuls `OrderStatusChanged::dispatch` d'OrderService sont `:2179 / :2309 / :2573` — **pas** dans ce bloc.
- **Repro** : admin passe une commande web à PAID alors que statut=ACCEPT → promotion PREPARING silencieuse. L'OSS (filtre `[PREPARING,PREPARED]`, `OrderStatusScreenOrderService.php:63`) devrait l'afficher, mais aucun push (poll seul). Le tracker client web (abonné `OrderStatusChanged`) ne reçoit jamais le passage « en préparation ». Latence.

### [P2] `SettingsUpdated` diffusé, aucun abonné client
- **Preuve** : `broadcast_as='SettingsUpdated'` (`PersistSettingsUpdatedToOutbox.php:69`), émis par `CurrencyController:38/50/62`, `TaxController:39/51/63`, `CompanyController:36`, `OrderSetupController:37`, `SiteController:40`. Aucun `onEvents`.
- **Repro** : changer le taux de TVA / la devise / l'order-setup en admin → aucune surface (borne/POS) ne réagit en temps réel ; elles gardent les réglages périmés jusqu'à un reload manuel. Fail-safe : les prix étant backend-SSOT, un total périmé → `422 expected_total` au submit. Op rare, non money-path, mais l'event est mort.

### [P2/P3] `BranchStatusChanged` diffusé, aucun abonné client
- **Preuve** : `broadcast_as='BranchStatusChanged'` (`PersistBranchStatusChangedToOutbox.php:74`), `BranchController.php:72/99`. Aucun `onEvents`.
- **Repro** : fermer la branche (status INACTIVE) → borne/web ne réagissent pas. Quasi-moot en V1 (mono-branche Le Cayenne toujours ouverte), mais l'intention temps-réel est morte.

### [P3] `MenuSnapshot` ne bump PAS au 86 extra/variation (piège B8 confirmé côté mécanique)
- **Preuve** : `BumpMenuSnapshotOnItemAvailabilityChanged` n'est câblé QUE sur `ItemAvailabilityChanged` (`EventServiceProvider.php:238-239`) ; il est **absent** des listeners de `ItemExtraAvailabilityChanged` / `ItemVariationAvailabilityChanged` (`:251-272`). Un 86 extra-only ne fait pas avancer `snapshot_version`.
- **Impact réel aujourd'hui ≈ nul** : `MenuSnapshot.php:11-15` prévoit un client qui gate son refetch sur `snapshot_version`, mais aucun endpoint HTTP ne lit cette version (grep vide) et la borne est couverte en direct par le bridge `CatalogChanged` ; le web n'utilise pas MenuSnapshot. **Dette latente** : si un jour un client s'appuie sur le gate snapshot au reconnect WS, il restera périmé sur un 86 extra/variation survenu pendant l'outage.

### [P3] `OrderTableChanged` consommé par le KDS seul ; le POS Floorplan poll (15 s)
- **Preuve** : dispatch `DiningTableService.php:269/436` ; abonné unique KDS (`KitchenDisplaySystemComponent.vue:2474`). `FloorplanComponent.vue` ne s'abonne pas — il poll toutes les 15 s (`:122`).
- **Impact** : réaffectation de table → KDS instantané, plan de salle POS à ≤15 s. Auto-cohérent au poll (même source DB). Latence bénigne.

---

## REFUTED (vérifiés SAINS — pas de finding)

- **Commande REFUNDED reste sur les boards KDS/OSS** → REFUTED. KDS filtre `visibleStatuses` + `applyBoardReleaseFilter` (`KitchenDisplaySystemOrderService.php:80`) ; OSS filtre `[PREPARING,PREPARED]` + release filter (`OrderStatusScreenOrderService.php:63/71`). `REFUNDED(20)` exclu par le filtre paiement → retirée au poll (cf. P2 pour la latence de push).
- **Stock libéré (annulation/refund) laisse l'item grisé** → REFUTED. `StockService::releaseForOrder` (`StockService.php:52`) → `dispatchAvailabilityChanged` (`:158`) + `syncItemAvailabilityForStockLevel` (`:264`, dé-86 quand `on_hand` repasse >0, `:318`) ré-émet `ItemAvailabilityChanged` → borne/web/KDS dé-grisent. SAIN.
- **86 item-level non propagé au web** → REFUTED. `refreshAvailability` poll `is_available` branch-aware (`api.js:803-817`) toutes les 25 s + focus (`index.html:322-342`) → le web grise bien un ITEM 86 (seuls extras/variations manquent, cf. P1).
- **Fidélité incohérente cross-surface** → REFUTED (hors mécanisme temps-réel). Le solde est lu à la demande depuis le grand-livre (aucun event outbox de solde) ; borne/caisse/web ré-interrogent l'API → cohérence requête-scoped. Les P0 d'asymétrie débit↔remboursement sont healés (BRAIN 2026-08-02).
- **Coupon/catalogue non propagés** → REFUTED. `CouponChanged`/`CatalogChanged` abonnés borne (`KioskAppComponent.vue:554/571`) ; web poll le catalogue ; garde prix `422 expected_total` en filet.

---

## VERDICT

**Cœur statut/board/stock/prix = SAIN** : KDS et OSS partagent le SSOT `KitchenReleaseRule` (filtres statut + paiement), les commandes annulées/remboursées tombent des boards, le 86 item-level et le dé-86 au restock se propagent partout, `expected_total` garde le money-path. **0 P0.**

**Le défaut de fond = dimension PUSH temps-réel, pas la cohérence éventuelle** : cinq events sont émis sans abonné client (`OrderPaymentStatusChanged`, `SettingsUpdated`, `BranchStatusChanged`) ou avec un abonné trop étroit (`ItemExtra/VariationAvailabilityChanged` → StockDashboard seul ; `OrderTableChanged` → KDS seul), plusieurs avec un commentaire backend affirmant explicitement le contraire. Le polling rattrape tout SAUF le seul cas de **divergence permanente** :

**P1 unique à corriger** : le web ne reflète pas le 86 des extras/variations (UI aveugle + garde backend `assertItemsOrderableForBranch` item-only) → un supplément en rupture, grisé sur la borne, reste vendu sur le web. Cross-surface state divergence réelle, permanente, mais NF525-safe (prix correct). Reco : étendre le poll web `/api/frontend/item` (ou un endpoint dispo dédié) aux extras/variations ET étendre `assertItemsOrderableForBranch` aux `ItemExtra`/`ItemVariation` (rempart serveur symétrique borne↔web). Les P2 (abonnements manquants `OrderPaymentStatusChanged`/`SettingsUpdated`) sont à câbler pour honorer l'intention temps-réel documentée, mais ne cassent pas la cohérence (poll = filet).
