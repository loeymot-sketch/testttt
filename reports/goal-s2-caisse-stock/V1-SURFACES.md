# V1 — REGISTRE DES SURFACES CAISSE + STOCK (2026-07-29, base fa172d5f4)

> Cartographie par agent Explore (very thorough) — chaque ligne vérifiée file:line.
> Verdicts visuels : remplis après lecture des captures (agent B).
> ⚠️ APP_URL du repo = `http://127.0.0.1:8766` (`.env:7`) mais serveur local répond sur :8000.

## A. Entrée POS V4 dédiée (`pos-app.js`, blade `admin-pos-v4.blade.php` FROZEN)
| Surface | URL | Accès | Composant | Verdict |
|---|---|---|---|---|
| POS V4 | `/admin/pos-v4` | URL directe (raccourci PC caisse) | `pos/PosComponent.vue` via `pos-app.js:83` | à capturer |
| Floorplan V4 | `/admin/pos-v4/floorplan` | CACHÉE | `pos/FloorplanComponent.vue` (`pos-app.js:89`) | à capturer |

Stubs navigation dure `pos-app.js:112-165` (tracker, OSS, listes, encaissement, historique, login) + redirects compat `:93-100` + fallback `:166`.

## B. Caisse bundle complet (`app.js`)
| Surface | URL | Accès | Composant | Verdict |
|---|---|---|---|---|
| POS | `/admin/pos` | sidebar | `PosComponent.vue` (`posRoutes.js:15`) | à capturer |
| Floorplan | `/admin/pos/floorplan` | CACHÉE | `FloorplanComponent.vue` (`posRoutes.js:25`) | à capturer |
| Liste commandes POS | `/admin/pos-orders` | sidebar | `posOrders/PosOrderListComponent.vue` (`posOrderRoutes.js:26`) | à capturer |
| Détail commande | `/admin/pos-orders/show/:id` | ligne | `PosOrderShowComponent.vue` (`posOrderRoutes.js:36`) | à capturer |
| Suivi/tracker | `/admin/pos-orders-tracker` | CaisseSecondaryNav + barre opérateur (cachée sidebar) | `pos/PosOrdersTrackerComponent.vue` (`posOrderRoutes.js:52`) | à capturer |
| Encaissement | `/admin/encaissement` | sidebar + SecondaryNav | `encaissement/EncaissementComponent.vue` (`encaissementRoutes.js:10`) | à capturer |
| Historique | `/admin/historique` | sidebar + SecondaryNav | `orderHistory/HistoriqueListComponent.vue` (`historiqueRoutes.js:25`) | à capturer — ⚠️ état vide `v-if` sans `v-else` visible (`:102`) à vérifier |
| Cash overview | `/admin/cash-overview` | sidebar | `cashOverview/CashOverviewComponent.vue` (`cashOverviewRoutes.js:11`) | à capturer (empty + alerte cash non enregistré `:178`) |
| Sessions caisse (rapport) | `/admin/cash-sessions-report` | **CACHÉE** (deep-link seul) | `cashSessionReport/CashSessionReportListComponent.vue` | à capturer |
| Cash livreurs | `/admin/delivery-boy-cash-sessions` | sidebar (⚠️ module deliveryBoys masqué par ailleurs) | `deliveryBoyCashSessionRoutes.js:18` | à capturer |
| Transactions | `/admin/transactions` | sidebar | `transactionRoutes.js:8` | à capturer |
| Écran client (OSS) | `/admin/order-status-screen` | SecondaryNav `_blank` | `orderStatusScreen/OrderStatusScreenComponent.vue` | à capturer |
| Outbox observabilité | `/admin/observability/outbox` | CACHÉE | `observabilityRoutes.js:20` | à capturer |

## C. Stock + Catalogue
| Surface | URL | Accès | Composant | Verdict |
|---|---|---|---|---|
| Hub Produits & Stock | `/admin/catalog-hub?tab=stock` | sidebar | `items/CatalogHubComponent.vue` (`itemRoutes.js:98`) — 2 onglets `v-if` | à capturer ×2 onglets |
| Stock rupture | `/admin/stock/rupture` | CACHÉE (remplacée par hub) | `stock/StockRuptureDashboardComponent.vue` (`stockRoutes.js:12`) | à capturer |
| Vue stock unifiée | `/admin/stock/unified` | sidebar | `stock/UnifiedStockViewComponent.vue` (`stockRoutes.js:23`) | à capturer |
| Studio catalogue | `/admin/items/studio` | sidebar (enfant virtuel) | `items/CatalogStudioComponent.vue` (`itemRoutes.js:41`) | à capturer |
| Liste items plate | `/admin/items?create=1` | CACHÉE (redirect studio sinon) | `items/ItemListComponent.vue` | à capturer |
| Fiche item | `/admin/items/show/:id` | ligne | `ItemShowComponent.vue` | à capturer |
| Composer par item | `/admin/items/:id/composer` | **INACCESSIBLE** — flag `wizard_per_item_demo` OFF (`config/catalog_v15.php:174`, absent .env) → redirect | n/a |
| Composer par catégorie | `/admin/categories/:id/composer` | CACHÉE, **non gardée par le flag** (seule porte réelle) | `items/composer/ProductComposerEditorComponent.vue` (`itemRoutes.js:139`) | à capturer |
| Ingrédients | `/admin/ingredients` (+3 variantes byType CACHÉES) | sidebar | `ingredients/IngredientListComponent.vue` | à capturer |
| Scan facture | `/admin/purchasing/scan` | sidebar | `purchasing/PurchaseScanComponent.vue` (`purchasingRoutes.js:9`) | à capturer (bannière démo si OpenAI off) |
| Rapport items | `/admin/items-report` | sidebar | `itemsReportRoutes.js:21` | à capturer |

## D. Blade autonomes
| Surface | URL | Auth | Verdict |
|---|---|---|---|
| Stock mobile | `/m` | PIN 2580 (`EnsureMobileStockPin`, fail-closed) | à capturer (PIN, erreur, stock, no-results) |
| Carnet | `/carnet` | PIN — ⚠️ défaut commité `2468` (`config/daily_book.php:12`), warning boot | à capturer |
| Ponts impression | `/dl/caisse-bridge.js` | aucune | n/a (téléchargement) |

## E. Anomalies cartographie (à disputer en RED avant statut finding)
1. **[SEC-cand] `/admin/pos-v4/*` sans middleware auth serveur** — blade servie à un anonyme, bounce client-only (`routes/web.php:110`, `AdminPosV4Controller.php:26`, `pos-app.js:174`). À évaluer : exposition réelle (HTML shell vs données).
2. **[CONF-cand] `DAILY_BOOK_PIN` absent du .env** → PIN par défaut commité `2468` actif (`config/daily_book.php:12`, warning `AppServiceProvider.php:199`).
3. **[NAV-cand] `delivery-boy-cash-sessions` visible sidebar** alors que module `deliveryBoys` est dans `V1_HIDDEN_MENU_MODULES` (`v1-hidden-modules.js`).
4. **[DEAD] `items/AvailabilityToggleComponent.vue`** — orphelin (0 référence). **[DEAD] `items/wizard/ProductCreateWizardComponent.vue`** — orphelin (ancien wizard).
5. **[FLAG] Routes composer item + wizard-launcher inaccessibles** (flag off) mais composer accessible par la route catégorie non gardée — incohérence de garde.
6. **[UX-cand] Historique : état vide sans `v-else`** (`HistoriqueListComponent.vue:102`) — vérifier visuellement.
7. **[ROUTE] `admin.items.create`** déclare un composant jamais monté (redirect systématique) — hygiène mineure.

## E-bis. Verdicts visuels (captures `tests/captures/goal-s2-v1-2026-07-29/`, toutes LUES)
| Surface | PNG | Verdict | Défauts |
|---|---|---|---|
| /admin/pos | 01-pos.png | DÉFAUT | **V-01 P2** header : « CAISSE LE CAYENNE »/« COMMANDES » superposés + pilule « À encaisser 5 » recouvre « Commande rapide » |
| Grille catégorie Tacos | 02-pos-categorie.png | DÉFAUT | **V-02 P2** tuiles upsell/suppléments AVANT les produits Tacos (produits sous la ligne de flottaison) · **V-03 P2** images manquantes (tuiles + pastilles catégories) · **V-04 P3** libellé anglais « Upsell item » |
| Wizard caisse Tacos M | 03-wizard.png | DÉFAUT mineur | **V-05 P2** format monétaire anglais « €6.90 » (pos-wizard.js FROZEN → signalement/gate) · latence variable ouverture (232 ms→~4 s selon run) |
| Panier rempli | 04-pos-panier.png | OK réserve | **V-06 P3** « 1 Articles » (pluriel) |
| /admin/encaissement | 05-encaissement.png | OK réserve | **V-07 P2cand** badge « Caisse » vs POS « À encaisser borne » — source incohérente |
| Tracker | 06-suivi-tracker.png | OK réserve | **V-08 P1cand** colonne À ENCAISSER = 0 alors qu'Encaissement montre 5 |
| Historique + filtres | 07*.png | OK réserves | **V-09 P3** colonne DATE tronquée 1280px · **V-10 P3** pagination « Previous/Next » anglaise |
| Écran client OSS | 08-ecran-client-oss.png | OK | — |
| Stock rupture | 09-stock-rupture.png | DÉFAUT | **V-11 P2** cartes produit sans NOM + 4 images identiques génériques · **V-12 P2** pollution « E2E_PLAYWRIGHT_STUDIO_* » sidebar |
| Conso & Stock | 09b-conso-stock.png | OK | — |
| Scan facture | 09c-scan-facture.png | OK | bannière démo OpenAI attendue |
| /admin/items + éditeur | 10*.png | DÉFAUT | **V-12** pollution E2E ligne 1 · **V-13 P2** radios « Oui / N° » (MIS EN AVANT, SUGGESTION CAISSE) · **V-14 P3** « Choose File » natif anglais |
| /m (PIN 2580) | m-0*.png | OK | **V-15 P3cand** « Aucune rupture ✅ » vs 12 ruptures matières (périmètres différents — trompeur ?) |
| /carnet (PIN 2468) | c-0*.png | OK | **V-16 P3** dates natives format US (locale headless — vérifier vrai poste) |

Notes env : SPA :8000 appelle l'API :8766 (les 2 UP). Commandes borne A0036→A0039 apparues pendant les runs = session parallèle S1 (aucune commande créée par S2). Compte `pos@` redirigé hors stock/items (perm) — captures faites avec `admin@`.

## F. Baselines techniques
- PHPUnit filtre `Pos` : **699 tests / 2827 assertions / 0 échec** (9 skipped, 2 incomplete) — VERT.
- PHPUnit filtre `Stock|Purchas|RawMaterial|Encaissement` : **248 tests / 1583 assertions / 0 échec** (5 skipped) — VERT.
