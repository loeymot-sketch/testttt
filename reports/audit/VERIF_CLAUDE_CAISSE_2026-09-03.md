# Vérification en lecture seule — 5 affirmations d'audit externe (CAISSE)

Dépôt : `testttt` · HEAD `28cd79d5a` · 2026-09-03 · agent lecture seule (aucun fichier de production modifié).

---

## C1 — Tiroir de caisse tronqué à 100 commandes — **CONFIRMÉ**

### Preuve

`resources/js/components/admin/pos/PosComponent.vue:4771-4795` (`_fetchServiceOrdersOnce`) :

```js
paginate: 1,
per_page: 100,
lean: 1,
composition: 1,
from_date: jour.from,
to_date: jour.to,
vuex: false,
```

La réponse est consommée à `PosComponent.vue:4802-4806` :

```js
const list = (res?.data?.data) || [];
this.serviceOrders = Array.isArray(list) ? list : [];
```

et de nouveau à `PosComponent.vue:5311-5312` (`loadReadyOrders`). **Ni `meta.total`, ni `meta.last_page`, ni `meta.current_page` ne sont lus** : `grep -n "tronqu\|truncated\|last_page\|meta.total"` sur `PosComponent.vue` + `PosControlDrawer.vue` ne rend qu'une seule ligne, `PosControlDrawer.vue:172`, qui tronque le **texte d'une composition de ligne**, sans rapport avec la pagination.

Le tri est bien du plus récent au plus ancien : le client n'envoie ni `order_column` ni `order_by`, et `app/Services/OrderService.php:139-140` applique les valeurs par défaut `id` / `desc`.

### L'endpoint serveur

Route : `routes/api.php:1451-1452` → `Route::prefix('pos-order')` / `Route::get('/', [PosOrderController::class, 'index'])`.
Contrôleur : `app/Http/Controllers/Admin/PosOrderController.php:250-261` → `$this->orderService->list($request)`.
Pagination : `app/Services/OrderService.php:137-138` :

```php
$method = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
$methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
```

**Le commentaire de la ligne 4777 est exact** : sans `paginate`, la méthode est `->get('*')`, c'est-à-dire toute la journée, sans plafond. Il n'existe **aucun plafond serveur** sur cette route : le seul garde-fou est la validation `app/Http/Requests/PaginateRequest.php:27` — `'per_page' => ['numeric','min:1','max:1000']`. Le serveur accepterait donc `per_page: 1000`. Le 100 est un choix **exclusivement client**.

### Tests exerçant > 100 commandes : **aucun**

- `tests/js/posTrackerStaleness.spec.js:231-241` verrouille l'**émission** des paramètres (`paginate:1, per_page:100, lean:1, vuex:false`) — il fige la troncature, il ne l'éprouve pas.
- `tests/Feature/Pos/PosOrderListLeanPaginationTest.php:58-70` : 3 commandes, `per_page: 2`. Prouve que le plafond est honoré, pas ce que devient la journée au-delà.
- `tests/js/filesControleModule.spec.js` : jeux de 1 à 7 commandes.
- Aucun test JS ni Feature ne construit > 100 commandes sur cette route.

### Conséquence réelle en rush

Au-delà de 100 commandes dans la journée de service (`serviceDayRange`, `resources/js/helpers/posServiceDay.js:42`), le tri `id desc` garde les 100 **plus récentes**. Ce qui disparaît est donc exactement le contraire de ce qui est urgent : **les plus anciennes commandes du service**, celles qui traînent — encaissement borne en attente, commandes prêtes non retirées.

Deviennent faux, en silence :
- `filesControle` (`PosComponent.vue:3030`) et les quatre files du tiroir (`filesDeControle`) ;
- les badges `pos-control-badge-cash` / `pos-control-badge-ready` et les compteurs d'onglet ;
- `activeOrdersStats` (`PosComponent.vue:4807-4815`) — badge « Suivi n » ;
- `readyOrders` (`PosComponent.vue:5313+`) — raccourci « Prêt à livrer » ;
- `attenteCuisineTicket` / `phraseAttenteCuisine` (`PosComponent.vue:3059-3067`) : « depuis N min » et « rang k » sont calculés sur l'ensemble tronqué, donc **sous-estimés**.

Le même plafond existe dans le tableau de suivi, `PosOrdersTrackerComponent.vue:1856` — même défaut, même correctif.

Atténuation partielle : `anciennesAEncaisser` (`PosComponent.vue:3045-3053`) compte les encaissements **antérieurs** à la journée depuis `kioskCashOrders`, une autre source. La troncature **intra-journée** n'est atténuée par rien.

### Correctif minimal

Le serveur renvoie déjà `meta` via `LengthAwarePaginator`. Lire `res.data.meta.total` dans `_fetchServiceOrdersOnce`, le stocker, et le passer au tiroir pour afficher un bandeau « n sur N » quand `total > list.length` — exactement le patron déjà retenu côté serveur pour `/stale` (`PosOrderController.php:377-382`, clé `truncated`). Monter `per_page` à 300 (`PaginateRequest` autorise 1000) réduit la fenêtre mais ne remplace pas le signal : une troncature silencieuse se lit comme « il n'y a que ça ».

---

## C2 — Enums recopiées en dur — **CONFIRMÉ, mais P2 (aucune divergence aujourd'hui)**

### Valeurs recopiées

`resources/js/support/filesControle.js:18-23` : `STATUT_PREPARED=8`, `STATUT_DELIVERED=13`, `STATUT_CANCELED=16`, `STATUT_REJECTED=19`, `STATUT_RETURNED=22`, `PAIEMENT_REFUNDED=20`.

`resources/js/support/fileCuisine.js:34-40` : `STATUT_ACCEPT=4`, `STATUT_PREPARING=7`, `PAIEMENT_PAID=5`, `PAIEMENT_PENDING_COUNTER=15`, `PAIEMENT_REFUNDED=20`, `TYPE_POS=15`, `REGLEMENT_ESPECES=1`.

### Les enums JS canoniques existent et couvrent les 4 familles

`resources/js/enums/modules/orderStatusEnum.js`, `paymentStatusEnum.js`, `orderTypeEnum.js`, `posPaymentMethodEnum.js`. `PosComponent.vue` importe d'ailleurs `orderStatusEnum` et s'en sert (`PosComponent.vue:4810-4812`).

### Concordance avec la source PHP — vérifiée une à une

| Constante | PHP | enum JS | copie |
|---|---|---|---|
| ACCEPT / PREPARING / PREPARED | `app/Enums/OrderStatus.php:8,9,10` = 4/7/8 | 4/7/8 | 4/7/8 |
| DELIVERED / CANCELED / REJECTED / RETURNED | `OrderStatus.php:12-15` = 13/16/19/22 | idem | idem |
| PAID / PENDING_COUNTER / REFUNDED | `app/Enums/PaymentStatus.php:7,9,10` = 5/15/20 | idem | idem |
| POS | `app/Enums/OrderType.php:9` = 15 | 15 | 15 |
| CASH | `app/Enums/PosPaymentMethod.php:7` = 1 | 1 | 1 |

**Zéro divergence à ce jour.** Ce n'est donc pas un P0 : c'est une dette de couplage.

### Ce que les tests prouvent réellement

`tests/js/fileCuisineModule.spec.js:18-21` assume la duplication à voix haute : « Les valeurs numériques sont écrites en clair (4/7/8, 5/15/20, 15/25, 1) exprès […] un test qui importerait les mêmes constantes que le code testé ne prouverait rien ». Le raisonnement est juste **sur le comportement du module**, mais il ne prouve **que la copie** : les deux specs codent les mêmes littéraux que le module. Aucune sentinelle ne compare le JS au PHP — `grep -rln "enums/modules" tests/Feature tests/Unit` ne rend qu'un fichier hors sujet (`KioskOfflinePaymentScopeTest.php`).

**Conséquence** : le jour où une constante PHP bouge, rien ne rougit — ni les specs JS (littéraux figés côté test), ni PHPUnit (n'a jamais lu le JS). La caisse rangerait alors une commande dans la mauvaise file, sans alerte.

**Correctif minimal** : une sentinelle PHPUnit qui lit `resources/js/enums/modules/*.js`, en extrait les paires, et les compare à `App\Enums\*` par réflexion. Elle mord des deux côtés sans toucher aux modules de la caisse.

---

## C3 — Contrôles `pos-control-*` absents du DOM servi — **RÉFUTÉ**

### Sélecteurs en source

`grep -rho "pos-control-[a-z0-9-]*" resources/js/` → **28 sélecteurs distincts**, dans `PosControlDrawer.vue` et `PosComponent.vue` (`pos-control-drawer`, `-badge-cash`, `-badge-ready`, `-tab-`, `-panel-`, `-row-`, `-collect-`, `-deliver-`, `-detail`, `-full-page`, `-older-pending`, `-freshness`, `-live`, `-refresh`, `-empty-`, `-rank-`, …).

### Bundle réellement servi

`public/js/pos-app.js` : **0 occurrence** — c'est ce que l'audit a mesuré. Mais `pos-app.js` est le **point d'entrée**, pas le morceau qui porte le composant. `resources/views/admin-pos-v4.blade.php:118-120` charge `manifest.js` + `vendor.js` + `pos-app.js` ; `public/js/manifest.js` porte la carte des morceaux, dont `pos-shell`, et `public/mix-manifest.json` résout `/js/pos-shell.d8da1ca9.js`.

Dans ce morceau :

```
grep -o "pos-control-" public/js/pos-shell.d8da1ca9.js | wc -l   →  29
grep -o "PosControlDrawer" public/js/pos-shell.d8da1ca9.js | wc -l →  3
```

Les **28 sélecteurs distincts** sont présents, un pour un.

### Fraîcheur

`PosControlDrawer.vue` : dernier commit `41025322f`, 2026-09-02 20:56. `public/js/pos-shell.d8da1ca9.js`, `manifest.js`, `pos-app.js` : tous horodatés **2026-09-03 01:28** — soit ~4 h 30 **après** la source. Le bundle n'est pas périmé.

**Conclusion** : le défaut allégué n'existe pas ; l'audit a grepé le mauvais fichier (l'entrée au lieu du morceau différé). Il n'y a **ni bundle périmé, ni défaut de source** : le tiroir est correctement importé (`PosComponent.vue:2345`), enregistré (`:2413`) et monté (`:1970-1985`). Les 5 scénarios Playwright échouent donc pour une **autre** cause, non établie ici — à instrumenter avant tout signalement (cf. CLAUDE.md §3ter, « instrument avant produit »).

---

## C4 — Couverture des composants dashboard — **CONFIRMÉ, et pire que dit**

`grep -rl "<Nom>" tests/js tests/e2e` :

| Composant | Fichiers de test | Monté par `DashboardComponent.vue` |
|---|---|---|
| ChannelStatsComponent | **0** | oui (3 occurrences) |
| CustomerStatsComponent | **0** | **non (0)** |
| FeaturedItemsComponent | **0** | oui |
| MostPopularItemsComponent | **0** | oui |
| OrderStatisticsComponent | **0** | oui |
| RealtimeReportComponent | **0** | oui |
| SalesSummaryComponent | **0** | oui |
| TopCustomersComponent | **0** | **non (0)** |

Les 8 sont bien à zéro référence. L'audit a raison sur `CustomerStats` et `TopCustomers`, et **sous-estime** : `grep -rn "CustomerStatsComponent" resources/js` et `grep -rn "TopCustomersComponent" resources/js` ne rendent chacun **qu'une seule ligne — leur propre `name:`** (`CustomerStatsComponent.vue:33`, `TopCustomersComponent.vue:29`). Ils ne sont montés **nulle part** dans la base : ce sont des **composants morts**, pas des composants non testés.

**Conséquence** : 6 composants réellement affichés sans aucun banc direct ; 2 fichiers morts qui gonflent le bundle et font croire à une couverture fonctionnelle inexistante.
**Correctif minimal** : supprimer les 2 orphelins (ou les monter si le produit les veut) ; un test de montage par composant vivant, sur données vides + données peuplées.

---

## C5 — « 0/16 routes dashboard sans test HTTP direct » — **RÉFUTÉ**

Les 16 suffixes, `routes/api.php:1566-1587` : `total-sales`, `total-orders`, `total-customers`, `total-menu-items`, `order-statistics`, `order-summary`, `sales-summary`, `customer-states`, `top-customers`, `featured-items`, `popular-items`, `realtime-report`, `sla-alerts`, `channel-statistics`, `audit-trail`, `eod-pdf` (POST).

12 ont un appel HTTP littéral et nominatif (`->getJson('/api/admin/dashboard/<suffixe>')`), par exemple `tests/Feature/Dashboard/DashboardBranchScopeMatrixTest.php:77,82,87,94`, `PopularItemsFailClosedTest.php:64`, `EodPdfRecapSentinelTest.php:63`.

Les 4 restants — `order-summary`, `customer-states`, `top-customers`, `audit-trail` — n'apparaissent pas sous forme `dashboard/<suffixe>` **parce que l'URL y est construite dynamiquement** : `tests/Feature/Dashboard/DashboardRoutesAuthzMatrixTest.php:27-33` (constante `ROUTES`) puis `:47`, `:57`, `:70` — `$this->getJson('/api/admin/dashboard/'.$route)`, quatre cas d'autorisation par route. `order-summary` et `customer-states` sont en outre couverts par `DashboardDateContractMatrixTest.php:36-38`.

**Compte réel de routes sans référence : 0/16.** La couverture reste inégale (l'essentiel de la matrice porte sur l'autorisation et la portée de branche, pas sur la justesse des chiffres), mais l'affirmation « aucun test HTTP direct » est fausse. Un `grep` littéral ne voit pas une URL concaténée — c'est l'erreur de méthode qui a produit ce chiffre.

---

## Synthèse

`P1_REELS_CAISSE: 1`

- **P1** — C1 : troncature silencieuse à 100 commandes de la journée de service, `meta.total` ignoré (`PosComponent.vue:4782` + `:4802`, `PosOrdersTrackerComponent.vue:1856`). Aucun test au-delà de 100.
- **P2** — C2 : duplication d'enums, valeurs concordantes ce jour, aucune sentinelle de convergence JS↔PHP.
- **P2** — C4 : 6 composants dashboard vivants sans banc + 2 composants morts.
- **Réfutés** — C3 (mauvais bundle grepé) et C5 (URL concaténée invisible au grep littéral).

### Composants réellement non couverts (0 test direct)
ChannelStats, FeaturedItems, MostPopularItems, OrderStatistics, RealtimeReport, SalesSummary — montés.
CustomerStats, TopCustomers — **jamais montés** (code mort).

### Routes dashboard réellement sans référence
Aucune. 16/16 sont atteintes par au moins un appel HTTP de test.
