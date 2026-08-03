# S5 — Chasse LOGIQUE read-only · Système ADMIN / GESTION (2026-07-18)

Repo: `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` · DB `foodking_e2e` · serveur :8000 · **RIEN MODIFIÉ**.
Méthode : lecture code + preuves DB/route via `php artisan tinker` (RO) + résolution middleware réelle par la route.

---

## PROBLÈMES RÉELS

### [P1] `app/Http/Controllers/Admin/SalesReportController.php:40` — `/admin/sales-report/overview` (agrégat CA) NON protégé par `permission:sales-report`

**Cause** : le heal REP-AUTHZ-01 (2026-06-01) a écrit
`$this->middleware(['permission:sales-report'])->only('index','export','pdf','overview');`
mais la **méthode réelle s'appelle `salesReportOverview`** (route `routes/api.php:1158` :
`Route::get('/overview', [SalesReportController::class, 'salesReportOverview'])`). Laravel `->only()`
matche par **nom de méthode** ; `'overview'` n'existe pas → la middleware Spatie ne s'applique
JAMAIS à ce endpoint. Les 3 sœurs (`index`/`export`/`pdf`, toutes des lectures) SONT gated ;
`overview` est le seul read omis (dead target).

**Preuve (résolution middleware réelle, pas le fichier)** :
```
FULL STACK /overview :  ... BlockKioskTokenFromAdminRoutes, localization   ← FIN
FULL STACK /index    :  ... localization, PermissionMiddleware:sales-report ← gated
gatherRouteMiddleware(/overview) → permission-mw: []   (index → [PermissionMiddleware:sales-report])
```
Stack `/overview` == stack `/index` **moins** la dernière ligne `PermissionMiddleware:sales-report`.
`BlockKioskTokenFromAdminRoutes` ne bloque que les tokens kiosk ; tout token Sanctum staff passe.

**Impact** : n'importe quel staff authentifié SANS le droit `sales-report` (POS Operator, Chef,
Waiter, Livreur…) peut `GET /api/admin/sales-report/overview?date_from=…&date_to=…` et lire
`SalesReportOverviewResource` = `total_orders, total_earnings (CA net), total_discounts,
total_delivery_charges` d'une période/branche. Fuite de données financières = bypass RBAC.

**Aggravant — sentinelle vert-faux** : `tests/Feature/Admin/MgmtReadAuthzGateSentinelTest.php`
« teste » ce cas mais vérifie que la chaîne `'overview'` est dans `getMiddleware()->only` (elle y est,
en tant que string) sans jamais confirmer qu'elle mappe la vraie méthode `salesReportOverview`.
`php artisan test --filter=test_sales_report_overview_is_gated…` → **PASS** alors que la route est
ouverte. Le test entérine le bug.

**Fix (indicatif, non appliqué)** : remplacer `'overview'` par `'salesReportOverview'` dans le
`->only(...)`, et corriger la sentinelle pour asserter le vrai nom de méthode / la middleware
gathered de la route.

---

### [P3] `app/Http/Controllers/Admin/AnalyticSectionController.php:26` — `index`/`show` (lecture) non gated alors que le parent l'est

`AnalyticController` gate `index,show,store,update,destroy` par `permission:settings` (l.26).
Son enfant `AnalyticSectionController` ne gate QUE `store,update,destroy` → `GET
/api/admin/setting/analytic-section/{analytic}` (index) et show résolvent **sans** middleware
permission (preuve `gatherRouteMiddleware → perm=[]`). Incohérence : le parent (config analytics)
exige `settings`, l'enfant (sections de cette config) non. **Faible sévérité** : tables `analytics`
/ `analytic_sections` vides en base, contenu = métadonnées de sections dashboard (pas de secret de
tracking). À aligner sur le parent par cohérence, pas un risque opérationnel V1.

---

## ÉCARTÉS (vérifiés SAINS — raison)

- **Projection prix/dispo admin → caisse ET borne** : events `ItemUpdated`/`ItemAvailabilityChanged`
  /`Category*`/`ComposerProfileChanged`/`ItemExtra|VariationAvailabilityChanged` (EventServiceProvider
  225-296) → `InvalidateKioskMenuCacheOnCatalogChange` (Cache::forget `kiosk.menu.branch.{id}`) +
  `BumpMenuSnapshot` + outbox. La borne lit `KioskMenuService::build` (cache 60s invalidé au toggle),
  la caisse/`/menu-projection` lit `MenuProjectionService` : **les DEUX moteurs appliquent la même
  règle** `branchAvailable && globalAvailable` (KioskMenuService:312/367 == MenuProjectionService:138-140).
  Aucun desync.
- **Prix facturé** : `PricingService:57` lit `Item::select('id','price','tax_id')` **frais de la DB**
  à chaque quote/commande (SSOT). Un prix changé en admin est facturé immédiatement, indépendamment
  des caches de projection (qui ne servent que l'affichage). Sain.
- **CRUD enfants (variation/extra/addon)** : dispatchent `ItemAvailabilityChanged(type=full)` →
  invalidation cache + snapshot bump. Item store/update/destroy idem via `DB::afterCommit`. Cohérent.
- **`items.is_available` NULL** : colonne `NOT NULL DEFAULT 1` (preuve `SHOW COLUMNS`) → la divergence
  potentielle « admin (bool)null=false vs projection null=true » n'est pas atteignable. Écarté.
- **Dashboard (lens 3)** : bornes jour **Paris face-value** (session_tz=Paris documenté), exclut les
  miroirs de remboursement (`whereNull('parent_order_id')`), `realizedRevenue()` exclut annulées-payées
  + Uber non-fiscalisé (miroir exact du Z), donut canal = complément exact kiosk∪pos (somme 100 % par
  construction), ticket moyen dénominateur = payées non-terminales, agrégats status en 1 GROUP BY
  (sémantique identique). Aucun agrégat faux trouvé. Sain.
- **Carnet (lens 4)** : PIN `hash_equals` + throttle 5/min + `session()->regenerate()` (anti-fixation),
  `EnsureDailyBookPin` session glissante, `create()` en transaction (photo+entrée atomiques), validations
  bornées (`amount prohibited_if:type,note` + `min:0.01`, `entry_date` bornée 2024-01-01..J+1, photo servie
  DERRIÈRE le gate PIN via route, pas d'URL /storage publique), HORS NF525. Cohérent, rien à signaler.
- **Coupon (lens 6)** : `CouponService::validateCouponForOrder` vérifie existence, `minimum_order`,
  `start_date`/`end_date`, `limit_per_user` + `max_uses_global` (comptés sur `order_coupons`, pas la
  colonne morte `usage_count`), `isUsableNow` (status/jour/heure/branch/surface). Montant clampé
  `min(subtotal, maximum_discount)`. Appliqué via `PricingService`/`DiscountCalculator` = SSOT au
  moment de la facturation (pas confiance au front). Sain.
- **Stock/rupture (lens 2)** : `decrementForOrder` idempotent (`Cache::add` SETNX par order),
  reset jour lazy (`whereDate('daily_reset_at','<',today)`) + cron `ResetStaleDailyQuota`, release
  annulation/refund idempotent via ledger `order_items.released_qty` (delta borné) + garde
  `unavailable_reason==='out_of_stock'` empêchant d'écraser un 86 manuel/cron, auto-86 quota CAS-style.
  `availabilityCounts` branch-aware. `catalogOverview` reflète maintenant `on_hand<=0` + rupture
  ingrédient (parité admin↔borne, heals TERRAIN 2026-07-16). Sain.
- **`TableOrderController` `->only('selectDeliveryBoy')`** (l.30) : string MORTE, aucune méthode/route
  `selectDeliveryBoy` n'existe → aucune route laissée ouverte (toutes les vraies méthodes
  index/show/export/changeStatus/changePaymentStatus/tokenCreate SONT gated). Cosmétique, PAS une vuln.
- **`/setting/*` index/show ouverts** (company/site/tax/currency/slider/branch/theme/page/…),
  `dining-table@index`, `payment-terminals@index`, `oss-order publicIndex` : pattern **délibéré
  read-open / write-gated** (le SPA doit lire la config pour tout staff ; les mutations sont gated),
  et OSS est un tableau public par design. Scan systématique (33 routes) trié : seule
  `sales-report/overview` gate ses **sœurs de lecture** par la même permission tout en omettant un read
  → seul vrai oubli accidentel. Le reste = design, écarté.

## Outils de preuve (scratchpad, RO)
- `dead_only.php` → 2 dead `->only()` targets : `overview` (P1) + `selectDeliveryBoy` (cosmétique).
- `sibling_gap2.php` → 30 candidats, discriminés : 1 seul oubli réel (`overview`), reste = read-open design/OSS public.
