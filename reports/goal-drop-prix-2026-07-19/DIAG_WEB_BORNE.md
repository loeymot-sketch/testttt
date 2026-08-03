# DIAG Phase 1 — Drop de prix « panier 12 € → payé 10 € » (extras supprimés)
Date : 2026-07-19 · Méthode : systematic-debugging Phase 1 (REPRODUIRE + TRACER, **aucun fix, aucun fichier modifié** hors ce rapport).
Serveur : `http://127.0.0.1:8000` · DB `foodking_e2e` · token borne (user 3 = Caissier/KioskMachine 1, branch 1).

---

## 0. VERDICT

- **Le backend NE drop PAS.** `PricingService` (SSOT partagé borne+web+caisse) facture correctement chaque extra dès qu'un `item_extra_id` valide est fourni. Preuve chiffrée ci-dessous (P1–P4).
- **La BORNE ne drop PAS** (affichage et payload sont gatés sur la MÊME condition `getKioskExtraSauceUnitPrice(item)` ; le montant encaissé = total SSOT = payload). Le LOCK 2026-07-15 est **compilé** dans `public/js/pos-app.js` (rebuild 18/07 20:02).
- **Le DROP est un défaut FRONTEND WEB (standalone React).** La racine n'est PAS la sauce (le fix sauce du 15/07 marche dans la source déployée) mais une **classe plus large** : le web chiffre le panier 100 % côté client puis, au submit, **résout chaque option par NOM** et **omet silencieusement** toute option non résolue — sans aucun preview serveur pour détecter l'écart. La sauce a été patchée nominativement ; les **suppléments / viande supp. / options hors-catalogue-de-l'item** restent droppés.

---

## 1. RACINE EXACTE (file:line + mécanisme)

### 1.A — Racine primaire : WEB résout par NOM et skip en silence (pas de preview serveur)
Fichier déployé : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/`

- **`api.js` `resolveLine()`** construit le payload en re-résolvant chaque option contre le détail backend, avec un skip silencieux si non trouvé :
  - Suppléments : **`api.js:393-397`** → `var eid = findExtraId(dIdx,['supplement'],nm); if (eid != null) item_extras.push(...)` → **si `eid == null` : rien n'est poussé** (aucune erreur).
  - Crudités `:388-390`, suppléments bol `:400-404`, viande supp. `:407-415`, sauce `:360-379` : **même pattern `if (id != null) push`**.
  - `findExtraId()` **`api.js:292-299`** : match sur `norm(name)` dans `extraByGroup[group]`. Aucune correspondance → `return null` → drop.
- **`wizard-v2.jsx` `suppOptions(item)` `:50-54`** propose **tout le pool global `SUPPLEMENTS`** (filtré seulement galette_only/galette_excluded), **PAS** les extras réels de l'item backend → des suppléments inexistants sur l'item sont proposés + chiffrés côté client → droppés au submit.
- **`data/menu.js` `priceFor()` `:514-517`** (et le bloc suppléments) : le total **client** additionne le prix de l'option (sauce +0,50 ; supp. +0,90 ; Boule gratinée +1,00). C'est le « 12 € » affiché.
- **Pas de garde serveur pour le web** : `PricingPreviewController::preview` **`app/Http/Controllers/Frontend/PricingPreviewController.php:36-45`** exige `KioskMachine::where('user_id',$user->id)` → **réservé BORNE**. Le web n'a **aucun** recalcul serveur avant la création. Le 1er calcul serveur = `POST /api/frontend/order` → l'écart devient visible seulement au paiement = « chute à 10 € ».

**Mécanisme complet du drop web** : `priceFor` compte l'option (cart 12) → `resolveLine` ne la résout pas → payload sans l'option → `PricingService` scelle sans elle (10) → absente ticket/KDS car jamais persistée. Aucune erreur, car l'option n'atteint jamais le serveur.

### 1.B — Racine backend latente (secondaire, pas le déclencheur live)
`PricingService::calculateOrder` **`app/Services/Pricing/PricingService.php:171-174`** :
```php
$extraId = $extra->id ?? null;
if (! $extraId) { continue; }   // extra SANS id → skip silencieux (pas de 422)
```
Un extra `{name:...}` sans `id` est **silencieusement ignoré**. En preview c'est bloqué en amont par le FormRequest (« Payload invalide »), mais c'est la trappe si un payload malformé (name-only) arrive. Le web déployé envoie **avec id**, donc ce n'est pas le déclencheur actuel — mais c'est la même philosophie « skip » que côté front.

### 1.C — Les gardes NE sont PAS des drops silencieux (elles jettent 422)
`PricingService.php` : id inconnu → **422** (`:176-181` extra, `:146-151` variation) ; cross-item → **422** (`:182-187`, `:152-157`) ; contrainte attribut min/max → **422** (`:427-438`). Donc un id **présent mais faux** = erreur dure (commande rejetée), **jamais** un total baissé en silence. Le drop silencieux vient donc **du front qui n'envoie pas l'id**, pas du backend.

---

## 2. REPRO CHIFFRÉE (curl réel → `/api/frontend/pricing/preview`, PricingService SSOT)
Item 24 « Galette Cayenne » @7,00 · attr1 Viande (min1/max1) · attr5 « Sauce (1ère Gratuite) » (min1/max1) · extra 430 « Sauce supplémentaire » @0,50 grp=`sauce` · extra 81 « Cheddar » @0,90 grp=`supplement`.

| # | Payload | Total serveur | Verdict |
|---|---------|--------------|---------|
| P1 | base + viande22 + sauce293 | **7,00** | base OK |
| P2 | + extra 430 (1 sauce en plus) | **7,50** (+0,50) | ✅ RETENU |
| P3 | + extra 430 ×2 (2 entrées) | **8,00** (+1,00) | ✅ RETENU |
| P3' | + `{id:430, quantity:2}` (forme web déployé) + Cheddar | **8,90** | ✅ quantity honorée |
| P3'' | + `{id:430, quantity:3}` | **8,50** (+1,50) | ✅ quantity honorée |
| P4 | + extra 81 Cheddar | **7,90** (+0,90) | ✅ RETENU |
| G1 | extra `{name:'Sauce supplémentaire'}` **sans id** | 422 « Payload invalide » (preview) / skip L172 sinon | trappe latente |
| G2 | extra `{id:999999}` | 422 « Supplément introuvable » | garde dure (pas silent) |

**Le backend retient TOUJOURS l'extra s'il a un id valide.** ⇒ le drop n'est pas ici.

### Repro du DROP WEB (chiffré) — supplément non résolu sur l'item
Résolution `findExtraId('supplement', …)` contre le détail réel de l'item 24 :
- `Cheddar` → **81** (ok, facturé) · `Viande supplémentaire` → **401** (ok, facturé)
- **`Boule gratinée` → `null`** (proposée sur galette @1,00 mais n'existe qu'en `supplement_bol`, 0 en `supplement`) → **droppée**
- **`Boursin` → `null`** (galette_excluded ici ; sur 6 items à sauce sans Boursin → droppée)

Écart prouvé : panier web « Galette + Boule gratinée » = **8,00** (priceFor 7,00+1,00) → payload résolu = viande+sauce seuls → `preview` scelle **7,00** → **DROP 1,00 €**, aucune erreur, option absente du ticket/KDS.
Magnitude « 12 → 10 » cohérente en cumulant (ex. Boule gratinée 1,00 + un supp. non résolu 0,90 ≈ 2 €, ou viande supp. 2,50 sur un item qui ne l'a pas).

---

## 3. LES LOCK SAUCE 15-16/07 : marchent-ils ?
- **BORNE** (`resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1906-1928` payload + `resources/js/helpers/kioskPricing.js:29-41,128-135` display) : sauce en plus poussée en ItemExtra 430 **avec id réel** pris de `item.extras` ; l'affichage `getKioskExtraSauceUnitPrice(item)` gate sur la MÊME présence. Affichage == scellé. **OK, compilé (pos-app.js 18/07).** Aucun cas de drop (item sans l'extra → 0 des 2 côtés = gratuit cohérent). Les 34 items à attr sauce ont TOUS l'extra (grp=`sauce`, status ACTIF).
- **WEB déployé** (`Site lecayenne/api.js:360-379`) : 1ʳᵉ sauce → variation attr5/8 gratuite ; sauces en plus → `findExtraId(['sauce'],'Sauce supplémentaire')` → **430**, envoyé `{id:430, quantity:N-1}`. `norm()` retire « sauce » en tête des 2 côtés → `supplementaire` → match pour les 35 extras. **La sauce est donc CORRECTE dans la source déployée.**
- **Conclusion** : les LOCK sauce **fonctionnent** (sauce ≠ le drop actuel dans la source). Mais ils ont patché **nominativement la sauce** ; ils n'ont **pas** corrigé la classe générale (suppléments/viande supp./options hors item) qui, elle, drop toujours. Si l'owner voit encore la **sauce** chuter côté web → suspecter un **déploiement Vercel périmé** (le dossier local `Site lecayenne` a le fix ; l'état réel de Vercel n'est pas vérifiable d'ici — repo sans `.git`).

---

## 4. Sealing / intent_hash (question 4)
`FrontendOrderService::myOrderStore` **`app/Services/FrontendOrderService.php:301-320`** : `pricing.use_ssot_service=true` (défaut) → total via `PricingService::calculateOrder` (mêmes chiffres que le preview), lignes persistées telles quelles (`:313 orderItemInsertRows`). Le total commande (`:544-565`) = SSOT.
`OrderQuoteService::sealForCommit` **`app/Services/Order/OrderQuoteService.php:111-122`** n'est appelé **que pour la BORNE** (`FrontendOrderService.php:566`) et **compare** `quote.total_ttc` vs total SSOT : mismatch → **HTTP 409 dur** (pas de recompute silencieux, pas de drop). Le WEB n'a **pas** de seal. ⇒ **Le sealing ne cause aucun drop** ; il ferait au pire un 409 (erreur visible), jamais un total réduit en douce.

---

## 5. PREUVE DB (question 5)
- Scan 1500 derniers `order_items` : **53 lignes avec extra payant** → **52 facturées correctement**, 1 « mismatch » = facturée **plus haut** (extra supprimé du catalogue, artefact de recompute) → **0 drop backend**.
- Les lignes `extra_total=0` sont des **crudités gratuites** (Salade/Tomate/Oignon @0,00) — normal, pas un drop.
- Ex. réel `order_item 5484` (ord 5727, item 26, 16/07) : extra 431 « Sauce supplémentaire » → `item_extra_total=0,50`. **Facturé.**
- Limite : la DB **ne peut pas** montrer un drop front (l'option droppée n'atteint jamais la persistance ; `item_extras` reflète ce que le front a envoyé). L'absence de trace DB **confirme** que le drop est **en amont (front)**, pas backend.

---

## 6. HYPOTHÈSE DE FIX (Phase 2 — à valider, NON appliqué)
Cible : **le web (`Site lecayenne`)**, pas le backend, pas la borne.
1. **Fail-loud au lieu de skip silencieux** dans `api.js resolveLine` : si une option chiffrée côté client ne se résout pas (`findExtraId/pickVariation == null`), **bloquer** (erreur « article indisponible, rafraîchis le menu ») au lieu de `if (id != null) push`. Empêche panier ≠ payé.
2. **Preview serveur pour le web** : exposer un recalcul SSOT (élargir `PricingPreviewController` au client web, ou nouvel endpoint) et **afficher/valider le total serveur** avant paiement → l'écart devient impossible (le web verrait 10 et non 12).
3. **`suppOptions(item)` doit lire les extras RÉELS de l'item** (détail backend) et non le pool global → ne plus proposer Boule gratinée / Boursin sur des items qui ne les ont pas. Idem viande supp. Aligner `data/menu.js` ↔ extras DB par item.
4. **Défense backend** : transformer le `continue` silencieux `PricingService.php:172-174` (extra sans id) en 422 explicite (défense en profondeur), et vérifier la validation d'`OrderRequest` sur les extras du path `/order`.
5. Re-tester la magnitude réelle (viande supp. 2,50 ; multi-supp.) pour matcher « 12 → 10 ».

---

### Annexe — fichiers clés
- Backend SSOT : `app/Services/Pricing/PricingService.php` (extras :168-191 ; gardes :146-187 ; snapshot :270-276)
- Order path : `app/Services/FrontendOrderService.php:298-320, 544-573`
- Seal : `app/Services/Order/OrderQuoteService.php:111-122`
- Preview kiosk-only : `app/Http/Controllers/Frontend/PricingPreviewController.php:36-45`
- Borne payload/display : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1906-1928` + `resources/js/helpers/kioskPricing.js:29-41,128-152`
- WEB déployé (racine du drop) : `lecayenne-web-deploy/Site lecayenne/api.js:292-299,360-379,388-415` + `wizard-v2.jsx:50-54,130-136` + `data/menu.js:204-207,514-517`
