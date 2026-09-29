# CARTO_SURFACES — Inventaire exhaustif des surfaces CAISSE
> Worktree `goal-caisse-vision-2026-08-24` · branche `pos/category-first-caisse-2026-06-23` · 2026-08-24
> Périmètre = système **CAISSE** au sens `SYSTEM_MAP.md §2` (+ canal agrégateur Uber, ticket promo, roue — tous gated `pos` / `pos-orders`).
> LECTURE SEULE. Chaque chemin ci-dessous provient d'une sortie réelle `ls`/`grep`/`sed`.

## Tableau des surfaces

| # | URL (route réelle) | Nom de route Vue | Composant racine (file:line de la définition de route) | Contrôleur / endpoint API principal | Rôle pour le caissier |
|---|---|---|---|---|---|
| 1 | `/admin/pos-v4` (+ `/{any?}`) | `admin.pos.v4` | `resources/js/pos-app.js:89-94` → `PosComponent` (import `pos-app.js:81`) ; entrée serveur `routes/web.php:110` | `AdminPosV4Controller::index` (`app/Http/Controllers/Admin/AdminPosV4Controller.php:26-33`) → vue `admin-pos-v4` ; puis `POST admin/pos/quote` (`routes/api.php:969`), `POST admin/pos` (`api.php:971`) | L'écran de vente principal (entrée dédiée, bundle `pos-app.js`) : composer la commande, encaisser, imprimer. |
| 2 | `/admin/pos-v4/floorplan` | `admin.pos.v4.floorplan` | `resources/js/pos-app.js:95-100` → `FloorplanComponent` (import `pos-app.js:83`) | aucun appel `axios` propre trouvé dans `FloorplanComponent.vue` (grep vide) — `à vérifier` | Plan de salle depuis l'entrée POS V4 (sur-place). |
| 3 | `/admin/pos` | `admin.pos` | `resources/js/router/modules/posRoutes.js:14-23` → `PosComponent` (import `posRoutes.js:10`) | idem #1 ; + `GET admin/pos/web-orders/pending` (`api.php:1054`), `GET admin/pos/web-orders/paid` (`api.php:1111`), `GET admin/pos/counter-collect/pending` (`api.php:973`), `GET admin/pos/system-health` (`api.php:1158`) | Même caisse, servie depuis la SPA admin (`app.js`). Dans `pos-app.js:105` ce nom redirige vers `admin.pos.v4`. |
| 4 | `/admin/pos/floorplan` | `admin.pos.floorplan` | `resources/js/router/modules/posRoutes.js:24-34` → `FloorplanComponent` | `à vérifier` (voir #2) | Plan de salle depuis la SPA admin. Redirigé vers #2 par `pos-app.js:107`. |
| 5 | `/admin/pos-orders` | `admin.pos-orders` (redirect → `.list`) | `resources/js/router/modules/posOrderRoutes.js:12-22` → `PosOrderComponent` | — (wrapper) | Conteneur de la section commandes caisse. |
| 6 | `/admin/pos-orders` | `admin.pos-orders.list` | `posOrderRoutes.js:24-34` → `PosOrderListComponent` | `GET admin/pos-order` (`api.php:1357` groupe `posOrder.` ; store `resources/js/store/modules/posOrder.js:179`) | Liste des commandes d'origine caisse (filtres, export). |
| 7 | `/admin/pos-orders/show/:id` | `admin.pos-orders.show` | `posOrderRoutes.js:35-45` → `PosOrderShowComponent` | `GET admin/pos-order/show/{id}` (`posOrder.js:226`) ; actions `change-status` (`posOrder.js:258`), `change-payment-status` (`:272`) | Détail d'une commande : ticket, statut, remboursement, fidélité. |
| 8 | `/admin/pos-orders-tracker` | `admin.pos-orders.tracker` | `posOrderRoutes.js:48-60` → `PosOrdersTrackerComponent` (import `posOrderRoutes.js:10`) | `GET admin/pos-order` (store) + `GET admin/pos-order/stale` (`PosOrdersTrackerComponent.vue:1656`) + `GET admin/pos/counter-collect/pending` (`:1819`) ; live Echo `branch.{id}` | Tableau kanban plein écran du suivi des commandes en cours (ACCEPT→DELIVERED). |
| 9 | `/admin/encaissement` | `admin.encaissement` | `resources/js/router/modules/encaissementRoutes.js:10-20` → `EncaissementComponent` | `GET admin/pos/counter-collect/pending` (`EncaissementComponent.vue:146` ; route `api.php:973`, nom `:1045`) ; confirm `api.php:1170`, cancel `api.php:1226` | File unique des commandes à encaisser au comptoir (borne Plan B + walk-in). |
| 10 | `/admin/historique` | `admin.historique` (redirect → `.list`) | `resources/js/router/modules/historiqueRoutes.js:11-21` → `HistoriqueComponent` | — (wrapper) | Conteneur historique unifié. |
| 11 | `/admin/historique` | `admin.historique.list` | `historiqueRoutes.js:22-33` → `HistoriqueListComponent` | `GET admin/order-history` (`api.php:1352` groupe `orderHistory.`) | Historique de TOUTES les commandes (borne / caisse / web / livraison) avec badge d'origine + colonnes NF525. |
| 12 | `/admin/cash-overview` | `admin.cash-overview` | `resources/js/router/modules/cashOverviewRoutes.js:10-20` → `CashOverviewComponent` | `GET admin/cash-overview` (`CashOverviewComponent.vue:433` ; route `api.php:1528` → `CashOverviewController::index`) | Vue unifiée des transactions encaissées (POS, borne, livreur) pour le rapprochement d'écart. |
| 13 | `/admin/cash-sessions-report` | `admin.cash-sessions-report` | `resources/js/router/modules/cashSessionReportRoutes.js:9-19` → `CashSessionReportListComponent` | `GET admin/cash-sessions-report` (`CashSessionReportListComponent.vue:251` ; route `api.php:1517` → `CashSessionReportController::index`) | Rapport quotidien des sessions de caisse (ouverture / fermeture / nb transactions). |
| 14 | `/admin/uber-photo` | `admin.uberPhoto` | `resources/js/router/modules/uberPhotoRoutes.js:11-21` → `UberPhotoCaptureComponent` | `POST admin/uber/photo/scan` (`api.php:453`), `GET admin/uber/photo/recent` (`api.php:455`), confirm `:457`, discard `:459`, reprint `:463` — `UberPhotoCaptureController` | Photographier un ticket Uber sur tablette et l'envoyer en cuisine. |
| 15 | `/admin/promo-flyer` | `admin.promoFlyer` | `resources/js/router/modules/promoFlyerRoutes.js:12-22` → `PromoFlyerComponent` | `GET/POST admin/promo-flyer` (`store/modules/promoFlyer.js:25,28`), reprint `:31`, revoke `:34` | Émettre / réimprimer un ticket promo nominatif au comptoir. |
| 16 | `/admin/promo-flyer/settings` | `admin.promoFlyer.settings` | `promoFlyerRoutes.js:23-32` → `PromoFlyerSettingsComponent` | `GET/PATCH admin/promo-flyer/settings` (`promoFlyer.js:19,22`) | Réglages du ticket promo. |
| 17 | `/admin/roue` | — (page Blade, hors routeur Vue) | `routes/web.php:161-162` → `WheelAccessController::show` (vue `resources/views/admin/wheel/acces.blade.php`) | `WheelAccessController` ; passe signée via `POST admin/wheel/screen-pass` (`api.php:961`, appelée par `CaisseSecondaryNav.vue:123`) | Accueil de la roue : code d'accès + liste des écrans. |
| 18 | `/admin/roue-validation` | — (Blade) | `routes/web.php:186-188` → `WheelCounterController::show` (`views/admin/wheel/validation.blade.php`) | `POST /admin/roue-validation` (`web.php:189`) | Valider un tour de roue au comptoir. |
| 19 | `/admin/roue-borne` | — (Blade) | `routes/web.php:213-215` → `WheelCounterController::kiosk` (`views/admin/wheel/borne.blade.php`) | — | Écran d'attente plein écran de la tablette comptoir (QR renouvelé). |
| 20 | `/admin/roue-lot` | — (Blade) | `routes/web.php:217-219` → `WheelPrizeController::show` (`views/admin/wheel/lot.blade.php`) | `POST /admin/roue-lot/remettre` (`web.php:220`) | Remettre le lot gagné à un client. |
| 21 | `/admin/roue-historique` | — (Blade) | `routes/web.php:229-230` → `WheelPrizeController::history` (`views/admin/wheel/historique.blade.php`) | — | Lignes de ce qui a été gagné / remis / reste dû. |
| 22 | `/admin/roue-reglages` | — (Blade) | `routes/web.php:208-209` → `WheelSettingsController::show` (`views/admin/wheel/reglages.blade.php`) | `POST /admin/roue-reglages` (`web.php:210`) | Réglages du jeu (lien d'avis, comptes). |
| 23 | `/admin/order-status-screen` | `admin.order-status-screen` | `resources/js/router/modules/orderStatusScreenRoutes.js:7-17` → `OrderStatusScreenComponent` | groupe `oss-order.` (`SYSTEM_MAP.md §3`, `à vérifier` ligne exacte) | Écran client, ouvert en nouvel onglet depuis la caisse (`CaisseSecondaryNav.vue:38-47`). **Voie KDS/OSS**, pas CAISSE. |

Gates : `permissionUrl: "pos"` pour #1-#4 ; `"pos-orders"` pour #5-#11 et #14-#16 ; `"cash-sessions-report"` pour #12-#13 (`BackendMenuComponent.vue:197` remappe l'entrée sidebar `cash-overview` sur ce droit) ; `roue` → `pos-orders` (`BackendMenuComponent.vue:186`) puis middleware `wheel.access` (`web.php:185`).

---

## 1. Surfaces de VISIONNAGE des commandes

| Surface | Ce qu'elle affiche | Composant qui rend |
|---|---|---|
| `/admin/pos` · `/admin/pos-v4` | Panneaux latéraux : commandes web en attente (`admin/pos/web-orders/pending`, `PosComponent.vue:4363`), web payées (`:4396`), file d'encaissement (`:4461`) | `PosComponent.vue` |
| `/admin/pos-orders-tracker` | Kanban temps réel des commandes en cours + « en souffrance » (`/pos-order/stale`) + file comptoir, toutes sources | `PosOrdersTrackerComponent.vue` |
| `/admin/pos-orders` | Liste des commandes d'origine caisse | `PosOrderListComponent.vue` |
| `/admin/pos-orders/show/:id` | Détail + ticket d'une commande | `PosOrderShowComponent.vue` (ticket : `PosOrderReceiptComponent.vue:466`, carte : `PosOrderMapComponent.vue:473`) |
| `/admin/encaissement` | File des commandes en attente d'encaissement (borne + walk-in) | `EncaissementComponent.vue` (+ `PosCounterCollectModal.vue:88`) |
| `/admin/historique` | Historique de TOUTES les commandes, tous canaux, badge d'origine + NF525 | `HistoriqueListComponent.vue` (+ `ReceiptComponent.vue:219`) |
| `/admin/cash-overview` | Toutes les transactions encaissées (POS / borne / livreur) | `CashOverviewComponent.vue` |
| `/admin/cash-sessions-report` | Sessions de caisse jour par jour | `CashSessionReportListComponent.vue` |
| `/admin/uber-photo` | 20 dernières captures de tickets Uber (`/uber/photo/recent`) | `UberPhotoCaptureComponent.vue` |
| `/admin/order-status-screen` | Commandes en préparation / prêtes (côté client) | `OrderStatusScreenComponent.vue` — **voie KDS/OSS** |

Aucune surface caisse dédiée « commandes téléphone » ou « plateforme » n'a été trouvée : les commandes plateforme passent par `/admin/uber-photo` (capture) et se retrouvent dans le tracker et l'historique. `/admin/online-orders` existe (`onlineOrderRoutes.js:17`) mais est gated `online-orders`, hors des droits caisse listés — **voie CENTRAL**.

## 2. Zones FROZEN touchées

| Fichier de l'inventaire | Ligne de `CLAUDE.md` §7 (`CLAUDE.md:336`) | Où il apparaît dans l'inventaire |
|---|---|---|
| `resources/views/admin-pos-v4.blade.php` | `CLAUDE.md:354-355` — FROZEN strict | Vue rendue par la surface #1 (`AdminPosV4Controller.php:32`) |
| `public/js/pos-wizard.js` | `CLAUDE.md:351-352` | Chargé par cette vue (`admin-pos-v4.blade.php:136`) |
| `public/css/pos-wizard.css` | `CLAUDE.md:353` | Chargé par cette vue (`admin-pos-v4.blade.php:35`) |
| `resources/js/components/admin/pos/PaymentComponent.vue` | `CLAUDE.md:345-346` | Enfant de `PosComponent.vue:2009` — surfaces #1 et #3 |
| `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` | `CLAUDE.md:347-348` | Enfant de `PaymentComponent.vue:379` — surfaces #1 et #3 |

Les trois fichiers du wizard existent bien (`ls` : `pos-wizard.js` 323 357 o, `pos-wizard.css` 47 763 o, `admin-pos-v4.blade.php` 8 314 o).

## 3. Trous d'inventaire

**Aucun composant orphelin trouvé.** Les 19 fichiers de `components/admin/pos/`, les 9 de `pos/v5/`, les 5 de `posOrders/`, et ceux de `cash/`, `cashOverview/`, `cashSessionReport/`, `encaissement/`, `uber/`, `promo/` sont tous soit montés par une route, soit importés par un composant qui l'est. Preuves grep (import → consommateur) :

- `CaisseSecondaryNav.vue` ← `EncaissementComponent.vue:87`, `HistoriqueListComponent.vue:218` (jamais monté par `PosComponent`)
- `PosCounterCollectModal.vue` ← `PosComponent.vue:2014`, `EncaissementComponent.vue:88`, `PosOrdersTrackerComponent.vue:786`
- `PosLoyaltyRedeemModal / PosLoyaltyIdentifyModal` ← `PosComponent.vue:2040-2041`, `PosOrderShowComponent.vue:479-480`
- `PosRefundModal.vue` ← `PosOrderShowComponent.vue:486`, `PosOrdersTrackerComponent.vue:782`
- `PosStockOutflowModal.vue`, `PosSystemHealthPill.vue` ← `PosOrdersTrackerComponent.vue:788, 787` **uniquement** (absents de `PosComponent`)
- `PromoFlyerQuickModal.vue` ← `PosOrdersTrackerComponent.vue:789` uniquement
- `PosCashDrawerSessionDialog.vue` (`admin/cash/`) ← `PosComponent.vue:2085` uniquement
- `ReceiptDuplicataMarker / ReceiptRemboursementMarker` ← `ReceiptComponent.vue:358, 362` (+ `PosOrderReceiptComponent.vue:162`)
- `PromoFlyerPrintListener.vue` ← `DefaultComponent.vue:61` (monté globalement — §6 SHARED)
- `pos/v5/*` : les 9 sont importés par `PaymentComponent.vue:377,379`, `PosComponent.vue:2077-2083`, `PosCounterCollectModal.vue:250`

**Points de vigilance (pas des orphelins, des ambiguïtés) :**

1. **Double routeur, double URL pour la même caisse.** `PosComponent` est monté par DEUX routeurs distincts : `posRoutes.js:14` (`/admin/pos`, bundle `app.js`) et `pos-app.js:89` (`/admin/pos-v4`, bundle `pos-app.js`). Depuis `/admin/pos-v4`, `pos-app.js:105-107` redirige `admin.pos` et `admin.pos.floorplan` vers les variantes `v4` ; depuis la SPA admin, aucune redirection inverse n'existe. Deux URLs vivantes pour le même écran.
2. **Stubs de navigation dans `pos-app.js:118-140`** : `admin.pos-orders.tracker`, `admin.order-status-screen`, `admin.pos-orders.list`, `admin.pos-orders.show` y sont déclarés en `beforeEnter → window.location.assign` (rechargement complet vers le bundle `app.js`). Depuis `/admin/pos-v4`, ces quatre surfaces coûtent donc un rechargement de page.
3. **`FloorplanComponent.vue`** : atteignable par deux routes (#2, #4) mais aucun appel `axios` n'y a été trouvé (`grep "axios\." FloorplanComponent.vue` → vide) ; sa source de données reste `à vérifier`.
4. **Les 6 écrans de la roue** sont des pages Blade hors routeur Vue : ils ne peuvent pas être atteints par `router-link` (d'où le `<a>` explicite en `CaisseSecondaryNav.vue:63` et le drapeau `external: true` en `BackendMenuComponent.vue:168`).
