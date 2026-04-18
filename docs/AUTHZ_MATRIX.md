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

## Permissions POS (Phase 9) — Spatie `can()` gates

Les permissions suivantes sont créées par `RolePermissionTableSeeder` et gardées par `OrderService`, `PosOrderRequest`, et les contrôleurs Fiscal. Chaque route vérifie `Auth::user()->can(<perm>)` ; l'absence d'une permission retourne un `HTTP 403`.

| Permission | Rôles attribués | Garde | Route(s) |
|---|---|---|---|
| `pos-apply-discount` | `Admin`, `Branch Manager` uniquement. `POS Operator` explicitement exclu. | `OrderService::applyDiscount()` + `PosOrderRequest::rules()` rejettent `discount > 0` si absent. | `POST /api/admin/pos-order/*` avec champ `discount`. |
| `pos-destroy-paid` | `Admin` uniquement. Jamais donné par défaut à un `Branch Manager`. | `OrderService::destroy()` rejette la suppression d'une commande `payment_status=PAID`. | `DELETE /api/admin/order/{id}`. |
| `pos-manage-fiscal` | `Admin`, `Branch Manager`. Refusé à `POS Operator`, `Chef`, `Waiter`. | Gates sur `/admin/fiscal/*` (ouverture, clôture, export archive). | `POST /api/admin/fiscal/z-report/{open,close}`, `GET /api/admin/fiscal/{z-report,x-report}`. |

### Rate-limits dédiés

- `POST /api/admin/fiscal/z-report/open` et `.../close` : `throttle:10,1` (10 req / min / utilisateur).
  Un utilisateur légitime ouvre au plus 1 Z/jour/branche ; le rate-limit bloque les retry-storms qui pourraient brûler de manière permanente des `sequence_no` monotoniques (chaque open alloue un numéro signé dans la chaîne HMAC, INSERT-only, irréversible).

Voir [`docs/FISCAL_SECRETS.md`](./FISCAL_SECRETS.md) pour la rotation des secrets HMAC qui signent `audit_logs` et `z_reports`.
