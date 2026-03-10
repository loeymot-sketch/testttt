# Cartographie des API (API_MAP)

Le projet expose de nombreuses routes pour les différents modules et `devices`. Ce document recense le niveau de risque et les contraintes d'accès associées.

## 1. Auth 
**Namespace** : `Auth`
**Risque** : CRITIQUE (Ne jamais modifier sans audit complet de sécurité)
- `POST /api/auth/login` : Connexion Standard. **Auth** : Guest. **Rôle** : Tout Admin/Manager.
- `POST /api/auth/kiosk-login` : Connexion Kiosk. **Auth** : Guest. **Rôle** : KioskMachine. (Génère un token limité).
- `POST /api/auth/logout` : Révocation. **Auth** : Sanctum.

## 2. Frontend & Kiosk
**Namespace** : `Frontend`
**Risque** : MOYEN (Impact direct sur l'encaissement et l'UX client)
- **Order** : `/api/frontend/order` (GET, POST). **Auth** : Sanctum. **Rôle** : Client, Kiosk. 
  - *Spécificité : Les prix envoyés par POST sont systématiquement ignorés au profit d'un recalcul serveur.*
- **Référentiel** : `/api/frontend/item`, `/item-category`, `/branch`. **Auth** : Guest (avec api-key). **Rôle** : Public.
- **Pages & Textes** : `/api/frontend/setting`, `/page`. **Auth** : Guest. **Rôle** : Public.

## 3. Back-Office Admin (Restaurant Boss)
**Namespace** : `Admin`
**Risque** : HAUT (Contrôle système et Configuration)
- **Dashboard** : `/api/admin/dashboard/*`. **Auth** : Sanctum. **Rôle** : Admin, Branch Manager.
- **Opérations** : `/api/admin/pos-order`, `/table-order`. **Auth** : Sanctum. **Rôle** : Caissier, Manager.
- **Configuration** : `/api/admin/payment-gateway`. **Auth** : Sanctum. **Rôle** : Admin Exclusivement.

## 4. Kitchen Display System (KDS)
**Namespace** : `Admin/KitchenDisplaySystem`
**Risque** : HAUT (Impact direct sur la production et la sortie des plats)
- `GET /api/admin/kds-order` : Liste filtrée `PREPARING`. **Auth** : Sanctum. **Rôle** : Chef Cuisine / KDS.
- `POST /api/admin/kds-order/change-status/{order}` : Met à jour la commande.

## 5. Order Status Screen (OSS)
**Namespace** : `Admin/OrderStatusScreen`
**Risque** : FAIBLE (Affichage passif uniquement)
- `GET /api/admin/oss-order` : Flux Websocket/AJAX de la file d'attente. **Auth** : api-key. **Rôle** : Écran public. *Interdiction stricte d'exposer des méthodes POST/PUT.*
