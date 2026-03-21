# Audit Architecture, Logique Métier & Infrastructure — FoodKing SaaS

**Date :** 2026-03-10  
**Type :** Audit Claude (raisonnement, analyse de risque, pas d'implémentation)  
**Portée :** Backend Laravel, services métier, auth, order flow, BDD, frontend POS  
**Méthodologie :** Lecture directe du code source (OrderService, FrontendOrderService, KDSService, modèles, règles, routes, migrations, tests)

---

## 1. Vue d'ensemble de l'architecture

### Stack validée
| Couche | Technologie | Version |
|--------|-------------|---------|
| Backend | Laravel | 9 / PHP 8.1+ |
| Auth | Laravel Sanctum | Abilities kiosk:order |
| Permissions | Spatie Permission | Manager / Chef / Admin |
| Temps réel | Pusher / WebSockets | KDS, OSS, POS |
| Notifications push | Firebase FCM | Jobs asynchrones |
| BDD | MySQL 8 | InnoDB (lockForUpdate) |
| Frontend Admin/POS | Vue 3 + Vuex | Build via Laravel Mix |
| POS Wizard | Vanilla JS (pos-wizard.js) | 4 881 lignes |

### Flux de données validé
```
Kiosk/App/Web → FrontendOrderService → orders (table)
POS Caissier   → OrderService.posOrderStore → orders (table)
Table Order    → OrderService.tableOrderStore → orders (table)
```
**Important :** `FrontendOrder` et `Order` sont deux modèles Eloquent distincts mais pointent sur la **même table** `orders`. Cette séparation est logique (comportements différents) mais peut créer de la confusion lors de la maintenance.

---

## 2. Points solides — Ce qui fonctionne bien

### 2.1 Intégrité des prix (SSOT) ✅
Les trois chemins de création de commande (`myOrderStore`, `posOrderStore`, `tableOrderStore`) :
- Récupèrent les prix **toujours depuis la BDD** (`Item::whereIn('id', ...)->get()`)
- Rejettent toute commande avec un `item_id` inexistant (throw 422)
- Bulk-loadent les variations et extras avant la boucle (prévention N+1)
- Recalculent `total = realSubtotal + totalTax + delivery_charge - discount`
- Le payload client ne peut pas imposer un prix

### 2.2 Sécurité coupon ✅
`FrontendOrderService` et `posOrderStore` recalculent le discount depuis la BDD :
- `DiscountType::PERCENTAGE` → plafonné par `maximum_discount`
- `DiscountType::FIXED` → valeur DB
- Discount `> realSubtotal` → ignoré (pas de total négatif)

### 2.3 Transitions de statut ✅
`ValidStatusTransition` (règle isolée, testée) est appliquée dans :
- `OrderService::changeStatus`
- `KitchenDisplaySystemOrderService::changeStatus`
- `FrontendOrderService::changeStatus`

Matrice : `PENDING → ACCEPT → PREPARING → PREPARED → DELIVERED`. États terminaux (CANCELED, REJECTED, RETURNED) bloqués sauf pour Admin.

### 2.4 Transactions BDD et rollback ✅
Tous les stores sont enveloppés dans `DB::transaction()` avec `DB::rollBack()` explicite dans le catch. Le `lockForUpdate()` sur `queue_number` protège les race conditions en heure de pointe.

### 2.5 Auth Kiosk isolée ✅
`KioskMachineLoginController` crée les tokens Sanctum avec `['kiosk:order']`. Cela bloque nativement les routes `/api/admin/*` pour les bornes.

### 2.6 Isolation branche dans KDS ✅
`KitchenDisplaySystemOrderService` filtre par `auth()->user()->branch_id`. Admin (branch_id = 0) voit toutes les branches.

### 2.7 ActionLogs ✅
Les changements de statut et créations de commandes sont tracés dans `action_logs` avec `user_id`, `action`, `resource`, `details`.

### 2.8 Couverture de tests (Feature) ✅
Suite complète : `KioskAuthTest`, `KioskSecurityTest`, `PricingIntegrityTest`, `CouponSecurityTest`, `KDSFlowTest`, `BranchIsolationTest`, `OSSReadOnlyTest`, `OrderFlowTest`, `OrderStateTransitionTest`, `PosDiscountTest`, etc.

---

## 3. Problèmes identifiés — Priorisés

### 🔴 P0 — Critiques (sécurité ou corruption de données)

#### P0-1 : `OrderCoupon` enregistre `$request->discount` au lieu du discount recalculé
**Fichier :** `app/Services/OrderService.php:430`  
**Code :**
```php
// Dans myOrderStore — PROBLÈME
OrderCoupon::create([
    'order_id' => $this->order->id,
    'coupon_id' => $request->coupon_id,
    'user_id' => Auth::user()->id,
    'discount' => $request->discount  // ← valeur CLIENT, pas recalculée
]);
```
**Impact :** La table `order_coupons` stocke un discount fourni par le client, pas le discount vérifié par le backend. Le total de la commande est correct (calculé côté serveur), mais l'enregistrement d'audit dans `order_coupons` peut être faussé. Un client malveillant pourrait faire apparaître un rabais fictif dans les logs de la commande.  
**Correction :** Remplacer `$request->discount` par `$calculatedDiscount` (déjà calculé juste au-dessus dans FrontendOrderService, mais absent dans `myOrderStore` de OrderService — la version FrontendOrderService est correcte depuis la fix Phase 7, mais pas `myOrderStore`).

#### P0-2 : `changeStatus` (admin path) sans vérification `branch_id`
**Fichier :** `app/Services/OrderService.php:1022–1044`  
**Code :** Le `else` branch (chemin admin, non-auth-user) de `changeStatus` applique la transition sans vérifier que `auth()->user()->branch_id === $order->branch_id`.  
**Impact :** Un Manager de la branche A authentifié sur une session valide peut (via un appel API direct) changer le statut d'une commande de la branche B. La règle est respectée dans le KDS (service séparé) mais pas dans le service général.  
**Correction :** Ajouter avant le `$order->status = $request->status` :
```php
$authBranch = auth()->user()->branch_id ?? null;
if ($authBranch && $authBranch != $order->branch_id && !auth()->user()->hasRole('Admin')) {
    throw new Exception('Accès refusé : branche incorrecte.', 403);
}
```

#### P0-3 : Fichiers PHP de maintenance dans le répertoire potentiellement accessible
**Fichiers :** `EXECUTE_MENU_FIX.php` et `RESET_MENU_FRENCH.php` présents à la racine du dépôt.  
**Impact :** Si la racine du projet est le `public_html` d'un serveur web ou si `.htaccess` ne les filtre pas correctement, ces fichiers sont accessibles publiquement et peuvent exécuter des opérations BDD. Risque de suppression ou modification des données menu en production.  
**Correction :** Déplacer dans `scripts/` ou supprimer si obsolètes. Vérifier que seul `public/` est exposé.

---

### 🟠 P1 — Importants (logique, maintenabilité, risque futur)

#### P1-1 : `Tax::get()` appelé deux fois dans le même scope
**Fichiers :** `OrderService.php:277–278` et `OrderService.php:496–497`  
**Code :**
```php
$dbTaxes = Tax::get()->pluck('tax_rate', 'id');  // requête DB
$taxes = AppLibrary::pluck(Tax::get(), 'obj', 'id'); // 2ème requête DB identique
```
**Impact :** Double requête à chaque création de commande. `$dbTaxes` n'est d'ailleurs jamais utilisé dans ces méthodes (variables mortes).  
**Correction :** Une seule `Tax::get()` stockée en `$taxCollection`, puis `$dbTaxes = $taxCollection->pluck(...)` et `$taxes = AppLibrary::pluck($taxCollection, ...)`. Supprimer la variable `$dbTaxes` inutilisée.

#### P1-2 : Deux modèles Eloquent sur la même table (`Order` et `FrontendOrder`)
**Modèles :** `app/Models/Order.php` et `app/Models/FrontendOrder.php` — les deux ont `protected $table = "orders"`.  
**Impact :** Risque de confusion lors d'une maintenance ou refactoring :
- Requêtes `OrderItem::insert` avec `'order_id' => $this->frontendOrder->id` — les items ont le bon `order_id` mais le développeur doit savoir que les deux modèles partagent la table.
- Les `with('orderItems')` doivent être testés sur les deux modèles.
- La queue_number est recherchée sur `\App\Models\Order::` depuis `FrontendOrderService` — cohérent (même table) mais contre-intuitif.

**Recommandation :** Documenter explicitement ce pattern dans `docs/DATABASE_SCHEMA_CORE.md` avec un commentaire dans les deux modèles.

#### P1-3 : `posOrderStore` — discount manuel sans plafond configuré
**Fichier :** `app/Services/OrderService.php:621–627`  
**Code :**
```php
} elseif ($request->discount > 0) {
    $manualDiscount = (float) $request->discount;
    if ($manualDiscount <= $realSubtotal) {
        $calculatedDiscount = $manualDiscount;
    }
}
```
**Impact :** Un caissier (Manager) peut appliquer n'importe quelle remise jusqu'à 100% du subtotal, sans limite configurée ni validation côté BDD. Si un compte Manager est compromis, toute commande peut être soldée à 0€.  
**Recommandation :** Ajouter un plafond de remise manuelle dans les settings (`order_setup_max_manual_discount_percent`). Logger systématiquement dans `action_logs` chaque application de remise manuelle avec le montant et le `user_id`.

#### P1-4 : `OrderService` trop grande (God Class — 1243 lignes)
**Impact :** Un seul service gère : listing/filtrage, création web, création POS, création table, changement de statut, changement de statut paiement, token, sélection livreur, cashback. Toute modification risque un effet de bord. Le service est difficile à tester unitairement et à maintenir.  
**Recommandation (long terme) :** Extraire `OrderStatusService`, `OrderQueryService`, `DeliveryService` de `OrderService`. Ne pas faire en sprint court.

#### P1-5 : `changePaymentStatus` (admin path) sans vérification `branch_id`
**Fichier :** `app/Services/OrderService.php:1075–1087`  
**Impact :** Même problème que P0-2 : un Manager d'une branche peut modifier le statut paiement d'une commande d'une autre branche.  
**Correction :** Même guard que P0-2.

---

### 🟡 P2 — Améliorations (observabilité, robustesse)

#### P2-1 : Frontend POS Wizard — prix hardcodés
**Fichier :** `public/js/pos-wizard.js`  
**Code :**
```js
var SAUCE_EXTRA_PRICE = 0.50;  // hardcodé
// Viande extra : 2.50€ hardcodé
// Grande Portion : +1.00€ hardcodé
// Cheddar : +1.00€ hardcodé
```
**Impact :** Si ces prix changent en BDD, le wizard affiche les mauvais montants au caissier. Le total final est recalculé côté serveur (donc le ticket est juste), mais le caissier voit une estimation incorrecte pendant la saisie.  
**Recommandation :** Injecter ces prix dans le HTML via Blade (une variable JS globale chargée depuis les settings ou la BDD) plutôt que les hardcoder.

#### P2-2 : `pos-wizard.js` — 4 881 lignes, non bundlé via Laravel Mix
**Impact :** Le fichier n'est pas dans `resources/js` et n'est pas compilé/minifié via le pipeline Mix. Il est chargé directement depuis `public/js/`. Pas de tree-shaking, pas de source maps en dev, pas de cache-busting automatique (sauf `?v=9-{{ time() }}` en Blade).  
**Recommandation :** À terme, intégrer dans le bundle Vue ou en module ES6 versionné.

#### P2-3 : Migrations de données en production
**Fichiers :** `2026_03_11_000000_reset_menu_french.php`, `2026_03_11_999999_emergency_purge_english_menu.php`, `2026_03_16_000002_update_crudites_to_atomic_sprint23.php`  
**Impact :** Des migrations qui modifient des données (et non juste le schéma) sont irréversibles et dangereuses en production. Si `php artisan migrate` est relancé sur un environnement frais ou de staging, elles s'exécutent et peuvent supprimer ou remplacer le menu.  
**Recommandation :** Utiliser des Seeders pour les données, pas des migrations. Ou au moins protéger avec un `if (app()->environment('production'))` check et un log d'avertissement.

#### P2-4 : Tests de `changeStatus` branch_id isolation manquants
**Impact :** Le cas P0-2 n'est pas couvert dans la suite de tests actuels. `BranchIsolationTest` couvre le KDS mais pas le changement de statut via le service général.

#### P2-5 : Rate limiting non documenté pour le POS/Admin
**Fichier :** `docs/SECURITY_NOTES.md` mentionne `200/minute` pour le Frontend Kiosk.  
**Impact :** Aucune documentation sur le rate limiting pour les routes Admin/POS. Si non configuré, un compte Manager compromis peut hammerer l'API sans limite.

---

## 4. Matrice de risques consolidée

| ID | Zone | Sévérité | Probabilité | Impact | Priorité |
|----|------|----------|-------------|--------|----------|
| P0-1 | OrderCoupon avec discount client | Sécurité audit | Réelle (web/app) | Log coupon faux | **P0** |
| P0-2 | changeStatus sans branch check | Sécurité authz | Faible mais réelle | Modif commande branche B | **P0** |
| P0-3 | PHP scripts en racine | Sécurité infra | Dépend du serveur | Exécution BDD publique | **P0** |
| P1-1 | Tax::get() double appel | Performance | Systématique | +1 requête par commande | P1 |
| P1-2 | Dual model same table | Maintenabilité | Confusion future | Bugs silencieux | P1 |
| P1-3 | Discount POS sans plafond | Contrôle interne | Compte compromis | 100% remise | P1 |
| P1-4 | OrderService god class | Maintenabilité | Refactor risqué | Effet de bord | P1 |
| P1-5 | changePaymentStatus sans branch | Sécurité authz | Faible | Modif paiement branche B | P1 |
| P2-1 | Prix hardcodés wizard | Cohérence UX | Si prix change DB | Affichage incorrect caissier | P2 |
| P2-2 | pos-wizard.js hors bundle | Technique | Maintenance | Pas de versionning auto | P2 |
| P2-3 | Migrations de données | Infra | Si migrate sur staging | Perte menu | P2 |
| P2-4 | Test branch isolation manquant | Couverture | Toujours | Régression non détectée | P2 |
| P2-5 | Rate limit POS non documenté | Sécurité | Compte compromis | Brute force non limité | P2 |

---

## 5. Ce qui est confirmé SOLIDE (ne pas toucher sans plan)

| Module | Statut | Remarque |
|--------|--------|----------|
| Recalcul prix SSOT (3 chemins) | ✅ Solide | Testé, bulk-loaded, DB-verified |
| Coupon engine (FrontendOrderService) | ✅ Solide | Recalcul correct depuis DB |
| Transitions de statut (ValidStatusTransition) | ✅ Solide | Règle isolée, appliquée partout |
| DB transactions + rollback | ✅ Solide | Toutes les stores |
| Queue number lockForUpdate | ✅ Solide | 3 chemins, InnoDB-safe |
| Kiosk token abilities | ✅ Solide | ['kiosk:order'] restrictif |
| KDS branch isolation | ✅ Solide | Admin bypass documenté |
| ActionLog trail | ✅ Solide | status + création loggés |
| Suite de tests Feature | ✅ Solide | ~30 fichiers de test |

---

## 6. Actions recommandées (plan séquencé)

### Sprint immédiat (P0)

1. **Corriger `OrderCoupon` dans `myOrderStore`** — remplacer `$request->discount` par `$calculatedDiscount` (ajouter le recalcul coupon dans `myOrderStore` comme fait dans `FrontendOrderService` et `posOrderStore`).
2. **Ajouter check `branch_id` dans `changeStatus` et `changePaymentStatus`** — guard avant toute modification de statut dans le chemin non-auth-user.
3. **Déplacer ou supprimer `EXECUTE_MENU_FIX.php` et `RESET_MENU_FRENCH.php`** — hors du webroot ou dans `scripts/` non accessible.

### Sprint court (P1)

4. **Éliminer la double requête `Tax::get()`** dans `myOrderStore` et `posOrderStore`.
5. **Documenter le pattern dual-model** dans `docs/DATABASE_SCHEMA_CORE.md`.
6. **Ajouter plafond de discount manuel POS** dans les settings + log systématique.
7. **Ajouter test `BranchIsolationTest` pour `changeStatus` admin path**.

### Moyen terme (P2)

8. **Injecter les prix wizard via Blade** (variable JS) au lieu de les hardcoder.
9. **Protéger les migrations de données** (env check ou migrer vers Seeders).
10. **Documenter rate limiting Admin/POS** dans `SECURITY_NOTES.md`.

---

## 7. Résumé exécutif

Le backend FoodKing SaaS présente une **base solide et bien construite** sur les points critiques :
- L'intégrité des prix est correctement protégée sur les 3 chemins de commande.
- Les transactions BDD sont bien gérées.
- L'auth kiosk est proprement isolée.
- La matrice de transition de statut est cohérente et testée.

Les risques principaux sont :
- **P0-1** (mineur mais réel) : un log de coupon incorrect dans `order_coupons` pour les commandes web/app.
- **P0-2 / P1-5** (authz) : absence de vérification `branch_id` dans `changeStatus` et `changePaymentStatus` pour le path admin général.
- **P0-3** (infra) : fichiers de maintenance PHP dans le répertoire racine.
- En termes de maintenabilité : `OrderService` est une God Class de 1243 lignes qui nécessitera une extraction à moyen terme.

Le front-end POS (`pos-wizard.js` — 4881 lignes) est fonctionnel mais contient des prix hardcodés et n'est pas intégré au pipeline de build. C'est un risque de cohérence, pas de sécurité (le serveur recalcule toujours).

---

*Audit conforme au workflow multi-agents. Exécution des corrections P0 déléguée selon `workflows/task-routing.md`. Document archivé dans `reports/planning/`.*
