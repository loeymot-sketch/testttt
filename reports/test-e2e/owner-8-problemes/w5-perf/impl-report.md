# W5-PERF — Rapport d'implémentation (fixes validés verdicts.md §6)

> Implémenteur GStack TDD — 2026-07-06, serveur local http://127.0.0.1:8000, base `701bca335`.
> Périmètre = UNIQUEMENT les fixes validés par mesure dans `verdicts.md` §6 (1-5 + bonus).
> Frozen zones : **0 ligne touchée** (pos-wizard.js, admin-pos-v4.blade.php, PaymentComponent,
> KioskWizard*, fiscal, PricingService, kdsSymbolic, EscPos, config/menu_images.php intouchés).

---

## 1. MESURES AVANT / APRÈS (même protocole, même machine, build dev, admin@lecayenne.fr)

Protocole identique à verdicts.md §0 : Playwright chromium headless, instrumentation
réseau (octets réels des réponses) + hook `Storage.setItem` (écritures/octets), 10 ajouts
panier (Boissons → 1er produit → « Ajouter au panier », popup wizard réel).

| Métrique | AVANT | APRÈS | Δ |
|---|---|---|---|
| **Images au hub catégories** (11 req) | **2,93 Mo** | **1,60 Mo** (dont tuiles menu ≈ 0,18 Mo webp ; le reste = `theme-logo.png` 1,34 Mo hors périmètre, cf. §5) | **-45 %** (tuiles menu : **-88 %**) |
| **Images au 1ᵉʳ tap catégorie** (Sandwichs, 41-43 req) | **32,70 Mo** | **0,71-0,74 Mo** | **-97,8 %** ✅ levier n°1 |
| **GET `/api/admin/item` par ajout panier** | 1 par ajout (10/10) | **2 pour 10 ajouts** (amorce + resync 60 s) ; **0 en régime** | **-80 % / -100 % régime** |
| **localStorage par ajout** (régime) | ~9 écritures / **~135 Ko** JSON (pics 13/195 Ko) | 6 écritures / **~4,5 Ko** (clé scopée `pos_cart_v3`, pics 46 Ko seulement quand le resync 60 s écrit `vuex`) | **-97 % octets** |
| **GET `/admin/pos/walk-in-customer` au mount** | 2 | **1** | -50 % (349-589 ms économisés) |
| **Latence clic « Ajouter » → ligne panier** | 236 ms | ~227 ms (2 échantillons valides¹) | ≈ inchangé (le paint n'était pas le poste ; le gain = réseau + re-render + sérialisation supprimés) |
| **`pos-wizard.js`+`css` re-téléchargés à chaque reload** | 341 Ko (`?v=time()` = URL neuve à CHAQUE rendu) | URL **stable** (`?v=filemtime`) — cacheable (prod : .htaccess Expires 1 an, PERF-015) | -341 Ko/reload en prod |
| POST `/api/frontend/csp-report` au mount | 33 | 33 — **artefact env local** (APP_URL :8766 ≠ origin :8000 ; émetteur = mécanisme natif `report-uri` du NAVIGATEUR, aucun code app n'appelle ce endpoint — grep : seule référence JS = allowlist anti-toast `bootstrap.js:117`). **0 code changé** (mission : ne pas toucher la CSP prod) | — |
| Vignettes générées (one-shot idempotent) | — | **152 webp ≤320 px : sources 84,8 Mo → 1,9 Mo** ; `sandwich-cayenne.png` 2,61 Mo → **15,0 Ko** (×174) | — |

¹ Les autres échantillons = artefact de mesure (fusion de lignes panier : la qty est un `<input>`, invisible à la signature innerText) — pas un comportement produit ; wizard fermé + toast immédiats sur capture.

**Parcours caissier réel « hub + 1 catégorie »** : **35,6 Mo → 2,3 Mo d'images (-93,5 %)** — c'est le ressenti « années 2000 » adressé, surtout sur le hardware faible de la caisse (décodage PNG 1536×1024 par tuile supprimé).

---

## 2. FIXES IMPLÉMENTÉS (file:line)

### Fix 1 — Vignettes réelles (verdicts A4, levier n°1)
- **`app/Support/MenuImageThumb.php`** (nouveau) — résolveur : `<base_path>/thumbs/<nom>.webp` si présent, sinon null (l'appelant garde le plein format → comportement antérieur préservé, y compris images ajoutées sans vignette). `config/menu_images.php` **non touché** (LOCK parallèle) : la résolution est dérivée du filename déjà résolu.
- **`app/Console/Commands/GeneratePosThumbsCommand.php`** (nouveau) — `php artisan images:generate-pos-thumbs {--max=320} {--quality=82} {--force}` : GD (vérifié : ext-gd + imagewebp), alpha préservé, jamais d'upscale, **idempotent par filemtime** (2ᵉ run : « 0 générées, 152 à jour ») ; regénère si la source est plus récente (rattrape le swap d'images du LOCK viandes/boissons).
- Fallbacks branchés : `app/Models/Item.php:100-107`, `app/Models/ItemCategory.php:98-105` (+ le hub POS = 11 tuiles cat), `app/Models/ItemVariation.php:86-92` (viandes/sauces du wizard), `app/Models/ItemExtra.php:62-68` (suppléments/crudités). Chemin medialibrary (conversions 19-36 Ko) inchangé — prioritaire comme avant.
- **152 vignettes committées** (`public/images/menu/thumbs/`, 2,2 Mo) : le VPS les sert dès le pull sans dépendre d'un hook deploy.

### Fix 2 — Refetch après chaque ajout (verdicts §5.2)
- **`resources/js/components/admin/pos/PosComponent.vue:3913-3921`** `onProductAddedReturnToCategories` : retour hub (reset name+category — mandat POS-CATEGORY-FIRST intact) **sans** `allCategory()` ; refetch resync **max 1/60 s** (`_lastPostAddCatalogRefetchAt`). Les événements stock réels restent en push : `_onCatalogChanged` → `itemList` et `ItemAvailabilityChanged` (type full) **inchangés** — la dispo/86 affichée ne régresse pas. Consommateurs du refetch tracés : hub = `posCategory/lists` (pas les items) ; banner rupture + drinksCatalog (cache W2) tolèrent le throttle ; chaque drill-in de catégorie refetch de toute façon (`setCategory`).

### Fix 3 — localStorage 228 Ko/ajout (verdicts B1)
- **`resources/js/store/index.js:274-311`** : `"posCart"` **retiré** des paths persistedstate + **`filter:`** qui saute la sérialisation full-state sur les mutations `posCart/*` + `getState` purge un snapshot `posCart` legacy de la clé `vuex` (jamais de réhydratation non scopée au boot).
- ⚠️ **Vérification du restore exigée par la mission → BUG RÉEL TROUVÉ** : le module `auth` n'est **pas namespacé** → `getters['auth/authInfo']` renvoyait TOUJOURS `undefined` → `posCart/setScope` recevait `userId: null` → **la clé scopée `pos_cart_v3:b<branch>:u<user>` (POS-9.1.9) n'a JAMAIS été écrite ni relue** ; le panier ne survivait que par le path vuex (celui que le verdict fait retirer). Corrigé :
  - **`PosComponent.vue:2793-2806`** `applyPosBranchScope` — résolution userId en cascade (`getters.authInfo` global + `state.auth.authInfo`), même pattern défensif que `authBranchId()` ;
  - **`resources/js/store/modules/posCart.js:345-364`** `setScope` — commit `subtotal` après `hydrateFromScope` (bug latent : `shapePosListItem` droppe le champ dérivé `total` → lignes restaurées à « 0,00 € »).
- **Preuve e2e restore** (pos@lecayenne.fr, branch 1) : ajout Coca 1,90 € → clé `pos_cart_v3:b1:u3` écrite (438 octets vs ~19 Ko full-state), `vuex` sans posCart → **reload → ligne restaurée à 1,90 €** ✅. Bénéfice sécurité gratuit : l'anti-fuite inter-caissier POS-GA-F-41 fonctionne enfin comme documenté.

### Fix 4 — Cache-buster time() (verdicts A2)
- **`resources/views/master.blade.php:57, 329`** : `?v=2-{{ time() }}` / `?v=9-{{ time() }}` → `filemtime(public_path(...))` (préfixes de version manuels 2-/9- conservés ; busting réel au changement de fichier préservé). Grep repo : plus aucun `{{ time() }}` asset hors `admin-pos-v4.blade.php` (**FROZEN §7 — non touché**, comme exigé).

### Fix 5 — Echo → 3 GET complets (verdicts D3)
- **`PosComponent.vue:2408-2422`** : `_schedulePanelsRefresh` = `debounce(..., 400)` trailing (fourchette validée 300-500 ms) regroupant `loadKioskCashOrders` + `loadActiveOrdersStats` + `loadReadyOrders`, garde `_destroyed`.
- **`PosComponent.vue:3040-3069`** : les 3 handlers Echo (`OrderCreated`/`OrderStatusChanged`/`OrderPaidAtCounter`) appellent le refresh coalescé — une rafale de N événements = **1 refresh** (avant : N×3 GET). `_notifyNewOrder` (son + toast caissier) reste **immédiat**. Polling de secours + refresh post-encaissement inchangés.
- **`PosComponent.vue:2342-2347`** : `cancel()` au `beforeUnmount` (pas de trailing call post-démontage).

### Fix 6 — Bonus validés
- **walk-in 2× au mount** : `PosComponent.vue:2870-2887` `ensureWalkInCustomer` single-flight (`_ensureWalkInInflight` partagée ; corps d'origine → `_ensureWalkInCustomerInner`). Mesuré 2 → 1 GET.
- **csp-report** : 0 code (voir tableau §1) — émetteur = navigateur natif (`report-uri` du header CSP report-only), cause = APP_URL local :8766 ≠ origin :8000. Le VPS (APP_URL = domaine) n'a pas ce flood.

---

## 3. TESTS (counts exacts)

| Suite | Résultat |
|---|---|
| **PHPUnit `--filter='Menu\|Asset\|Pos'`** | **742 passed**, 2 incomplete, 13 skipped (pré-existants), **0 fail** — 135 s |
| dont **`tests/Feature/Menu/PosThumbFallbackTest.php`** (nouveau) | 8 verts : fallback original sans vignette / vignette servie si présente (item + catégorie) / slug non mappé → placeholder / svg jamais réécrit / commande ≤320 px + idempotente + regénère si source plus récente / mapping absent sans exception |
| dont **`tests/Feature/AssetCacheBusterTest.php`** (nouveau) | 3 verts : plus de `{{ time() }}` / filemtime sur les 2 assets / rendu réel du blade stable entre 2 rendus espacés d'1 s |
| **Vitest full** | **318 fichiers / 2214 passed / 3 skipped / 0 fail** — 20 s |
| dont **`tests/js/posNoRefetchOnCartAdd.spec.js`** (nouveau) | 10 verts (contrat source + exécution réelle du handler extrait : 10 ajouts → 1 GET, resync >60 s, retour hub ; + single-flight walk-in) |
| dont **`tests/js/storePersistedPathsSentinel.spec.js`** (nouveau) | 9 verts (posCart hors paths, filter posCart/*, purge getState, restore module câblé, **baseline exacte des 25 paths verrouillée**, ADR-007 locale exclue, bugfix userId verrouillé) |
| dont **`tests/js/posEchoDebounce.spec.js`** (nouveau) | 8 verts (3 handlers → scheduler, 300≤delay≤500, trailing pur, cancel au unmount, polling fallback intact, notification immédiate, comportement rafale/2-rafales/cancel en fake timers) |
| **Baselines adaptées** (2 fichiers, intention préservée) | `tests/js/sentinels/posShortcutsSentinel.spec.js` (promesse X2 verrouillée en 2 maillons handler→scheduler→loadReadyOrders) ; `tests/js/posCartScoped.spec.js` (mock accepte le commit `subtotal` de restore, ordre vérifié) |

## 4. E2E RÉEL (Playwright, serveur live)

- **POS charge** : hub catégories visible, tuiles webp nettes (captures lues : hub, grille Boissons, wizard Coca 1,90 €).
- **Ajout fluide** : catégorie → produit → « Ajouter au panier » → ligne panier → **retour hub auto** ✅, 0 erreur JS, 0 refetch (régime).
- **Commande espèces complète** : **#5507** (serial 0607265507) — `payment_status=5 (PAID)`, `pos_payment_method=1 (cash)`, total 1,90 €, **fiscal_sequence_no=2630 alloué**, ticket modal rendu (N°A0006, rendu 0,10 €, empreinte audit) — capture lue.
- **NF525** : `php artisan fiscal:verify-chain --all` → **CHAIN OK ×4 branches** (avant ET après la commande e2e).
- **Restore panier** : prouvé (§2 fix 3).
- Rebuild **`npm run development`** : webpack compiled successfully (bundles non committés — convention repo, le VPS rebuild via deploy-vps.sh).

## 5. NOTES / RESTES (hors périmètre, 0 code)

1. **`theme-logo.png` 1,34 Mo** à chaque load POS (rendu ~150 px) — asset thème owner (upload admin), pas menu_images. Reco : re-uploader une version optimisée via l'admin (~30 Ko attendu). En prod le .htaccess le cache 1 mois après le 1ᵉʳ hit.
2. **`tests/e2e/02-pos-cash.spec.js` (legacy) rouge PRÉ-EXISTANT** : son step 3 attend des tuiles produit AU LANDING — obsolète depuis POS-CATEGORY-FIRST (2026-06-23, le landing = hub catégories) + il laisse la modal « Session active » ouverte. Mes changements n'y sont pour rien (3 autres tests du fichier verts). À rafraîchir dans une passe e2e dédiée.
3. Latence paint d'ajout ~227 ms ≈ inchangée : le poste dominant restant = popup wizard vanilla (~200 ms, frozen). Les gains W5 sont réseau/render/sérialisation (mesurés §1).
4. `AFTER` hub 11 requêtes : les vignettes ont un `?v=filemtime` stable → en prod (Apache Expires) les reloads suivants = 0 octet image.

## 6. FICHIERS DU COMMIT (scopé, bundles exclus)

Sources : `app/Models/{Item,ItemCategory,ItemVariation,ItemExtra}.php`, `app/Support/MenuImageThumb.php`, `app/Console/Commands/GeneratePosThumbsCommand.php`, `resources/views/master.blade.php`, `resources/js/components/admin/pos/PosComponent.vue`, `resources/js/store/index.js`, `resources/js/store/modules/posCart.js`.
Tests : `tests/Feature/Menu/PosThumbFallbackTest.php`, `tests/Feature/AssetCacheBusterTest.php`, `tests/js/{posNoRefetchOnCartAdd,storePersistedPathsSentinel,posEchoDebounce,posCartScoped}.spec.js`, `tests/js/sentinels/posShortcutsSentinel.spec.js`.
Assets : `public/images/menu/thumbs/*.webp` (152).
Rapport : ce fichier.

**NON committé (pas à moi / convention)** : bundles `public/js/*` + `public/css/app.css` (rebuild VPS), `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (agent LOCK parallèle), `.claude/*`, suppressions worktree pré-existantes (`public/images/menu/assiette_*.png`…).
