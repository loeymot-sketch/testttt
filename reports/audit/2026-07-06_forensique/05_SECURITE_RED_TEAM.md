# FoodKing — Sécurité & Red Team

> Partie 5/7 de l'audit forensique du 2026-07-06.
> Méthode : 6 scénarios d'attaque en boîte blanche statique, chaque maillon ancré dans le code réel. **5 sur 6 sont jugés « faisables », 1 « conditionnel ».**

## 0. Verdict sécurité : le produit n'est pas déployable en l'état

La plateforme manipule des paiements, de la PII client et des données fiscales. Or **cinq chaînes d'attaque complètes** sont exploitables, dont plusieurs par un acteur **non authentifié** ou par un simple **compte client**. Le point commun : la sécurité est *opt-in* (on ajoute une garde quand on y pense) au lieu de *deny-by-default*.

| # | Scénario | Faisable | Acteur | Impact |
|---|---|---|---|---|
| A | Manipulation prix/remise via l'API table QR | 🔴 oui | Non authentifié | Repas ~gratuits, répétable |
| B | Token borne → super-admin | 🔴 oui | Accès borne/réseau | Compromission back-office totale |
| C | Forge de « paiement confirmé » | 🔴 oui | Compte client | Commandes PAID sans encaissement |
| D | Installer en prod → reprise DB | 🔴 oui | Non authentifié | Prise de contrôle de la plateforme |
| E | Écoute broadcast cross-branch | 🟠 conditionnel | Token borne | Fuite temps réel des commandes |
| F | Exploitation des secrets committés | 🔴 oui | Accès HTTP/dépôt | Compromission du projet GCP |

---

## 1. Inventaire des secrets & données sensibles committés

| Fichier (suivi par Git) | Contenu | Gravité |
|---|---|---|
| `public/file/service-account-file.json` | **Clé privée admin Firebase/GCP** (`foodking-inilabs`, `firebase-adminsdk@…`) — sous le docroot **public**, donc servable en HTTP | 🔴 critique |
| `payload_caissier.json`, `payload_chef.json`, `payload_customer.json` | Emails réels de branche + mot de passe `password` en clair | 🟠 haute |
| `database/seeders/UserTableSeeder.php` | Admin par défaut `admin@lecayenne.fr` / `123456` | 🟠 haute |
| `phpunit.xml`, `.env.example` | Identifiants machine borne `kiosk-lecayenne` / `kiosk123` | 🟠 haute |
| `id`, `name`, `email`, `url`, `branch_id`, `landing_url` | Résidus de debug (non secrets, mais déchets committés) | ⚪ hygiène |

> **Action immédiate** : la clé GCP doit être considérée comme **brûlée** — rotation/révocation côté Google **avant** toute autre chose, puis purge de l'historique Git. Les mots de passe de test committés doivent être rotés s'ils existent en prod.

---

## 2. Chaînes d'attaque détaillées

### 🔴 A — Manipulation des prix via l'API table QR (non authentifié)
**Pré-requis** : la clé `x-api-key` est **statique et partagée**, embarquée dans la SPA de commande QR → récupérable via le trafic réseau. Aucune authentification utilisateur.

1. **Recon** : scan du QR d'une table, chargement de la SPA, extraction du header `x-api-key` (`ApiKeyMiddleware.php:23`).
2. **Inventaire** : `GET /api/table/item-category` (mêmes middlewares) donne les `item_id` réels et leurs prix.
3. **Forge** : `POST /api/table/dining-order/` (`routes/api.php:1007`, **sans `auth:sanctum`**) avec `discount = subtotal`.
4. **Validation permissive** : `TableOrderRequest` n'impose que `discount => nullable,numeric` — **aucun plafond, aucun rôle**.
5. **Application** : `PricingService.php:213` applique la remise car `context='table'` ; `total = subtotal + tax + delivery − discount = tax`.
6. **Impact** : commande PENDING poussée au KDS, la cuisine prépare, le client ne paie que la TVA. **Répétable 20 cmd/min/IP**, sans traçabilité caissier. *(Chemin legacy `OrderService.php:1156` également vulnérable.)*

### 🔴 B — Token borne → super-admin
**Pré-requis** : accès à une borne (physique ou réseau) + identifiants machine par défaut inchangés.

1. Clé API statique partagée (`ApiKeyMiddleware.php:21-24`).
2. Identifiants par défaut `kiosk-lecayenne`/`kiosk123`, `user_id=1` (`KioskMachineTableSeeder.php:31-37`).
3. `POST /api/auth/kiosk-login` → 201 + **token Sanctum émis sur l'utilisateur id=1 = admin** (`KioskMachineLoginController.php:83`).
4. L'ability `kiosk:order` **n'est imposée nulle part** sur `/api/admin/*` (aucun alias `abilities` dans `Kernel.php`).
5. `Gate::before(admin => true)` (`AuthServiceProvider.php:30-32`) → le token borne a **tous les droits**.
6. **Impact** : réécriture des prix (`PUT /api/admin/setting/item/{item}` → corruption SSOT), CRUD cross-branch (`branch`, `item-category`, `currency`), gestion utilisateurs/rôles. **Compromission complète du back-office depuis la surface la moins fiable.**

### 🔴 C — Forge de « paiement confirmé »
**Pré-requis** : un compte client Sanctum (inscription libre) propriétaire d'une commande. **Aucun secret PSP, aucune signature.**

1. `POST /api/frontend/order` → commande `UNPAID`, `user_id = moi`.
2. `POST /api/frontend/order/{id}/payment-confirm` (`api.php:852`) avec `transaction_id: 'FAKE-123'`.
3. `paymentConfirm` (`OrderController.php:101-115`) écrit `payment_status = PAID` **sans appeler aucun PSP**.
4. `finalizePaidKioskOrder` notifie la cuisine/KDS → commande impayée traitée comme acceptée.
5. **Impact** : commande PAID, transaction fictive persistée, repas préparé, **reporting fiscal comptabilisant un encaissement inexistant**.

### 🔴 D — Installer en production → reprise DB
**Pré-requis** : module Installer routé en prod (`routes/web.php:21-33`), aucun retrait conditionnel.

1. La garde du constructeur (`InstallerController.php:28-31`) fait `Redirect::to()->send()` **sans `exit`** → en PHP-FPM, la réponse est flushée mais **l'exécution continue**.
2. Preuve zéro-prérequis : `GET /install/final-store` (`routes/web.php:32`), hors CSRF (verbe GET).
3. `POST /install/database` avec des creds pointant le **MySQL de l'attaquant** → `databaseSetup` (`InstallerService.php:31-45`) réécrit `DB_*` du `.env` prod, `config:cache`, puis **`migrate:fresh --force`** (DROP de toutes les tables).
4. `db:seed` recrée `admin@lecayenne.fr` / `123456` (`UserTableSeeder.php`).
5. **Impact** : prise de contrôle totale, destruction des données prod, effondrement de l'isolation `branch_id` et de la source de vérité pricing. + réécriture de secrets via EnvEditor, DoS via `migrate:fresh`.

### 🟠 E — Écoute broadcast cross-branch
**Pré-requis** : token borne valide (bornes provisionnées sous un utilisateur partagé `id=1`).

1. Les événements commande sont diffusés sur `private-branch.{branch_id}` avec **payload complet** (`order_id`, `total`, `token`, `queue_number`, `status`).
2. `POST /api/broadcasting/auth` avec `channel_name=private-branch.1, .2, …`.
3. La garde `channels.php:28` exécute `KioskMachine::where('user_id',1)->first()` — **requête non scopée** — et autorise la branche de la **première** borne, pas celle de l'appelant.
4. **Impact** : réception temps réel de **toutes les commandes** d'une branche arbitraire (montants, tokens de retrait, files, statuts). Aggravant : une borne légitime de la branche B est autorisée sur A et pas sur la sienne.

### 🔴 F — Exploitation des secrets committés
1. `GET /file/service-account-file.json` : `public/.htaccess` ne route vers `index.php` que si le fichier **n'existe pas** — or il existe → Apache **le sert tel quel**.
2. Le JSON contient la `private_key` RSA complète, `client_email` `firebase-adminsdk` et `project_id` `foodking-inilabs`.
3. Reproduction de `FirebaseService::getAccessToken()` (scope `cloud-platform`) → **access token Google**.
4. **Impact** : push FCM frauduleux vers **toute la base clients** (phishing/spam) + surface potentielle élargie sur le projet GCP (Firestore/Auth), à confirmer côté Google.

---

## 3. Faiblesses de sécurité transverses (au-delà des chaînes)

- **`x-api-key` unique et statique** comme seule barrière des endpoints publics, comparée en **non constant-time** (`ApiKeyMiddleware.php:24`) — timing attack + clé partagée non rotative.
- **Tokens Sanctum émis avec l'ability `*`** pour tous les comptes non-borne (`LoginController.php:78`) — aucune défense en profondeur.
- **Token stocké en clair dans `localStorage`** via `vuex-persistedstate` (`store/index.js:219`) — vol de session trivial par XSS (surface XSS à auditer côté `v-html`).
- **`QueryException` renvoyée brute au client** (`Handler.php:110`) — fuite de structure SQL.
- **Retours PSP `payment.success/fail/cancel` sans vérification de signature** (`web.php:40`) et **exclus du CSRF**.
- **Migration destructrice** dans le chemin `migrate` standard (`emergency_purge`, cf. C17).

---

## 4. Priorités sécurité (extrait — feuille de route complète en 07)

| Prio | Action | Effort |
|---|---|---|
| **P0** | Révoquer/roter la clé GCP, la sortir de `public/`, purger l'historique | S |
| **P0** | Neutraliser l'Installer en prod (middleware `abort(404)` si `installed`) | S |
| **P0** | Vérifier le paiement côté PSP avant `PAID` + webhooks signés | M |
| **P0** | Corriger `BranchScope` (`branch_id=0` ≠ admin) + garde `role:admin` sur `/api/admin` | M |
| **P1** | Token borne sur utilisateur dédié sans rôle admin + enforcement `abilities:kiosk:order` | M |
| **P1** | Retirer prix/remise/`delivery_charge`/identité du payload sur endpoints table/kiosk | M |
| **P1** | Corriger l'autorisation des canaux broadcast (par borne, par rôle) | S |

---

*Contexte : ces scénarios sont établis par analyse statique en boîte blanche (dépendances non installées). Ils sont autorisés dans le cadre d'un audit interne. Les impacts « cloud élargi » (GCP) et « compte non roté » restent à confirmer côté fournisseur.*
