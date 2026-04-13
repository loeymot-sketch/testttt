# Architecture monolithe — vue structurée

## 1. Schéma mental (couches)

```
HTTP (api.php + middlewares)
    → Controllers (Admin/*, Frontend/*, Auth/*)
        → Services (logique métier, transactions)
            → Models + global scopes (ex. BranchScope)
            → Events (OrderCreated, OrderStatusChanged, ItemAvailabilityChanged)
                → Broadcast (Soketi) + Listeners (FCM, fidélité)
```

**Règle d’or** : le client (Vue, kiosk) envoie des **intentions** ; le serveur **recalcule prix, taxes, coupons, statuts**.

## 2. Surfaces utilisateur (toutes Vue 3 dans ce repo sauf mention)

| Surface | Rôle approximatif | Dossier indicatif |
|---------|-------------------|-------------------|
| Admin | CRUD, réglages, utilisateurs | `resources/js/components/admin/` |
| POS | Caisse web | `resources/js/components/admin/pos/` |
| KDS | Cuisine, changement statuts | `resources/js/components/admin/kitchenDisplaySystem/` |
| OSS | File d’attente client | `resources/js/components/admin/orderStatusScreen/` |
| Kiosk | Borne client | `resources/js/components/frontend/kiosk/` |
| Auth / reset password | SPA | `resources/js/components/frontend/auth/` |

## 3. Backend : services « cœur »

| Service | Responsabilité |
|---------|----------------|
| `OrderService` | Commandes POS, tables, coupons, nombreux changements de statut |
| `FrontendOrderService` | Kiosk / web : création, idempotence, paiement différé, annulation client |
| `KitchenDisplaySystemOrderService` | Flux cuisine + broadcast statut |
| `CouponService` | Validation coupons, listes sécurisées |
| `ItemService` | Articles ; émet `ItemAvailabilityChanged` pour menu temps réel |

Liste complète : `app/Services/` (~87 fichiers). Ne pas tout charger en tête : utiliser [`04_FICHIERS_PIVOTS_PAR_FLUX.md`](./04_FICHIERS_PIVOTS_PAR_FLUX.md).

## 4. Données & multi-branche

- **`BranchScope`** : filtre les requêtes selon le contexte branche / admin.
- **`DefaultAccess` / trait** : résolution branche utilisateur ; comportement spécifique en tests — voir code et `BranchScopeTest`.

## 5. Authentification

- **Sanctum** : tokens API ; abilities dont **`kiosk:order`** pour machines borne.
- **Spatie** : rôles / permissions sur routes admin.
- **Clé API** : header `x-api-key` aligné sur `config('app.api_key')` (pas `env()` nu dans le middleware).

Détail : [`../AUTHZ_MATRIX.md`](../AUTHZ_MATRIX.md).

## 6. Connectivité externe (résumé)

| Système | Usage |
|---------|--------|
| MySQL | Source de vérité |
| Soketi / Pusher | WebSockets privés `branch.{id}` |
| Firebase FCM | Push (topics) via jobs |
| SMTP / SMS gateways | Notifications commande (événements legacy + builders) |

## 7. Documentation détaillée existante

- [`../ARCHITECTURE.md`](../ARCHITECTURE.md)  
- [`../CORE_MODULES.md`](../CORE_MODULES.md) (si présent)  
- [`../DATABASE_SCHEMA_CORE.md`](../DATABASE_SCHEMA_CORE.md) (si présent)
