# NUIT Wave A2 — Cible : MOBILE RN standalone (mobile/) — data / palette / logique

HEAD ~86e3eee22 · posture refute-by-default · CODE-ONLY (standalone no-API V1 = attendu).
Slug : na2-mobile-standalone

## Verdict : IMPROVABLE (1 P3 réel + reproduit ; le reste HELD-GREEN)

Convergence quasi-totale : le miroir data est exact, la palette est conforme, la
logique multi-viandes est correcte, 0 produit inventé. Un seul écart réel : un prix
« Menu complet » codé en dur à 3,00 € dans l'étape wizard alors que tout le reste de
l'app (total, récap, panier) et le SSOT DB facturent 2,50 €. Le miroir WEB avait déjà
été corrigé ; le mobile a été oublié.

---

## FINDING — P3 (improvement / UI-logic) : « Menu complet +3,00€ » affiché dans l'étape, mais +2,50€ réellement ajouté (drift SSOT + désync avec le miroir web)

**Fichier** : `mobile/screens-item-steps.jsx:525`

```js
const options = [
  { id: 'full',    name: 'Menu complet',  desc: 'Frites + Boisson', price: 3.00, emoji: '🍟🥤' },  // ← 3,00 codé en dur
  { id: 'frites',  name: 'Ajouter Frites', desc: 'Frites uniquement', price: 2.00, emoji: '🍟' },
  { id: 'boisson', name: 'Ajouter Boisson', desc: 'Boisson uniquement', price: 2.00, emoji: '🥤' },
  { id: 'none',    name: 'Sans formule',   desc: 'Plat seul',         price: 0,    emoji: '🚫' },
];
```

Ce `price: 3.00` n'alimente QUE l'affichage du badge de la carte de choix
(`screens-item-steps.jsx:548-551` → « +3,00€ »). Le calcul réel n'utilise jamais cette
valeur : `computeTotal()` (`:190-203`) mappe `menuChoice==='full'` → `formuleId:'f-menu'`
et lit le prix depuis `window.LC.menu.formules` = **2,50 €** (`mobile/data/menu.js:181`).
De même la ligne de récap `menuLabel` (`:780-784`) lit FORMULES = **2,50 €**, et la
ligne panier (`:955-956`, `:985`) dérive de `computeTotal` = **2,50 €**.

Donc dans le MÊME wizard : l'étape « Faire un menu » annonce **+3,00€**, mais le total
en cours, le récapitulatif et le panier ajoutent **+2,50€**. Le client voit deux prix
contradictoires pour la même option.

**SSOT** : DB `items` cat 27 « Menu (Frites + Boisson) » = **2,50** (vérifié tinker
READ-ONLY, status 5) ; `OwnerMenuUpdate20260623Seeder.php` ; le test canon
`mobile/tests/menu.spec.js` passe « Tacos M + menu = 9,40€ (6,90+2,50) ». → la vérité
est 2,50 ; le 3,00 est une valeur stale.

**Désync avec le sibling web** (preuve que c'est un oubli, pas un choix) : le miroir web
a DÉJÀ été corrigé — `/Users/1millnonstop/Downloads/web/wizard-v2.jsx:126` :
`{ id:'full', name:'Menu complet', price: 2.50, popular:true, savings:1.50 }`, et le
plan web note explicitement « Menu complet = 2,50 … Corriger la donnée si elle dit 3,00 »
(`/Users/1millnonstop/Downloads/web/reports/PLAN_WEB_SYNC_CAISSE_2026-06-26.md:28`). Le
mobile n'a pas reçu ce correctif.

**Repro (READ-ONLY, statique + DB)** :
1. `grep -n "price: 3.00" mobile/screens-item-steps.jsx` → ligne 525 (badge « +3,00€ »).
2. `grep -n "f-menu" mobile/data/menu.js` → `price: 2.50` (source du calcul réel).
3. tinker : `DB::table('items')->where('name','Menu (Frites + Boisson)')->value('price')` → `2.50`.
4. Parcours : Tacos/Sandwich/Burger/Galette (has_menu_addon) → étape « Faire un menu » →
   carte « Menu complet » affiche +3,00€ ; le total CTA/récap/panier ajoutent +2,50€.

**Impact** : cosmétique-logique, standalone V1 (non-prod). Le client est facturé MOINS
que l'affiché (pas de risque fiscal/surfacturation), mais l'incohérence intra-écran est
réelle et contredit le SSOT + le miroir web. Angle « logique compo cohérente » du mandat.

**Fix proposé (non-frozen, 1 ligne)** : supprimer le prix codé en dur et lire la valeur
canonique comme le fait déjà `menuLabel` :
```js
{ id: 'full', name: 'Menu complet', desc: 'Frites + Boisson',
  price: ((window.LC?.menu?.formules||[]).find(f=>f.id==='f-menu')||{}).price ?? 2.50,
  emoji: '🍟🥤' },
```
(ou simplement `price: 2.50`). Aligne l'affichage sur le total, le SSOT et le web.

---

## HELD-GREEN (attaques ayant échoué à casser — attestations de robustesse)

1. **Miroir data = DB (0 produit inventé)** — REFUTÉ le grief. 48 items DB status 5
   confrontés à `menu.js` : Cayenne 7,40 / Suprême 7,00 / Méga 8,00 / Terminator 9,00 /
   Galette 6,50-7,00 / 6 burgers (Chicken 4,90 … Grill 8,00) / Tacos M 6,90 · L 7,90 /
   Bols 7,90 / Frites 2,50-4,00 / Desserts 3,50 / canettes 1,90 · Eau 1,00 · Capri 1,50 /
   Menu enfant 4,90 — **tous les noms et prix concordent**. `mobile/tests/menu.spec.js`
   passe 0 failure (incl. checks « fantôme absent » Big Cayenne/Bowl…/Menu Nuggets, 7
   viandes exactes, 12 sauces, 9 suppléments @0,90, 3 crudités, 31 produits/9 catégories)
   sur mobile ET web mirror.

2. **Palette NOIR/ORANGE/JAUNE/BLANC respectée** — REFUTÉ le grief « rouge Cayenne ».
   Aucune occurrence `#F4501E` dans mobile/*. Tokens dominants : NOIR `#0A0A0A` (31×),
   JAUNE `#FFD93D` (13×), ORANGE `#FF5A1F` (12×), BLANC. `#E5341A` = token sémantique
   `--red` (danger) + gradient `--orange-deep`, usage légitime non-brand. Les
   terracotta/bleu/violet (`#D97757`/`#2A6FDB`/`#7A5AE0`) n'existent QUE dans les
   commentaires de `tweaks-panel.jsx` (outil de design désactivé), pas dans l'UI rendue.

3. **Logique multi-viandes correcte** — Tacos L `viandes:2`, Méga/Terminator `viandes:2`,
   Galette/Bols/Tacos M `viandes:1`, burgers `viandes:0` (composition fixe). L'étape
   `ScreenStepViandes` exige `meatIds.length === item.viandes` avant validation
   (`:160`, `:322-329`) ; sélection glissante au-delà du max. Le bug kiosk « Viande 2
   perdue » n'a pas d'équivalent ici (sélection stockée en tableau plat, pas d'aplatissement
   objet fragile).

4. **Panier : pas de double-comptage qty** — `price` = prix unitaire (options incluses,
   qty=1), `lineTotal`/`unitPrice` séparés ; barre panier et sous-total utilisent
   `price*qty` (`screens-main.jsx:281,599`). `addToCart` (`index.html:172`) normalise
   `qty: it.qty || 1` → l'ajout rapide « + » ne produit pas de `NaN`.

5. **Quick-add « + » correctement gaté** — pour tout item personnalisable
   (`viandes>0 || has_sauce || has_supplements!==false || has_menu_addon || has_frites_style`)
   le bouton ouvre le wizard au lieu d'ajouter une compo vide (`screens-main.jsx:261-263`).
   Seuls desserts/boissons (compo nulle) permettent l'ajout direct → pas de commande
   incomplète au panier.

6. **FR-lock / allergènes** — UI intégralement française ; agrégation allergènes
   item+suppléments+boissons au récap (FIC 1169/2011, `screens-item-steps.jsx:798`).
   Prix formatés `,` décimale FR partout.

## Notes mineures (non retenues comme findings)
- `priceFor` facture +0,50€/sauce au-delà de la 1ʳᵉ (`menu.js:457-459`). Divergence
  possible avec le kiosk (sauce `max_select=1`), mais = choix de design standalone,
  non-reproductible comme bug (aucun sur-débit vs SSOT car parcours borne différent).
- Nom « Option Gratiné » (mobile `sb-gratine`) vs « Boule gratinée » (DB) — libellé,
  prix 2,00 identique. Cosmétique.
