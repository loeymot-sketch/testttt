# Matrice d'Autorisation (AUTHZ_MATRIX)

Ce document décrit les permissions, middlewares et limites imposées à chaque acteur interagissant avec l'API Restaurant/SaaS. Il sert de contrat de référence pour tout refactoring IAM.

> **Relevé le 2026-10-01 contre le code et la base d'exploitation.** Trois affirmations de la
> version précédente étaient fausses et sont corrigées ci-dessous : un rôle « Manager » qui
> n'existe pas, une permission « pos-apply-discount » qui n'existe pas, et la surface OSS
> publique confondue avec la surface admin. Une matrice d'autorisation qui invente est plus
> dangereuse qu'une matrice absente : on s'y fie.
>
> *Les noms ci-dessus sont entre guillemets, et non entre accents graves, parce qu'ils
> n'existent pas : la sentinelle `AuthzMatrixNeMentPasTest` vérifie que tout nom en accents
> graves correspond à un acteur réel. Les lignes ⛔ du corps sont ses contre-exemples.*
>
> **Comment re-vérifier** (ne pas faire confiance à ce document sans le rejouer) :
> ```
> php artisan tinker --execute="echo implode(' | ', DB::table('roles')->pluck('name')->toArray());"
> php artisan route:list --path=<préfixe> --json
> ```

## Les rôles qui existent réellement

Huit rôles, tous sur le garde `sanctum` (`RoleTableSeeder`) :
`Admin`, `Branch Manager`, `POS Operator`, `Chef`, `Waiter`, `Stuff`, `Customer`, `Delivery Boy`.

⛔ Il n'existe **pas** de rôle `Manager`. Le rôle du gérant s'appelle **`Branch Manager`** ;
celui de la caisse, **`POS Operator`**. Ce sont deux rôles distincts aux permissions
différentes — les confondre fait écrire des gardes qui ne s'appliquent à personne.

| Acteur | Token | Routes autorisées | Routes interdites |
|---|---|---|---|
| **Admin System** | Sanctum + rôle `Admin` | `/api/admin/*` | — |
| **Gérant** | Sanctum + rôle `Branch Manager` | `/api/admin/pos*`, `/api/admin/online-order*`, fiscal, rapports de caisse | Config globale en écriture (`permission:settings`, qu'il n'a pas), suppression de succursale |
| **Caissier** | Sanctum + rôle `POS Operator` | `/api/admin/pos*`, `/api/admin/online-order*`, KDS, OSS | Fiscal (`pos-manage-fiscal`), remises > 10 %, suppression d'une commande payée |
| **Borne Kiosk** | Sanctum (`kioskToken`, ability `kiosk:order`) | `/api/frontend/*` (création) | **Totalement interdit** : `/api/admin/*` (`BlockKioskTokenFromAdminRoutes`). Modifier infos compte |
| **Chef (KDS)** | Sanctum + rôle `Chef` | `/api/admin/kds-order/*`, OSS | Voir une autre succursale. Rejeter un encaissement POS |
| **Client / App** | Sanctum (normal) | `/api/frontend/order`, `/api/frontend/address` | Imposer un prix JSON. Marquer la commande `DELIVERED` |
| **Écran client (OSS)** | voir ci-dessous — **deux surfaces distinctes** | | |

### L'écran client a DEUX surfaces, à ne pas confondre

La version précédente annonçait « OSS Screen — api-key uniquement — `/api/admin/oss-order` ».
C'est faux : elle nommait le chemin **admin** en décrivant l'authentification du chemin
**public**.

| Surface | Chemin | Garde réelle |
|---|---|---|
| Admin (exploitation) | `GET /api/admin/oss-order`, `/popular-items` | `auth:sanctum` **+ `permission:order-status-screen`** (`OrderStatusScreenController:22`) — détenue par Admin, Branch Manager, POS Operator, Chef, Waiter, Stuff |
| Mur client (TV publique) | `GET /api/frontend/oss-order`, `/popular-items` | **Aucune authentification**, `throttle:oss-public` (60/min/IP). Charge sans PII : id, numéro de commande, jeton, numéro de file, type, statut |

Le mur est monté sur un écran public : c'est la raison d'être de la surface frontend — sans
elle, le SPA appelait la route admin sans session et les colonnes restaient vides sur un 401.

## Invariants de Sécurité
- L'app doit avoir le fichier `storage/installed` pour démarrer.
- Le middleware `apiKey` filtre les requêtes publiques pour éviter le web scraping agressif.
  ⚠️ **Ce n'est pas un secret** : la clé est publiée dans le HTML et les bundles JS
  (`ApiKeyMiddleware` le documente lui-même). Elle freine le scraping, elle n'autorise rien.
- Le token des bornes (`KioskMachine`) porte l'ability `['kiosk:order']` au niveau Sanctum, et
  `BlockKioskTokenFromAdminRoutes` lui refuse `/api/admin/*`.
- Les validations JSON de prise de commande NE DOIVENT JAMAIS accepter les totaux envoyés par
  les clients ; le backend calcule depuis sa propre **Source of Truth** (`PricingService`).

## Permissions POS — gates Spatie `can()`

Créées par `RolePermissionTableSeeder`, gardées par `OrderService`, `PosOrderRequest` et les
contrôleurs Fiscal. L'absence d'une permission retourne `HTTP 403`.

⛔ Il n'existe **pas** de permission `pos-apply-discount`. La remise est découpée en **trois
paliers** — c'est ce découpage qui permet au caissier de remiser seul jusqu'à 10 % sans
ouvrir la porte au-delà.

| Permission | Rôles qui la détiennent (relevé en base) | Garde | Route(s) |
|---|---|---|---|
| `pos-discount-up-to-10` | `Admin`, `Branch Manager`, `POS Operator` | `OrderService` + `PosOrderRequest` | `POST /api/admin/pos-order/*` avec `discount` ≤ 10 % |
| `pos-discount-over-10-requires-manager` | `Admin`, `Branch Manager` | idem | `discount` > 10 % |
| `pos-discount-unlimited` | `Admin` seul | idem | remise sans plafond |
| `pos-destroy-paid` | `Admin` seul | `OrderService::destroy()` refuse une commande `payment_status=PAID` | `DELETE /api/admin/order/{id}` |
| `pos-manage-fiscal` | `Admin`, `Branch Manager` | gates `/admin/fiscal/*` (ouverture, clôture, export) | `POST /api/admin/fiscal/z-report/{open,close}`, `GET /api/admin/fiscal/{z-report,x-report}` |
| `order-status-screen` | `Admin`, `Branch Manager`, `POS Operator`, `Chef`, `Waiter`, `Stuff` | `OrderStatusScreenController:22` | `GET /api/admin/oss-order*` |

D'autres permissions actives ne figurent pas encore dans ce tableau — notamment `pos-refund`,
`pos-reopen-z`, `cash-sessions-report`, `cash.reconcile.variance.override`, `catalog.compose`,
`catalog.publish`, `ingredients_manage`, `availability_toggle`, `pos-flyer-print`. Les lister
sans les avoir vérifiées une par une reproduirait le défaut que cette révision corrige.

### Rate-limits dédiés

- `POST /api/admin/fiscal/z-report/open` et `.../close` : `throttle:10,1` (10 req/min/utilisateur).
  Un utilisateur légitime ouvre au plus 1 Z par jour et par branche ; le rate-limit bloque les
  retry-storms, qui brûleraient définitivement des `sequence_no` monotoniques (chaque ouverture
  alloue un numéro signé dans la chaîne HMAC, en INSERT-only, irréversible).
- `GET /api/frontend/oss-order*` : `throttle:oss-public` (60/min/IP), contre l'énumération de
  `branch_id` sur un flux non authentifié.

Voir [`docs/FISCAL_SECRETS.md`](./FISCAL_SECRETS.md) pour la rotation des secrets HMAC qui
signent `audit_logs` et `z_reports`.
