# KDS/OSS — Lens SYNC/LOGIC : Lisibilité compo / allergènes / snapshot / durées (R1)

Agent: sync/logique sur sous-système KDS/OSS. READ-ONLY. DB `foodking_e2e` (:8766).
Méthode: Read fichiers ancrés + requêtes SELECT + repro via le VRAI `renderItem`/labels (Vitest jetable, supprimé) + Vitest existants.

Verdict lens: **0 P0, 0 P1**. Le cœur lisibilité (inversion `Poulet mariné:` HEALÉE `d71dfbfe8`, split allergène food-safety, supplément, OSS 0-PII) tient. Deux findings réels P2 (durée brute + perte slots Viande1/Viande2 sur la carte V2), un P3 latent (merge-key snapshot-blind).

---

## [P2] resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue:394-395 — Durée brute "il y a 9540 min" non humanisée (section « Récemment servies »)
  repro:
    - DB: `mysql -u root foodking_e2e -e "SELECT id,updated_at,TIMESTAMPDIFF(MINUTE,updated_at,NOW()) FROM orders WHERE status=8 ORDER BY updated_at DESC"` → rows à 8695/9590/13190 min.
    - Le feed `list()` (KitchenDisplaySystemOrderService.php:126-137) admet les **advance orders en retard SANS borne basse** (`order_datetime < tomorrow`, ligne 135) → une commande advance PREPARED vieille de plusieurs jours entre dans `visibleOrders` → `recentlyServed` (KdsV2Grid:237-247) → `servedAgoLabel`.
    - Math reproduite (i18n fr.json:800 `kds_served_ago="il y a {mins} min"`, V2 default-ON via useV2Layout:1273-1304):
      `updated 6 j 15 h ago -> "il y a 9540 min"` ; `1 jour -> "il y a 1440 min"` ; `23h43 -> "il y a 1423 min"`.
    - Germe « 6 j 15 h » déjà CONFIRMÉ live par le superviseur cette session (minutes brutes rendues).
  evidence: node repro déterministe de la fct (sortie "il y a 9540 min"/"il y a 8695 min") ; DB rows réels (5032=8695min, 4823=13190min) ; useV2Layout default true (aucun KDS_V2_DEFAULT_ENABLED en .env).
  lentille: cuisinier (lit une durée illisible « 9540 min » au lieu de « 6 j 15 h ») + commerçant (chiffre non fiable).
  reco: humaniser dans `servedAgoLabel` — minutes→{j,h,min}. Réutiliser le SSOT `appService.humanizeMinutes()` (déjà utilisé ailleurs admin pour « 6 j 4 h ») ou ajouter labels `kds_served_ago_hm`/`_dh`. Scope-minimal, fichier NON-frozen. TDD: `tests/js/kdsServedAgoHumanized.spec.js` (à créer) asserte 9540→"6 j 15 h", 90→"1 h 30", 30→"il y a 30 min".

---

## [P2] resources/js/helpers/kdsCustomization.js:279 (+261-296) — 2 viandes distinctes affichées sous un groupe générique « Choix » (slots Viande 1/Viande 2 perdus) sur la carte V2
  repro:
    - DB row order_item 4933 (order 5175, PREPARING) snapshot.lines = `Viande 1=Cordon Bleu`, `Viande 2=Fricadelle`, `Sauce=Samouraï`.
    - `renderItem()` réel (Vitest jetable, supprimé après preuve) produit :
      `[{type:'variation',group:'other',label:'Cordon Bleu, Fricadelle'},{type:'variation',group:'sauce',label:'Samouraï'}]`.
    - Carte V2 (`KdsOrderCard.vue:133/180/390` → `renderItem`, V2 default-ON) → cuisinier lit « Choix : Cordon Bleu, Fricadelle » (`label.kds_group_other`=« Choix », fr.json:811).
    - Cause: `renderItem` regroupe via `classifyGroup(v.variation_name, v.attribute_name)` (l.279) — aucun pattern bread/sauce/cuisson ne matche « Viande » → fallback `other` ; les DEUX viandes tombent dans le même seau et sont jointes. Les helpers du heal `d71dfbfe8` (`kdsVariationGroupValue`/`kdsVariationLine`, l.143-169) qui résolvent GROUP=attribute_name **ne sont PAS appelés par `renderItem`** (seul le template legacy items-board les utilise: KitchenDisplaySystemComponent.vue:1577/2239).
  evidence: sortie réelle `renderItem` ci-dessus ; le test existant kdsCustomization.spec.js:298-300 ne couvre que les helpers EN ISOLATION, jamais `renderItem` 2-viandes (le plan liste `kdsTwoMeatsDistinctRender.spec.js` comme À CRÉER) ; legacy path l.1576-1578 rend correctement « Viande 1: Cordon Bleu » (heal OK, divergence des 2 chemins).
  lentille: cuisinier — les 2 viandes RESTENT visibles (pas de fusion, pas de viande perdue, pas de mauvaise viande), mais le slot (« Viande 1 » vs « Viande 2 ») est perdu sous « Choix ». Dégradation de lisibilité, PAS une commande ratée → P2 (et NON P0/P1 : aucune viande masquée).
  reco: dans `renderItem`, pour les lignes snapshot à `attribute_name` non-vide, grouper PAR `attribute_name` (slot réel) plutôt que par `classifyGroup`, en réutilisant `kdsVariationGroupValue` (déjà le SSOT shape-agnostique du heal) — une ligne `variation` par groupe nommé. Fichier NON-frozen. TDD: `tests/js/kdsTwoMeatsDistinctRender.spec.js` asserte 2 lignes group `Viande 1`/`Viande 2`. Non-régression: kdsCustomization 35 tests doivent rester verts.

---

## [P3] app/Services/KitchenDisplaySystemOrderService.php:547-568 — merge-key items-board hashe le LEGACY `item_variations` (3 shapes) pas `composition_snapshot.lines` (snapshot-blind, latent)
  repro:
    - `orderItems()` groupe par `item_variations` (l.548) + `composition_snapshot.addons` (l.550) MAIS jamais `composition_snapshot.lines`.
    - Le legacy `item_variations` a 3 formes co-existantes en DB: full `{"id":362,...,"name":"Cordon Bleu"}` (rows 4933/4932) vs id-only `[{"id":43}]` (rows 4930/4929). Deux compos SNAPSHOT identiques mais formes legacy différentes hashent ≠ → doublure (produit listé 2×, chacun correct). Direction dangereuse (2 viandes ≠ fusionnées en 1) exigerait legacy identique + snapshot ≠.
  evidence: DB `snap_present_but_legacy_empty=0` (chaque row à snapshot a aussi un legacy peuplé → la divergence dangereuse n'est PAS déclenchable ici) ; `SELECT order_id,item_id,COUNT(*) ... HAVING n_rows>=2` sur 2-viandes = **0 ligne** (aucun cas réel de doublure/fusion en base).
  lentille: cuisinier (théorique). Per anti-hallucination §3 : NON reproductible avec données réelles → reporté en P3 LATENT only (la résource `KDSOrderItemsResource:67-89` rend bien snapshot-first ; c'est la CLÉ DE MERGE qui reste legacy). Régression possible si une future écriture re-introduit legacy vide+snapshot riche (cf. bug caisse v5 2026-06-24).
  reco: aligner la merge-key sur le snapshot (hasher `composition_snapshot.lines` canonicalisé, comme la leçon doublure 2026-06-20) OU sentinelle `tests/Feature/KDS/KdsMergeKeySnapshotAwareTest.php`. Pas d'action V1-urgente (0 donnée déclenchante). NON-frozen.

---

## Vérifié-PROPRE (non-findings, pour traçabilité)
- Inversion `Poulet mariné:` (heal `d71dfbfe8`) : Vitest kdsCustomization 35 + kdsLineSemantics 5 + kdsAllergens 13 = **53/53 vert**. `kdsVariationGroupValue` discriminant `attribute_name` OK. Legacy template (1577/2239) corrigé.
- Split allergène food-safety (Service:559 `sha1(normalizeAllergensForHash)`) : OK ; coercion strval (helper l.246-250 [#8]) garde codes numériques. `allergens_snapshot` exposé items-board (KDSOrderItemsResource:44).
- Supplément visible : row 4933 snapshot.extras `Cheddar 0.9` → `renderItem` → "+ Cheddar" (l.300-306). OK.
- Anti-doublure instruction (`sanitizeKdsInstruction` l.205-220) : drop blob compo « Viandes:…Sauce:… », garde « + », « ↳ », notes. OK (n'écho pas la compo).
- OSS PII public : `CDSOrderDetailsResource` = id/serial/token/queue/type/status → **0 PII** (ni name/phone/total). OK.
- Release-filter visible==bumpable : `KitchenReleaseRule` (itemBoardStatuses / orderIsReleasedForBoard:447) — hors lens profond (autre agent), pas d'anomalie repérée côté payload.
- TZ Paris : session_tz=SYSTEM (Paris) confirmé `SELECT @@session.time_zone` → bornes Carbon Paris-local (list:121-124, historyToday:226-236) cohérentes.
