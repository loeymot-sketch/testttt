# Cartographie des API (API_MAP)

Le projet expose de nombreuses routes pour les différents modules et `devices`.

## 1. Auth 
**Namespace** : `Auth`
- `POST /api/auth/login` : Connexion Standard Admin/Manager.
- `POST /api/auth/kiosk-login` : Connexion Kiosk (utilise `username`/`password` lié au `branch_id`).
- `POST /api/auth/logout` : Révocation du Token Sanctum.

## 2. Frontend & Kiosk
**Namespace** : `Frontend`
- **Order** : `/api/frontend/order` (GET list, POST store, change-status). Protégé `auth:sanctum`.
- **Référentiel** : `/api/frontend/item`, `/item-category`, `/branch`, `/slider`, `/country-code`.
- **Pages & Textes** : `/api/frontend/setting`, `/page`, `/language`, `/message`.
- **User Info** : `/api/frontend/address`, `/subscriber`, `/delivery-boy`.

## 3. Back-Office Admin (Restaurant Boss)
**Namespace** : `Admin`
- **Dashboard Phase 6** : `/api/admin/dashboard/realtime-report` (et `sla-alerts`, `channel-statistics`, `audit-trail`).
- **Opérations** : `/api/admin/kiosk-machine`, `/online-order`, `/table-order`, `/pos-order`.
- **Configuration** : `/api/admin/payment-gateway`, `/push-notification`.

## 4. Kitchen Display System (KDS)
**Middleware/Namespace** : `kitchen-display-system`
- `GET /api/admin/kds-order` : Liste filtrée par branche `PREPARING`, `ACCEPT`.
- `POST /api/admin/kds-order/change-status/{order}` : Met à jour la commande.

## 5. Order Status Screen (OSS)
**Namespace** : `Admin/OrderStatusScreen`
- Route `/api/admin/oss-order` fournissant les flux websockets pour l'affichage de l'attente client.
