# Matrice d'Autorisation (AUTHZ_MATRIX)

Ce document décrit les permissions, middlewares et limites imposées à chaque acteur interagissant avec l'API Restaurant/SaaS. Il sert de contrat de référence pour tout refactoring IAM.

| Acteur | Mode d'Auth | Routes autorisées | Actions autorisées | Actions interdites |
|---|---|---|---|---|
| **Admin System** | Sanctum (`auth:sanctum`) + `isAdmin` | `/api/admin/*` | CRUD complet The Boss, dashboard analytique global, config gateway. | — |
| **Manager/Caissier** | Sanctum + Rôle (`Spatie`) | `/api/admin/pos*`<br>`/api/admin/online-order*` | Créer des POS/Online orders, changer statut `PENDING`→`ACCEPT`, gérer clients. | Modifier configuration globale système, supprimer branches entières. |
| **Borne Kiosk (Machine)** | Sanctum KioskToken<br>Ability: `['kiosk:order']` | `/api/frontend/*` | Créer des commandes `PENDING`, lister les items du menu (`/item-category`). | Interdit d'accéder à `/api/admin/*`. Modifier infos de compte (pas d'UUID user réel associé). |
| **Chef Cuisine (KDS)** | Sanctum + Rôle | `/api/admin/kds-order/*` | Voir la liste des commandes payées (`ACCEPT`→`PREPARING`→`PREPARED`) de SA succursale exclusive. | Voir les données d'une autre succursale. Sauter des étapes (e.g., livrer un ticket non payé). |
| **Client Frontend / Mobile** | Sanctum (`auth:sanctum`) | `/api/frontend/order`<br>`/api/frontend/address` | Créer une commande, gérer profil client, gérer adresses persos. | Choisir le statut final de la commande, imposer un prix falsifié en JSON. Passer en cuisine. |
| **Visiteur Public** | `apiKey` middleware | `/api/frontend/item*`<br>`/api/frontend/setting` | Lire le catalogue, la config frontend de base, la liste des restaurants. | Toute action de type POST/PUT/DELETE. |

## Middlewares Critiques
- `installed` : L'app doit avoir le fichier `storage/installed`.
- `apiKey` : Empêche un curl externe non autorisé sur le front public via l'header `x-api-key`.
- `auth:sanctum` : Protège l'accès utilisateur actif.
- `VerifyKioskToken` (Implicite via les Token Abilities Laravel) : Barricade qui interdit l'échappatoire d'une borne vers le module comptabilité admin (Dashboard Boss).
