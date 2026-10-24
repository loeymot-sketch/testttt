# U3 — Socle Uber, KDS, déploiement (lecture seule, 2026-09-06)

Branche lue : `pos/category-first-caisse-2026-06-23`. Aucun fichier du produit modifié.
Aucune valeur de secret recopiée : `<défini>` / `<vide>`.

---

## 1. VERDICT — le socle OAuth est RÉUTILISABLE **en forme**, pas **en l'état**

`app/Services/Uber/UberClient.php:21-59` — mécanique du jeton :

- Lecture cache → `Cache::get('uber_eats_access_token')` (clé **constante privée**, `:18`).
- Sinon `POST` en `asForm()` sur `config('uber.token_url')` avec
  `grant_type=client_credentials`, `client_id`, `client_secret`, `scope` (`:36-41`).
- TTL = `expires_in` renvoyé, moins 60 s, plancher 60 s (`:49,53`). Défaut 3600 s si absent.
- Échec → `Log::warning` + `null`, jamais d'exception (`:42-45, 55-58`).
- **Renouvellement sur 401** : `authedGet`/`authedPost` font `Cache::forget(TOKEN_CACHE_KEY)`
  puis **un** retry (`:106-110`, `:126-130`).

C'est un flot `client_credentials` standard, et Uber Direct utilise le **même** point
d'entrée OAuth (`https://login.uber.com/oauth/v2/token`). Mais trois câblages en dur
interdisent de réutiliser la classe telle quelle :

1. `TOKEN_CACHE_KEY` est une **constante** (`:18`) : deux jeux d'identifiants
   (Marketplace + Direct) écriraient dans la **même** entrée de cache. Le jeton Direct
   écraserait le jeton Eats — panne silencieuse des deux côtés.
2. `accessToken()` lit `config('uber.client_id' | 'client_secret' | 'scopes' | 'token_url')`
   en dur (`:28-29, 36, 40`). Aucun paramètre, aucune injection.
3. Les méthodes métier (`fetchOrder`, `acceptOrder`, `denyOrder`, `storeStatus`, `:62-83`)
   sont Marketplace-only, et `url()` (`:85-92`) préfixe avec `config('uber.api_base')`.

**Conséquence pratique.** Ne pas toucher `UberClient` (mandat propriétaire). Extraire *ou*
recopier la mécanique du jeton dans un `UberDirectClient` séparé, avec :
`private const TOKEN_CACHE_KEY = 'uber_direct_access_token';` et lecture de
`config('uber_direct.*')`. Le gain réel de réutilisation est le **patron** (cache −60 s,
invalidation sur 401 + un retry, `null` au lieu d'exception), pas le code partagé. Un
refactor en client générique paramétré serait plus élégant mais toucherait le chemin Eats
en service — hors mandat.

⚠️ Les *scopes* diffèrent : Eats = `eats.store eats.order` (`config/uber.php:22`) ;
Uber Direct exige `direct.organizations`. **ABSENT** du dépôt : aucune trace de
`direct.organizations`, `customer_id`, `/v1/customers/`, `robocourier`, `X-Postmates-Signature`.

---

## 2. Configuration — conventions

`config/uber.php` (55 lignes, lu intégralement). Toutes les valeurs viennent de `env()` avec
un défaut littéral : `UBER_CLIENT_ID`, `UBER_CLIENT_SECRET`, `UBER_ORG_ID`, `UBER_STORE_ID`,
`UBER_TOKEN_URL`, `UBER_API_BASE`, `UBER_SCOPES`, `UBER_WEBHOOK_SECRET`, `UBER_FISCALIZE`,
`UBER_AUTO_ACCEPT`, `UBER_DENY_ON_OOS`, `UBER_BRANCH_ID`, `UBER_FALLBACK_ITEM_ID`.
Déclarées dans `.env.example:539-567`.

Convention du projet, mesurée :

- Un **intégrateur qui possède sa propre logique métier** obtient son **fichier de config
  dédié** : `config/uber.php`, `config/kiosk.php`, `config/fiscal.php`.
- `config/services.php` ne reçoit que des **jeux de clés plats** sans logique
  (`stripe.webhook_secret` `:73`, `fcm` `:57`, `openai` `:88`, `apple`/`google` `:117-123`).
- Préfixe d'environnement = nom du fournisseur en majuscules (`UBER_`, `FCM_`, `OPENAI_`).

**Sur la proposition du propriétaire.** `UBER_DIRECT_CUSTOMER_ID`, `UBER_DIRECT_CLIENT_ID`,
`UBER_DIRECT_CLIENT_SECRET`, `UBER_DIRECT_WEBHOOK_SIGNING_KEY` **collent** à la convention
(préfixe fournisseur + rôle). Deux ajustements recommandés :

- Le suffixe existant est `_SECRET`, pas `_SIGNING_KEY` (cf. `UBER_WEBHOOK_SECRET`,
  `STRIPE_WEBHOOK_SECRET`). Préférer **`UBER_DIRECT_WEBHOOK_SECRET`** pour l'homogénéité.
- Ne PAS ajouter ces clés dans `config/uber.php` : Uber Direct a sa propre logique métier
  (course, statuts livreur, pourboire) → **`config/uber_direct.php` dédié**, plus
  `UBER_DIRECT_TOKEN_URL`, `UBER_DIRECT_API_BASE`, `UBER_DIRECT_SCOPES` (défaut
  `direct.organizations`) et un interrupteur `UBER_DIRECT_ENABLED` **par défaut `false`**
  — le motif « rien n'appelle le réseau tant que l'owner n'a pas fourni la clé » est déjà
  celui de `services.php:88-95` (OpenAI Vision).

---

## 3. Webhooks entrants — le patron à imiter

Route : `routes/api.php:167-169`
`POST /api/webhooks/uber` → `UberWebhookController@handle`,
middleware `['installed', 'throttle:60,1']`, **public** (pas de `apiKey`, pas de Sanctum).
S'y ajoute le groupe `api` (`app/Http/Kernel.php:51-58` : `throttle:api`,
`SubstituteBindings`, `JsonMiddleware`, `CorrelationIdMiddleware`).

**Corps brut : PRÉSERVÉ.** `UberWebhookController.php:39` fait `$request->getContent()`
**avant** tout `json_decode`, et la signature est calculée sur cette chaîne (`:327`).
Vérifié qu'aucun intergiciel ne réécrit le corps : `JsonMiddleware` ne touche que les
en-têtes (`app/Http/Middleware/JsonMiddleware.php:19-28`) ; `Installed` ne fait qu'un test
de fichier (`Installed.php:18-30`) ; les globaux `TrimStrings` /
`ConvertEmptyStringsToNull` (`Kernel.php:28-29`) agissent sur `input()`, pas sur
`getContent()`.

**Signature** (`:316-330`) : HMAC-SHA256 hexadécimal de tout le corps, clé
`config('uber.webhook_signing_secret')` (repli sur `UBER_CLIENT_SECRET`,
`config/uber.php:26`), en-tête `X-Uber-Signature`, comparaison `hash_equals`.
**Fail-closed** : secret vide → 401 (`:319-322`). Uber Direct signe avec
`X-Postmates-Signature` — même algorithme, en-tête différent : à paramétrer.

**Idempotence** (`:57-86`) : table `webhook_events`, `UNIQUE (provider, webhook_id)`
(`database/migrations/2026_05_09_120000_create_webhook_events_table.php:83`, index
`uk_webhook_provider_id`), plus FK `order_id → orders.id` en `nullOnDelete`
(`2026_05_18_120000_add_webhook_events_order_id_fk.php:39-42`). Le contrôleur fait
`SELECT` puis `INSERT`, et **rattrape la violation d'unicité** en cas de course
(`:75-85`, `isUniqueViolation` `:296-304`) → 200 `already_processing`. Discriminant à
réutiliser : `provider = 'uber_direct'`.

**Politique de réponse** (`:132-147`) : erreur transitoire → **503** pour qu'Uber rejoue ;
au-delà de 5 tentatives → 200 + ligne `status=failed` conservée pour supervision. C'est le
comportement à copier tel quel : un 2xx prématuré perd une commande payée.

---

## 4. Adresse du restaurant — ELLE EXISTE, ne pas dupliquer

`SELECT * FROM branches WHERE id = 1` (exécuté ce jour) :

| colonne | valeur |
|---|---|
| `name` | Le Cayenne (principal) |
| `address` | 437 Rue Élie Gruyelle, 62110 Hénin-Beaumont |
| `city` / `zip_code` / `state` | Hénin-Beaumont / 62110 / Hauts-de-France |
| `latitude` / `longitude` | `50.4215667` / `2.9549060` |
| `phone` | `0365678291` |
| `siret` / `vat_intra` | `10417050100019` / `FR19104170501` |

Colonnes déclarées en `2022_11_17_110125_create_branches_table.php:18-26` (`latitude` /
`longitude` sont des `string` nullable). **L'adresse de retrait Uber Direct doit être lue
depuis `branches` (id 1), pas recopiée en configuration.** Les coordonnées sont correctes
(50.42 / 2.95 = Hénin-Beaumont).

⚠️ **Défaut annexe constaté** (hors périmètre, à signaler) : `branches.zone` contient un
polygone **parisien** (`{"lat":48.86,"lng":2.33}`…), à 200 km du restaurant. Toute logique
de zone de livraison qui lirait cette colonne serait fausse.

---

## 5. KDS et machine à états — où loger un statut de livraison

Statuts : `app/Enums/OrderStatus.php:7-15` — PENDING 1, ACCEPT 4, PREPARING 7,
PREPARED 8, OUT_FOR_DELIVERY 10, DELIVERED 13, CANCELED 16, REJECTED 19, RETURNED 22.
Transitions : `app/Domain/Order/OrderStateMachine.php:30-124` (**GELÉ**, lu seulement).

Arrivée au KDS : `KitchenDisplaySystemController@index`
(`app/Http/Controllers/Admin/KitchenDisplaySystemController.php:47-83`, droit
`permission:kitchen-display-system` `:44`) → `KitchenDisplaySystemOrderService::list()`,
qui filtre `whereIn('status', KitchenReleaseRule::visibleStatuses())`
(`KitchenDisplaySystemOrderService.php:80`). `visibleStatuses()` =
**ACCEPT, PREPARING, PREPARED** (`app/Domain/Kds/KitchenReleaseRule.php:16-23`). Repli
par sondage : `KdsSyncController@sync` (`routes/api.php:1772`).

Une commande Uber Eats entre par `UberOrderIngestor.php:138-142` :
`order_type` DELIVERY|TAKEAWAY, `source_surface = 'uber_eats'`, `status = ACCEPT`,
`payment_status = PAID` → visible cuisine immédiatement.

**Recommandation.** La table `orders` ne porte **aucune** colonne de suivi de course
(`SHOW COLUMNS FROM orders` : `delivery_boy_id` et rien d'autre). Le statut de livraison
Uber Direct doit vivre dans une **table dédiée**, pas dans `orders.status` :

- Précédent exact et validé dans le dépôt :
  `database/migrations/2026_08_10_090000_create_uber_ticket_captures_table.php:29-60` —
  domaine « NEUF, ADDITIF, HORS NF525 », `branch_id` indexé, cycle de vie propre
  (`status` varchar), lien nullable vers `orders`, idempotence portée par un UNIQUE en base.
- Donc : `uber_direct_deliveries` (`order_id`, `delivery_id`, `status` course, `tracking_url`,
  `courier_*`, horodatages, `raw_payload`), modèle avec `BranchScope` — la liste des 24
  modèles scopés est verrouillée par
  `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`, à mettre à jour dans le même geste.
- `orders.status` reste piloté par la cuisine ; la course avance en parallèle. Aucune arête
  nouvelle dans `OrderStateMachine` → zone gelée intacte, zéro LOCK.

---

## 6. Déploiement

**Accès** : `ssh lecayenne` fonctionne (clé `~/.ssh/lecayenne_prod`). Hôte
`/var/www/lecayenne`, PHP **8.1.2**, `php8.1-fpm`, MySQL 8.0.46, Node 18.20.8.
`git remote origin` = `https://github.com/loeymot-sketch/testttt.git`, **aucune** réécriture
`insteadOf` ; une clé `~/.ssh/gh_deploy` existe sur le serveur.
HEAD production ce jour : `a5720abe`, branche `pos/category-first-caisse-2026-06-23`.

**Variables d'environnement** : fichier unique `/var/www/lecayenne/.env` (5 003 o, propriétaire
`ubuntu`). Clés Uber présentes : `UBER_CLIENT_ID=<défini>`, `UBER_CLIENT_SECRET=<défini>`,
`UBER_WEBHOOK_SECRET=<défini>`, `UBER_STORE_ID=<défini>`, `UBER_TOKEN_URL=<défini>`,
`UBER_API_BASE=<défini>`, `UBER_AUTO_ACCEPT=<défini>`, `UBER_VISION_ENABLED=<défini>`.
`UBER_ORG_ID` **absent** du .env de production. `APP_ENV=staging`, `APP_DEBUG=false`,
`CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`.
Ajouter une variable = éditer ce `.env` ; comme la configuration n'est **pas** en cache
(`bootstrap/cache/config.php` ABSENT — vérifié), un `reload php8.1-fpm` suffit.

**`config:cache` — CONFIRMÉ, et le garde ne protège PAS cette machine.**
`app/Services/Fiscal/AuditLogService.php:324` lit
`env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` à l'exécution. Le garde existe bien
(`app/Providers/AppServiceProvider.php:381-396` : si la config est en cache et qu'une
surcharge déclarée devient illisible → `RuntimeException`) et
`config/fiscal.php:56-64` calcule la liste pendant que `.env` est encore lisible.
**Mais** tout le bloc est enfermé dans `if (app()->environment('production'))`
(`AppServiceProvider.php:190`) — or la production tourne en `APP_ENV=staging`
(`php artisan env` → « staging »). Le garde est donc **inerte sur la machine qui encaisse**,
et le .env de production déclare bien une surcharge (`grep -c '^FISCAL_AUDIT_SECRET_BRANCH_'`
= 1). Un `config:cache` y casserait la chaîne **sans être arrêté**.

Aggravant : `scripts/deploy/deploy.sh:288-289` exécute **toujours** `config:cache`, et
`reports/deploiement-2026-09-02/ETAT_REEL_DE_LA_PRODUCTION.md:68` le prescrit encore.
`docs/DEPLOIEMENT.md:1-16` porte l'avertissement.

**Procédure sûre (lecture des sources ci-dessus) :**
```
php artisan foodking:backup-daily
php artisan fiscal:verify-chain --all          # CHAIN OK AVANT
git fetch origin && git checkout <révision>
composer install --no-dev --optimize-autoloader
npm ci && npm run production                   # bundles construits SUR la cible
php artisan migrate --force
php artisan route:cache && php artisan view:cache     # ⛔ PAS config:cache
sudo systemctl reload php8.1-fpm nginx
php artisan fiscal:verify-chain --all          # CHAIN OK APRÈS
```
Si `bootstrap/cache/config.php` existe déjà : `rm bootstrap/cache/config.php` — **pas**
`php artisan config:clear`, qui démarre l'application (`AppServiceProvider.php:390-392`).
Contrôle de sortie : comparer l'empreinte du bundle **servi** à celle sur disque.

---

## 7. Tests

`tests/Feature/Uber/` existe **sur cette branche** (10 fichiers) :
`UberIntegrationTest`, `UberGoLiveHardeningTest`, `UberSelfAuditHardeningTest`,
`UberDenyOnOutOfStockTest`, `UberOrderMapperMeatLinesTest`, `UberOrderMapperNoteTest`,
`UberTicketCuisineEtCaisseTest`, `UberTicketOptionClassifierTest`,
`UberPhotoCaptureFlowTest`, `UberWebhookIngestorParityTest`.

**Patron réutilisable pour un webhook signé** — `UberGoLiveHardeningTest.php:50-66` :

```php
private function signedPost(array $payload)
{
    $body = json_encode($payload);
    return $this->call('POST', '/api/webhooks/uber', [], [], [], [
        'CONTENT_TYPE'          => 'application/json',
        'HTTP_X_UBER_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
    ], $body);
}

private function fakeUberApis(array $orderDetail): void
{
    Http::fake([
        'login.uber.com/*'                              => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
        'api.uber.com/v1/eats/orders/*/accept_pos_order' => Http::response(['ok' => true], 200),
        'api.uber.com/v1/eats/orders/*'                  => Http::response($orderDetail, 200),
    ]);
}
```

Le point clé : `$this->call(...)` avec **corps brut explicite** (et non `postJson`), sans
quoi la signature ne porte pas sur les mêmes octets que ceux vus par le contrôleur.
Trois cas de garde déjà écrits et à dupliquer côté Direct :
signature invalide → 401 (`UberIntegrationTest.php:37-46`) ; secret vide → 401 fail-closed
(`:49-58`) ; rejeu du même `event_id` → idempotent (`:61-80`). Ajouter le cas
« 401 invalide le cache et retente » (`UberGoLiveHardeningTest.php:188`).

---

## ABSENT / non mesuré

- Uber Direct : 0 ligne (config, service, route, migration, test).
- `UBER_ORG_ID` absent du `.env` de production.
- Aucun mécanisme de rotation des secrets Uber, aucune supervision automatique de
  `webhook_events status=failed` (le contrôleur `:140` la réclame en commentaire).
