# B2 — Diff structurel de la composition produit, 48 h (2026-09-02 01h00 → 2026-09-04)

Source « avant » : tables `h48_*` (dump `daily-2026-09-02.sql.gz`). Source « après » : tables de
production sans préfixe. Toutes les requêtes ci-dessous ont été exécutées en lecture seule sur
`ssh lecayenne` → `mysql --defaults-extra-file=/tmp/.dbcnf foodking`.

**Comparaisons impossibles (dites ici plutôt que devinées)** : `item_addons` et `wizard_pages` /
`wizard_page_choices` n'ont pas d'équivalent `h48_`. L'état « avant » des add-ons (formule, boisson,
accompagnement) et des pages de bibliothèque n'est donc pas mesurable. Les constats sur la formule
ci-dessous portent sur **l'étape qui expose l'add-on**, pas sur l'add-on lui-même.

---

## 0. Le changement de régime (cadre de lecture de tout le reste)

`MenuProjectionService.php:309-312` (caisse) et `KioskMenuService.php:476-479` (borne) sélectionnent
le profil de composition avec **`whereIn('item_id', …)->where('is_published', true)`** et ne chargent
que les étapes **`is_active = true`**. Un profil de *catégorie* n'est jamais lu par ces deux surfaces.
Le front confirme la préséance : `KioskWizardComponent.vue:827` (`this.resolvedItem?.composer_profile
|| null`) et `public/js/pos-wizard.js` (« composer_profile.steps is present, delegate to buildStep »).
Sans profil, les deux surfaces retombent sur la charge brute `variations` / `extras` / `addons`.

| | h48 | maintenant |
|---|---|---|
| Profils `item_wizard_profiles` | 30 | 60 |
| dont profils **produit** publiés | **2** (Bol Frites, Bol Riz) | **30** |
| Étapes `item_wizard_steps` | 131 | 301 |

30 profils produit publiés ont été créés **le 2026-09-03 entre 01:17:15 et 01:17:18**, par le fan-out
`ComposerProfileService::fanOutCategoryPublish()` déclenché par la publication des profils de
catégorie 34→39. Conséquence : **28 produits qui composaient jusque-là par le chemin de repli
(attributs + extras + add-ons bruts) sont passés d'un coup au pilotage par profil**, où seules les
étapes actives existent. C'est la cause structurelle de tout ce qui suit.

---

## 1. Produits (`h48_items` vs `items`) — quasi immobiles

98 lignes avant, 98 après.

| Contrôle | Résultat |
|---|---|
| Ajoutés | **0** |
| Supprimés / `deleted_at` modifié | **0** |
| `status` modifié | **0** |
| `price` modifié | **0** |
| `is_available`, `channels`, `item_type`, `allergen_flags`, `item_category_id` | **0** |
| Renommés | **1** |

Seul renommage : `id 25` « Sandwich Classique » → « **Sandwich Classique (ancienne fiche, mai 2026)** ».
Le produit reste `status=5`, `deleted_at IS NULL` : il est toujours commandable, avec un nom qui
s'affiche tel quel au client.

## 2. Attributs (`h48_item_attributes` vs `item_attributes`) — un seul changement, majeur

10 attributs avant, 10 après. Aucun ajout, aucune suppression, aucun renommage, aucun changement de
`status` ni d'`is_available`.

| id | nom | `min_select` | `max_select` | `allow_repeat` |
|---|---|---|---|---|
| **1** | **Viande 1** | 1 → 1 | **1 → 3** | **0 → 1** |
| 2,3,4,5,6,7,8,9,13 | (les 9 autres) | inchangé | inchangé | inchangé |

« Viande 1 » devient **multiple (jusqu'à 3) et répétable**. Le plafond effectif reste toutefois fixé
par l'étape : les profils tacos imposent `min/max` 1/4, 2/2 (Tacos L) et 3/3 (Tacos XL).

## 3. Variations par produit × attribut

Toutes les lignes sont `status=5` des deux côtés : seul `deleted_at` discrimine.
**Actives : 815 → 924 (+109).** 181 lignes créées, 46 lignes disparues (suppression physique),
**0 ligne nouvellement soft-deletée**.

Les 46 disparues sont les « viandes inventées » (Poulet curry / tandoori / crispy + 1 produit de
test) — nettoyage légitime signalé dans le brief. **Aucune autre perte de choix.**

Les 7 noms ajoutés partout sont : Cordon Bleu, Fricadelle, Mexicanos, Nuggets, Poulet mariné,
Tenders, Viande Hachée (+ « Mixte (hachée + poulet) » sur Viande 1 seulement).

| Produit | Attribut | Avant → Après | Retraits |
|---|---|---|---|
| Suprême | Viande 1 | 0 → 8 | — |
| Cayenne, Sdw Cl. (anc.), Tacos M, Suprême, Sdw Classique (119) | Viande 2 | 0 → 7 | — |
| Tacos M, Big Tacos, Tacos L | Viande 3 | 0 → 7 | — |
| Cayenne, Sandwich Classique (119) | Viande 1 | 3 → 8 | — |
| Sdw Cl. (anc.), Big Tacos, Big Cayenne, Big Classique, Big Chicken | Viande 1 | 4 → 8 | Poulet crispy/curry/tandoori |
| Big Tacos, Big Cayenne, Big Classique | Viande 2 | 4 → 7 | Poulet crispy/curry/tandoori |
| Galette Normale, Tacos M, Tacos L, Méga, Terminator, Tacos XL | Viande 1 | 7 → 8 | — |

**Les 6 burgers restent à 0 choix de viande** (`38 Chicken Burger`, `98 Cheese Burger`,
`99 Double Cheese`, `100 Fish Burger`, `101 Big Burger`, `102 Grill Burger`) : leurs lignes viande
étaient **déjà détachées dans le dump h48** (`avant = 0`), la mesure ne les compte donc pas comme une
perte de la fenêtre. Voir §6 pour la conséquence réelle.

## 4. `visible_on` des variations

**Aucune ligne existante n'a changé de `visible_on` (0 sur 924).** La bascule vient des lignes neuves.

| `visible_on` | h48 | maintenant |
|---|---|---|
| `NULL` (toutes surfaces) | 809 | 906 |
| `["pos"]` (caisse seule) | **6** | **18** |

Les 12 nouvelles lignes caisse-seule sont toutes **« Mixte (hachée + poulet) »** (Galette Normale,
Sdw Cl. anc., Tacos M/L/XL, Big Tacos, Big Cayenne, Big Classique, Big Chicken, Suprême, Méga,
Terminator). Effet client : sur ces produits la borne affiche **7 viandes**, la caisse **8**. Les 6
lignes `["pos"]` préexistantes suivent le même motif — donc probablement voulu, mais c'est un écart
borne/caisse à assumer.

## 5. Extras (`h48_item_extras` vs `item_extras`)

Actifs : **520 → 558 (+38)**. Par `group_label` : `crudite` **139 → 132**, `supplement` 216 → 220,
`supplement_bol` **66 → 108**, `sauce` 45 → 45, `NULL` 52 → 52.

**Ajouts** (tous créés le 2026-09-03 01:17, `visible_on = NULL`, `status = 5`) :

| `group_label` | Nom | Prix | Nb produits |
|---|---|---|---|
| `supplement_bol` | Boursin, Cheddar, Emmental, Légumes sautés, Œuf, Raclette | 0,90 € | 6 bowls (42,43,44,46,47,48) |
| `supplement_bol` | Viande supplémentaire | 2,50 € | 6 bowls |
| `supplement` | Boursin | 0,90 € | 23, 24, 25, 120 |

**Retraits** (suppression physique de lignes qui étaient actives) :

| Produit | Groupe | Nom |
|---|---|---|
| 23, 24, 25, 36, 37, 39, 120 | `crudite` | **Cornichon** |
| 22 Cayenne | `supplement` | Viande supplémentaire |

**Changement de champ** : `22 Cayenne` / « Viande en plus » (2,50 €) `status` **10 → 5** — la ligne
est **réactivée**. Aucun changement de `price`, de `group_label` ni de `visible_on` sur les 520
lignes existantes.

> **Sur les cornichons signalés « ajoutés à tort »** : la mesure dit l'inverse.
> `SELECT COUNT(*) … WHERE name LIKE '%ornichon%' AND deleted_at IS NULL` → **h48 = 7, maintenant = 0**.
> Aucun cornichon n'a été ajouté dans la fenêtre ; les 7 existants ont été **retirés**. Soit l'ajout
> constaté par le propriétaire est antérieur au 2026-09-02 01h00, soit il a été corrigé depuis.

## 6. Étapes de profil (`h48_item_wizard_steps` vs `item_wizard_steps`)

**0 étape supprimée. 170 étapes créées. 16 étapes existantes modifiées.**

**Les 16 modifications** portent toutes sur les profils de catégorie publiés (34→39) et sont une
normalisation : `source_ref` passe du **nom** de l'attribut (« Viande 1 », « Sauce (1ère Gratuite) »)
à son **id** (« 1 », « 5 »), et `source_item_attribute_id` passe de `NULL` à l'id. Aucun changement de
`min_select`, `max_select`, `is_active`, `position` ni `visible_on`. Seule exception : l'étape 228
« Choisis ta formule » (cat. 5) dont `source_ref` passe de `''` à `menu_component`.

**Les 170 créations** sont les étapes des 30 profils produit du fan-out. Elles devraient être la copie
conforme des étapes de catégorie (`upsertPublishedItemClone()` recopie `is_active` tel quel), **mais
elles ont divergé produit par produit** :

| Cat. | Profil source (état des étapes) | Clones produit — écarts constatés |
|---|---|---|
| 1 | `pain` **inactive**, `viande` active, `viande_2` active | **22 Cayenne, 103 Suprême, 119 Sdw Classique : `pain` ACTIVE, `viande` et `viande_2` INACTIVES.** 36 Big Cayenne : `pain` inactive, viandes actives. 104 Méga, 105 Terminator : `pain` **et** viandes actives. |
| 2 | `pain` inactive, `viande` active | 23, 24, 120 : conformes. |
| 3 | `pain` inactive, viandes actives | 25 : `pain` inactive ; 37 : conforme. |
| 4 | `pain` inactive, `viande` active | 38, 39, 98→102 : conformes **mais** voir ci-dessous. |
| 5 | `garnitures` + `supplements` inactives, `menu` active | 26, 27, 97, 121 : conformes. |
| 6 | `pain` + `viande` + `garnitures` inactives | 41, 42→48 : conformes. |

Étapes actives dont la source est vide, mesurées produit par produit
(`COUNT(item_variations WHERE item_id = … AND item_attribute_id = source_item_attribute_id AND deleted_at IS NULL)`) :

| Profil | Produit | Étape | `min_select` | Choix caisse | Choix borne |
|---|---|---|---|---|---|
| 53 | Chicken Burger | Choisis ta viande | **1** | **0** | **0** |
| 55 | Cheese Burger | Choisis ta viande | **1** | **0** | **0** |
| 56 | Double Cheese | Choisis ta viande | **1** | **0** | **0** |
| 57 | Fish Burger | Choisis ta viande | **1** | **0** | **0** |
| 58 | Big Burger | Choisis ta viande | **1** | **0** | **0** |
| 59 | Grill Burger | Choisis ta viande | **1** | **0** | **0** |

Toutes les autres étapes actives ont au moins 2 choix.

**Étapes `addon`.** Il n'existe **aucune étape `addon` active** en dehors des 4 tacos (`menu`,
`menu_component`) et de Bol Frites / Bol Riz (`boisson`, `drink`, portées par les anciens profils 8 et
12, toujours publiés — les nouveaux profils 64 et 68 sont `is_published = 0` et ne gagnent donc pas).

| Situation | Produits |
|---|---|
| Possèdent un add-on `menu_component` **et** une étape `menu` active | 26 Tacos M, 27 Big Tacos, 97 Tacos L, 121 Tacos XL |
| Possèdent un add-on `menu_component` **mais 0 étape addon active** | **22, 23, 24, 25, 36, 37, 38, 39, 98, 99, 100, 101, 102, 103, 104, 105, 119, 120** (18 produits) |
| Possèdent un add-on `drink` **mais 0 étape addon active** | 42, 43, 44, 46, 47, 48 (6 bowls) |

Les 24 produits de ces deux dernières lignes n'avaient **aucun profil produit publié** il y a 48 h
(`prof_pub_h48 = 0`) : ils composaient par le repli, qui expose `addons` brut. L'add-on
« Menu (Frites + Boisson) » (item 1, `status=5`, `is_available=1`, `channels=NULL`) est intact ; c'est
**l'étape qui le présente** qui a disparu.

**Tacos** : `garnitures` et `supplements` sont inactives sur les 4 profils. Tacos M / L / XL portent
pourtant **10 extras `supplement` actifs** chacun, désormais inatteignables (Big Tacos : 1).

**Étape orpheline** : `taille` (profils 60→63) est `source_type=item_attribute` avec `source_ref=''`
et `source_item_attribute_id=NULL`. Elle est `is_active=0`, donc sans effet — mais elle est publiée.
