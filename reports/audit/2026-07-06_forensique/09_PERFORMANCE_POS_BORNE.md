# FoodKing — Audit de performance : causes du ralentissement POS caisse & borne

> Complément au rapport forensique du 2026-07-06.
> **Objectif** : identifier les causes réelles de lenteur du **POS caisse** et de la **borne**, et tracer le chemin vers **5×–10× plus rapide**.
> **Méthode** : 9 auditeurs de performance en parallèle (front + back), 60 optimisations candidates, **vérification adversariale à double front** (gain réel + préservation des invariants sécurité) + plan quantifié. Audit statique ; les causes majeures ont été **relues à la source**.
> ⚠️ **Contrainte non négociable** : aucune optimisation ne doit réintroduire une faille (pricing recalculé serveur, cache cloisonné par branche, aucun secret côté client). Voir §6.

---

## 0. Verdict : le 5×–10× est réaliste — mais **PAS uniforme**

Le temps se perd sur **4 axes**. La vérification adversariale a recalibré les gains : le 5×–10× est réel sur le **chargement** (surtout la borne au démarrage à froid), mais l'**interaction** plafonne honnêtement à 2–3×.

| Axe | Cause dominante | Multiplicateur réaliste |
|---|---|---|
| **Chargement (TTI)** | Bundle unique pour tout + 91 Mo de PNG non optimisés | **5–10×** ✅ (surtout borne, démarrage à froid) |
| **Soumission (submit)** | `QUEUE_CONNECTION=sync` → FCM/Pusher HTTP dans la réponse | **3–5×** |
| **Backend / temps réel** | Re-fetch complet de la liste à chaque événement + index manquants | **2–4×** |
| **Interaction (jank/tap)** | `persistedstate` re-sérialise tout à chaque mutation + zéro virtualisation | **2–3×** (le rendu Vue reste le plancher) |

> ⚠️ **Correction issue de la vérification adversariale** : annoncer un « 5–10× uniforme » serait **malhonnête**. Le titre est porté par le **chargement de la borne à froid** (bundle par surface × code-splitting × WebP × cache), démontrable. Le submit vise 3–5× (queue async). L'interaction gagne 2–3× (éliminer le N+1 et le jank `localStorage`), mais le plancher est le rendu Vue.

> **Deux hypothèses initiales corrigées par l'audit** : (a) le menu backend est **bien eager-loadé** (~10 requêtes fixes, pas de N+1 par item — sauf le chemin POS/Table `ItemCategory@show`) ; (b) le submit charge déjà les items **en bulk**. Le vrai coût backend est ailleurs : jobs synchrones, absence d'ETag/compression sûrs, re-fetch temps réel.

---

## 1. AXE A — Chargement initial (le plus gros levier)

### A1. 🔴 Bundle monolithique — la borne charge tout le back-office
`webpack.mix.js:14` ne produit **qu'un seul `app.js`** :
```js
mix.js('resources/js/app.js', 'public/js').vue().postCss('resources/css/app.css', 'public/css', [require("tailwindcss")])
```
- Seul `kioskRoutes.js` est en `import()` dynamique ; **`posRoutes.js:1` et ~28 autres modules importent leurs composants en statique** → tout l'admin atterrit dans `app.js`.
- `app.js:23` enregistre **apexcharts en global** (~130 Ko gz, usage limité à 3 dashboards) ; les navbars (`DefaultComponent.vue:34`) tirent **firebase** dans toutes les surfaces.
- Conséquence : la borne (matériel faible) télécharge et parse un bundle contenant **firebase + apexcharts + quill + swiper + ~110 modules Vuex + tout le dashboard**, **puis** lazy-charge encore son chunk kiosk.

### A2. 🔴 Cache-buster qui tue le cache du bundle POS
`resources/views/master.blade.php:128` :
```blade
<script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}"></script>
```
`{{ time() }}` génère une **URL unique à chaque chargement** → `pos-wizard.js` (281 Ko) **et** `pos-wizard.css` (41 Ko) = **~328 Ko re-téléchargés à chaque ouverture du POS**, jamais mis en cache. → remède : `filemtime()` (stable tant que le fichier ne change pas).

### A3. 🔴 Aucun cache HTTP, aucune compression, aucun fingerprint
- `public/.htaccess:22` : **aucun** `Cache-Control`/`Expires`, **aucune** compression brotli/gzip sur JS/CSS/JSON/images.
- `webpack.mix.js` : `mix.version()` absent → pas de fingerprinting → cache long impossible en sécurité.

### A4. 🔴 Images : 91 Mo de PNG bruts, le fallback sert le plein format
- `public/images/menu` = **61 Mo**, `ai_food` = **30 Mo** ; PNG 1024×1024 / 1024×1536, jusqu'à 2 Mo pièce (44 fichiers > 1 Mo).
- `app/Models/Item.php:96` : **cause centrale** — le grid affiche `product.thumb`, mais `getThumbAttribute` ne renvoie la conversion medialibrary 168×180 **que si un média est uploadé** ; pour tout le menu mappé via `config/menu_images.php`, le **fallback renvoie le PNG PLEIN FORMAT**. Un écran borne charge ~10–20 Mo là où des vignettes WebP feraient ~0,5 Mo.
- `Item.php:122` : `keepOriginalImageFormat()` → les vignettes restent en **PNG** (pas de WebP/AVIF).
- `KioskProductListComponent.vue:56` : **aucune dimension explicite** (`width`/`height`) → CLS + décodage bloquant.
  > *Correction adversariale : ajouter des `srcset` sur les vignettes medialibrary serait **contre-productif** — elles sont déjà minuscules (168×180) et une variante 512w serait plus lourde. Le vrai poids vient du **fallback plein format** (ci-dessus) et de l'absence de **WebP + cache**, pas d'un manque de `srcset`.*

### A5. 🟠 Rendu au chargement + CSS double
- Les grilles montent **tous** les items d'un coup (voir B2).
- `resources/js/bootstrap.js:1` : **Bootstrap 5.2 ET Tailwind** ensemble + JS global Bootstrap/lodash → `app.css` de 140 Ko.
- `master.blade.php:11,113` : **fonts Google externes bloquantes** + 7 `<script>` synchrones (pas de `defer`, pas de `preload`).

---

## 2. AXE B — Réactivité par interaction (jank au tap/scroll)

### B1. 🔴 `vuex-persistedstate` : sérialisation JSON synchrone à chaque mutation
`resources/js/store/index.js:217` — le plugin s'abonne à **TOUTES les mutations des ~110 modules** et re-`JSON.stringify` **tous** les paths persistés à chaque commit (broadcasts Echo KDS/OSS, notifications, fetch menu **inclus**), **sur le thread principal**.
- `posCart.js:331` : un simple tap `+`/`−` déclenche **jusqu'à 3 écritures `localStorage` synchrones**.
- **Cause n°1 de latence par tap** (le panier ne fait pas d'appel réseau, il recalcule au submit).
- Bonus sécurité+perf : `index.js:223` — `posCart` est persisté **non scopé par caissier** → fuite inter-caissier **et** panier périmé rechargé.
  > *Correction adversariale sur le remède : ne PAS filtrer naïvement par préfixe de path (`auth` n'est pas namespacé, `posCart` serait oublié → déconnexions + panier perdu). Le remède sûr est de **throttler `setState`** (ou passer sur `idb-keyval` async, déjà présent) et de persister le panier **scopé par caissier**.*

### B2. 🔴 Zéro virtualisation des grilles produits
- `KioskProductListComponent.vue:37` (borne) et `ItemComponent` (POS) montent **toute la catégorie** d'un coup.
- Méthodes de template (`sanitize` ×3, `formatPrice`, emoji) **ré-exécutées par cellule à chaque render**, sans `v-memo` → taper un article re-rend **toutes** les cartes.
- `ItemComponent.vue:3` : `:key="item"` (objet) au lieu de `item.id` (le même bug est déjà corrigé sur les catégories juste au-dessus).

### B3. 🟠 Données menu réactives + getters non mémoïsés
- `kioskMenu.js:114` : données menu (immuables) rendues **profondément réactives** → tracking Vue inutile. `Object.freeze`/`markRaw` recommandés.
- `kioskMenu.js:74` : les getters **re-filtrent et re-trient tout le catalogue à chaque lecture** (pas de mémoïsation, pas d'index `catégorie→items`).
- `store/index.js:114` : **~110 modules Vuex instanciés sur chaque surface**.

---

## 3. AXE C — Latence de soumission (la caisse attend)

### C1. 🔴 `QUEUE_CONNECTION=sync` — auto-documenté comme critique, pourtant défaut committé
`config/queue.php:16` : `'default' => env('QUEUE_CONNECTION', 'sync')`. Et `.env.example:73` :
```
# [CRITICAL-PROD] QUEUE_CONNECTION=sync is synchronous and blocks the API.
QUEUE_CONNECTION=sync
```
→ au submit, `SendFcmNotificationJob` (×2-3, HTTP FCM ~200-500 ms) et `DispatchDomainEventsJob` (HTTP Pusher) s'exécutent **inline**. **Budget inline réaliste : 0,6 à 1,5 s** (si credentials configurés). C'est le **levier maître du submit**.

### C2. 🟠 Effets de bord non asynchrones (même avec une queue)
- `EventServiceProvider.php:103` : les 3 listeners `OrderCreated` ne sont **pas `ShouldQueue`**.
- `SendOrderGotMailNotification.php:15` : mail/SMS **bloquants**.
- `AvailabilityService.php:123` : décrément de stock **N+1 synchrone**.
- `OrderDetailsResource.php:43` : la réponse au submit **lazy-load ~12 relations** (N+1).
- `OrderService.php:908` : `ActionLog` + `AuditLog` HMAC **dans la transaction** sérialisée par branche.
> Tout basculer en async `afterCommit` + eager-load la réponse ramène le submit à **~80–150 ms**. ⚠️ `OrderService.php:948` : `OrderCreated` dispatché **hors transaction** → corriger en `afterCommit` **sans casser l'atomicité outbox** (invariant sync).

---

## 4. AXE D — Backend menu, DB & temps réel

### D1. 🟠 API menu : pas de compression ; ETag **à ne pas activer en l'état**
`MenuController.php:66-74` — menu bien eager-loadé mais :
- **Aucune compression** du JSON, **ré-encodé** à chaque requête (`:70`) au lieu d'être servi pré-sérialisé.
- **TTL 60 s** force un **rebuild complet chaque minute** au lieu d'une invalidation par événement.
- `KioskMenuService.php:290` : eager-load `itemAttribute` **inutile** + payload non aminci.
> 🚫 **Piège écarté par la vérification** : **NE PAS** activer d'ETag/304 basé sur `MenuSnapshot` en l'état. `MenuSnapshot::current` n'est bumpé que par `ItemAvailabilityChanged`, **pas** par `ItemCreated/Deleted/Category*` → un 304 servirait un **menu périmé** (item supprimé encore commandable, prix DB non reflété) = **violation de l'invariant « backend seule source de vérité »**. L'ETag n'est sûr **qu'après** avoir fait bumper le snapshot sur **tous** les événements qui changent le menu.

### D2. 🟠 Index & requêtes non-sargables — attention à l'efficacité réelle
- `OrderService.php:124` : `whereDate('order_datetime', …)` **non-sargable** → aucun range seek (à réécrire en `whereBetween([00:00, lendemain 00:00))`).
- `OrderService.php:461` : `MAX(queue_number)` avec `whereDate`+`SUBSTRING`+`REGEXP` **dans un `Cache::lock`** → scan de tout l'historique branche **sous lock**, non borné.
- `OrderService.php:166` : listes POS/KDS `ORDER BY` sans index couvrant → **filesort**.
> ⚠️ **Nuance de la vérification** : un index `(branch_id, status, order_datetime)` **seul est marginal** — l'`order_column` par défaut est **`id`** (`:111,184`), donc l'index ne sert pas l'`ORDER BY`, et `whereDate()` bloque le range. L'index n'aide **que** si l'on change aussi `order_column` en `order_datetime` **et** qu'on rend `whereDate` sargable. Sinon : effort dépensé pour rien.

### D3. 🔴 Temps réel : re-fetch complet à chaque événement
Défaut structurel : **chaque** événement Echo provoque un **re-fetch HTTP complet** de la liste, pas une mise à jour ciblée.
- `KitchenDisplaySystemComponent.vue:577` + `kitchenDisplaySystemOrder.js:39` : le payload (order_id, statut) est **jeté**, le store remplace toute la référence → re-render du board entier ; un tap de statut peut déclencher **3-4 re-fetch**.
- `KitchenDisplaySystemComponent.vue:809` : le KDS re-télécharge **DEUX listes complètes** par événement.
- `BackendNavbarComponent.vue:296` : FCM **et** Echo → **double livraison**.
- `PreparingAndReadyComponent.vue:144` (OSS) : **aucun debounce** (le KDS, lui, est déjà debouncé 300 ms + borné à 50).
> Gain réel surtout côté **OSS** (non debouncé) et sur l'élimination du flicker/re-render sur matériel bas de gamme.

---

## 5. Plan pour atteindre le gain (séquencé, vérifié)

Ordre validé par le plan adversarial : **arrêter le gaspillage d'abord (S), puis structurer (L/XL)**. Effort : S (<1 j), M (1-3 j), L (1-2 sem), XL (chantier).

| # | Action | Axe | Gain estimé | Effort |
|---|---|---|---|:---:|
| 1 | **`QUEUE_CONNECTION=redis`** + worker queue `high` + test de garde anti-`sync` hors env test | Submit | **-0,6 à -1,5 s / submit** | S |
| 2 | Cache-buster **stable `filemtime()`** sur `pos-wizard.js/css` (au lieu de `time()`) | Chargement | -328 Ko dès le 2ᵉ chargement POS | S |
| 3 | `Cache-Control: immutable, max-age=1an` sur `/images` + brotli/gzip (`.htaccess`) | Transport | Images menu non re-transférées entre sessions | S |
| 4 | Sortir **apexcharts** du bundle global → `defineAsyncComponent` local aux 3 dashboards | Chargement | -130 Ko gz du bundle initial | M |
| 5 | Eager-load `items.media/offer/category` dans `ItemCategoryService::show` (N+1) | Backend | **~50 requêtes → 2** par tap catégorie | M |
| 6 | Retirer les 3 formats monnaie du payload liste (recalcul front depuis `price`) | Interaction | Payload + CPU/item allégés (pricing backend préservé) | S |
| 7 | **Throttle** l'écriture `persistedstate` (pas de filtre naïf) + panier scopé caissier | Interaction | -70 à -90 % du jank au tap | S/M |
| 8 | `Object.freeze`/`markRaw` données menu + clés de liste stables (`item.id`) | Interaction | Moins de re-renders | S |
| 9 | Index `(branch_id, created_at)` + `whereBetween` sargable sur `MAX(queue_number)` | Submit | Coût du lock borné au jour (30-100 % sur gros volume) | M |
| 10 | Index `(branch_id, status, order_datetime)` **+ `order_column = order_datetime`** (sinon inutile) | Backend | Suppression du `filesort` (prouver par `EXPLAIN`) | M |
| 11 | **Virtualiser** (windowing) les grilles produits POS & borne | Interaction | Scroll fluide quel que soit le nombre d'items | L |
| 12 | **Code-splitting par route** (POS + 28 modules en `import()`, comme le kiosk) | Chargement | -bundle initial (cold-start, amorti sur la session) | L |
| 13 | **Mutations store incrémentales** (KDS/OSS : patch en place au lieu du re-fetch) + tuer double livraison FCM+Echo | Temps réel | Fin du re-render complet / flicker en rush | L |
| 14 | Pipeline **WebP pré-généré** (256 px + 600 px, `<picture>` WebP-first/PNG-fallback) + corriger le fallback `getThumbAttribute` | Chargement | **91 Mo → 6-8 Mo** (-85/-90 %) sur le payload images | L |
| 15 | **Entrypoints webpack séparés par surface** (kiosk/pos/admin) — la blade choisit le bundle | Chargement | **>2× parse/TTI borne** (admin/firebase/apexcharts hors critique) | XL |

### Estimation honnête du multiplicateur (par axe)
- **Chargement borne à froid** : #15 (>2× parse) × #12 × #14 (WebP ~12× sur images) × #3 (cache) → **5–10× atteignable et démontrable**. C'est l'axe qui porte le titre.
- **Submit** : #1 (async) retire le poste dominant, #9/#10 bornent le reste → **3–5×** (plancher = calcul total backend, invariant intouché).
- **Backend menu / listes** : #5 (N+1 50→2) + #10 (filesort) → **2–4×** au tap catégorie et sur les listes.
- **Interaction** : #7/#8/#11 → **2–3× honnête** (le rendu Vue reste le plancher — pas de 5-10× ici).

---

## 6. Garde-fous sécurité (ne pas casser en optimisant)

La vérification adversariale a **rejeté** toute optimisation gagnant du temps au prix d'un invariant :
- ❌ **Ne jamais** faire confiance à un total/prix/remise **client**. Le panier affiche localement, le serveur **recalcule** au submit (déjà le cas — le garder).
- ❌ **Ne jamais** partager un cache menu/données **entre branches** (clé de cache **par branche**).
- 🚫 **ETag/304 sur le menu** interdit tant que `MenuSnapshot` n'est pas bumpé sur **tous** les événements de changement (sinon menu périmé servi — cf. D1).
- ✅ **Corriger** `posCart` persisté non scopé (fuite inter-caissier **et** panier périmé).
- ✅ Submit async : préserver l'**atomicité outbox** (`afterCommit` + même transaction) et l'intégrité fiscale.
- ❌ Aucun secret/token exposé côté client au nom de la perf.

---

## 7. Comment PROUVER le gain (mesure avant/après)

| Axe | Métrique | Outil | Cible |
|---|---|---|---|
| Chargement | TTI / LCP / TBT (borne & `/admin/pos`) | Lighthouse mobile, throttle 4× CPU | bundle /2 à /4 ; images /10 |
| Chargement | Poids par chunk | `webpack-bundle-analyzer` / rapport Vite | borne sans code admin |
| Submit | Latence POST commande (médiane + p95, 20 submits) | Server-Timing / log | < 400 ms serveur |
| Backend | Requêtes SQL/tap | `laravel-query-detector` (déjà en dev-dep) | 50 → 2 ; `EXPLAIN` sans `filesort` |
| Interaction | FPS scroll + rafales Echo | Chrome Performance (device réel) | 60 fps ; 1 écriture `localStorage`/tap |

> Établir la **baseline** avant tout changement, re-mesurer après chaque étape. **Test de garde** : faire échouer la CI si `QUEUE_CONNECTION=sync` hors env test.

---

*9 auditeurs de performance + vérification adversariale double-front (4 confirmées, 7 nuancées, 3 rejetées) + plan quantifié. Les causes majeures ont été relues à la source ; les gains ont été recalibrés par la passe adversariale (le 5-10× n'est pas uniforme). Aucune modification de code appliquée — ce rapport est un plan à valider.*
