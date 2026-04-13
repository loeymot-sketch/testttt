# Continuité projet & vision — passation pour nouvelle session IDE

**Objectif de ce document** : donner à tout assistant (ou développeur) qui ouvre une **nouvelle discussion** l’état réel du projet **FoodKing / déploiement « Le Cayenne »**, la **vision produit** telle qu’elle a été exprimée, les **choix techniques**, **où se trouve le code**, **ce qui a été corrigé**, et **ce qui reste à faire**.  
**Complément obligatoire** : lire aussi `README.md`, `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `docs/API_MAP.md`, `docs/AUTHZ_MATRIX.md`, `docs/BUSINESS_RULES.md`.

---

## 1. Résumé exécutif (5–10 lignes)

- **Monolithe Laravel 9** + **SPA Vue 3** (admin, POS, KDS, OSS) ; **MySQL** ; **Sanctum** + **Spatie Permission** ; notifications **FCM** et événements **Laravel** (broadcast Pusher/Soketi si configuré).
- **Deux grands chemins de commande** : **POS / tables** via `OrderService` et **`FrontendOrderService`** pour kiosk / web client (même table SQL `orders`, modèles Eloquent distincts `Order` vs `FrontendOrder`).
- **Vision opérationnelle** : caisse rapide avec **wizard** (options, viandes, sauces, suppléments), **catégorie Suppléments** dédiée, **synchronisation** POS ↔ cuisine (KDS) ↔ écran client (OSS), borne **Windows + Electron** avec **imprimante ESC/POS**, **TPE** (adaptateurs) et **tiroir caisse** sur espèces.
- **État** : nombreux correctifs sur **intégrité commandes**, **file d’attente**, **broadcasts**, **auth après refresh**, **loyalty**, **clé API**, **modèle FrontendOrder**, **borne** ; **non livré** en profondeur : **modification d’une commande déjà validée** (amend) côté POS web, **temps réel fiable** (queue worker + WebSockets opérationnels), **FCM** pleinement configuré.

---

## 2. Contexte métier et branding

| Élément | Détail |
|--------|--------|
| **Restaurant cible** | **Le Cayenne** (remplacement du branding type « Le Grill House » / démo Bangladesh). |
| **Devise / menu** | Configurée pour **EUR** et contenu **Le Cayenne** dans `config/menu.php` + seeders associés. |
| **Objectif** | Base exploitable en production : pas de démo incohérente, auth et données stables, parcours commande bout en bout. |

---

## 3. Vision produit par surface (ce que l’utilisateur veut vraiment)

### 3.1 POS (caisse web Vue)

- **Wizard produit** : choix de viande, cuisson, sauces, suppléments, options — **UX rapide**, modal large, logique dans `public/js/pos-wizard.js` + `public/css/pos-wizard.css` et intégration Vue (`ItemComponent.vue`, etc.).
- **Catégories** : menu complet incluant une catégorie **« Suppléments »** (slug typique `supplements`) avec **articles autonomes** (sauce en plus, fromage, etc.), pas seulement des options liées à un plat.
- **Panier** : module Vuex `posCart`, persistance locale avec TTL.
- **Paiement** : **Cash** et **Card** en UI ; sur **POS navigateur**, la carte = saisie **4 derniers chiffres** / note dans `pos_payment_note` — **pas d’intégration TPE physique** dans le seul navigateur (il faudrait Electron ou service local si exigence stricte).
- **Client « Walking »** : filtrage clients avec `role_id: 2`, fallback email / nom contenant « walking », puis premier client — **risque** si aucun walking en base.
- **Après coup** (demande récurrente) : pouvoir **modifier une commande déjà validée** (amend / rouvrir) — **à spécifier** (annuler + recréer vs patch API) ; **largement non implémenté** sur le flux POS web documenté ici.

### 3.2 KDS (Kitchen Display System)

- **Rôle** : voir les commandes cuisine, changer les statuts (préparation, prêt, etc.).
- **Attente utilisateur** : **mise à jour quasi temps réel** avec l’OSS et le POS ; en pratique : **polling ~30 s** + événement navigateur `realtime-order-update` + **broadcast Laravel** si Pusher/Soketi OK.
- **Point critique corrigé** : les changements de statut **depuis le KDS** doivent déclencher **`OrderStatusChanged`** pour que l’**OSS** (et le reste) reste cohérent — logique dans `KitchenDisplaySystemOrderService` (à vérifier dans le code actuel).

### 3.3 OSS (Order Status Screen)

- **Rôle** : affichage côté salle / client : commandes en préparation / prêtes.
- **Comportement** : même idée que KDS — **écoute CustomEvent** + **polling** en secours.

### 3.4 Borne / Kiosk (Windows + Electron)

- **Contexte** : passage d’une cible **Android** vers **Windows** ; application **Electron** en **plein écran / démarrage auto**.
- **Besoins matériels** : **impression ticket** (ESC/POS), **terminal de paiement** (plusieurs marques possibles via **adaptateurs**), **ouverture tiroir caisse** sur **paiement espèces**.
- **Emplacement code** : souvent un dossier **`borne-windows/`** au niveau du dossier parent du projet Laravel (ex. `.../web/borne-windows/`) — **pas toujours dans le même repo** que `testttt` ; vérifier le workspace local.
- **Correctif documenté** : appel **`window.borne.openDrawer()`** dans le flux **cash** de la vue paiement kiosk (ex. `KioskPaymentView.vue` / `processCashPayment`).
- **API** : création commande `POST /frontend/order`, confirmation carte `POST /frontend/order/{id}/payment-confirm` — côté Laravel `Frontend\OrderController` ; persistance complète du `transaction_id` selon version (parfois log uniquement).

### 3.5 Commandes table / QR / frontend

- Même **table `orders`** avec le modèle **`FrontendOrder`** pour les flux non-POS.
- **File d’attente** `queue_number` : logique **cross-table** entre `Order` et `FrontendOrder` avec gestion de **scope branche** (`BranchScope`, `withoutGlobalScope` là où nécessaire) pour éviter doublons et bugs **branch_id = 0** en admin.

### 3.6 Sécurité & configuration (demandes utilisateur)

- **`DEMO=false`** en production.
- **Loyalty** : endpoints sensibles (`add-points`, `redeem`, `balance`, `history`) derrière **`auth:sanctum`** ; `check` / `register` peuvent rester publics selon règle métier — **à valider**.
- **Clé API** : middleware type **`ApiKeyMiddleware`** doit utiliser **`config('app.api_key')`** (sourcé depuis `env('MIX_API_KEY')` dans `config/app.php`) pour survivre à **`php artisan config:cache`**.

---

## 4. Architecture technique détaillée

### 4.1 Flux HTTP typique

```
Client (navigateur, ou Electron borne)
  →  Header x-api-key + Bearer Sanctum (selon route)
  →  routes/api.php + middlewares (auth, permission, abilities kiosk…)
  →  Controllers (Admin/*, Frontend/*)
  →  Services (OrderService, FrontendOrderService, KitchenDisplaySystemOrderService, …)
  →  MySQL
  →  Events (OrderCreated, OrderStatusChanged) / Jobs (SendOrderGotPush, …)
  →  FCM + (optionnel) Broadcast → Echo / Pusher / Soketi
```

### 4.2 Fichiers et modules clés (backend)

| Zone | Fichiers / dossiers typiques |
|------|------------------------------|
| Commandes POS / admin | `app/Services/OrderService.php` (très volumineux — recalcul prix, statuts, coupons, notifications) |
| Commandes kiosk / web | `app/Services/FrontendOrderService.php`, `app/Models/FrontendOrder.php` |
| KDS | `app/Services/KitchenDisplaySystemOrderService.php`, `Admin\KitchenDisplaySystemController` |
| Auth | `app/Http/Controllers/Auth/LoginController.php`, `routes/api.php` (closure `authcheck`) |
| Menu / catégories POS | `config/menu.php`, `MenuSeeder`, `PosCategoryController`, `ItemService`, statut actif **`Status::ACTIVE` (ex. 5)** pour `pos-category` |

### 4.3 Frontend (Vue)

| Surface | Emplacement indicatif |
|---------|------------------------|
| POS | `resources/js/components/admin/pos/*` (`PosComponent.vue`, `PaymentComponent.vue`, `ItemComponent.vue`) |
| KDS | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` |
| OSS | `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` |
| Auth / routes | `resources/js/store/modules/auth.js`, `resources/js/router/index.js`, `DefaultComponent.vue`, `appService.js` |

### 4.4 Auth, rôles, landing après login

- **`landing_url`** sur la table **`roles`** : redirige après login (ex. POS → `pos`, chef → `kitchen-display-system`). **Doit être renseigné en base**.
- **`POST /api/auth/authcheck`** : doit renvoyer cohérence **menu / permissions / defaultPermission** comme le login ; **fix** : réappliquer **`landing_url`** sur `defaultPermission` (comme `LoginController`) pour éviter qu’après F5 un « POS Operator » retombe sur le premier écran (ex. dashboard).
- **Limite connue** : `recursiveRouter` peut s’exécuter **une fois au boot** — permissions rafraîchies sans reload complet peuvent laisser **`meta.access`** obsolète.

### 4.5 Temps réel et notifications

- **Jobs** : si `QUEUE_CONNECTION=sync`, tout s’exécute **dans la requête HTTP** — risque de lenteur / timeout si notifications lourdes.
- **FCM** : sans clés projet valides, pas de push réel.
- **Pusher / Soketi** : si clés placeholder ou service arrêté, le **broadcast échoue** ; le **polling** reste le filet de sécurité.

### 4.6 Zones sensibles (doc officielle)

Voir **`docs/ARCHITECTURE.md`** section « Zones gelées » : gateways paiement héritées, certains modules analytics / delivery, etc. **Ne pas modifier** sans plan explicite.

---

## 5. Historique des corrections majeures (à ne pas régresser)

> Vérifier toujours dans Git / le fichier source que le correctif est toujours présent.

1. **Notifications hors transaction** : pour `myOrderStore` / `tableOrderStore` (et chemins analogues), dispatch notifications / jobs **après** `DB::transaction()` pour éviter des notifications « fantômes » si rollback.
2. **`posOrderStore`** : notifications KDS / événements **après** transaction ; **`OrderCreated::dispatch`** sur les flux concernés.
3. **`changeStatus` (admin)** : **`OrderStatusChanged::dispatch`** avec ancien et nouveau statut.
4. **KDS `changeStatus`** : dispatch **`OrderStatusChanged`** pour l’OSS (correctif « OSS ne voyait pas les changements depuis KDS »).
5. **`queue_number`** : cohérence **Order + FrontendOrder** + scopes branche.
6. **`OrderCoupon`** : création sur les chemins POS / table là où c’était manquant.
7. **Robustesse** : `max(0, …)` sur totaux ; timestamps sur `OrderItem::insert` en bulk où touché.
8. **`FrontendOrder`** : `$fillable` incluant **`queue_number`**, **`total_tax`** ; cast **`source`** aligné avec `Order`.
9. **`FrontendOrderService::myOrderStore`** : **`OrderCreated::dispatch($this->frontendOrder)`** après commit (note : l’event peut type-hinter `Order` — PHP accepte mais à homogénéiser si refactor).
10. **Loyalty** : routes sensibles protégées par Sanctum.
11. **ApiKey** : `config()` au lieu de `env()` nu dans le middleware.
12. **Borne** : **`openDrawer()`** sur le chemin cash.

---

## 6. Ce qui reste à faire (priorisé)

| Priorité | Sujet | Détail |
|----------|--------|--------|
| P0 | **Queue asynchrone** | `QUEUE_CONNECTION=database` ou `redis` + **worker** supervisé ; réduire `sync` en prod. |
| P0 | **Temps réel** | Soketi ou Pusher réel, `.env` complet, **Laravel Echo** branché partout où objectif &lt; 1 s. |
| P1 | **FCM** | Projet Firebase + clés serveur / client pour push fiables. |
| P1 | **Amend commande POS** | Spec métier + API + UI ; aujourd’hui **non livré** comme feature complète. |
| P2 | **POS + matériel** | Si la caisse reste navigateur pur : pas de TPE/tiroir natifs — prévoir **Electron** ou **agent local**. |
| P2 | **Tests automatisés / E2E** | S’appuyer sur `docs/TEST_PLAN.md`, `docs/MASSIVE_TEST_PLAN.md`, rapports `reports/antigravity/`. |
| P3 | **Tokens** | Envisager **expiration Sanctum** + refresh propre (impact UX mobile / kiosks). |
| P3 | **Refactor types events** | `OrderCreated` / payloads stricts `Order` vs `FrontendOrder` pour clarté et outillage IDE. |

---

## 7. Commandes et scripts utiles

```bash
# Backend / identité / menu (adapter aux artisan commands réellement présents sur la branche)
php artisan migrate --seed
php artisan db:seed --class=MenuSeeder    # attention effets de bord selon seeder
php artisan menu verify                   # si disponible

# Frontend Laravel (dossier racine app web, ex. testttt/)
npm install && npm run prod

# Borne Electron (dossier borne-windows/ si présent)
npm install && npm run dev   # ou npm run make selon le projet
```

---

## 8. Workflow multi-agents du dépôt (obligatoire si vous modifiez « important »)

1. QA (`reports/antigravity/`)  
2. Plan (`reports/planning/`)  
3. Implémentation ciblée (`reports/execution/`)  
4. Retest  

Voir **`AGENTS.md`**, **`workflows/task-routing.md`**, **`workflows/report-format.md`**.

---

## 9. Checklist « nouvelle session IDE »

- [ ] Lire **ce fichier** + **`docs/ARCHITECTURE.md`** + **`docs/ORDER_FLOW.md`**
- [ ] Vérifier **`.env`** : `APP_URL`, DB, `MIX_API_KEY`, `QUEUE_CONNECTION`, clés broadcast, FCM
- [ ] Vérifier **rôles** : `landing_url` pour POS / KDS / admin
- [ ] Vérifier **`DEMO`** et données **Le Cayenne**
- [ ] Si toucher aux commandes : relire **`OrderService`** / **`FrontendOrderService`** et risques **prix / statuts / idempotence**
- [ ] Si toucher authz : **`docs/AUTHZ_MATRIX.md`**

---

## 10. Glossaire rapide

| Terme | Signification |
|-------|----------------|
| **POS** | Point of sale — caisse web Vue |
| **KDS** | Kitchen Display System — écran cuisine |
| **OSS** | Order Status Screen — écran statut commande |
| **Borne / Kiosk** | Terminal de commande client — ici surtout **Electron Windows** |
| **FrontendOrder** | Modèle Eloquent commandes issues web/kiosk (table `orders`) |
| **Order** | Modèle Eloquent commandes caisse / admin classiques |
| **Amend** | Modifier une commande déjà validée (feature demandée, peu implémentée) |

---

*Document généré pour continuité entre sessions. Le code et Git font foi en cas d’écart avec ce texte.*
