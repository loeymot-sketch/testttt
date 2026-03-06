# Cartographie des API (API_MAP)

Le projet expose de nombreuses routes pour les différents modules et `devices`.

## 1. Auth 
**Namespace** : `Auth`
- `POST /api/v1/auth/login` : Connexion Standard Admin/Manager.
- `POST /api/v1/auth/kiosk-login` : Connexion Kiosk (utilise `username`/`password` lié au `branch_id`).
- `POST /api/v1/auth/logout` : Révocation du Token Sanctum.

## 2. Frontend & Kiosk
**Namespace** : `Frontend`
- **Order** : `/api/v1/frontend/order` (GET list, POST store, change-status). Protégé `auth:sanctum`.
- **Référentiel** : `/api/v1/frontend/item`, `/item-category`, `/branch`, `/slider`, `/country-code`.
- **Pages & Textes** : `/api/v1/frontend/setting`, `/page`, `/language`, `/message`.
- **User Info** : `/api/v1/frontend/address`, `/subscriber`, `/delivery-boy`.

## 3. Back-Office Admin (Restaurant Boss)
**Namespace** : `Admin`
- **Dashboard Phase 6** : `/api/v1/admin/dashboard/realtime-report` (et `sla-alerts`, `channel-statistics`, `audit-trail`).
- **Opérations** : `/api/v1/admin/kiosk-machine`, `/online-order`, `/table-order`.
- **Configuration** : `/api/v1/admin/payment-gateway`, `/push-notification`.

## 4. Kitchen Display System (KDS)
**Middleware/Namespace** : `kitchen-display-system`
- `GET /api/v1/admin/kitchen-display-system` : Liste filtrée par branche `PREPARING`, `ACCEPT`.
- `POST /api/v1/admin/kitchen-display-system/{id}/change-status` : Met à jour la commande.

## 5. Order Status Screen (OSS)
**Namespace** : `Admin/OrderStatusScreen`
- Route fournissant les flux websockets pour l'affichage de l'attente client.
