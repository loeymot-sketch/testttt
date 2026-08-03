# KDS/OSS — Lentille PSYCHOLOGIE CUISINIER (r1) — sous-système Sub 3.b « Lisibilité compo / allergènes / snapshot / durées »
**DB** : foodking_e2e (READ-ONLY, SELECT only). **Méthode** : Read ancres + tinker/Vitest preuves + SQL réel.
**Note** : `psychologie-cuisinier.md` (agent Sub 3.a) couvre board/bump/recall ; CE rapport approfondit compo/allergène/snapshot/durée avec preuve DB. Le finding « Choix » est commun (lui P2 divergence, moi P3 cosmétique car 2 viandes présentes+distinctes — preuve ci-dessous).
**Verdict** : board V2 = LIVE par défaut (`master.blade.php:246` → `config('kds.v2_default_enabled', true)` ; défaut `true` `config/kds.php:24`). **0 P0/P1 PROUVÉ**. 1 P2 latent food-safety, 2 P3 readability. 4 germes adversaires RÉFUTÉS par preuve.

---

## [P3] resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:385 (+ KitchenDisplaySystemComponent.vue:385) — « Récemment servies » rend les minutes BRUTES (`il y a 8696 min`), non humanisées, sans clamp d'âge
- **repro** : ouvrir `/admin/kitchen-display-system` (board V2 défaut). Bande « Récemment servies » = `recentlyServed()` (`KdsV2Grid.vue:237-248` : filtre status===PREPARED puis `.slice(0,4)` par `updated_at` desc, **aucun clamp d'âge**). `servedAgoLabel(o)` : `mins=floor((now-stamp)/60000)` → `$t('label.kds_served_ago',{mins})`.
- **evidence** :
  - i18n `resources/js/languages/fr.json:800` → `"kds_served_ago": "il y a {mins} min"` (interpolation brute ; pas de h/j ; pas de clamp).
  - DB réelle PREPARED périmés : `SELECT id,TIMESTAMPDIFF(MINUTE,updated_at,NOW()) FROM orders WHERE status=8 ORDER BY updated_at DESC` ⇒ id 5032=**8696 min**, 4995=9591, 4949=11189. Top-4 (5032/5026/5025/5023)=8696 ⇒ rendraient **« il y a 8696 min »**.
  - Extrême « 9570 min » (germe) ATTEIGNABLE : un PREPARED `is_advance_order=1` overdue entre dans le feed TODAY (`list:133-136` + `orderItems:539-542` n'excluent QUE DELIVERED/CANCELED) → `recentlyServed`. (0 advance-PREPARED en base à l'instant ⇒ pas live maintenant, mais reachable ; même un ticket oublié du matin = « il y a 540 min » au lieu de « il y a 9 h ».)
  - Jumeau : `KitchenDisplaySystemComponent.vue:385` porte la MÊME méthode `servedAgoLabel` (board legacy `?v2=0`).
- **lentille** : cuisinier — « il y a 8696 min » force un calcul mental ; lecture 2 m doit dire « il y a 6 j » / « il y a 9 h ». Bande INFORMATIONNELLE (pas la file d'action) ⇒ pas de commande ratée ⇒ P3.
- **reco (hors frozen)** : (a) humaniseur JS (`<90 min→« X min »`, `<1440→« Xh »`, sinon « Xj ») — **aucun `humanizeMinutes` n'existe côté JS** (grep vide app + resources/js) ; (b) clamp d'âge dans `recentlyServed()` (ignorer PREPARED dont `updated_at`>N h) pour qu'un advance-overdue ne s'affiche jamais « récemment servi ». TDD `tests/js/kdsServedAgoHumanize.spec.js`.

---

## [P2] app/Services/KitchenDisplaySystemOrderService.php:559 (+ normalizeAllergensForHash:593-607) — masquage d'allergène dans le merge items-board pour `allergens_snapshot` DOUBLE-ENCODÉ (JSON STRING)
- **repro** (tinker, env standard) :
  ```php
  $oi  = App\Models\OrderItem::withoutGlobalScopes()->find(4391); // allergens STRING-typé "[\"gluten\",\"lait\"]"
  $svc = new App\Services\KitchenDisplaySystemOrderService();
  (new ReflectionMethod($svc,'normalizeAllergensForHash'))->invoke($svc, $oi->allergens_snapshot); // => []
  ```
- **evidence** :
  - `SELECT JSON_TYPE(allergens_snapshot),COUNT(*) FROM order_items WHERE allergens_snapshot IS NOT NULL AND JSON_LENGTH>0 GROUP BY 1` ⇒ **STRING=2** (id 4391/4392, order 4619, 2026-06-13), ARRAY=16.
  - Le cast modèle `'array'` ne déballe PAS le double-encodage : `gettype($oi->allergens_snapshot)` = **string** `"[\"gluten\",\"lait\"]"`. `normalizeAllergensForHash:595` `if(!is_array($snapshot)) return [];` ⇒ `[]` ⇒ `sha1(json_encode([]))`=`97d170e1…` = **hash VIDE = identique à un article SANS allergène** (tinker : « MATCHES EMPTY → YES — allergens DROPPED in merge key »). ⇒ 2 articles allergène-distincts (gluten/lait double-encodé + un sans allergène, mêmes variations/extras/instruction) **fusionnent** sur l'items-board → cuisinier voit « X ×2 » avec l'allergie du 2ᵉ client masquée — exactement ce que le split `[Lot 2.I/G-5]` devait empêcher.
  - **Asymétrie prouvée** : les MÊMES rows récupèrent l'array au niveau resource — `OrderItemResource:37` (tinker ⇒ `["gluten","lait"]` array) ET `KDSOrderItemsResource:44` (tinker ⇒ `["gluten","lait"]` array). Payload = allergène présent, mais clé de merge = perdu.
  - Trou de couverture : `KdsAllergenAggregationSplitTest` 5/5 PASS mais construit les allergènes via factory = shape ARRAY ⇒ **ne teste JAMAIS la forme STRING double-encodée**.
- **lentille** : cuisinier/food-safety. **Atténuants forts ⇒ P2 (pas P1)** : (1) seules 2 rows LEGACY (≤ id 4392) STRING-typées ; `MAX(id) WHERE JSON_TYPE=STRING`=4392 vs newest_oi=4936, `MAX(id) WHERE ARRAY+len>0`=4916 ⇒ **pipeline courant émet ARRAY**, rows legacy hors fenêtre TODAY ; (2) le template items-board `KitchenDisplaySystemComponent.vue:150-191` **n'affiche AUCUN badge allergène** (nom/variations/extras/addons/instruction/qté seulement) ⇒ l'allergène se lit sur les CARTES today-orders (via `OrderItemResource`, qui récupère le double-encodage — board principal sûr, vérifié tinker).
- **reco (hors frozen)** : durcir `normalizeAllergensForHash` : si `is_string($snapshot)` tenter `json_decode` AVANT de renoncer (aligner sur `safeJsonDecodeArray` des resources). TDD : étendre `KdsAllergenAggregationSplitTest` avec `allergens_snapshot` = JSON-string `"[\"gluten\"]"` et asserter NON-fusion vs article sans allergène.

---

## [P3] resources/js/helpers/kdsCustomization.js:238 renderItem (consommé KdsOrderCard.vue:391) — board V2 écrase « Viande 1/Viande 2 » en groupe générique « Choix »
- **repro** : Vitest sur snapshot RÉEL order_item 4910 (Méga, Cordon Bleu + Viande Hachée).
- **evidence** (dump Vitest prouvé) :
  - V2 (`renderItem`) ⇒ `[variation group=other] Cordon Bleu, Viande Hachée` → **« Choix : Cordon Bleu, Viande Hachée »** (`kds_group_other`=« Choix » fr.json:811) + `[supplement] + Cheddar` + `[supplement] + Viande supplémentaire` + `[menu_child] Menu (Frites + Boisson)`.
  - Legacy (`kdsVariationLine`, `KitchenDisplaySystemComponent.vue:1577`) ⇒ `"Viande 1: Cordon Bleu"`, `"Viande 2: Viande Hachée"` (conserve Viande1/2).
  - Cause : `renderItem` classe via `classifyGroup(v.variation_name,v.attribute_name)` (regex → « Viande 1/2 » tombe en `other`) + `variationLabel(v)=v.name||v.variation_name`, **sans** utiliser le helper soigné `kdsVariationGroupValue` (heal d71dfbfe8).
- **lentille** : cuisinier — **les DEUX viandes sont présentes ET distinctes** (aucune fusion, aucune inversion « Cordon Bleu: », aucun blanc) ⇒ pas de mauvaise viande préparée. Seule perte = positions Viande1/2 collapsées en « Choix ». Tacos/burger = viandes cuites ensemble ⇒ ordre immatériel ⇒ cosmétique ⇒ P3. (L'agent Sub 3.a le classe P2 « divergence board principal » — désaccord de sévérité assumé : ma preuve montre 0 perte d'info de préparation.)
- **reco (optionnel)** : router la branche `variation` de `renderItem` via `kdsVariationGroupValue(v).group` pour préserver « Viande 1/Viande 2 » comme le board legacy déjà corrigé.

---

## GERMES ADVERSAIRES RÉFUTÉS (preuve négative — ne PAS re-flagger)
- **« 2 viandes fusionnées / inversion compo ('Cordon Bleu: ') »** : RÉFUTÉ. order_item 4910 = 2 viandes distinctes rendues distinctement sur V2 (« Cordon Bleu, Viande Hachée ») ET legacy (« Viande 1: Cordon Bleu » / « Viande 2: Viande Hachée »). Heal `kdsVariationGroupValue` tient (discriminant `attribute_name`). Snapshot lines ne portent jamais `name` (DB keys = attribute_name/variation_name/quantity/…) ⇒ `variationLabel` retombe sur `variation_name` (VALEUR) ⇒ pas d'inversion V2 non plus.
- **« compo blanche (valeur vide) »** : RÉFUTÉ. `JSON_TABLE` sur toutes les lignes snapshot ⇒ **0 row** avec `variation_name` NULL/''.
- **« instruction qui double la compo »** : RÉFUTÉ. `sanitizeKdsInstruction` prouvé sur blobs pos-wizard RÉELS : 4932 `"TACOS M\nViandes : Mexicanos Sauce : Harissa Supplément : Cheddar"`→`""` (compo dédupliquée) ; 4926 `"…\n↳ Sauce frites: Harissa"`→garde `"↳ Sauce frites: Harissa"` (n'existe que là) ; 4921 `"…\n- Salade…\n↳ Sauce frites: Ketchup"`→`"↳ Sauce frites: Ketchup"`.
- **« supplément non visible (Cheddar +0,90) »** : RÉFUTÉ. `renderItem` émet `[supplement] + Cheddar` ET `+ Viande supplémentaire` (jaune italique KdsOrderLine:47).

## TESTS REJOUÉS (baseline vert)
- PHPUnit (env test standard, SANS DB override) : `KdsAllergenAggregationSplitTest` 5/5 · `KdsOrderItemsResourceAllergenExposureTest` 3/3 · `KdsSnapshotImmutableTest` 4/4 = **12/12 PASS**.
- Vitest : `kdsCustomization.spec.js` 35/35 · `kdsLineSemantics.spec.js` 5/5 = **40/40 PASS**.

## FROZEN — aucun fichier touché (audit READ-ONLY). Fichiers cités = éditables (KDS/OSS non-frozen). SOURCE de compo (pos-wizard.js / composition_snapshot / PricingService) non en cause.
