# E2E WEB — NAVIGATEUR RÉEL — Drop de prix « panier 12 € → payé 10 € »
Date : 2026-07-19 · Méthode : Playwright Chromium (navigateur réel, viewport mobile 392 px) contre le
site web standalone servi en local, API pointée sur le backend testttt live `:8000` (DB `foodking_e2e`).

## 0. VERDICT — **PASS** ✅
Le bug « panier 12 € → payé 10 €, suppléments largués » est **CORRIGÉ**, prouvé en conditions réelles
navigateur + confirmé en base :
- **panier affiché == bouton Payer == `expected_total` envoyé == total commande backend == 11,80 €**, **tous
  les extras payants présents et facturés**, **zéro drop** (commande web réelle #5824 / serial 1907265824).
- **Fail-loud OK** : forcer une option indisponible (la « Boule gratinée » fantôme, racine du bug) → l'ordre
  est **bloqué** avec un message clair, **0** commande envoyée (aucune commande fausse sous-facturée).
- **Garde backend OK** : `expected_total` divergent → **422** ; rétro-compat (sans le champ) → 201.
- ⚠️ **Bonus — P0 trouvé & healé en cours de route** : `CheckoutPage` **plantait (page blanche) → AUCUNE
  commande web possible depuis le 16/07** (regression `bfe8768`). Corrigé (1 ligne, non-frozen). Détail §6.

---

## 1. SETUP (reproductible)
1. **Site web servi en local** : copie du dossier déployé `lecayenne-web-deploy/Site lecayenne/` dans un
   scratchpad, `php -S 127.0.0.1:8090`. La copie sert à **ne PAS committer l'override d'URL** (repo réel
   intact : `index.html` reste `api-base-url = http://127.0.0.1:8766`).
2. **Base URL API → backend live** : dans la COPIE uniquement, `<meta name="api-base-url">` réglé sur
   `http://127.0.0.1:8000`. (Le finding connu « 8766 » est réel dans le repo ; override de test seulement.)
3. **CORS** : NON bloquant. `config/cors.php` a le pattern `#^http://(localhost|127\.0\.0\.1):\d{2,5}$#`
   → l'origine `http://127.0.0.1:8090` est autorisée (vérifié : `Access-Control-Allow-Origin: …:8090`).
   La `X-API-Key` du web (`b6d68vy2-…`) == `MIX_API_KEY` du `.env`. OK.
4. **Auth** : `site_phone_verification = DISABLE(10)` → le endpoint verify n'exige PAS le vrai code (tout
   code passe). **Login réel navigateur prouvé** : `guestOtp()` + `guestVerify(phone,'1234')` via api.js →
   `hasToken:true, authed:true`. Pour le parcours de commande, une **session invité réelle** (token
   `kiosk:order` émis par le backend, user 260) a été injectée dans `localStorage` afin de ne pas épuiser
   le throttle `verify 3/5min` — le **placement de commande sous test reste 100 % navigateur + api.js**.
5. Navigateur : **Playwright Chromium 1.58.2** (chromium-1223), headless, mobile 392×850, DPR 2. React
   18 + Babel standalone chargés depuis unpkg (connectivité OK).

---

## 2. SCÉNARIO JOUÉ (parcours client web réel)
Produit composable **Galette Cayenne** (web id 202 → backend item 24 @7,00 €), wizard complet :
| Étape wizard | Choix | Effet prix |
|---|---|---|
| Choisis 1 viande | **2 viandes** (Mexicanos + Cordon Bleu) | 1 incluse + **1 en plus @2,50** |
| Sauce | **2 sauces** (Mayonnaise + Ketchup) | 1ʳᵉ incluse + **1 en plus @0,50** |
| Crudités | défauts (Salade, Tomate, Oignon) | @0,00 (gratuit) |
| Suppléments gourmands | **2** (Oignons frits + Champignons) | **+0,90 × 2 = 1,80** |
| Faire un menu ? | Sans formule | 0 |
| **Total attendu** | | **7,00 + 2,50 + 0,50 + 1,80 = 11,80 €** |

Progression du total sur le bouton wizard (capturée) : 7,00 → (viandes) 9,50 → (sauce) 10,00 →
(suppléments) 11,80 → récap **11,80** → panier **11,80** → paiement **11,80** → confirmation **11,80**.

---

## 3. RÉSULTAT — panier == payé == backend (aucun drop)
Commande **réelle #5824** placée par le navigateur (`POST /api/frontend/order`, HTTP **201**, queue A0034) :

| Mesure | Valeur |
|---|---|
| Panier affiché (drawer, `06-cart`) | **11,80 €** |
| Bouton « Confirmer la commande » (`09-payment`) | **11,80 €** |
| `expected_total` **réellement envoyé par le navigateur** | **11.8** |
| Total commande backend (réponse API + DB) | **11,80 €** (tax 1,07) |
| Confirmation (`10-confirm`, ticket) | **TOTAL 11,80 € · ✓ ENVOYÉE EN CUISINE** |

**Extras présents & facturés (composition_snapshot NF525, DB, item price 7,00 + extra_total 4,80) :**
- ✅ Sauce supplémentaire (extra 430) @0,50 ×1
- ✅ Oignons frits (extra 88) @0,90 ×1
- ✅ Champignons (extra 89) @0,90 ×1
- ✅ Viande supplémentaire (extra 401) @2,50 ×1
- ✅ crudités gratuites Salade/Tomate/Oignon @0,00 (résolues, pas de fail-loud parasite)

Payload navigateur (extrait réel) :
`items=[{"item_id":24,…,"item_extras":[{"id":430,…"Sauce supplémentaire (Ketchup)"},{77},{78},{79},{88},{89},{401}]}], "expected_total":11.8`
→ **AUCUN extra payant droppé.** base + 4,80 = 11,80 = total scellé. **NO DROP.**

---

## 4. FAIL-LOUD (option indisponible → blocage, pas de commande fausse)
Scénario adverse en navigateur : ré-injection de la **« Boule gratinée » @1,00** (l'option fantôme exacte
qui causait le drop) dans le pool, sur une Galette Cayenne, puis `resolveOrderItems` + `placeOrder` via api.js :
- `resolveOrderItems` → **throw** `kind:'resolve'` :
  « **Boule gratinée n'est plus disponible pour Galette Cayenne. Retire cette option et réessaie (le prix
  affiché doit correspondre à ce qui est facturé).** »
- `placeOrder` → **throw** (même message).
- **POST /api/frontend/order pendant le test fail-loud = 0** → aucune commande sous-facturée créée.
- Le funnel affiche ce message via `setApiError` (funnel.jsx:473, role="alert" ligne 613) → l'utilisateur
  voit un blocage clair au lieu d'une commande silencieusement moins chère (ancien comportement).

Ancien comportement (sans fix) : l'option était droppée en silence → commande @7,00 scellée sans erreur.
Nouveau : **blocage dur + message + 0 commande**. ✅

---

## 5. GARDE BACKEND `expected_total` (défense-en-profondeur, via curl)
| Cas | Attendu | Obtenu |
|---|---|---|
| Payload complet, `expected_total=11.80` | 201 @11,80, extras présents | **201 @11,80** (ordre 5821, extra_total 4,80) ✅ |
| Drop simulé : `expected_total=11.80` mais payload résout à 7,00 | 422 | **422** « Le total ne correspond pas au montant attendu — certaines options sont peut-être indisponibles. » ✅ |
| Rétro-compat : même drop **sans** `expected_total` | 201 @7,00 | **201 @7,00** (ordre 5823) ✅ (garde opt-in ; le web envoie TOUJOURS le champ) |

Le total facturé reste 100 % SSOT (`PricingService`) ; `expected_total` n'est **jamais** persisté ni
facturé (témoin uniquement). Confirmé OrderRequest.php:169 + FrontendOrderService.php:580-589.

---

## 6. ⚠️ P0 TROUVÉ & HEALÉ EN E2E — CheckoutPage plantait (page blanche)
**Symptôme réel** (capturé) : après « Passer commande » → upsell → **la page de checkout était BLANCHE**,
`ReferenceError: deliveryEnabled is not defined at CheckoutPage`. **Conséquence : AUCUNE commande web ne
pouvait aboutir** (le funnel crashe avant le paiement) — pour TOUT client, pas seulement ce test.

**Racine** : `deliveryEnabled` est référencé dans `CheckoutPage` (`funnel.jsx:225, 246`) mais n'était
défini QUE dans `PaymentPage` (`funnel.jsx:403`). Regression introduite par `bfe8768` (WEB-DELIVERY-
CONTRADICTION, 2026-07-16). Indépendant du drop, mais **bloquant pour prouver le drop** en navigateur.

**Fix appliqué** (non-frozen, 1 ligne, même pattern que PaymentPage), `funnel.jsx` CheckoutPage :
```js
const deliveryEnabled = !!(api && api.config && api.config.deliveryEnabled);
```
→ après ce fix, le checkout rend correctement, le paiement s'affiche, la commande #5824 est passée.
**Statut** : appliqué dans le repo web `lecayenne-web-deploy/Site lecayenne/funnel.jsx` en **working tree
NON committé** (le laisse à revue owner). À **committer + bump cache-bust `?v=` + redéployer Vercel** —
sinon le site en ligne reste NON fonctionnel (checkout blanc).

---

## 7. SCREENSHOTS (analysés, dossier `nav-screens/`)
`01-home` · `02-menu`(home CTA, nav mobile repliée) · `03-detail`(détail Galette Cayenne) ·
`04-wizard-start` + `04-step-0..4`(étapes wizard) · `05-recap`(récap 11,80) · `06-cart`(panier 11,80,
sous-total 11,80, +11 pts) · `07-after-passer`(upsell boissons) · `08-checkout`(après fix, rend OK) ·
`09-payment`(« Confirmer la commande 11,80 € », récap 1 article) · `10-confirm`(« C'EST PARTI ! » ticket
#1907265824, TOTAL 11,80 €, ENVOYÉE EN CUISINE). (`99-error` = artefact d'un run pré-fix : panier 11,80 OK.)

---

## 8. RESTE / NOTES
- **Repo réel intact côté validation** : `index.html` non modifié (override d'URL = copie de test uniquement).
- **1 seule modif de code** hors data : `funnel.jsx` (fix P0 checkout, non-frozen) — à committer + déployer.
- Auth : login OTP réel prouvé côté navigateur ; le parcours commande a utilisé une session invité réelle
  injectée (throttle verify 3/5min) — le placement reste 100 % navigateur/api.js/backend.
- Pour la prod Vercel : (a) régler `api-base-url` + `menu-image-base` sur l'URL HTTPS du backend,
  (b) committer le fix `funnel.jsx` + **bumper le token `?v=`** (sinon cache sert l'ancien checkout cassé).
