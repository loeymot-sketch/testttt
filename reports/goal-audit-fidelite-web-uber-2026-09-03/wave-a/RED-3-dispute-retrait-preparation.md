# RED-3 — Contestation adverse de A3 et A4

Lecture seule. `testttt`, HEAD `a91f95e2e`, base `foodking_e2e` (`.env:14`). Chaque contestation = requête que j'ai exécutée ou `fichier:ligne` que j'ai lu.

---

## 1. « `tracking_token` NULL sur 253/253 web » — **NUANCÉE**

Mesure reproduite à l'identique :

```
source_surface   n     with_token
pos            1823      8
kiosk          1277     46
web             253      0
```

Le chiffre est juste. **L'angle est faux.** Autre requête, autre axe — la date :

```
source_surface  first                last                 n     tok
web             2026-06-13 16:23:29  2026-08-14 13:49:33   253    0

-- toutes surfaces, créées APRÈS 2026-08-16 :
pos 8/8 · kiosk 46/46 · phone 1/1 · NULL 1/1   → 56/56 = 100 %
```

La dernière commande web date du **2026-08-14** ; le hook est du **2026-08-16** (`Order.php:190`, `FrontendOrder.php:45`). Aucune commande web n'a été créée depuis. Le « 0/253 » ne mesure pas le canal web, il mesure le calendrier.

Qui remplit : les deux hooks `creating`, un par classe. Le chemin web est `FrontendOrderService.php:349` → `FrontendOrder::create()` — **exactement la même classe et le même appel que le kiosk**, qui affiche 46/46. Le canal web est donc démontré fonctionnel par transitivité : rien ne distingue les deux au moment du `creating`.

A3 écrit lui-même « hook du 2026-08-16, jamais rétro-appliqué » — c'est exact. Mais il réutilise ensuite le « NULL sur 253/253 » comme grief (§ MANQUE point 3a) contre la réutilisation de `tracking_token`. Ce grief-là tombe. Les deux autres tiennent (48 caractères intapables, QR ouvrant une page publique).

## 2. « Les points partent avant le retrait » — **NUANCÉE**

D'abord, je refuse le piège logique du brief : `OrderType::TAKEAWAY = 10` (`app/Enums/OrderType.php`). Une commande web à `order_type=10` **EST** TAKEAWAY, donc **entre bien** dans `$isKiosk` (`AwardLoyaltyPointsOnDelivery.php:41`). Il n'y a aucune contradiction. Lecture d'A3 exacte, et 245/253 commandes web sont bien en `order_type=10`.

Mesure indépendante — le grand livre :

```
-- earn joints aux commandes web
type    status  n
earn      13    2      ← DELIVERED, jamais PREPARED
redeem     1    3

-- les 2 commandes web actuellement à PREPARED(8)
id     status  loyalty_points_awarded  loyalty_customer_code
5935     8            NULL                    NULL
6038     8            NULL                    NULL

-- 253 web, 12 seulement portent un code fidélité
```

**Aucune commande web n'a jamais été créditée à `PREPARED`.** Le risque est réel en code, nul en fait, pour une raison qu'A3 n'a pas vue : `award()` ne trouve pas de porteur (`:107-116`) et **relâche la sentinelle** — `:117-123` la repasse à `NULL`. Donc pour les 95 % de commandes web sans identité fidélité, le crédit anticipé ne consomme rien.

Conséquence directe : l'avertissement d'A3 (« § chemin le plus court », étape 4 : « la sentinelle aura déjà consommé le crédit à PREPARED ») est **faux dans le cas général** et vrai seulement pour un client identifié. Le point « le plus subtil du chantier » est plus étroit qu'annoncé.

## 3. « Aucune route ne consomme un QR pour muter une commande » — **CONFIRMÉE**

Cherché autrement. `grep BarcodeDetector resources/js --include="*.vue"` → 2 fichiers, dont un qu'A3 ne cite pas : `PosComponent.vue:3516`. Son handler `:5693-5703` fait `item/lookupByBarcode` → modale de variation. Produit, pas commande. Côté POST admin : `routes/api.php:1255` `counter-collect/{order}/confirm` mute bien une commande, mais sur un `{order}` lié par l'URL depuis un clic de liste, pas sur un code scanné ; `uber/photo/scan` construit une `UberTicketCapture`. **Je concède.**

Un correctif à la proposition d'A3 (§ chemin le plus court, étape 5) : « le lecteur physique se branchera en émulation clavier — zéro code supplémentaire » est démenti par `resources/js/helpers/posBarcode.js:26-34`. Le détecteur est posé sur `window` en phase CAPTURE et **abandonne si la cible est un `INPUT`**. Champ de code focalisé → le scan tombe dedans, parfait. Modale non focalisée → le wedge avale le scan et déclenche un lookup produit, aboutissant à l'alerte `pos.barcode_not_found` (`PosComponent.vue:5698`). Ce n'est pas « zéro code ».

## 4. « `order_type=30` sur 1338 lignes, donnée non contrainte » — **NUANCÉE, origine RÉFUTÉE**

L'enum est bien limité à 5/10/15/20/25, je l'ai relu. Mais l'origine invoquée (dérive de canal) est fausse :

```
order_type=30 → source_surface='pos', status=13, 1338 lignes, 1 SEUL user_id,
                du 2026-05-28 18:01 au 2026-06-08 10:24
```

Source trouvée : `app/Console/Commands/E2ESoakCommand.php:970` et `:1051` — le générateur de charge **du dépôt lui-même** poste `'order_type' => 30` sur `POST /api/admin/pos`. Ce sont 1338 artefacts de banc, pas une dérive de production.

Le trou sous-jacent, lui, existe — et il est plus grave qu'A3 ne l'a formulé : `PosOrderRequest.php:145` `'order_type' => ['required','numeric']`, **sans `Rule::in`**, et le bloc conditionnel `:297-311` ne teste que DINING_TABLE / DELIVERY / TAKEAWAY / vide. `30` traverse intégralement. Or `order_type` décide du déclencheur fidélité (`:41`) **et** de la colonne monétaire lue (`:137-141`, `total` vs `order_amount`). Bonne conclusion, mauvaise cause.

## 5. « La valeur en base est 30, pas 15 » — **CONFIRMÉE**

```
id  key                                payload                          group
40  order_setup_food_preparation_time  {"$cast": null, "$value": "30"}  order_setup
```

Chaîne de lecture vérifiée bout en bout : `SettingController.php:23` → `SettingService::list()` qui fait `array_merge(..., Settings::group('order_setup')->all())` (`:16`) → `SettingResource::__construct` pose `$this->info` (`:16-19`) → `:58` lit cette clé. C'est bien **cette ligne**. Confirmé une seconde fois par la colonne estampée : `preparation_time=30` sur **238/253** commandes web. Aucune réserve.

## 6. « Rien après paiement en retrait » — **CONFIRMÉE, mais la cause donnée est secondaire**

Le gate existe : `frontend/components/OrderStatusComponent.vue:37-41`, `order_type == DELIVERY` sur les deux blocs. A3/A4 ont raison sur la lecture.

Mais c'est du code mort. `frontendRoutes.js:54-58` : `/my-orders/:id` porte `isFrontend: true`. `router/index.js:287-290` renvoie vers `/login` (ou le dashboard) **toute** route `isFrontend:true` absente de `STAFF_ONLY_FRONTEND_ALLOWLIST` — laquelle (`:76-88`) ne contient que des routes d'auth, `route.notFound` et `route.exception`. `.env:73 STAFF_ONLY_MODE=true`, et `config/features.php:50` vaut `true` par défaut, fail-secure. **Le client n'atteint jamais l'écran.** Le gate DELIVERY est un détail derrière une porte fermée.

Et la même porte emporte `/checkout` (`frontendRoutes.js:90-94`, `isFrontend:true`) — c'est-à-dire **le seul « OUI, affiché » du tableau d'A4**. Sa ligne « Checkout → OUI → 30 minutes » est inatteignable en V1.

Sur le suivi : je tranche contre l'hypothèse du brief. `/suivi/:trackingToken` est **volontairement exempté** des deux gardes (`orderTrackingRoutes.js:1-8`, `meta: { isTracking: true }` seul). Il n'est donc pas inatteignable par construction ; il l'est seulement pour les 253 commandes historiques, pour la raison chronologique du §1.

## 7. « Deux sources dynamiques fuient au client » — **(i) RÉFUTÉE · (ii) CONFIRMÉE**

**(ii) `WaitEstimateService` : confirmée.** `:90-91` → `OrderTrackingService.php:138-139` → `OrderTrackingPageComponent.vue:196-197` → rendu `:82`. Chaîne complète.

**(i) `preparation_time` : la fuite n'arrive pas où A4 le dit.** J'ai listé tous les consommateurs non-admin : 3 hits seulement.
- `CheckoutComponent.vue:102` — lit le *réglage*, pas la colonne, et route bloquée (§6).
- `frontend/components/OrderStatusComponent.vue:42` — route bloquée (§6).
- `table/components/OrderStatusComponent.vue:30` — **jamais mentionné par A4**.

Surtout : `OrderTrackingService.php:129-141` retourne `found/queue_number/status/status_label/step/position_ahead/almost_ready/ready/wait_low/wait_high/server_time` — **pas `preparation_time`** ; `grep -c preparation_time` sur la page de suivi renvoie **0**. Le commentaire `OnlineOrderController.php:171-172` (« OrderDetailsResource:89 la ship déjà au suivi client ») est **faux**, et A4 en a repris la prémisse sans vérifier la charge utile. Les 15/25/40 min du caissier n'atteignent jamais `/suivi`.

En revanche, la seule surface client où `preparation_time` **arrive vraiment** est celle qu'A4 a manquée : `tableOrderRoutes.js:48-53`, `/table-order/:slug/:id`, `auth: false` et **sans `isFrontend`** — donc survivante du garde staff-only — dont `OrderStatusComponent.vue:30` affiche `{{ preparation_time }} min` **sans aucun gate DELIVERY**.

---

## Verdict

**Réfutées ou nuancées : 5 sur 7** (§1, §2, §4, §6, §7-i). Confirmées : §3, §5. Confirmée avec cause corrigée : §6.

**LE défaut que ni A3 ni A4 n'ont vu.**

En V1 (`STAFF_ONLY_MODE=true`), le réglage `order_setup_food_preparation_time` **n'atteint aucun écran client accessible**. Ses deux consommateurs Vue vivent derrière des routes `isFrontend:true` que `router/index.js:288` renvoie vers `/login`. Deux surfaces client seulement franchissent le garde :

- `/suivi/:token` → affiche `wait_low/wait_high`, qui **ignorent totalement le réglage** ;
- `/table-order/:slug/:id` → affiche la colonne `preparation_time` estampée, pas le réglage.

Conséquence : la proposition scope-minimal d'A4 — ajouter `order_setup_food_preparation_time_max` et rendre « 15–25 min » — serait **invisible pour 100 % des clients**. Le propriétaire demande « afficher 15 min au client » ; le seul levier qui atteigne un client sur ce backend est `WaitEstimateService::TIERS` (`:40-44`), précisément le fichier qu'A4 classe « ne pas toucher ». A4 a produit un plan complet sur une surface éteinte, et A3 a documenté un QR de suivi dont la page est le seul écran client encore vivant — sans qu'aucun des deux ne rapproche les deux faits.
