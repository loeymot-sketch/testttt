# AUDIT TOTAL — FoodKing / Le Cayenne
**Date:** 2026-03-21  
**Auteur:** Claude (rôle : architecture, analyse, planification)  
**Périmètre:** Authentification → Structure produits → Parcours commande → Synchronisation KDS/POS  

---

## 1. ÉTAT RÉEL DE LA BASE DE DONNÉES

| Entité | Nombre | Statut |
|--------|--------|--------|
| Catégories | 13 | ✅ Toutes status=5 (ACTIVE) |
| Items | 63 | ✅ Tous status=5 (ACTIVE) |
| Variations | 772 | ✅ |
| Extras | 482 | ✅ |
| Addons | 156 | ✅ |

**Comptes utilisateurs confirmés :**
- Admin : `admin@example.com`
- POS Operator : `posoperator@example.com`
- Chef KDS : à vérifier

---

## 2. AUTHENTIFICATION — FLUX COMPLET

### 2.1 Flow de login (backend)
- `POST /api/auth/login` → throttle 5 req/min ✅
- Vérifie `status=ACTIVE` avant de créer le token ✅
- Crée un token Sanctum (`createToken`) ✅
- Retourne : token, branch_id, user, menu, permission, defaultPermission, defaultMenu ✅
- `DefaultAccess::storeOrUpdate()` → stocke le contexte branch de l'utilisateur ✅
- Si `roles[0]->landing_url` défini → redirige le rôle au bon écran post-login ✅

### 2.2 Flow frontend
- Token persisté dans `localStorage` via `vuex-persistedstate` ✅ (mais risque XSS)
- Axios injecte `Authorization: Bearer <token>` et `x-api-key` sur chaque requête ✅
- `router.beforeEach()` : si non connecté → `/login` ; si accès refusé → page exception ✅
- `recursiveRouter()` marque les routes `meta.access` selon les permissions (appelé une seule fois au boot)

### 2.3 Rôles et permissions confirmés
| ID | Rôle | Accès après login |
|----|------|-------------------|
| 1 | Admin | Dashboard complet |
| 5 | Chef | KDS + OSS seulement |
| 6 | Branch Manager | Dashboard + gestion |
| 7 | POS Operator | POS seulement |

---

## 3. STRUCTURE DES PRODUITS

### 3.1 Catégories existantes (13 au total)
| Sort | Nom | Wizard Template |
|------|-----|----------------|
| 1 | Nos Tacos | tacos (wizard multi-étapes) |
| 2 | Nos Sandwichs | sandwich |
| 3 | Nos Burgers | burger |
| 4 | Nos Assiettes | assiette |
| 5 | Ojja | simple |
| 6 | Omelettes | omelette |
| 7 | Nos Salades | salade |
| 8 | Chicken & Tenders | snacking |
| 9 | Nos Menus Enfants | simple |
| 10 | Frites & Accompagnements | simple |
| 11 | Nos Desserts | simple |
| 12 | Nos Boissons | simple |
| 13 | **Suppléments** ← NOUVEAU | simple |

### 3.2 Catégorie Suppléments créée aujourd'hui
Items créés directement en DB (commandables standalone au POS) :
- Sauce supplémentaire — 0,50 €
- Fromage supplémentaire — 1,00 €
- Jambon de dinde — 1,00 €
- Boursin — 1,00 €
- Fromage à raclette — 1,00 €
- Œuf — 1,00 €
- Galette pommes de terre — 1,00 €
- Salade verte — 2,00 €

### 3.3 Pourquoi les produits n'apparaissaient pas au POS
**Cause principale : le serveur PHP n'était pas démarré.**  
Port 8000 libre = aucune réponse API = Vuex reste vide = POS affiche "no data available".  
Les données existaient en base, les requêtes fonctionnaient — le problème était l'infrastructure locale.

**Fix appliqué :** `php artisan serve --host=127.0.0.1 --port=8000` lancé.

**Bug secondaire corrigé :** `sort` absent de `ItemCategory.$fillable` — l'ordre des catégories ne persistait pas au re-seed.

---

## 4. PARCOURS COMMANDE — AUDIT COMPLET

### 4.1 Flux POS → KDS (normal)
```
POS (caissier) → POST /api/admin/pos → OrderService::posOrderStore()
    → DB::transaction() [atomique] ✅
    → Pricing recalculé depuis DB (client total ignoré) ✅
    → Order status = ACCEPT (skip PENDING) ✅
    → order_items::insert() en masse
    → Queue number avec lockForUpdate() ✅
    → COMMIT transaction
    → SendOrderGotPush::dispatch() APRÈS commit ✅
    → FCM → devices Admin/Chef/Manager
    → KDS reçoit notification → re-fetche liste
    → Fallback polling 30 secondes ✅
```

### 4.2 Synchronisation statuts
- KDS Chef : ACCEPT → PREPARING → PREPARED
- POS/Admin : toutes transitions (avec règle ValidStatusTransition)
- Après changement statut KDS : `CustomEvent('realtime-order-update')` propagé ✅
- OSS (Order Status Screen) : polling 30 secondes ✅

---

## 5. BUGS CRITIQUES IDENTIFIÉS

### 🔴 CRITIQUE

**BUG-C1 : FCM dispatché DANS la transaction pour les commandes web/table**
- **Fichier :** `app/Services/OrderService.php` lignes 438-444, 877-879
- Les commandes web (`myOrderStore`) et table (`tableOrderStore`) envoient le push FCM avant le COMMIT. En cas de rollback, le KDS reçoit une commande fantôme inexistante en DB.
- Le POS (`posOrderStore`) est correct : FCM après commit.
- **Correction :** Déplacer `SendOrderGotPush::dispatch()` après la fermeture du bloc `DB::transaction()`.

**BUG-C2 : Aucune validation de coupon (expiry, usage, statut)**
- **Fichier :** `app/Services/OrderService.php` lignes 623, 845
- `Coupon::find($id)` vérifie seulement l'existence. Pas de contrôle : date d'expiration, nombre max d'utilisations, statut actif, restriction par branche.
- Un coupon expiré ou à usage unique peut être appliqué indéfiniment.

**BUG-C3 : `OrderCoupon` jamais créé pour commandes POS et table**
- **Fichier :** `app/Services/OrderService.php` (posOrderStore et tableOrderStore)
- La remise est appliquée (`order.discount` mis à jour), mais aucun enregistrement `OrderCoupon` n'est créé. Tracking des usages coupon = impossible pour ces commandes.

### 🟠 HAUTE

**BUG-H1 : `total` sans garde null/négatif dans posOrderStore et tableOrderStore**
- **Fichier :** `app/Services/OrderService.php` lignes 646, 868
- `$order->delivery_charge` peut être null (commande à emporter). PHP 8.1 génère un avertissement de dépréciation. Un grand coupon peut produire un total négatif.
- `myOrderStore` a le garde `max(0, ... ?? 0)` — à répliquer.

**BUG-H2 : BranchScope fausse la requête lockForUpdate des numéros de queue pour Admin**
- **Fichier :** `app/Services/OrderService.php` lignes 602-614
- Pour Admin (branch_id=0), la scope globale `BranchScope` transforme la requête en `WHERE branch_id=0 AND branch_id=X` → 0 résultats → toujours génère `A001` → collisions.

**BUG-H3 : Queue numbers dupliqués entre POS et kiosque**
- **Fichier :** `app/Services/FrontendOrderService.php` lignes 227-232
- Les commandes kiosque lisent le max de `Order` (POS), pas de `FrontendOrder`. Résultat : POS et kiosque peuvent générer le même `A001` le même jour.

### 🟡 MOYENNE

**BUG-M1 : FCM synchrone bloque la réponse HTTP POS**
- Tous les listeners de notification n'implémentent pas `ShouldQueue`. Avec 5 devices staff, chaque création d'ordre POS attend 5 appels Guzzle en série → latence 500ms-2s.
- `QUEUE_CONNECTION=sync` en `.env` aggrave cela.
- **Fix :** `QUEUE_CONNECTION=database` + `php artisan queue:work`.

**BUG-M2 : Client "de passage" avec fallback dangereux**
- **Fichier :** `resources/js/components/admin/pos/PosComponent.vue` ligne 664
- Si `walkingcustomer@example.com` absent → fallback sur `res.data.data[0]` (premier client). Une commande POS pourrait être assignée à un vrai client.

**BUG-M3 : `order_items.created_at` / `updated_at` sont NULL**
- `OrderItem::insert()` ne renseigne pas les timestamps automatiquement. Tout reporting sur les items par date est cassé.

**BUG-M4 : Firebase init retardée de 5 secondes**
- **Fichier :** `resources/js/components/layouts/backend/BackendNavbarComponent.vue` ligne 245
- Fenêtre de 5 secondes au chargement pendant laquelle les push FCM ne sont pas écoutés.

### 🔵 SÉCURITÉ

**SEC-1 : Routes loyalty totalement non authentifiées**
- `POST /api/frontend/loyalty/add-points` et `redeem` accessibles sans token. N'importe qui avec l'API key peut ajouter des points à n'importe quel compte.

**SEC-2 : Token Bearer dans localStorage (risque XSS)**
- En cas de XSS, le token admin est extractible. Mitigation : `HttpOnly` cookie + SameSite=Strict.

**SEC-3 : `/api/refresh-token` sans auth ni throttle**
- Un token volé peut générer un nouveau token valide indéfiniment.

**SEC-4 : Tokens Sanctum sans expiration**
- `config/sanctum.php` : `'expiration' => null`. Configurer à 43200 (30 jours) minimum.

**SEC-5 : `env()` dans middleware (casse après config:cache)**
- `app/Http/Middleware/ApiKeyMiddleware.php` ligne 20 : `env('MIX_API_KEY')` retourne null après `php artisan config:cache`. Utiliser `config('app.api_key')`.

---

## 6. GAPS ARCHITECTURE

| Documenté | Réalité |
|-----------|---------|
| Pusher/WebSockets pour sync temps réel | PUSHER_APP_ID=app-id (placeholder) — non configuré |
| QUEUE_CONNECTION async (jobs) | sync — bloque HTTP |
| Permission middleware sur routes admin | Aucun à niveau des routes, seulement dans constructeurs controllers |
| `action_logs` pour audit trail | Créé pour changements statuts POS, mais absent pour self-cancel client |
| Module Loyalty | Aucune doc dans docs/ |

---

## 7. FIXES APPLIQUÉS AUJOURD'HUI

| # | Fix | Fichier | Statut |
|---|-----|---------|--------|
| F1 | `sort` ajouté à `ItemCategory.$fillable` | `app/Models/ItemCategory.php` | ✅ Appliqué |
| F2 | Catégorie Suppléments créée (config + DB) | `config/menu.php` + DB direct | ✅ Appliqué |
| F3 | Serveur PHP démarré sur port 8000 | Runtime | ✅ Appliqué |

---

## 8. PROCHAINES ÉTAPES RECOMMANDÉES (Priorité)

### Phase D — Bugs critiques order flow (Claude planifie, Kimi implémente)
1. **D1 — BUG-C1** : Déplacer `SendOrderGotPush::dispatch()` hors transaction dans `myOrderStore` et `tableOrderStore`
2. **D2 — BUG-C3** : Créer `OrderCoupon` record dans `posOrderStore` et `tableOrderStore`  
3. **D3 — BUG-H1** : Ajouter `max(0, ... ?? 0)` dans `posOrderStore` et `tableOrderStore`
4. **D4 — BUG-H2** : Ajouter `->withoutGlobalScope(BranchScope::class)` dans la requête queue number

### Phase E — Infrastructure (DevOps)
1. `QUEUE_CONNECTION=database` dans `.env`
2. `php artisan queue:table && php artisan migrate`
3. Lancer `php artisan queue:work` en daemon
4. Sanctum expiration = 43200 dans `config/sanctum.php`
5. Fix `env()` → `config()` dans `ApiKeyMiddleware`

### Phase F — Sécurité loyalty
1. Ajouter `auth:sanctum` aux routes loyalty
2. Atomiser les opérations points avec `increment()`/`decrement()`

---

## 9. TEST DE VALIDATION RECOMMANDÉ

1. **Login Admin** → `http://localhost:8000` → Doit rediriger vers `/admin/dashboard`
2. **Login POS Operator** → Doit rediriger vers `/admin/pos`
3. **Login Chef** → Doit rediriger vers `/admin/kitchen-display-system`
4. **POS → catégories** → Doit afficher 13 catégories dont "Suppléments"
5. **POS → Suppléments** → Doit afficher 8 items (sauce, fromage, etc.)
6. **Créer commande POS** → Doit apparaître dans KDS en < 30 secondes
7. **KDS → changer statut** → Doit se refléter dans OSS

---

*Rapport généré par Claude — rôle : audit architecture. Exécution des correctifs Phases D/E/F → Kimi après validation humaine.*
