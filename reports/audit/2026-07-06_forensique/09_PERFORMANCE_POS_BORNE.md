# FoodKing — Audit de performance : causes du ralentissement POS caisse & borne

> Complément au rapport forensique du 2026-07-06.
> **Objectif** : identifier les causes réelles de lenteur du **POS caisse** et de la **borne**, et tracer le chemin vers **5×–10× plus rapide**.
> **Méthode** : 9 auditeurs de performance en parallèle (front + back), 60 optimisations candidates, vérification adversariale à double front (**gain réel** + **préservation des invariants sécurité**). Audit statique ; les 4 causes majeures ont été **relues à la source**.
> ⚠️ **Contrainte non négociable** : aucune optimisation ne doit réintroduire une faille (pricing recalculé serveur, cache cloisonné par branche, aucun secret côté client). Voir §6.

---

## 0. Verdict : le 5×–10× est réaliste, surtout sur le **chargement**

Le temps se perd sur **4 axes**. Le plus gros gisement est le **chargement initial** (bundle monolithique + images), suivi de la **réactivité par interaction** (sérialisation `localStorage` + absence de virtualisation).

| Axe | Cause dominante | Multiplicateur visé |
|---|---|---|
| **Chargement (TTI)** | Bundle unique pour tout + 114 Mo d'images PNG non cachées | **5–10×** ✅ (porte le titre) |
| **Interaction (jank/tap)** | `persistedstate` re-sérialise tout à chaque mutation + zéro virtualisation | **3–8×** |
| **Soumission (submit)** | `QUEUE_CONNECTION=sync` → FCM/Pusher HTTP dans la réponse | **2–5×** |
| **Backend / temps réel** | Re-fetch complet de la liste à chaque événement + index manquants | **2–4×** |

> Deux hypothèses initiales **corrigées par l'audit** (honnêteté) : (a) le menu backend est **bien eager-loadé** (~10 requêtes fixes, pas de N+1 par item) ; (b) le submit charge déjà les items **en bulk**. Le vrai coût backend est ailleurs : jobs synchrones, absence d'ETag/compression, et re-fetch temps réel.

---

## 1. AXE A — Chargement initial (le plus gros levier)

### A1. 🔴 Bundle monolithique — la borne charge tout le back-office
`webpack.mix.js:14` ne produit **qu'un seul `app.js`** :
```js
mix.js('resources/js/app.js', 'public/js').vue().postCss('resources/css/app.css', 'public/css', [require("tailwindcss")])
```
- Seul le kiosk est partiellement code-splitté ; le **POS et les 28 autres modules de routes importent leurs composants en statique** → tout l'admin atterrit dans `app.js`.
- `resources/js/app.js:23` enregistre **apexcharts en global** ; `DefaultComponent.vue:34` met **firebase/messaging** dans le chemin critique.
- Conséquence : la borne (appareil peu puissant) télécharge et parse **firebase + google-maps + apexcharts + quill + swiper + ~110 modules Vuex + tout le dashboard** — qu'elle n'utilise jamais.

### A2. 🔴 Cache-buster qui tue le cache du bundle POS
`resources/views/master.blade.php:128` :
```blade
<script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}"></script>
```
`{{ time() }}` génère une **URL unique à chaque chargement** → `pos-wizard.js` (281 Ko) **et** `pos-wizard.css` (41 Ko) sont **re-téléchargés à chaque ouverture du POS**, jamais mis en cache. Sur une caisse ouverte toute la journée, c'est du gaspillage réseau permanent.

### A3. 🔴 Aucun cache HTTP, aucune compression, aucun fingerprint
- `public/.htaccess` (l.22) : **aucun** `Cache-Control`/`Expires`, **aucune** compression gzip/brotli sur JS/CSS/JSON/images.
- `webpack.mix.js` : `mix.version()` absent → pas de fingerprinting → impossible d'appliquer un cache long en sécurité.
- Résultat : chaque session re-télécharge tout, y compris les 114 Mo d'images.

### A4. 🔴 Images : 114 Mo de PNG bruts servis pleine taille
- `public/images/menu` = **61 Mo**, `ai_food` = **30 Mo** ; PNG 1024×1024 / 1024×1536, 100 Ko à 2 Mo pièce.
- `app/Models/Item.php:122` : `registerMediaConversions()` utilise `keepOriginalImageFormat()` → les vignettes restent en **PNG** (pas de WebP/AVIF).
- `app/Models/Item.php:96` : le **fallback** `getThumbAttribute` sert le **PNG pleine taille** de `config/menu_images.php` au grid, au lieu d'une vignette 168×180.
- `KioskProductListComponent.vue:56` : **aucun `srcset`/`sizes`**, aucune dimension explicite, `loading="lazy"` même above-the-fold → LCP retardé, CLS, décodage bloquant.

### A5. 🟠 Rendu au chargement + CSS double
- Les grilles montent **tous** les items d'un coup (voir B2).
- `resources/js/bootstrap.js:1` : **Bootstrap 5.2 ET Tailwind** chargés ensemble + JS global Bootstrap/lodash → `app.css` de 140 Ko.
- `master.blade.php:11,113` : **fonts Google externes bloquantes** + 7 `<script>` synchrones sérialisés (pas de `defer`, pas de `preload`).

---

## 2. AXE B — Réactivité par interaction (jank au tap/scroll)

### B1. 🔴 `vuex-persistedstate` : sérialisation JSON synchrone à chaque mutation
`resources/js/store/index.js:217` — le plugin s'abonne à **TOUTES les mutations des ~110 modules** et re-`JSON.stringify` **tous** les paths persistés (`auth`, `globalState`, `posCart`, `tableCart`, `kioskCart.*`…) à chaque commit, **sur le thread principal**.
- `posCart.js:331` : un simple tap `+`/`−` déclenche **jusqu'à 3 écritures `localStorage` synchrones**.
- C'est **la cause n°1 de latence par tap** (confirmé : le panier ne fait pas d'appel réseau, il recalcule au submit).
- Bonus sécurité+perf : `index.js:223` — `posCart` est persisté **non scopé par caissier** → fuite inter-caissier **et** rechargement d'un panier périmé.

### B2. 🔴 Zéro virtualisation des grilles produits
- `KioskProductListComponent.vue:37` (borne) et `ItemComponent` (POS) montent **toute la catégorie** d'un coup.
- Chaque cellule appelle des **méthodes dans le template** (`sanitize` ×3, `formatPrice`, emoji) **ré-exécutées à chaque render** (`KioskProductListComponent.vue:39`).
- `ItemComponent.vue:3` : `:key="item"` (objet) au lieu de `item.id` → clés instables → re-créations de nœuds.

### B3. 🟠 Données menu profondément réactives + getters non mémoïsés
- `kioskMenu.js:114` : les données menu (immuables) sont rendues **profondément réactives** → coût de tracking Vue inutile. `Object.freeze`/`markRaw` recommandés.
- `kioskMenu.js:74` : les getters **re-filtrent et re-trient tout le catalogue à chaque lecture** (pas de mémoïsation, pas d'index `catégorie→items`).
- `KioskProductListComponent.vue:43` : animation d'entrée décalée `animationDelay: idx*40ms` → jank d'entrée de liste.
- `store/index.js:114` : **~110 modules Vuex instanciés sur chaque surface** (POS et borne chargent tout).

---

## 3. AXE C — Latence de soumission (la caisse attend)

### C1. 🔴 `QUEUE_CONNECTION=sync` — auto-documenté comme critique, mais c'est le défaut committé
`config/queue.php:16` : `'default' => env('QUEUE_CONNECTION', 'sync')`. Et `.env.example:73` :
```
# [CRITICAL-PROD] QUEUE_CONNECTION=sync is synchronous and blocks the API.
QUEUE_CONNECTION=sync
```
→ au submit, `SendFcmNotificationJob` (×3, HTTP FCM) et `DispatchDomainEventsJob` (HTTP Pusher) s'exécutent **inline dans la réponse**. Le caissier/la borne attend des allers-retours réseau externes avant la confirmation.

### C2. 🟠 Effets de bord non asynchrones (même avec une queue)
- `EventServiceProvider.php:103` : les 3 listeners `OrderCreated` ne sont **pas `ShouldQueue`** → synchrones quel que soit le driver.
- `SendOrderGotMailNotification.php:15` : mail/SMS envoyés en **SMTP/HTTP bloquant**.
- `AvailabilityService.php:123` : **décrément de stock en N+1 synchrone** au submit.
- `OrderDetailsResource.php:43` : la réponse au submit **lazy-load ~12 relations** (N+1) pendant que la caisse attend.
- `OrderService.php:908` : `ActionLog` + `AuditLog` HMAC **dans la transaction** sérialisée par branche → section critique allongée.

> ⚠️ `OrderService.php:948` : `OrderCreated` est dispatché **hors transaction** → à corriger en `afterCommit` **sans** casser l'atomicité outbox (invariant sync). Le passage à l'async doit préserver cet invariant.

---

## 4. AXE D — Backend menu, DB & temps réel

### D1. 🟠 API menu : pas d'ETag, pas de compression, TTL aveugle
`MenuController.php:66-74` — menu **bien eager-loadé** mais :
- **Aucun ETag/304** ni `Cache-Control` → la borne re-télécharge tout le JSON à chaque appel.
- **Aucune compression** du JSON.
- **TTL 60 s** force un **rebuild complet chaque minute** au lieu d'une invalidation par événement (`MenuSnapshot` existe déjà).
- JSON **ré-encodé** à chaque requête (`MenuController.php:70`) au lieu d'être servi pré-sérialisé.
- `KioskMenuService.php:290` : eager-load `itemAttribute` **inutile** pour l'UI + payload non aminci.

### D2. 🟠 Index & requêtes non-sargables sur les chemins chauds
- `OrderService.php:166` : listes commandes POS/KDS filtrent `branch_id + status + date` puis `ORDER BY` **sans index couvrant** → **filesort**.
- `OrderService.php:124` : `whereDate('order_datetime', …)` **non-sargable** → scan (réécrire en plage de timestamps).
- `OrderService.php:461` : `MAX(queue_number)` **à chaque submit** sans index adapté.
- `KioskMenuService.php:62,74` : index couvrants manquants sur `items(item_category_id, status, deleted_at)` et `item_branch_availability(branch_id, item_id, is_available)`.
- `migration 2026_03_12_130000:42` : index **morts/redondants** qui taxent chaque `INSERT` du submit.

### D3. 🔴 Temps réel : re-fetch complet à chaque événement
Défaut structurel : **chaque** événement Echo provoque un **re-fetch HTTP complet** de la liste, pas une mise à jour ciblée.
- `KitchenDisplaySystemComponent.vue:577` + `kitchenDisplaySystemOrder.js:39` : le payload (order_id, statut) est reçu puis **jeté**, le store remplace **toute la référence** → re-render du board entier.
- `KitchenDisplaySystemComponent.vue:809` : le KDS re-télécharge **DEUX listes complètes** non filtrées à chaque événement.
- `BackendNavbarComponent.vue:296` : FCM **et** Echo déclenchent chacun un refetch → **double livraison**.
- `PreparingAndReadyComponent.vue:144` (OSS) : **aucun debounce** → rafales en rush.
- `KitchenDisplaySystemComponent.vue:537` : reconnexion WS → refetch complet **+ polling 60 s maintenu en parallèle**.
→ En coup de feu (le pire moment), le POS/KDS s'effondre sous les re-renders.

---

## 5. Plan pour atteindre 5×–10×

Ordonné par **levier/effort**. Effort : S (<1 j), M (1-3 j), L (1-2 sem), XL (chantier).

### 🟢 Quick wins (S/M) — gros gain immédiat, faible risque
| # | Action | Axe | Gain | Effort |
|---|---|---|---|---|
| 1 | Supprimer le cache-buster `?v={{time()}}` sur `pos-wizard.js/css` + gater son chargement aux surfaces POS/kiosk | Chargement | Élimine un re-download permanent | S |
| 2 | `.htaccess` : activer **brotli/gzip** + `Cache-Control: immutable` sur assets fingerprintés + images | Transport | -60 à -80 % de transfert répété | S |
| 3 | **`QUEUE_CONNECTION=redis`** + rendre les listeners `OrderCreated`/notifs `ShouldQueue` (`afterCommit`) | Submit | Submit de plusieurs sec → sub-seconde | S/M |
| 4 | `persistedstate` : **filtrer** les mutations (uniquement panier) + **throttle** l'écriture (ou `idb-keyval` async) | Interaction | -70 à -90 % du jank au tap | S/M |
| 5 | `Object.freeze`/`markRaw` sur les données menu + clés de liste stables (`item.id`) | Interaction | Moins de re-renders | S |
| 6 | Images : `width/height` + `decoding="async"` + `loading="lazy"` **sauf** above-the-fold | Chargement | -CLS, scroll fluide | S |
| 7 | Corriger `getThumbAttribute` : servir la conversion 168×180, jamais le PNG plein format | Chargement | -90 % du poids image par vignette | S |
| 8 | Index composite `(branch_id, status, order_datetime)` + réécrire `whereDate` en plage | Backend | Listes POS/KDS instantanées | M |

### 🟠 Chantiers structurants (L/XL) — portent le 5-10× du chargement
| # | Action | Axe | Gain | Effort |
|---|---|---|---|---|
| 9 | **Migrer laravel-mix → Vite** + **entries séparés par surface** (borne/POS/admin/frontend) | Chargement | La borne ne charge plus l'admin/firebase/apexcharts | XL |
| 10 | **Code-splitting par route** (POS + 28 modules en `import()` dynamique, comme le kiosk) | Chargement | -majeure du JS parsé au 1er rendu | L |
| 11 | Lazy-load **firebase/messaging** et **apexcharts** hors chemin critique | Chargement | Allège borne/POS | M |
| 12 | **Virtualiser** (windowing) les grilles produits POS & borne | Interaction | Scroll fluide quel que soit le nombre d'items | L |
| 13 | Convertir le catalogue **PNG → WebP** pré-généré + `srcset`/`sizes` | Chargement | -60 à -80 % du poids image (61 Mo→~15 Mo) | L |
| 14 | Temps réel : **mise à jour incrémentale** du store (appliquer le payload) au lieu du re-fetch complet ; tuer la double livraison FCM+Echo ; debounce OSS | Temps réel | Board stable en rush | L |
| 15 | Menu : **ETag/304 + `Cache-Control`** (réutiliser `MenuSnapshot`) + TTL long + invalidation par événement | Backend | 1er chargement menu 2-5× | M |
| 16 | Supprimer une des deux stacks CSS (Bootstrap **ou** Tailwind) | Chargement | -CSS, -conflits | L |

### Estimation honnête du multiplicateur (par axe)
- **Chargement borne/POS** : quick wins 1-2-6-7 + chantiers 9-10-11-13 → **5–10× réaliste** (le monolithe + les images sont les deux masses ; les séparer et les compresser change l'ordre de grandeur). C'est **l'axe qui porte le titre**.
- **Interaction (tap/scroll)** : 4-5 + 12 → **3–8×** sur la latence par tap et le FPS de scroll.
- **Submit** : 3 + async → **2–5×** (de plusieurs secondes à sub-seconde).
- **Temps réel en rush** : 14 → passe d'un board qui « rame » à des mises à jour fluides (gain qualitatif majeur, difficile à chiffrer en ×).

---

## 6. Garde-fous sécurité (ne pas casser en optimisant)

La vérification adversariale a **rejeté** toute optimisation qui gagnerait du temps au prix d'un invariant. À respecter impérativement :
- ❌ **Ne jamais** faire confiance à un total/prix/remise **calculé côté client** pour aller plus vite. Le panier peut afficher localement, mais le serveur **recalcule** au submit (c'est déjà le cas — le garder).
- ❌ **Ne jamais** partager un cache menu/données **entre branches** : la clé de cache reste **par branche** (invariant d'isolation).
- ✅ **Corriger** `posCart` persisté non scopé (finding B1) : c'est **à la fois** une fuite inter-caissier **et** une source de panier périmé.
- ✅ Passage du submit en async : préserver l'**atomicité outbox** (`afterCommit` + écriture dans la même transaction) et l'intégrité fiscale.
- ❌ Aucun secret/token supplémentaire exposé côté client au nom de la perf.

---

## 7. Comment PROUVER le gain (mesure avant/après)

| Métrique | Outil | Cible |
|---|---|---|
| **TTI / LCP / TBT** borne & POS | Lighthouse (mode mobile, throttling) | LCP < 2,5 s ; TBT < 200 ms |
| **Poids du bundle** par surface | `webpack-bundle-analyzer` / rapport Vite | Borne < 300 Ko JS (vs monolithe actuel) |
| **Poids image du 1er écran** | DevTools Network | -70 % au moins |
| **Latence submit** | Server-Timing / log applicatif | < 400 ms côté serveur |
| **Nb de requêtes DB** (menu, submit, listes) | `beyondcode/laravel-query-detector` (déjà en dev-dep) | Pas de régression N+1 |
| **FPS au scroll** du menu | DevTools Performance | 60 fps stable |
| **Écritures `localStorage`/tap** | DevTools Performance / compteur | 1 (throttlée) au lieu de 3 |

> Établir la **baseline** sur chaque métrique **avant** tout changement, puis re-mesurer après chaque quick-win : c'est ainsi qu'on démontre le 5×–10× (et qu'on évite d'optimiser à l'aveugle).

---

*Findings issus de 9 auditeurs de performance + vérification adversariale double-front. Les 4 causes majeures (bundle, images, `QUEUE_CONNECTION=sync`, cache-buster) ont été relues manuellement à la source. Aucune modification de code n'a été appliquée — ce rapport est un plan à valider.*
