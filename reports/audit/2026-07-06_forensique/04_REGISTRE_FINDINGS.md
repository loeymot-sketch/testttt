# FoodKing — Registre des findings vérifiés

> Partie 4/7 de l'audit forensique du 2026-07-06.
> Source : fusion dédupliquée des 32 panels (3 lentilles/système), 8 traces d'invariants et 6 scénarios red team.
> **Vérification** : la phase adversariale a soumis les findings critical/high à 3 réfutateurs indépendants + juge d'arbitrage. Sur les verdicts collectés, **~87 % (152/174) ont été maintenus** ; les 3 plus graves ont en plus été relus manuellement à la source (voir §0). Total consolidé : **31 critical, 76 high, 85 medium, ~19 low** (216 findings dédupliqués).

## 0. Note de fiabilité
Les trois findings les plus explosifs ont été **re-vérifiés à la main dans le code** durant la rédaction :
- ✅ `public/file/service-account-file.json` — clé privée GCP `foodking-inilabs` présente, suivie par Git, sous le docroot public. **Confirmé.**
- ✅ `database/migrations/2026_03_11_999999_emergency_purge_english_menu.php:74` — `DB::table($table)->truncate()` en boucle dans le chemin `migrate`. **Confirmé.**
- ✅ `InstallerController.php:29` — `Redirect::to()->send()` sans `exit` dans le constructeur : la garde ne stoppe pas l'exécution. **Confirmé.**

---

## 1. Findings **CRITIQUES** (31)

Regroupés par cause racine (voir rapport 03 §9). `xN` = nombre d'auditeurs indépendants ayant remonté le même point.

### Cluster A — Le client dicte prix / identité sur des endpoints non authentifiés (9)

| # | Finding | Emplacement |
|---|---|---|
| C01 | Commande table QR : `branch_id`/`customer_id`/`dining_table_id` acceptés bruts → usurpation d'identité et injection cross-branch | `app/Http/Requests/TableOrderRequest.php:29` |
| C05 | `delivery_charge` client sans borne `min:0` ni recalcul → **valeur négative = repas gratuits** `x2` | `app/Services/FrontendOrderService.php:514` |
| C06 | `delivery_charge` client négatif sur commande DELIVERY → total ~0 mais PAID | `app/Http/Requests/OrderRequest.php:40` |
| C21 | `delivery_charge` client honoré (le paiement débite `$order->total`) | `app/Services/FrontendOrderService.php:514` (trace) |
| C10 | Remise manuelle client sur endpoint table non authentifié → repas gratuits | `app/Services/OrderService.php:1156` |
| C20 | Remise manuelle jusqu'à 100 % sur QR non authentifié (ne paie que la TVA) | `app/Http/Requests/TableOrderRequest.php:36` |
| C26 | Remise arbitraire jusqu'au sous-total sur QR (répétable 20 cmd/min/IP) | `app/Services/Pricing/PricingService.php:213` |
| C13 | Remise « fantôme » POS : `form.discount` périmé envoyé après changement de quantité → sous-facturation | `resources/js/components/admin/pos/PosComponent.vue:1446` |
| C11 | Paiement borne marqué **PAID sur simple déclaration client** (aucun contrôle PSP) `x3` | `app/Http/Controllers/Frontend/OrderController.php:111` |

### Cluster B — Isolation de branche : `branch_id=0` surchargé, IDOR, scope *opt-in* (9)

| # | Finding | Emplacement |
|---|---|---|
| C07 | `branch_id=0` (client) désactive `BranchScope` → un client voit **toutes les branches** `x4` | `app/Models/Scopes/BranchScope.php:33` |
| C02 | IDOR public : `/table/dining-order/show/{order}` expose toute commande (PII) `x4` | `app/Http/Controllers/Table/OrderController.php:33` |
| C16 | IDOR non authentifié : énumération d'IDs → fuite PII cross-branch massive `x2` | `routes/api.php:1005` |
| C08 | IDOR : `/api/admin/my-order/show/{user}/{order}` ne vérifie jamais l'appelant `x3` | `app/Services/OrderService.php:1286` |
| C12 | Broadcast : `branch_id===0` accorde **tous** les canaux de branche à chaque client `x2` | `routes/channels.php:33` |
| C30 | Broadcast : garde kiosque `->first()` non liée à la borne → écoute la mauvaise branche | `routes/channels.php:28` |
| C22 | Transactions non scopées : un Branch Manager exporte les paiements de **toutes** les branches | `app/Services/TransactionService.php:33` |
| C18 | KDS change-status sans garde de branche → un chef modifie les commandes d'une autre branche | `app/Services/KitchenDisplaySystemOrderService.php:116` |

### Cluster C — Surface d'autorisation : la borne = super-admin (5)

| # | Finding | Emplacement |
|---|---|---|
| C15 | Token borne émis sur l'utilisateur **admin id=1** ; l'ability `kiosk:order` jamais appliquée → prise de contrôle admin | `app/Http/Controllers/Auth/KioskMachineLoginController.php:82` |
| C19 | Idem, contredit frontalement `AUTHZ_MATRIX.md` (la doc jure un blocage natif Sanctum) `x2` | `.../KioskMachineLoginController.php:83` |
| C27 | À la conception : la borne est *seedée* sur l'admin id=1 (moindre privilège violé) | `database/seeders/KioskMachineTableSeeder.php:33` |
| C09 | Groupe `/api/admin` sous `auth:sanctum` **seul** : 12+ contrôleurs sans garde de permission `x3` | `routes/api.php:229` |
| C14 | Paiement carte encaissé sur commande auto-rejetée : argent capturé, cuisine jamais notifiée, « Paiement confirmé » mensonger | `app/Services/FrontendOrderService.php:797` |

### Cluster D — Intégrité fiscale & atomicité des chemins argent (5)

| # | Finding | Emplacement |
|---|---|---|
| C24 | Stripe : `(int) $order->total * 100` → **montant tronqué**, perte d'argent déterministe, grand livre incohérent | `app/Http/PaymentGateways/Gateways/Stripe.php:47` |
| C25 | Remboursement **dupliqué** par annulation concurrente (ni verrou ni transaction) | `app/Services/FrontendOrderService.php:661` |
| C03 | Scellement Z incomplet : `changeStatus`/`changePaymentStatus` mutent une commande scellée | `app/Services/OrderService.php:1596` |
| C23 | Idem (trace) : annulation post-clôture rembourse une vente déjà comptée au Z signé | `app/Services/OrderService.php:1499` |
| C04 | Trou entre deux Z : commandes POS numérotées omises de tout rapport fiscal (sous-déclaration CA/TVA) `x3` | `app/Services/Fiscal/ZReportService.php:207` |

### Cluster E — Secrets exposés, Installer, migration destructrice (3)

| # | Finding | Emplacement |
|---|---|---|
| C31 | 🔥 **Clé privée admin Firebase/GCP committée dans le docroot public** (projet `foodking-inilabs`) | `public/file/service-account-file.json:5` |
| C28 | Garde Installer inefficace (`->send()` sans `exit`) → routes `/install/*` exécutables en prod | `app/Http/Controllers/Installer/InstallerController.php:29` |
| C29 | Reprise DB non authentifiée : réécriture `.env` + `migrate:fresh` + reseed admin `123456` | `app/Services/InstallerService.php:40` |
| C17 | Migration `emergency_purge` : `TRUNCATE` inconditionnel de tout le menu, cross-branch, irréversible | `database/migrations/2026_03_11_999999_emergency_purge_english_menu.php:74` |

---

## 2. Findings **HIGH** (76) — synthèse par système

> Liste des plus notables par système (le détail complet est dans les données brutes de l'audit).

**orders (9)** — Override Admin des états terminaux sans trace HMAC (`OrderStateMachine.php:60`) · scellement Z limité à `destroy()` (`OrderService.php:1723`) · ventes kiosk/web/QR jamais numérotées fiscalement (`:862`) · `changeStatus` sans verrou + route sans throttle (`:1470`) · `OrderCreated` best-effort avec listeners critiques synchrones (`:948`).

**authz (7)** — Tokens non-kiosque émis avec ability `*` (`LoginController.php:78`) · `DefaultAccessService` mass-injection sans FormRequest (`:38`) · `/api/admin/default-access` sans permission (`DefaultAccessController.php:31`) · suppression cross-branch des messages (`MessageController.php:36`) · `/register` public écrase l'email d'un compte téléphone (`LoyaltyController.php:155`).

**db (6)** — Ventes annulées mais encaissées comptées **à la fois** en CA et en annulation dans le Z (`ZReportService.php:217`) · fenêtre d'agrégation Z bornée sur `created_at`/`opened_at` → intervalle mort (`:210`,`:212`) · `total_by_tax_rate` court-circuite le `SoftDeletingScope` (`:250`) · `BranchScope` sur 5 modèles seulement (`BranchScope.php:13`).

**pricing (6)** — Palier d'autorisation de remise POS contourné via le `subtotal` client (`PosOrderRequest.php:143`) · TOCTOU sur `limit_per_user` des coupons (`CouponService.php:308`) · `tax_id` invalide → 0 % TVA silencieux (`PricingService.php:141`) · remise manuelle sans plafond % ni autorisation (`DiscountCalculator.php:22`) · SSOT calculé puis jeté et recalculé inline (`FrontendOrderService.php:222`).

**sync (6)** — Clé Pusher vide en prod : `dispatched_at` positionné **sans** broadcaster → perte silencieuse d'événements (`DispatchDomainEventsJob.php:84-88`) · double diffusion sans idempotence consommateur (`:91`) · reconnexion WebSocket **sans resynchronisation** (`WebSocketService.js:128`) · outbox non atomique (`OrderService.php:1556`).

**kiosk (6)** — Points fidélité déduits mais jamais remboursés à l'auto-rejet (`CleanupStalePendingKioskOrders.php:29`) · articles auto-86 jamais réactivés → disparition permanente du menu (`AvailabilityService.php:133`) · promo affichée en preview mais jamais appliquée (`FrontendOrderService.php:211`) · IDOR inter-commandes borne par `user_id` partagé (`:625`).

**structure (6)** — **227 Mo de binaires** committés sans LFS (`.gitattributes`) · **4 implémentations UI/kiosque concurrentes** dont du Flutter/Dart (`kiosk_implementation/.../payment_screen.dart`, `borne (Remix)/lib/kiosk-app.jsx`) · bundles compilés `kiosk.js`/`pos-wizard.js` servis en prod **sans entrée de build** (`master.blade.php:128`) · chemins hostiles au tooling (répertoire avec guillemet littéral).

**docsdrift (5)** — Disponibilité/86 jamais vérifiée à la commande alors que `BUSINESS_RULES.md` l'affirme (`PricingService.php:35`) · `AUTHZ_MATRIX.md` documente une permission `pos-apply-discount` **fantôme** (`AUTHZ_MATRIX.md:26`) · endpoints `/api/admin/*` sans autorisation malgré la doc.

**kds (4)** — Commandes POS chargées mais **jamais affichées** en cuisine (`KitchenDisplaySystemComponent.vue:611`) · `limit(50)` + tri desc fait disparaître les commandes actives les plus **anciennes** en coup de feu (`:103`) · deux sources de vérité pour la branche (`:49`).

**security (4)** — Clé API unique statique comparée en **non constant-time** (`ApiKeyMiddleware.php:24`) · Installer sans authentification (`InstallerController.php:28`) · credentials committés (`payload_caissier.json`) · IDOR table (`Table/OrderController.php:36`).

**tests (4)** — Suite **Vitest (53 fichiers) jamais exécutée en CI** (`playwright.yml:57`) · tests d'invariants **vacants** (`PricingIntegrityTest.php:27`) · assertions tolérantes acceptant succès **ET** échec (`SecurityComprehensiveTest.php:188`, `KioskScopeIsolationTest.php:56`).

**api / posadmin / payments / autres** — `QueryException` SQL brut renvoyé au client (`Handler.php:110`) · **token Sanctum en clair dans `localStorage`** → vol de session par XSS (`store/index.js:219`) · `posCart` persisté non scopé (`:224`) · aucun webhook signé Stripe (`Stripe.php:46`) · retours PSP `payment.success/fail` sans vérification de signature (`web.php:40`) · identifiants borne par défaut réappliqués en prod par CLI (`EnsureKioskMachineCommand.php:24`).

---

## 3. Medium (85) & Low (~19)
Détaillés dans les données brutes de l'audit. Thèmes dominants : absence de pagination sur des listes admin, arrondis monétaires, N+1 sur les listes commandes/items, gestion d'erreurs front silencieuse, dette de nommage, drift de contrat d'événements front↔back. À traiter en phase P2/P3 (voir feuille de route 07).

---

*Chaque finding est ancré sur `fichier:ligne`. Le narratif de cause racine et les chaînes d'exploitation sont dans les rapports 03 (invariants) et 05 (red team).*
