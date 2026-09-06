# U2 — Parcours client & temps de préparation (avant Uber Direct)

Lecture seule. Chaque affirmation porte un `fichier:ligne` réellement lu.
Sonde production faite (GET public, lecture seule).

---

## 0. CORRECTION DE VOIE — le dépôt indiqué dans la mission est périmé

La mission désigne `/Users/1millnonstop/Downloads/web` comme « le site client ».
**C'est un leurre.** Preuves :

| | `Downloads/web` | `Downloads/lecayenne-web-deploy/Site lecayenne` |
|---|---|---|
| `git remote` | **aucun** | `github.com/loeymot-sketch/Site-lecayenne` (main) |
| dernier commit | 24 août (contenu 12 juil.) | `30cc82f`, 3 sept. 2026 |
| `funnel.jsx` | 46 Ko / 820 l. | **177 Ko / 2611 l.** |
| `api.js` | 28 Ko / 557 l. | **94 Ko / 1549 l.** |
| `compiled/` | absent | **présent** (22 fichiers) |
| `api-base-url` | `http://127.0.0.1:8766` | `https://vps-418872ac.vps.ovh.net` |

Tout ce qui suit porte sur **`~/Downloads/lecayenne-web-deploy/Site lecayenne`**
(noté `SITE/`). Le CLAUDE.md §3bis pointe encore `Downloads/web` : à corriger.

---

## 1. Le tunnel de commande

Routage **par hash**, pas de `react-router` : `SITE/racine.jsx:143`
`routeUrl = r => r==='home' ? pathname+search : '#'+r`. Écrans montés en
`racine.jsx:589-625` (`home | menu | orders | loyalty | avis | reseaux |
checkout | payment | confirm | track`).

Depuis le mandat « ONE-PAGE » du 2026-08-03, **checkout et paiement sont une
seule page** : `racine.jsx:608` monte `PaymentPage` pour `view==='checkout'`
**et** `view==='payment'`. `CheckoutSections` (`funnel.jsx:243`) est un
sous-composant rendu à l'intérieur.

Ordre réel de la page unique (`funnel.jsx:626` → `PaymentPage`) :
1. Mode À emporter / Livraison — `funnel.jsx:410-434`
2. Heure de retrait (créneaux) — `funnel.jsx:466-500`
3. Code promo — `funnel.jsx:505-522`
4. Note cuisine — `funnel.jsx:526-530`
5. **Tes coordonnées** (email → prénom/nom/téléphone → code OTP) — `funnel.jsx:1646-1740`
6. Paiement + CTA

**Total affiché** : `OrderSummary` (`funnel.jsx:159`), ligne « Livraison »
conditionnelle `funnel.jsx:222`, total `funnel.jsx:161`
(`subtotal − discount + deliveryFee`).
**Déclencheur du paiement** : `PaymentPage` → `placeRealOrder`
(`funnel.jsx:~988-1010`) → `api.placeOrder`.

### La livraison EXISTE DÉJÀ — et elle est éteinte par un drapeau

- `SITE/api.js:77` : `deliveryEnabled: metaContent('feature-delivery','0')==='1'`
- **`feature-delivery` est ABSENT de `SITE/index.html`** (métas présentes :
  `feature-online-card=1` l.69, `feature-wheel=0` l.74) ⇒ `deliveryEnabled = false`.
- Drapeau OFF ⇒ `funnel.jsx:428` : la tuile « Livraison » est un **lien externe
  Uber Eats** (`<meta name="uber-eats-url">`, `index.html:87`).
- Drapeau ON ⇒ `funnel.jsx:418` tuile native + `funnel.jsx:436-452` bloc adresse.

Le parcours « À emporter » **ne passe par aucun de ces branchements** : il est
gardé par `fulfillment === 'pickup'` (`funnel.jsx:456`, `:543`). Activer la
livraison ne peut donc pas le casser.

**Ce qui manque pour la demande owner** : l'adresse est **un seul champ libre**
(`funnel.jsx:440`, `placeholder="N° rue, code postal, ville"`). Pas de
complément, ni CP, ni ville, ni instructions séparés. Le **téléphone est déjà
collecté** au checkout (`funnel.jsx:1701`, `id="auth-phone"`). Il ne reste donc
qu'à éclater le champ adresse et ajouter complément + instructions.

---

## 2. Contrat d'API (`SITE/api.js`)

Base `https://vps-418872ac.vps.ovh.net` (`index.html:61`), en-têtes
`X-API-Key` + `Bearer` + `X-Idempotency-Key` (`api.js:119-125`).

| Appel | Chemin réel | api.js |
|---|---|---|
| `waitEstimate()` | `GET /api/frontend/order/wait-estimate?branch_id=1` | `:1200` |
| `placeOrder()` | `POST /api/frontend/order` | `:1193` |
| `getOrder(id)` | `GET /api/frontend/order/show/{id}` | `:1205` |
| `saveAddress()` | `POST /api/frontend/address` | `:203` |
| `deliveryQuoteForAddress()` | Nominatim OSM + haversine **local** | `:82`-ish |
| `checkCoupon()` | `POST /api/frontend/coupon/coupon-checking` | — |

**Payload de commande** (`api.js:1168-1192`) :
`branch_id, order_type (10=TAKEAWAY · 5=DELIVERY), source:5, is_advance_order
(5=YES/10=NO), payment_method, items (JSON), expected_total`, puis si livraison :
`address_id, delivery_distance_km, delivery_time ('ASAP'), delivery_charge`,
et `scheduled_at` si créneau choisi (`api.js:1190`).

**Total** : `expected_total` (`api.js:1181`) est un **témoin** ; le serveur
recalcule tout et rejette 422 si l'écart dépasse 1 centime (garde WEB-TOTAL-GUARD,
commentaire `api.js:1155-1164`).

**Champ adresse** : oui — `saveAddress` (`api.js:195-206`) envoie
`label, latitude, longitude, address (≤500), apartment?`.
⛔ **Ni téléphone, ni code postal, ni ville, ni instructions.** C'est le point
d'extension exact pour Uber Direct.

`SITE/apiContract.js` n'existe plus dans le dépôt déployé (il ne subsistait que
dans le leurre, en `ACTIVATED:false`, non chargé).

---

## 3. Construction & déploiement — le piège

**Le navigateur n'exécute PAS les `.jsx`.** `index.html:414+` charge
`compiled/*.js`. Procédure exacte (`SITE/tools/compile-jsx.mjs:1-22`) :

```
cd "~/Downloads/lecayenne-web-deploy/Site lecayenne"
node tools/compile-jsx.mjs            # recompile ce qui a changé
node tools/compile-jsx.mjs --check    # échoue si un compilé est périmé
node tools/check-asset-versions.mjs   # ENREGISTRE les empreintes, ne bumpe RIEN
```

Babel est résolu depuis le dépôt backend (`compile-jsx.mjs:38-40` :
`testttt/node_modules`), ce dépôt n'a pas de `node_modules`.

⚠️ **Deux pièges cumulés** :
1. `check-asset-versions.mjs` **ne bumpe pas** les `?v=` — c'est manuel dans
   `index.html`. Sans bump, un correctif est déployé et **inerte** pour tout
   client déjà venu.
2. `vercel.json` → `outputDirectory: "."`. `git push origin main` déclenche
   Vercel (~30 s). Le backend, lui, se déploie **à la main** sur le VPS :
   **sonder la prod avant de déployer un appel à une route neuve.**

---

## 4. Design réutilisable — rien à inventer

| Besoin | Composant / classe existant | Fichier:ligne |
|---|---|---|
| Champ étiqueté + erreur a11y | `.lcf-cardform-field` + `<label>` + `aria-invalid` + `aria-describedby` + `.lcf-field-error` | `funnel.jsx:1667-1675` (patron), CSS `styles-v4.css:294-323` |
| Champ + bouton d'action | `.lc-promo` (input + bouton) | `funnel.jsx:439-441`, CSS `styles-v2.css:455-468` |
| Zone de texte (instructions) | `.lc-notes` (190 car. + compteur) | `funnel.jsx:526-530`, CSS `styles-v2.css:470-477` |
| Tuile de choix radio | `.lcf-paymethod` (+ `.is-on`, `role="radio"`) | `funnel.jsx:412-433`, CSS `styles-v4.css:195-205` |
| Section de page | `.lcf-section` + `<h3>` | `styles-v4.css:72-74` |
| Bouton principal | `.lc-btn.lc-btn--ink` | `funnel.jsx:1710`, CSS `styles.css:288-297` |
| Message d'erreur | `.lcf-field-error` `role="alert"` | `styles-v4.css:556` |

**Recommandation** : les 5 champs livraison se calquent 1:1 sur
`.lcf-cardform-field` (patron `funnel.jsx:1679-1707`), qui porte déjà
`aria-invalid`/`aria-describedby`/`autoComplete`. Zéro CSS nouveau.

---

## 5. TEMPS DE PRÉPARATION — cartographie complète

### RÉPONSE À LA QUESTION DÉCISIVE : **ABSENT**

Aucune méthode serveur ne rend une **heure de prêt absolue**. Vérifié :
- grep vide sur `ready_at|ready_time|pickup_ready|estimated_ready` dans `app/`,
  `database/`, `routes/`, `resources/` ;
- schéma `orders` : `preparation_time` = **int minutes**
  (`database/migrations/2022_11_17_110810_create_orders_table.php:33`) ;
  `prepared_at` = **événement passé**, estampillé à la transition
  (`app/Models/Order.php:225-230`) ; `scheduled_at` = **demande client**, pas une
  prévision ;
- les 18 `addMinutes(` de `app/` servent tous à des TTL, des validations de lead
  time ou au créneau texte `delivery_time` — jamais à une heure de prêt.

Seul datetime prospectif du backend :
`app/Http/Resources/KDSOrderDetailsResource.php:57-61`
`kitchen_timer_anchor_iso = scheduled_at − leadMinutes` — **ancre de démarrage
cuisine pour commandes programmées**, `null` en ASAP. Pas un `pickup_ready_dt`.

### Les quatre sources, et leur nature

| Source | Nature | Valeur | Preuve |
|---|---|---|---|
| Réglage `order_setup_food_preparation_time` | durée (min) | **30 en base** (`settings.payload = {"$value":"30"}`), repli code **15** | lu par `app/Services/OrderService.php:428`, `:860`, `:1611`, `app/Services/FrontendOrderService.php:354` ; écrit par `app/Http/Controllers/Admin/OrderSetupController.php:32-40` |
| `WaitEstimateService` | **fourchette** de durées | paliers `[3=>[15,20], 5=>[20,25]]`, débordement `[25,30]` | `app/Services/WaitEstimateService.php:41-45` ; signature `estimate(int $branchId): array` `:56` ; retour `:84-94` |
| Caissier 15/25/40 | durée (min) | écrit `orders.preparation_time` **à l'ACCEPT seulement** | `app/Http/Controllers/Admin/OnlineOrderController.php:178-181` ; options `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:517-519` ; validation 5-120 `app/Http/Requests/OrderStatusRequest.php:56` |
| Littéraux du site | durée | replis seulement | `funnel.jsx:257` `'~15-20 min'`, `funnel.jsx:285` `|| 20`, `funnel.jsx:293` `'23:45'`, `screens-v3.jsx:169-171`, `screens.jsx:1376-1377` |

⚠️ Les deux systèmes sont **indépendants** : `WaitEstimateService` ne lit ni le
réglage ni `orders.preparation_time` — uniquement la longueur de file
(`WaitEstimateService.php:63-74`).

⚠️ Le site n'invente **plus** d'heure : `racine.jsx:53-61` a mis `slotTime: null`
par défaut, et `funnel.jsx:2261` affiche « Dès que prêt » plutôt qu'un chiffre.
**Ne pas régresser vers un 15 min fixe.**

### Sonde production (GET public, 2026-09-06 09:01)

```
GET https://vps-418872ac.vps.ovh.net/api/frontend/order/wait-estimate?branch_id=1
→ 200 {"queue_count":0,"queue_count_displayed":2,"wait_low":15,"wait_high":20,
       "closing_time":null,"server_time":"2026-09-06T09:01:02+02:00"}
```

### Où calculer `pickup_ready_dt` sans rien réécrire

**Une seule réponse porte déjà les deux ingrédients** : `server_time` (heure de
Paris signée serveur, décalage compris) et `wait_high`.

```
pickup_ready_dt = server_time + wait_high minutes        (ASAP)
pickup_ready_dt = scheduled_at                            (créneau choisi)
pickup_ready_dt = accepted_at + preparation_time          (après ACCEPT caisse)
```

Point d'ajout le plus économe : **`WaitEstimateService::estimate()`
(`app/Services/WaitEstimateService.php:84-94`)** — ajouter une clé
`ready_at_iso = $now->copy()->addMinutes($high)->toIso8601String()` à côté de
`server_time`, sans toucher aux paliers. Consommateurs existants inchangés
(ajout additif). Pour une commande déjà passée, le même calcul se pose dans
`app/Services/OrderTrackingService.php:129-141` (même retour, même service
délégué `:127`).

⚠️ `server_time` est **déjà émis et lu par personne** — `api.js:1466` le note
noir sur blanc. S'y brancher immunise aussi le site contre une horloge
d'appareil fausse, ce que le repli `Intl` de `api.js:1471-1503` ne peut pas faire.
⚠️ `closing_time: null` en prod ⇒ `maxPick` retombe sur `'23:45'` codé en dur
(`funnel.jsx:293`) alors que le resto ferme à minuit (`data/menu.js:43`).

---

## 6. Suivi client — où afficher « Suivre ma livraison »

**Deux mécanismes existent, et le site déployé n'utilise que le second.**

1. **Page publique par jeton (backend SPA, PAS le site Vercel)**
   - Route SPA : `resources/js/router/modules/orderTrackingRoutes.js:13`
     → `path: "/suivi/:trackingToken"`, nom `order.tracking.public`
   - Composant : `resources/js/components/frontend/tracking/OrderTrackingPageComponent.vue:168`
   - API publique : `routes/api.php:1943-1946`
     `GET api/frontend/order/track/{trackingToken}` (`[A-Za-z0-9]{48}`, throttle 30/min)
   - Le jeton est renvoyé à la création :
     `app/Http/Controllers/Frontend/OrderController.php:106-111`
     (`additional(['tracking' => ... + 'tracking_token'])`)

2. **Ce que le site Vercel fait réellement** — grep `tracking_token|order/track|/suivi`
   sur `SITE/api.js`, `funnel.jsx`, `racine.jsx` : **aucune occurrence**.
   - Composant : **`TrackingPage`**, `SITE/funnel.jsx:2324`
   - Route : hash **`#track`** (`racine.jsx:619`, `routeUrl` `:143`) —
     **il n'existe pas de `/suivi/:token` côté site client**
   - Il **poll `GET /api/frontend/order/show/{id}`** toutes les 20 s avec Bearer
     (`funnel.jsx:2370-2392`, relance `:2392`)
   - Bouton d'entrée : `funnel.jsx:2285` « Suivre ma commande » depuis
     `ConfirmationPage` (`funnel.jsx:2064`)

**Emplacement pour « Suivre ma livraison »** : `TrackingPage`, à côté du bloc
d'attente `funnel.jsx:2473-2478`, et le bouton dans `ConfirmationPage`
`funnel.jsx:2285`.

⚠️ Conséquence Uber Direct : le suivi actuel **exige une session Bearer**. Un
lien de suivi livraison partageable devra passer par le `tracking_token` déjà
émis par le backend mais **jamais consommé par le site**. C'est un câblage, pas
un développement.

---

## 7. Ce qui est ABSENT (à créer)

- Heure de prêt absolue côté serveur → **ABSENT** (§5, remède proposé)
- Champs adresse détaillés (complément, CP, ville, instructions) → **ABSENT**
  (`api.js:195-206` n'accepte que `address` + `apartment`)
- Téléphone sur l'adresse de livraison → **ABSENT** côté `saveAddress`, mais
  **déjà collecté** au checkout (`funnel.jsx:1701`)
- Endpoint HTTP de devis livraison → **ABSENT** ; `app/Services/Delivery/DeliveryQuoteService.php:32`
  n'est appelé qu'en interne (`app/Http/Requests/OrderRequest.php:108-115`), et
  rend `{distance_km, delivery_charge}` — **aucun champ de temps**
- Consommation de `tracking_token` par le site Vercel → **ABSENT**
- Méta `feature-delivery` dans `SITE/index.html` → **ABSENT** (c'est l'interrupteur)
