# DIAG CAISSE — « drop » du prix / suppressions des sauces & suppléments en plus au paiement

**Phase 1 systematic-debugging (REPRODUIRE + TRACER — AUCUN fix appliqué, aucun fichier de code modifié).**
Date : 2026-07-19 · Repo : `foodking-web/web/testttt` · Branche : `pos/category-first-caisse-2026-06-23` · HEAD : `effbf9531`
DB : `foodking_e2e` (lecture seule) · Serveur :8000.

---

## 0. Verdict compact

- **Le drop NE se reproduit PAS sur la caisse dans le code actuel.** L'extra « Sauce supplémentaire » @0,50 est **retenu** de bout en bout : preuve chiffrée `total = X + 0,50` (Tacos M 6,90 → **7,40**), confirmée sur commande réelle **#5727** ET par rejeu direct du chemin `/pos/quote` (le endpoint exact appelé au paiement).
- Le bug **a bien existé et était total AVANT le 2026-07-16** : sur **185** lignes caisse d'articles-à-sauce pré-fix, **0** facturait la sauce en plus. Le correctif `58e961e24` (2026-07-16, `[LOCK_CAISSE_SAUCE_SEAL]`) — **ancêtre de HEAD**, avec e2e multi-sauces « 5/5 PASS » au HEAD — corrige la racine.
- **Le mécanisme de drop reste latent** (défense manquante) : tout payload qui envoie l'extra **sans `id` valide** ou en **forme-objet** est **droppé SILENCIEUSEMENT** (ni erreur, ni 409), et **le sealing ne peut structurellement pas l'attraper**.
- Hypothèses de fix listées §7 (durcir la défense + fiabiliser 2 chemins clients résiduels). **Non implémentées.**

---

## 1. Modèle de données (vérifié)

| Élément | Réalité DB |
|---|---|
| 1ʳᵉ sauce (gratuite) | **`item_variation`** sous l'attribut **#5 « Sauce (1ère Gratuite) »**, `min=1 / max=1` (mono-sélection), `price=0`. Ex. var 311 « Algérienne ». |
| Sauce EN PLUS | **`item_extra` « Sauce supplémentaire » @0,50** — un par article (35 en base : item 22→extra 428, item 26→**431**, item 38→432, …). |
| Article témoin | **#26 Tacos M**, `price=6,90` (requiert var attr#1 Viande + attr#5 Sauce). |

Preuve modèle — `composition_snapshot` de la commande réelle **#5727** (POS, 2026-07-16 04:50) :
```
lines: [{attribute_id:5, "Sauce (1ère Gratuite)", variation_id:311, "Algérienne", unit_price:0}, {attr 1 Viande...}]
extras:[{extra_id:244 Salade 0}, {245 Tomate 0}, {246 Oignon 0}, {extra_id:431 "Sauce supplémentaire" ...}]
```
`order_items` #5727 : `item_extras=[{"id":431,"item_id":26,"name":"Sauce supplémentaire","quantity":1}]`, `item_extra_total=0.50`, `order.total=7,40`. ✅ extra retenu.

---

## 2. Ce que le wizard met RÉELLEMENT dans le payload pour la sauce en plus

`public/js/pos-wizard.js` (FROZEN) **ne sérialise PAS directement** l'appel API : il pilote via le DOM un `ItemComponent.vue` Vue caché (« pont »/shim). Pour la sauce en plus (correctif `[LOCK_CAISSE_SAUCE_SEAL 2026-07-16]`) :

- `pos-wizard.js:4012-4026` — si `selections.sauceOrder.length > 1`, `sauceSupplQty = length-1`, puis trouve l'extra **générique par nom** `/(sauce\s*suppl)/i` dans `lastItemData.extras` → `sauceSupplExtraId = parseInt(ssExtra.id)` (= **431**).
- `pos-wizard.js:4127-4149` — marque `allSelectedExtras[431]=true`, pose `cb.setAttribute('data-wizard-qty', sauceSupplQty)` sur la checkbox `.extra .custom-checkbox-field[value=431]`, puis `cb.click()`.
- `ItemComponent.vue:712-723` `onWizardBridgeExtra` lit `data-wizard-qty` → `setExtraQuantity(431, N)` → `ItemComponent.vue:741-762` pousse `temp.item_extras = [{id:431, quantity:N, name}]`.

➡️ **Le payload porte donc `item_extra_id = 431` VALIDE + quantité** — pas seulement un nom. (Avant le fix : l'ancien matching par NOM de sauce ne trouvait jamais l'extra générique « Sauce supplémentaire » → 2ᵉ sauce jamais cochée → 0 € facturé. C'était ça, le bug.)

Sérialisation finale (2 constructeurs, tous deux forme-tableau + `id` valide) :
- `PosComponent.vue:4411-4439` `buildPosCheckoutOrderRow` → `item_extras:[{id:431,item_id:26,name,quantity}]`.
- `store/modules/posCart.js:196-215` `normalizeCartForApi` → `normalizeExtrasPayload`.
- Paiement : `PaymentComponent.vue:723,744` re-normalise puis `POST admin/pos/quote`.

---

## 3. Reproduction chiffrée (tinker + chemin /pos/quote réel — lecture seule)

**A. `PricingService::calculateOrder` direct** (Tacos M, var 311+43) :

| Cas | `item_extras` envoyé | subtotal / total |
|---|---|---|
| A | `[{id:431,quantity:1}]` (correct) | **7,40** ✅ +0,50 retenu |
| B | `[{name:"Sauce supplémentaire"}]` (sans id) | **6,90** ❌ droppé silencieux |
| C | `[{id:null,...}]` | **6,90** ❌ droppé silencieux |
| D | `[{id:431,quantity:2}]` (2 sauces en plus) | **7,90** ✅ +1,00 |
| E | `[]` (aucun extra) | 6,90 (référence) |
| F | `{extras:{...},names:{...}}` (forme-objet) | **6,90** ❌ droppé silencieux |
| G | `[{id:432}]` (extra de l'item 38, pas 26) | **THROW 422** « n'appartient pas » (BRUYANT, ≠ drop) |

**B. Chemin `/pos/quote` bout-en-bout** (`OrderQuoteService::quote`, surface `pos`, ce que `PaymentComponent.refreshQuote` appelle) :

| Cas | `total_ttc` |
|---|---|
| A. `[{id:431,quantity:1}]` | **7,40** ✅ |
| B. sans id | **6,90** ❌ |
| F. forme-objet `{extras:[431],names}` | **6,90** ❌ |
| E. baseline | 6,90 |

➡️ Le **serveur retient l'extra dès que le payload porte l'`id` numérique valide en forme-tableau** (cas A). Il ne le drop QUE si l'`id` manque/est nul (B,C) ou si la forme est un objet (F).

---

## 4. Racine exacte du drop (file:line)

### 4.1 Point de drop SILENCIEUX — backend
`app/Services/Pricing/PricingService.php`
- **L.171-174** : `$extraId = $extra->id ?? null; if (! $extraId) { continue; }` → **extra sans id ⇒ sauté sans erreur** (cas B/C).
- **L.169** : `if (isset($item->item_extras) && is_array($item->item_extras))` → **forme-objet (stdClass) ⇒ tout le bloc extras ignoré** (cas F).
- (Miroir de collecte des ids : L.73-80 `pluck('item_extras')->flatten(1)->pluck('id')->filter()` — un id nul/absent disparaît aussi ici.)

### 4.2 Ce qui N'EST PAS la cause (garde bruyante)
`PricingService.php:176-187` — `Extra introuvable` / `n'appartient pas à l'article` **lèvent une 422** (cas G). Donc `assertItemExtrasBelongToItem`/cross-item guard **rejettent BRUYAMMENT**, ils ne droppent pas en silence. Idem `assertVariationPresenceConstraints` / `MultiVariationConstraint` (`OrderQuoteService.php:220-245`) : lèvent 422, ne droppent pas.

### 4.3 Miroir de drop côté CLIENT
`resources/js/helpers/posNormalizeIds.js`
- **L.5-17** `normalizeId` : `''`/`null`/`undefined`/`<=0`/non-fini ⇒ `null`.
- **L.73-89** `normalizeExtrasPayload` : `id===null` ⇒ entrée ⇒ `null` ⇒ **`.filter(Boolean)` la supprime du payload**.
- **L.140-157** `normalizeExtraEntries` : convertit la forme-objet héritée → tableau, drop id nul.
➡️ Un extra sans id valide est **retiré côté client** avant l'envoi — même effet, sans trace.

### 4.4 Pourquoi le sealing N'ATTRAPE JAMAIS le drop (clé du sujet)
`app/Services/OrderService.php:1134-1139` — le commit POS appelle
`sealForCommit($request, 'pos', order->id, expectedTotal = $this->order->total)`,
où `order->total` **vient de `PricingService` sur le MÊME payload** (L.1117-1131).
`app/Services/Order/OrderQuoteService.php:111-127` — `sealForCommit` **re-quote le MÊME payload** (`$this->quote($request,...)`) puis compare `quote.total_ttc` à `expectedTotal`. Les deux côtés sont calculés **à partir du même payload** ⇒ **toujours égaux** ⇒ le **409 « total does not match » ne se déclenche jamais** sur un drop client. Le drop est **structurellement invisible au sealing**.

---

## 5. Panier vs sealing : où (ne) diverge (pas) le payload

Il n'existe **pas** deux payloads « panier 12 » vs « sealing 10 » côté serveur : `PaymentComponent.refreshQuote` (`/pos/quote`) et `OrderService` tarifient **le même `items` normalisé**. La divergence « affiché X+0,50 / scellé X » ne peut naître que **côté client** si l'affichage panier compte l'extra (via `selections.sauceOrder`, `pos-wizard.js:1305/1357 SAUCE_EXTRA_PRICE`) **alors que `temp.item_extras`/le payload l'omettent** (échec de synchro de la checkbox). Sur le chemin standard actuel, les deux sources concordent (l'extra est dans `temp.item_extras`) ⇒ **pas de divergence**.

---

## 6. Commandes réelles (step 5 — taux chiffré)

Requête : lignes `order_items` d'articles-à-sauce (35 items), `source_surface='pos'`, 400 dernières :

| Période | Lignes caisse | avec « Sauce supplémentaire » facturée |
|---|---|---|
| **avant 2026-07-16** | 185 | **0** |
| **après 2026-07-16** | 1 | **1** (cmd #5727) |

- Avant le fix : **0/185** → drop **total** (corrobore le commentaire LOCK « la 2e sauce n'était jamais cochée → backend facturait 0 € »). ⚠️ 0 ne prouve pas que 185 clients voulaient une 2ᵉ sauce, mais l'absence *totale* + le bug connu convergent.
- Après le fix : **1/1** facturée correctement — mais échantillon post-fix minuscule (n=1, donnée de test), donc **validation réelle terrain faible**.

Timeline : fix `58e961e24` (2026-07-16 04:28, `[LOCK_CAISSE_SAUCE_SEAL]`) = **ancêtre de HEAD** `effbf9531` (2026-07-18, « validation blocs C/D/fidélité/**multi-sauces** — 5/5 PASS »). Bundle compilé `public/js/pos-shell.js` (18 juil 20:02) **contient** `data-wizard-qty` + `onWizardBridgeExtra`.

---

## 7. Hypothèses de fix (NON implémentées — Phase 1)

1. **Rendre le drop BRUYANT plutôt que silencieux (défense en profondeur).**
   `PricingService.php:171-174` : au lieu de `continue` quand l'`id` manque mais qu'un `name` est présent → lever une 422 explicite (ou logger + flag). But : plus jamais de sous-facturation silencieuse. ⚠️ à valider contre les payloads légitimes (addons forme-objet).
2. **Sealing réellement anti-drop.** Faire porter par le sealing un **total attendu déclaré côté client** (le « 12 » affiché), distinct du recompute serveur, pour que tout écart → 409. ⚠️ touche `OrderService`/`OrderQuoteService` (adjacents NF525) → gate.
3. **Fiabiliser les 2 chemins clients résiduels (non reproduits via curl/tinker, à confirmer navigateur) :**
   - **Édition d'une ligne panier** : à l'édition, la checkbox `Sauce supplémentaire` est déjà cochée ; `syncAndSubmit` (`pos-wizard.js:4146` `if (cb.checked !== shouldBeChecked) cb.click()`) **ne re-clique pas** ⇒ `data-wizard-qty` non relu ⇒ passer de 1→2 sauces n'augmente pas la quantité (sous-facturation) ; et si `sauceOrder` n'est pas restauré (`buildWizardRestorePayload` `ItemComponent.vue:1202 sauceOrder:[]`), `shouldBeChecked` peut tomber à false ⇒ **cb décochée ⇒ extra retiré**. → piloter `setExtraQuantity` **directement** (pas via clic conditionnel) + restaurer `sauceOrder`.
   - **Divergence `item.extras` (prop Vue) vs `lastItemData.extras` (wizard)** : si l'extra 431 n'est pas dans la liste Vue rendue, aucune checkbox ⇒ jamais cochée. À auditer (même source de données ?).
4. **Déploiement** : le fix critique est dans `pos-wizard.js` (statique, servi tel quel) — s'assurer que la **prod sert bien le `pos-wizard.js` + `pos-shell.js` post-2026-07-16** (sinon le bug persiste en prod malgré le code corrigé au repo).

---

## 8. Fichiers clés cités

- `public/js/pos-wizard.js:4004-4026, 4127-4149, 1305, 1357` (FROZEN — le correctif sauce)
- `resources/js/components/admin/pos/ItemComponent.vue:712-723, 741-762, 222-228`
- `resources/js/components/admin/pos/PosComponent.vue:4411-4439`
- `resources/js/components/admin/pos/PaymentComponent.vue:712-723, 744`
- `resources/js/store/modules/posCart.js:196-215`
- `resources/js/helpers/posNormalizeIds.js:5-17, 73-89, 140-157`
- `app/Services/Pricing/PricingService.php:73-80, 169, 171-174, 176-187` **(racine du drop silencieux)**
- `app/Services/OrderService.php:1134-1139` **(sealing non-attrapant)**
- `app/Services/Order/OrderQuoteService.php:111-127, 220-245`
- Repro : `scratchpad/repro_pricing.php`, `scratchpad/repro_quote.php`
