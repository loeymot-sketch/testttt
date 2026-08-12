# INVENTAIRE — Fidélité en CAISSE et en ADMIN

**Date** : 2026-08-10
**Branche** : `pos/category-first-caisse-2026-06-23` — HEAD `e32cbdbcf`
**Nature** : cartographie en LECTURE SEULE. Aucun fichier, aucune base, aucun `.env` modifié.
**Objet** : recenser ce qui EXISTE déjà avant d'écrire une ligne de code, pour ne pas réécrire
ce qui est là. Chaque affirmation porte un `file:line` réellement ouvert ; chaque valeur de
configuration a été LUE via `php artisan tinker`, jamais déduite du fichier de config.

---

## 0. Le besoin, ramené à ce qui existe

| # | Demande du propriétaire | État réel |
|---|---|---|
| 1 | Créer un compte client sur place | Backend PRÊT — un service **avec toutes les gardes** existe (`WheelAccountService::ensure`). **Aucun écran caisse/admin** |
| 2 | Ajouter des points pour une commande | Backend PRÊT en DEUX chemins (automatique + manuel `addPoints`) — **aucun écran, aucun appelant** |
| 3 | Identifier par téléphone ou e-mail | Recherche par téléphone EXISTE — mais **`users.phone` n'a AUCUN index, n'est PAS unique, et 5 numéros sont déjà portés par plusieurs comptes** |
| 4 | Scanner le QR avec la caméra de la tablette | Récepteur serveur COMPLET et inutilisé — **aucun lecteur caméra n'existe dans le projet** |
| 5 | Plancher d'utilisation (« 1000 points ») | Réglage EXISTE et accepte 1000 — mais **le service de CAISSE ne le lit pas**, et l'écran de réglage est **retiré du menu** |
| — | Utiliser les points au paiement | **DÉJÀ FAIT, branché en caisse, testé** (`PosLoyaltyRedeemModal.vue`, 7 tests) |

La demande n'est donc pas « construire la fidélité ». Le moteur est là, éprouvé, avec son
grand-livre et ses sentinelles. Ce qui manque est presque entièrement de la **surface d'écran**,
plus deux trous réels : le plancher non appliqué en caisse, et le lecteur de QR par caméra.

---

## 1. Le modèle de données

### 1.1 Le solde vit sur `users` — deux colonnes

`database/migrations/2026_03_08_145926_add_loyalty_fields_to_users_table.php:16-17`

```php
$table->string('loyalty_code', 15)->nullable()->unique();
$table->integer('loyalty_points')->default(0);
```

- `loyalty_code` : **UNIQUE et indexé** — index `users_loyalty_code_unique` confirmé en base.
- `loyalty_points` : `integer` **signé**, défaut 0. C'est le solde courant, la valeur d'usage.

### 1.2 Le journal EXISTE — `loyalty_transactions`

`database/migrations/2026_03_26_075918_create_loyalty_transactions_table.php:20-33`

Ce n'est donc **pas** « seulement un solde » : il y a un vrai grand-livre append-only.

| Colonne | Détail |
|---|---|
| `user_id` | indexé, FK `users` `onDelete('cascade')` (:32) |
| `loyalty_code` | `string(25)`, indexé — copie de traçabilité au moment du mouvement |
| `order_id` | nullable, indexé |
| `type` | `enum('earn','redeem','manual_add','manual_deduct','expire')` (:25) — **liste fermée** |
| `points` | `integer` signé : positif = gain, négatif = dépense |
| `balance_after` | photo du solde après le mouvement (:27) |
| `source_surface` | `string(20)` — `kiosk / pos / web / mobile / admin` (:28) |
| `pos_session_id` | nullable, ajouté par `2026_05_19_120000_...:34` — rattache un rachat caisse à la session de tiroir ouverte |

**Garde d'unicité** : `2026_03_26_075919_add_unique_to_loyalty_transactions.php:29`
→ `UNIQUE (user_id, order_id, type)`, nommée `loyalty_transactions_user_order_type_unique`.
C'est cette contrainte qui rend « un seul rachat par commande » vrai au niveau base, et non
seulement au niveau service.

⚠️ **Sa limite, documentée dans le code** : MySQL traite les `NULL` comme distincts, donc la
contrainte **ne protège rien quand `order_id` est NULL** — le cas de `addPoints` (crédit manuel)
et du pré-rachat web. `LoyaltyService.php:305-312` le dit explicitement et compense par un jeton
`[reap:<id>]` dans la description. `addPoints`, lui, compense par la couche HTTP d'idempotence
(`routes/api.php:1729-1736`).

### 1.3 Sur `orders` — trois colonnes seulement, dont **une seule monétaire**

| Colonne | Migration | Sens |
|---|---|---|
| `loyalty_points_awarded` | `2026_03_25_003209_...:17` | `unsignedInteger` nullable. `null` = pas encore crédité, `0` = inéligible, `N` = N points crédités. Sert de **sentinelle anti-double-crédit** |
| `loyalty_customer_code` | `2026_03_26_005907_...:18` | `string(25)` — le client fidélité de la commande (sur une commande borne, `user_id` est la MACHINE, pas le client) |
| `discount` | `2022_11_17_110810_create_orders_table.php:27` | `decimal(19,6)` — **le seul champ de remise de toute la table** |

Il n'existe **aucune** colonne `discount_type`, `loyalty_discount`, `points_redeemed` sur
`orders`. Conséquence fiscale majeure : voir §5.3.

Note : `2026_05_11_010000_fix_orders_loyalty_points_awarded_signed.php` existe — la colonne a dû
être repassée en signé pour héberger la sentinelle `-1`.

### 1.4 Génération du `loyalty_code` — un seul motif, répété à trois endroits

```php
strtoupper(substr(md5(uniqid()), 0, 8))   // ex. A1B2C3D4
```

- `app/Http/Controllers/Frontend/LoyaltyController.php:215` (création via `register`)
- `app/Http/Controllers/Frontend/LoyaltyController.php:102` (rattrapage dans `check`)
- `app/Http/Controllers/Frontend/LoyaltyController.php:943` (rattrapage dans `generateQr`)

**8 caractères hexadécimaux majuscules** (alphabet `0-9A-F`, soit 16^8 ≈ 4,3 milliards).

⚠️ **Aucun de ces trois endroits ne gère la collision.** Il n'y a pas de boucle de retry :
l'unicité est laissée à la contrainte `UNIQUE` de la base, donc une collision ne se traduit pas
par une régénération mais par une `QueryException` 23000 remontant en **500 « Erreur serveur »**
(`LoyaltyController.php:244-247`). La probabilité est faible ; le comportement en cas de heurt est
néanmoins « échec brut », pas « réessaie ». À 151 codes existants c'est théorique — mais le motif
est copié trois fois, ce qui est le bon moment pour le centraliser si on y touche.

### 1.5 État réel de la base aujourd'hui (lu, pas supposé)

```
users total                 = 346
users avec loyalty_code     = 151
users loyalty_points > 0    =  11
somme des points en circul. = 3859
loyalty_transactions        =  43
loyalty_consents            =   9
commandes loyalty_points_awarded > 0 =  5
commandes loyalty_customer_code non nul = 37
```

Ventilation du grand-livre :

| type | surface | n | points |
|---|---|---|---|
| earn | kiosk | 6 | +146 |
| earn | pos | 2 | +404 |
| earn | web | 4 | +21 |
| redeem | pos | 11 | −1200 |
| redeem | kiosk | 7 | −1940 |
| manual_add | pos | 8 | +900 |
| manual_deduct | admin | 2 | −525 |
| manual_deduct | web | 3 | −225 |

Lecture franche : **le programme est déjà utilisé en caisse pour DÉPENSER (11 rachats) mais
presque jamais pour GAGNER (2 gains)**. 5 commandes seulement, sur plusieurs milliers, ont crédité
des points. C'est exactement la plainte du propriétaire, et §4.4 en donne la cause mécanique.

### 1.6 Table annexe — `loyalty_qr_nonces_consumed`

`database/migrations/2026_05_19_100000_create_loyalty_qr_nonces_consumed_table.php:37-68`
`UNIQUE(nonce)` **global** (non scopé par branche, :63) + index sur `customer_id` et `consumed_at`.
C'est le garde anti-rejeu du QR signé. Rétention 6 mois prévue par cron.

### 1.7 Table annexe — `loyalty_consents` (RGPD)

`app/Models/LoyaltyConsent.php:28-45` — IP et User-Agent stockés **hashés** (sha256 + sel
`app.key`), `privacy_notice_version` conservée. Écrite uniquement par `optIn`
(`LoyaltyController.php:497-504`).

---

## 2. Les services existants et leur câblage RÉEL

### 2.1 `app/Services/LoyaltyService.php` (362 lignes) — le service de RÉPARATION

Il ne crédite ni ne débite en usage normal. Il **répare** :

| Méthode | file:line | Ce qu'elle fait | Appelée par |
|---|---|---|---|
| `refundPoints` | :21-43 | Rend les points d'un rachat quand la commande est annulée. Rembourse **chaque ligne à SON porteur** via `groupBy('user_id')` (:40) | `OrderService:1753`, `OrderService:1856`, `FrontendOrderService:707`, `Order/RefundWithCounterEntryService:241` (listés :84-85) |
| `refundPointsToOwner` | :48-125 | Le remboursement unitaire, idempotent par pré-détection de la ligne `manual_add` (:89-100) | interne |
| `clawbackEarnedPoints` | :159-221 | Reprend les points GAGNÉS lors d'un remboursement. Écrit `manual_deduct` négatif, solde clampé à 0 (:194) | `ClawbackLoyaltyPointsOnRefund`, `OrderService:2500` |
| `reapOrphanRedemptions` | :242-273 | Récupère les pré-rachats jamais consommés (`order_id` resté NULL). **Plancher dur à 11 min** (:252-254) pour ne jamais courir contre la fenêtre de rattachement de 10 min | cron |

À réemployer tel quel. Deux détails qui sont des décisions, pas des accidents, et qu'il ne faut
pas « corriger » : **aucun filtre de statut** sur le porteur (:54-59 et :179-183) — filtrer avait
détruit les points de comptes legacy `status=1` ; et l'identification par `user_id` issu du
grand-livre, jamais par `orders.loyalty_customer_code` qui est écrasé par le dernier rachat (:35-39).

### 2.2 `app/Services/Loyalty/PosRedemptionService.php` (311 lignes) — **DÉJÀ branché à la caisse**

C'est la pièce centrale du besoin n°2 (« utiliser les points au paiement ») et **elle existe et
fonctionne**. Chemin : `POST /api/admin/pos-order/{order}/redeem-loyalty`.

Séquence de `applyToOrder` (:64-278) :

1. `:75-81` interrupteur `pos.loyalty_enabled` → sinon `LOYALTY_DISABLED` / 422
2. `:86` + `:284-310` garde pré-paiement : refus si `payment_status = PAID` (409) ou statut terminal `DELIVERED / CANCELED / REJECTED / RETURNED` (409)
3. `:99-109` le taux `loyalty_points_for_1_euro_discount` ; **refus si les points ne sont pas un multiple du taux** → jamais de centime fractionnaire
4. `:117-131` recherche du client par `loyalty_code` avec `lockForUpdate`, **puis repli sur `status = 1`** (comptes legacy) (:126-130)
5. `:137-144` contrôle du solde → `INSUFFICIENT_BALANCE` / 422
6. `:155-164` la remise **CUMULÉE** (existante + ce rachat) ne peut dépasser le sous-total. Le correctif du 2026-07-11 est documenté sur place : tester le rachat seul laissait déborder une remise déjà posée, débitant tous les points pour une valeur nulle
7. `:171-178` capture du `pos_session_id` du tiroir ouvert — **null toléré** en mode simulation matériel
8. `:182-187` débit du solde
9. `:192-213` ligne de grand-livre `redeem` ; `QueryException 23000` → `ALREADY_REDEEMED` / 409
10. `:236-240` recalcul du total, **branché sur TTC vs HT** — le commentaire :221-235 raconte le bug corrigé : ajouter `total_tax` en mode TTC surfacturait le client fidèle (un rachat de 50 € atterrissait à 4,55 € au lieu de 0 €)
11. `:248-255` écriture de `discount`, `total`, `loyalty_customer_code`. **`total_tax` n'est jamais touché** (:67) — voir §5.3

⚠️ **Le trou du besoin n°5** : ce service lit `loyalty_points_for_1_euro_discount` (:99) mais
**JAMAIS `loyalty_min_redeem_points`**. Vérifié :

```
$ grep -n "min_redeem\|minRedeem" app/Services/Loyalty/PosRedemptionService.php
>>> AUCUNE occurrence <<<
```

Le plancher est appliqué sur les DEUX autres surfaces (`DiscountCalculator.php:58-65` pour la
borne, `LoyaltyController.php:402-408` pour le web) mais **pas au comptoir**. Aujourd'hui c'est
invisible : plancher 50 < taux 100, et la règle « multiple de 100 » impose déjà 100 minimum. Le
jour où le propriétaire règle le plancher à **1000**, la borne et le site refuseront sous 1000 et
**la caisse acceptera 100 points pour 1 €**. Le réglage qu'il demande existe, mais il ne
mordra pas là où il compte l'utiliser.

### 2.3 `app/Http/Controllers/Admin/PosLoyaltyController.php` (99 lignes)

Contrôleur dédié, créé exprès pour **ne pas toucher** `PosController.php`.
- `:45-56` : `Order::withoutGlobalScope(BranchScope::class)->find()` puis **contrôle de branche explicite après lecture** — la permission Spatie étant globale et non liée à une branche (:38-44). Admin `branch_id=0` passe.
- `:81-97` : enveloppe d'erreur stable `{status, code, message}`.

### 2.4 `app/Http/Requests/PosLoyaltyRedeemRequest.php` (34 lignes)

- `:24` autorisation : `$this->user()?->can('pos.redeem-loyalty')`
- `:30-31` règles : `points` entier 1..100000 ; `loyalty_code` 4..25 caractères, `regex:/^[A-Z0-9\+\s\-]+$/i` — **tolérant au téléphone E.164** (le `+`, l'espace, le tiret sont admis).

⚠️ Mais `PosRedemptionService:117` ne cherche **que** sur `loyalty_code`. La règle de validation
accepte un téléphone, le service ne le résoudra jamais → `CUSTOMER_NOT_FOUND` / 404. La
recherche par téléphone existe ailleurs (`LoyaltyController::check:79-80`), pas ici.

**Permission vérifiée en base** : `pos.redeem-loyalty` (id=20) est portée par **Admin,
Branch Manager, POS Operator**. Semée par `PermissionTableSeeder.php:185` et
`RolePermissionTableSeeder.php:84,106`.

### 2.5 `app/Http/Controllers/Frontend/LoyaltyController.php` (1044 lignes) — le couteau suisse

Le plus gros gisement de réemploi. Sept méthodes utiles, dont **trois répondent directement au
besoin** :

| Méthode | file:line | Route | Auth | Utile pour |
|---|---|---|---|---|
| `check` | :61-129 | `POST api/frontend/loyalty/check` | `auth:sanctum` + `throttle:10,1` | **Besoin 3** — recherche par code PUIS par téléphone (:76-81) |
| `register` | :131-248 | `POST api/frontend/loyalty/register` | **public** + `throttle:5,1` | **Besoin 1** — création de compte téléphone+nom |
| `addPoints` | :254-316 | `POST api/frontend/loyalty/add-points` | `auth:sanctum` + `idempotency` | **Besoin 2** — crédit manuel réservé au personnel |
| `redeem` | :324-450 | `POST api/frontend/loyalty/redeem` | `auth:sanctum` + `idempotency` | pré-rachat (web/borne), débite AVANT la commande |
| `optIn` | :472-515 | `POST api/frontend/loyalty/opt-in` | `throttle:5,1` | `register` + trace RGPD |
| `config` | :521-574 | `GET api/frontend/loyalty/config` | aucune | publie le **plancher EFFECTIF** (:537-539) |
| `history` | :576-671 | `GET api/frontend/loyalty/history` | `auth:sanctum` | historique du grand-livre — **pour le client, pas pour le gérant** |
| `scan` | :698-909 | `POST api/frontend/loyalty/scan` | `auth:sanctum` + `throttle:20,1` | **Besoin 4** — résout un QR en profil |
| `generateQr` | :927-969 | `POST api/frontend/loyalty/qr` | `auth:sanctum` + `throttle:30,1` | frappe un QR signé pour LE CLIENT connecté |

Trois points d'attention sur `addPoints`, la brique du besoin n°2 :
- `:258` l'autorisation est un **contrôle de RÔLE en dur** (`Admin`, `Branch Manager`, `POS Operator`, `Stuff`), pas une permission Spatie. Divergent du reste de la caisse qui utilise `can('...')`.
- `:272` recherche **par `loyalty_code` uniquement** — pas de téléphone.
- `:281-303` transaction atomique, `increment` + ligne `manual_add` `source_surface='admin'`, description `'Ajout manuel par staff #<id>'`. Correct et idempotent via la route.

### 2.6 `app/Services/Loyalty/LoyaltyQrSigner.php` (264 lignes)

Signe et vérifie les jetons `lqr.<payload>.<hmac>` (HMAC-SHA256).
- `sign` :50-72 → charge utile `{v, cust, code, nonce, iat, exp}`
- `verifyAndConsume` :94-160 → format, **HMAC en temps constant** (:117-121), version (:129-131), expiration avec tolérance (:134-138), puis **consommation du nonce** (:140-157)

⚠️ **Le nonce est à USAGE UNIQUE.** `:143-149` insère dans `loyalty_qr_nonces_consumed`, et une
violation d'unicité lève `qr_replay` (:154). Un même QR affiché **ne peut être vérifié qu'une
seule fois**. Voir le piège n°1 en §10.

### 2.7 `app/Services/LoyaltySetupService.php` (39 lignes)

Trivial : lit et écrit le groupe de réglages `loyalty_setup`
(`Settings::group('loyalty_setup')`). Appelé par `Admin/LoyaltySetupController.php`.

### 2.8 `app/Listeners/AwardLoyaltyPointsOnDelivery.php` (168 lignes) — voir §4

### 2.9 `app/Listeners/ClawbackLoyaltyPointsOnRefund.php` (95 lignes)

Miroir inverse : appelle `LoyaltyService::clawbackEarnedPoints` au remboursement.

### 2.10 `app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php` (51 lignes)

**Le précédent le plus proche du besoin n°4.** `POST api/admin/pos/customers/lookup-by-nfc`
(`routes/api.php:1158`), `middleware(['permission:pos'])` (:16). Cherche un `User` de rôle
`Customer` par `nfc_uid` **et `branch_id`** (:30-34) et renvoie `{id, name, phone, loyalty_points}`
(:42-49).

C'est exactement la forme de réponse dont un écran caisse a besoin. Deux réserves : la table a
bien une colonne `nfc_uid` avec un index `UNIQUE(branch_id, nfc_uid)`, mais le filtre
`->where('branch_id', $branchId)` (:33) échouera sur un client créé avec `branch_id = 0` (le
motif de tous les chemins de création client — cf. `LoyaltyController.php:193`) dès que le
caissier est sur `branch_id = 1`. Et cela suppose un lecteur NFC, que le propriétaire n'a pas.

### 2.11 Wheel (roue) — `app/Services/Wheel/**`

Hors périmètre direct, mais `WheelAccountService.php` crée des comptes et
`WheelReportService.php:188` lit `loyalty_points_for_1_euro_discount` pour valoriser les lots.
Signalé pour cohérence : toute modification du barème se répercute sur la valorisation des lots
de la roue.

---

## 3. Les interrupteurs — valeurs EFFECTIVES lues en base

Lues via `php artisan tinker`, sur cette machine, à cette date.

### 3.1 Réglages `loyalty_setup` (table `settings`, groupe `loyalty_setup`)

```
loyalty_points_per_euro              = 10
loyalty_points_for_1_euro_discount   = 100
loyalty_min_redeem_points            = 50
```

**Le barème que le propriétaire cite est exact** : 100 points = 1 €, donc 1000 points = 10 €.
Et 10 points gagnés par euro dépensé → une commande de 10 € rapporte 100 points, soit 1 € de
remise, soit **10 % de retour**.

Le plancher est aujourd'hui à **50**, pas 1000. Modifiable par le gérant sans déploiement :
`LoyaltySetupRequest.php:19` accepte `integer|min:0|max:10000` → **1000 est une valeur légale**.
Écran : `resources/js/components/admin/settings/LoyaltySetup/LoyaltySetupComponent.vue`.

Note : `config()` de `LoyaltyController.php:527` lit aussi une clé `loyalty_tiers`
(défaut `'100,250,500,1000,2000'`) **absente de la base** — donc le défaut s'applique, et cette
clé n'est ni dans `LoyaltySetupRequest` ni dans `LoyaltySetupResource`. Réglage fantôme, non
pilotable par l'admin.

### 3.2 Interrupteurs `config/pos.php`

| Clé | Valeur EFFECTIVE | Ce qu'elle bloque |
|---|---|---|
| `pos.loyalty_enabled` | **`true`** | Le REDEEM de points. Ouvert. |
| `pos.manual_discount_enabled` | **`false`** | Remise manuelle libre + coupons. **Coupé.** |
| `pos.coupon_codes_enabled` | **`false`** | Codes promo (interrupteur dédié). Coupé. |
| `pos.simulation_hardware` | **`true`** | Précondition de tiroir ouvert. Contournée (dev). |
| `pos.walkin_route_to_counter` | `false` | Encaissement différé caisse. |
| `kiosk.promo_enabled` | `false` | L'UI de rachat **sur la borne**. |
| `kiosk.payment_route_all_to_counter` | `true` | Plan B — la borne encaisse au comptoir. |
| `pricing.tax_inclusive_prices` | **`true`** | Prix TTC. Détermine la formule de total. |

### 3.3 Le commentaire de `config/pos.php` sur le découplage : **VRAI, vérifié dans le code**

Le propriétaire avait raison de demander vérification. La réponse est : **le commentaire dit vrai,
le REDEEM n'est PAS gaté par le kill-switch des remises.**

`config/pos.php:177-186` annonce le découplage (owner 2026-07-18 « garde la fidélité »).
Preuve dans le code, aux deux chokepoints qui débitent :

- **Caisse** — `PosRedemptionService.php:75` : `if (config('pos.loyalty_enabled') !== true)`.
  Le mot `manual_discount_enabled` **n'apparaît pas** dans ce fichier.
- **Web/borne** — `LoyaltyController.php:336` : même test, même flag dédié.

Et c'est verrouillé par sentinelle, pas seulement par intention :
`tests/Feature/Fiscal/ManualDiscountDisabledV1SentinelTest.php:136-167`
(`test_loyalty_redeem_is_decoupled_from_manual_discount_killswitch`) prouve qu'avec
`manual_discount_enabled = false` le rachat **franchit** la barrière et échoue plus loin sur
`CUSTOMER_NOT_FOUND` ; `:169-192` prouve qu'avec `loyalty_enabled = false` il est refusé en 422
**avant toute mutation**. `tests/Feature/Loyalty/LoyaltyDecoupledFromManualDiscountTest.php:219-229`
verrouille l'indépendance **dans les deux sens**.

Donc, en clair pour le propriétaire : **les remises sont coupées, la fidélité est ouverte, et
c'est bien ce que fait le code.** Aucun travail à faire de ce côté.

### 3.4 Configuration QR (`config/loyalty.php`)

| Clé | Valeur EFFECTIVE | Conséquence |
|---|---|---|
| `loyalty.qr.secret` | **renseigné, 64 caractères** | signature opérationnelle |
| `loyalty.qr.ttl_seconds` | `300` | un QR vit **5 minutes** |
| `loyalty.qr.leeway_seconds` | `30` | tolérance d'horloge |
| `loyalty.qr.accept_legacy_plaintext` | **`false`** | **un QR en clair `FK:<code>` est REFUSÉ** |
| `loyalty.orphan_redeem_reap_minutes` | `30` | fenêtre du récupérateur |

Le `false` sur `accept_legacy_plaintext` est une décision de sécurité (`config/loyalty.php:52-58`
— un code de 8 caractères permettait de récolter le profil). Il a une conséquence directe sur le
besoin n°4 : voir le piège n°2 en §10.

### 3.5 Idempotence

`idempotency.enabled = true`. Les **trois** routes qui bougent des points sont dans
`required_routes` (`config/idempotency.php:96,99,104`) :
`api/frontend/loyalty/redeem`, `api/frontend/loyalty/add-points`,
`api/admin/pos-order/*/redeem-loyalty`.

---

## 4. L'attribution des points (le GAIN)

### 4.1 Quand

`app/Listeners/AwardLoyaltyPointsOnDelivery.php`, branché sur l'événement `OrderStatusChanged`.

- `:40-46` : commande borne ou à emporter (`KIOSK=25`, `TAKEAWAY=10`) → déclenche sur
  **`PREPARED` (8) ou `DELIVERED` (13)**.
- `:44-46` : **tout le reste, dont la caisse (`POS=15`) → déclenche uniquement sur `DELIVERED` (13)**.

### 4.2 Anti-double-crédit

`:55-63` : `UPDATE orders SET loyalty_points_awarded = -1 WHERE id = ? AND loyalty_points_awarded IS NULL AND status NOT IN (CANCELED, REJECTED, RETURNED)`.
Un seul processus peut réclamer la sentinelle `-1` ; les autres sortent (:61-63). En cas d'échec
ultérieur, la sentinelle est **remise à NULL** (:79-83, :89-93, :108-112, :158-161) pour laisser
une seconde chance. `:31-38` refuse en plus tout crédit sur commande terminale — un événement
`DELIVERED` différé arrivant après un remboursement créditait 300 points sur une commande déjà
reprise.

### 4.3 Le barème est-il réellement appliqué, et sur quel montant ?

**Oui.** `:87` lit `loyalty_points_per_euro` (10). `:105` :

```php
$pointsToAward = (int) floor($orderTotal * $rate);
```

`floor`, donc jamais d'arrondi favorable. Et `$orderTotal` (:99-104) :

```php
if ($isKioskOrder) { $orderTotal = (float) ($order->total ?? 0); }
else               { $orderTotal = (float) ($order->order_amount ?? $order->total ?? 0); }
```

⚠️ **`orders.order_amount` N'EXISTE PAS.** Vérifié :
`Schema::hasColumn('orders','order_amount')` → **NON**. Aucune migration ne la crée, aucun
accesseur ne la simule sur `Order` ni sur `FrontendOrder`. Le commentaire du code (:96) affirme
pourtant « Order (POS) uses `order_amount` » : **le commentaire est faux**. Le `??` sauve la
mise et la valeur retenue est toujours `$order->total`.

Donc, réponse à la question posée : les points sont calculés sur **`orders.total`**, c'est-à-dire
le **TTC APRÈS remise** (`pricing.tax_inclusive_prices = true` → `total = subtotal − discount + livraison`,
`PricingService.php:351`). Un client qui paie 20 € puis obtient 2 € de remise fidélité gagne
180 points, pas 200. C'est cohérent (on ne gagne pas de points sur ce qu'on n'a pas payé) mais
c'est un choix implicite, jamais énoncé nulle part — à confirmer avec le propriétaire.

### 4.4 **Pourquoi le gain ne se produit presque jamais en caisse** — la cause racine

Le crédit exige d'identifier un client (`:68-84`) :
1. `:69-71` par `orders.loyalty_customer_code`
2. `:72-77` sinon par `orders.user_id`, à condition que ce compte ait un `loyalty_code`
3. `:78-84` sinon → sentinelle remise à NULL, **on repart sans rien créditer**

Or la caisse ne remplit ces champs que si le caissier a désigné un client.
`app/Services/OrderService.php:1179-1188` :

```php
if ($request->loyalty_customer_code) {
    $this->order->loyalty_customer_code = $request->loyalty_customer_code;
} else {
    $customer = \App\Models\User::find($request->customer_id);
    if ($customer && $customer->loyalty_code) {
        $this->order->loyalty_customer_code = $customer->loyalty_code;
    }
}
```

Les deux entrées existent déjà côté requête : `PosOrderRequest.php:110`
(`customer_id` — `nullable`) et `:215` (`loyalty_customer_code` — `nullable`).
*(Ces deux numéros de ligne étaient 159 et 258 au début de cette session : le fichier a été
réécrit par une autre session à 21 h 09 pendant l'inventaire — cf. l'avertissement en §12.)*

**Le tuyau est posé de bout en bout. Ce qui manque est le robinet : un écran de caisse qui
identifie le client AVANT l'encaissement.** Les chiffres de §1.5 en sont la preuve directe —
5 commandes créditées sur plusieurs milliers, 2 lignes `earn` de surface `pos`.

C'est le cœur du besoin, et c'est un travail d'**écran**, pas de moteur.

Sur le pas de porte, une conséquence à ne pas manquer : sur une commande de caisse, le crédit
n'arrive qu'au passage en `DELIVERED`. Les données montrent que **39 commandes POS sur 186** ont
atteint `DELIVERED`. Les autres restent en `PREPARING (7)`, `PREPARED (8)` ou `PENDING (1)`.
Autrement dit, même avec un client correctement rattaché, **une commande de caisse jamais
clôturée ne créditera jamais de points**. À vérifier avec le propriétaire : veut-il créditer à
`PREPARED` comme pour la borne, ou clôturer proprement ses commandes ?

---

## 5. Le rachat (dépense) et son traitement FISCAL

### 5.1 Les chemins existants — trois, dont **deux seulement sont utilisables en V1**

| Surface | Chemin | Moment du débit | État |
|---|---|---|---|
| **Caisse** | `PosLoyaltyController::redeem` → `PosRedemptionService::applyToOrder` | **APRÈS** création, avant paiement | **actif et branché à l'écran** |
| Web / borne (pré-rachat) | `LoyaltyController::redeem` → ligne `redeem` avec `order_id = NULL`, rattachée ensuite par `FrontendOrderService` (fenêtre 10 min) | **AVANT** la commande | actif |
| Borne (UI) | derrière `kiosk.promo_enabled` = **`false`** | — | **fermé** (câblage `coupon_id` non réparé, `config/pos.php:219-222`) |

### 5.2 Les gardes du rachat

| Garde | Caisse | Borne / Web |
|---|---|---|
| Interrupteur dédié | `PosRedemptionService:75` | `LoyaltyController:336` |
| Multiple du taux | `:103-109` | `:395-398` |
| **Plancher `min_redeem`** | ⛔ **ABSENT** | `:402-408` et `DiscountCalculator:58-65` |
| Solde suffisant | `:137-144` (sous `lockForUpdate`) | `:409-411` / `DiscountCalculator:69-71` |
| Remise cumulée ≤ sous-total | `:155-164` | `DiscountCalculator:49` |
| Une fois par commande | `UNIQUE(user_id, order_id, type)` + `:204-213` → 409 | idem |
| Pré-paiement seulement | `:284-310` | n/a (débit avant commande) |
| Traçabilité tiroir | `pos_session_id` (:171-178) | n/a |
| Idempotence HTTP | route (`config/idempotency.php:104`) | route (:96) |
| Exclusivité coupon | n/a | `DiscountCalculator:38-40` — **le coupon gagne**, la fidélité tombe à 0 |

Le rachat est plafonné au **sous-total** (`DiscountCalculator:49`, `PosRedemptionService:158`) et
« snappé » aux points entiers (`DiscountCalculator:56-57`) pour rester propre au centime.

### 5.3 Côté FISCAL : **oui, un rachat de points EST une remise au sens du Z**

Et il n'y a aucune façon de l'en distinguer.

`orders.discount` (`decimal(19,6)`, `create_orders_table.php:27`) est le **seul** champ de remise
de la table. Y écrivent indifféremment :
- la remise manuelle et le coupon, via `PricingService.php:326-344`
- le rachat fidélité borne/web, via `FrontendOrderService.php:603`
- le rachat fidélité caisse, via `PosRedemptionService.php:251`

Le service fiscal gelé nette la TVA sans jamais regarder l'origine :
`app/Services/Fiscal/ZReportService.php:803-812`, cœur ligne **:811** —

```php
return max(0.0, min(1.0, ($subtotal - $discount) / $subtotal));
```

Ce ratio est appliqué à chaque tranche de taux (`:826-854`, application ligne **:850**), donc
**la remise est allouée proportionnellement entre les taux de TVA**, pas imputée à l'un d'eux.
`total_tva` = somme des tranches (:561), `total_ht` = TTC − TVA (:563).

À noter : `PosRedemptionService.php:251-255` met à jour `discount`, `total` et
`loyalty_customer_code` mais **jamais `total_tax`** (dit explicitement :67). La TVA stockée sur la
commande reste donc la TVA **brute d'avant remise** ; le netting n'existe **qu'au moment de
l'agrégation du Z**. C'est cohérent — mais il faut le savoir avant de « corriger » une TVA de
commande qui paraît trop haute.

### 5.4 `ZReportDiscountNettingTest` — ce qu'il prouve exactement

`tests/Feature/Fiscal/ZReportDiscountNettingTest.php` (281 lignes, 5 tests, `tax_inclusive_prices = true` :42-44).

| Test | file:line | Preuve |
|---|---|---|
| `test_discounted_order_z_tva_is_netted_to_post_discount_base` | :71 | 10,00 TTC − 2,00 de remise, `total_tax` brut 0,91 → le Z déclare **`total_tva` = 0,73** (:89), TTC 8,00 (:88), HT 7,27 (:90), identité TTC = HT + TVA (:91-96), tranche `'10'` = 0,73 (:98) |
| `test_multi_rate_discount_allocates_proportionally` | :107 | 10 % + 5,5 %, ratio 0,9 → chaque tranche mise à l'échelle du **même** ratio : 1,64 (:126) et 0,47 (:127) |
| `test_non_discounted_order_breakdown_is_unchanged` | :138 | remise 0 → ratio 1 → aucune altération (delta 0,001, :155-156) |
| `test_total_tva_exactly_equals_sum_of_total_by_tax_rate` | :172 | identité **EXACTE** (`assertSame`) : `total_tva === round(Σ tranches, 2)` (:194-198) et `total_ttc === round(total_ht + total_tva, 2)` (:200-204), sur un cas construit pour casser un double arrondi naïf |
| `test_discounted_z_close_signs_and_chain_verifies` | :216 | **le seul bout-en-bout** : `open()` → commande remisée PAYÉE/LIVRÉE → **`close()` réel** (:246) → **`verifySignature()` vrai** (:250) **et `verifyChain()` valide, `errors === []`** (:256-258) ; sur la ligne Z **persistée et signée** : TVA 0,73 (:277), HT 7,27 (:278), tranche `'10'` 0,73 (:279) |

**Ce qu'il prouve, en une phrase** : sur une commande remisée, la TVA du rapport Z est calculée
sur la **base NETTE** (après remise), répartie proportionnellement entre les taux, et le Z
correspondant **se signe et sa chaîne se vérifie**. C'est ce test qui a fermé le défaut « F1 » et
qui, cité par les deux sentinelles de §3.3, **autorise le rachat de points sans risque fiscal**.

Conséquence pratique pour le besoin : **un rachat de points est fiscalement sûr aujourd'hui**.
Augmenter le plancher à 1000 points ne change rien à cette démonstration — c'est un contrôle
d'entrée, pas un calcul fiscal.

---

## 6. Les écrans existants

### 6.1 La ZONE GELÉE ne contient RIEN sur la fidélité — vérifié

| Fichier gelé | `loyalt` / `fidel` / `points` |
|---|---|
| `public/js/pos-wizard.js` (6 329 lignes) | **0 occurrence** |
| `public/css/pos-wizard.css` (2 188 lignes) | **0 occurrence** |

C'est la meilleure nouvelle de cet inventaire, et ce n'est pas un hasard : c'est la raison d'être
du LOCK de mai. Le commentaire de `PosOrderShowComponent.vue:403-405` le dit —
« modal — separate Vue overlay **outside the FROZEN pos-wizard.js** ».
**Toute la fidélité caisse vit dans des composants Vue non gelés.** Le détour est déjà construit.

### 6.2 Côté CAISSE — quatre surfaces, dont trois cliquables

**A. Bouton principal dans la barre opérateur** — `resources/js/components/admin/pos/PosComponent.vue:316-330`
- Texte visible : 🎁 « **Appliquer une réduction fidélité** » (clé `pos.loyalty.redeem.title`, `resources/js/languages/fr.json:341`)
- `data-testid` : `pos-loyalty-redeem-main-cta-open`
- Groupe « **Caisse** » (:218-225), à côté de No-sale / Caisse / Rupture
- ⚠️ **Condition d'existence** : c'est le `v-else` de `v-if="dineInEnabled"` (:288 / :317) — le bouton
  n'existe **que si le service à table est désactivé** (défaut V1). Si le dine-in est réactivé, le
  lien « Plan de salle » prend le créneau et **le bouton fidélité disparaît**.
- Condition d'activation : `:disabled="!canShowLoyaltyMainCta"` (:322) → helper pur
  `resources/js/helpers/posLoyaltyMainCta.js:70-92` : dine-in OFF + une commande en cours captée
  via `order:confirmed` (:3141) + non payée + statut non terminal.
- Aide en état grisé : « **Créez d'abord une commande pour appliquer une réduction fidélité** »
  (`fr.json:342`) — ajoutée après une plainte du propriétaire (« greyed out / inaccessible »,
  commentaire :309-315). Le tracker retombe à `null` en fin de commande (:4405, :4765).

**B. Bouton dans la fiche commande** — `resources/js/components/admin/posOrders/PosOrderShowComponent.vue:332-344`
Même libellé, `data-testid` `pos-loyalty-redeem-open`, condition `:558-570` (non payée, non
terminale) — **sans** le verrou dine-in. Route `admin.pos-orders.show`
(`resources/js/router/modules/posOrderRoutes.js:38`), permission `pos-orders`.

**C. La fenêtre de rachat** — `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue` (505 lignes)
Montée depuis `PosComponent.vue:1494-1499` et `PosOrderShowComponent.vue:406-411`.

| Élément | file:line | Texte FR |
|---|---|---|
| Titre | :18 | Appliquer une réduction fidélité |
| Solde | :59-62 | « Solde actuel » + `{n} pts` — `v-if="customerBalance !== null"` |
| Champ 1 | :68 | **Code fidélité ou téléphone** |
| Champ 2 | :84 | Points à utiliser |
| Aide | :103 | « {rate} points = 1 € » |
| Aperçu | :112-114 | « Réduction prévue : −X,XX € » |
| Boutons | :124 / :133 | Annuler / **Appliquer** |
| Succès | :317-321 | « Réduction appliquée : -X,XX € » |

Appel `POST /admin/pos-order/{id}/redeem-loyalty` (:306) avec en-tête `X-Idempotency-Key`
(:271-276). **8 messages d'erreur en français** mappés par `error_code` (`fr.json:352-361`).

⚠️ **Deux défauts d'usage à connaître** :
1. **La fenêtre ne consulte jamais le solde avant de valider.** Le champ « Solde actuel »
   n'apparaît **qu'après** un « Appliquer » réussi. Le caissier tape le nombre de points **à
   l'aveugle** et découvre « solde insuffisant » après coup.
2. Le champ dit « **Code fidélité ou téléphone** » (:68) et la validation serveur accepte bien un
   téléphone (`PosLoyaltyRedeemRequest:31`) — mais `PosRedemptionService:117` ne cherche **que**
   sur `loyalty_code`. **Un téléphone saisi là donnera toujours « Code fidélité introuvable ».**
   L'écran promet une chose que le serveur ne sait pas faire.

**D. Badge passif dans le panier** — `PosComponent.vue:859-869`
⭐ « **{n} pts fidélité** (CODE) » — chaîne **codée en dur, hors i18n** (:865). `v-if="selectedCustomerLoyalty.code"`,
donc seulement si un client est choisi dans le sélecteur « **Sélectionner un client** » (:793-795).
Alimenté par `_loadCustomerLoyalty()` (:5261-5296) depuis `UserResource` (`app/Http/Resources/UserResource.php:37-38`),
repli `GET frontend/loyalty/balance` (:5281). **Lecture seule, aucun bouton.**

Ce sélecteur de client est la brique la plus précieuse pour le besoin n°2 : c'est déjà lui qui
alimente `customer_id`, donc `orders.loyalty_customer_code` (§4.4).

**E. Ce que la fiche commande NE dit pas** — `PosOrderShowComponent.vue:308-311` affiche une ligne
« **Remise** » générique. Elle ne dit jamais que la remise vient de la fidélité, n'affiche ni le
code, ni les points débités, ni le solde restant.

### 6.3 Côté ADMIN — tout existe, et tout est masqué du menu

**A. Réglages Fidélité** — `resources/js/components/admin/settings/LoyaltySetup/LoyaltySetupComponent.vue` (140 lignes)
Trois champs, avec leurs libellés et leurs aides en français :

| Champ | file:line | Libellé | Aide | Bornes |
|---|---|---|---|---|
| `loyalty_points_per_euro` | :15-28 | **Points par €** | « Nombre de points gagnés par euro dépensé. » | 0–1000 |
| `loyalty_points_for_1_euro_discount` | :32-45 | **Points pour 1€ de réduction** | « Nombre de points nécessaires pour obtenir 1 € de remise. » | 1–10000 |
| `loyalty_min_redeem_points` | :49-62 | **Minimum pour utiliser** | « Solde minimum de points requis avant qu'un client puisse les utiliser. » | 0–10000 |

Aperçu vivant (:67-73) : « **Aperçu — 10€ d'achat = 100 pts → 1,00 € de réduction** ».
Route `/admin/settings/loyalty-setup` (`resources/js/router/modules/settingRoutes.js:160-168`),
permission `settings`, API `routes/api.php:433-436`.

⚠️ **L'onglet est RETIRÉ du menu en V1.** `resources/js/config/v1-hidden-modules.js:24` contient
`'settings.loyalty-setup'`, et `MenuComponent.vue:51` applique `v-if="!isSettingHidden('loyaltySetup')"`.
L'écran reste atteignable **par URL directe uniquement** (commentaire `v1-hidden-modules.js:7`).
Le champ « Minimum pour utiliser » que le propriétaire veut porter à 1000 **existe donc, mais il
n'a aucun chemin de navigation** — il faut connaître l'adresse.

**B. Écran Clients** — `resources/js/components/admin/customers/CustomerListComponent.vue`,
`CustomerShowComponent.vue`, routes `resources/js/router/modules/customerRoutes.js:11-58`
- Colonnes de la liste (:76-82) : Nom / E-mail / Téléphone / Statut / Action
- Onglets de la fiche (:47-63) : Profil / Sécurité / Adresse / Mes commandes

⚠️ **`grep -riE "loyalty|fidel|points" resources/js/components/admin/customers/` = 0 occurrence.**
Solde affiché : **inexistant**. Solde éditable : **inexistant**. Bouton « ajouter des points » :
**inexistant**. Code fidélité affiché : **inexistant**.
Et le module est lui aussi masqué du menu (`v1-hidden-modules.js:12` → `'customers'`), filtré par
`BackendMenuComponent.vue:213-236`. **Il n'y a pas de lien « Clients » dans la barre latérale.**

**C. Historique des mouvements de points** — **INEXISTANT en admin.**
Aucun écran, aucun composant, aucune route.
`grep -riE "loyalty_transaction|loyaltyTransaction" resources/js resources/views` = 0.
L'endpoint de lecture existe (`GET /api/frontend/loyalty/history`, `routes/api.php:1744`) mais ses
**seuls consommateurs sont l'application mobile** (`mobile/api/client.js:113`, `mobile/data/loyalty.js:26`) —
aucun appel depuis `resources/js`.
**Conséquence : un gérant ne peut vérifier NULLE PART l'historique des points d'un client. Ni pour
un litige, ni pour un contrôle.** Les 43 lignes du grand-livre sont invisibles depuis l'admin.

**D. « Ajouter des points »** — endpoint sans écran. `POST /api/frontend/loyalty/add-points`
existe (`routes/api.php:1735-1736`), **aucun appelant dans `resources/js`**.

**E. La SEULE surface admin qui crédite réellement des points aujourd'hui : la ROUE**
`resources/views/admin/wheel/lot.blade.php` (Blade, hors Vue) — écran `/admin/roue-lot`
(`routes/web.php:202-205`, `wheel.access` + PIN).
Textes visibles : « **Numéro du client** » → « **Chercher son lot** » (:82-87), « **· à créditer
sur son compte** » (:94), gros bouton vert « **✓ REMIS AU CLIENT** » (:124).
Ce bouton incrémente `users.loyalty_points` : `app/Services/Wheel/WheelDeliveryService.php:291-292`.

⚠️ **Et il n'écrit RIEN dans `loyalty_transactions`** — vérifié :
`grep -c "loyalty_transactions\|LoyaltyTransaction" app/Services/Wheel/WheelDeliveryService.php` → **0**.
Le grand-livre a donc déjà un trou : les points offerts par la roue apparaissent au solde mais
sont **introuvables dans l'historique**. À ne pas reproduire, et à signaler au propriétaire s'il
compte s'appuyer sur cet historique pour arbitrer un litige.

Ce même écran est cependant le **précédent le plus proche** de ce qu'il demande : une tablette, un
numéro de téléphone saisi, un client retrouvé, un gros bouton de validation au comptoir. La forme
existe déjà et elle a été validée en production.

### 6.4 Côté BORNE — le solde se consulte, les points ne se dépensent pas

| Surface | file:line | Texte FR | État |
|---|---|---|---|
| Entrée « Mon compte » | `KioskCategoriesComponent.vue:35-44` | 👤 **Mon compte** | **visible, AUCUN flag** — chip permanente |
| Écran fidélité, étape saisie | `KioskLoyaltyComponent.vue:22-79` | « **Entrez votre code fidélité ou votre numéro de téléphone** », « Ex. : code ou 06 12 34 56 78 », clavier virtuel, « **Vérifier mon code** », « **Pas encore membre ? S'inscrire** » | **libre** → `POST frontend/loyalty/check` (:536) |
| Étape inscription | `:82-146` | « **Créer votre compte fidélité** » — Nom \* / Téléphone \* / E-mail (facultatif) | **libre** → consentement RGPD (`ds/KsConsentModal.vue:259` `opt-in`) puis `register` (:625) |
| Étape solde | `:149…` | compteur + « **points disponibles** », « Plus que {n} points pour le prochain palier » | **libre** |
| « **Utiliser mes points** » / « Garder mes points » | `:195` | — | ⛔ `v-if="canRedeem && discountsEnabled && kioskPromoEnabled"` → **les deux flags sont false** |
| « **Avez-vous une carte fidélité ?** » (panier) | `KioskCartComponent.vue:336-345` | ★ | ⛔ mêmes deux flags |
| Confirmation | `KioskConfirmationComponent.vue:56-62` | ⭐ « {Nom}, **vous gagnez +{n} points fidélité !** » | actif |
| Ticket imprimé | `:129-131` | « **FIDÉLITÉ : +{n} pts** » | actif |
| Avertissement paiement | `KioskPaymentComponent.vue:506-513` | « Votre réduction fidélité n'a pas pu être appliquée… » | actif |

Donc en production **la borne affiche le solde mais n'offre aucun moyen de le dépenser** — c'est
assumé et documenté (`KioskCartComponent.vue:333-335`, `KioskLoyaltyComponent.vue:386-411`) : le
panier borne promettait « -X € » et le client était **débité plein tarif**. Ce qui confirme le
commentaire de `config/pos.php:219-222` : « Le redeem fidélité utilisable en V1 = **caisse** + API. »

**L'étape « inscription » de la borne est un modèle directement transposable** au besoin n°1 :
trois champs, un consentement, deux appels d'API. Le travail de conception est déjà fait.

### 6.5 Côté SITE web — inexistant dans ce dépôt

`grep -rliE "loyalt|fidel" resources/js/components/frontend/ | grep -v /kiosk/` = **0 résultat**.
`resources/js/components/frontend/account/` contient `address/`, `changePassword/`, `chat/`,
`editProfile/`, `myOrder/` — **pas de page « Mes points »**. Le client web ne peut pas consulter
son solde. Seule l'application **mobile** le peut (`mobile/screens-main.jsx:1291`, `mobile/data/loyalty.js`),
dépôt séparé.

C'est une pièce du piège n°2 : le propriétaire dit que « des clients ont un compte sur le site » —
mais **ce site ne leur montre pas leurs points et ne leur affiche aucun QR**.

### 6.6 Traductions

Le SSOT est `resources/js/languages/fr.json` : `pos.loyalty.redeem.*` (:339-366),
`menu.loyalty_setup` (:411), `label.loyalty_*` (:757-760, :809, :1168-1170),
`kiosk.loyalty_*` (:1760, :1765, :1793-1794), `kiosk.loyalty_screen.*` (:1992-2032),
`kiosk.confirmation.*` (:2283, :2291-2293).
`grep -rni "loyalty|fidel" lang/` = **0** — aucune chaîne fidélité côté PHP/Blade.
Deux chaînes échappent à l'i18n : `PosComponent.vue:865` (« pts fidélité ») et
`PosLoyaltyRedeemModal.vue:61` (« pts »).

### 6.7 Tests d'écran existants

`tests/js/posLoyaltyRedeemModal.spec.js`, `tests/js/posLoyaltyMainPageCta.spec.js`,
`tests/js/kioskLoyaltyConsentWiring.spec.js`, `tests/js/kioskLoyaltyDiscountConsistency.spec.js`,
`tests/e2e/wave-E-1-pos-loyalty-main-cta-capture.spec.js`.

---

## 7. La création de compte

Cinq chemins serveur créent un `User` client. **Aucun n'est piloté depuis la caisse aujourd'hui** :
la caisse ne stocke qu'un `orders.pos_customer_phone` en **texte libre, sans aucun compte**
(`app/Services/OrderService.php:829-830`, formulaire `PosComponent.vue:942`).

### 7.1 Le tableau de décision

| Chemin | Sans OTP | Sans mot de passe | Pose `loyalty_code` | Garde ÉQUIPE | Garde SUPPRIMÉ | Normalise le tél. | Émet un jeton/session |
|---|---|---|---|---|---|---|---|
| **`WheelAccountService::ensure`** | **oui** | **oui** | **oui** | **oui** | **oui** | **oui** | **non** |
| `GuestSignupController::register` | non (OTP requis) | oui | oui | oui | oui (+restaure) | non | **oui** |
| `LoyaltyController::register` | oui | oui | oui | **NON** | **NON** | non | non |
| `SignupController::register` | non | non | **non** | partielle | non | non | non |
| Admin `POST /api/admin/customer` | oui | non | **non** | permission seule | non | non | non |

### 7.2 Le candidat évident : `app/Services/Wheel/WheelAccountService.php`

Signature (`:60`) : `ensure(string $phone, ?string $email, ?string $name): array{user_id, created, reason}`.
**Ne jette jamais** — try/catch `:62-70` renvoie `reason='error'`.

C'est un **service pur, sans route à lui**, donc injectable tel quel depuis un contrôleur de
caisse. Ses gardes, toutes vérifiées ligne à ligne :

| Garde | file:line | Comportement |
|---|---|---|
| Téléphone invalide (< 9 chiffres après normalisation) | :77-79 | `reason='phone_invalid'`, rien créé |
| Recherche sur **TOUTES les écritures** du numéro + `withTrashed()` | :87-91 | `whereIn('phone', phoneVariants($tel))` |
| **Refus d'un compte de l'ÉQUIPE** | :96-100 | `reason='staff_phone'`, **rien créé, rien modifié** |
| **Compte supprimé jamais ressuscité** | :102-104 | `reason='deleted_account'` |
| Compte existant → complété, jamais écrasé | :106-110 | `reason='existing'` |
| E-mail déjà porté par un autre compte → non rattaché | :145-155 | échec silencieux, le reste passe |
| Vrai nom jamais écrasé par un nom générique | :159-162 | |
| **AUCUN jeton, AUCUNE session** | doc :38-39 | c'est le point décisif |
| Pose `loyalty_code` | :166-169 | `strtoupper(substr(md5(uniqid('',true)), 0, 8))` |

Valeurs posées (:113-125) : `name` = nom fourni sinon `'Client Roue'`, `username = Str::slug($nom).Str::random(5)`,
`phone` **normalisé**, `country_code='+33'`, `branch_id=0`, `is_guest=Ask::YES` (5),
`status=Status::ACTIVE` (5), `password=Hash::make(Str::random(32))`, `assignRole(CUSTOMER)`.
`email_verified_at` volontairement **non posé** (doc :136-139).

Sa propre documentation revendique explicitement de **ne pas ouvrir de seconde porte d'entrée**
(:26-32) : « deux portes, c'est deux fois les gardes à maintenir, et un jour l'une des deux oublie
quelque chose. » C'est exactement la contrainte que le propriétaire nous demande de respecter.

Deux réserves honnêtes : le nom par défaut est `'Client Roue'` (:112) — inadapté au comptoir ; et
le nom du service annonce la roue alors que le besoin est général. C'est de la nomenclature, pas
de l'architecture.

### 7.3 Le chemin à NE PAS réemployer tel quel : `LoyaltyController::register`

C'est celui qui **ressemble** le plus au besoin (public, téléphone + nom, ni OTP ni mot de passe,
pose le `loyalty_code`) — et c'est le moins gardé de l'inventaire.

- ⛔ **AUCUNE garde équipe.** Si le téléphone appartient à un compte de l'équipe, on tombe dans le
  `else` (:204-211) puis on **écrit un `loyalty_code` sur ce compte staff** (:214-218).
- ⛔ **AUCUNE garde compte supprimé.** Le lookup `:143` est `User::where('phone', …)` **sans
  `withTrashed()`** → un compte supprimé produit un **doublon**.
- ⛔ **Aucun `assignRole`** — le compte créé n'a **aucun rôle**. Il ne sera donc pas trouvé par
  `->role('Customer')`, ce qui casse notamment `CustomerNfcLookupController:31`.
- ⛔ Aucune normalisation du téléphone. `min:8|max:20`, aucune règle `ValidPhone`, aucune unicité.

Les gardes qui, elles, existent et sont bonnes : e-mail déjà pris → 409 `EMAIL_EXISTS` sans fuite
(:147-165) ; e-mail jamais lié à un compte créé sur un téléphone tiers (:171-177) ; e-mail jamais
attaché à un compte existant (:204-211) ; réponse neutre si le compte préexistait
(`wasRecentlyCreated`, :228-234).

Décisions à ne surtout pas « corriger » : `status = Status::ACTIVE` (:181-187 — le legacy `1`
empêchait toute connexion web), `branch_id = 0` (:188-193 — un `NULL` cassait la finalisation du
login), `is_guest = Ask::YES` (:194-202 — sans ce marqueur le téléphone était verrouillé à jamais
pour les quatre portillons d'inscription).

### 7.4 Le chemin OTP, pour mémoire

`GuestSignupController` (`routes/api.php:209-223`) est le mieux gardé — refus des comptes
non-invités même supprimés (:238-241), restauration contrôlée (:245-249), anti channel-confusion
sur l'OTP e-mail (:135-156). Mais il **émet un jeton Sanctum `kiosk:order` 30 jours et ouvre une
session web** (:325, :354-356). Depuis une caisse partagée, c'est un effet de bord indésirable.
Et l'OTP part **par e-mail** — le SMS n'a **aucun fournisseur câblé** (`app/Services/OtpManagerService.php:23-28`,
mandat propriétaire).

### 7.5 La colonne du téléphone — et son vrai problème

**Elle s'appelle `phone`.** Confirmé aux trois endroits : migration
`database/migrations/2014_10_12_000000_create_users_table.php:22`, `$fillable`
`app/Models/User.php:45`, `$casts` `:65`. Pas de `mobile`, pas de `telephone`. L'indicatif est
séparé : `country_code`.

À noter : **`loyalty_code`, `loyalty_points` et `nfc_uid` ne sont PAS dans `$fillable`** — ils
doivent être assignés après création (c'est ce que fait `GuestSignupController:291`).

Trois faits d'infrastructure, mesurés et non déduits :

1. **`users.phone` n'a AUCUN index et n'est PAS unique.** Migration :22 →
   `$table->string('phone')->nullable();`. Passée `NOT NULL` par
   `2026_05_16_140100_make_user_phone_required.php:93-107` avec remplissage `PENDING_<id>` —
   **toujours sans index**. Index réellement présents en base : `PRIMARY`,
   `users_loyalty_code_unique`, `users_branch_nfc_uid_unique`, `idx_users_deleted_at`,
   `idx_users_email` (non unique). **Rien sur `phone`.**
   → `loyalty_code` est **la seule colonne d'identité client réellement UNIQUE** de la table.

2. **Il n'existe aucune normalisation globale du téléphone.** Le seul normalisateur du projet vit
   dans le domaine de la roue : `app/Services/Wheel/WheelService.php:116-125` (`normalizePhone` :
   chiffres seuls, `33XXXXXXXXX` → `0XXXXXXXXX` — forme **nationale FR**, pas E.164) et
   `:145-158` (`phoneVariants` → `[0XXXXXXXXX, XXXXXXXXX, 33XXXXXXXXX, +33XXXXXXXXX]`).
   **Tous les autres lookups comparent la chaîne brute** : `GuestSignupController:230`,
   `SignupController:82`, `LoyaltyController:143`, `:490`, `:820`. Seule exception cosmétique :
   `LoyaltyController:79` retire espaces et tirets.
   `App\Support\PhoneDisplay:38-49` n'est **pas** un normalisateur — c'est un filtre de sortie qui
   masque les sentinelles `PENDING_*`.

3. **Mesure réelle sur les 346 comptes de cette base :**

   | Forme | n |
   |---|---|
   | `0X…` sur 10 chiffres (forme normalisée) | **284** |
   | 9 chiffres sans le zéro | **21** |
   | `+33` / `33…` | **6** |
   | `PENDING_*` (sentinelle) | **30** |
   | autre | **5** |

   → **32 comptes réels portent une forme non normalisée.** Une recherche naïve sur la chaîne
   brute ne les trouvera pas.

   Et surtout : **5 numéros de téléphone sont portés par PLUS D'UN compte**, dont un par
   **5 comptes différents**. Rien ne l'empêche en base. Le `->first()` de
   `LoyaltyController::check:80` en désignera un **arbitrairement** — donc créditera ou débitera
   potentiellement le mauvais solde.

⚠️ Piège technique à connaître : **`BranchScope` ne filtre JAMAIS le modèle `User`** —
`app/Models/Scopes/BranchScope.php:21-23` (`if ($model instanceof User) return;`, pour éviter une
récursion avec Sanctum). Tous les `withoutGlobalScope(BranchScope::class)` sur `User` sont donc
**décoratifs** ; seul `withTrashed()` change réellement le résultat.

---

## 8. Le scan par la caméra

### 8.1 Le verdict, sans détour : **aucun lecteur par caméra n'existe dans ce projet**

Preuve négative, vérifiée sur cinq niveaux :

| Vérification | Résultat |
|---|---|
| `getUserMedia`, `mediaDevices`, `enumerateDevices`, `videoinput` dans tout le source | **0 occurrence** |
| `BarcodeDetector` natif, `jsQR`, `@zxing/*`, `quagga`, `html5-qrcode`, `qr-scanner`, `instascan`, `vue-qrcode-reader`, `react-qr-reader`, `expo-camera`, `expo-barcode-scanner`, `react-native-camera`, `vision-camera` | **0 occurrence** |
| `package.json` — `dependencies` ET `devDependencies` | **aucune bibliothèque de scan** |
| `package-lock.json` + `node_modules` | **aucun paquet correspondant**, même en transitif |
| bundles compilés `public/js/*.js` | `getUserMedia` = **0** |

Faux ami à écarter : les occurrences de `createBarcodeDetector` ne sont **pas** l'API navigateur.
C'est un homonyme maison — un écouteur clavier. Voir §8.3.

### 8.2 Le récepteur serveur, lui, est COMPLET — et n'est branché sur rien

`POST /api/frontend/loyalty/scan` (`routes/api.php:1798-1800`,
`LoyaltyController.php:698-909`) est entièrement écrit : validation
(`method: qr|nfc`, `raw_data` ≤ 512 caractères, :721-724), vérification HMAC, anti-rejeu, rejet du
format en clair, réponse **toujours HTTP 200** avec `error_code` pour ne jamais casser un parcours
(:684-686). NFC → `nfc_not_provisioned` (:737-742).

**Aucun composant d'interface ne l'appelle.** `grep raw_data` sur `resources/` et `mobile/` = 0.
Les seuls appelants sont des tests (`tests/Feature/LoyaltyScanRequiresKioskMachineTest.php:67`,
`tests/Feature/Sentinels/LoyaltyQrSigningSentinelTest.php`, deux specs e2e en appel API direct).

Autrement dit : **le récepteur est prêt, l'émetteur n'a jamais été écrit.**

### 8.3 Ce qui existe en entrée aujourd'hui

**Douchette USB (clavier émulé), déjà câblée à la caisse** —
`resources/js/helpers/posBarcode.js:2` (« POS barcode (HID keyboard wedge) »), `:13-44` :
`window.addEventListener('keydown')`, heuristique d'écart ≤ 50 ms, longueur minimale 6,
terminateur `Enter`. Aucune caméra, aucun flux vidéo. Branché dans
`PosComponent.vue:1878` (import), `:2832` (montage), `:4514-4522` (`onBarcodeScanned` →
`item/lookupByBarcode`), route `routes/api.php:807`. Testé : `tests/js/posBarcode.spec.js`.

C'est le bon squelette d'intégration — mais il suppose une douchette, que le propriétaire n'a pas.

**Saisie manuelle sur la borne** — `KioskLoyaltyComponent.vue:22-76` : un `<input>` + clavier
virtuel + bouton « Vérifier », `:530-536` → `POST frontend/loyalty/check`. Les événements nommés
`loyalty_scanned` / `loyalty_scan` sont des noms d'événements de mesure d'audience, **pas du scan**.

### 8.4 La GÉNÉRATION de QR, elle, existe et est solide

- `composer.json` : **`simplesoftwareio/simple-qrcode ^4.2`** — disponible côté serveur
- `app/Http/Controllers/Frontend/LoyaltyController.php:927-969` — jeton signé, TTL 300 s
- `app/Services/DiningTableService.php:22,98,134` — QR PNG par table
- `app/Http/Controllers/Admin/Wheel/WheelCounterController.php:9,80,122` — QR SVG (roue)
- `app/Services/Hardware/EscPosCommandBuilder.php:250,269` — **QR NATIF sur l'imprimante thermique**
  (`qrCode($data, $moduleSize, $ecc)`), déjà utilisé par
  `app/Services/Promo/PromoFlyerEscPosRenderer.php:163`

Ce dernier point est précieux : **la caisse sait déjà imprimer un QR sur un ticket.** Il n'y a
aucun paquet npm de QR côté client — tout passe par PHP.

### 8.5 Zone limite : la caméra de l'OS est déjà sollicitée ailleurs

Pas du scan de code, mais le précédent d'accès matériel :
- `resources/js/components/admin/uber/UberPhotoCaptureComponent.vue:14-21` —
  `<input type="file" accept="image/*" capture="environment">`, « 📷 Photographier le ticket »
  → `:223` `POST admin/uber/photo/scan` → reconnaissance par IA côté serveur
- `resources/js/daily-book/DailyBookApp.vue:52` — même motif `capture="environment"`

Donc le sélecteur natif de caméra est un motif **déjà accepté** dans ce projet. Le flux vidéo
continu (`getUserMedia`), lui, n'a jamais été utilisé.

### 8.6 Faux amis à ne pas confondre avec un scanner

- `mobile/screens-modals.jsx:207-220` — « Scanne le QR au dos de ta carte » + bouton « Activer
  l'appareil photo ». Le gestionnaire (`mobile/index.html:343`) **ferme la fenêtre et affiche un
  message de succès**. Maquette complète, aucune caméra.
- `mobile/components/LoyaltyQR.jsx:7` — commentaire explicite : l'élément historique a été
  supprimé car « il ne scannait rien ».
- `mobile/vendor/qrcode.js` — générateur pur (Kazuhiko Arase), le seul `decode` du fichier est un
  base64 interne.
- `KioskIdleScreenComponent.vue:70,381` — le seul `<video>` du projet : boucle d'attractivité en
  veille. Pas de `srcObject`.
- `stock/scan-rupture/*` — « scan » au sens balayage de base de données.

---

## 9. Zones GELÉES sur le chemin (CLAUDE.md §7)

État vérifié : `git status --porcelain` sur les fichiers gelés → **vide, tout est propre**.

| Fichier gelé | Sur le chemin du besoin ? | Pourquoi |
|---|---|---|
| `app/Services/Fiscal/ZReportService.php` | **LECTURE SEULE — ne pas toucher** | C'est lui qui nette la TVA d'un rachat (:811). Le besoin ne demande **aucune** modification : le netting est déjà prouvé correct. Un plancher à 1000 points ne change rien ici. |
| `app/Services/Pricing/PricingService.php` | **LECTURE SEULE** | Formule de total (:351). Le rachat caisse la recopie hors zone gelée (`PosRedemptionService:236-240`) — motif déjà validé, **à conserver**. |
| `app/Services/Pricing/DiscountCalculator.php` | ⚠️ **à surveiller** | C'est ici que le plancher est appliqué pour la borne/web (:58-65). Si on veut un plancher unique pour les trois surfaces, la tentation sera d'y toucher. **Préférer** ajouter le contrôle dans `PosRedemptionService` (non gelé) et laisser ce fichier intact. |
| `resources/js/components/admin/pos/PaymentComponent.vue` | **à éviter** | Si l'on veut le bouton « utiliser les points » **dans l'écran de paiement**, on tombe dessus. La solution existante contourne déjà le problème : le rachat se fait **avant** le paiement, depuis un habillage séparé (`PosLoyaltyRedeemModal.vue`, hors gel). **Réemployer ce détour, pas le rouvrir.** |
| `public/js/pos-wizard.js` + `public/css/pos-wizard.css` | **à éviter** | Chargés par `resources/views/master.blade.php:344` **et** `admin-pos-v4.blade.php:136`. Si le bouton de fidélité doit vivre dans la fenêtre du wizard, c'est une zone gelée → **LOCK + accord explicite du propriétaire**. |
| `resources/views/admin-pos-v4.blade.php` | **à éviter** | Sert `/admin/pos-v4` (`routes/web.php:110-113` → `AdminPosV4Controller:32`). Ne rien y ajouter. Noter que `master.blade.php` (la SPA `/admin/pos`) **n'est PAS gelé**. |
| `app/Services/Fiscal/FiscalSequenceService.php`, `AuditLogService.php` | non concernés | Aucun besoin ne les touche. |
| `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` | non concerné | |
| `app/Models/Scopes/BranchScope.php`, `IdempotencyKeyMiddleware.php` | non concernés | Le motif « bypass + contrôle explicite après lecture » est déjà écrit hors gel dans `PosLoyaltyController:45-56`. **À copier, pas à modifier.** |

**Conclusion sur le gel** : correctement mené, ce besoin se réalise **sans toucher une seule
zone gelée**. Le précédent de 2026-05-19 (`LOCK_POS_LOYALTY_REDEEM_UI`) a justement établi la
recette : contrôleur dédié plutôt que `PosController`, habillage Vue séparé plutôt que
`PaymentComponent`. La seule chose qui forcerait un LOCK serait de vouloir le bouton **dans**
`pos-wizard.js`. À nommer comme tel si c'est la demande.

---

## 10. Les trois pièges que j'anticipe

### Piège n°1 — Le QR signé est à USAGE UNIQUE : « scanner » deux fois échoue

Le propriétaire dit : « le scan doit mener directement à ajouter OU utiliser ses points ». Le
réflexe naturel serait de scanner une fois pour ajouter, puis de rescanner pour utiliser.

**Ça ne marchera pas.** `LoyaltyQrSigner::verifyAndConsume` (:140-157) insère le `nonce` dans
`loyalty_qr_nonces_consumed` dont l'index est `UNIQUE` (migration :63). Le **deuxième** appel sur
le même QR affiché lève `qr_replay` (:154) → le client reçoit `ok=false` et paraît « inconnu »,
alors que son compte est parfaitement valide. Le caissier conclura à un bug.

Deuxième couche du même piège : le QR ne vit que **300 secondes** (`ttl_seconds = 300`), tolérance
30 s. Un client qui affiche son QR dans la file et arrive au comptoir 6 minutes plus tard obtient
`qr_expired`.

Ce que ça impose : **un scan = une résolution d'identité, tenue en mémoire par l'écran de caisse**
pour toute la durée de la commande, avec ensuite « ajouter » et « utiliser » agissant sur
l'identité déjà résolue. Pas deux scans. Et un repli manuel (code ou téléphone) obligatoire,
parce que l'expiration arrivera en salle.

### Piège n°2 — Un compte créé au comptoir ne peut PAS afficher de QR

C'est le piège qui casse le besoin n°4 dans le cas d'usage **le plus fréquent** : le client qui
vient de s'inscrire à la caisse.

Chaîne de faits, chacun vérifié :
1. Un QR scannable doit être **signé** — `loyalty.qr.accept_legacy_plaintext = false` (valeur
   effective), donc `FK:<code>` en clair est **refusé** (`LoyaltyController.php:790-798`).
2. Seul `POST /api/frontend/loyalty/qr` frappe un jeton signé, et il exige `auth:sanctum` —
   c'est-à-dire **le client connecté sur son propre téléphone** (`routes/api.php:1751`).
3. Or `register` crée le compte avec `password = bcrypt(uniqid())` (`LoyaltyController.php:180`),
   un mot de passe aléatoire que **personne ne connaît**, et `email = null` forcé (:177, décision
   de sécurité assumée : ne jamais lier un e-mail non vérifié).

Donc : compte créé au comptoir → le client ne peut pas se connecter → il ne peut pas frapper de
QR → **il n'y a rien à scanner**.

Et le propriétaire dit précisément : « des clients ont un compte sur le site ». Or **le site n'a
aucune page fidélité** : `grep -rliE "loyalt|fidel" resources/js/components/frontend/` hors borne
= **0 résultat** (§6.5). Un client connecté sur le site **ne voit pas ses points et n'a aucun QR
à afficher**. La seule surface qui affiche un QR fidélité est l'**application mobile**
(`mobile/components/LoyaltyQR.jsx`), dépôt séparé.

Autrement dit : aujourd'hui, **presque personne ne peut présenter un QR à scanner**.

Trois issues existent déjà dans le projet, sans rien inventer : le repli par téléphone
(`check:76-81`), ou **imprimer le QR sur le ticket** — la caisse sait le faire nativement
(`EscPosCommandBuilder.php:250`) — ou assumer que le code à 8 caractères se saisit à la main.
À trancher avec le propriétaire, mais **le scan ne peut pas être le chemin unique, et il ne peut
même pas être le chemin principal en V1**.

### Piège n°3 — Le plancher qu'il demande ne mordra pas là où il compte l'utiliser

Le propriétaire veut « pas de dépense avant 1000 points ». Il va le régler dans
`LoyaltySetupComponent.vue` — la valeur 1000 est légale (`LoyaltySetupRequest.php:19`,
`max:10000`) — et croire l'affaire réglée.

Résultat réel : la **borne** refusera sous 1000 (`DiscountCalculator.php:58-65`), le **site**
refusera sous 1000 (`LoyaltyController.php:402-408`), et la **caisse acceptera 100 points pour
1 €** — parce que `PosRedemptionService` ne lit jamais ce réglage (`grep min_redeem` → aucune
occurrence).

Le défaut est aujourd'hui **masqué** : plancher 50 < taux 100, et la règle « multiple du taux »
impose déjà 100 au minimum sur les trois surfaces. Personne ne l'a vu. Le jour où le propriétaire
relève le plancher, la caisse devient la porte de sortie — c'est-à-dire précisément la surface
qu'il veut contrôler.

Et un second effet, plus sournois : `LoyaltyController::config` (:537-539) publie le plancher
**EFFECTIF** (premier multiple du taux ≥ réglage), sentinelle
`tests/Feature/Frontend/LoyaltyConfigEffectiveFloorTest.php:45-74`. Avec plancher 1000 et taux
100, il publiera bien 1000. Mais un écran de caisse qui lirait `loyalty_min_redeem_points`
directement en base au lieu de passer par `/config` afficherait le réglage brut et **divergerait
du chiffre annoncé au client**. Le défaut de 2026-08-05 (« utilisables dès 50 points » affiché à
un client refusé au comptoir) se rejouerait à l'identique, dans l'autre sens.

---

## 11. Ce qui MANQUE réellement

### Manque de SURFACE (l'essentiel — le moteur existe)

1. **Écran caisse : identifier le client** (téléphone / code / QR) et le rattacher à la commande
   via `customer_id` ou `loyalty_customer_code`, déjà acceptés par `PosOrderRequest.php:110,215`.
   **C'est le point n°1** : c'est ce qui débloque le GAIN de points en caisse (§4.4), et c'est la
   cause mécanique des 2 lignes `earn` de surface `pos` en base.
2. **Écran caisse/admin : créer un compte** — enveloppe autour de `WheelAccountService::ensure`
   (§7.2), pas autour de `LoyaltyController::register` (§7.3).
3. **Écran caisse/admin : ajouter des points** — enveloppe autour de `addPoints`, aucun appelant
   côté interface aujourd'hui.
4. **Lecteur de QR par caméra** — **à écrire de zéro** (§8.1). Le récepteur serveur est prêt et
   inutilisé depuis mai.
5. **Écran admin : voir le grand-livre d'un client.** Les 43 lignes de `loyalty_transactions` ne
   sont exposées que par `history`, **au client lui-même via l'app mobile**. Le gérant n'a aucune
   vue — ni pour un litige, ni pour un contrôle.
6. **Consultation du solde AVANT validation dans la fenêtre de rachat** — aujourd'hui le caissier
   tape le nombre de points à l'aveugle (§6.2 C).
7. **Rendre les écrans atteignables.** Deux modules nécessaires au besoin sont **masqués du menu**
   par `resources/js/config/v1-hidden-modules.js` : `'settings.loyalty-setup'` (:24) et
   `'customers'` (:12). Le réglage du plancher que le propriétaire veut porter à 1000 **existe** et
   **fonctionne**, mais n'a aucun chemin de navigation — il faut connaître l'URL.

### Manque de LOGIQUE (des trous réels, tous petits)

8. **Le plancher en caisse** — `PosRedemptionService` doit lire `loyalty_min_redeem_points`
   (piège n°3). Quelques lignes, fichier **non gelé**, mais **exige une sentinelle** : aucun test
   actuel ne couvre ce cas, c'est précisément pourquoi il est passé inaperçu.
9. **La recherche par téléphone au rachat caisse** — `PosLoyaltyRedeemRequest:31` accepte un
   téléphone et l'écran l'annonce (« Code fidélité **ou téléphone** »,
   `PosLoyaltyRedeemModal.vue:68`), mais `PosRedemptionService:117` ne cherche que sur
   `loyalty_code`. **L'interface promet ce que le serveur ne sait pas faire.**
10. **Une normalisation du téléphone partagée.** Le seul normalisateur du projet est enfermé dans
    le domaine de la roue (`WheelService:116-158`). Tous les autres chemins comparent la chaîne
    brute. Il faut l'emprunter, pas en écrire un troisième.
11. **Le crédit de la roue n'écrit pas au grand-livre** (`WheelDeliveryService:291-292`, 0
    occurrence de `LoyaltyTransaction`). L'historique est donc **déjà incomplet**. À corriger si
    l'on veut s'appuyer sur cet historique pour arbitrer, et surtout à ne pas reproduire.
12. **Un `loyalty_code` généré sans gestion de collision**, motif copié trois fois
    (`LoyaltyController:102,215,943`) plus deux fois ailleurs (`GuestSignupController:293-296`,
    `WheelAccountService:166-169`). Le bon moment pour centraliser, si on y touche.

### Manque d'INFRASTRUCTURE

13. **`users.phone` n'a AUCUN index et n'est PAS unique** — alors que c'est le moyen
    d'identification **préféré** du propriétaire. Index réellement présents :
    `PRIMARY`, `users_loyalty_code_unique`, `users_branch_nfc_uid_unique`, `idx_users_deleted_at`,
    `idx_users_email`. **Rien sur `phone`.**
    - Coût de lecture : balayage complet (346 lignes → indolore aujourd'hui, lent à terme).
    - **Coût de justesse, lui, est immédiat** : mesuré sur cette base, **5 numéros sont portés par
      plus d'un compte, dont un par 5 comptes**. Le `->first()` de `LoyaltyController::check:80`
      en désignera un arbitrairement → risque de créditer ou débiter **le mauvais solde**.
    - Et **32 comptes portent une forme non normalisée** (21 sans le zéro, 6 en `+33`, 5 autres) :
      une recherche sur la chaîne brute ne les retrouvera pas.

### Ce qui NE manque PAS — et qu'il faut se retenir de réécrire

- Le **rachat en caisse** : service, contrôleur, permission, requête, fenêtre Vue, 7 tests, tout
  est là et éprouvé (§2.2, §2.3, §6.2).
- Le **grand-livre** et ses réparations (remboursement par porteur, reprise au remboursement,
  récupérateur d'orphelins) — §2.1.
- La **sûreté fiscale** du rachat : démontrée, signée, chaîne vérifiée (§5.4). **Rien à faire.**
- Le **découplage** remises / fidélité : réel, vérifié, verrouillé par sentinelles (§3.3).
- Le **rattachement du client à une commande de caisse** : `customer_id` et
  `loyalty_customer_code` sont déjà acceptés de bout en bout (§4.4).
- La **génération de QR**, y compris **sur le ticket thermique** (`EscPosCommandBuilder:250`).
- L'**écran d'inscription de la borne**, directement transposable (§6.4).
- Le motif **« bypass de scope + contrôle explicite après lecture »** (`PosLoyaltyController:45-56`) :
  à copier, jamais à modifier.

---

## 12. ⚠️ AVERTISSEMENT — une AUTRE session écrit sur cette branche en ce moment

Constat fait pendant l'inventaire, à signaler avant toute écriture de code.

Au démarrage de cette session, l'arbre de travail comptait **4 fichiers modifiés**
(`public/css/daily-book.css`, `public/js/daily-book.js`, `public/js/vendor.js`,
`reports/antigravity/playwright-latest.json`). À la fin, il en compte **une quarantaine de
modifiés et une soixantaine de non suivis** — chantier « capture photo Uber » + impression
cuisine : `app/Services/Uber/**`, `app/Http/Controllers/Admin/UberPhotoCaptureController.php`,
`database/migrations/2026_08_10_090000_create_uber_ticket_captures_table.php`,
et aussi **`CLAUDE.md`, `SYSTEM_MAP.md`, `routes/api.php`,
`resources/js/languages/fr.json`, `resources/js/router/index.js`**.

Preuve par horodatage, et **elle touche directement cet inventaire** :
`app/Http/Requests/PosOrderRequest.php` a été réécrit à **21 h 09 : 30**, soit **pendant** ma
lecture. Ses numéros de ligne ont bougé sous mes yeux : `customer_id` est passé de **159 à 110**,
`loyalty_customer_code` de **258 à 215**. J'ai revalidé et corrigé ces deux citations (§4.4, §11).
`PosComponent.vue` et `OrderService.php` ont été revérifiés et n'ont pas bougé.

Trois conséquences pratiques :

1. **Les `file:line` de ce document sont vrais au 2026-08-10 vers 21 h 10.** Sur les fichiers que
   l'autre session touche — `routes/api.php`, `fr.json`, `PosOrderRequest.php`,
   `resources/js/router/index.js` — **les revérifier avant d'éditer**, pas les recopier.
2. Deux chantiers se croisent sur des fichiers partagés : `routes/api.php` (où irait une route de
   fidélité caisse), `fr.json` (où iraient les libellés), `resources/js/router/index.js` (où irait
   un écran admin). **Risque de conflit direct.**
3. La mémoire du projet a déjà nommé ce cas : « DEUX sessions sur la même branche = HOLD deploy ».
   **Ne rien pousser, et se coordonner avant d'écrire dans les fichiers ci-dessus.**

À noter enfin que `CLAUDE.md` lui-même est modifié et non commité : la version lue au démarrage de
toute nouvelle session pourrait ne pas être celle du dépôt.

---

*Fin de l'inventaire. Je n'ai créé qu'un seul fichier — ce rapport. Aucun fichier existant
modifié, aucune base touchée, aucun test exécuté. Les autres modifications visibles dans
`git status` ne sont pas les miennes (§12).*
