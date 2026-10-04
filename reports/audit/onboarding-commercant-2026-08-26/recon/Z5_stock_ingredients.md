# Z5 — STOCK, INGRÉDIENTS & DISPONIBILITÉ (ONB-08 / W1) — 2026-08-26

> **Mandat de session : LECTURE SEULE ABSOLUE.** Aucun POST/PUT/PATCH/DELETE, aucune écriture DB, aucun test Playwright
> déclenché. Cette reconnaissance est donc **100 % analyse de code (Read/grep) + inspection de process** — pas de
> chronomètre live sur `:8800`/`:8808`. Chaque délai donné plus bas est **dérivé du code** (TTL déclarés, cadences de
> poll codées en dur, chemin d'invalidation), jamais mesuré en direct. Là où le code ne tranche pas, `NON VÉRIFIÉ`.
> Question directrice (Karim, 20h, plus de poulet) : combien de gestes pour retirer l'article, combien de temps pour
> que la borne le sache.

---

## 0. Résumé exécutif (persona Karim)

1. Karim ouvre `/admin/dashboard` (accueil, `admin.dashboard`, `resources/js/router/index.js:147`).
2. **1 clic** sur l'entrée de menu « Produits & Stock » (`catalog-hub?tab=stock`) → il voit la tuile burger, il clique le
   toggle « rupture » → **c'est le SEUL geste d'écriture**. Le formulaire est validé par `AvailabilityToggleRequest`
   (pas de FormRequest manquante sur ce chemin précis — `AvailabilityController.php:52`).
3. Diffusion : le commit DB déclenche un event PHP synchrone (`DB::afterCommit`, pas de queue en soi pour l'event lui-même) qui,
   selon le transport (WebSocket Echo vs polling de secours), atteint borne/caisse/KDS en **< 1 s en régime normal**
   et en **15-30 s au pire cas codé** si le WebSocket est muet (voir §3 tableau).
4. Le site web public n'existe pas en V1 pour ce flux (`/` → redirect `/login`, aucun consommateur du canal
   `public-menu.{id}` dans le code) — voir §3.4.

---

## 1. Les trois questions du commerçant — chemin exact + clics depuis l'accueil

Accueil = `/admin/dashboard` (`admin.dashboard`, route racine `/admin` → redirect `admin.dashboard`,
`resources/js/router/index.js:125,147`). La sidebar (`BackendMenuComponent.vue`) est un layout persistant affiché sur
toute page admin — les entrées à URL réelle (pas `url:'#'`) sont des `<router-link>` directs
(`BackendMenuComponent.vue:31-36`) : **1 clic** suffit depuis n'importe quelle page admin, y compris l'accueil.

### a. « Est-ce que cet article est encore vendable ? »
- **Chemin** : sidebar « Produits & Stock » → `resources/js/components/layouts/backend/BackendMenuComponent.vue:109`
  (`url:'catalog-hub', query:'?tab=stock'`) → route `/admin/catalog-hub?tab=stock` → `CatalogHubComponent.vue:69`
  monte `<StockRuptureDashboardComponent v-if="activeTab==='stock'">` → composant
  `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` (767 lignes, un toggle binaire par
  produit, lignes 1-24 : doc du composant).
- **Clics** : **1** (accueil → clic sidebar). La réponse est visible immédiatement à l'affichage (pas de sous-clic
  requis pour VOIR l'état ; un 2e clic est nécessaire uniquement pour AGIR = basculer le toggle).
- Route legacy encore vivante en deep-link : `/admin/stock/rupture` (`admin.stock.rupture`,
  `resources/js/router/modules/stockRoutes.js:19-28`), même composant.

### b. « Combien il m'en reste ? »
- **Chemin** : sidebar « Conso & Stock » → `BackendMenuComponent.vue:114` (`url:'stock/unified'`) → route
  `/admin/stock/unified` (`stockRoutes.js:30-39`) → `resources/js/components/admin/stock/UnifiedStockViewComponent.vue`
  (680 lignes), section « 2 rayons (matières / boissons revendues) » avec `on_hand` par ligne (doc composant lignes
  1-4 ; source `GET admin/stock/unified-overview` → `App\Services\Stock\UnifiedStockViewService::overview()`,
  `app/Services/Stock/UnifiedStockViewService.php:63`).
- **Clics** : **1**.

### c. « Qu'est-ce que je dois racheter ? »
- **Chemin** : **même écran que (b)**, section « À acheter » affichée **EN HAUT de la page par défaut**
  (`UnifiedStockViewComponent.vue:4` commentaire, `:73-74,104-106` template ; getter `to_buy`
  `UnifiedStockViewComponent.vue:251`, alimenté par `UnifiedStockViewService::overview()` clé `to_buy`
  `app/Services/Stock/UnifiedStockViewService.php:97`). Aucun clic de filtre supplémentaire nécessaire.
- **Clics** : **1** (le même que (b) — les questions b et c partagent un seul écran).
- **Bonus 0-clic partiel** : le widget `StockLowAlertsWidget.vue` est déjà présent sur `/admin/dashboard`
  (`resources/js/components/admin/dashboard/DashboardComponent.vue:68`, import `:85`) — alertes stock bas visibles
  **sans navigation**. Incohérence mineure notée : son lien « voir tout »
  (`StockLowAlertsWidget.vue:16`, `to="/admin/stock/rupture"`) pointe vers l'écran de bascule (a), pas vers l'écran
  quantités/à-acheter (b/c) — un commerçant cliquant depuis l'alerte basse arrive sur le mauvais écran pour
  « qu'est-ce que je rachète ».

**C1 (GOAL §0.5, cible ≤ 2 clics chacune) : 3/3 VRAI, et même 1/1 clic réel** (b et c sont confondus sur un seul
écran, ce qui est mieux que la cible, pas pire).

---

## 2. La rupture — mécanismes, et qui gagne en cas de conflit

**Il y a QUATRE mécanismes concurrents, pas un seul**, vérifiés dans le code :

| # | Mécanisme | Table/colonne | Écrit par | Portée |
|---|---|---|---|---|
| 1 | Statut catalogue global | `items.status`, `items.is_available` | admin édite la fiche produit | Global, toutes branches |
| 2 | Rupture par branche (item) | `item_branch_availability.is_available` / `.unavailable_reason` / `.manual_unavailable_since` | `AvailabilityService::toggle()` (manuel, `app/Services/Menu/AvailabilityService.php:74-150`) OU `StockService::syncItemAvailabilityForStockLevel()` (auto, physique, `app/Services/Stock/StockService.php:264-329`) | Une branche |
| 3 | Rupture extra/variation | `stock_levels` (table polymorphe `stockable_type=ItemExtra|ItemVariation`, colonnes `manual_unavailable_reason`, `on_hand`) | `AvailabilityService::toggleStockable()` (`:767`) ou décrément commande | Une branche |
| 4 | Quota journalier | `item_branch_availability.max_daily_qty` / `.daily_consumed_qty`, `unavailable_reason='out_of_stock'` | `AvailabilityService::setMaxDailyQty()` (`:163-230`) et `decrementForOrder()` | Une branche, auto-reset lazy |

**Ordre de résolution vérifié** (`App\Services\Menu\AvailabilityService::assertItemsOrderableForBranch`,
`app/Services/Menu/AvailabilityService.php:349-411`) : le mécanisme 1 (catalogue) est vérifié **en premier** — un
item `status != ACTIVE` ou `is_available===false` est rejeté avant même de regarder la branche. Puis le mécanisme 2
tranche par branche.

**Qui gagne entre manuel et automatique (le vrai risque de conflit) ?** Le code encode une règle de préséance
explicite et testée (`ManualEightySixStickyThroughRestockTest`) : **un 86 posé à la main
(`manual_unavailable_since != null`) est STICKY — jamais réécrit ni auto-réactivé par le stock physique**
(`StockService.php:284-291,318-325`, commentaire « owner decision A, 2026-07-23 »). Une rupture AUTO (stock
physique `on_hand<=0`, raison `'stock_rupture'` sans marqueur manuel) s'auto-réactive au réappro
(`StockService.php:308-325`). La raison de quota (`'out_of_stock'`) est un troisième vocabulaire, explicitement
exclu de la logique auto-stock (`StockService.php:23-42`, constante `DAILY_QUOTA_REASON` documentée « never treat as
auto stock rupture »). **Donc : la main de l'humain gagne toujours ; entre deux mécanismes automatiques, le dernier
écrit gagne (pas de hiérarchie explicite entre stock physique et quota — ce sont deux raisons distinctes qui ne
s'écrasent pas mutuellement par construction, chacune ne touchant que sa propre branche du code).**

---

## 3. LA PROPAGATION — mécanisme et délai théorique par surface

**Émission** : toute mutation passe par `App\Services\Menu\AvailabilityService::dispatchEvent()`
(`app/Services/Menu/AvailabilityService.php:622-631`) → `DB::afterCommit(fn () => event(ItemAvailabilityChanged::forBranch(...)))`.
Écouteurs enregistrés (`app/Providers/EventServiceProvider.php:243-248`), dans l'ordre :
1. `BumpMenuSnapshotOnItemAvailabilityChanged` (bump version snapshot)
2. `InvalidateKioskMenuCacheOnItemAvailabilityChanged` (`app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:45-78`) — `Cache::forget('kiosk.menu.branch.{id}')`, **synchrone, même requête**
3. `PersistCatalogChangedToOutbox`
4. `PersistItemAvailabilityChangedToOutbox` (`app/Listeners/PersistItemAvailabilityChangedToOutbox.php:17-119`) — écrit `domain_events` (outbox, idempotent par `idempotency_key`), puis `DB::afterCommit(() => DispatchDomainEventsJob::dispatch(...))`. Diffusion sur **2 canaux** : `private-branch.{id}` (staff : borne/caisse/KDS) et `public-menu.{id}` (web public) — `:140-146`.

### 3.1 Cache serveur borne (le point demandé explicitement par la mission)
`GET /api/frontend/menu` (`app/Http/Controllers/Frontend/MenuController.php:34-84`) est mis en cache
`Cache::remember("kiosk.menu.branch.{id}", $ttl=60s, ...)` (`:66-71`, `config('kiosk.menu_cache_ttl', 60)`). Ce
cache **serveur** est invalidé de façon synchrone dans la même requête HTTP que le toggle (écouteur #2 ci-dessus,
try/catch non-bloquant, best-effort — log `Log::warning` si échec, jamais d'exception remontée). **Donc le cache
serveur n'ajoute normalement AUCUN délai** (il est vidé avant que la borne ne puisse re-poller).

### 3.2 Cache CÔTÉ BORNE — trouvé, distinct du cache serveur
Le store Vuex `resources/js/store/modules/kioskMenu.js` porte son **propre** cache mémoire côté client :
`CACHE_TTL_MS = 5 * 60 * 1000` (**5 minutes**, `kioskMenu.js:22`), et `fetchMenu({force})`
(`kioskMenu.js:259-263`) **skip l'appel réseau** si `!force && !isStale`. En usage normal ce cache n'est PAS le
chemin de propagation d'un 86 : `KioskAppComponent.vue:662-712` (`_handleItemAvailabilityChanged`) reçoit l'event
Echo `ItemAvailabilityChanged` et fait `this.$store.commit('kioskMenu/UPDATE_ITEM', payload)` — un **patch direct de
l'état en mémoire**, qui contourne le TTL de 5 min. Un refetch réseau (`force:true`) n'est déclenché que si
`payload.type==='full'` (changement structurel, pas une simple bascule). **Risque documenté par le code lui-même**
(`_subscribeEchoChannel`, `KioskAppComponent.vue:539-541` : « No-op if Echo is not configured … TTL cache remains
the fallback ») : **si le WebSocket échoue silencieusement, le patch direct n'a jamais lieu, et rien ne force un
refetch avant 5 minutes** (aucun poll périodique codé dans `KioskAppComponent.vue` pour la disponibilité — seul un
refetch sur reconnexion WS, `:385-393`).

### 3.3 Tableau des délais par surface

| Surface | Transport primaire | file:line | Filet de secours codé | Délai théorique pire cas |
|---|---|---|---|---|
| **Borne (kiosk)** | Echo `private-branch.{id}`, event `ItemAvailabilityChanged` → patch direct `UPDATE_ITEM` | `KioskAppComponent.vue:543-551,662-706` | **AUCUN poll périodique dédié** à la disponibilité ; seul refetch sur reconnexion WS (`:385-393`) | **< 1 s si WS vivant ; jusqu'à 5 min (`CACHE_TTL_MS`) si WS muet et pas de reconnexion entretemps** |
| **Caisse (POS)** | Echo `private-branch.{id}`, event `ItemAvailabilityChanged` → `_onItemAvailabilityChanged` patch in-place | `PosComponent.vue:3907,3941` | Poll dédié **~30 s**, explicitement motivé par le risque « worker de queue down » (`loadAvailabilitySnapshotFallback`, `PosComponent.vue:3908-3936`, commentaire `pos-86-propagation-dead-no-poll 2026-07-22`) | **< 1 s si WS vivant ; ≤ 30 s sinon (borné par le poll)** |
| **KDS** | Echo `private-branch.{id}`, event `ItemAvailabilityChanged` → `_onItemAvailabilityChanged` (badge OOS + toast + aria-live) | `KitchenDisplaySystemComponent.vue:2589,2911-2955` | Poll **15 s si WS `connected`, 5 s sinon** — cadence resserrée **explicitement** le 2026-07-31 après avoir constaté qu'un ticket restait faux jusqu'à 60 s (`:2540-2548`) ; **admin (branch_id=0) ne s'abonne jamais à Echo, polling seul** (`:2562-2564`) | **< 1 s si WS vivant et branche>0 ; borné à 15 s sinon (5 s si déconnecté)** |
| **Web public (projection)** | Canal `public-menu.{id}` diffusé côté serveur (`PersistItemAvailabilityChangedToOutbox.php:124-146`) | — | **Aucun consommateur trouvé dans le code JS** (`grep public-menu` ne retourne que le fichier serveur qui écrit dessus) ; `/` redirige vers `/login` (`routes/web.php:43`) — pas de page menu publique servie en V1 | **NON APPLICABLE en V1** — le canal existe côté serveur mais n'a pas de lecteur ; cohérent avec CLAUDE.md §3bis (« web standalone séparé, pas de wireup API en V1 ») |

### 3.4 Risque d'environnement spécifique à CETTE session (pas un défaut produit)
Vérifié par inspection de process (`ps`, `lsof`, lecture seule) : le worker de queue (`php artisan queue:work
--queue=high,default`, PID 35253) et le serveur Soketi (PID 42019) tournant sur cette machine ont tous deux leur
`cwd` sur **l'arbre PRINCIPAL** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`, PAS sur ce worktree.
Les deux `.env` (principal et worktree) partagent `APP_NAME="Le Cayenne"` et `QUEUE_CONNECTION=redis` sans
`REDIS_PREFIX` explicite → même préfixe Redis par défaut (`le_cayenne_database_`). **Conséquence : les jobs de
diffusion (`DispatchDomainEventsJob`) déclenchés par CE worktree seraient consommés par le worker de l'arbre
principal, pas par un worker propre à ce worktree.** Ceci n'est PAS un défaut de production (V1 = une seule
installation, ce risque de contamination croisée n'existe pas hors environnement multi-worktree de dev/audit), mais
cela signifie qu'un chronomètre live exécuté sur ce worktree sans précaution mesurerait un pipeline partagé avec un
autre arbre de code. NON VÉRIFIÉ au-delà de cette inspection statique (aucune commande d'exécution/mesure n'a été
lancée, conformément au mandat lecture seule).

---

## 4. Les mouvements — append-only, et comment on annule une erreur

**Append-only confirmé au niveau modèle (hook Eloquent, pas seulement convention) :**
- `StockMovement` (ventes/commandes) : `app/Models/StockMovement.php:46-55` — `static::updating` et
  `static::deleting` lèvent tous deux `LogicException('stock_movements is append-only.')`. `UPDATED_AT=null`
  (`:10`).
- `RawMaterialMovement` (matières premières, ajustement manuel + scan facture) : `app/Models/RawMaterialMovement.php:42-51`
  — même garde miroir, même exception.

**Comment on annule/corrige une erreur de saisie : un NOUVEAU mouvement compensateur, jamais une suppression ni une
UPDATE.** Vérifié dans `RawMaterialStockService::adjust()` (`app/Services/RawMaterials/RawMaterialStockService.php:91-107`)
qui délègue à `mutate()` (`:115-155`) : le service verrouille la ligne stock (`lockOrCreateStock`), calcule
`$delta = target - current` (`:139-140`), **`$stock->forceFill(['on_hand'=>$target])->save()`** met à jour le solde
courant (une seule ligne « état actuel », PAS un ledger figé), puis **`RawMaterialMovement::create([...'delta'=>$delta...])`**
insère une nouvelle ligne d'historique (`:142-151`). Pour corriger une erreur, l'opérateur ressaisit le bon comptage
(`target_on_hand`) via `POST /api/admin/raw-materials/{id}/adjust`
(`app/Http/Controllers/Admin/RawMaterialAdjustController.php:113-167`) — un **second** mouvement au delta inverse
apparaît, le premier reste intact et visible dans l'historique (`history()`, `:64-100`, 20 derniers mouvements
`source_type='manual_adjustment'`). Traçabilité qui/quand/pourquoi : `meta.adjusted_by_user_id/name` (colonne JSON,
pas de colonne `user_id` dédiée — noté explicitement dans le commentaire du contrôleur `:29-35`), `created_at`
natif, `reason` natif (obligatoire par validation applicative, PAS par contrainte du service —
`RawMaterialAdjustController.php:117-121` : `'reason' => ['required','string','min:3','max:64']` en `$request->validate()` inline, pas de FormRequest dédiée — confirme le constat GOAL §2.3).

---

## 5. Le scan de facture — pipeline et validation humaine

`POST /api/admin/purchasing/scan` (`app/Http/Controllers/Admin/PurchasingScanController.php:46-133`) : photo →
`InvoiceVisionContract::extractLines()` (lecture IA, mock en local — `OPENAI_VISION_ENABLED=false` par mandat V1,
binding `MockInvoiceVisionService` — commentaire contrôleur `:19-29` confirme « AUCUNE écriture stock ici — l'IA
propose, l'humain valide ») → `InvoiceClassificationService::propose()` → crée `PurchaseDocument` (`status=draft`)
+ des `PurchaseLine` (`status=proposed`) — **zéro écriture sur `stock_levels`/`raw_material_stocks` à ce stade**
(vérifié : la transaction `DB::transaction` de `scan()`, `:92-121`, ne touche que `PurchaseDocument`/`PurchaseLine`).

**Étape de validation humaine, séparée et obligatoire** : `POST /api/admin/purchasing/{document}/apply`
(`PurchasingScanController.php:191-255`) — l'owner doit soumettre, ligne par ligne, une `target_type`/`target_id`
choisie (matière/produit/charge), avec vérification d'existence (`assertTargetExists`, `:261-276`), avant que
`PurchaseService::validateDocument()` (`:252`) ne matérialise l'entrée en stock. **Seules les lignes explicitement
soumises passent `proposed→validated`** (`:240` `PurchaseLine::STATUS_VALIDATED`) ; les lignes non soumises restent
`proposed`, jamais appliquées. Idempotent : un document déjà `STATUS_VALIDATED` renvoie un no-op (`:200-206`).
Validation inline (`$request->validate` à `:51` pour `scan()`, `:208` pour `apply()`) — confirmée conforme au
constat GOAL, avec la garde anti-RCE déjà présente (`NoDangerousFileExtension`, `:61`, commentaire explicite sur le
finding polyglot `.pht` du 2026-05-24) : validation inline **≠** validation absente, comme le GOAL le tranchait déjà
(§0.7 C-VALIDATION).

---

## 6. Ce qui n'a PAS pu être vérifié (mandat lecture seule)

- Délai RÉEL mesuré en direct (chronomètre horloge) pour chacune des 4 surfaces — **NON MESURÉ**, seule l'analyse de
  code (TTL déclarés, cadences de poll codées en dur) est fournie ci-dessus.
- Comportement réel d'Echo/Soketi sur ce worktree précis (le doute §3.4 n'a pas été levé par un test de bout en
  bout — aucune requête HTTP n'a été émise).
- Contenu exact de `config/idempotency.php` vs les routes stock (gate G-IDEMP du GOAL) — hors du périmètre des 5
  questions posées, non vérifié ici.
- État actuel des 55 `stock_levels` / 11 articles `kds_station=none` en DB — non re-requêté cette session (chiffres
  du GOAL §0.6/§1 datés du 26/08, non revérifiés en lecture SQL par cette mission pour rester strictement
  file-system/code read-only).
