# NUIT Wave A2 — WEB standalone (data/logique) — na2-web-standalone

Cible : `/Users/1millnonstop/Downloads/web` (React-JSX standalone, `window.LC.menu`).
Posture refute-by-default. CODE-ONLY (absence d'API V1 = attendu, non signalée).
HEAD backend ~86e3eee22. DB `foodking_e2e` LIVE.

## Attaques menées
1. **Menu = miroir DB exact + prix SSOT** — comparé `data/menu.js` (31 items) vs
   `database/seeders/OwnerMenuUpdate20260623Seeder.php` + requête live
   `Item::withoutGlobalScopes()->where('status',5)->where('is_available',1)`.
   → **Tous les prix matchent** : Cayenne 7,40 / Suprême 7,00 / Méga 8,00 / Terminator 9,00 /
   Galette 6,50-7,00 / Chicken 4,90 / Cheese 6,00 / Double 7,00 / Fish 6,00 / Big 9,00 / Grill 8,00 /
   Tacos M 6,90 / Tacos L 7,90 / Bols 7,90 / Frites 2,50-4,00 / Desserts 3,50 / Canettes 1,90 /
   Eau 1,00 / Capri 1,50 / Menu enfant 4,90. **0 produit inventé** (les 31 slugs web existent tous en DB).
2. **Produits vendables DB vs web** — DB expose 7 bols actifs (28-32 @10,50-12,50 + 41/45 @7,90),
   web n'en montre que 2 → **déjà escaladé owner** (`project_web_api_wireup_caisse` mémoire :
   « 7 bols actifs en caisse vs 2 web »). NON nouveau, non re-signalé.
3. **Parité prix client vs backend (formule/sauce)** — `priceFor` contient un chemin
   multi-sauce +0,50 MAIS le wizard force `max:1` sur la sauce sandwich/bol (WEB-WIREUP 2026-06-26)
   et passe `fritesSauceIds=[]` + `fritesStyleId=undefined` pour la cascade formule → le menu
   (+2,50 flat) n'est PAS sur-facturé côté web. **Robuste, tenu vert.**
4. **XSS rendu client-side** — `grep innerHTML|dangerouslySetInnerHTML|insertAdjacent|document.write|eval`
   sur `*.jsx *.js data/*.js index.html` = **0 sink**. Rendu 100 % JSX (auto-échappé). Robuste.
5. **Total/remise/livraison** — `OrderSummary` : `subtotal - discount + deliveryFee` ; discount vient
   de `api.checkCoupon` (scope web enforced backend) ; total final payé = `order.total` (SSOT backend).
   Robuste, pas de -10% client-inventé.
6. **Drink addon bol** — trouvé 1 incohérence de donnée (voir finding F1).

## Findings

### F1 (P3, durability) — Capri-Sun en boisson de bol : aperçu wizard sur-affiché de 0,40 €
`data/menu.js:priceForDrinkAddon` mappe `'d-capri': 1.90`, mais le catalogue réel du
Capri-Sun (item 59, DB + `DRINKS` web) = **1,50 €**.
- `computeWizardTotal` (wizard-v2.jsx:288) additionne `priceForDrinkAddon('d-capri')=1.90`
  dans le prix de la ligne bol.
- `api.js:resolveOrderItems` (l.399-402) émet le Capri comme **ligne séparée** via
  `DRINK_ID_TO_SLUG['d-capri']='capri-sun'` → facturée par le backend au catalogue **1,50 €**.
- Résultat : aperçu panier « Bol + Capri » = +1,90, total réellement débité (order.total SSOT) = +1,50.
  → **le client voit 0,40 € de PLUS qu'il ne paie** (pas de préjudice client, backend=SSOT, pas
  d'impact NF525). Tous les autres drinks (canette 1,90 / eau 1,00) matchent le catalogue → OK.
- **Repro** : `priceForDrinkAddon('d-capri')` renvoie 1.90 ; `LC.menu.findItem('capri-sun').price` = 1.50 ;
  DB `Item::find(59)->price` = 1.500000.
- **Fix** : `data/menu.js` mettre `'d-capri': 1.50` dans le map `drinkPriceMap` de `priceForDrinkAddon`
  (aligner sur le catalogue). 1 ligne, non-frozen.

### F2 (P3, improvement) — Points fidélité estimés sur le frais de livraison
`funnel.jsx:101` affiche `+{Math.round(total)} pts` où `total` inclut `deliveryFee` (l.69).
Le client se voit promettre des points sur les frais de port (ex. +4 pts pour 4 € de livraison).
Estimation d'affichage uniquement (le solde réel vient du backend), mais sur-promesse cosmétique.
- **Fix** : baser l'affichage points sur `subtotal - discount` (hors livraison), cohérent avec
  l'assiette d'acquisition backend. Non-frozen.

## Verdict
Le web standalone est **cohérent** : données menu = miroir fidèle de la DB (prix SSOT vérifiés
un-à-un, 0 produit inventé), rendu XSS-safe, parité prix client/backend soigneusement tenue
(formule flat, sauce max=1, cascade non-facturée). Seuls 2 P3 cosmétiques (aperçu Capri bol,
points-sur-livraison) — aucun préjudice client, backend reste SSOT. La divergence 7-bols est
déjà escaladée. **CONVERGENCE quasi-complète** (IMPROVABLE sur 2 micro-alignements d'affichage).
