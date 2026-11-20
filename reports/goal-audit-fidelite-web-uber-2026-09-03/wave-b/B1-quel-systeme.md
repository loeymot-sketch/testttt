# B1 — Quel système le composeur consomme-t-il réellement ?

Enquête lecture seule. Dépôt : `pos/category-first-caisse-2026-06-23`.
Production : `ssh lecayenne`, `/var/www/lecayenne`, HEAD déployé `3c1be8156` (2026-09-04),
même branche que l'arbre local. Toutes les requêtes SQL sont des `SELECT`.

## Réponse courte

`wizard_pages` **n'est jamais lu à l'exécution**, ni par la borne ni par la caisse.
C'est une couche d'**écriture** côté administration : elle matérialise ses choix dans
`item_variations` / `item_extras` / `item_addons` et **relie** les étapes existantes
(`item_wizard_steps.wizard_page_id`). Les deux surfaces continuent de lire
`item_wizard_profiles` + `item_wizard_steps`, via `ComposerProfileProjection`.

## 1. La borne

Chemin réellement suivi :

- `app/Services/Kiosk/KioskMenuService.php:413` → `composerProfileProjection()->project(...)`,
  profils cherchés en `item_wizard_profiles` **au niveau produit uniquement**
  (`KioskMenuService.php:477-478` : `whereIn('item_id', $itemIds)->where('is_published', true)`).
- `app/Services/Composer/ComposerProfileProjection.php:36-55` : les étapes viennent de
  `$profile->steps` (`item_wizard_steps`). Aucune référence à `WizardPage` dans ce fichier.
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue:827` lit
  `resolvedItem.composer_profile`, et `:559-562` : si le profil publié donne des étapes,
  elles **priment** sur le parcours par gabarit (`tacos` / `sandwich` / …).

`grep -rn "wizard_page" resources/js/` → 0 occurrence. La borne ne connaît pas la notion de page.

## 2. La caisse

- `public/js/pos-wizard.js:650-655` lit `data.composer_profile` ; `:701-747`
  `buildStepsFromComposerProfile`. **0 occurrence** de `wizard_page` dans le fichier.
- Payload servi par `app/Http/Resources/NormalItemResource.php:137` + `:186-211`
  (`where('item_id', $this->id)->where('is_published', true)`).

**Même source que la borne** : `item_wizard_profiles` / `item_wizard_steps`. Mais la caisse
en fait un usage bien plus faible qu'il n'y paraît : l'écran effectivement rendu est
`renderSinglePage()` (`pos-wizard.js:3055`, appelé en `:5224` et `:5588`), et cette fonction
**ne lit jamais la variable `steps`** — elle reconstruit tout depuis
`lastItemData.itemAttributes` / `variations` / `extras`. Le résultat de `buildSteps()`
(`:5150`) ne sert plus qu'à la validation et à la navigation (`:2311-2365`).
⇒ Divergence réelle : la borne rend les étapes du profil, la caisse rend les attributs bruts.

## 3. Le commit responsable

`feat(catalogue): le Dashboard pilote enfin le parcours client, pages et prix compris`
— **Wed Sep 2 20:50:30 2026**, Kossay20. Deux SHA pour le même travail (deux branches) :

| SHA | Dans HEAD ? |
|---|---|
| `5f0f1d405635b386e1ed4b2806b0f5b25e7b3157` | **OUI** — `pos/category-first-caisse-2026-06-23`, `main`, `origin/main` |
| `b6b934609e2545938d793aa37a038a1f9b703426` | non — `livraison/wizard-pages-prod`, `origin/livraison/wizard-pages-2026-09-03` |

Migrations apportées : `database/migrations/2026_09_02_100000_create_wizard_pages_tables.php`
et `2026_09_02_110000_bootstrap_wizard_pages_library.php`.

Ce que le message déclare : *« À la publication, les choix sont matérialisés sur chaque
produit (item_variations / item_extras / item_addons, idempotent, jamais de suppression dure)
puis le profil est cloné : le contrat lu par la caisse, la borne et PricingService ne change
pas d'un octet »*. Il annonce aussi avoir corrigé le fait que **28 produits sur 30 n'avaient
aucun clone publié** — « la caisse et la borne tournaient sur l'ancienne heuristique ».

C'est là le vrai changement de comportement, vérifié en base de production :

```
SELECT DATE(created_at) j, COUNT(*) n, SUM(is_published) pub
  FROM item_wizard_profiles WHERE item_id IS NOT NULL GROUP BY 1;
2026-05-28 | 10 |  2
2026-06-24 |  1 |  0
2026-07-17 |  1 |  0
2026-09-03 | 30 | 28   ← créés à 01:17
```

Avant le 2026-09-03, `composerProfilePayload()` renvoyait `NULL` pour la quasi-totalité des
produits ⇒ la borne retombait sur le gabarit (une seule page viande). Depuis, le profil publié
existe ⇒ le parcours par étapes prend la main, `viande_2` comprise. **Le système n'a pas changé ;
c'est sa couverture qui est passée de 2 produits à 30.**

## 4. Y a-t-il un interrupteur ?

**Oui pour la caisse, non pour la borne.**

- `config/catalog_v15.php:99-105` : `pos_wizard_composer_aware.enabled`,
  alimenté par `env('FK_POS_WIZARD_COMPOSER_AWARE_ENABLED', false)`.
- Exposé au navigateur : `resources/views/admin-pos-v4.blade.php:109-110` et
  `resources/views/master.blade.php:221-222` ; lu en `public/js/pos-wizard.js:644-648`.
- **Valeur en production : `true`** — `/var/www/lecayenne/.env` ligne 113
  `FK_POS_WIZARD_COMPOSER_AWARE_ENABLED=true`, confirmé par
  `config('catalog_v15.pos_wizard_composer_aware')` → `["enabled"=>bool(true)]`.

Le passer à `false` renvoie la caisse à `buildSteps()` (heuristique historique). C'est le levier
de retour arrière le moins risqué **côté caisse**, sans toucher au code ni à la base.

La borne n'a **aucun drapeau** : `KioskWizardComponent.vue:561-562` donne systématiquement la
priorité au profil publié. Aucune ligne `wizard`/`composer` dans la table `settings`
(`SELECT * FROM settings WHERE key LIKE '%wizard%' OR key LIKE '%composer%'` → 0 ligne).
Le seul retour arrière borne consiste à dépublier les clones produit créés le 2026-09-03 —
écriture en base, hors de mon mandat, à décider par le propriétaire.

## 5. `viande_2` : qui gagne, la page ou l'attribut ?

**Ni l'une ni l'autre. Aucune des deux.**

État vérifié en production pour le Terminator (`items.id=105`, catégorie 1) :

| source | min | max | allow_repeat |
|---|---|---|---|
| `item_attributes` #1 « Viande 1 » | 1 | **3** | **1** |
| `wizard_pages` #2 `viande` | 1 | 1 | 0 |
| `item_wizard_steps` #311 (profil 46 publié) | 1 | 1 | 0 |

Chaîne d'arbitrage, lue ligne à ligne :

1. **La page ne transmet jamais son min/max.** `ComposerStepService::applyPage()`
   (`app/Services/Composer/ComposerStepService.php:77-101`) ne recopie que `source_type`,
   `source_ref`, `source_item_attribute_id`, `addon_role`, `step_key` et le libellé. Le
   commentaire `:72-76` l'écrit noir sur blanc : *« le libellé, le minimum/maximum et les
   canaux restent propres à la catégorie »*. Même liste dans
   `WizardPageMaterializer::bindSteps()` (`app/Services/Composer/WizardPageMaterializer.php:141-147`).
   ⇒ **La page perd toujours.**
2. **L'étape est ce qui est projeté** : `ComposerProfileProjection.php:45-47`
   (`min_select` / `max_select` / `allow_repeat` de `$step`). Elle est lue par la borne
   (`KioskWizardComponent.vue:918-919`) et par la caisse (`pos-wizard.js:737-738`) — mais
   **uniquement pour les écrans génériques**.
3. **L'écran viande, lui, ignore l'étape.**
   - Borne : `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue:160-163`
     → `maxViandes = selections._tailleMeta?.viandeCount || 1`, alimenté par la taille choisie,
     sinon `item.viande_count`, sinon le **nom du produit**
     (`KioskWizardComponent.vue:1013-1027`, `resources/js/helpers/kioskTacosSize.js:84`).
   - Caisse : `renderSinglePage()` appelle `detectViandeCountFromData()`
     (`pos-wizard.js:3247`, `:3579`, défini `:397-418`) → nom, puis description, puis
     **`itemAttributes[].max_select`** (`:412`), c'est-à-dire l'**attribut** (max=3 ici),
     jamais l'étape ni la page. L'attribut arrive au navigateur par
     `NormalItemResource.php:177-179` et `KioskMenuService.php:510-512`.

**Verdict : sur l'écran « QUELLE VIANDE ? », c'est l'attribut (caisse) ou le nom du produit
(borne) qui fixe le quota — le `max_select=1` de l'étape et de la page est mort-né.** D'où le
symptôme : la page viande laisse prendre plusieurs viandes, et `viande_2` s'ajoute quand même
puisque c'est une étape distincte pointant l'attribut #2.

`viande_2` n'est **pas** né avec `wizard_pages` : l'étape existe depuis le profil 28 du
2026-06-08 18:33 (`item_wizard_steps`, `step_key='viande_2'`, `source_ref='Viande 2'`). Elle
était simplement invisible tant qu'aucun profil produit n'était publié. Ses choix sont bien la
même liste, moins une entrée : page 2 = 8 choix, page 3 = 7 choix — le seul écart est
« Mixte (hachée + poulet) ».

Le rendu générique de `viande_2` est voulu par construction : `WizardPage::effectiveStepKey()`
(`app/Models/WizardPage.php:133-143`) ne donne la clé dédiée qu'à la page dont la clé **est**
le kind ; `viande_2` garde sa clé, absente des registres
(`KioskWizardComponent.vue:316-340`, `pos-wizard.js:657-666`) ⇒ écran générique.
