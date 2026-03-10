# Matrice d'Autorisation (AUTHZ_MATRIX)

Ce document décrit les permissions, middlewares et limites imposées à chaque acteur interagissant avec l'API Restaurant/SaaS. Il sert de contrat de référence pour tout refactoring IAM.

| Acteur | Token | Routes autorisées | Routes interdites |
|---|---|---|---|
| **Admin System** | Sanctum + Rôle `Admin` | `/api/admin/*` | — |
| **Manager/Caissier** | Sanctum + Rôle `Manager` | `/api/admin/pos*`, `/api/admin/online-order*` | Modifier config globale, supprimer des succursales complètes. |
| **Borne Kiosk** | Sanctum (`kioskToken`) | `/api/frontend/*` (uniquement création) | **Totalement interdit** : `/api/admin/*`. Modifier infos compte. |
| **Chef (KDS)** | Sanctum + Rôle `Chef` | `/api/admin/kds-order/*` | Voir une autre succursale. Rejeter un encaissement POS. |
| **Client / App** | Sanctum (Normal) | `/api/frontend/order`, `/api/frontend/address` | Imposer un prix JSON. Marquer la commande comme `DELIVERED`. |
| **OSS Screen** | api-key uniquement | `/api/admin/oss-order` (Lecture) | Toute route POST, PUT, DELETE. Accès aux prix. |

## Invariants de Sécurité
- L'app doit avoir le fichier `storage/installed` pour démarrer.
- Le middleware `apiKey` filtre les requêtes publiques pour éviter le web scraping agressif.
- Le Token des bornes Kiosks (`KioskMachine`) possède des "abilities" restreintes (`['kiosk:order']`) au niveau du driver Sanctum qui bloque nativement les échappatoires vers l'Admin.
- Les validations JSON pour la prise de commande NE DOIVENT JAMAIS accepter les totaux envoyés par les clients ; le backend utilise sa propre **Source of Truth** (DB).
