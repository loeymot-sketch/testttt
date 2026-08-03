# E2E per-functionality audit — SITE WEB standalone (/Users/1millnonstop/Downloads/web)

HEAD 3c7145bf4 · scope = CODE-ONLY (no-API V1 = attendu). Postiure refute-by-default,
chaque fonctionnalité prouvée par trace code + diff programmatique vs DB `foodking_e2e`.

## Verdict: ALL_OK (client-side) — 0 P0/P1/P2. 3 P3 cosmétiques (dégradation gracieuse).

## Fonctionnalités testées

### 1. Affichage menu (data/menu.js = miroir DB) — OK (PROUVÉ)
- Script node (`/tmp/menucheck.js`) charge `data/menu.js` et diff les 31 items vs DB items status=5.
- Résultat: `ITEMS count: 31`, `INVENTED: []`, `MISSING: []`, `price mismatches: 0`.
- Les 31 slugs + prix matchent EXACTEMENT la DB (cat1×4, cat2×2, cat4×6, cat5×2, cat6×2,
  cat7×2, cat9×3, cat10×8, cat11×2). Aucun produit inventé.
- Catégories cachées DB (cat3 "Sandwich Classique" st10, cat8 "Suppléments" st10)
  correctement ABSENTES du web. 0 produit fantôme.
- Frites cheddar variants DB (SKU 107-110) modélisés côté web en addons `fritesStyle` qui
  reproduisent les mêmes prix (3.50/4.50/5.00/6.00) — vérifié.

### 2. Navigation catégories — OK
- `screens.jsx:376` `items.filter(i => i.cat === cat)` sur slug ; `W_CATS` = 9 cats + 'all'.
- Compteurs par cat `screens.jsx:383` cohérents. Recherche `q` filtre name+desc (l.377).

### 3. Ajout panier — OK
- `index.html:107` addToCart simple (qty:1) ; `index.html:163-176` onAdd wizard stocke
  `price:total(unit), qty, subs, state`. `OrderSummary` somme `price*qty` (funnel.jsx:68).
  Pas de bug quantité² (HEAL WIZ-01 documenté, prix = unit).

### 4. Composition (viande/sauce/extras/formule) — OK
- `wizard-v2.jsx buildSteps` par template (sandwich/tacos/burger/bol/custom/simple).
- Viandes min=max=viande_count (obligatoire) ; sauce cappée **max:1** (= règle backend attr
  Sauce max_select 1) ; crudités défaut all-on ; suppléments +0,90 ; viande suppl +2,50 (max 2) ;
  formule menu radio full/frites/boisson/none. Défauts seedés (init + cascade dynamique HEAL WIZ-04).

### 5. Calcul prix / total / remise — OK (PROUVÉ cohérent backend)
- `priceFor` (menu.js:445) traces node vérifiés vs règles seeder `OwnerMenuUpdate20260623Seeder`:
  Méga+menu=10,50 ; +extra viande=13,00 ; Petite frites cheddar=3,50 ; cheddar+oignon=4,50 ;
  Bol Riz+gratiné=9,90 ; Tacos L+menu=10,40. Extra sauce +0,50 (=PAID_SAUCE_PRICE) — dead car
  UI cappe à 1 (aligné backend).
- Checkout `funnel.jsx:68/127/407` total = subtotal − discount + deliveryFee. Remise = coupon
  validé SERVEUR (`api.checkCoupon`, l.142), pas de -10% client-inventé. Total final = `order.total`
  backend (SSOT, l.344). Format FR `.replace('.', ',')` + `€`.

### 6. Images (filenames existants) — OK (fallback partout)
- 71/73 filenames référencés existent dans backend `public/images/menu/` (vérifié un-à-un).
- Toutes les `<img>` ont `onError` (hide + emoji/icon fallback) : screens.jsx:42, funnel.jsx:80,
  wizard-v2.jsx:480/508/593.
- 2 manquants = `signature/cayenne-hero.png` + `signature/tacos-hero.png` (dossier signature/
  inexistant). `heroFor` NON utilisé dans le JSX ; le seul hero codé en dur (screens.jsx:123)
  a `onError` hide → cosmétique, pas cassé. → P3.

### 7. FR / palette — OK
- `index.html lang="fr"`, currency `€`, format FR. Palette standalone NOIR/ORANGE/JAUNE/BLANC:
  `--ink #0A0A0A`, `--orange #FF5A1F`, `--yellow #FFD93D`, `--cream #FAF7F2` (styles.css:8-27).
  Variants WCAG AA text (--orange-text/--green-text/--red-text) présents. Conforme mandat standalone.

### 8. Checkout logique (sans wireup) — OK client-side
- Routing state-driven home→menu→checkout→payment→confirm→track (index.html:128-142).
- NB: le web EST câblé à `window.LC.api` (override owner documenté MEMORY project_web_api_wireup) —
  hors scope (no-API attendu ; le live-test API relève d'un autre agent). Logique client correcte.

## Défauts confirmés (tous P3 cosmétiques, dégradation gracieuse)

- **P3** `data/menu.js` W_DIET filtre "✨ Nouveau" toujours vide : aucun item n'a le tag 'NEW'
  ni `is_new:true` (tags réels = SIGNATURE/XL/TOP/ENFANT). `DIET_PRED.new` (screens.jsx:379)
  retourne 0 résultat en permanence. Badge 'NOUVEAU' pareillement mort. Repro: cliquer chip
  Nouveau → "0 résultat". Fix: retirer le chip 'new' de W_DIET OU tagger un item NEW.
- **P3** filtre "⭐ Top" ne matche que Tacos L (seul `tags:['TOP']`) ; Double Cheese/Big Burger
  sont `is_featured` + badge mais sans tag matché → résultats incohérents avec les badges affichés.
- **P3** hero signature/*.png manquants (voir §6) — fallback onError, cosmétique.

Aucun défaut reproductible P0/P1/P2. Le standalone est fonctionnellement correct côté client,
data 100% alignée SSOT DB.
