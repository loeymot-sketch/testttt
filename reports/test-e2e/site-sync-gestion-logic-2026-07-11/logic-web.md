# Audit LOGIQUE — Site web standalone Le Cayenne (2026-07-11)

**Périmètre** : logique client JS/JSX (prix panier, wizard, livraison, fidélité, checkout,
cohérence data). Audit statique, lecture seule.
**Repos** : déployé Vercel `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/`
+ miroir source `/Users/1millnonstop/Downloads/web/`.
**Diff des 13 fichiers logiques (menu.js, funnel.jsx, wizard-v2.jsx, api.js, flows.jsx,
loyalty.js, loyalty-v2.jsx, screens*, orders, upsell, account, apiContract)** :
**byte-identiques** entre les deux repos → tout finding s'applique aux DEUX. (Chemins
`fichier:ligne` = repo source `web/`, identiques dans le déployé.)

> NB périmètre : le site déployé a `api-base-url=http://127.0.0.1:8766` → les commandes réelles
> nécessitent un backend public (connu, hors périmètre). L'audit porte sur la **cohérence de la
> logique client** (prix affiché, panier, compo) et son alignement avec la borne/backend.

---

## Verdict : P0 = 0 · P1 = 0 · P2 = 3 · P3 = 2

Aucun prix FAUX facturé au client (P0) ni checkout cassé côté client (P1) : les prix **affichés**
sont internement cohérents (ligne panier = total wizard ; le récap somme = total du pied ;
livraison correcte). Les défauts trouvés sont des **divergences web↔borne** et des **incohérences
d'affichage des points**.

---

## F1 — [P2] Suppléments `galette_only` / `galette_excluded` JAMAIS filtrés → divergence compo/prix web↔borne
`web/wizard-v2.jsx:45-47` (`suppOptions`) + appel `web/wizard-v2.jsx:114` ;
données `web/data/menu.js:170` (`sup-boursin galette_excluded:true`) et
`web/data/menu.js:175` (`sup-boule-gratinee galette_only:true, price:1.00`).

`suppOptions()` fait `M.supplements.map(...)` **sans aucun filtre**. Les flags
`galette_only`/`galette_excluded` sont définis dans la data mais **consommés nulle part**
(`grep galette_only|galette_excluded *.jsx` → NONE).

**Repro** :
- Cayenne (sandwich, cat 1) → l'étape « Suppléments gourmands » propose **« Boule gratinée +1,00 € »**
  (réservée aux galettes côté borne). Client l'ajoute → ligne panier **8,40 €** (7,40 + 1,00).
  À la résolution (`api.js:320-324` `findExtraId(dIdx,['supplement'],'Boule gratinée')`) l'extra
  est **introuvable sur l'item borne → droppé silencieusement** → la caisse facture **7,40 €**.
  → **prix affiché ≠ facturé** + supplément demandé non honoré.
- Galette Normale → propose **« Boursin +0,90 € »** alors que Boursin est `galette_excluded`
  (absent des extras canoniques Galette) → même divergence.

**Fix** : filtrer `suppOptions()` par catégorie de l'item, ex.
`const isGalette = item.category_id === 2;`
puis `M.supplements.filter(s => (!s.galette_only || isGalette) && !(s.galette_excluded && isGalette))`.

---

## F2 — [P2] Aperçu wizard : `Math.round` au lieu de `Math.floor` (points sur-promis)
`web/wizard-v2.jsx:637` : `+<b>{Math.round(total)} pts</b> Pepper Club`.

Le reste du site a été healé round→floor pour parité backend (`funnel.jsx:131,406,560`,
`flows.jsx:130` via `LC.loyalty.earnPoints` = floor). Le **preview live du wizard** est resté en
`Math.round`, **et** hardcode le ratio 1 (n'utilise pas `points_per_euro` réel).

**Repro** : total 7,60 € → wizard affiche **« +8 pts »**, backend crédite `floor(7,60×1)=7`.
Sur-promesse systématique dès que la partie décimale ≥ 0,50.

**Fix** : `{(window.LC?.loyalty?.earnPoints ? window.LC.loyalty.earnPoints(total) : Math.floor(total))} pts`.

---

## F3 — [P2] Base de calcul de l'« earn » incohérente entre 4 écrans
- `flows.jsx:130` (panier)      : `earnPoints(total)` avec `total = subtotal` (hors remise/livraison)
- `funnel.jsx:131,406,560`      : `Math.floor(total × ppe)` avec `total = subtotal − remise + LIVRAISON`
- `orders.jsx:64` (historique)  : `Math.round(Σ totaux)` (round, pas floor)
- crédité réel (`funnel.jsx:406`): `floor(order.total × ppe)` (SSOT backend)

Quatre formules ⇒ pour la même commande, le nombre de points annoncé **change d'un écran à l'autre**
(ex. panier 20 pts → checkout 25 pts si 5 € de livraison). De plus, **gagner des points sur le
frais de livraison** (funnel inclut `deliveryFee` dans la base earn) est discutable métier.

**Fix** : une seule règle partagée `floor(montant_articles_hors_livraison × points_per_euro)`,
appliquée identiquement panier / checkout / paiement / historique.

---

## F4 — [P3] `data/loyalty.js` : modèle redeem LINÉAIRE contredit le backend (multiples de 100)
`web/data/loyalty.js:71-95` : `redeemableEuros(347)` → **3,47 €** (linéaire) et `redeem()` en pas
continu. Or backend + UII réelle (`api.js:494` commentaire « multiples de 100 UNIQUEMENT » ;
`screens.jsx:605-609` `usablePoints = floor(points/100)*100`) → **347 pts → 3,00 €**.

Le doc du fichier affirme « parité mobile : 250 pts = 2,50 € » ce qui **contredit** la règle backend
(250 → 2,00 €, 50 pts restants). Aujourd'hui **latent** : l'UI (`screens.jsx`) recalcule elle-même
`usableEuros` en multiples et n'appelle que `formatEuros`/`earnPoints` du helper. Mais c'est un
**piège** : quiconque câble `LC.loyalty.redeemableEuros()` dans l'UI sur-estimera l'€ utilisable de
jusqu'à 0,99 €.

**Fix** : aligner `redeemableEuros`/`redeem` sur des multiples de `min_redeem_points`, ou marquer
explicitement le helper « tests node uniquement ».

---

## F5 — [P3] Pas de clamp `≥0` sur le total + dérive centimes float au seuil livraison
`funnel.jsx:479` (PaymentPage) et `funnel.jsx:96` (OrderSummary) :
`total = subtotal − (ctx.discount||0) + deliveryFee` **sans `Math.max(0, …)`**. Si le backend
renvoyait un `discount > subtotal+livraison`, l'UI afficherait un total négatif. Risque faible
(coupon borné backend) mais non défendu côté client.
Par ailleurs `subtotal = cart.reduce((s,i)=>s+i.price*i.qty,0)` sans arrondi centimes : dérive
flottante possible pile au seuil `≥ 30 €` livraison offerte (`api.js:49`, comparaison stricte).

**Fix** : `Math.max(0, …)` sur les totaux + `Math.round(subtotal*100)/100` avant la comparaison de seuil.

---

## Points VÉRIFIÉS CONFORMES (non-régressions)
- **Livraison** (`api.js:47-54`) : `≥ 30 €` → offerte (bornes 29,99 vs 30 correctes, `≥`) ;
  `4 € ≤ 5 km` puis `+1 €/km entamé` via `Math.ceil(d-5)` ; aucun total négatif possible (fee ≥ 4).
  Le CTA checkout (`funnel.jsx:336`) et le total paiement (`funnel.jsx:479`) incluent le fee de façon cohérente.
- **Prix wizard internement cohérent** : `computeWizardTotal`/`priceFor` = base + suppléments(0,90) +
  viande suppl.(2,50) + formule(2,50/1,50/1,00) ; la somme du **récap** (`wizard-v2.jsx:557-582`)
  égale le **total du pied**. Cascade menu (frites-style/boisson) non re-facturée = bundle plat (aligné borne).
- **Sauce = 1 incluse / max 1** cohérent PARTOUT : wizard principal (`:97`), bol (`:159`),
  cascade sauce frites (`:265` min1/max1) — plus de `+0,50/sauce` (code mort `menu.js:513` jamais atteint).
- **Data menu** : 38 produits (4 sandwichs + 2 galettes + 6 burgers + 2 tacos + 2 bols + 2 frites +
  3 desserts + 15 boissons + 2 menu enfant) = seeder canonique, **aucun produit fantôme, aucun prix 0/NaN**.
- **Images** : les **78 fichiers référencés existent** dans le repo déployé `assets/menu/`
  (y compris `signature/`), 0 manquant (`menu-image-base=assets/menu/`).
- **Quantités** : `Math.max(1, …)` partout (`flows.jsx:47`, `wizard-v2.jsx:675`) → jamais 0/négatif.
- **Checkout** : panier vide → pas de bouton « Passer commande » (`flows.jsx:124`) ; double-submit
  gardé (`funnel.jsx:412` `submitting`, `idemKey` stable `:373`) ; OTP guest branché.
- **Fidélité QR** (`components.jsx:27`) : mint-on-display token `lqr.*`, re-mint auto à expiration,
  erreurs réseau gérées (jamais throw), solde/soldes en `parseInt(...)||0` (pas de NaN affiché).
