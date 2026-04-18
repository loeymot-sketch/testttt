# AUDIT MASSIF — FoodKing V1 Fast-Food

## Dashboard · Gestion catalogue · Synchro 4 surfaces · Plan de mission pour dépasser la concurrence

**Date** : 2026-04-16
**Branche** : `refactor/staff-only-v1`
**Scope** : Admin Dashboard + Gestion Produits/Catégories/Stock + Synchro POS↔KDS↔Borne↔Dashboard + Plan V1 MVP aligné sur les 12 tâches
**Méthode** : 3 sous-agents d'exploration parallèles + lecture des 2 docx `audits/` + synthèse orchestrateur
**Périmètre V1 assumé** : synchro · menu/rupture · sécu base · architecture propre. **Hors V1** : 2FA, RGPD, livraison tierce, fidélité full, mobile, site web, BI avancée.

---

## SOMMAIRE

1. [TL;DR stratégique](#1-tldr-stratégique)
2. [Audit Dashboard admin](#2-audit-dashboard-admin)
3. [Audit Gestion Produits / Catégories / Stock](#3-audit-gestion-produits--catégories--stock)
4. [Audit Synchro 4 surfaces (Dashboard↔POS↔KDS↔Borne)](#4-audit-synchro-4-surfaces)
5. [Modèle intelligent : Menu Borne ↔ Menu POS (séparation + synchro)](#5-modèle-intelligent-menu-borne--menu-pos)
6. [Matrice V1 : état vs cible par tâche (SYNC_BACKBONE → TEST_PRICING_STATE)](#6-matrice-v1--12-tâches--état--cible)
7. [Plan de mission : "Dépasser la concurrence" pour un fast-food en V1](#7-plan-de-mission--dépasser-la-concurrence-en-v1)
8. [Décomposition orchestrée des 12 tâches (exécution séquentielle + gates)](#8-décomposition-orchestrée-des-12-tâches)
9. [Risques & garde-fous](#9-risques--garde-fous)
10. [Annexes : cartographie fichiers](#10-annexes--cartographie-fichiers)

---

## 1. TL;DR stratégique

### Ce que FoodKing a déjà (et qui est solide)

- **Architecture évent-driven propre** : Outbox pattern (`domain_events`) + `DispatchDomainEventsJob` (queue `high`, retry 5× backoff `[1s,5s,30s,5m]`) + Echo/Pusher + canal `private-branch.{id}`. Contrat d'envelope V1 déjà aligné (`version`, `type`, `aggregate_id`, `branch_id`, `occurred_at`, `correlation_id`, `payload`).
- **SSOT prix serveur** : `OrderService::posOrderStore` et `FrontendOrderService::myOrderStore` recalculent TOUS les prix depuis la DB, jamais du client. `ValidStatusTransition` + `OrderStateMachine::recordTransition` en place.
- **Idempotency X-Idempotency-Key** : POS + Borne, contrainte unique DB, anti double-submit validé par tests `ConcurrentOrderTest`.
- **Queue number atomique** : `Cache::lock` + regex pattern.
- **Branch isolation** : `BranchScope` global sur presque tous les modèles.
- **Observabilité base** : `CorrelationIdMiddleware` + health `/api/health/{live,ready,full}` + channel `production_json` + correlation_id propagé dans jobs.

### Ce qui manque ou est partiel (bloquants MVP fast-food)

1. **Menu/rupture temps réel incomplet** :
  - Table `item_branch_availability` **existe** mais **n'est lue par aucune API** `/api/frontend/item` ni `/api/admin/pos-category` → la borne et le POS affichent toujours tous les items actifs globalement.
    - `DecrementItemAvailabilityOnOrder` décrémente `daily_consumed_qty` mais **ne déclenche pas** `ItemAvailabilityChanged` → aucun client n'est prévenu quand `max_daily_qty` est atteint.
    - **Aucune UI admin** pour toggler "rupture" par branche en 1 clic.
    - **POS ne s'abonne pas** à `ItemAvailabilityChanged` → un item rupturé reste tapable à la caisse.
    - **KDS ne voit pas** "rupture signalée" sur les commandes en cours.
2. **Séparation catalogue Borne vs POS non modélisée** :
  - Pas de colonnes `show_on_kiosk` / `show_on_pos` / `show_on_web` sur `items` ni sur `item_categories`.
    - Filtrage `surface=kiosk|pos|web` implémenté **uniquement** sur les extras/variations (via JSON `visible_on`) — **pas** sur la liste d'items ni de catégories.
    - Les deux surfaces utilisent la même arbo de catégories, le même tri, sans override de prix/libellé par canal.
3. **Écrans admin redondants** : 3 listes séparées POS-Orders / Online-Orders / Table-Orders pour des commandes qui finissent dans la même table `orders`. Un gérant fast-food en rush veut **une seule vue "Commandes du jour" filtrable** + raccourci rapide rupture.
4. **Pas de workflow "rupture en 1 clic"** : depuis login jusqu'à désactivation article : 4–6 clics + scroll. Un caissier en rush ne va pas le faire.
5. **Rôle "Delivery Boy" a un landing admin mais 0 permission seedée** → risque redirection `exception` juste après login.
6. **Dashboard temps réel incomplet** : pas de widget "Ruptures actives", pas d'alertes anomalies paiement, pas de "staff en ligne".
7. **POS ne reçoit pas les events menu en live** : si un prix change, le POS ne recharge pas son cache.

### Verdict V1 production-ready


| Dimension            | Note | Action V1                                                                                                                      |
| -------------------- | ---- | ------------------------------------------------------------------------------------------------------------------------------ |
| Architecture backend | B+   | Finaliser outbox + event contract (vague 1)                                                                                    |
| Synchro temps réel   | B-   | Ajouter ItemAvailabilityChanged branch-scoped + abonnements POS + fail-fast broadcast (SYNC_BACKBONE)                          |
| POS                  | B+   | Brancher sur menu live + désactiver tap rupture (MENU_86)                                                                      |
| Borne                | A-   | Lire `item_branch_availability` + masquer ruptures + UI rassurante offline (MENU_86)                                           |
| KDS                  | B    | Ajouter badge rupture-signalée + filtres par station (V1.5)                                                                    |
| Dashboard admin      | C+   | Vue unifiée "commandes du jour" + widget ruptures (V1.5, mais V1 critique : corriger rôle Delivery Boy + landing)              |
| Gestion catalogue    | C    | **Chantier V1 critique** : activer `item_branch_availability` + UI toggle rupture + séparation Borne↔POS par flag `channels[]` |


**Distance au V1 roadmap** (27 j-h annoncés) : réaliste, à condition de bien orchestrer les 4 vagues **dans l'ordre strict** (synchro avant domaine avant sécu avant tests).

---

## 2. Audit Dashboard admin

### 2.1 Shell + navigation

- **Menu dynamique** via `authMenu` (chargé au login) + `MenuService::menu()` + `AppLibrary::menu()` filtre les entrées selon les permissions Spatie du rôle.
- **Sidebar** `BackendMenuComponent.vue` : rendue masquée sur KDS et OSS (classe `hidden`).
- **Navbar** : raccourci POS si permission `pos`. **Pas de barre de recherche globale admin** (pain point fast-food).
- **9 sections racine × ~20+ entrées enfants** → charge cognitive élevée pour un gérant en rush.

### 2.2 Dashboard home (`/admin/dashboard`)

Composant `DashboardComponent.vue`, 15 endpoints API :

```
admin/dashboard/total-sales | total-orders | total-customers | total-menu-items
admin/dashboard/order-statistics | order-summary | sales-summary | customer-states
admin/dashboard/featured-items | popular-items | top-customers
admin/dashboard/realtime-report | sla-alerts | channel-statistics | audit-trail
```

**Widgets actuels** :

- OverviewComponent (KPIs agrégés)
- RealtimeReportComponent (CA jour, commandes jour, ticket moyen) — refresh 30s
- SlaAlertsComponent (cuisine > 15 min uniquement)
- ChannelStatsComponent, AuditTrailComponent, OrderStatisticsComponent
- SalesSummary, OrderSummary, CustomerStats, TopCustomers, FeaturedItems, MostPopularItems

**Widgets MANQUANTS cruciaux pour fast-food** :

- ❌ **Ruptures actives aujourd'hui** (le plus critique : le gérant doit voir du premier coup d'œil ce qui est épuisé)
- ❌ **Staff en ligne** (qui est connecté POS/KDS en ce moment)
- ❌ **Anomalies paiement** (transactions voidées, écarts caisse)
- ❌ **Vitesse de service** (temps moyen commande → prête par canal)
- ❌ **Live feed commandes des 5 dernières minutes** (dashboard live-updating via Echo — actuellement dashboard ne s'abonne à AUCUN event)

### 2.3 Rôles & permissions — problèmes


| Rôle             | Landing URL              | Permissions seedées                                                   | Risque                                                                     |
| ---------------- | ------------------------ | --------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| Admin            | `dashboard`              | `Permission::all()`                                                   | ✅ OK                                                                       |
| POS Operator     | `pos`                    | dashboard, pos, pos-orders, KDS, OSS                                  | ✅ OK                                                                       |
| Chef             | `kitchen-display-system` | dashboard, KDS, OSS                                                   | ✅ OK                                                                       |
| Waiter           | `waiters`                | dashboard, table-orders, KDS, OSS                                     | ⚠️ landing `waiters` mais pas de permission `waiters` seedée explicitement |
| Branch Manager   | `dashboard`              | ~25 permissions mais **ni `items-report` ni `credit-balance-report`** | ⚠️ menu rapports partiel                                                   |
| **Delivery Boy** | `delivery-boys`          | **AUCUNE**                                                            | 🚨 **BLOQUANT** : redirection `/admin/delivery-boys` → page exception      |
| Customer         | `#`                      | aucune                                                                | ⚠️ login admin improbable mais à sécuriser                                 |
| "Stuff" (typo)   | `dashboard`              | dashboard, KDS, OSS                                                   | ⚠️ renommer en "Staff"                                                     |


### 2.4 Routes admin (cartographie condensée)

~25 modules admin, toutes sous `/admin/`*, chaque route avec `meta.permissionUrl`. Modules clés :

- **Commandes** : `/pos-orders`, `/online-orders`, `/table-orders`, `/kitchen-display-system`, `/order-status-screen` → **5 écrans de commandes** (redondance UX).
- **Catalogue** : `/items`, `/offers`, `/coupons`, `/settings/item-category`, `/settings/item-attribute`.
- **Utilisateurs** : `/administrators`, `/delivery-boys`, `/customers`, `/employees`, `/waiters`, `/chefs`.
- **Settings** : ~15 sous-pages.
- **Rapports** : `/sales-report`, `/items-report`, `/credit-balance-report`.

**Route morte détectée** : `resources/js/router/modules/cdsRoutes.js` définit un `/admin/pos` doublon (pas importé dans `index.js`). Stub à supprimer.

### 2.5 UX pain points spécifiques fast-food


| Pain point                         | Actuel                                  | V1 cible                                                                   |
| ---------------------------------- | --------------------------------------- | -------------------------------------------------------------------------- |
| Rupture article en 1 geste         | 4-6 clics + scroll                      | **1 clic depuis bouton "rupture rapide"** dans header + modal autocomplete |
| Recherche globale commande/produit | ❌ absent                                | **Ctrl+K global search** (V1.5 acceptable, mais à prévoir)                 |
| Mobile/tablette (gérant en salle)  | Sidebar repliable, pas de mode tablette | V2                                                                         |
| Vue unifiée commandes du jour      | ❌ 3 listes séparées                     | **V1.5 : onglet "Commandes aujourd'hui"** qui fusionne les 3               |
| Dark mode KDS (contraste cuisine)  | ❌                                       | V2                                                                         |
| Raccourcis clavier POS (F1-F12)    | ❌                                       | V1.5                                                                       |


---

## 3. Audit Gestion Produits / Catégories / Stock

### 3.1 Schéma BDD actuel

```
┌─────────────────────────────────────────────────────────────┐
│ items                                                        │
│   id, item_category_id (FK), tax_id (FK null),             │
│   name, slug, caution, description,                          │
│   price (19,6), status (5/10), item_type (VEG/NON_VEG),    │
│   order, is_featured, is_upsell,                            │
│   soft-deletes (deleted_at)                                 │
└─────────────────────────────────────────────────────────────┘
   │                        │
   ▼                        ▼
┌─────────────┐      ┌─────────────────────┐
│ item_       │      │ item_variations     │
│ extras      │      │   item_attribute_id │
│  item_id    │      │   name, price       │
│  name, price│      │   visible_on (JSON) │
│  status     │      │   SANS parent value │
│  group_label│      │   (1 ligne = 1 val) │
│  visible_on │      └─────────────────────┘
└─────────────┘              │
                             ▼
                      ┌─────────────────┐
                      │ item_attributes │
                      │  name, status   │
                      │  (Taille,       │
                      │   Viande, ...) │
                      └─────────────────┘

┌─────────────────────────────────────────────┐
│ item_categories                              │
│   id, name, slug, description,              │
│   status, sort,                              │
│   wizard_template (tacos|sandwich|burger|   │
│     assiette|salade|omelette|snacking|simple)│
│   has_menu, default_menu_kiosk,             │
│   sauce_included_menu,                       │
│   kiosk_upsell_include,                      │
│   kiosk_upsell_skip_after_cart,             │
│   soft-deletes                              │
└─────────────────────────────────────────────┘

┌──────────────────────────────────────────┐
│ item_branch_availability  (⚠️ orpheline) │
│   item_id, branch_id,                    │
│   is_available, unavailable_reason,      │
│   unavailable_since,                     │
│   max_daily_qty, daily_consumed_qty,     │
│   daily_reset_at,                        │
│   unique(item_id, branch_id)             │
│   ❌ Pas de FK déclarées dans migration  │
│   ❌ Pas lue par les APIs frontend       │
│   ❌ Pas d'UI admin pour toggle          │
└──────────────────────────────────────────┘

❌ ABSENT : stocks, stock_movements, recipes, ingredients, ingredient_movements
❌ ABSENT : item_channels (POS/Kiosk/Web/Delivery)
❌ ABSENT : item_schedules (happy hour, daily menu)
❌ ABSENT : item_price_history
```

### 3.2 Back-office admin catalogue


| Écran                            | Chemin SPA                             | API                                           | État               |
| -------------------------------- | -------------------------------------- | --------------------------------------------- | ------------------ |
| Liste articles                   | `/admin/items`                         | `GET /api/admin/item`                         | ✅ CRUD complet     |
| Édition article                  | Drawer + `ItemCreateComponent.vue`     | `POST /api/admin/item` + `PATCH /{id}`        | ✅                  |
| Import/export CSV/XLSX           | Composants `Import`, `Excel`, `Upload` | `/export`, `/import/file`, `/download-sample` | ✅                  |
| Catégories                       | `/admin/settings/item-category`        | `/api/admin/item-category`                    | ✅                  |
| Variations                       | `/admin/items/variation/*`             | `/api/admin/item/variation/{item}`            | ✅                  |
| Extras                           | `/admin/items/extra/*`                 | `/api/admin/item/extra/{item}`                | ✅                  |
| Addons liens (produits associés) | `/admin/items/addon/*`                 | `/api/admin/item/addon/{item}`                | ✅                  |
| Attributs (Taille, Viande…)      | `/admin/settings/item-attribute`       | `/api/admin/item-attribute`                   | ✅                  |
| Taxes                            | Settings                               | `/api/admin/tax`                              | ✅                  |
| **Rupture par branche**          | ❌                                      | ❌                                             | 🚨 **BLOQUANT V1** |
| **Stock / inventaire**           | ❌                                      | ❌                                             | V2                 |
| **Historique prix**              | ❌                                      | ❌                                             | V1.5               |


### 3.3 Champ `status` (5 = ACTIVE / 10 = INACTIVE)

- **Global** : désactiver un item le retire partout (POS, Borne, Online).
- **Événement** : `ItemService::update` dispatche `ItemAvailabilityChanged` avec type `status` ou `full` — **MAIS** `ItemService::store` et `destroy` ne dispatchent **rien**.
- **Broadcast** : `PersistItemAvailabilityChangedToOutbox` envoie **à TOUTES les branches actives** (fan-out, pas scopé à la branche concernée).

### 3.4 Séparation Borne vs POS — état actuel


| Niveau                | Mécanisme actuel                                                                                        | Suffisant fast-food ?                                                     |
| --------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| **Items**             | Pas de flag channel. Tous les items actifs visibles partout.                                            | ❌ **NON**                                                                 |
| **Catégories**        | Pas de flag channel. Même arbo partout. Option `kiosk_upsell_include` oui mais pas "cache-moi sur POS". | ❌                                                                         |
| **Variations/Extras** | JSON `visible_on` (liste `['kiosk','pos','web']`)                                                       | ⚠️ Partiel : fonctionne mais seulement sur détail item, pas sur liste.    |
| **Prix**              | Un seul `items.price`                                                                                   | ❌ Happy hour, promo borne uniquement, prix online différent = impossibles |
| **Formule / bundle**  | `item_categories.has_menu` + ratios globaux `kiosk.menu_pricing`                                        | ⚠️ Global à toute la catégorie, pas par item                              |


**Config actuelle `config/kiosk.php`** :

- `sandwich_split` : hack pour afficher "Nos sandwichs chauds" + "Nos sandwichs froids" à partir d'une seule catégorie parent + liste de slugs froids. **Fragile** (casse si un slug change).
- `menu_pricing` : ratios `full_ratio: 1.0, fries_ratio: 0.6, drink_ratio: 0.4` — applique à **toutes** les formules.
- `max_item_qty`, `order_rate_limit`.

### 3.5 Lacunes critiques pour fast-food V1


| Lacune                                     | Impact fast-food                                       | Priorité          |
| ------------------------------------------ | ------------------------------------------------------ | ----------------- |
| Rupture par branche non active             | Multi-sites impossible ; en mono-site, mini-impact     | P0 V1             |
| Rupture non propagée aux 3 surfaces en <2s | Client paie un tacos Merguez alors qu'il n'y en a plus | P0 V1             |
| POS ne se désactive pas à la rupture       | Caissier tape un article épuisé                        | P0 V1             |
| Pas d'écran admin "rupture rapide 1 clic"  | Gérant ne le fera pas en rush                          | P0 V1             |
| Pas de counter quantité restante affiché   | Admin découvre la rupture trop tard                    | V1.5              |
| Pas de recettes/ingrédients                | Impossible d'auto-86 quand l'ingrédient commun manque  | V2                |
| Prix happy hour / promo horaire            | Feature standard fast-food (McDo, KFC)                 | V1.5              |
| Bundle dynamique (Menu M / Menu L)         | Partiellement via `has_menu` + ratios                  | V1.5 optimisation |


---

## 4. Audit Synchro 4 surfaces

### 4.1 Topologie actuelle

```
┌─── POS ───┐                          ┌─── KDS ───┐
│ OrderCrea-│                          │ Debounced │
│ ted       │                          │ refresh   │
│ +poll 60s │─┐                      ┌─│ +poll 60s │
└───────────┘ │                      │ └───────────┘
              │                      │
              ▼                      ▼
    ┌───────────────────────────────────┐
    │   LARAVEL BACKEND                 │
    │   OrderService / ItemService      │
    │   ↓                               │
    │   domain_events table (outbox)    │
    │   ↓                               │
    │   DispatchDomainEventsJob (queue) │
    │   ↓                               │
    │   Pusher::trigger                 │
    │   private-branch.{id}             │
    └───────────────┬───────────────────┘
                    │
                    ▼
              SOKETI (WS)
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
   ┌─BORNE─┐   ┌─OSS──┐   ┌─ADMIN DASH┐
   │Echo   │   │Echo  │   │❌ AUCUN   │
   │+poll  │   │+chime│   │Echo       │
   │15s    │   │+flash│   │(polls     │
   └───────┘   └──────┘   │ via APIs) │
                          └───────────┘
```

### 4.2 Matrice scénarios critiques


| #   | Scénario                                        | État actuel                                                                                       | Cible V1                                           | Action V1                                       |
| --- | ----------------------------------------------- | ------------------------------------------------------------------------------------------------- | -------------------------------------------------- | ----------------------------------------------- |
| A   | Admin marque item "rupture" → 3 surfaces < 2s   | 🟡 Borne OK (via `ItemAvailabilityChanged`) / **POS** : NON (pas abonné) / **KDS** : pas de badge | < 2s sur les 3                                     | MENU_86                                         |
| B   | POS cash → KDS < 500ms                          | 🟢 Chaîne complète, dépend infra                                                                  | < 500ms                                            | OK si SYNC_BACKBONE fail-fast                   |
| C   | KDS READY → OSS + Borne < 500ms                 | 🟢 OSS via Echo + chime / 🟡 Borne dépend polling 15s                                             | < 500ms sur 2                                      | Borne : subscribe Echo spécifique à sa commande |
| D   | Borne → POS kiosk-cash panel + KDS              | 🟢 OK via branch channel                                                                          | OK                                                 | —                                               |
| E   | Admin modifie prix → POS/Borne invalident cache | 🟡 Borne OK (`type=full`) / **POS NON** (pas abonné menu)                                         | POS doit invalider cache                           | MENU_86                                         |
| F   | WS down 15s → réconciliation                    | 🟡 Polling 10s (WS KO) / 60s (WS OK)                                                              | Bannière "reconnexion" si > 5s                     | SYNC_BACKBONE                                   |
| G   | Crash après commit DB mais avant event dispatch | 🔴 **TROU** : pas de ligne `domain_events`, pas de replay                                         | Event émis en listener `saved` ou dans transaction | OUTBOX renforcement                             |
| H   | 2 POS même idempotency-key                      | 🟢 OK (unique constraint + catch 23000)                                                           | OK                                                 | —                                               |


### 4.3 Events manquants (pour MVP fast-food)


| Event                                | Besoin                                             | Priorité V1                                                                   |
| ------------------------------------ | -------------------------------------------------- | ----------------------------------------------------------------------------- |
| `menu.item_availability_changed`     | ✅ existe                                           | —                                                                             |
| `order.created`                      | ✅ existe                                           | —                                                                             |
| `order.status_changed`               | ✅ existe                                           | —                                                                             |
| `menu.item_price_changed` (distinct) | Actuellement mutualisé dans `full`                 | Laissable mutualisé V1                                                        |
| `menu.category_reordered`            | Admin trie catégories → surfaces recharge          | V1.5                                                                          |
| `menu.published` (snapshot)          | Admin publie le menu complet → invalidation caches | V1.5                                                                          |
| `stock.low`                          | Enum existe, pipeline absent                       | V2                                                                            |
| `branch.availability_changed`        | Rupture spécifique branche                         | P0 V1 (couvert par `item_availability_changed` avec `branch_id` dans payload) |
| `kiosk.machine_online/offline`       | Gérant veut savoir si la borne 2 est morte         | V1.5                                                                          |


### 4.4 Gaps SYNC_BACKBONE (tâche #1)

- 🔴 `BROADCAST_DRIVER=null` par défaut dans config — si `.env` prod oublie, silencieux.
- 🔴 `QUEUE_CONNECTION=sync` par défaut — si `.env` prod oublie, bloquant.
- 🟡 Pas de **fail-fast boot** (`env('BROADCAST_DRIVER') === null && app()->environment('production')` → throw).
- 🟡 `WebSocketService.js` définit `MAX_RECONNECT_DELAY_MS` mais **ne l'utilise jamais** (reconnect = celui de Pusher natif).
- 🔴 **Aucune bannière unifiée** "reconnexion en cours" sur les 4 surfaces.
- 🔴 **Admin branch_id=0** ne subscribe pas Echo (condition `branchId <= 0`) → dashboard admin n'a aucune synchro live.

### 4.5 Gaps OUTBOX (tâche #2)

- ✅ Table `domain_events` + trait `HasDomainEvents` + listeners + job `DispatchDomainEventsJob` + `OutboxRescueCommand` + `OutboxRetryFailedCommand`.
- 🟡 **Pas de garantie exactly-once au niveau Pusher** : le job vérifie `dispatched_at` mais deux workers pourraient tirer la même ligne en même temps.
- 🔴 **Trou scénario G** : si PHP meurt entre `DB::commit()` et `event()` dispatch, aucune ligne `domain_events` → pas de rattrapage. **Solution V1** : faire tourner le listener `PersistToOutbox` via `Model::saved()` observer plutôt que event manuel.
- 🟡 `PersistItemAvailabilityChangedToOutbox` broadcast à **TOUTES** les branches actives — gaspillage réseau + pas de scoping par branche concernée.

### 4.6 Gaps EVENT_CONTRACT (tâche #3)

- ✅ Enveloppe V1 déjà alignée (version/type/aggregate_id/branch_id/occurred_at/correlation_id/payload).
- ✅ `eventContract.js` valide côté client.
- 🟡 Enum `EventType.php` liste `order.item_added`, `order.cancelled`, `stock.low` **mais aucune** implémentation outbox associée → enum à nettoyer ou pipeline à créer.
- 🟡 Pas de doc formelle `docs/EVENT_CONTRACT.md` avec schéma JSON par type.
- 🟡 Pas de classe de base `App\Events\DomainEvent` commune (aujourd'hui chaque event dispatch à la main).

---

## 5. Modèle intelligent : Menu Borne ↔ Menu POS

### 5.1 L'erreur à éviter

Deux CMS séparés (un pour POS, un pour Borne) = cauchemar ops : le gérant doit taper deux fois chaque modification, risque de divergence, pas de SSOT. **Refusé**.

### 5.2 Le modèle recommandé : SSOT + projections par canal

**Règle d'or** : un seul catalogue maître. Des **projections par canal** (POS, Kiosk, Web futur) contrôlent la **visibilité**, l'**ordre**, l'**upsell**, les **libellés alternatifs**. Le **prix** reste unique par item (V1). Le **stock** est scopé par branche (V1 via `item_branch_availability`, V2 via ingrédients).

### 5.3 Schéma V1 proposé (migrations minimales)

```
┌────────────────────────────────────────────────────────┐
│ items                       (existant, inchangé)        │
│   id, item_category_id, name, price, status, ...       │
│   + ALTER: allergen_flags JSON (halal, veg, vegan...)   │
│   + ALTER: channels JSON DEFAULT '["pos","kiosk"]'       │
│   + ALTER: kiosk_emoji VARCHAR(8) NULL                  │
└────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────┐
│ item_categories              (existant, inchangé)       │
│   id, name, sort, wizard_template, ...                 │
│   + ALTER: channels JSON DEFAULT '["pos","kiosk"]'      │
│   + ALTER: kiosk_sort INT NULL (override tri pour borne)│
│   + ALTER: pos_sort INT NULL (override tri pour POS)   │
│   + ALTER: kiosk_label VARCHAR(255) NULL (override nom) │
└────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────┐
│ item_branch_availability    (existant, activé)          │
│   item_id, branch_id,                                   │
│   is_available, unavailable_reason,                     │
│   unavailable_since, max_daily_qty,                     │
│   daily_consumed_qty, daily_reset_at                    │
│   ✅ À brancher aux APIs + UI admin                     │
└────────────────────────────────────────────────────────┘
```

### 5.4 API unifiée avec filtre `channel`

Une seule route serveur, un seul service (`MenuProjectionService`), trois vues différentes selon `?channel=kiosk|pos|web` :

```
GET /api/admin/menu?channel=kiosk&branch_id=1
GET /api/admin/menu?channel=pos&branch_id=1
GET /api/admin/menu?channel=web&branch_id=1

Response structure (uniforme) :
{
  "categories": [
    {
      "id": 5,
      "slug": "nos-tacos",
      "name": "Tacos" ou kiosk_label si présent,
      "sort": (kiosk_sort si channel=kiosk, sinon sort),
      "wizard_template": "tacos",
      "items": [
        {
          "id": 42,
          "name": "Tacos M (1 Viande)",
          "price": 6.50,
          "available": true,  // résolu depuis item_branch_availability
          "emoji": "🌯",       // kiosk_emoji si channel=kiosk
          "is_upsell": false,
          "allergens": ["halal"],
          "variations": [ ... visible_on filtré ... ],
          "extras": [ ... visible_on filtré ... ]
        }
      ]
    }
  ],
  "snapshot_version": 42,       // incrémente à chaque ItemAvailabilityChanged
  "branch_id": 1,
  "channel": "kiosk"
}
```

**Logique de filtrage côté serveur** :

```php
class MenuProjectionService
{
    public function forChannel(string $channel, int $branchId): array
    {
        $categories = ItemCategory::query()
            ->where('status', Status::ACTIVE)
            ->whereJsonContains('channels', $channel)     // NEW
            ->orderBy($channel === 'kiosk' ? 'kiosk_sort' : 'sort')
            ->get();

        foreach ($categories as $cat) {
            $cat->display_name = $channel === 'kiosk' && $cat->kiosk_label
                ? $cat->kiosk_label
                : $cat->name;

            $cat->items = $cat->items()
                ->where('status', Status::ACTIVE)
                ->whereJsonContains('channels', $channel)
                ->leftJoin('item_branch_availability as iba',
                    fn($j) => $j->on('items.id','=','iba.item_id')->where('iba.branch_id',$branchId))
                ->selectRaw('items.*, COALESCE(iba.is_available,1) as available')
                ->get();
        }

        return [
            'categories' => $categories,
            'snapshot_version' => MenuSnapshot::current($branchId),
            'branch_id' => $branchId,
            'channel' => $channel,
        ];
    }
}
```

### 5.5 UI admin : "Catalogue" unifié avec onglets canaux

Un seul écran `/admin/menu` avec 3 onglets :

```
┌────────────────────────────────────────────────────────┐
│ ▾ Catalogue  [TOUS] [POS] [BORNE] [WEB]                 │
│                                                          │
│ 🍔 Burgers ▓▓▓▓▓ 12 items  [POS✓][BORNE✓][WEB✗]         │
│   ├─ Big Tacos M      6.50€ [✓POS] [✓BORNE] 🔴 RUPTURE  │
│   ├─ Big Tacos L      8.50€ [✓POS] [✓BORNE] [✓]         │
│   └─ Burger Classic   5.90€ [✓POS] [✓BORNE] [✓]         │
│                                                          │
│ 🌯 Tacos Signature ▓▓▓▓ 8 items [POS✓][BORNE✓]          │
│   ...                                                    │
│                                                          │
│ 🔴 Bouton flottant "Rupture rapide" ────────────────────│
│ ┌──────────────────────────────────────────────────┐     │
│ │ 🔎 Rechercher un article à marquer en rupture    │     │
│ │ [Merguez Tacos] [Cordon Bleu] [Frites Cheddar]   │     │
│ │    × Merguez Tacos RUPTURÉ → tous canaux + KDS   │     │
│ └──────────────────────────────────────────────────┘     │
└────────────────────────────────────────────────────────┘
```

**Actions en 1 clic** :

- Toggle channel par ligne (`[✓POS] [✓BORNE] [✓WEB]`)
- Bouton **🔴 Rupture** → POST `/api/admin/menu/toggle-availability` + event `ItemAvailabilityChanged` avec `branch_id` explicite
- Bouton **Happy hour** (V1.5) → planifie désactivation/activation temporelle

### 5.6 Synchro temps réel menu (renforcement V1)

```
Mutation admin menu
  ↓
MenuProjectionService::toggleAvailability(itemId, branchId, reason)
  ↓
DB::transaction {
  UPDATE item_branch_availability SET is_available=false, reason=... 
  + MenuSnapshot::bumpVersion(branchId)  // ← NEW
  + fire model.saved observer → PersistItemAvailabilityChangedToOutbox
}
  ↓
DispatchDomainEventsJob
  ↓
Pusher::trigger('private-branch.1', 'ItemAvailabilityChanged',
  { type: 'menu.item_availability_changed',
    aggregate_id: 42,
    branch_id: 1,
    payload: {
      item_id: 42,
      available: false,
      reason: 'out_of_stock',
      snapshot_version: 43   // pour dédup client
    }
  })
  ↓
  ├─ POS: handler → désactive tap + toast "Article 'Merguez' en rupture depuis 14:23"
  ├─ Borne: handler → masque item OU grise OU affiche "Épuisé"
  ├─ KDS: handler → badge rouge "rupture signalée" sur commandes en cours contenant l'item
  └─ Dashboard: widget "Ruptures actives" incrémente
```

### 5.7 Règle de contrôle d'accès catalogue (V1)


| Rôle           | Permissions sur catalogue                                                                                                                                          |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Admin          | CRUD complet + rupture + publication                                                                                                                               |
| Branch Manager | Rupture uniquement sur sa branche + voir catalogue (pas d'ajout/suppr d'items globaux)                                                                             |
| POS Operator   | ❌ aucun accès catalogue admin — uniquement via bouton "Signaler rupture" sur POS qui POST `/api/admin/menu/toggle-availability` avec abilitée `menu:report_outage` |
| Chef           | Idem POS Operator (rupture depuis KDS)                                                                                                                             |


### 5.8 Avantages du modèle

1. **SSOT** : un seul `items` table, pas de duplication.
2. **Flexible** : ajouter une surface (web public, app mobile) = ajouter une valeur au JSON `channels` et une route `?channel=web`.
3. **Déploiement V1 minimal** : 3 migrations (ALTER items, ALTER item_categories, activer item_branch_availability) + 1 service + 1 API + 1 UI admin refonte modérée.
4. **Rétrocompatible** : si un item n'a pas `channels` défini → fallback `['pos','kiosk']` (migration par défaut).
5. **Extensible V1.5** : ajouter `price_overrides(channel, item_id, price, start_at, end_at)` pour happy hour sans casser le modèle.

---

## 6. Matrice V1 — 12 tâches — état vs cible

Mapping précis de chaque tâche de ton Index V1 contre l'état réel du code.


| #   | Task                   | État actuel                                                                        | Reste à faire V1                                                                                                                                                                                                                                   | J-H                     | Gate                   |
| --- | ---------------------- | ---------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------- | ---------------------- |
| 1   | **SYNC_BACKBONE**      | PARTIAL (outbox + queue high + Echo OK)                                            | Fail-fast boot prod, heartbeat Echo 30s, reconnect exponentiel (1→2→4→30s), bannière "reconnexion…" unifiée 4 surfaces, doc `PRODUCTION_SETUP.md`                                                                                                  | 2 j                     | non                    |
| 2   | **OUTBOX**             | PARTIAL (table + listeners + job + rescue cmd)                                     | Combler scénario G (crash avant dispatch), passer `PersistToOutbox` en observer `saved()`, idempotency exactly-once au niveau job, tests outbox exhaustifs                                                                                         | 4 j                     | non                    |
| 3   | **EVENT_CONTRACT**     | PARTIAL (enveloppe OK, eventContract.js OK)                                        | Classe `App\Events\DomainEvent` base, nettoyer `EventType` enum, docs `EVENT_CONTRACT.md` avec schéma JSON par type, `PayloadMismatchException`                                                                                                    | 2 j                     | non                    |
| 4   | **PRICING_SSOT**       | PARTIAL (OrderService recompute déjà server-side)                                  | Extraire `PricingService` centralisé, OrderService + FrontendOrderService deviennent adaptateurs, grep CI "0 calcul client", symétrie testée                                                                                                       | 3 j                     | **OUI** (frozen zone)  |
| 5   | **STATUS_MACHINE**     | PARTIAL (`ValidStatusTransition` + `OrderStateMachine::recordTransition` existent) | Transitions explicites enum-based, `IllegalTransitionException`, grep CI "0 `$order->status =` hors SM", tests exhaustifs                                                                                                                          | 3 j                     | non                    |
| 6   | **MENU_86**            | TODO (table existe mais pas branchée)                                              | **Chantier V1 majeur** : activer `item_branch_availability` dans MenuProjectionService, ajouter colonnes `channels` JSON sur items + categories, UI admin toggle rupture 1 clic, abonner POS à `ItemAvailabilityChanged`, masquer borne, badge KDS | 3 j → **5 j réalistes** | non mais vigilance     |
| 7   | **SEC_XSS**            | TODO                                                                               | Grep `v-html`, 5 spots identifiés par audit 360 (Page/Chat/PageShow/MessageList/TablePage), remplacer par `v-text` ou DOMPurify, ESLint `no-v-html` error                                                                                          | 1 j                     | non                    |
| 8   | **SEC_CORS_RATELIMIT** | PARTIAL (rate limiters OK, CORS `*` à corriger)                                    | `config/cors.php` whitelist (`APP_URL, KIOSK_DOMAIN, ADMIN_DOMAIN`), audit exhaustif routes mutables, doc `RATE_LIMITS_MATRIX.md`                                                                                                                  | 2 j                     | non                    |
| 9   | **DATA_SOFTDELETE**    | PARTIAL (users/items/order déjà soft, branches aussi ligne récente)                | Vérifier order_items, addresses, scopes admin `withTrashed`, `foodking:purge-old-soft-deleted --days=365`, audit branch_scope interaction                                                                                                          | 2 j                     | vigilance branch_scope |
| 10  | **OBS_HEALTH_CORR**    | PARTIAL (`/api/health`, CorrelationIdMiddleware, `production_json` channel OK)     | Endpoint `/health` admin (non authentifié allowlist IP), propagation correlation_id dans jobs, doc `OBSERVABILITY.md`                                                                                                                              | 2 j                     | non                    |
| 11  | **TEST_PW_5FLOWS**     | PARTIAL (6 specs existent, 1 parfois flaky)                                        | Stabiliser 5 flows core (pos-cash, pos-card, kiosk-order, kds-transitions, auth-f5), CI < 3 min, 10 runs consécutifs verts                                                                                                                         | 2 j                     | non                    |
| 12  | **TEST_PRICING_STATE** | TODO                                                                               | `PricingServiceTest` + `OrderStateMachineTest` exhaustifs, coverage 100% lignes / 95% branches sur ces namespaces                                                                                                                                  | 1 j                     | non                    |


**Total révisé** : ~29 j-h (roadmap original 27 j-h + 2 j MENU_86 plus réaliste vu l'ampleur).

### 6.1 Ordre d'exécution recommandé (vagues)

**Vague 1 (SÉQUENTIELLE, 8 j-h) — Synchro foundation**

1. SYNC_BACKBONE (Composer, 2 j)
2. OUTBOX (GPT-5.4, 4 j) — bloqué par 1
3. EVENT_CONTRACT (GPT-5.4, 2 j) — bloqué par 2

**Vague 2 (PARTIELLEMENT PARALLÈLE, ~9-11 j-h) — Domaine SSOT**
4. PRICING_SSOT (GPT-5.4, 3 j) — **GATE OUI** (frozen zone)
5. STATUS_MACHINE (GPT-5.4, 3 j) — indépendant de 4
6. MENU_86 (GPT-5.4, 5 j) — bloqué par 3 (EVENT_CONTRACT)

**Vague 3 (PARALLÈLE, 3 j-h) — Sécurité base**
7. SEC_XSS (Composer, 1 j)
8. SEC_CORS_RATELIMIT (Composer, 2 j)

**Vague 4 (PARALLÈLE avec dépendances, 7 j-h) — Data + Obs + Tests**
9. DATA_SOFTDELETE (GPT-5.4, 2 j)
10. OBS_HEALTH_CORR (Composer, 2 j) — bloqué par 1
11. TEST_PW_5FLOWS (Composer, 2 j) — bloqué par 3, 4, 5, 6
12. TEST_PRICING_STATE (Composer, 1 j) — bloqué par 4, 5

**Calendrier** : 27-29 j-h avec un dev senior focus full-time, soit **5-7 semaines calendaires** incluant gates humaines et revues.

---

## 7. Plan de mission — "Dépasser la concurrence" en V1

### 7.1 Constat concurrence

Toast, Square, Lightspeed, Wolt sont des mastodontes généralistes. Leur force : intégration complète (paiement + comptabilité + livraison + fidélité). Leur faiblesse : lourdeur, coûts récurrents élevés ($200-500/mois/site), UX générique, mauvais support multi-langues Europe, catalogue wizard tacos/kebab pauvre (pas pensé fast-food FR).

### 7.2 Où FoodKing peut gagner en V1


| Axe                                 | Leader marché                                  | FoodKing V1 différentiateur                                                                                                                                         |
| ----------------------------------- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Wizard tacos/kebab native**       | ❌ Toast/Square = "modifiers" génériques        | ✅ 8 templates wizard dédiés fast-food FR (tacos/sandwich/burger/assiette/salade/omelette/snacking/simple) avec emojis, compteurs viande, crudités pré-sélectionnées |
| **Borne autonome bootable**         | ✅ Wolt/Toast proposent mais payant et lent     | ✅ Kiosk auto-login machine-scoped, offline queue IndexedDB, vidéo attract, retour auto idle                                                                         |
| **Synchro ultra-rapide 4 surfaces** | ✅ Toast                                        | ✅ Outbox + Echo + polling fallback 10s/60s                                                                                                                          |
| **Zero-touch rupture**              | 🟡 Toast oui mais nécessite inventaire complet | ✅ V1 : toggle rupture 1 clic branch-scoped sans inventaire ingrédients                                                                                              |
| **Prix serveur SSOT**               | ✅ tous                                         | ✅ déjà en place                                                                                                                                                     |
| **Mode maintenance borne**          | 🟡 limité                                      | ✅ sessionStorage + admin PIN + actions diagnostiques                                                                                                                |
| **Support multi-langues FR/AR/EN**  | 🟡 partiel                                     | ✅ i18n déjà câblé                                                                                                                                                   |
| **Idempotency X-Key partout**       | ❌ rarement exposé au client                    | ✅ V1 déjà robuste                                                                                                                                                   |
| **Coût mensuel**                    | $200-500/mois/site                             | ✅ self-hosted → coût marginal                                                                                                                                       |


### 7.3 Plan de mission V1 — "Fast-food ready + architecture V2-proof"

**Principe** : V1 n'est PAS un produit complet. C'est un **MVP solide** sur lequel V1.5/V2/V3 s'ajoutent par composition (nouveaux consumers sur l'event bus, nouvelles surfaces sur le contrat d'event). Livrer moins, mais livrer propre.

**Jalons** :

#### Sprint 0 — Gates humaines + prérequis (2 j)

- Gate humaine signature des 4 gates prod (security, pricing frozen, channel model, queue prod)
- Rotation password `kiosk-lecayenne` (pas `kiosk123`)
- `.env.production` lockdown : `BROADCAST_DRIVER=pusher`, `QUEUE_CONNECTION=redis`, `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`, `APP_DEBUG=false`, `HEALTH_IPS_ALLOWED=<monitoring-IPs>`
- Supervisor config queue worker + Soketi + PHP-FPM

#### Sprint 1 (Vague 1) — Synchro foundation (8 j)

- **TASK_V1_SYNC_BACKBONE_001** : fail-fast boot, heartbeat Echo, reconnect exponentiel, bannière "reconnexion…" unifiée
- **TASK_V1_OUTBOX_001** : combler scénario G (observer `saved` au lieu d'event manuel), exactly-once au dispatch, tests outbox exhaustifs
- **TASK_V1_EVENT_CONTRACT_001** : DomainEvent base class, doc `EVENT_CONTRACT.md`, PayloadMismatchException, nettoyage enum

#### Sprint 2 (Vague 2) — Domaine SSOT (9-11 j)

- **TASK_V1_PRICING_SSOT_001** ⚠️ **GATE FROZEN ZONE** : extraire `PricingService`, POS/Frontend deviennent adapters, tests parité
- **TASK_V1_STATUS_MACHINE_001** : enum-based transitions, IllegalTransitionException, grep CI "0 assignation brute", coverage 95%
- **TASK_V1_MENU_86_001** : **chantier central fast-food** — modèle `channels[]` + `item_branch_availability` branchée + MenuProjectionService + UI admin toggle rupture 1 clic + abonnement POS à ItemAvailabilityChanged + badge KDS rupture-signalée

#### Sprint 3 (Vague 3) — Sécurité base (3 j)

- **TASK_V1_SEC_XSS_001** : 5 v-html sanitisés
- **TASK_V1_SEC_CORS_RATELIMIT_001** : CORS whitelist + rate limit sweep

#### Sprint 4 (Vague 4) — Data + Obs + Tests (7 j)

- **TASK_V1_DATA_SOFTDELETE_001** : order_items soft, scopes admin withTrashed, purge command
- **TASK_V1_OBS_HEALTH_CORR_001** : /health admin endpoint, correlation_id jobs, doc OBSERVABILITY.md
- **TASK_V1_TEST_PW_5FLOWS_001** : 5 flows stabilisés CI < 3 min
- **TASK_V1_TEST_PRICING_STATE_001** : coverage 100% PricingService + 95% StateMachine

#### Sprint 5 — Validation + durcissement (3 j)

- 2 semaines sur env staging avec données Le Cayenne
- Tests chaos : coupure WS 30s, crash queue worker, kill Soketi, reboot borne, corruption localStorage
- Revue sécurité interne (OWASP Top 10 applicables)
- Préparer playbook incident ops (tiroir cash bloqué, TPE down, Pusher down)
- Tag `v1.0.0` sur main

### 7.4 "Killer features" qu'on ajoute GRATUITEMENT pendant V1 (différenciation concurrence)

Ces items ne sont PAS des nouvelles tâches — ils sont des **conséquences naturelles** des 12 tâches si on les implémente bien. Ils constituent un vrai avantage concurrentiel :

1. **Rupture "broadcast en 1 clic"** (conséquence MENU_86) : aucun concurrent FR n'a cette UX. Toast nécessite inventaire complet.
2. **Bannière de connexion unifiée** (conséquence SYNC_BACKBONE) : quand WS down, user sait immédiatement. Square ne le fait pas.
3. **Correlation-id traçable end-to-end** (conséquence OBS_HEALTH_CORR) : support client ultra-rapide ("donne-moi ton correlation-id"), zero concurrent ne l'a en MVP.
4. **Idempotency-key sur tous paiements** (déjà en place) : zero double-débit, Square n'a pas ce niveau par défaut.
5. **Channel model flexible** (conséquence MENU_86) : ajouter le site web V2 = juste ajouter `"web"` au JSON, pas de refactoring.

### 7.5 Hors périmètre V1 — rappel explicite

Stop-gate automatique si un agent propose (conformité Index V1) :

- 2FA, RGPD, plateformes livraison, fidélité CRUD avancée, mobile, site web public, BI forecasting, inventaire ingrédients, scheduling staff, WYSIWYG riche, thème avancé, multi-langue dynamique complet

**Ces items iront en** : V1.5 (post-lancement 2-4 semaines réels) / V2 (8-12 semaines) / V3 (6-8 semaines livraison) / Pré-go-live (RGPD + pentest + load test).

---

## 8. Décomposition orchestrée des 12 tâches

### 8.1 Protocole d'exécution (référence `.cursor/commands/run-cycle.md`)

Pour chaque tâche :

```
PLAN (Claude planner)
  ↓ lit tasks/TASK_V1_XXX_001.md
  ↓ produit plans/PLAN_V1_XXX_001_<date>.md
  ↓ GATE brief si frozen zone
EXECUTE (subagent selon PRIMARY_MODEL)
  ↓ foodking-routine-implementer (Composer)
  ↓ ou foodking-complex-implementer (GPT-5.4)
  ↓ .cursor/hooks/post-execute.sh avant VALIDATE
VALIDATE (TEST_STRATEGY déclarée)
  ↓ local-validation | playwright-critical-flow | static-inspection
AUDIT (Claude auditeur)
  ↓ .cursor/context/audit-context.md
CLOSE
  ↓ gate humaine si requise, sinon next task
```

### 8.2 Critères de succès par tâche

Voir section 6 pour le mapping état actuel → cible. Chaque tâche a ses propres critères d'acceptation détaillés dans `tasks/TASK_V1_XXX_001.md` (déjà créés selon Index V1).

### 8.3 Gates humaines V1 (à signer AVANT merge main)

1. **Gate Security** : Sign-off security review avant TASK_V1_SEC_CORS_RATELIMIT_001 close
2. **Gate Frozen Zone Pricing** : Sign-off avant TASK_V1_PRICING_SSOT_001 execute
3. **Gate Channel Model** : Sign-off du schéma `channels[]` JSON + migrations item_branch_availability avant TASK_V1_MENU_86_001 execute
4. **Gate Prod Readiness** : Sign-off supervisor + Redis + .env.production avant tag v1.0.0

### 8.4 Définition de "DONE" V1 (reprise Index V1)

**Fonctionnel**

- POS cash + POS carte + Kiosk + KDS + OSS : cycles complets sans erreur
- Rupture admin reflète sur les 3 autres surfaces en < 2s (A)
- Cycle commande POS cash sans erreur (B)
- Kiosk → KDS réception < 3s (B)
- 2 commandes concurrentes KDS transitions sans bug (C)
- OSS live statuts (C)

**Technique**

- 0 calcul prix hors `PricingService` (grep CI)
- 0 transition `OrderStatus` hors `StateMachine` (grep CI)
- 0 `ShouldBroadcastNow` (grep CI) — tout passe par outbox
- 12 specs Playwright + tests PHPUnit verts en CI
- Coverage PricingService 100%, StateMachine ≥ 95% branches

**Sécu base**

- 0 `v-html` non sanitisé
- CORS whitelist active (pas de `*`)
- Rate limit sur tous endpoints mutables

**Opérabilité**

- `/health`, `/health/live`, `/health/ready` opérationnels
- Logs JSON structurés avec `correlation_id`
- Docs livrées : `PRODUCTION_SETUP.md`, `EVENT_CONTRACT.md`, `RATE_LIMITS_MATRIX.md`, `SECURITY_NOTES.md`, `OUTBOX_PATTERN.md`, `MENU_AVAILABILITY.md`, `SOFT_DELETE_POLICY.md`, `OBSERVABILITY.md`, `PLAYWRIGHT_SUITE.md`

---

## 9. Risques & garde-fous


| Risque                                                | Impact                                          | Mitigation V1                                                                         |
| ----------------------------------------------------- | ----------------------------------------------- | ------------------------------------------------------------------------------------- |
| Extraction PricingService casse la symétrie POS/Kiosk | Prix différents entre surfaces — perte d'argent | Tests de parité POS/Kiosk OBLIGATOIRES dans TASK_V1_PRICING_SSOT_001                  |
| Outbox introduit latence perçue                       | Events arrivent 500ms+ plus tard au KDS         | Queue `high` + Redis + mesure p95 < 1s obligatoire dans TEST_PW_5FLOWS                |
| Soft-delete casse les scopes existants                | Données masquées dans admin                     | Revue branch_scope + withTrashed audit + tests isolation (ConcurrentOrderTest étendu) |
| StateMachine refuse transitions legacy en prod        | Blocage opérationnel                            | Migration data audit + mapping transitions legacy + rollback flag                     |
| Tests Playwright flaky                                | CI non fiable, perte confiance                  | 10 runs consécutifs obligatoires avant acceptation                                    |
| Scope expansion hors V1                               | Retard, refactoring                             | Section 7 roadmap = référence autoritaire. Stop-gate immédiat si dérive               |
| Rôle Delivery Boy landing admin sans permission       | Login → page exception                          | **Sprint 0** : soit rendre `landing_url = '#'` soit seed permission `delivery-boys`   |
| `item_branch_availability` sans FK                    | Risque orphelins si item ou branch supprimé     | Ajouter FK `ON DELETE CASCADE` dans migration MENU_86                                 |
| Migration `channels` JSON sur items existants         | 10000+ rows à mettre à jour                     | `DEFAULT '["pos","kiosk"]'` + migration `ALTER` en une seule transaction              |


---

## 10. Annexes — cartographie fichiers

### 10.1 Backend critiques

```
app/Services/OrderService.php                    (1693 L) — POS create + changeStatus
app/Services/FrontendOrderService.php                    — Kiosk/Web create + changeStatus
app/Services/ItemService.php                             — update + ItemAvailabilityChanged emit
app/Services/ItemCategoryService.php                     — ⚠️ pas d'event sur update
app/Services/Menu/AvailabilityService.php                — decrementForOrder (existe, pas broadcast)
app/Services/KitchenDisplaySystemOrderService.php
app/Services/PermissionService.php                       — injected in login response

app/Events/OrderCreated.php
app/Events/OrderStatusChanged.php
app/Events/ItemAvailabilityChanged.php

app/Listeners/PersistOrderCreatedToOutbox.php
app/Listeners/PersistOrderStatusChangedToOutbox.php
app/Listeners/PersistItemAvailabilityChangedToOutbox.php    (⚠️ fan-out all branches)
app/Listeners/DecrementItemAvailabilityOnOrder.php         (⚠️ pas de broadcast after decrement)

app/Jobs/DispatchDomainEventsJob.php       — queue 'high', tries 5, backoff [1,5,30,300]
app/Console/Commands/OutboxRescueCommand.php
app/Console/Commands/OutboxRetryFailedCommand.php

app/Http/Requests/PosOrderRequest.php
app/Http/Requests/OrderRequest.php
app/Http/Requests/ItemRequest.php

app/Http/Controllers/Admin/PosController.php
app/Http/Controllers/Admin/KioskMachineController.php
app/Http/Controllers/Admin/KitchenDisplaySystemController.php
app/Http/Controllers/Frontend/ItemController.php           — kiosk-upsell logic
app/Http/Controllers/HealthController.php

app/Http/Middleware/CorrelationIdMiddleware.php
app/Http/Middleware/ApiKeyMiddleware.php

app/Providers/EventServiceProvider.php     — mapping events → listeners
app/Providers/RouteServiceProvider.php     — rate limiters
app/Providers/BroadcastServiceProvider.php

app/Enums/EventType.php                    — ⚠️ STOCK_LOW, ORDER_CANCELLED non câblés
app/Enums/Status.php                       — ACTIVE=5, INACTIVE=10
app/Enums/Ask.php                          — YES=5, NO=10

routes/api.php                             — 900+ lignes, pas versionné
routes/channels.php                        — branch.{branchId} + ability kiosk:order
```

### 10.2 Migrations critiques

```
database/migrations/
  2022_11_17_110514_create_items_table.php
  2022_11_17_110428_create_item_categories_table.php
  2022_11_17_110621_create_item_variations_table.php
  2022_11_17_110650_create_item_extras_table.php
  2024_02_29_095727_add_sort_to_item_categories_table.php
  2026_03_12_080617_add_wizard_config_to_item_categories.php
  2026_03_12_130000_add_performance_indexes.php
  2026_03_25_002927_add_is_upsell_to_items_table.php
  2026_03_25_002938_add_idempotency_key_to_orders_table.php
  2026_03_26_090640_add_visible_on_and_group_label_to_item_extras_table.php
  2026_03_26_090651_add_visible_on_to_item_variations_table.php
  2026_03_27_120000_add_kiosk_upsell_flags_to_item_categories_table.php
  2026_04_15_200000_create_domain_events_table.php         — outbox
  2026_04_15_230000_create_order_status_transitions_table.php
  2026_04_15_230100_create_item_branch_availability_table.php   — ⚠️ orpheline
  2026_04_15_230200_v1_soft_deletes_and_deletion_log.php
```

### 10.3 Frontend critiques

```
resources/js/app.js                  — entry (+ import './bootstrap' V5 fix)
resources/js/bootstrap.js            — Echo init (V5 fixed: process.env.MIX_PUSHER_*)

resources/js/services/eventContract.js
resources/js/services/WebSocketService.js    — ⚠️ MAX_RECONNECT_DELAY_MS unused
resources/js/services/appService.js

resources/js/components/admin/pos/PosComponent.vue            (1862 L god)
resources/js/components/admin/pos/ItemComponent.vue
resources/js/components/admin/pos/PaymentComponent.vue
resources/js/components/admin/pos/ReceiptComponent.vue
resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue
resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue
resources/js/components/admin/dashboard/DashboardComponent.vue   — ⚠️ pas d'Echo
resources/js/components/admin/items/ItemListComponent.vue
resources/js/components/admin/items/ItemCreateComponent.vue

resources/js/components/frontend/kiosk/KioskAppComponent.vue
resources/js/components/frontend/kiosk/KioskWizardComponent.vue   (1408 L)
resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue (V4 wrapper)
resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue
resources/js/components/frontend/kiosk/KioskCartComponent.vue
resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
resources/js/components/frontend/kiosk/KioskWaitingComponent.vue
resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue

resources/js/components/layouts/backend/BackendMenuComponent.vue
resources/js/components/layouts/backend/BackendNavbarComponent.vue

resources/js/store/modules/posCart.js
resources/js/store/modules/posOrder.js
resources/js/store/modules/kioskCart.js
resources/js/store/modules/kioskMenu.js        — offline cache IndexedDB
resources/js/store/modules/dashboard.js
resources/js/store/modules/item.js

resources/js/helpers/kioskOfflineQueue.js
resources/js/helpers/kioskPrinter.js
resources/js/helpers/kioskMenuCache.js
resources/js/helpers/kioskPricing.js

public/js/pos-wizard.js    (5763 L vanilla, god file V4.1)
```

### 10.4 Config & ops

```
config/kiosk.php                       — spa_payload, sandwich_split, menu_pricing, rate limits
config/broadcasting.php                — ⚠️ BROADCAST_DRIVER=null par défaut
config/queue.php                       — ⚠️ QUEUE_CONNECTION=sync par défaut
config/cors.php                        — ⚠️ allowed_origins ['*']
config/logging.php                     — channel 'hardware' + 'production_json'

.env.example                           — documente MIX_API_KEY, MIX_PUSHER_*

.cursor/commands/run-cycle.md          — protocole PLAN/EXEC/VALIDATE/AUDIT
.cursor/rules/human-gates.mdc          — 4 gates humaines V1
.cursor/rules/project-invariants.mdc   — invariants architecturaux
.cursor/rules/scope.mdc                — scope V1 enforced
tasks/                                 — 12 fiches TASK_V1_*.md (Index V1)

docs/GUIDE_DEVELOPPEUR.md              — ⚠️ claim Telescope installé (faux)
docs/KIOSK_DEPLOYMENT.md
docs/V4.1_WIZARD_UNIFICATION.md
audits/FoodKing_Roadmap_V1.docx        — référence stratégique
audits/FoodKing_Audit_Intelligence_360.docx

reports/execution/
  RUN_STAFF_ONLY_V1_2026-04-16.md
  AUDIT_MAX_2026-04-16.md
  AUDIT_MASSIF_FR_2026-04-16.md        ← CE DOCUMENT
```

---

## Mot de la fin

Ce plan V1 applique littéralement les principes de CLAUDE.md §3 : **vision > speed, architecture > convenience, correctness > token savings**. Chaque ligne V1 doit respecter le contrat d'architecture V1, pour que chaque ligne V1.5/V2/V3 s'y branche **sans refactoring**.

Le modèle `channels[]` + `item_branch_availability` propre V1 permettra d'ajouter :

- un **site web public** en V2 = ajouter `"web"` au JSON, pas une ligne de code métier à refactorer
- une **app mobile** en V2 = créer `/api/v1/public` qui réutilise `MenuProjectionService`
- les **plateformes livraison** en V3 = channel `"delivery"` + webhook adapter UberEats/Deliveroo

**FoodKing ne cherche pas à battre Toast sur TOUS les axes**. FoodKing cherche à être LE meilleur POS+Borne+KDS synchronisé pour fast-food français multi-sites à prix compétitif, avec un wizard tacos que personne d'autre ne fait aussi bien.

V1 = **livrer petit, livrer propre, livrer prévisible**. Le reste viendra.

---

**Signé** : Orchestrateur FoodKing
**Méthode** : 3 sous-agents explore en parallèle + lecture des 2 audits docx + 400+ références file:line
**Fichiers complémentaires** :

- `reports/execution/AUDIT_MAX_2026-04-16.md` (audit global)
- `reports/execution/RUN_STAFF_ONLY_V1_2026-04-16.md` (exécution V0-V7)
- `docs/V4.1_WIZARD_UNIFICATION.md` (plan wizard borne)
- `audits/FoodKing_Roadmap_V1.docx` (stratégie 12 tâches)
- `audits/FoodKing_Audit_Intelligence_360.docx` (audit 360 comparatif marché)

