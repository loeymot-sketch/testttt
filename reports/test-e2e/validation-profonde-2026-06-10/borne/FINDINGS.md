# W-A BORNE — Findings (validation profonde 2026-06-10)

## F-BORNE-01 — P1 — Borne atterrit sur « Boissons » au lieu de la 1re catégorie — **HEALED (worktree, à merger)**
- **Preuve** : `captures/cycle-1/a2-sidebar-full.jpg` (zone = BOISSONS à l'entrée fraîche) ; dump API `GET /api/frontend/menu` → ordre catégories `[10,11,8,9,6,7,5,4,3,2,1]` ; repro PHP standalone : `sortBy([fn,fn])` → `10,11,8,9,6,7,5,4,3,2,1`, chained sortBy → `1..11`.
- **Root cause** : `app/Services/Kiosk/KioskMenuService.php:251` — `projectCategories()` utilisait `sortBy([fn, fn])` (interprété comme paires [clé, direction]) — même classe de bug que Wave Y A-001 (items, corrigé à `sortItems` :294-299 mais PAS pour les catégories). Le front (`resources/js/store/modules/kioskMenu.js:284-287, 313-316`) auto-sélectionne `categories[0]` ⇒ landing = dernière catégorie réelle (« Boissons »).
- **Heal appliqué (ce worktree, non-frozen)** : chained `sortBy` stable (id puis sortFor) + test de régression `tests/Feature/Services/Menu/KioskMenuCategoryOrderRegressionTest.php` (ROUGE sur code non patché, VERT patché). PHPUnit : MenuProjectionParitySentinelTest 6/6 ✓, KioskEndpointsTest 17/17 ✓.
- **⚠️ Limite** : :8766 sert le checkout `pre-cloud-exec` (non patché) → les captures montrent toujours le landing « Boissons ». Merge du patch sur la spine requis pour le live.

## F-BORNE-02 — P2 — Catégorie « Sandwich Cayenne » : items upsell affichés en premier avec description brute « Upsell item »
- **Preuve** : `captures/cycle-1/a2-cat-1.jpg` — « Boisson Seule » €2,00 / « Frites Seules » €2,00 / « Menu (Frites + Boisson) » €3,00 affichés avant les sandwichs signature (Sandwich Cayenne €7, Big Cayenne €9,50), description visible « Upsell item » (jargon EN), badge « Nouveau ».
- **Root cause** : data — items 1, 2, 3 rangés `item_category_id=1` avec description « Upsell item » et `order` faible. Le tri items (corrigé Wave Y) respecte `order` croissant → companions en tête.
- **Reco** : data-ops owner (réécrire description FR + sort après les sandwichs, ou catégorie dédiée masquée) — pas de heal code aveugle.

## F-BORNE-03 — P2 — Loyalty : message throttle brut anglais « Too Many Attempts. » montré au client borne
- **Preuve** : run log cycle-1 `[A6] invalid-code error="Too Many Attempts."` + capture `a6-loyalty-invalid-throttled.jpg` (cycle re-run).
- **Root cause** : `routes/api.php:1427` `POST /frontend/loyalty/check` → `throttle:10,1` ; `KioskLoyaltyComponent.vue:505-506` affiche `err.response?.data?.message` brut (pas de mapping 429 → i18n FR).
- **Reco** : mapper 429 → message FR localisé (composant NON-frozen) ; P2 — documenté, pas healé dans ce lot (scope).

## F-BORNE-04 — P3 — Idle : copy « CHOISISSEZ UNE OPTION POUR COMMENCER » avec une seule option visible (« À emporter », dine-in OFF V1)
- **Preuve** : `captures/cycle-1/a1-order-type.jpg`.

## F-BORNE-05 — P3 — Format monétaire mixte : panier/wizard « €8,50 » (symbole préfixé) vs écran caisse « 10,00 € » (suffixe FR)
- **Preuve** : `a4-cart-2lines.jpg` (€8,50) vs `a7-confirmation.jpg` (« Montant à régler 10,00 € »).

## F-BORNE-06 — P3 — Propagation rupture → borne ≤ 60 s (cache menu serveur)
- **Preuve** : échec initial A8-restore (badge persistant ~3 s après restore) ; `app/Http/Controllers/Frontend/MenuController.php:66-73` `Cache::remember kiosk.menu.branch.{id}` TTL `kiosk.menu_cache_ttl` (60 s), commentaire :25 « invalidé par les events d'availability (future) » — un flip direct DB ne bust pas le cache. V1 single-box : acceptable, à connaître en exploitation.

## Notes (non-findings)
- Pollution e2e du clone (cats `E2E-CAT-*` 13-15, items 61-64) correctement absente de la surface borne (catégories vides filtrées).
- Sidebar : 11 catégories réelles, image présente pour chacune (`a2-sidebar-cycle1.json`).
- Clamp qty 20 (fix F1 kioskCart `MAX_ITEM_QTY` :24) validé : 25 clics + → qty=20, ligne €140,00 = 20×€7,00, total €141,50 (`a4-clamp-20.jpg`).
- Idempotency double-clic confirm : 1 seule commande créée (order 4470, payment_status=15 PENDING_COUNTER, fiscal NULL, source kiosk).

## F-BORNE-07 — P1 — TypeError au rendu du panier contenant une ligne upsell — peut BLANCHIR tout le panier client — **HEALED (worktree, à merger)**
- **Preuve** : run cycle-1 `[Z][HARD] A5-accept pageerror (line.item_variations || []).map is not a function` (×2) ; reproduit par test unitaire.
- **Root cause** : les lignes ajoutées par l'upsell (FROZEN `KioskUpsellComponent.vue:234`) portent l'ANCIEN format objet `item_variations: { variations: {}, names: {} }` / `item_extras: { extras: [], names: [] }` ; `KioskCartComponent.vue:542-546` (`cartLineAllergenSelections`) mappait ces champs comme tableaux sans le guard dual-format déjà appliqué à `getItemSelectionSummary` ([GAP-22-2] :556).
- **Heal appliqué (ce worktree, fichier NON-frozen)** : guards `Array.isArray(...)` sur les deux champs + test `tests/js/kioskCartUpsellLegacyModifiersGuard.spec.js` (ROUGE non patché / VERT patché). Vitest KioskCart : 15/15 + 2/2 ✓. Effet avant fix : badges allergènes silencieusement absents des lignes upsell + pageerror à chaque rendu.
- **⚠️ Impact aggravé prouvé (cycle 2)** : la même TypeError (variante console minifiée `(e.item_variations || []).map`, `kiosk-shell.js:2:83143` = cartLineAllergenSelections) a AVORTÉ le rendu initial du panier → **panier rendu BLANC** alors que 2 articles y étaient (capture `captures/cycle-2/a5-cart-with-upsell.jpg` + assertion 0 ligne). Manifestation intermittente (cycle 1 : rendu OK malgré 2 pageerrors ; cycle 2 : rendu avorté) ⇒ P1 confirmé côté client.
- **⚠️ Limite** : :8766 sert les assets buildés de la spine → l'erreur reste observable live tant que le patch n'est pas mergé+rebuildé ; classée « justified-known » dans le bilan Z du spec + retry-1-reload documenté en A5 (A5-BLANK-CART-F07).

## F-BORNE-08 — P3 — Boot borne : sonde pré-auth `GET /api/login` → 401 + erreur console à CHAQUE boot de contexte
- **Preuve** : errors-cycle JSONL (1×401 + 1× console mirror par test A1→A8) ; `routes/api.php:151-153` (route nommée `login` renvoyant 401 JSON, cible de redirect du middleware auth pour les appels pré-token).
- **Impact** : bruit console/log uniquement (l'auto-login machine suit immédiatement). Reco V1.0.x : sauter l'appel authentifié avant l'acquisition du token kiosk.

## Note A6 — throttle partagé
`throttle:10,1` inline est keyé par user SANS préfixe de route (Laravel) → `loyalty/check`, `loyalty/balance`, `coupon-checking`… partagent le MÊME bucket pour l'utilisateur machine borne, d'où des 429 plausibles en navigation normale ; renforce F-BORNE-03 (mapper 429 → message FR côté borne).
