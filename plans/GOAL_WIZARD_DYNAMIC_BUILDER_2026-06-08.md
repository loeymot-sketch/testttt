# GOAL — Wizard Dynamique : Builder de pages décomposable (item / catégorie / box)

**Date:** 2026-06-08 · **Branche:** `heal/pre-cloud-exec-2026-06-05` @ `6591837fe` · **Statut:** PLAN-ONLY (attend `lance le GOAL` owner)
**Auteur:** Claude (ultra-architect-planify) · **Discovery:** Workflow `wbzay65k6` (8 agents, 237 tool-uses, anchors primary-source @ HEAD 6591837fe)
**Fondation:** audit `reports/test-e2e/wizard-dynamic-2026-06-08/ULTRA_AUDIT_VERDICT_2026-06-08.md` + GATE-G

---

## §0 — PRÉAMBULE (lire en premier — gouverne chaque tâche)

### 0.1 Working-tree / discipline
- **PLAN-ONLY.** Ce document décrit QUOI faire. Aucune exécution avant GO owner. À la fin → **STOP**, présente, attends `lance le GOAL`.
- Branche d'exécution future : nouvelle branche depuis `heal/pre-cloud-exec-2026-06-05`. No push sans owner (§10 CLAUDE.md).
- Pipeline par tâche = `ultra-audit-profond` (audit→test→visual→self-correct). NON re-décrit ici.

### 0.2 ⚖️ LE CONTRAT PORTEUR (la colonne vertébrale — toute tâche le respecte)
> **Le builder SE PRÉSENTE comme un CMS, mais chaque option PERSISTE sur un construct catalogue** (`ItemVariation` / `ItemExtra` / `ItemAddon`). Prix + photo + description vivent **sur le construct**, édités *à travers* le wizard — **JAMAIS un champ sur le step**.

Pourquoi (vérifié primary-source) :
- **PRIX = SSOT NF525.** Le prix est calculé 100% backend depuis le construct par id (`PricingService.php:159` `$dbVar->price`, `:189` `$dbExt->price`, `:226` `addonItem->price` ; payload = id+quantité seulement `:65-98`). Le prix est **PROHIBÉ sur le step** (`ComposerStepRequest.php:32` `'price'=>['prohibited']`, `ComposerProfileRequest.php:20,36`) et **REQUIS sur le construct** (`ItemVariationRequest:40` / `ItemExtraRequest:35` `required`). ⇒ éditer le prix d'une option = pur CRUD construct, **0 touche frozen**.
- **Photo + description = métadonnée catalogue NON-fiscale** → s'ajoutent au construct sans jamais toucher le prix SSOT.
- **« Page personnelle / libre »** = le builder crée un nouveau `extra_group`/`addon` à la volée et lie le step via `source_ref`. **PAS de nouveau `source_type`, PAS de résurrection du `'fixed'` legacy** (rejeté à `ComposerStepService.php:12,85`).

**Tripwire anti-dérive :** si une tâche propose un champ `price` sur un step wizard → STOP, c'est le signe qu'on a glissé en CMS-land illégal.

### 0.3 Décisions de cadrage (anchored, à confirmer en Wave 0)
- **« TYPE » = faux-ami → adopter « type == catégorie ».** `item_type` est un flag **diététique VEG=5 / NON_VEG=10** (`app/Enums/ItemType.php:6-7`), ZÉRO lien wizard. L'ownership wizard est `item_id` **XOR** `item_category_id` (DB CHECK, `migration ...000020:112-116` ; `ItemWizardProfile::ownerType()` → item|category|orphan `:62-73`). **Seulement 2 axes** (item, catégorie). Le mot « type » est retiré du spec ; un vrai 3e axe = changement de schéma frozen-adjacent **hors-V1** (gate si jamais demandé).
- **Per-ITEM builder gaté OFF** par `FEATURE_WIZARD_PER_ITEM_DEMO=false` (`EnsureProfileNotItemOwnedUnlessDemoEnabled`) — routes/service/precedence existent + testés ; flip = décision owner (change comportement, Wave 7).
- **Box/formule = PAS un modèle dédié** — c'est un `Item` portant des `ItemAddon` `role='menu_component'`/`drink`/`side`/`dessert` (vérifié : aucune migration/modèle box/combo/bundle). Réutilise le même modèle profile/step.

### 0.4 Critères de convergence (rejet si non tenu)
Raw label visible · layout cassé · console error · **toute touche frozen sans LOCK** · tout champ prix sur un step · test acceptance sans chemin de test · NF525 chain hash modifié · 2 cycles convergence à findings différents → loop. **Done = production-perfect, pas « presque ».**

---

## §1 — MAP PRINCIPAL (7 sous-systèmes, anchors vérifiés)

| # | Sous-système | Maturité | Anchor clé (vérifié) | Frozen ? |
|---|---|---|---|---|
| A | Pipeline image/desc/prix par option | PRIX ✅ / IMAGE+DESC ❌ | `ItemVariation.php:19-37,61-95` (price ok, no image/desc col) ; `ItemExtra.php:15,41-71` ; `config/menu_images.php` (name→file accessor) | non-frozen (additif) |
| B | Système BOX / formule / menu | partiel | Box = `Item`+`ItemAddon` role `menu_component` ; `KioskStepMenuComponent.vue:20-105` (3 cartes hardcodées, **frozen**) ; `config/kiosk.php:144` ratios | render **frozen**, pricing générique non-frozen |
| C | Mécanique builder (add/del/reorder/personnalise) | ✅ profile/step only | `ProductComposerEditorComponent.vue` (saveDraft→PUT) ; routes `api.php:766-792` ; **aucun endpoint composer ne crée de construct** (`api.php:734-749` séparé) | non-frozen |
| D | Data-model + lifecycle + versioning + capacité 10 pages | ✅ no cap | `ItemWizardProfile/Step/StepVersion` ; **AUCUN cap de steps** ; race version/snapshot per-step (`ComposerStepService.php:109-130` bump sans snapshot) | non-frozen |
| E | Axe ownership (item / type / catégorie) | 2 axes | `item_id` XOR `item_category_id` ; `resolveForItem:104-120` = **CATEGORY-WINS mort** (piège) | non-frozen |
| F | Render + 9 step components (kiosk + POS) | hybride | kiosk fallback générique `KioskStepGenericChoicesComponent` (id-keyed, addon ok) ; POS générique = **page BLANCHE** (`pos-wizard.js:1131-1152` pas de branche) | kiosk steps/ non-frozen ; pos-wizard.js **frozen** |
| G | Pricing / NF525 / order | ✅ SSOT | prix catalog-by-id `PricingService:134-234` ; GATE-G item_id-only `:566` ; snapshot immuable | PricingService **frozen** |

**Définitions canoniques (anti-dérive) :**
- **PAGE wizard** = un `ItemWizardStep` (label, `source_type`∈{item_attribute,extra_group,addon}, `source_ref`, min/max_select, allow_repeat, visible_on, addon_role).
- **OPTION** = un choix projeté = un construct catalogue (`ItemVariation`/`ItemExtra`/`ItemAddon`) lié par `source_ref` ; porte prix (+photo+desc après Wave 1).
- **BOX/formule** = un `Item` dont des pages addon (`role` menu_component/drink/side/dessert) composent un menu ; prix par composant = prix de l'Item lié (ou ratio menu_* frozen legacy).
- **PAGE PERSONNELLE/LIBRE** = page liée à un `extra_group`/`addon` **créé à la volée** par le builder.

## §2 — SÉPARÉ / HORS-PÉRIMÈTRE
- **POS `pos-wizard.js`** (frozen §7, « design parfait » owner) : 2e voie de render indépendante. Parité box POS = **wave frozen séparée, gate** (§G-4).
- **Mobile RN / Web standalone** : non câblés (mandate owner), hors scope.
- **Système legacy menu ratio** (`KioskStepMenuComponent` + `menuRoleAdjustedAddonPrice` + `config/kiosk.menu_pricing`) : **préservé intact** (gate §G-2), le nouveau box générique vit À CÔTÉ.

---

## §3 — DÉCOMPOSITION PAR WAVE (build order = dépendances)

> Chaque tâche : **anchor** (file:line vérifié) · **acceptance** (chemin de test réel ou `(test À CRÉER à …)`) · effort.

### §3.A — WAVE 1 : Fondation media construct (non-frozen, additif) — *critical path #1*
**Sub 1.1 — Schéma construct (description + image)**
- T-1.1.1 Migration additive : col nullable `description` (text) + `image_path` (string) sur `item_variations` + `item_extras`.
  • anchor: `database/migrations/2022_11_17_110621_create_item_variations_table.php:16-30` / `..._110650_create_item_extras_table.php:16-28` (confirment absence)
  • acceptance: `(test À CRÉER à tests/Feature/Migrations/ConstructMediaColumnsTest.php)` — colonnes présentes, nullable, rollback propre
- T-1.1.2 fillable + casts + `ItemVariationRequest`/`ItemExtraRequest` rules (`nullable|string`, image upload) + `ItemVariation/ItemExtraResource` émettent `description`+`image`.
  • anchor: `ItemVariation.php:19-37`, `ItemExtra.php:15`, `app/Http/Requests/Item{Variation,Extra}Request.php`, `ItemExtraResource.php:30`
  • acceptance: étendre `tests/js/productComposerEditor.spec.js:142,150` (ajoute `admin-variation-form-image`/`admin-extra-form-image`) + `(test À CRÉER tests/Feature/Catalog/ConstructMediaPersistenceTest.php)`
**Sub 1.2 — Résolution image : stored-first, config fallback**
- T-1.2.1 `getThumbAttribute()` préfère `image_path` stocké, retombe sur `config/menu_images.php` (préserve les 45 items legacy, 0 régression).
  • anchor: `ItemVariation.php:61-95`, `ItemExtra.php:41-71`, `config/menu_images.php:16-26`
  • acceptance: `(test À CRÉER tests/Feature/Catalog/ConstructThumbPrecedenceTest.php)` — stored>config>default ; `tests/e2e/wave-q5-pos-ui-polish.spec.js` non régressé
- T-1.1.3 **Addon** : pas de champ propre → le builder édite l'**Item lié** (`addonItem`) où prix/photo/desc vivent légalement (`ItemAddonResource.php:70`). Décision-contrat, pas de champ addon-local.

### §3.B — WAVE 2 : Enrichissement projection (non-frozen) — *critical path #2*
- T-2.1 `ComposerProfileProjection::choices()` émet `image` + `description` + `price` (read-only echo) pour les 3 source_types.
  • anchor: `ComposerProfileProjection.php:91-167` (émet aujourd'hui id/name/source_type/status/is_available seulement) ; lire `$variation->thumb`/`$extra->thumb`/`$addon->addonItem?->thumb` + `convert_price`
  • acceptance: étendre `tests/Feature/Composer/ComposerProfileProjectionVariationRuptureTest.php` (lock point) — payload contient image/description/price ; **prix = display-only, PricingService reste SSOT** (NF525-neutre)

### §3.C — WAVE 3 : Surface d'édition option dans le builder (non-frozen) — *critical path #3*
- T-3.1 Forms variation/extra Vue : champ image-upload (réutilise le composant Spatie du form Item) + champ description + test-ids.
  • anchor: `tests/js/productComposerEditor.spec.js:102,107` (le form Item a déjà `admin-item-form-image`) vs `:142,150` (construct = name+price only)
  • acceptance: `tests/js/productComposerEditor.spec.js` étendu (nouveaux test-ids) GREEN
- T-3.2 Panneau « éditer l'option » du builder = binding présentationnel vers les endpoints construct **existants** (`ItemVariation/ExtraController store/update`, `api.php:734-749`, `permission:items_edit`).
  • anchor: `app/Http/Controllers/Admin/ItemExtraController.php:35-48`, `ItemVariationController.php:45-58`, `ItemExtraService.php:58`
  • acceptance: `(test À CRÉER tests/js/composerOptionEditPanel.spec.js)` — édit option POST au bon endpoint construct, prix/photo/desc persistés

### §3.D — WAVE 4 : 10 pages + bindings turnkey + modèle de sauvegarde (non-frozen) — *deliverable « 10 pages avec images »*
**Sub 4.1 — Templates turnkey (fin du `source_ref=''`)**
- T-4.1.1 Apply-template pré-lie chaque step `item_attribute`/`addon` au construct réel de l'item représentatif (par nom d'attribut/role), au lieu de `''`.
  • anchor: `ComposerTemplateService.php:46` (`source_ref => ''` hardcodé), `:71-128` (7 templates) ; `ComposerProfileController::applyTemplateToCategory:142-145`
  • acceptance: étendre `tests/Feature/Composer/ComposerTemplateApplyTest.php:81-93` — steps appliqués ont `source_ref` non-vide résolvant des choix DISTINCTS
**Sub 4.2 — Galerie de ~10 pages-archétypes AVEC images**
- T-4.2.1 Bibliothèque de ~10 pages prêtes (taille, viande, sauce, garnitures, suppléments, boisson, accompagnement, dessert, sauce-2, formule/box), chacune avec image d'archétype, dans `ComposerTemplatePickerModal`.
  • anchor: `ComposerTemplateService::stepsFor` + `ComposerTemplatePickerModal.vue` (picker texte/icône, pas d'images aujourd'hui — gap C)
  • acceptance: `(test À CRÉER tests/js/composerPageGallery.spec.js)` — 10 archétypes listés avec image ; drop d'une page crée un step bindé
**Sub 4.3 — Modèle de sauvegarde (résout la race version/snapshot)**
- T-4.3.1 **Décision (Wave 0) + impl** : builder travaille sur DRAFT, seul **Publish** snapshot+sync (le plus propre, matche `publish()` existant) — OU ajoute `assertVersionMatches` + snapshot aux routes step.
  • anchor: `ComposerStepService.php:109-130` (bump version+sync SANS snapshot), `ComposerProfileService.php:155-167` (snapshot sur publish only), `assertVersionMatches:266-275` (409 sur bulk only)
  • acceptance: `tests/Feature/Composer/ComposerProfileVersionConflictTest.php` étendu — édits page-par-page sur profil publié ne dérivent plus la version sans snapshot/lock

### §3.E — WAVE 5 : Page personnelle / libre = construct à la volée (non-frozen orchestration) — *deliverable « ajouter une page personnelle »*
- T-5.1 Flow builder : (a) POST au contrôleur construct existant (`/admin/item/extra/{item}` ou `/addon/{item}`) pour matérialiser un nouveau `extra_group`/`addon` (prix/photo/desc y vivent, NF525-safe) ; (b) écrit un step `source_type`∈{extra_group,addon} + `source_ref` = nouveau groupe.
  • anchor: `routes/api.php:734-749` (construct CRUD séparé), `ComposerProfileService.php:44-62` (ne touche jamais les constructs)
  • acceptance: `(test À CRÉER tests/Feature/Composer/PersonalPageCreatesConstructTest.php)` — créer page perso → construct créé + step bindé + rendu avec ses options ; **0 nouveau source_type, 0 `'fixed'`**
- T-5.2 Page perso au niveau **catégorie** : le construct vit sur un item réel (les options vivent sur items, pas catégories) — documenter que `availableSourcesForCategory` dérive de `category->items()->first()` (`controller:173-182`, 422 si catégorie vide).
  • anchor: `ComposerProfileController.php:173-182,142-145`
  • acceptance: `tests/Feature/Composer/ComposerControllerCategoryRoutesTest.php` étendu

### §3.F — WAVE 6 : Builder BOX/formule + renderer escape-hatch (non-frozen + 1 gate) — *deliverable « système de box »* + **LE RISQUE #1**
**Sub 6.1 — Render box data-driven (l'escape hatch, le crux)**
- T-6.1.1 Les steps box du builder utilisent un **step_key non-registry + `addon_role` null** → `resolveExplicitStepType` retourne null → `hasGenericComposerChoices` (accepte 'addon') → `KioskStepGenericChoicesComponent` (id-keyed, NON-frozen). **Évite** le force-route vers `KioskStepMenuComponent` frozen.
  • anchor: `KioskWizardComponent.vue:340-345` (ADDON_ROLE_TO_TYPE), `:347-353,809-810` (addon_role check FIRST), `:801-805` (generic accepte addon), `KioskStepGenericChoicesComponent.vue:114-123` (id-keyed)
  • acceptance: `tests/js/kioskWizardGenericComposer.spec.js:83-108` (prouve sélection addon par id) étendu — un step box atteint le renderer générique ; **`tests/Feature/Sentinels/AddonRoleBindingSentinelTest.php` reste GREEN** (garde NF525 P0-Z4-01)
- T-6.1.2 Render image/desc/prix sur la page générique (dépend Wave 2) : `<img>` + label prix read-only dans `KioskStepGenericChoicesComponent` (`steps/` NON-frozen).
  • anchor: `KioskStepGenericChoicesComponent.vue:20` (texte seul, pas d'`<img>`)
  • acceptance: `tests/js/kioskWizardGenericComposer.spec.js` — choix rendus avec image+prix
**Sub 6.2 — Pricing + cart box par id (évite name-string)**
- T-6.2.1 Découverte addon box par `role='menu_component'` (déjà sur la row + projeté), PAS par name-match `/menu/i`.
  • anchor: `kioskPricing.js:51-52` (name-match, non-frozen helper), push consommateur dans `KioskWizardComponent.vue:1934` (frozen → piloter le push depuis la sélection id-keyed du renderer générique)
  • acceptance: `(test À CRÉER tests/js/boxRoleBasedDiscovery.spec.js)` — box nommée hors « menu » price correctement par id ; `tests/Unit/Services/Pricing/MenuRoleAdjustedAddonPriceTest.php` GREEN (ratios legacy intacts)

### §3.G — WAVE 7 : Parité per-item + type==catégorie + piège resolveForItem (non-frozen + flag)
- T-7.1 Flip/valider per-item builder (`FEATURE_WIZARD_PER_ITEM_DEMO`) : authoring identique item & catégorie (même apply-template, même step CRUD).
  • anchor: `tests/Feature/WizardPerItemDemoMiddlewareTest.php:31-79`
  • acceptance: ce test + parité authoring item/catégorie GREEN (gate §G-5 pour le flip prod)
- T-7.2 **PIÈGE `resolveForItem`** : `ComposerProfileService.php:104-120` est CATEGORY-WINS (opposé du live item-wins), code mort, lock par `ComposerProfileServiceCategoryTest.php:65-76`. **Toute tâche qui « branche » resolveForItem inverserait silencieusement la precedence.** Disposition : supprimer la méthode morte + son test, OU la réaligner item-wins.
  • acceptance: décision owner ; si suppression → `ComposerProfileServiceCategoryTest` retiré, 4 resolvers live intacts (`MenuProjectionComposerProfileTest` 8/8)

### §3.H — WAVE 8 : Durcissement + gates frozen (mix)
- T-8.1 **GATE-G** (frozen) : `PricingService::assertComposerStepConstraints` item_id-only → enforce category-inherited server-side. **Requis seulement si** enforcement composer côté serveur pour pages catégorie. Diff prêt : `reports/test-e2e/wizard-dynamic-2026-06-08/GATE-G-PRICINGSERVICE-INHERITANCE-LOCK-REQUEST.md`.
  • acceptance: flip `ComposerStepConstraintTest::test_GAP_..._pending_gate_g` → expectException ; chain `fiscal:verify-chain` inchangé
- T-8.2 **POS box render** (frozen `pos-wizard.js`) : page générique = BLANCHE (`:1131-1152` pas de branche). Renderer générique one-time + branche dispatcher. **Gate owner LOCK** (§G-4), séquencé APRÈS kiosk.
  • anchor: `pos-wizard.js:513-524,1131-1152` ; `tests/js/posWizardComposerAware.spec.js:58-59`
- T-8.3 Reprise audit prior : N+1 `snapshotForItem` per-item, i18n fr-only des clés composer (cf. `ULTRA_AUDIT_VERDICT_2026-06-08.md`).

---

## §A — ARMÉE D'AGENTS (fan-out matrix)

| Rôle | Type | Tools | Quand |
|---|---|---|---|
| Architect | Plan | RO | début chaque wave (cohérence contrat §0.2) |
| Implementer | general-purpose | Edit+Bash | 1 par sub-system, JAMAIS 2 en // sur même fichier |
| DBA | general-purpose | RO | Wave 1 (migration additive, FK, no-N+1) |
| Security | general-purpose | RO | Wave 5/6 (construct-on-the-fly authz, branch-scope) |
| QA Visual | general-purpose | Playwright | Wave 3/4/6/7 (builder + kiosk render) |
| RED Visual | general-purpose | RO | // QA Visual (dispute screenshots) |
| RED-team | general-purpose | RO | après chaque implementer commit |

**Matrice :** frontend builder (Wave 3/4/6) → Architect+UX+Implementer+RED+QA-Vis+RED-Vis · migration (Wave 1) → Architect+DBA+Implementer+RED · render kiosk (Wave 6/7) → +QA-Vis · frozen gate (Wave 8) → Architect+Security+RED, **owner LOCK avant tout edit**.

---

## §X — VAGUES DE CONVERGENCE (checkpoint + interrupt-resume)

**Ordre + parallélisme :** Wave 0 (séquentiel, gates) → 1 (séquentiel, schéma) → 2 → 3 → 4 → 5 → 6 (le crux) → 7 → 8 (gates frozen). Waves 1-2 séquentielles (schéma partagé) ; 3-4-5 peuvent chevaucher en lecture mais 1 implementer/fichier.

**Checkpoint (6 pts avant N→N+1) :** tasks PASS · frozen-diff = 0 ligne (sauf wave gate LOCKée) · NF525 chain inchangée si fiscal-adjacent · visual gate tiré (screenshots Read+analysés) si frontend · RED dispute clos · BRAIN §2/§3 + commits.

**Interrupt-resume :** commit WIP `wip(<wave>): …` + manifest `reports/.../INTERRUPT_<wave>.md` (dernier SHA vert, task en cours, next) + BRAIN §2.

**Convergence-failure (3 heals même cluster) :** STOP → spawn Plan agent → `STUCK_<wave>.md` → surface owner (accept-doc / pivot / défer / human). PAS d'auto-pick.

---

## §G — OWNER GATES (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G-0 | Confirmer **« type==catégorie »** + flip per-item policy + modèle save (draft vs granular) | Owner | décisions §0.3/§4.3 | Wave 0 / BRAIN §2 | PENDING |
| **G-0b** ⚠️ | **FORK SÉMANTIQUE BOX (gate la difficulté de Wave 6)** : « box » = (A) **bundle composants à prix plein** (`addonItem.price`, non-`menu_*` → pas de ratio `PricingService:795-797`) = **buildable maintenant, 0 frozen** ; OU (B) **formule à remise** (full=1.0/frites=0.6/boisson=0.4) = le mécanisme legacy **frozen** (`menuRoleAdjustedAddonPrice:794-799` + `config/kiosk.menu_pricing` + `KioskStepMenuComponent`) → **nécessite gate frozen G-3**. **L'owner heurtera ça jour 1** (« mon menu ne remise pas la boisson »). | Owner | choisir A ou B (ou A maintenant + B en gate différé) | Wave 0 (avant Wave 6) | PENDING |
| G-1 (=GATE-G) | `PricingService` enforce composer catégorie-hérité (frozen §7) | Owner | contresign LOCK | `GATE-G-PRICINGSERVICE-INHERITANCE-LOCK-REQUEST.md` | PENDING |
| G-2 | **Préserver** le menu-ratio legacy (`KioskStepMenuComponent` + `menuRoleAdjustedAddonPrice` + `config/kiosk.menu_pricing`) intact ; box générique vit à côté | Owner | confirm no-touch | Wave 6 | PENDING |
| G-3 | Si escape-hatch insuffisant → edit routing dans **frozen `KioskWizardComponent`** (`ADDON_ROLE_TO_TYPE`/`resolveExplicitStepType`) | Owner | LOCK | Wave 6 (à éviter via step_key non-registry) | CONTINGENT |
| G-4 | **POS box render** (frozen `pos-wizard.js`) — renderer générique one-time | Owner | LOCK + « design parfait » waiver | Wave 8 | PENDING |
| G-5 | Flip prod `FEATURE_WIZARD_PER_ITEM_DEMO` (change comportement frozen-adjacent) | Owner | go env-flag | Wave 7 | PENDING |
| G-6 | Per-option photo upload libre = colonne media construct (Wave 1) — confirmer stockage (Spatie vs path) | Owner | choix stockage | Wave 1 | PENDING |

**Protocole gate-pending :** exécuter les waves non-dépendantes en // ; lister bloquées vs running dans BRAIN §2. Waves 1-5 + 7 = exécutables **sans aucun gate frozen** ; seuls G-1/G-3/G-4 touchent du frozen.

---

## §R — RÉFÉRENCES
- Audit fondation : `reports/test-e2e/wizard-dynamic-2026-06-08/ULTRA_AUDIT_VERDICT_2026-06-08.md` + `GATE-G-…LOCK-REQUEST.md`
- Discovery anchors : Workflow `wbzay65k6` (full output `tool-results/b6nnmxxlv.txt`)
- Mémoire : `[[reference_composer_wizard_hinge_2026-06-07]]`, `[[project_wizard_dynamic_exec_2026-06-08]]`, `[[reference_e2e_harness_foodking_e2e_2026-06-07]]`
- Frozen list : CLAUDE.md §7 · NF525 : §8 · `[[reference_frozen_zones]]`
- Tests existants clés : `ComposerProfileProjectionVariationRuptureTest`, `ComposerTemplateApplyTest`, `AddonRoleBindingSentinelTest` (NF525 P0-Z4-01), `ComposerProfileVersionConflictTest`, `MenuRoleAdjustedAddonPriceTest`, `WizardPerItemDemoMiddlewareTest`, `productComposerEditor.spec.js`, `kioskWizardGenericComposer.spec.js`

## §F — RÈGLE FINALE (DONE)
Le GOAL est atteint quand : l'owner peut, **sans dev**, composer/ajouter/modifier/supprimer une page sur n'importe quel item OU catégorie OU box ; chaque option porte **prix (SSOT) + photo + description** persistés sur son construct ; une **page personnelle** crée son construct à la volée ; ~**10 pages-archétypes avec images** sont prêtes au drop ; le tout rendu correctement sur kiosk (POS via gate G-4) ; **0 touche frozen sans LOCK, 0 champ prix sur step, 0 violation NF525, tests technique+visuel verts, convergence 2 cycles identiques.** Sinon : heal ou block — production-perfect ou rien.

**⏸️ PLAN-ONLY — STOP. Attend `lance le GOAL` (et résolution G-0) pour exécuter Wave 0→8.**
