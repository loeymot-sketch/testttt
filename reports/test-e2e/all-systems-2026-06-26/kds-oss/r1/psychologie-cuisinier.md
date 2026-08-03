# KDS/OSS r1 — Lentille PSYCHOLOGIE CUISINIER (Board + bump/recall + transitions)

Rôle: vrai cuisinier en rush — « lis-je SANS ambiguïté quoi préparer ? ». Une erreur de
lecture = commande client ratée. READ-ONLY. DB live `foodking_e2e`.

Sous-système ciblé: Sub 3.a (board/bump/recall/transitions) + lisibilité compo (Sub 3.b coeur).

---

## SYNTHÈSE

Le coeur bump/recall/transitions est SOLIDE et prouvé (22/22 PHPUnit verts, données live
cohérentes) — la garde release (UNPAID non-bumpable, pas de notif-avant-paiement),
le 409 optimiste, le cap recall N=1 fenêtre-glissante, le whitelist transition tiennent.
Les findings ne sont PAS dans la mécanique de bump mais dans la **LISIBILITÉ** (le coeur
de ma lentille) et un **filtre fenêtre**.

| # | Sév | Titre | Lentille |
|---|-----|-------|----------|
| 1 | P2 | Board V2 (défaut ON) fusionne les 2 viandes sous « Choix », perd « Viande 1/2 » — diverge du board principal | cuisinier |
| 2 | P2 | Filtre fenêtre KDS ne matche que `is_advance_order` ∈ {5,10} ; validation accepte tout numeric → commande payée invisible | cuisinier/technique |
| 3 | P3 | `KdsSyncService::sync` n'applique PAS le release-filter de `list()` (divergence SSOT sur le feed poll) | technique/sync |

VERIFIED-HOLDS (réfutés, NON reportés) — voir §HOLDS.

---

## [P2] resources/js/helpers/kdsCustomization.js:33-41,277-295 — 2 viandes fusionnées « Choix : … » sur le board V2 (défaut ON), divergence avec le board principal

**repro**:
1. Commande réelle #5175 (order_items.id=4933, item_id=97) en DB `foodking_e2e` :
   `composition_snapshot.lines` = [Viande 1=Cordon Bleu, Viande 2=Fricadelle, Sauce=Samouraï] + extra Cheddar.
2. Board V2 = `KdsV2Grid` → `KdsOrderCard.vue:391 renderItem(item).lines` → `KdsOrderLine.vue`.
   `KDS_V2_DEFAULT_ENABLED` non-set dans `.env` → `config/kds.php:24` défaut **true** → V2 est le board LIVE.
3. Exécution du VRAI helper sur le VRAI snapshot (Vitest probe) :
   - V2 (`renderItem`): `["H:1x Sandwich Cayenne","V[other]:Cordon Bleu, Fricadelle","V[sauce]:Samouraï","S:+ Cheddar"]`
   - Board principal (`kdsVariationLine`, `KitchenDisplaySystemComponent.vue:1577`): `["Viande 1: Cordon Bleu","Viande 2: Fricadelle","Sauce (1ère Gratuite): Samouraï"]`

**evidence**: probe Vitest sur `resources/js/helpers/kdsCustomization.js` (renderItem + kdsVariationLine),
sortie `V2_RENDERITEM` vs `MAINBOARD_KDSVARIATIONLINE` ci-dessus. Cause racine :
`classifyGroup(variationName, attributeName)` (l.33-41) teste les `GROUP_PATTERNS` (bread/crudites/
sauce/cooking/drink/size) — **« viande » n'y figure PAS** → les 2 lignes Viande tombent dans
`'other'` → fusionnées en UNE ligne `byGroup.get('other')` (l.284-295) = `"Cordon Bleu, Fricadelle"`,
libellé `kds_group_other` = « Choix » (fr.json:811). Le helper HEALÉ group-aware
`kdsVariationLine`/`kdsVariationGroupValue` (l.143-169, heal `d71dfbfe8`) qui PRÉSERVE
« Viande 1 »/« Viande 2 » **n'est PAS utilisé par `renderItem`** → seul le board principal en
bénéficie. Le board V2 (KdsOrderCard + KdsHistoryDrawer) garde le chemin `classifyGroup`.

**lentille**: cuisinier — sur le board par défaut, le cuisinier voit « Choix : Cordon Bleu,
Fricadelle » au lieu de « Viande 1 : Cordon Bleu / Viande 2 : Fricadelle ». Les 2 viandes restent
listées (pas de perte de nom → il prépare bien les 2), mais la STRUCTURE positionnelle (quelle
viande est #1 vs #2) est effacée et le même ordre s'affiche différemment selon le board → confusion
en rush. Borderline P1 si un produit utilisait Viande1/Viande2 pour des positions distinctes ;
maintenu P2 car les 2 noms sont présents et le risque food est borné.

**reco**: faire passer `renderItem` par `kdsVariationGroupValue` (discriminant `attribute_name`)
au lieu de `classifyGroup` pour les lignes à `attribute_name` présent (shape snapshot), pour que
V2/cards/drawer rendent identiquement au board principal. NON-frozen (helper + KdsOrderLine).
TDD: créer `tests/js/kdsCardCompositionShapeParity.spec.js` + `kdsTwoMeatsDistinctRender.spec.js`
(listés « À CRÉER » dans le plan Sub 3.b) asserttant 2 lignes distinctes Viande1/Viande2.

---

## [P2] app/Services/KitchenDisplaySystemOrderService.php:126-137 — fenêtre KDS ne matche que is_advance_order ∈ {Ask::YES=5, Ask::NO=10} ; validation `numeric` → commande payée invisible au cuisinier

**repro**:
1. `OrderRequest.php:164` (et `PosOrderRequest:107`) : `'is_advance_order' => ['required','numeric']`
   — aucun `Rule::in([Ask::YES,Ask::NO])`. Seul `=== 0` est remappé en `Ask::NO` (`OrderRequest:88-89`).
   Modèle Order ne caste qu'en `integer` (Order.php:84), pas de clamp. Colonne défaut = `Ask::YES`
   (migration 2022_11_17_110810:34).
2. La fenêtre `list()` (l.126-137) matche UNIQUEMENT :
   - standard: `whereBetween(order_datetime,[today,endOfDay]) AND is_advance_order = Ask::NO(10)`
   - advance:  `is_advance_order = Ask::YES(5) AND order_datetime < tomorrow AND status NOT IN (13,16)`
   Toute valeur ∉ {5,10} ne matche NI l'une NI l'autre → exclue du board.
3. DB live : 21 commandes `status=7(PREPARING)`, `payment_status=5(PAID)`, branch_id=1, avec
   `is_advance_order ∈ {1,2}` → invisibles au board (requête de simulation du filtre exact).

**evidence**:
```
SELECT is_advance_order,status,COUNT(*) FROM orders
 WHERE deleted_at IS NULL AND status IN (4,7,8) AND branch_id=1
   AND (payment_status IN (5,15) OR (order_type=15 AND pos_payment_method=1))
   AND is_advance_order NOT IN (5,10) GROUP BY is_advance_order,status;
-- is_advance_order=1 status=7 n=17 ; is_advance_order=2 status=7 n=4
```
Filtre identique dans `KdsSyncService.php:99-108` (même cécité).

**lentille**: cuisinier — une commande PAYÉE en cours de prépa qui n'apparaît jamais sur l'écran =
client servi en retard/jamais. **Caveat honnête**: les 21 rows live ont `source='15'` + `order_type=1`
(valeur OrderType invalide) → ce sont des rows de test-pollution, PAS des commandes client réelles.
Les clients légitimes (borne/POS/web) envoient 0→remappé 10, ou le toggle advance→5. La cécité
requiert donc un client malformé envoyant un numeric hors {5,10,0}. Sévérité maintenue P2 :
la validation l'ACCEPTE réellement et la conséquence (commande payée invisible) est grave si
déclenchée ; c'est un durcissement de validation (defense-in-depth), pas une casse live pour
clients légitimes.

**reco**: durcir `is_advance_order` → `Rule::in([Ask::YES,Ask::NO])` dans OrderRequest/PosOrderRequest
(rejette l'orphelin au lieu de le perdre), OU rendre le filtre fenêtre robuste
(`is_advance_order != Ask::YES` ⇒ traité comme standard). NON-frozen. TDD : test FormRequest 422
sur valeur hors-enum + sentinel service que toute commande payée du jour status∈{4,7,8} est listée.

---

## [P3] app/Services/KdsSyncService.php:96-112 — le feed delta-poll n'applique PAS applyBoardReleaseFilter (divergence SSOT « visible == bumpable »)

**repro**:
1. `KitchenDisplaySystemOrderService::list()` applique `KitchenReleaseRule::applyBoardReleaseFilter`
   (l.78) → board = PAID|PENDING_COUNTER|POS-cash uniquement.
2. `KdsSyncService::sync()` (l.96-112) filtre status∈{ACCEPT,PREPARING,PREPARED} + fenêtre + branch,
   **SANS** `applyBoardReleaseFilter` → un ordre non-released matchant la fenêtre peut être ré-injecté
   sur le board via le merge delta (le poll est le fallback du cuisinier si soketi meurt en local).
3. DB live : #4991 (UNPAID payment_status=10, DELIVERY, status=8 PREPARED, advance) — exclu par
   `list()` (non-released) mais matche la requête de `sync()`.

**evidence**:
```
SELECT id,order_type,status,payment_status FROM orders
 WHERE deleted_at IS NULL AND status IN (7,8) AND payment_status=10;  -- id=4991 status=8
```
Lecture code: `KdsSyncService.php:96-108` n'a aucun appel `KitchenReleaseRule::*`.

**lentille**: technique/sync — la promesse SSOT « visible sur le board == bumpable » (raison d'être
de KitchenReleaseRule, doc l.93-98) est rompue sur la 3e surface (le feed poll). Exposition réelle
faible : pour qu'une commande UNPAID atteigne ACCEPT/PREPARING il faut un chemin NON-KDS
(changeStatus bloque l'UNPAID l.447) ; et le front poll avec un `since` récent (#4991 maj il y a 1
semaine n'apparaîtrait que si `since` est très ancien). P3 : divergence de filtre réelle mais
exploitation pratique étroite en V1 mono-poste.

**reco**: appliquer `KitchenReleaseRule::applyBoardReleaseFilter($ordersQuery)` dans `KdsSyncService::sync`
(l.96, après le whereIn status) — 1 ligne, NON-frozen, miroir exact de list(). TDD : sentinel que
sync() n'émet jamais un ordre que list() cacherait (paire de fixtures UNPAID-PREPARING).

---

## HOLDS (réfutés — verify-before-report, NON reportés comme findings)

- **Garde release bump UNPAID** : `changeStatus:447 orderIsReleasedForBoard` → 422 sur UNPAID non-cash.
  Prouvé : `KitchenReleaseRuleTest` (board release blocks unpaid non cash / pos cash released) +
  `KdsUnreleasedOrderBumpTest`/P1 verts. Live : aucune commande UNPAID atteignable en board via KDS.
- **2 stations bump simultané → 409** : lock optimiste `expected_status` (`changeStatus:411-424`),
  `KdsExpectedStatusConflictSentinelTest` vert (2e bump même expected = 409).
- **Recall cap N=1 / fenêtre 60s** : `recall:331-339` fenêtre glissante `now - 60s` (immunisée à
  l'avance de updated_at), `KdsRecallCapNTest` vert (2e recall 409 même après updated_at avance).
  Status JAMAIS muté (re-read assertion l.360-363).
- **51e commande tronquée** : `list:172 limit(51)→take(50)` + meta `overflow` (`Controller:33`) =
  le cuisinier EST averti du débordement. `KdsPaginationOverflowTest` couvre. (Note: bannière overflow
  exposée par meta — surface front à vérifier visuellement par QA-Visual, hors lentille backend.)
- **Recall ne mute pas le status / ne downgrade pas OSS** : append-only `order_status_transitions`
  reason='kitchen_recall', `OrderStateMachine::recordTransition` court-circuité à dessein (from==to).
- **TZ Paris fenêtre** : session_tz=SYSTEM=Paris (NOW()=04:24 vs UTC 02:24 vérifié live) → bornes
  Carbon Paris-local correctes ; sentinel `KdsTodayWindowTzSentinelTest`. Les rows {1,2} invisibles
  ne sont PAS dues à la TZ (elles sont in-window) mais à l'enum-orphan (finding #2).
- **Supplément Cheddar +0,90 visible** : `renderItem` émet `S:+ Cheddar` sur les 2 boards (prouvé probe).
- **Whitelist transition** : `KdsTransitionWhitelistSentinelTest` (impossible d'annuler depuis le KDS).

---

## Evidence técnique
- PHPUnit (sqlite :memory:) `KdsUnreleasedOrderBump|KdsRecallCapN|KdsExpectedStatusConflict|KdsTransitionWhitelist|KdsPaginationOverflow|KitchenReleaseRule` = **22 passed**.
- Vitest `kdsCustomization|kdsBumpRecall|kdsLineSemantics` = **44 passed** (baseline ; aucun n'asserte la distinction 2-viandes sur V2 → gap couverture confirmé).
- Probe Vitest (réel helper, réel snapshot #5175) : V2 fusionne / board principal distinct.
- Frozen: AUCUN fichier touché (READ-ONLY). pos-wizard.js/KioskWizard/PricingService/snapshot intacts.
