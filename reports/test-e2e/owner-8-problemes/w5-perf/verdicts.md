# W5-PERF — Verdicts claim-par-claim du rapport externe 09_PERFORMANCE_POS_BORNE.md

> Audit READ-ONLY 2026-07-06, serveur local http://127.0.0.1:8000, HEAD `feb7ec2ee`.
> Rapport externe = ADVISORY : chaque claim vérifié localement (code + mesures Playwright + EXPLAIN + curl).
> **Constat transversal : le rapport externe a été écrit contre une version ANCIENNE/upstream du code.**
> Ses line-numbers ne matchent pas ce repo (master.blade.php:128→réel :323 ; store/index.js:217→réel :247 ;
> OrderService:461→réel :3158…) et plusieurs « bugs » sont DÉJÀ corrigés ici depuis avril-mai 2026.

---

## 0. BASELINE MESURÉE (le juge de paix) — 3 runs, médianes

Machine locale (M-series), build **DEV non-minifié** (bundles rebuildés aujourd'hui par `mix watch`),
`php artisan serve` (aucun header cache, aucun gzip), QUEUE_CONNECTION=**redis**, CACHE=redis.

| Étape | Médiane | Détail |
|---|---|---|
| (a) Load `/admin/pos` → grille interactive | **~4 050 ms** | DCL 3 316 / FCP 3 328 / LCP 3 988 / tiles 4 038 ; **1 long task de ~3 300 ms** = parse+exec JS dev |
| — JS chargé | 10 fichiers, **10,8 Mo** transférés à CHAQUE reload (0 cache local) | manifest+vendor+app (mix ?id=) + 5 scripts thème + pos-wizard.js?v=9-`time()` + pos-shell lazy |
| — API au mount | **59-60 requêtes** | dont ~50 POST `/api/frontend/csp-report` (artefact env local, voir §5) ; slowest : walk-in-customer 349-589 ms, counter-collect 317-369 ms |
| (b) Tap catégorie → tuiles | **8-11 ms** (JS) / **52 ms** (clic réel) | rendu depuis le store + refetch `/api/admin/item` en fond (48-83 ms) |
| (c) Tap produit complexe (Tacos L) → wizard | **24 ms** (18/24/25) | pos-wizard vanilla = instantané |
| (c') Tap produit simple (Coca) → popup | **227 ms** (204-325) | popup single-page pos-wizard |
| (d) « Ajouter au panier » → ligne panier rendue | **~206 ms** (22/206/207) | + retour auto hub catégories + **REFETCH complet `/api/admin/item`** |
| (d') localStorage par ajout | **12 écritures × ~19 Ko = ~228 Ko** JSON sérialisé (clé `vuex`) | 0 long task ≥50 ms sur cette machine |
| Submit Espèces : POST `/api/admin/pos` | **180 ms** (155/180/235) | clic Confirmer → réponse POST : 251-277 ms |
| Submit : clic → modal ticket affichée | **~344 ms** (329/358) | + print-receipt 56-130 ms async |
| DOM grille | 55 tuiles = **1 322 nœuds** total page | ~24 nœuds/tuile — léger |

⚠️ Caveat honnête : la baseline TTI 4 s est **gonflée par le build dev local** (7,2 Mo app.js non-minifié).
En prod (`npm run production`, fait par `tools/deploy-vps.sh:38`), le boot POS documenté = **785 Ko gz**
(`reports/baseline/POS_V4_PERF_HISTORY.md:40-51`, KPI cible 220 Ko NON atteint). Le ressenti « années
2000 » de l'owner sur la caisse réelle vient de : 785 Ko gz à parser sur hardware faible + re-téléchargements
(A2/A3) + images plein format (A4) + cycle d'ajout avec refetch (§6).

---

## 1. AXE A — Chargement

### A1 « Bundle monolithique, un seul app.js, posRoutes statique » — **FAUX tel qu'énoncé, PARTIEL en substance**
- **FAUX** : `webpack.mix.js:59-80` a DEUX entrées (`pos-app.js` + `app.js`) + `.extract([16 vendors])` + `.version()` (depuis 2026-04-26, commits POS-V4 W1/W2). Le « `webpack.mix.js:14` ne produit qu'un seul app.js » cité n'existe pas ici.
- **FAUX** : `resources/js/router/modules/posRoutes.js:11-12` = `import(/* webpackChunkName: "pos-shell" */ …)` — LAZY, pas statique. 34/38 modules routes sont lazy (chunks nommés `admin-shell` 608 Ko gz, `pos-shell` 275 Ko gz, `kiosk-shell`, `admin-kds`, `admin-reports`, `admin-oss`…). Seuls `adminRoutes/authRoutes/ingredientRoutes/frontendRoutes` restent statiques.
- **VRAI (résidu)** : `/admin/pos` (SPA `master.blade.php:306-308`) charge le `app.js` générique (pas `pos-app.js`, réservé à `/admin/pos-v4` legacy) → boot POS prod = **785 Ko gz vs KPI 220 Ko**. firebase est dans chaque entry (pas dans l'extract vendor) ; apexcharts est global (`app.js:30,202`) mais DANS vendor.js (extract), pas dans app.js.
- Verdict : le gros du travail réclamé (#12 code-splitting) **est déjà fait** ; le levier restant = brancher `/admin/pos` sur l'entrée dédiée `pos-app.js` (elle existe !) ou sortir firebase/Dashboard de app.js.

### A2 « Cache-buster time() sur pos-wizard » — **VRAI** ✅
- `master.blade.php:54` (`pos-wizard.css?v=2-{{ time() }}`, 41 Ko) et `:323` (`pos-wizard.js?v=9-{{ time() }}`, 300 Ko) — vérifié LIVE : `<script src="/js/pos-wizard.js?v=9-1783355193">` (epoch). Idem `admin-pos-v4.blade.php:35,136` (⚠️ ce blade-là est FROZEN).
- **~341 Ko re-téléchargés à chaque reload du POS**, jamais cachés. Fix `filemtime()` sur `master.blade.php` = non-frozen, S, zéro risque.

### A3 « Aucun Cache-Control/Expires, aucun fingerprint, aucune compression » — **FAUX aux 2/3**
- **FAUX** : `public/.htaccess:57-95` contient Expires 1 an JS/CSS/fonts + 1 mois images + `Cache-Control max-age=2592000` (ajouté « [Audit 2026-05-29 PERF-015] »).
- **FAUX** : `mix.version()` présent (`webpack.mix.js:80`), manifest `?id=<hash>` vérifié.
- **VRAI** : aucune directive gzip/brotli nulle part dans le repo (ni mod_deflate .htaccess, ni conf nginx versionnée). Local `php artisan serve` : `curl -I /js/pos-wizard.js` → AUCUN Cache-Control/ETag/Content-Encoding. ⚠️ Les règles .htaccess ne s'appliquent que sous Apache — si le VPS est nginx, elles sont ignorées : **la conf cache+gzip du VPS est à vérifier côté serveur (hors repo)**.

### A4 « 91 Mo de PNG, fallback plein format » — **VRAI** ✅ (le levier n°1 validé)
- Mesuré : `public/images` = **164 Mo** (menu 85 Mo, ai_food 30 Mo) — pire que les 91 Mo du rapport. PNG jusqu'à 2,87 Mo pièce.
- `app/Models/Item.php:89-107` : `getThumbAttribute` fallback `config/menu_images.php` → **PNG PLEIN FORMAT** ; conversions medialibrary 168×180 `keepOriginalImageFormat()` (PNG) `Item.php:129-131`.
- **Prouvé LIVE** : tuiles POS Sandwichs = `sandwich-cayenne.png` **1536×1024 (~2,5 Mo)** rendu à 547 px ; les items AVEC média uploadé servent `/storage/N/conversions/frites-thumb.png` **19-36 Ko**. Ratio ≈ **×70**. Une catégorie sandwichs 1er affichage ≈ 15-20 Mo.
- La correction adversariale du rapport (pas de srcset, corriger le fallback + WebP) est correcte.

### A5 « Rendu au chargement + CSS double » — **PARTIEL**
- **VRAI** : Bootstrap JS importé (`resources/js/bootstrap.js:4`) + Tailwind coexistent ; `app.css` = 284 Ko (pas 140). Fonts Google bloquantes `master.blade.php:21-23` (Inter, toujours) + 4 CSS fonts thème. 9 `<script>` sync (fin de body → impact modéré).
- **FAUX/écarté** : « grilles montent tout d'un coup » = vrai mais 55 items → 1 322 nœuds, tap 8-52 ms mesuré : pas un problème à cette échelle.
- Mesuré : FCP 3,3 s / LCP 4,0 s sur build DEV local (§0 caveat).

## 2. AXE B — Interaction

### B1 « vuex-persistedstate sérialise tout à chaque mutation » — **VRAI mécaniquement, coût mesuré modéré** ✅
- `resources/js/store/index.js:3` (import), `:247` (plugin subscribe TOUTES mutations), `:274-317` (paths dont `posCart` entier). **114 modules** enregistrés (`:132-245`).
- **MESURÉ : 12 écritures localStorage × ~19 Ko ≈ 228 Ko JSON sérialisé PAR AJOUT panier** (clé `vuex`). 0 long task ≥50 ms sur M-series ; sur le hardware caisse faible, estimé 50-150 ms de jank par tap — réel mais pas le facteur dominant.
- **REJETÉ (déjà corrigé)** : « posCart non scopé par caissier → fuite inter-caissier ». `posCart.js:19-33` [POS-9.1.9] = clé `pos_cart_v3:b<branch>:u<user>`, TTL 2 h, purge legacy. Le module a SA propre persistence scopée → **retirer `"posCart"` des paths persistedstate (`index.js:280`) est le remède le plus simple** (déjà persisté par le module), + throttle du plugin. Le remède « throttle, pas de filtre naïf » du rapport reste valide.

### B2 « Zéro virtualisation des grilles » — **REJETÉ à l'échelle V1**
- Mécaniquement vrai (pas de windowing) mais : catalogue = **55 items max**, DOM total 1 322 nœuds, tap catégorie 8-52 ms, rendu sans long task. Virtualiser (effort L) = gain nul à cette échelle.
- **REJETÉ (déjà corrigé)** : `:key="item"` objet — le code réel est `:key="item.id || item"` (`ItemComponent.vue:12`).
- Les images plein format dans les tuiles (A4) sont le vrai coût du render, pas le nombre de nœuds.

### B3 « Getters non mémoïsés + menu réactif profond » — **PARTIEL, gain minime**
- **VRAI** : `kioskMenu.js:96` `itemsByCategory: (s) => (categoryId) =>` = getter paramétré → pas de cache Vuex, re-filtre par appel ; pas de `markRaw`/`Object.freeze` sur le menu (grep = 0 hit).
- **NUANCE** : les computed POS (`PosComponent.vue:2069-2201`) sont des computed Vue → cachés par dépendances ; recalcul O(45) seulement quand `item/lists` change. À 45-55 items, coût négligeable. `markRaw` = hygiène S, pas un levier.

## 3. AXE C — Submit

### C1 « QUEUE_CONNECTION=sync par défaut → 0,6-1,5 s inline » — **FAUX localement / OBSOLÈTE sur le repo**
- `.env` local = **redis** (vérifié) ; `.env.example:172` = **redis** (le `sync` cité « :73 » n'y est plus, remplacé par un warning CRITICAL-PROD + note `/api/health/ready` **retourne 503 en prod si sync**) → le « test de garde anti-sync » réclamé par le plan #1 **existe déjà**.
- Résidu VRAI : `config/queue.php:16` fallback `'sync'` si la variable manque.
- **MESURÉ : POST `/api/admin/pos` = 155/180/235 ms ; clic → ticket = 329-358 ms.** Le scénario 0,6-1,5 s ne se produit pas avec l'env du repo. Action restante = OPS : vérifier `.env` du VPS + worker `--queue=high,default` (déjà dans deploy-vps.sh).

### C2 « Effets de bord synchrones (listeners, N+1 stock, resource) » — **PARTIEL, non prioritaire (mesuré 155-235 ms)**
- VRAI : les listeners `OrderCreated` (`EventServiceProvider.php:172-182`) ne sont pas `ShouldQueue` → inline. MAIS : `PersistOrderCreatedToOutbox` → `DB::afterCommit` + `DispatchDomainEventsJob::dispatch` (job **queued**) ; `SendFcmOnOrderCreated` → `SendFcmNotificationJob::dispatch` (job **queued**). L'inline réel = décréments stock/availability (3 UPDATEs par ligne, `AvailabilityService.php:332-370` — « N+1 » vrai mais UPDATEs indexés) + prints **kiosk-gated** (`PrintKioskOrderToCounter.php:44` : `source_surface !== 'kiosk'` → return ; n'affecte PAS le submit caisse).
- « OrderDetailsResource lazy-load ~12 relations » : non reproduit sur le chemin POS store — `PosOrderController.php:148` fait `->load('orderItems')` ; le seul extra = 1 query `parent_order_serial_no` (refunds only, `OrderDetailsResource.php:44-46`).
- Verdict : optimisations réelles possibles mais le poste total mesuré est déjà ~200 ms — pas le levier du ressenti owner.

## 4. AXE D — Backend/temps réel

### D1 « Menu API : pas de compression, TTL 60 s, re-encode » — **PARTIEL, impact caisse NUL**
- VRAI : `Frontend/MenuController.php:65-71` = `Cache::remember("kiosk.menu.branch.X", 60 s)` + `response()->json()` ré-encode par requête ; pas d'ETag (et le rapport a raison de dire de NE PAS l'activer en l'état — pièges MenuSnapshot).
- L'endpoint est BORNE-only ; la caisse consomme `/api/admin/item` (mesuré 48-83 ms). Compression = conf serveur web (hors repo). Gain marginal.

### D2 « whereDate non-sargable + index manquants + MAX(queue_number) non borné » — **REJETÉ (déjà corrigé / échelle)**
- `grep whereDate` dans `OrderService.php` = **0 hit** ; KDS/OSS services portent les commentaires « [RED-team P1 perf 2026-05-17] whereDate non-sargable → range query (uses idx_orders_datetime) » (`KitchenDisplaySystemOrderService.php:89,545`, `OrderStatusScreenOrderService.php:73,228`) — la conversion réclamée a été faite il y a 2 mois.
- **EXPLAIN réel** (3 106 orders) : `Index range scan on orders using idx_orders_datetime … cost=5.21 rows=11`. Items : `Index lookup using idx_items_status_category, rows=69`. 11 index existent sur orders (`idx_orders_branch_status`, `idx_orders_datetime`, `idx_orders_status_updated`…).
- `allocateQueueNumber` (`OrderService.php:3158-3196`) : borné par `business_date` + `LIKE 'A%'` sous `Cache::lock` 30 s — PAS « tout l'historique branche ». À l'échelle Le Cayenne (quelques centaines de commandes/jour), rien à gagner.

### D3 « Re-fetch complet à chaque événement Echo » — **VRAI (pattern), lignes citées obsolètes** ✅
- **Vérifié POS** : `PosComponent.vue:2962-3010` `_subscribeEcho` → `OrderCreated`/`OrderStatusChanged`/`OrderPaidAtCounter` déclenchent CHACUN **3 GET complets** (`loadKioskCashOrders` + `loadActiveOrdersStats` + `loadReadyOrders`) ; le payload de l'événement est jeté. Mesuré au calme : counter-collect 174-369 ms + oss-order 58-110 ms ×2. En rush (N événements/min), multiplication réelle.
- Les lignes KDS/OSS citées (577/809/144) ne correspondent plus ; le KDS est déjà debouncé 300 ms + borné 50 (note du rapport lui-même).
- Remède court validé : **debounce/coalesce 300-500 ms des 3 loads POS** (S/M) avant les « mutations incrémentales » (L).

---

## 5. TROUVAILLES HORS RAPPORT (mesurées pendant l'audit)

1. **Flood CSP-report** : ~50 POST `/api/frontend/csp-report` en <1 s par interaction. Cause LOCALE : `.env APP_URL=http://127.0.0.1:8766` ≠ origin servie :8000 → toutes les images cross-origin violent la CSP report-only. Artefact d'env local (le VPS a APP_URL=domaine), MAIS montre que le POS peut marteler ce endpoint : un rate-limit/dédoublonnage client sur le report serait sain.
2. **Refetch systématique après CHAQUE ajout panier** : `PosComponent.vue:3846-3848` `onProductAddedReturnToCategories` → `allCategory()` → `itemList()` = re-GET `/api/admin/item` complet + re-render grille + retour hub après chaque article (comportement POS-CATEGORY-FIRST voulu par l'owner 2026-06-23, mais le REFETCH n'est pas nécessaire — les 55 items sont déjà dans le store). Contribue directement au ressenti « ajout pas fluide ».
3. **~60 requêtes API au mount du POS** (walk-in-customer appelé 2×, 349-589 ms). Dédoublonnage possible.
4. **Double persistence du panier** : plugin vuex (`index.js:280`) + module scopé (`posCart.js`) écrivent tous les deux — l'un des deux suffit.
5. Un build `mix watch` (dev, non-minifié) tourne sur cette machine — ne jamais mesurer/déployer ces bundles (deploy-vps.sh rebuild proprement).

---

## 6. FIXES VALIDÉS PRIORISÉS (gain × risque × effort, terrain V1 single-box, frozen/NF525-safe)

| # | Fix | Gain estimé | Risque | Effort | Frozen ? |
|---|---|---|---|---|---|
| 1 | **A4 — vignettes réelles** : corriger `Item::getThumbAttribute` fallback pour servir une vignette pré-générée (WebP/PNG ≤320 px, script one-shot sur `public/images/menu`) au lieu du PNG 2,5 Mo | **-95 % payload images** caisse ET borne (15-20 Mo → <1 Mo/catégorie) ; décodage/mémoire hardware faible | Faible (fallback visuel inchangé si vignette absente) | M | Non (`Item.php` non-frozen) |
| 2 | **A2 — `filemtime()`** au lieu de `time()` sur `master.blade.php:54,323` | -341 Ko re-DL à chaque reload POS | Nul | S | Non (master.blade non-frozen ; NE PAS toucher admin-pos-v4.blade.php frozen) |
| 3 | **§5.2 — supprimer le refetch `/api/admin/item` après chaque ajout** (servir le hub depuis le store, refetch max 1/60 s) | ~100-500 ms + un re-render par article ajouté ; fluidité directe du geste owner | Faible (data déjà dans le store) | S/M | Non (PosComponent non-frozen) |
| 4 | **B1 — retirer `posCart` des paths persistedstate** (module scopé v3 le persiste déjà) + throttle `setState` 250-500 ms | -12 écritures/-228 Ko sérialisés par ajout | Faible (tester restore panier) | S/M | Non |
| 5 | **D3 — debounce/coalesce 300-500 ms** des 3 loads Echo du POS (`_subscribeEcho`) | Fin des rafales 3×GET par événement en rush | Faible | S/M | Non |
| 6 | **OPS — VPS** : vérifier `.env` prod (QUEUE=redis + worker `high,default` — garde 503 déjà en place), conf nginx gzip+cache statique (les règles .htaccess n'agissent que sous Apache) | Transport prod (bundles+images 1er load) | Nul (ops) | S | — |
| 7 | **A1 résidu — servir `/admin/pos` via l'entrée `pos-app.js` existante** (ou sortir firebase/dashboard de app.js) | 785→~600 Ko gz boot prod (cible 220 documentée) | Moyen (blade switch, tests boot) | M/L | Non, mais à gater par tests boot |

**Écartés / rejetés avec preuve** : virtualisation B2 (55 items, 8-52 ms/tap), index+sargable D2 (EXPLAIN range scan, whereDate déjà éradiqué, 3 106 rows), fuite inter-caissier B1-bonus (pos_cart_v3 scopé), « un seul bundle » A1 (multi-entry+split+version depuis avril), « aucun cache-control/fingerprint » A3 (.htaccess PERF-015 + mix.version), « submit 0,6-1,5 s » C1 (mesuré 155-235 ms avec redis), plan externe #5 « N+1 ItemCategoryService@show 50→2 » (le POS V5 n'appelle pas ce endpoint pour sa grille — `/api/admin/item` unique), ETag menu D1 (le rapport lui-même le proscrit — d'accord).

**Garde-fous §6 du rapport externe : vérifiés et conformes** — pricing serveur intact (POST recalcule, `PricingService` SSOT), cache menu cloisonné par branche (`kiosk.menu.branch.{id}`), posCart scopé caissier (déjà fait), outbox `afterCommit` (déjà fait, `PersistOrderCreatedToOutbox.php:62-75`), aucun secret client. Aucun des fixes 1-7 ne touche une frozen zone ni un invariant NF525.
