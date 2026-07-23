# Dimension B — Gestion Stock + BOM (matières premières) — Findings adversaires

> READ-ONLY. HEAD `b8084b3107`. DB locale `foodking_e2e` (probes lecture seule, aucune écriture).
> Verify-before-report : chaque finding porte file:line + preuve DB/grep. Findings non prouvés = REJETÉS (voir §Réfutés).

## Preuves DB (lecture seule, tinker)
- `raw_material_recipe_lines` = **104**, TOUTES `subject_type = App\Models\Item` (0 ItemVariation, 0 ItemExtra).
- `subject_group` non-null = **0 row**.
- `raw_materials` = 12 (seeder doc dit « 11 rows » — dérive doc mineure), `avg_cost` NULL = **12/12**.
- `raw_material_stocks` = 5, `raw_material_movements` = 5, `on_hand < 0` = 5 (signé, attendu).
- `qty = 0` recipe lines = **1** → Suprême `Poulet×0`.
- Produits pain SANS protéine en recette : **Méga, Galette Cayenne, Galette Normale, Fish Burger, Tacos M, Tacos L**.
- Migration `2026_07_23_100000_add_manual_unavailable_since_to_item_branch_availability` = **Pending** (colonne absente sur cette DB).

---

## CONFIRMÉS

### B-1 — [P2] Consommation LIVE jamais annulée sur cancel/refund → dérive vs replay
`ConsumeRawMaterialsOnOrderCreated` (listener OrderCreated, `EventServiceProvider.php:188`) consomme à la création **quel que soit le statut final**. Aucun listener n'appelle `RawMaterialStockService::receive()` sur `OrderCanceled` / `RefundCreated` — grep : `receive(` n'a **zéro appelant** hors sa propre définition (`RawMaterialStockService.php:40`). Or `raw-materials:replay-consumption` **exclut** CANCELED/REJECTED/RETURNED (`RawMaterialReplayConsumptionCommand.php:61-65`).
Conséquence : `on_hand` matière **sur-consomme** définitivement à chaque commande annulée et **diverge en permanence** d'une reconstruction par replay. Le système `stock_levels` existant, lui, RÉTABLIT (ReleaseStockOnOrderCanceled/RefundCreated) → les deux vérités stock **se contredisent sur les annulées**. Seul l'inventaire mensuel `adjust()` réaligne. Asymétrie non documentée.

### B-2 — [P2, matérialité élevée] Extras & variations ne décrémentent JAMAIS le stock matière — chaque extra silencieusement skippé
Seul `RawMaterialFicheCommand.php:142` écrit des recipe lines, exclusivement `subject_type=Item::class`, **jamais** `subject_group`, ItemVariation, ni ItemExtra (aucun autre writer — grep). DB : 104/104 Item, 0 subject_group. Donc dans `RawMaterialConsumptionService::consumeForOrderItem` les étapes 2 (variations, l.124-136) et 3 (extras, l.138-164) **résolvent vide à chaque commande** → tout extra part dans `skipped[]` (l.149).
Impact réel : suppléments (sauce en plus, viande/cheddar extra) ET **protéines choisies par variation/extra** (viande des Tacos/Assiettes) ne décrémentent aucune matière. Symptôme direct : **Tacos M/L, Méga, Fish Burger** ont une recette SANS protéine (leur viande passe par des options non mappées). Le docblock du service (l.24-32) présente pourtant la résolution extra/variation + `subject_group` OR-match comme ACTIVE, sans dire qu'aucune donnée ne l'alimente → machinerie **inerte**, stock théorique **sous-estime** la conso réelle des produits cœur. (La fiche défère « suppléments/bols » à une vague future, mais l'écart doc-active↔0-donnée + la sous-estimation protéine ne sont signalés nulle part.)

---

## MINEURS

### B-3 — [P3] Recipe lines périmées/zéro s'accumulent (updateOrCreate sans delete)
`RawMaterialFicheCommand` upsert (`updateOrCreate`) et **ne supprime jamais** les lignes qu'il ne régénère plus. DB : 1 ligne `qty=0` (Suprême `Poulet×0`, résidu de la règle pré-correction owner). Inoffensif au `consume` (`qty<=0` skippé, l.178) MAIS une ligne `Poulet×0` **flippe quand même** `FoodCostService::has_unknown_cost=true` (Poulet.avg_cost NULL, l.65-70) → un produit entièrement prix-connu pourrait afficher « en attente prix » à tort. Un futur changement de règle laissant une ligne `qty>0` périmée sur-consommerait. La promesse « idempotent, même nombre de lignes » ne tient QUE si les règles de prefill ne changent jamais.

---

## RÉFUTÉS (attaqués, propres — pas de finding)

- **#1 « viande hachée consommée pour un produit poulet »** : RÉFUTÉ. 0 produit poulet-nommé n'a de bœuf-seul (probe DB : `suspect=[]`). Cohérence sujet→matière niveau Item saine (échantillons Cayenne/Suprême OK).
- **#2 sum-then-consume / idempotence / signe** : RÉFUTÉ (design CORRECT). Agrégation `$totals[raw_material_id]` avant un **seul** `consume()` par matière (l.114-200) : 2 recettes même matière s'additionnent bien. Idempotence triplet (source_type,source_id,raw_material_id) rend le replay no-op vrai (`isDuplicateSource`, l.192-203). `on_hand` négatif = intentionnel/documenté (signé, pas de CHECK≥0).
- **#3 Decision-A** : CODE CORRECT (RÉFUTÉ comme bug). `StockService::syncItemAvailabilityForStockLevel` protège le 86 manuel (`manual_unavailable_since != null`) contre l'écrasement à `on_hand<=0` (l.201-204) ET la réactivation au restock (l.233-242). `AvailabilityService::toggle` stampe `manual_unavailable_since` ($manual=true défaut, l.79-95), `/m` `MobileStockController.php:131` y délègue. Quota auto-86 `'out_of_stock'` s'auto-guérit séparément (`reconcileStaleDailyQuota` key `==='out_of_stock'`, préserve le manuel). **NOTE ENV** : colonne `manual_unavailable_since` **absente sur la DB e2e locale** (migration Pending) → Decision-A non exerçable localement ; artefact d'environnement, pas un défaut code (vérifier VPS migré).
- **#4 food-cost 0€ trompeur** : RÉFUTÉ. `materialCostCell` renvoie `? (prix non saisi)` pour coût inconnu/0, jamais un 0€ mensonger ; `margin=null` si un coût manque ou pas de recette (NULL-safe, `FoodCostService.php:87-92`). Les 12 matières ayant avg_cost NULL, tout produit à recette affiche « en attente prix » = état attendu pré-P3 documenté.
- **#4 replay inclut les annulées** : RÉFUTÉ. Le replay les EXCLUT (`EXCLUDED_STATUSES`). La divergence réelle est inverse (le LIVE les inclut sans reprise → B-1).
