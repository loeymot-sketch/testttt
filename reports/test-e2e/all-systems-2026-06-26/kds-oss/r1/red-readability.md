# KDS/OSS — Lentille ADVERSAIRE-RED « Lisibilité compo / allergènes / snapshot / durées »
Round r1 · DB `foodking_e2e` (READ-ONLY) · 0 fichier modifié · 0 écriture DB

Méthode : Read fichiers ancrés + Vitest filtrés (57 verts) + PHPUnit KDS (28 verts) +
SELECT live + 2 probes Vitest (assertions exactes des shapes rendus) + tinker (cast réel).
Tout finding ci-dessous est reproduit. Les vecteurs réfutés (defenses correctes) sont listés
en fin pour traçabilité anti-fausse-certitude.

---

## FINDINGS PROUVÉS

### [P3] resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:394-395 — durée brute « il y a 8699 min » non humanisée (Récemment servies)
- repro: ouvrir `/admin/kitchen-display-system` (V2 par défaut, `config/kds.php:24` `v2_default_enabled=true`).
  La bande « Récemment servies » rend les 4 PREPARED les plus récents par `updated_at`.
  `servedAgoLabel(o)` (KdsV2Grid.vue:385-396) calcule `mins = Math.floor(diffSec/60)` et le passe
  BRUT à `kds_served_ago` = `"il y a {mins} min"` (fr.json:800). Aucun plafond, aucune conversion j/h.
- evidence (live foodking_e2e):
  `SELECT id, queue_number, updated_at, TIMESTAMPDIFF(MINUTE, updated_at, NOW()) FROM orders WHERE status=8 AND branch_id=1 ORDER BY updated_at DESC LIMIT 4;`
  → A0006/A0004/A0003/A0002 tous `mins_ago = 8699` → la bande afficherait littéralement
  « N°A0006 · il y a 8699 min » (au lieu de « il y a 6 j 1 h »). 47 PREPARED présents branche 1,
  beaucoup datant du 2026-06-19/20 (PREPARED qui n'ont jamais été basculés OUT/DELIVERED).
  i18n confirmé fr.json:800 `"kds_served_ago": "il y a {mins} min"` ; `humanizeMinutes` n'existe
  PAS dans `resources/js` (grep vide — MEMORY citait `appService.humanizeMinutes()` mais absent).
- lentille: cuisinier (lecture confuse d'une durée à 4 chiffres) — cosmétique, pas de commande ratée.
- reco: ajouter un helper `humanizeMinutes(mins)` (≥120 min → « X j Y h » ou « X h »; <120 → « X min »;
  <1 → « à l'instant ») et l'appeler dans `servedAgoLabel`; ajouter une nouvelle clé i18n
  (ex. `kds_served_ago_human`) au lieu de réutiliser `{mins} min`. NON-frozen (KdsV2Grid éditable).
  Spec à créer : `tests/js/kdsServedAgoHumanizes.spec.js`.

### [P3] resources/js/helpers/kdsCustomization.js:277-295 — sur la CARTE V2 (board par défaut) les groupes « Viande 1 / Viande 2 » sont génériqués en « Choix » (fidélité dégradée, MEATS distincts visibles)
- repro: order_item réel #(item d'order) avec snapshot 2-viandes distinctes :
  `SELECT composition_snapshot FROM order_items WHERE composition_snapshot LIKE '%Viande 2%' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1;`
  → `lines:[{attribute_name:"Viande 1",variation_name:"Cordon Bleu"},{attribute_name:"Viande 2",variation_name:"Fricadelle"},{attribute_name:"Sauce (1ère Gratuite)",variation_name:"Samouraï"}], extras:[{extra_name:"Cheddar",unit_price:0.9}]`.
  `KdsOrderCard.vue:390 renderItemLines → renderItem(item).lines` (board V2). Dans `renderItem`,
  `classifyGroup(v.variation_name, v.attribute_name)` concatène « Cordon Bleu Viande 1 » : aucun
  GROUP_PATTERN (bread/crudites/sauce/cooking/drink/size, l.24-31) ne matche « Viande » → groupe `'other'`.
  Les 2 viandes tombent dans le MÊME bucket `'other'` → 1 seule ligne `{group:'other', label:'Cordon Bleu, Fricadelle'}`.
  `KdsOrderLine.vue:96-103` rend `kds_group_other` = « Choix » (fr.json:811) → le cuisinier lit
  **« Choix : Cordon Bleu, Fricadelle »** (les libellés de slot Viande 1/Viande 2 sont PERDUS).
- evidence (probe Vitest, assertions exactes, PASS 2/2 sur le snapshot réel) :
  - chemin CARTE V2 `renderItem` → `[{group:'other',label:'Cordon Bleu, Fricadelle'},{group:'sauce',label:'Samouraï'}]`
  - chemin items-board `kdsVariationLine` (KitchenDisplaySystemComponent.vue:1576-1577) → `['Viande 1: Cordon Bleu','Viande 2: Fricadelle','Sauce (1ère Gratuite): Samouraï']`
  → DIVERGENCE prouvée entre les deux surfaces KDS pour la même donnée.
- IMPORTANT (calibrage sévérité): ce n'est PAS une commande ratée — les 2 viandes sont DISTINCTES
  et VISIBLES (« Cordon Bleu, Fricadelle »), pas de fusion, pas d'inversion « Poulet mariné: »,
  pas de ligne blanche; le Cheddar +0,90 supplément est bien rendu (`+ Cheddar`). Le seul défaut
  est la perte du libellé « Viande 1 / Viande 2 » (sans impact sur QUOI préparer : les deux viandes
  vont dans le même produit). La sauce groupe correctement (« Sauce : Samouraï »).
- lentille: cuisinier (fidélité d'affichage + incohérence inter-surfaces) — cosmétique/fidélité.
- reco: dans `classifyGroup`, ajouter un pattern viande (`/\bviande\b/i` → clé `meat`) + i18n
  `kds_group_meat` = « Viande »; OU faire passer le `attribute_name` réel comme `group` quand il
  est non vide (snapshot shape) au lieu de re-classifier par mots-clés — ce qui alignerait la carte
  sur `kdsVariationLine`/items-board. NON-frozen (`kdsCustomization.js` éditable). Spec à créer :
  `tests/js/kdsTwoMeatsDistinctRender.spec.js` (couvre le shape snapshot Viande1/Viande2 dans
  `renderItem`, aujourd'hui non testé — le test l.72 n'utilise QUE le shape legacy Pain/Crudités/Sauce).

### [P3] app/Services/KitchenDisplaySystemOrderService.php:559 + 593-607 — le split-allergène (food-safety) est plus strict que le décodeur de la resource sur le shape JSON-string « double-encodé » (gap défensif latent, NON atteignable par les commandes réelles)
- repro: 2 lignes legacy en DB ont `allergens_snapshot` stocké en JSON-STRING double-encodé :
  `SELECT JSON_TYPE(allergens_snapshot), COUNT(*) FROM order_items WHERE allergens_snapshot IS NOT NULL AND deleted_at IS NULL GROUP BY 1;` → ARRAY=2841, STRING=2 (ids 4391/4392, order 4619, `source_surface=NULL`, 2026-06-13).
  tinker (DB_DATABASE=foodking_e2e): le cast `'array'` (OrderItem.php casts) sur ce shape rend une
  **chaîne** `"[\"gluten\",\"lait\"]"` (pas un array). `normalizeAllergensForHash` exige `is_array()`
  (l.595) → retourne `[]` → si deux lignes partageaient item+variations et ne différaient QUE par
  cet allergène string-shaped, le split food-safety (l.554-559) ne se déclencherait PAS.
- evidence: tinker `normalizeAllergensForHash($oi->allergens_snapshot)` => `[]` pour #4391 ;
  MAIS `(new KDSOrderItemsResource($oi))->toArray()` => `allergens_snapshot: ["gluten","lait"]`
  (la resource `safeJsonDecodeArray` l.103-116 décode bien la chaîne) → la LIGNE d'allergène + le
  bord orange s'affichent quand même côté front. De plus ces 2 rows sont status=8 (PREPARED) :
  exclus de l'items-board (`itemBoardStatuses` = ACCEPT|PREPARING seulement, KitchenReleaseRule:28),
  et le board carte (`list`) NE FUSIONNE PAS entre commandes → le chemin de merge-split n'atteint
  jamais ces rows. Les commandes réelles (kiosk/pos/web) produisent toutes le shape ARRAY (2841/2843).
- lentille: cuisinier/food-safety — gap défensif latent, NON reproductible en flux réel.
- reco (hardening, non-bloquant V1): rendre `normalizeAllergensForHash` robuste au shape string
  (réutiliser `safeJsonDecodeArray`/`json_decode` si `is_string`) pour parité avec le décodeur de la
  resource; OU one-shot data-fix des 2 rows legacy. NON-frozen. Spec : étendre
  `KdsAllergenAggregationSplitTest` avec un cas snapshot JSON-string.

---

## VECTEURS RÉFUTÉS (defenses correctes — anti-fausse-certitude)

- **Inversion compo « Poulet mariné: » (heal d71dfbfe8)** : NON-régressé. `kdsVariationGroupValue`
  (discriminant `attribute_name`) + `kdsVariationLine` consommés par KitchenDisplaySystemComponent.vue:1576
  (board legacy + items-board). Vitest `kdsCustomization.spec.js` l.297-300 vert (« Viande 1: Poulet mariné »).
  57 specs verts (kdsCustomization 35 / kdsAllergens 13 / kdsLineSemantics 5 / kdsV2KillSwitch 4).
- **2 viandes IDENTIQUES au lieu de distinctes** : RÉFUTÉ — le snapshot réel porte 2 `variation_id`
  distincts (362 Cordon Bleu / 373 Fricadelle), les deux rendus distinctement sur les 2 chemins (cf P3 ci-dessus).
- **Supplément non visible (Cheddar +0,90)** : RÉFUTÉ — `renderItem` émet `{type:'supplement', label:'+ Cheddar'}`,
  rendu jaune italique par `KdsOrderLine.vue:47-49`. Probe PASS.
- **Allergène masqué par fusion 2 commandes** : RÉFUTÉ en flux réel — split-hash food-safety actif
  (`Service:554-559`), `KdsAllergenAggregationSplitTest`+`KdsOrderItemsResourceAllergenExposureTest` verts ;
  coercion numérique côté front (`kdsCustomization.js:247`). Seul reste le gap string-shape latent (P3 ci-dessus).
- **Instruction qui double la compo** : RÉFUTÉ — `sanitizeKdsInstruction` (l.205-220) drop la ligne
  nom-produit + le blob compo (`KDS_COMPO_LINE_RE`), garde seulement formule/`↳ Sauce frites`/notes.
  Specs `kdsCustomization.spec.js` l.210-253 verts.
- **Bump UNPAID → notif client avant paiement** : RÉFUTÉ — release-guard. `KdsUnreleasedOrderBumpTest`
  vert (422, status reste 4, « Bump Blocked: YES »). `KitchenReleaseRule` board-release admet
  PAID|PENDING_COUNTER|POS-CASH uniquement (`applyBoardReleaseFilter` SQL miroir de `orderIsReleasedForBoard`).
- **Recall spam > cap / fenêtre 60s** : RÉFUTÉ — `recall` cap N=1 (409) + state-guard PREPARED-only (422)
  + TTL 60s (422) + invariant « status JAMAIS muté » (l.358-362). `KdsRecallCapNTest` vert (« second recall 409 »).
- **51ᵉ commande tronquée** : RÉFUTÉ — `list:172` limit(51)→take(50) + meta overflow `lastListOverflow`.
  `KdsPaginationOverflowTest` vert (cap 50 + release exception POS-cash).
- **Fuite PII sur le mur OSS** : RÉFUTÉ — mur public `publicIndex` → `CDSOrderDetailsResource`
  (id, order_serial_no, token, queue_number, order_type, status — 0 PII). L'admin authed
  `index` → `PosShortcutOrderResource` (expose `total`). Filtre public PREPARING|PREPARED, branch-scopé.
  `KDSOrderDetailsResource:72-75` n'expose `phone` que pour DELIVERY (data-minimization).

## NON-RÉGRESSION (preuves)
- Vitest KDS: 57 verts (kdsCustomization 35, kdsAllergens 13, kdsLineSemantics 5, kdsV2KillSwitch 4).
- PHPUnit KDS: 28 verts (KdsUnreleasedOrderBump, KdsRecallCapN, KdsPaginationOverflow×2,
  KitchenReleaseRule×7, …).
- 0 fichier modifié, 0 écriture DB. Frozen non touché (aucun fichier KDS/OSS frozen ; source compo
  pos-wizard.js/snapshot/PricingService non touchée).
