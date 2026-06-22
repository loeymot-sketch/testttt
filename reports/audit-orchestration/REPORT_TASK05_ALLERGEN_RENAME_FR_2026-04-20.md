# T05 — Rapport allergènes FR chaîne (2026-04-20)

## Verdict : FAIL

## Checklist : V1..V8

- [x] **V1.** Seeder identique A↔B avec 14 codes FR — **OK** (fichiers byte-identiques, 14 codes : `gluten`, `crustaces`, `oeufs`, `poisson`, `arachides`, `soja`, `lait`, `fruits_a_coque`, `celeri`, `moutarde`, `sesame`, `sulfites`, `lupin`, `mollusques`).
- [ ] **V2.** Aucun reliquat anglais en prod path — **KO** — voir §2 et §8 : `KsAllergenBadge.vue` mappe les icônes sur des **codes anglais** ; i18n JSON utilise des **clés anglaises** (`allergens.milk`, etc.) alors que l’API émet `code` + `name_key` au format **FR** (`allergens.lait`, …).
- [ ] **V3.** Traductions FR / EN / AR cohérentes — **KO** — `en.json` sur **A** est **vide (0 octets)** ; sur A et B, le bloc `allergens` dans `fr.json` / `ar.json` (et `en.json` sur B) reste sur les **14 clés anglaises historiques**, pas sur les codes FR attendus pour `$t('allergens.<code>')` (voir §3).
- [ ] **V4.** Resources API exposent codes corrects + traductions — **Partiel** — Les resources exposent `code` et `name_key` depuis la DB (**cohérents avec le seeder FR**). La **traduction** est une responsabilité front (`name_key` / `$t`) : elle est **rompue** côté clés JSON + badge (voir §3–§4).
- [x] **V5.** Snapshot `allergens_snapshot` ↔ codes FR — **OK côté écriture** — `FrontendOrderService::resolveAllergenSnapshot` persiste un **JSON tableau de codes** issus du pivot `allergens.code` (donc FR après migration). Colonne ajoutée par `2026_04_18_140004_add_allergens_snapshot_to_order_items.php`. **Risque résiduel** : lignes `order_items` déjà remplies avec d’anciens codes anglais ne sont **pas** migrées par un script dédié (voir §5–§6).
- [x] **V6.** Migration des données existantes — **OUI (partielle)** — `database/migrations/2026_04_18_140002_rename_allergen_codes_to_fr.php` renomme `allergens.code` / `name_key` et remappe `items.allergen_flags`. **Absence** de migration des snapshots historiques dans `order_items.allergens_snapshot` (voir §6).
- [x] **V7.** Tests allergènes ciblent codes FR — **OK** pour les fichiers listés — pas de reliquat anglais **bloquant** dans les tests audités ; `tests/Feature/Database/AllergensSeederTest.php` utilise `crustaceans`/`eggs` uniquement en **`assertFalse`** (contrôle de non-régression). `OrderAllergenSnapshotTest` utilise `lactose` comme allergène factice (hors liste UE14) — acceptable pour le test de snapshot.
- [ ] **V8.** Composants Vue : aucun hardcode anglais — **KO** — `KsAllergenBadge.vue` : objet `ICONS` indexé par codes **anglais** ; `kioskFilters.js` : exemples JSDoc avec `'milk'` (documentation).

---

## 1. Diff seeder A vs B

| Élément | Résultat |
|--------|----------|
| Fichiers | `database/seeders/AllergensSeeder.php` **identiques** sur A (`testttt`) et B (`testttt-kiosk-p93`). |
| 14 codes (ordre seeder) | `gluten`, `crustaces`, `oeufs`, `poisson`, `arachides`, `soja`, `lait`, `fruits_a_coque`, `celeri`, `moutarde`, `sesame`, `sulfites`, `lupin`, `mollusques`. |

---

## 2. Reliquats anglais (catégorisés a/b/c)

Recherche effectuée (équivalent demandé) sur les deux racines ; **note méthodologique** : la requête `rg` avec chaînes **entre quotes** ne détecte pas les **clés d’objet JS non quotées** (ex. `crustaceans:` dans `KsAllergenBadge.vue`). Une recherche complémentaire `crustaceans|tree_nuts|…` dans `resources/js` a été utilisée pour les **hits applicatifs critiques**.

| Path | Code / extrait | Catégorie | Risque |
|------|----------------|-----------|--------|
| `database/migrations/2026_04_18_140002_rename_allergen_codes_to_fr.php` | Map `crustaceans` → `crustaces`, etc. | **(a)** Migration | Faible — attendu |
| `tests/Feature/Database/AllergensSeederTest.php` (A et B) | `assertFalse` sur `crustaceans`, `eggs` | **(b)** Test | Faible — vérifie l’absence des anciens codes |
| `tasks/audit-orchestration/05_TASK_*.md` | Exemple de commande `rg` | Doc | Néant |
| `reports/execution/KIOSK_DESIGN_V1_PHASE_1*.md` | Ancienne table codes EN | **(b)** Rapport | Néant runtime |
| `resources/js/helpers/kioskFilters.js` | JSDoc : exemples `'milk'`, `gluten` | **(c)** Doc dans bundle | Moyen — doc trompeuse pour intégrateurs |
| `resources/js/helpers/kioskViandeCatalog.js` | `lower.includes('fish')` | **(c)** Heuristique nom produit | Faible — mot anglais pour détection « poisson », pas le code allergène DB |
| `resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue` | `ICONS` : `crustaceans`, `eggs`, `milk`, `tree_nuts`, … | **(c)** **Prod** | **Critique** — icônes / UX : codes API FR (`lait`, `crustaces`, …) ne matchent pas les clés du map → fallback `•` ; `$t('allergens.'+code)` sans entrée FR → affichage du **code brut** |
| `resources/js/languages/fr.json`, `ar.json`, `en.json` (B) | Clés `allergens.milk`, `allergens.crustaceans`, … | **(c)** **Prod** | **Critique** — décalage avec `name_key` `allergens.lait`, `allergens.crustaces`, … |
| `resources/js/languages/en.json` (A) | Fichier **vide** | **(c)** **Prod** | **Critique** — aucune chaîne EN chargée |

**Synthèse V2** : aucune occurrence des motifs « quoted » dans `app/` (PHP). Les reliquats **bloquants** sont côté **Vue + i18n** (chemins ci-dessus), en particulier **`KsAllergenBadge.vue`** et les fichiers **`resources/js/languages/*.json`**.

---

## 3. Parité traductions FR/EN/AR (delta clés)

**Référence cible** (alignée seeder / API) : sous l’objet JSON `allergens`, les 14 clés devraient être :  
`gluten`, `crustaces`, `oeufs`, `poisson`, `arachides`, `soja`, `lait`, `fruits_a_coque`, `celeri`, `moutarde`, `sesame`, `sulfites`, `lupin`, `mollusques`.

| Langue | Fichier (A) | État |
|--------|-------------|------|
| **FR** | `resources/js/languages/fr.json` | Bloc `allergens` encore aux **clés anglaises** (`crustaceans`, `eggs`, `milk`, `tree_nuts`, `celery`, `sulphites`, `molluscs`, …). **Manquantes** pour la résolution `$t('allergens.<code>')` avec codes FR : entre autres `crustaces`, `oeufs`, `poisson`, `arachides`, `soja`, `lait`, `fruits_a_coque`, `celeri`, `moutarde`, `sulfites`, `mollusques` (les clés **nouvelles** alignées UE). **Conservées** sans renommage : `gluten`, `sesame`, `lupin` seulement en partie alignées (noms différents pour le reste). |
| **EN** | `resources/js/languages/en.json` | **Fichier vide (0 octets)** sur **A** — **toutes** les clés manquantes. |
| **AR** | `resources/js/languages/ar.json` | Même schéma que FR : **clés anglaises**, mêmes écarts vs codes FR API. |

`resources/lang/{fr,en,ar}/allergens.php` : **absents** (0 fichier trouvé).

**Clone B** : `fr.json` / `ar.json` / `en.json` présents avec le même bloc `allergens` aux **clés anglaises** — même problème de **parité** avec les codes FR, malgré un `en.json` non vide.

---

## 4. Resources API (NormalItem, Kds, etc.)

| Resource | Allergènes | Observation |
|----------|--------------|-------------|
| `NormalItemResource` | `allergens[]` : `id`, `code`, `name_key`, `icon`, `is_trace` | **`code` brut DB** (FR après migration) ; **pas** de libellé traduit serveur — le client doit traduire via `name_key` / i18n. |
| `SimpleItemResource` | Non exposé | N/A |
| `ItemResource` (admin/POS) | Idem `NormalItemResource` + `allergen_flags` | Cohérent |
| `OrderItemResource` | `allergens_snapshot` décodé | Tableau de codes (JSON) — aligné sur les codes persistés |
| `KDSOrderItemsResource` | Pas d’allergènes | N/A |
| `KDSOrderDetailsResource` | Délègue à `OrderItemResource` pour les lignes | Snapshot disponible via `order_items` |

**Cohérence** : le backend est **cohérent** avec les codes FR en base. L’incohérence est **front/i18n** (§3, §8).

*Variant* / *Combo* dédiés : aucun fichier `Variant*Resource.php` / `Combo*Resource.php` dans `app/Http/Resources/` sur A.

---

## 5. Snapshot `order_items.allergens_snapshot`

| Élément | Détail |
|---------|--------|
| Service | `FrontendOrderService::hydrateAllergenSnapshots` / `resolveAllergenSnapshot` : priorité aux codes du **pivot** `item_allergen` ; sinon legacy `allergen_flags` si `AllergenService::projectFlags` absent. |
| Format stocké | **JSON tableau de strings** (codes), ex. `["gluten","lait"]` — pas d’objet `{code,name}` dans ce flux. |
| Migration colonne | `2026_04_18_140004_add_allergens_snapshot_to_order_items.php` : ajoute `json` nullable. |

**Cohérence FR** : pour les **nouvelles** commandes après migration des lignes `allergens` et pivots, les codes snapshot suivent la DB. **Données historiques** dans `order_items.allergens_snapshot` pouvant contenir d’anciens codes EN : **non traitées** par une migration dédiée (§6).

---

## 6. Migration données existantes : OUI/NON + path/plan

| Livrable | Présent ? |
|----------|-----------|
| Renommage `allergens` + `items.allergen_flags` | **OUI** — `database/migrations/2026_04_18_140002_rename_allergen_codes_to_fr.php` |
| Colonne `order_items.allergens_snapshot` | **OUI** — `2026_04_18_140004_add_allergens_snapshot_to_order_items.php` |
| Migration / backfill des **valeurs** existantes dans `order_items.allergens_snapshot` (EN → FR) | **NON** — aucune migration repérée sous `database/migrations/*` ciblant ce JSON |

**Alerte** : environnements ayant déjà des snapshots en **codes anglais** : risque d’affichage / analytics incohérents jusqu’à script de migration ou reprise manuelle.

---

## 7. Tests allergènes : OK/KO par fichier

| Fichier | OK/KO | Notes |
|---------|-------|------|
| `tests/Feature/KioskPhase1/AllergensSeederTest.php` | **OK** | Liste des 14 codes **FR**. |
| `tests/Feature/Database/AllergensSeederTest.php` | **OK** | Assertions **négatives** sur anciens codes. |
| `tests/Feature/KioskPhase1/NormalItemResourceAllergensTest.php` | **OK** | Utilise `lait`, `gluten`, `name_key` préfixe `allergens.`. |
| `tests/Feature/ItemResourceAllergensTest.php` | **OK** | Codes `gluten`, `lait`, `sesame` ; `name_key` de test `allergen.*` (typo volontaire / jeu de test admin) — pas un reliquat seeder UE. |
| `tests/Feature/Orders/OrderAllergenSnapshotTest.php` | **OK** | `gluten`, `lactose`, `oeufs` — pas d’ancien code anglais pour l’UE14. |
| `tests/Feature/Orders/KDSAllergenVisibilityTest.php` | **OK** | `allergens_snapshot` : `['arachides']`. |
| `tests/Feature/Services/Menu/MenuProjectionServiceTest.php` | **OK** | `gluten`, `lait` dans les données de test. |
| `tests/Unit/Services/AllergenServiceTest.php` | **OK** | `gluten`, `oeufs`. |

**P0** : non déclenché par des **tests** utilisant encore la liste anglaise comme vérité — le **FAIL** vient du **front / i18n** et de **`en.json` vide sur A**.

---

## 8. Composants Vue

| Fichier | Problème |
|---------|----------|
| `KsAllergenBadge.vue` | `ICONS` et commentaires listent des codes **anglais** ; incompatible avec les codes **FR** renvoyés par l’API. |
| `kioskFilters.js` | JSDoc uniquement — exemples à mettre à jour (`lait`, etc.). |

Commande demandée `rg -n "allergens?\.(lait|milk|…)" resources/js` : sur A, **aucun match** pour ce motif (les fichiers utilisent surtout des clés JSON ou des concaténations `allergens.${code}`).

---

## Tableau actions (priorisé)

| Couche | OK / KO | Preuve | Action |
|--------|---------|--------|--------|
| Seeder A/B | OK | Fichiers identiques, 14 codes FR | Aucune |
| Backend Resources / snapshot | OK | `NormalItemResource`, `OrderItemResource`, `FrontendOrderService` | Optionnel : documenter contrat `allergens_snapshot` (codes FR uniquement) |
| Migration DB | Partiel | `2026_04_18_140002_*` | Ajouter **script / migration** pour `order_items.allergens_snapshot` si données historiques EN en prod |
| i18n JSON (FR/EN/AR) | KO | Clés `allergens.*` encore **anglais** ; `en.json` **vide** sur A | **P0** : renommer / dupliquer clés vers codes FR UE ; restaurer **`en.json`** sur A |
| `KsAllergenBadge.vue` | KO | `ICONS` en clés anglaises | **P0** : remapper icônes sur les 14 codes FR (ou table indirection code→icône) |
| `kioskFilters.js` | Doc | JSDoc `milk` | P2 : mettre à jour les exemples |
| Tests | OK | §7 | Surveiller futurs tests pour liste exclusivement FR |

---

*Audit READ-ONLY — aucune modification de code source hors ce rapport.*
