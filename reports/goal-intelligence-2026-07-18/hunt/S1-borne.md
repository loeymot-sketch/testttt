# S1 — BORNE : chasse logique (read-only) — 2026-07-18

Périmètre : logique métier borne (panier→quote→order, 86, offline/idempotence, upsell/menus, quantités, min/max serveur). Visuel exclu. DB `foodking_e2e` lue via tinker, serveur dev 8000 sondé en GET. Aucun fichier modifié, aucune écriture DB.

---

## Problèmes RÉELS confirmés

### [P1] `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue:21-92` + `KioskWizardComponent.vue:341-346,2020-2053` — Bols : l'étape « Boisson (optionnel) » (addon 2,00 €) est rendue comme une formule Menu GRATUITE ; la boisson choisie n'est ni facturée ni transmise au backend

**Chaîne prouvée :**
- DB : `item_wizard_steps` profil 8 (Bol Frites, item 41, publié) et 12 (Bol Riz, item 45, publié) ont un step `boisson` `source_type=addon`, `addon_role='drink'`, min 0 max 1, choices = addon 99/100 « Boisson Seule » **2,00 €** (vérifié live : `GET /api/frontend/item/details/41` → `step: boisson addon drink … choices [(99,'Boisson Seule',2)]`, `has_menu=false`).
- `KioskWizardComponent.vue:341-346` : `ADDON_ROLE_TO_TYPE['drink'] = 'menu'` → le step est rendu par **KioskStepMenu**, qui affiche les tuiles « Menu Complet (frites+boisson) » et « + Frites » **sans condition** (template lignes 21-55 ; seule la tuile boisson-seule est gatée, ligne 58) — sur un BOL qui n'a aucune formule.
- Prix affiché : `kioskPricing.js:51-73` `getKioskMenuAddonPrice` cherche un addon nommé `/menu/i` → inexistant sur les bols → **+0,00 €** affiché quel que soit le choix.
- Transmission : « Boisson Seule » est **exclue** de `boissonList` par `kioskDrinkAddons.js:14,20-22,44` (`kioskIsGenericDrinkOptionName('boisson seule')`=true) → repli catalogue global (Coca/Oasis…) → `selectBoisson` émet `addonId: null` (`KioskStepMenuComponent.vue:440-446`, rows catalogue sans `addonId`) → `KioskWizardComponent.vue:2042-2053` ne pousse l'addon **que si `boissonMeta.addonId`** → payload **sans** `item_addons` ; le push « menu addon » (2020-2034) ne matche rien non plus (`/menu/i`).
- Backend : `PricingService` facture 7,90 € seul ; `composition_snapshot` scellé SANS boisson ; le choix (menu complet/boisson + nom de la boisson) part uniquement dans `instruction` (`buildInstruction`, `KioskWizardComponent.vue:2150-2160`) → **imprimé sur le ticket cuisine**.

**Repro** : borne → Bols → Bol Frites → viande → sauce → étape « Menu » → « Menu Complet » (+0,00 affiché, hint « 1 boisson incluse ») → Coca → récap 7,90 € → payer. `order_items` : `total_price=7.90`, pas d'addon, instruction avec la boisson → **frites + boisson servies pour 0 €** (fuite produit) ou client frustré si la cuisine refuse. DB actuelle : 0 ligne bol avec instruction menu/boisson (`BOL_LINES_WITH_MENU_INSTR: 0`) → la fuite n'a pas encore été exploitée, mais le chemin est ouvert à chaque commande bol borne.

### [P1] `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue:220-257` + `app/Http/Controllers/Frontend/ItemController.php:81-107` — Upsell « Menu Enfant Nuggets » : ajout 1-tap sans wizard → paiement bloqué 422 (attribut requis « Sauce (1ère Gratuite) » jamais demandé)

**Chaîne prouvée :**
- Pool upsell : item 40 `is_upsell=5`, catégorie « Menu enfant » `kiosk_upsell_include=1` (DB) ; **probe live** `GET /api/frontend/item/kiosk-upsell?item_ids=4&limit=12` → item 40 servi `is_available=true` (3/3 tirages). Avec `limit=6` sur 13 items éligibles ≈ 46 % d'apparition.
- DB : attr 5 « Sauce (1ère Gratuite) » `min_select=1`, item 40 a **12 variations ACTIVE** sur cet attribut ; profil composer 40 (publié) step `sauce` min 1 → le parcours CATALOGUE force la sauce, mais l'upsell ajoute direct : `addAndContinue` pousse `item_variations: {}` (lignes 224-237) puis `router.push('kiosk.payment')` (254) **sans repasser par le panier ni le wizard**.
- Au paiement : `KioskPaymentComponent.vue:478` `refreshQuote()` → `OrderQuoteService.php:69` `assertVariationPresenceConstraints` → `MultiVariationConstraint.php:59,96-105` (attribut requis totalement omis) → **422** « Sauce (1ère Gratuite) : minimum 1 … » → toast brut, bouton Payer inopérant tant que la ligne n'est pas supprimée à la main (le message ne nomme même pas l'article fautif). Store (`OrderRequest`) refuserait pareil.

**Repro** : panier avec 1 article → Valider → écran upsell → cocher « Menu Enfant Nuggets » → « Ajouter » → paiement → choisir Espèces → Payer → 422. Tunnel mort pour un client seul devant la borne.

### [P2] `app/Http/Controllers/Frontend/ItemController.php:81-107` — kioskUpsell aveugle à la rupture PAR BRANCHE (86) + saute la défense « prune » du panier

- La requête upsell filtre `status`, `is_upsell`, `category.kiosk_upsell_include` mais **ne joint jamais `item_branch_availability`** et ne passe pas par `ItemService` : `SimpleItemResource.php:22-25` retombe alors sur le `is_available` GLOBAL (`effective_is_available` jamais setté — il n'est posé que par `ItemService.php:260,682`). Un article 86 branche 1 est donc proposé à l'upsell comme disponible.
- Aggravant : le chemin upsell→paiement est direct (`KioskUpsellComponent.vue:254`) et **contourne `pruneUnavailableLines`** qui ne s'exécute que dans `proceedToUpsell` (`KioskCartComponent.vue:744-763`) → le 86 n'est attrapé qu'au `refreshQuote` (422 `assertItemsOrderableForBranch`, `PricingService.php:50`) = même dead-end paiement que ci-dessus, récupérable seulement en revenant au panier.
- Non déclenchable live aujourd'hui (13 lignes IBA, 0 indisponible) — chaîne 100 % code-vérifiée. Fix évident : filtrer/annoter la dispo branche dans kioskUpsell (comme `itemDetails` l'a été le 2026-07-15).

---

## Écartés après vérification (ne pas reporter)

- **Profils composer PAR CATÉGORIE publiés (34-39) non appliqués serveur** : `assertComposerStepConstraints` matche `whereIn('item_id', …)` → les profils `item_id=NULL` (dont tacos 38) sont invisibles. MAIS la projection item details ne les projette pas non plus (tacos 26 → `composer_profile: None`, vérifié live) et les contraintes réelles passent par `item_attributes` (Viande 1 min1/max1, Viande 2 min1 — item 97 a bien des variations attr 2, cohérent 2 viandes Tacos L), enforced des deux côtés → aucune divergence exploitable. Watch-item V2.
- **`ADD_ITEM` merge ignore `item_addons`** (`kioskCart.js:258-263`) : toute différence d'addons implique aujourd'hui une différence d'`instruction` (label menu / nom boisson) → pas de fusion erronée reproductible.
- **Preview ≠ facturé (promo/fidélité)** : gaté OFF (`KIOSK_PROMO_ENABLED` absent → false ; heals C39/W2 : total affiché = plein tarif).
- **Idempotence / double-envoi** : `X-Idempotency-Key` présent au POST `/frontend/order` (`kioskCart.js:724-725`), clé par panier remise à zéro au RESET, lock cache + UNIQUE DB + recovery user-scopée (`FrontendOrderService.php:168-184,723-742`) ; replay offline réutilise la MÊME clé (`kioskOfflineQueue.js:99-103,558-560`) + re-quote frais (`:370-393`) ; paiement électronique refusé offline. Sain.
- **Divergence preview/scellé** : `sealForCommit` recompute serveur au commit, `intent_hash`+HMAC+409 total mismatch (`OrderQuoteService.php:111-127,443-468`) ; trigger DB `order_items_composition_snapshot_no_update` présent (SHOW TRIGGERS) → snapshot figé.
- **86 mid-parcours (hors upsell)** : double garde quote+store (`PricingService.php:50,102,546` incl. addons + `ChoiceAvailabilityResolver`), prune panier avec toast, janitor walk-away (`CleanupStalePendingKioskOrders`) avec release stock idempotent + garde NF525 `fiscal_sequence_no`. Sain.
- **Quantités** : clamp 20/ligne UI (ADD/UPDATE/REPLACE recomputent le total après clamp), cap serveur 999 (`ValidJsonOrder.php:77`), qty ≤ 0 rejetée, cap 50 lignes ; min/max/allow_repeat serveur OK (`MultiVariationConstraint` + `assertVariationConstraints`).
- **paymentConfirm** : echo montant ±1 ct, transaction_id UNIQUE cross-order, lock, statuts whitelist — rien à signaler.
