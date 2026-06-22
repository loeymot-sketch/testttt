# Ultra-Review GLOBALE — Catalog Tree FoodKing — 2026-05-03

| Champ | Valeur |
|---|---|
| Date | 2026-05-03 |
| Reviewer | Claude (modèle/version : claude-opus-4-7 1M context, Claude Code CLI, effort max) |
| Effort raisonnement | max (high) |
| MCP Graphiti utilisé | OUI — 7 queries (search_memory_facts ; `search_memory_nodes` indisponible côté serveur, fallback facts) |
| Verdict global | **REWORK + ESCALATE (gate Schema Migration)** |
| Score qualité (0-100) | **78/100** |
| Score fidélité design ↔ base (0-100) | **86/100** |
| Score vision plug-and-play (0-100) | **74/100** |

---

## 0. TL;DR (3 lignes max)

L'arbre central FoodKing (DB → services → projection → runtime POS/Kiosk) est **architecturalement sain** : `DispatchableAfterCommit` correctement câblé, `branch_id` isolé via `AdminController::authorize{Writable,}BranchScope`, atomicité stock garantie, design Claude v2 fidèle au schéma BDD à ≥90 % sur les fixtures et la structure du diff. **Mais 4 risques S2 non mitigés** dont un critique : `ComposerDiffService` lit `published_steps_snapshot` qui n'existe **pas** en BDD (vérifié `grep -r published_steps_snapshot app/ database/`) ⇒ **en production tout diff de profil publié renvoie systématiquement `is_clean=true`**, le modal "Diff publish" promis par l'iter 1 ④ deviendrait un faux positif silencieux. Verdict **REWORK** + **ESCALATE Schema Migration** (ajout colonne JSON `published_steps_snapshot` ou table d'historique) avant d'ouvrir β1 sur l'UI Diff.

---

## 1. Vision (V1-V5)

### V1 Plug-and-play : combien de clics réels pour ajouter "Pizza Margherita 8.50€ + wizard 3 étapes" ?

- **Comptage** : **8 clics minimaux** (sans erreur)
  1. Sélectionner catégorie cible (`CatalogStudioComponent.vue:43` `selectCategory`)
  2. Cliquer "Ajouter un produit" (`CatalogStudioComponent.vue:18-20`)
  3. Saisir nom + prix + description (clavier, pas un clic) ; cliquer "Save" (`CatalogStudioComponent.vue:97`)
  4. Cliquer le cog "Configurer wizard" sur la card produit (`CatalogStudioComponent.vue:131-135` `openComposerDrawer`)
  5. Drawer iframe charge ; cliquer "Choose template" (`ProductComposerEditorComponent.vue:88` `templateModalOpen`)
  6. Sélectionner template `tacos`/`assiette` (template applique 3-6 steps préremplis via `ComposerTemplateService::buildPayload` + `ComposerProfileService::createForItem`)
  7. (Optionnel) ajuster une step via `StepEditorComponent` — déjà rempli par template, donc 0 clic si on accepte les defaults
  8. Cliquer "Publier" → diff modal → confirmer → `ComposerProfileService::publish`
- **Verdict** : **74/100 plug-and-play.** Sous les 5 minutes/5 clics promis du brief §3, l'objectif est partiellement atteint. Le drawer iframe (cf. F1) ajoute 1 friction (chargement + auth duplicate). Le diff publish étant cassé (cf. B3), l'étape 8 est trompeuse en V1.
- **Évidence** : `CatalogStudioComponent.vue:75-179`, `ProductComposerEditorComponent.vue:82-138`, `ComposerProfileController::applyTemplate:86-104`, `ComposerTemplateService.php:38-130`.

### V2 Arbre central reflété sans rupture ?

- **Verdict** : OUI à 90 %, **avec une rupture documentée** dans la couche admin (iframe drawer = double Vue app).
- L'arbre §3 du brief est **fidèlement matérialisé** :
  - **Racine BDD** : 12 migrations cohérentes (cf. §2.1) — `items` + `item_categories` + `item_attributes` + `item_extras` + `item_addons` + `item_branch_availability` + `item_wizard_profiles` + `item_wizard_steps` (+ FK typée `source_item_attribute_id` Phase α) + `stock_levels` + `stock_movements`.
  - **Tronc services** : `ComposerStepService` + `ComposerProfileService` + `ComposerProfileProjection` + `ComposerDiffService` + `ComposerTemplateService` + `StockService` + `ChoiceAvailabilityResolver` + `AvailabilityService` + `MenuProjectionService`/`PosMenuProjection`.
  - **Branches** : `CatalogStudioComponent.vue` (admin) + composer/* + AvailabilityToggle + StockRuptureDashboard.
  - **Feuilles** : `runtime/pos-wizard.js` (mono) + `KioskWizardComponent.vue` (multi).
- Aucune couche manquante. **Une couche redondante** : `KioskPosWizardComponent.vue` (18 lignes, wrapper compatibility de `KioskWizardComponent.vue`, ligne 7 `<KioskWizardComponent v-bind="$attrs" />`) — héritage compatibility, non bloquant.
- **Évidence** : `audit-claude-ultra-review-2026-05-03/00-base-foodking/architecture-docs/CV1_CENTRAL_TREE_ARCHITECTURE_2026-05-03.md` §2 + §3 (table SSOT par entité, 7 paths sync validés).

### V3 POS-mono / Kiosk-multi : 1 source, 2 projections confirmé ?

- **Verdict** : **OUI ✅** — pas de duplication.
- Source unique : `item_wizard_profiles` + `item_wizard_steps` lus via `ComposerProfileProjection::project($profile, $item, $surface, $branchId)` (`ComposerProfileProjection.php:19-60`). Le surface (`'pos'` | `'kiosk'` | `'web'`) sélectionne via `stepVisibleOn()` (ligne 62-65) et adapte la projection des choix (`choices()` ligne 68-170).
- Consommation POS : `runtime/pos-wizard.js:431` `var profile = data.composer_profile;` puis `buildStepsFromComposerProfile(profile, data)` ligne 480-491 (filtre `isComposerStepVisibleOnPos` ligne 453-462), affiche en single-page DOM mutation.
- Consommation Kiosk : `KioskWizardComponent.vue` itère `composerActiveSteps` via `STEP_KEY_REGISTRY` explicite (cf. CV1 doc §2 ligne 72) avec components `KioskStep{Viande,Sauce,Garnitures,…}`.
- **Aucune duplication métier** : le code POS-monolithique a été refactoré en 3 batches (A/B/C) Phase α pour seam les helpers, pas pour dupliquer la logique. Le payload `composer_profile.steps` est **rigoureusement le même** que celui projeté pour Kiosk (différenciation = surface filter + rendering layer).
- **Évidence** : `ComposerProfileProjection.php:19-60`, `runtime/pos-wizard.js:431-543`, `KioskPosWizardComponent.vue:1-19`.

### V4 Stock central : combien de hops backend pour propagation ?

- **Verdict** : **4 hops, latence cible <5 s.** Aucun goulot.
- Trace : Admin clique "marquer indispo" sur une variation (ex. Saumon, attribute id=42) filiale 2 →
  1. `AvailabilityService::toggle(itemId, branchId=2, false, 'manual')` (`AvailabilityService.php:34-76`) wraps `DB::transaction { lockForUpdate + UPDATE item_branch_availability + dispatchEvent }`.
  2. `ItemAvailabilityChanged::forBranch(itemId, branchId=2, false, reason)` est dispatché. **DispatchableAfterCommit** confirmé (CV1 doc §1 ligne 58 + Graphiti fact "DispatchableAfterCommit dispatches Event Laravel after a database transaction").
  3. Outbox `PersistCatalogChangedToOutbox` persiste l'événement (CV1 doc §6 ligne 170).
  4. `DispatchDomainEventsJob` broadcaste sur `private-branch.{2}` channel Pusher (Graphiti facts confirmées).
  5. Côté runtime, `useCatalogChangeNotifier` (kiosk) ou `PosSyncService` (POS) reçoit l'event → invalidation cache local → re-fetch ou patch granulaire `kioskMenu/UPDATE_ITEM`.
- **Goulot potentiel** identifié (P3) : ~4 Pusher channels privés ouverts par utilisateur admin (`catalog-changed`, `item-availability-changed`, `composer-profile-changed`, `stock-level-changed`). Acceptable mais à surveiller en prod sur Soketi.
- **Asymétrie connue** (CV1 doc §5 ligne 150) : pour rupture de **variation** ou **extra**, le path passe par `StockLevelChanged` → listener → `CatalogChanged` (full re-fetch), pas via `ItemAvailabilityChanged` direct. Acceptable V1, refactor V2 (`V2-WIZARD-RT-REFACTOR-XL` listé roadmap).

### V5 Multi-langue + RTL : base supporte vraiment ?

- **Verdict** : **OUI partiellement** — 5 locales présentes, RTL admin OK design, POS/Kiosk **explicitement LTR** par décision documentée.
- Vérification i18n : `lang/{ar,bn,de,en,fr}/all.php` présents (5 répertoires `ls lang/`), chaque locale contient le namespace `studio` (`grep -c "'studio\|\"studio" lang/$l/all.php` retourne 1 par locale).
- **Profondeur non vérifiée** : ce comptage ne prouve pas que les 5 locales aient les mêmes clés à l'intérieur du namespace. Risque silencieux : AR pourrait manquer une clé → fallback EN (Laravel default). À sentinelliser en β2 (test parité de clés).
- RTL design (iter 3 artboard ⑮) : `<div dir="rtl" lang="ar">` + chevrons flippés + chips POS/Kiosk gardent `direction: ltr` ciblé. **`pos-wizard.js` reste LTR** (vanilla JS, pas de refactor RTL prévu V1).
- **Friction caissière arabophone** (D7) : admin AR-RTL mais POS LTR = mode mixte. Design accepte (handoff §2.3). Acceptable V1 mais à flagger pour V2.
- **Composants Vue non RTL-safe** non identifiables sans tests visuels manuels — `CatalogStudioComponent.vue` utilise classes Tailwind/SCSS sans logical properties (`margin-left/right` au lieu de `margin-inline-{start,end}`) sur ~30 endroits. Fragile en RTL strict.

---

## 2. Schéma & Data Model (S1-S5)

### S1 — Le schéma porte-t-il l'arbre §3 ? Trous ?

- **Verdict** : **OUI suffisant**, **pas de table `wizard_step_branch_overrides` requise**.
- Le brief suspecte un trou (overrides wizard par filiale). En réalité, le mécanisme existe déjà via `item_wizard_profiles.branch_id_scope` (FK nullable vers `branches`, `2026_04_27_143100_create_item_wizard_profiles_table.php:18`). Pour qu'une filiale ait un wizard différent : créer un *nouveau* profile avec `branch_id_scope = X`, et `ComposerProfileService::showForItem($item, $branchScope)` (ligne 24-36) sélectionne déterministe (filiale-spécifique en priorité, sinon global `whereNull('branch_id_scope')`).
- L'iter 1 ② "Branch Overrides matrice" du design vise en réalité l'**availability** (86 par filiale), pas l'override de structure wizard. Cible BDD = `item_branch_availability` (item_id, branch_id, is_available, unavailable_reason, max_daily_qty). **Mappage 1-1 design ↔ BDD ✅**.
- **Trous mineurs** :
  - `item_branch_availability.branch_id` est un **`unsignedBigInteger` sans `constrained()`** (`2026_04_15_230100_create_item_branch_availability_table.php:18`). Pas de FK formelle. Risque : orphan rows si une branche est supprimée. Comparé à `stock_levels.branch_id` qui est `foreignId('branch_id')->constrained('branches')->cascadeOnDelete()`. **Asymétrie, S1 mineur**.
  - `item_categories.wizard_template` enum `tacos|sandwich|burger|assiette|salade|omelette|snacking|simple` vs `item_wizard_profiles.template` enum `simple|sandwich|tacos|assiette|snacking|menu|custom`. Divergence (`burger`, `salade`, `omelette` côté catégorie ; `menu`, `custom` côté profile). **Asymétrie acceptée** (les enum n'ont pas vocation à être identiques : catégorie = défaut UX kiosk, profile = template wizard).
- **Évidence** : migrations dans `00-base-foodking/db-migrations/`.

### S2 — `source_ref` polymorphisme

- **Verdict** : **Partiellement durci**, gate humain `CV1-WC-T-WC-SOURCE-FK-01` option 2 cleared.
- Phase α a ajouté `source_item_attribute_id` (FK typée nullable, `2026_05_03_200500_*.php`) avec dual-write dans `ComposerStepService::resolveSourceItemAttributeId` (lignes 60-80). Backfill déterministe pour rows historiques (`source_type='item_attribute' AND ctype_digit(source_ref)`).
- Pour `source_type='extra_group'` : `source_ref` = `group_label` (string libre sur `item_extras.group_label`). **Pas de table canonique d'extra_groups**. Le matching dans `ComposerProfileProjection::choices` (ligne 109) compare `mb_strtolower((string) $extra->group_label) === $sourceRef`. **Risque silencieux** : renommer un `group_label` casse les steps qui le référencent, sans erreur. À sentinelliser ou bloquer le rename UI side.
- Pour `source_type='addon'` : `source_ref` = role token (drink/side/dessert/menu_component/upsell). Mais le backend privilégie déjà `step->addon_role` (colonne enum, `ComposerProfileProjection.php:130-133`), `source_ref` est ignoré ou redondant. **Polymorphisme résolu de facto** par addon_role enum.
- `source_type='fixed'` (présent dans migration enum) **rejeté** par `ComposerStepService::SOURCE_TYPES = ['item_attribute', 'extra_group', 'addon']` (ligne 12). Asymétrie schema/service. Test contractuel `ComposerStepServiceContractTest::test_service_rejects_unsupported_fixed_source_type` (ligne 18-28) verrouille le rejet — donc **enum DB est plus large que le service**, par design (fixed = future use, ou erreur historique inoffensive). À nettoyer en V2 (drop `'fixed'` de l'enum DB).
- **Évidence** : `2026_04_27_143110_create_item_wizard_steps_table.php:17`, `ComposerStepService.php:12`, `ComposerProfileProjection.php:81-167`, `audit-claude-ultra-review-2026-05-03/02-plans-reports/SOURCE_FK_TECHNICAL_FEASIBILITY_AUDIT_2026-05-03.md`.

### S3 — `addon_role` — rôles autorisés

- **Verdict** : enum déclaré strictement.
- Migration `2026_04_27_143110_create_item_wizard_steps_table.php:26` : `addon_role` enum `['drink', 'side', 'dessert', 'menu_component', 'upsell']` nullable.
- Cohérent avec ADR `ADR-COMPOSER-STOCK-2026-04-27.md` ligne 11 : "addon role enum approved as `drink|side|dessert|menu_component|upsell`".
- Cohérent avec `ItemAddon::ROLES` (référencé dans `ComposerProfileProjection.php:131`).
- Cohérent avec design `studio-data.jsx:56,80,88` : addon_role: 'side' / 'drink'.
- **Score** : 10/10 cohérence enum cross-couches.

### S4 — `composition_snapshot` immuable post-paiement (NF525)

- **Verdict** : Impossible à vérifier dans le périmètre du package (le code `OrderService` n'est pas inclus).
- Schema `order_items.composition_snapshot` n'est **pas dans les migrations du package** (le package contient les 12 migrations Catalog/Stock seulement). Présomption : la table existe ailleurs et `composition_snapshot` est figée à `payment_complete`.
- `StockService::requirementsForOrderItem` (ligne 243-295) **lit** `composition_snapshot` (`decodeSnapshotAddons` ligne 280) pour calculer le release stock — preuve que le snapshot existe et persiste post-création. Aucune écriture détectée vers ce champ depuis Composer/* services.
- **Aucune modification catalogue post-snapshot ne peut altérer rétroactivement** la composition d'un OrderItem existant : les services Composer écrivent uniquement sur `item_wizard_steps` (graphe parallèle), jamais sur `order_items.composition_snapshot`. ✅ Préservation NF525 par découplage architectural.
- **Recommandation** : sentinelle sentinel-fiscal (hors scope) qui prouve que le snapshot d'un OrderItem est byte-equal entre `payment_complete` et `Z_report` jour J+1.

### S5 — Performance : INSERT pour wizard 5 steps via `ComposerStepService::create`

- **Verdict** : **6 INSERT séquentiels en 1 transaction** = OK, pas N+1.
- Trace `ComposerProfileService::createForItem` (lignes 38-55) : 1 INSERT profile, puis foreach steps → `stepService->create($profile, $step, false)` qui exécute 1 INSERT par step. Donc 6 INSERT pour 1 profile + 5 steps.
- L'argument `emitSync=false` supprime le dispatch ComposerProfileChanged pendant la création (évite N events redondants).
- **Pas de cascade unintended** : les contraintes CHECK (`min_select <= max_select`, `position >= 0`) sont validées en write par `assertContract` (`ComposerStepService.php:82-107`) avant insertion DB.
- **Optimisation possible (pas blocante)** : remplacer le foreach par un `bulk insert` via `$profile->steps()->createMany($payloads)`. Gain : 1 INSERT batched. Recommandation V2.

---

## 3. Backend Services (B1-B10)

### B1 — Pipeline complet `Service.update() → Pusher`

- **Trace** : `ComposerProfileService::update($profile, $payload)` (lignes 57-80) →
  1. `DB::transaction(fn(): ItemWizardProfile => …)` ← début tx.
  2. `$profile->update([...])` + `$profile->steps()->delete()` + foreach `stepService->create($profile, $step, false)`.
  3. `$fresh = $profile->fresh('steps')`.
  4. Si `$fresh->is_published` : `ComposerProfileChanged::dispatch(...$payload)` ligne 75 — **dispatch INSIDE la closure**.
  5. Closure return → tx commit.
  6. `DispatchableAfterCommit` trait (verified `app/Events/ComposerProfileChanged.php:12`) défère réellement le dispatch jusqu'au commit confirmé via `DB::afterCommit()`.
  7. Hors tx : event listener `PersistCatalogChangedToOutbox` insère row outbox.
  8. Job `DispatchDomainEventsJob` (Graphiti fact) broadcast sur `private-branch.{branchId}` Pusher.
  9. Frontend Pusher echo → `useCatalogChangeNotifier.js:421-424` (CV1 doc §2 ligne 72) → toast + `wizard:invalidate-step`.
- **Hops** : 4 (PHP service → Outbox → Worker → Pusher → Echo client). **Latence target** : <500 ms en optimal, jusqu'à 5 s en mode polling fallback.

### B2 — `ComposerProfileChanged` est-il `DispatchableAfterCommit` ?

- **Verdict** : ✅ **OK, conforme I4**.
- Vérifié direct sur le repo : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Events/ComposerProfileChanged.php:12` :
```php
class ComposerProfileChanged
{
    use DispatchableAfterCommit;
    use InteractsWithSockets;
    use SerializesModels;
```
- Aucune violation I4 introduite.

### B3 — `ComposerDiffService::projectPublishedProfile()` risque diff faux silencieux

- **Verdict** : ❌ **S2 critique CONFIRMÉ. Mitigation requise (gate Schema Migration).**
- Le service lit `$profile->getAttribute('published_steps_snapshot')` (`ComposerDiffService.php:132,151`).
- **Cette colonne n'existe pas en BDD** :
  - Vérification 1 : `grep -r published_steps_snapshot /testttt/app/ /testttt/database/migrations/` → matchs uniquement dans `ComposerDiffService.php` (lignes 132, 151) et le test (qui injecte via `setAttribute()` en mémoire seule).
  - Vérification 2 : `ItemWizardProfile.php` `$fillable` (lignes 12-19) et `$casts` (21-28) ne contiennent **pas** `published_steps_snapshot`.
  - Vérification 3 : aucune migration n'ajoute cette colonne (12 migrations énumérées).
- **Conséquence en production** : pour tout profile `is_published=true` sans snapshot, le service appelle `projectPublishedProfile()` (ligne 154) qui :
  1. Crée un Item synthétique avec `setRawAttributes(['id'])` et **relations vides** (`variations`, `extras`, `addons` = `collect()`).
  2. Appelle `projection->project($profile, $synthetic_item, $surface, $branch_id_scope)`.
  3. La projection itère `$profile->steps` (la collection actuellement chargée) pour les steps.
  4. **Donc `publishedByKey === draftByKey`** (même collection).
  5. `changedFields()` retourne `[]` pour chaque step.
  6. **`is_clean = true` toujours** → admin voit "no changes" alors qu'il vient de modifier 5 steps.
- **Pourquoi les tests passent** : `ComposerDiffServiceTest::attachPublishedSnapshot` (ligne 199-202) injecte `setAttribute('published_steps_snapshot', $rows)` en mémoire — ce path n'est **jamais exercé en prod**.
- **Mitigation** :
  - Option A : ajouter colonne `published_steps_snapshot JSON NULL` à `item_wizard_profiles` + persister via `ItemWizardProfile::publish()` (capture `$this->steps->map(...)->toArray()`). **Schema migration → ESCALATE**.
  - Option B : créer table `item_wizard_step_versions(profile_id, version, snapshot_json)` insert-only à chaque publish. **Schema migration → ESCALATE**.
- **Évidence** : `app/Services/Composer/ComposerDiffService.php:130-184`, `app/Models/ItemWizardProfile.php:12-19`, absence de migration `published_steps_snapshot`, test `ComposerDiffServiceTest.php:59-80,199-202`.

### B4 — `comparable('position'|'min_select'|'max_select')` cast `(int)` → null = 0

- **Verdict** : ⚠️ **S1 mineur**, masquage de diff acceptable en pratique.
- `ComposerDiffService::comparable` ligne 261-267 : pour les champs `min_select`, `max_select`, `position`, `(int) null === 0`. Donc un step avec `min_select=null` apparaît identique à `min_select=0`.
- **En pratique** : `ComposerStepService::normalize` (ligne 49) cast systématiquement `(int) ($payload['min_select'] ?? 0)` avant insert. Migration met `default(0)` (ligne 19 du migration). **Donc null en colonne ne survient jamais** ⇒ le masquage est théorique seulement.
- Recommandation : aucun fix requis. Documenter dans le code.

### B5 — `COMPARED_FIELDS` whitelist 12 champs — manque-t-il quelque chose ?

- **Verdict** : ✅ **Complète pour le périmètre data.**
- Whitelist : `label, source_type, source_ref, source_item_attribute_id, min_select, max_select, allow_repeat, visible_on, stockable_choices, position, is_active, addon_role`.
- Migration colonnes : `profile_id, step_key, label, source_type, source_ref, source_item_attribute_id, min_select, max_select, allow_repeat, visible_on, stockable_choices, position, is_active, addon_role, timestamps`.
- **Champs ignorés** : `id`, `profile_id`, `created_at`, `updated_at`, `step_key`. Justifié :
  - `id`, `profile_id` : stables, ne changent jamais.
  - `created_at`/`updated_at` : timestamps, hors diff métier.
  - `step_key` : sert de **clé de jointure** (added/removed via `array_key_exists`). Renommer un step_key apparaît comme `removed[old_key] + added[new_key]` — comportement correct.

### B6 — `ItemPhotoController::store(Item $item)` — vérifier I3 branch_id

- **Verdict** : ✅ **I3 NON VIOLÉ — par construction.**
- **Découverte importante** : la table `items` n'a **AUCUNE colonne `branch_id`** (`2022_11_17_110514_create_items_table.php:19-39`). Items, attributes, extras, addons sont **globaux** dans le modèle FoodKing actuel ("mono-marque, multi-succursales" — `SAAS_VISION.md:7-13`).
- Donc la question "un admin filiale 2 peut-il polluer un item filiale 3 ?" est **vacuous** : il n'existe pas d'item-de-filiale-3, il existe un item GLOBAL et une availability filiale 3 (`item_branch_availability`). Le concept de branch isolation s'applique aux DONNÉES de filiale (orders, availability, stock), pas au catalogue produit.
- `ItemPhotoController` (`backend-controllers/ItemPhotoController.php:9-30`) :
  - Constructor : `$this->middleware(['permission:items_edit'])->only('store')` ← gate Spatie permission, pas branch.
  - `parent::__construct()` invoque `AdminController::__construct()` qui est **vide** (vérifié `/testttt/app/Http/Controllers/Admin/AdminController.php` lignes 9-13). **Aucun middleware branch global appliqué automatiquement**.
- **Question résiduelle** (hors I3 strict) : **doit-on autoriser un Branch Manager à éditer une photo d'item global ?** Cela impacte toutes les filiales. Par exemple, le Branch Manager filiale 2 pourrait remplacer la photo "Tacos M" globale sans demander aux autres filiales. C'est un **choix produit** plus qu'une violation invariant.
- **Recommandation** : restreindre `items_edit` aux rôles `Admin` / `Tenant Admin` (ou `Brand Manager` à créer), pas au `Branch Manager`. Hors scope de cet audit, à flagger product team.
- **Verdict B6 = OK pour I3**. Question produit ouverte.

### B7 — `ItemPhotoController` `clearMediaCollection` puis `addMediaFromRequest` non-atomique

- **Verdict** : ⚠️ **S2 medium — RISQUE CONFIRMÉ.**
- `ItemPhotoController.php:20-21` :
```php
$item->clearMediaCollection('item');
$media = $item->addMediaFromRequest('photo')->toMediaCollection('item');
```
- **Pas de DB::transaction**. Spatie Media Library opère sur DB (rows `media` table) + filesystem (uploads disk). Si `addMediaFromRequest` échoue (disk full, upload corrompu, permission denied) **après** `clearMediaCollection`, l'item se retrouve **sans aucune image** ⇒ regression visuelle visible immédiatement en POS/Kiosk (fallback `images/item/thumb.png` per `Item::getThumbAttribute` ligne 88-104).
- **Mitigation** :
  - Option A (recommandée) : inverser l'ordre — `addMediaFromRequest` d'abord (vers une collection temp), puis sur succès `clearMediaCollection('item')` + déplacer la nouvelle media vers `'item'`.
  - Option B : wrapper dans `DB::transaction { … }` ne suffit pas (filesystem ops hors tx). Utiliser un commit-after-pattern Spatie (collection-level swap).
  - Option C : try/catch + restore : capturer la media originale, sur échec re-attacher.

### B8 — `Item::registerMediaConversions` définit thumb/cover/preview ?

- **Verdict** : ✅ **OK toutes les 3 conversions définies.**
- `app/Models/Item.php:124-129` :
```php
$this->addMediaConversion('thumb')->crop('crop-center', 168, 180)->keepOriginalImageFormat()->sharpen(10);
$this->addMediaConversion('cover')->crop('crop-center', 390, 270)->keepOriginalImageFormat()->sharpen(10);
$this->addMediaConversion('preview')->width(600)->keepOriginalImageFormat()->sharpen(10);
```
- Match exact avec `ItemPhotoController` réponse ligne 26-28 : `thumb_url`, `cover_url`, `preview_url`. Aucun string vide silencieux. ✅

### B9 — `StockService` atomicité

- **Verdict** : ✅ **Atomique via `lockForUpdate` + tx + idempotency_key.**
- `StockService::mutateForOrder` (ligne 41-52) wraps tout dans `DB::transaction`. Inside :
  - `StockLevel::query()->where(...)->lockForUpdate()->first()` (ligne 86-91) ← row lock pessimiste.
  - Pré-check `$level->on_hand >= $qty` avant décrément (ligne 111-113), throw `StockUnavailableException` si insuffisant.
  - `$level->forceFill(['on_hand' => $beforeOnHand + $delta])->save()` (ligne 117).
  - INSERT `stock_movements` avec `idempotency_key` unique (ligne 119-128). Migration garantit uniqueness (`stock_movements_idempotency_unique`).
  - Pre-check idempotency via `where('idempotency_key', $movementKey)->exists()` (ligne 102-104) ← exit early si déjà décrémenté.
- **Concurrence safe** : 2 orders concurrents sur même stockable → second attend lock → re-évalue → soit décrémente, soit échoue, soit est un duplicate idempotent.
- **Sentinel** : `WizardOptionStockSyncTest 4/4` mentionné CV1 doc §4.

### B10 — `ChoiceAvailabilityResolver` ordre stock + IBA

- **Verdict** : ✅ **Ordre catalog → branch → stock défini explicitement.**
- `ChoiceAvailabilityResolver::availabilityForAddonItem` (lignes 280-310) chaîne :
  1. `addonItem` exists → sinon `addon_target_missing`.
  2. `addonItem.status === ACTIVE` → sinon `catalog_inactive`.
  3. `addonItem.is_available !== null && !is_available` → `catalog_unavailable`.
  4. `addonItem.isVisibleOn($surface)` → sinon `surface_hidden`.
  5. `branchAvailability && !is_available` → `unavailable_reason ?: 'branch_unavailable'`.
  6. Stock level check (`availabilityFromLevel`) → on_hand > 0 ? available : `stock_rupture`.
- Pour variations/extras (lignes 60-75) : seulement check stock level, pas IBA (car IBA est `item_id`-keyed, pas `variation_id`/`extra_id`). Asymétrie documentée CV1 doc §5.
- **Réponse à la question brief** : `stock=0 et branch_avail=true` → choice **indisponible** (stock_rupture). Stock prime sur branch_avail dans le path `availabilityFromLevel`.

---

## 4. Frontend Vue (F1-F7)

### F1 — `<iframe>` pour drawer composer : pourquoi ? Anti-pattern ?

- **Verdict** : ⚠️ **S1 — Pragmatique mais anti-pattern documenté.**
- `CatalogStudioComponent.vue:174-176` :
```html
<iframe v-if="composerDrawerUrl" class="catalog-studio__composer-frame" :src="composerDrawerUrl" />
```
- **Justification non écrite** mais déductible : éviter de re-monter `ProductComposerEditorComponent.vue` (638 lignes, 3 colonnes, child components, son propre Vuex `composer` module) à l'intérieur du `CatalogStudio` parent. L'iframe charge la route `admin.items.composer` qui mount le composer dans son propre contexte Vue.
- **Risques confirmés** :
  - **Double Vue app** : parent + iframe ont chacun leur Vuex/Pinia. Synchronisation = postMessage manuel ou cookie + reload.
  - **Double Pusher** : 2 souscriptions au même channel `composer-profile-changed` → 2 toasts si event arrive.
  - **Auth dupliquée** : Sanctum cookie partagé OK, mais le boot Vue + le `recursiveRouter` + permissions check tournent 2 fois (latence init drawer ~600 ms typiquement).
  - **Focus trap incomplet** : Tab depuis le parent peut entrer dans l'iframe mais ne piège pas (UA-dépendant). Cmd+W ferme l'onglet entier (ce qui détruit le drawer + le parent).
  - **ESC** : `@click.self="closeComposerDrawer"` (ligne 153) ferme via overlay click ; Tab interne ne ferme pas.
- **Mitigation** : refactor V2 pour mount Vue direct via `<component :is="ProductComposerEditorComponent">` avec props `:item-id="..."`. Cycle β6 ou roadmap V2.
- **Verdict** : Acceptable V1 (pragmatique pour livrer), à flagger V2.

### F2 — `createProduct()` cohérence avec `ItemListComponent::createProduct` ?

- **Verdict** : Divergence partielle non-bloquante.
- `CatalogStudioComponent.vue` createProduct (champs : name, price, description, image, order, tax_id nullable). Champ minimal pour quick-create.
- `ItemListComponent.vue` createProduct (legacy, hors lecture détaillée — mais le brief dit "encore utilisée pour edit complet") : champs étendus (item_type, channels, allergen_flags, etc.).
- **Risque maintenance** : 2 chemins d'écriture sur `items` table. Si `ItemListComponent::createProduct` valide un champ que CatalogStudio ne fournit pas, l'API rejette OU le default DB s'applique (ex. `is_featured` default 0, `item_type` default VEG).
- **Mitigation** : converger sur un seul controller endpoint backend (`POST /api/admin/item`) qui accepte un payload **superset** (les champs absents prennent les defaults). Apparemment c'est déjà le cas (ItemController utilisé par les deux). **Pas de violation, juste UX divergente**.
- **Recommandation** : sentinelle `apiPostItemContractTest` qui prouve que les 2 payloads (quick + full) produisent un Item valide en BDD.

### F3 — Filtre `searchTerm.toLowerCase()` Arabic

- **Verdict** : ✅ **Pas de risque réel.**
- `String.prototype.toLowerCase()` est Unicode-aware (ECMAScript spec). Pour l'arabe : la plupart des lettres arabes n'ont pas de notion de case. `'تاكوس'.toLowerCase() === 'تاكوس'`.
- Le risque réel est plutôt :
  - **Diacritiques / formes contextuelles** : "تاكوس" peut être stocké avec ZWNJ ou diacritiques différents qu'à la saisie. Solution : normaliser via `.normalize('NFC')` avant comparaison. Non fait dans `CatalogStudioComponent.vue`.
  - **Direction RTL** : la saisie en RTL n'affecte pas la comparaison string mais l'UX visuel.
- **Recommandation** : ajouter `.normalize('NFC').toLowerCase()` dans `searchTerm` getter. Mineur. Phase β2.

### F4 — Drawer composer iframe focus trap

- **Verdict** : ⚠️ Non vérifiable sans test manuel browser.
- Aucun `<focus-trap>` ou `tabindex` management détecté dans `CatalogStudioComponent.vue`.
- **Comportement attendu** :
  - Tab depuis le parent passe naturellement à l'iframe (intra-domain).
  - Cmd+W ferme la tab navigateur entière (destructif). Pas géré par Vue.
  - ESC : aucun listener attaché. L'utilisateur doit cliquer overlay.
- **Recommandation β2** : ajouter `useFocusTrap` composable + `keydown.esc` handler.

### F5 — i18n studio namespace 5 locales

- **Verdict** : ⚠️ **Présence vérifiée, parité de clés non vérifiée.**
- `grep -c "'studio\|\"studio" lang/$l/all.php` retourne `1` pour ar, bn, de, en, fr → namespace existe.
- **Profondeur** non vérifiable depuis le package (les fichiers complets ne sont pas dans `00-base-foodking/i18n/` mais dans le repo principal `/testttt/lang/`). Risque silencieux : AR pourrait avoir 50 clés vs FR 70 clés.
- **Recommandation** : sentinelle `i18nKeyParityTest` qui charge les 5 locales et compare `array_keys` du namespace `studio`. Test simple Vitest, β2.

### F6 — `AvailabilityToggleComponent` endpoint

- **Verdict** : ✅ **Optimistic vraisemblable**, endpoint conforme CV1.
- Per CV1 doc §2 ligne 71 : `POST /api/admin/menu/availability/toggle` — `AvailabilityService::toggle/setMaxDailyQty` (T-DEEP-AVAIL-API-01).
- Pattern Vue typique : émet `availability-changed` callback (ligne 114 CatalogStudio), parent updates local state. Optimistic update probable, mais le composant lui-même n'est pas dans le périmètre lu (existence seulement listée).

### F7 — `StockRuptureDashboardComponent` queries

- **Verdict** : Skeleton conservé par prudence, non router-bound V1.
- Per CV1 doc §7 ligne 190 : "🟡 DEAD SUSPECTED (0 match routeur mais API M2 2.1 backend complet)". KEEP par prudence — le widget V1 (StockLowAlertsWidget M2 V2 Lot C) attend cette page. Routeur à câbler en cycle V2.
- **Implication** : pour V1 Catalog Studio, le bouton "stock_link" du toolbar (`CatalogStudioComponent.vue:78-81`) pointe vers `admin.items.list?focus=availability`, **pas** vers `StockRuptureDashboardComponent`. Le design design-canvas.jsx S5 promet ce dashboard ; en code le wiring reste à faire (β6).

---

## 5. Runtime POS/Kiosk (R1-R4)

### R1 — `pos-wizard.js` wiring composer_profile

- **Verdict** : Via `data` payload côté JS (probablement injecté par un service Vue ou window).
- `runtime/pos-wizard.js:431` : `var profile = data.composer_profile;`.
- Le `data` est l'argument passé à la fonction qui transforme la modal item en wizard. Provient typiquement de `ItemComponent.vue` qui fetch `GET /api/admin/item/{id}?surface=pos` et passe le response (incluant `composer_profile`) à pos-wizard.js via DOM attribute ou window event. **Hors scope du package** (le code de l'appelant Vue n'est pas inclus).
- **Verdict pratique** : le wiring fonctionne (Playwright critical-flow PASS 12.2s prouve l'ouverture du composer drawer). Architecture seam établie.

### R2 — `KioskPosWizardComponent` réutilise structure `composer_profile` ?

- **Verdict** : ✅ **OUI — KioskPosWizardComponent est un wrapper direct.**
- `KioskPosWizardComponent.vue:1-19` est un wrapper de 18 lignes : `<KioskWizardComponent v-bind="$attrs" />`. Compatibility shim pour entrées staff/POS-kiosk.
- Le **vrai composant** est `KioskWizardComponent.vue` (non lu in extenso, mais lié dans le package via `kiosk-steps/KioskStepViandeComponent.vue`) qui consomme `composer_profile.steps` projeté par `ComposerProfileProjection::project` avec `surface='kiosk'`.
- **Aucune duplication structurelle** : POS et Kiosk lisent le **même payload** depuis `ComposerProfileProjection`, juste avec un filtre `visible_on` différent (POS utilise `'pos'` ou empty = all surfaces ; Kiosk utilise `'kiosk'`).

### R3 — `useCatalogChangeNotifier` re-fetch endpoint

- **Verdict** : `/api/frontend/menu` (kiosk) — délai ~5 s typique en push, jusqu'à 30 s en polling fallback.
- Per CV1 doc §2 ligne 72 : Kiosk endpoint `GET /api/frontend/menu` (cached `kiosk.menu.branch.{id}`), `KioskMenuService::build`.
- Trigger : `useCatalogChangeNotifier.js` écoute `CatalogChanged` Pusher event → toast + prune local cart + `wizard:invalidate-step` (CV1 doc §2 ligne 72 + §4 path D ligne 137).

### R4 — Runtime POS dispo temps réel

- **Verdict** : ✅ Pusher dédié `ItemAvailabilityChanged` + fallback polling flag-gated.
- Path : `StockService::syncItemAvailabilityForStockLevel` détecte rupture (on_hand <= 0) → `ItemAvailabilityChanged::forBranch(itemId, branchId, false, 'stock_rupture')` (ligne 202 StockService) → DispatchableAfterCommit → outbox → branch channel → POS écoute via `PosSyncService` ou Echo direct.
- Polling fallback : flag `pos_fallback_polling.enabled` OFF par défaut (CV1 doc §6 ligne 178). Active-able en prod après staging.

---

## 6. Design Claude v2 (D1-D9)

### D1 — `studio-data.jsx` fixtures fidèles au schéma ?

- **Verdict** : ✅ **10/10 fidélité.**
- TACOS_STEPS (lignes 42-91 de `studio-data.jsx`) : 6 steps avec **TOUS les champs backend en snake_case** :
  - `id`, `profile_id`, `step_key`, `label`, `source_type`, `source_ref`, `source_item_attribute_id` (incluant la nouvelle FK Phase α !), `min_select`, `max_select`, `allow_repeat`, `visible_on`, `stockable_choices`, `position`, `is_active`, `addon_role`.
- Le champ `source_item_attribute_id: 11/12/13` pour les steps `item_attribute` et `null` pour les steps `extra_group`/`addon` **mirorise exactement** le contrat de dual-write `ComposerStepService::resolveSourceItemAttributeId`.
- Le champ supplémentaire `options: [...]` dans les fixtures est design-only (preview values pour artboard), **pas remontant en BDD** — Claude Design l'a clairement séparé.
- Aucune confusion camelCase / snake_case détectée.

### D2 — `tokens.css` additions seules ?

- **Verdict** : ⚠️ **Partiellement.** Le fichier de design contient des **mirrors `--cv1-*` et `--fk-*`** au début (lignes 10-29 et 32-40), mais le **bloc `--studio-*`** (lignes 43-104) est **strictement additif**.
- Vérification : `grep -n "^  --cv1\|^  --fk" tokens.css` retourne 26 lignes. Si on appliquait littéralement la consigne brief D2 (`grep -c "^--cv1\|^--brand\|^--ks"`), résultat ≠ 0.
- **Mais** : le commentaire ligne 1-6 précise "Extends FoodKing CV1 (resources/css/foundations/cv1-tokens.css)" et "Nothing here redefines an existing token — these are *additions* scoped to the new Studio surface". Les mirrors sont des **valeurs idempotentes** pour permettre au canvas de design (HTML preview standalone) de fonctionner sans charger `cv1-tokens.css` réel.
- **Sentinel α3** (`tests/js/studioTokensAdditions.spec.js`) vérifie spécifiquement le `cv1-tokens.css` **production** ne soit pas redéfini. Le sentinel ne lit pas le `tokens.css` du package design (qui reste preview-only).
- **Verdict pratique** : à l'intégration β1, prendre **uniquement les déclarations `--studio-*`** et les ajouter à `cv1-tokens.css` ou un fichier `studio-tokens.css` dédié. Documenter cette règle dans β0.

### D3 — Iter 1 ④ Diff Modal compatibilité `ComposerDiffService::diff()` payload

- **Verdict** : ✅ **10/10 — Compatible.**
- iter1.jsx ligne 415 : `diff = compare(profile_published_v(n).steps, draft_v(n+1).steps, key = step_key)` — exactement le contrat backend.
- iter1.jsx ligne 429-444 : exemple concret "sauce max 1 → 2" affiche `step_key: "sauce"` + before + after + changed_fields → **structure identique** à `ComposerDiffService::diff()` retour `{added: [], removed: [], modified: [{step_key, before, after, changed_fields}]}`.
- iter1.jsx ligne 474-476 : 3 légendes "Ajout / Suppression / Modification" mappant 1-1 aux 3 sections du payload.
- **Mais** : **rappel B3 risque critique** — le backend renverra toujours `is_clean=true` en prod. Le modal s'affichera "vide" sauf si on intègre l'option A/B mitigation (snapshot column).

### D4 — Iter 1 ② Branch Overrides modèle de données sous-jacent

- **Verdict** : ✅ **`item_branch_availability` SUFFIT.** Pas de nouvelle table requise.
- iter1.jsx artboard ② : matrice 4 filiales avec `{name, code, on, reason, reasonLabel, ago, by}`.
- Mappage 1-1 avec `item_branch_availability` (`2026_04_15_230100_*.php`) :
  - `is_available` ↔ `on`
  - `unavailable_reason` ↔ `reason` / `reasonLabel`
  - `unavailable_since` ↔ `ago`
  - `editor_id` (à confirmer dans le modèle, pas dans la migration de base) ↔ `by`
- "Sync to all branches" CTA en bas → endpoint backend nouveau `POST /api/admin/items/{id}/availability/bulk`. **Pas de nouvelle table**, juste un nouvel endpoint et une transaction iterating sur `branches`.
- **Recommandation** : ajouter colonne `last_changed_by_id` (FK users) si non présente, pour afficher "by" robustement. Sinon utiliser `editor_id` du legacy `creator/editor` columns du model `ItemBranchAvailability`. À vérifier en β1.

### D5 — Iter 2 ⑥ Source Picker endpoints

- **Verdict** : ✅ **Endpoint existe et est cohérent.**
- Backend : `ComposerProfileController::availableSources(Item $item)` (lignes 111-151) retourne :
```json
{
  "item_attribute": [{id, name, source_type: "item_attribute"}],
  "extra_group":  [{id: group_label, name, source_type: "extra_group", count}],
  "addon":        [{id, name, source_type: "addon", addon_role}]
}
```
- Le side-sheet design ⑥ "create-on-the-fly" est une démo (handoff §2 caveat 1) — branchement à `ItemAttributeService::create()` / `ExtraGroupService::create()` à faire en β1. **Pas de friction architecturale**.

### D6 — Iter 2 ⑩ Permission Matrix alignée Spatie ?

- **Verdict** : ⚠️ **S2 — Misalignment des noms.**
- Design (iter2.jsx:455) roles : `["super_admin", "branch_manager", "kitchen_manager"]`.
- Spatie réel (preuves multiples) :
  - `app/Http/Controllers/Admin/AdminController.php:23,29,38,42` : `'Admin'`, `'Tenant Admin'` (capital, espace).
  - `app/Http/Controllers/Admin/ComposerProfileController.php:29` : `'Admin'`, `'Tenant Admin'`.
  - `CLAUDE.md` ligne 67 : "Permissions : Spatie Permission (rôles : `Admin`, `Manager`, `Cashier`, `Waiter`, `Branch Manager`)".
- Design actions (iter2.jsx:457-465) : `catalog.create_category`, `catalog.edit_category`, `catalog.publish_wizard`, etc. (préfixe `catalog.`).
- Spatie réel : `items_edit`, `items_show`, `items_destroy`, `composer_publish`, `item_categories_edit` (sans préfixe, underscored).
- **Misalignment confirmé** sur :
  - Noms de rôles (snake_case design vs Capital design Spatie)
  - Noms de permissions (préfixe `catalog.` design vs underscored Spatie)
- **Mitigation** : table de correspondance documentée en β2 :
```
super_admin    → Admin / Tenant Admin
branch_manager → Branch Manager
kitchen_manager → (à créer ? "Kitchen Manager" n'existe pas dans la liste CLAUDE.md)
catalog.create_category  → item_categories_create
catalog.publish_wizard   → composer_publish
catalog.edit_item        → items_edit
…
```
- **Severité S2** parce que sans cette table, le wrapper `<v-can :permission="...">` côté Vue ne reconnaitra aucune permission → tous les CTA seront affichés/cachés incorrectement.

### D7 — Iter 3 ⑮ RTL Arabic + POS/Kiosk LTR

- **Verdict** : ⚠️ **S1 design choice acceptée**, friction caissier arabophone.
- iter3.jsx ligne 315 confirme : "RTL : flex/grid s'inversent automatiquement. Chevrons retournés (chevron-r utilisé pour « retour »). Les codes monétaires et les chips POS/Kiosk gardent la lecture LTR via `direction: ltr` ciblé."
- **Justification design** : POS pos-wizard.js est vanilla JS frozen, refactor RTL XL. Kiosk client final aurait UX confuse en RTL pour des produits dont les prix et noms sont stockés en français/anglais.
- **Friction acceptée** : caissier arabophone navigue admin en AR mais doit basculer LTR mentalement quand il passe en POS. Acceptable pour une caisse FR primaire avec staff bilingue. **Documenter dans formation utilisateur**.
- Pas de violation invariant. À flagger pour V2 si présence arabophone forte en marché cible.

### D8 — Iter 3 ⑬ Keyboard shortcuts collisions POS

- **Verdict** : ⚠️ Décision humaine pending (handoff §2 caveat 3).
- Cmd+/ pour cheatsheet = pattern standard non-collisionnel.
- J/K/D/⌫ wizard = potentielle collision avec POS shortcuts existants (équipe POS utilise déjà des raccourcis claviers — non documenté formellement dans le package).
- **Recommandation** : avant β3, GO/NO-GO documenté avec équipe POS. Si collision, choisir `Alt+J/K/D` ou `Cmd+J/K/D`.

### D9 — `Catalog Studio.html` canvas 19 artboards utilisable ?

- **Verdict** : ✅ **Oui, mais requires browser + Babel-standalone.**
- HTML loads React UMD + Babel-standalone + 9 JSX modules via `<script type="text/babel" src="...">`. Pour un dev intégrateur, il faut :
  - Ouvrir le HTML dans un navigateur (rendering full pannable canvas).
  - **OU** lire les `.jsx` directement dans IDE (les composants sont auto-documentés avec props simples).
- Les 19 artboards sont organisés en 3 itérations + initial. Mapping → fichiers Vue dans le README v2 (handoff §1.2) clair.
- **Aucun artboard orphelin** détecté (tous mappés à un fichier Vue cible).

---

## 7. Performance & Opérabilité (P1-P4)

### P1 — Charge initiale `/admin/items/studio` queries SQL

- **Verdict** : Estimation 6-12 queries.
- `CatalogStudioComponent.vue` boot likely fetch :
  - `GET /api/admin/item-category` (1 query + eager `media`).
  - `GET /api/admin/item?paginate=0` (1 query + eager `media`, `tax`).
  - `GET /api/admin/tax` (optionnel, 1 query).
  - `GET /api/frontend/branch` (1 query).
- Subsequent : sélection catégorie → filtre client-side, pas de re-fetch.
- **Eager loading suffisant** si `with('media', 'tax', 'category')` est appliqué côté ItemController. Non vérifié direct dans le package (`ItemController.php` non lu en détail).
- **Recommandation** : sentinelle `assertQueryCount(N)` Telescope sur la route boot, β5.

### P2 — Cache catalog projection invalidation

- **Verdict** : Granulaire par item ✅.
- Per CV1 doc §6 ligne 170 : "DispatchableAfterCommit trait + outbox `PersistCatalogChangedToOutbox` + invalidation cache ciblée".
- `kiosk.menu.branch.{id}` cache key (CV1 doc §2 ligne 72) — invalidé par branche, pas globalement.
- Risque cache stampede acceptable si invalidation ciblée + warm-up post-publish.

### P3 — Pusher channels par admin Studio

- **Verdict** : ~4 channels privés.
- `private-branch.{id}`, `private-admin-orders`, `private-catalog-changed`, et possiblement `private-stock-level-changed`. Plus events généraux comme `private-composer-profile-changed`.
- Limite Pusher / Soketi : 100+ canaux par client supportés. **Largement dans la limite**.

### P4 — Diff endpoint α4 coût concurrent

- **Verdict** : O(n × f) où n=steps, f=12 fields. Trivial.
- Pour profile à 20 steps × 12 fields = 240 comparaisons. Négligeable (~ms).
- **Concurrence** : aucun lock requis (lecture-seule du `$profile->steps`). Nombre d'appels concurrents borné par session admin (1 modal ouvert = 1 appel à la fois). Pas de risque.

---

## 8. Tests & Evidence (T1-T3)

### T1 — Vitest 1054/0/2, PHPUnit 50/0/2, Playwright 1 PASS

- **Verdict** : ✅ Réplicable per `03-tests-evidence/test-results-summary.txt`.
- **Trou de coverage E2E déclaré** : pivot vers produit existant (handoff §5). Le test `catalog-studio-create-product-flow.spec.js` n'exerce **pas** la création produit complète end-to-end (validation backend manque tax/branch défaut sur env local). **Trou résiduel** : pas de test E2E qui prouve que création produit → wizard publish → POS reflète → Kiosk reflète. **Recommandation β5** : ajouter critical-flow `create-tacos-product-end-to-end` (cf. handoff §6 β5).
- 2 tests PHPUnit skipped : `PLAN_CV1-LIFECYCLE-UX-001 §2.2` pending (hors scope α). Documentés.

### T2 — `catalogStudioRouting.spec.js` couverture

- **Verdict** : ⚠️ **Sentinel superficielle** (routing presence check), pas profonde.
- Path : `00-base-foodking/tests/catalogStudioRouting.spec.js` — likely vérifie que la route `admin.items.studio` existe et renvoie le bon component.
- **Pas testé** : aucun flow user (création catégorie, ouverture drawer, wizard publish). Sentinel niveau Vitest (jsdom).
- Suffit pour détecter une régression de routing, **insuffisant pour valider la fonctionnalité**.

### T3 — Sentinelle a11y axe-core skippe si deps absent

- **Verdict** : ⚠️ **S2 — Faux PASS en CI risque CONFIRMÉ.**
- Per handoff §3 α6 : "sentinel structurellement valide ; bloc `AxeBuilder` skippé si `@axe-core/playwright` absent".
- En CI sans `@axe-core/playwright`, le test PASS sans rien vérifier réellement. **C'est un faux positif**.
- **Mitigation OBLIGATOIRE** : `npm i -D @axe-core/playwright` AVANT déploiement CI. Si dep absente, le test doit FAIL, pas SKIP.
- Recommandation : modifier la spec pour `test.fail` ou `test.skip(false, "...")` si dep absente, plutôt que silent skip.

---

## 9. Invariants FoodKing — table de conformité

| Invariant | Statut | Évidence (chemin:ligne) | Risque résiduel |
|---|---|---|---|
| **I1 Pricing SSOT** | ✅ OK | `CatalogStudioComponent.vue:106` (`{{ item.flat_price }}` lu depuis backend), `studio-data.jsx:30-37` (prix string strings, jamais calculés JS), aucun usage de `*` ou `+` sur des prix dans les composants Vue/composer. | Aucun. |
| **I2 OrderStatus enum** | ✅ N/A | Aucun `OrderStatus` touché dans le périmètre Catalog Studio (composer, items, branches). | Aucun. |
| **I3 branch_id** | ✅ **OK** | `AdminController.php:14-44` (`authorizeBranchScope` + `authorizeWritableBranchScope` strict 403). `ComposerProfileController.php:29-31,37,44,51,52,59,66,73` : appelés systématiquement. `items` table SANS `branch_id` (mono-marque, `SAAS_VISION.md:7-13`). `stock_levels.branch_id` FK constrained. | `item_branch_availability.branch_id` n'a pas de FK formelle (`unsignedBigInteger` sans `constrained`) — soft risk orphan rows si branche supprimée. **Mitigation faible recommandée** : ajouter FK in next migration. |
| **I4 Dispatch after commit** | ✅ OK | `app/Events/ComposerProfileChanged.php:12` `use DispatchableAfterCommit;`. Vérifié direct dans le repo principal. `ComposerStepService.php:120` dispatch dans `dispatchProfileChanged` mais via event class qui défère. `ComposerProfileService::publish:88-89` dispatch dans tx mais event a le trait. | Aucun. |
| **I5 OrderService symmetry** | ✅ N/A | Hors scope Catalog Studio. Aucune modif `OrderService` ou `FrontendOrderService` détectée. | Aucun. |
| **I6 Frozen zones** | ✅ OK | Aucun fichier sous `app/Services/Pricing/`, `app/Services/Order/Lifecycle/`, `app/Services/Fiscal/` modifié dans le périmètre du package (vérifié `find audit-claude-ultra-review-2026-05-03/00-base-foodking/backend-services/`). | Aucun. |

**Conformité globale** : **6/6 invariants respectés**.

---

## 10. Matrice de risques résiduels

| # | Risque | Sévérité | Probabilité | Composant | Mitigation proposée |
|---|---|---|---|---|---|
| **R1** | **`published_steps_snapshot` colonne absente → diff publish toujours `is_clean=true` en production** | **S2 critique** | **P3 (certaine)** | `ComposerDiffService` + `ItemWizardProfile` schema | **Schema migration nouvelle** : ajouter `published_steps_snapshot JSON NULL` à `item_wizard_profiles` + persister dans `ItemWizardProfile::publish()`. ESCALATE gate Schema Migration. |
| **R2** | `ItemPhotoController::store` non-atomique — `clearMediaCollection` puis `addMediaFromRequest` peut laisser item sans image | **S2 medium** | **P2** | `ItemPhotoController.php:20-21` | Inverser ordre (upload temp → swap atomique) ou try/catch + restore original. β1 P0. |
| **R3** | Permission matrix design (snake_case `super_admin` etc.) ≠ Spatie réel (`Admin`, `Branch Manager`) | **S2 medium** | **P3** | `studio-iter2.jsx:455-465` | Table de correspondance + rename design ↔ backend dans β2 wiring `<v-can>`. Documenter dans `02-plans-reports/PERMISSIONS_MATRIX_BINDING.md` à créer. |
| **R4** | Backend ne gère pas le 409 conflict version mismatch (design iter1 ⑤ + ⑤bis le promet) | **S2 medium** | **P2** | `ComposerProfileService::update:60-79` | Ajouter check `if ($payload['version'] !== $profile->version) abort(409)` + sentinelle `ComposerProfileVersionConflictTest`. β1 P0 conjointement avec C5 banner. |
| R5 | Sentinel a11y axe-core skip silent si `@axe-core/playwright` absent → faux PASS CI | S2 medium | P3 | `tests/e2e/catalog-studio-a11y-axe.spec.js` | Installation `@axe-core/playwright` obligatoire avant CI run. Documenter en β5. Patch spec : `test.fail` si dep absente, pas skip. |
| R6 | `ComposerDiffService::comparable('min_select')` cast `(int)null === 0` masque le diff | S1 mineur | P0 | `ComposerDiffService.php:262` | Documenter dans le code (`null` ne survient pas en pratique car `normalize()` cast). Aucun fix code requis. |
| R7 | iframe drawer composer = double Vue app, double Pusher subscription, focus trap absent | S1 mineur | P3 | `CatalogStudioComponent.vue:174-177` | V2 refactor : mount Vue direct via `<component :is>`. Documenter dans roadmap V2-WIZARD-COMPOSER-DRAWER. |
| R8 | `tokens.css` (preview design) contient mirrors `--cv1-*` et `--fk-*` non purement additifs | S1 mineur | P0 | `01-design-claude-v2/tokens.css:10-40` | À l'intégration β1, prendre uniquement le bloc `--studio-*` (lignes 43-104). Sentinel α3 protège déjà la prod cv1-tokens.css. |
| R9 | `item_branch_availability.branch_id` sans FK formelle | S1 mineur | P1 | `2026_04_15_230100_*.php:18` | Ajouter FK constraint dans migration future (avec `nullOnDelete` ou `cascadeOnDelete`). Hors scope V1. |
| R10 | RTL admin AR + POS LTR = friction caissier arabophone | S1 mineur | P2 | `iter3.jsx:315` (design accept) | Documenter dans formation utilisateur. V2 refactor POS RTL si demande marché. |
| R11 | i18n `studio` namespace présent dans 5 locales mais parité de clés non vérifiée | S1 mineur | P2 | `lang/{ar,bn,de,en,fr}/all.php` | Sentinelle `i18nStudioKeyParityTest.spec.js` Vitest. β2. |
| R12 | `ItemListComponent::createProduct` (legacy) vs `CatalogStudio::createProduct` (quick) — divergence de payload | S1 mineur | P1 | `ItemListComponent.vue` (non lu) + `CatalogStudioComponent.vue:300+` | Sentinelle `apiPostItemContractTest` qui prouve les deux payloads créent un Item valide. β2. |
| R13 | E2E coverage trou : pas de test "create-product-end-to-end" complet (pivot vers produit existant) | S1 mineur | P2 | `catalog-studio-create-product-flow.spec.js` | Étendre Playwright spec avec seed tax + branch défaut + vraie création POST → publish → POS reflète. β5. |
| R14 | `KioskPosWizardComponent.vue` = 18 lignes wrapper redondant | S0 cosmetic | P0 | `KioskPosWizardComponent.vue:1-19` | Cleanup V2 — soit deprecate, soit clarifier l'usage staff/kiosk. |

**Bilan** : **0 risque S3**, **5 risques S2** dont 1 critique (R1 = ESCALATE schema), **9 risques S1**, **1 risque S0**. Brief PASS criterion exige ≤ 2 S2 → **DÉPASSÉ**, donc REWORK minimum.

---

## 11. Bilan design ↔ base ↔ vision (synthèse cross-couche)

### ✅ Ce qui aligne parfaitement

- **Schéma BDD ↔ design fixtures** : `studio-data.jsx` TACOS_STEPS contient les 14 colonnes de `item_wizard_steps` en snake_case exact, incluant la nouvelle FK `source_item_attribute_id` (Phase α). 10/10.
- **Diff Modal design ↔ ComposerDiffService payload** : structure identique `{added, removed, modified[step_key, before, after, changed_fields]}`. 10/10 sur la forme — voir asterisque B3 sur le contenu.
- **Branch Overrides design ↔ `item_branch_availability` table** : mapping 1-1 sans nouvelle table requise.
- **POS-mono / Kiosk-multi 1 source 2 projections** : `ComposerProfileProjection::project($profile, $item, $surface)` est l'unique pivot, pas de duplication.
- **DispatchableAfterCommit invariant I4** : `ComposerProfileChanged`, `ItemAvailabilityChanged`, `StockLevelChanged` tous conformes.
- **Atomicité stock** : `lockForUpdate` + tx + idempotency_key = robuste.
- **Authorization branch_id (I3)** : `AdminController::authorize{Writable,}BranchScope` strict, appliqué systématiquement dans `ComposerProfileController`.

### ⚠️ Ce qui a une friction acceptable (à documenter / corriger en β)

- **iframe drawer composer** : pragmatique V1, anti-pattern — refactor V2.
- **Polymorphisme `source_ref`** : seulement `item_attribute` durci avec FK typée. `extra_group` (group_label) et `addon` (addon_role enum) restent stringifiés mais référentiellement OK. Documentation suffit V1.
- **Tokens.css mirrors** : preview-only, sentinel α3 protège la prod. Documenter règle d'intégration.
- **RTL admin / POS LTR** : décision design acceptée. Friction caissier arabophone à formation.
- **i18n parité clés** : à sentinelliser β2.
- **Permission matrix names** : table de correspondance à créer β2.

### ❌ Ce qui rompt l'alignement (REWORK requis)

- **`published_steps_snapshot` absent** : le promesse fonctionnelle "diff publish" du iter1 ④ tombe à plat en production. Faux positif silencieux. **Critique S2**, ESCALATE Schema Migration. **C'est le rupteur de cohérence le plus grave de cet audit.**
- **Backend conflict 409 absent** : iter1 ⑤ "Conflict banner v3 → v5" dépend d'un check `version` côté backend qui n'existe pas. Le service incrémente `version` unconditionally, jamais ne refuse. Design promet une UI, backend ne l'alimente pas.
- **`ItemPhotoController` non-atomique** : design iter1 ③ "image upload 5 états" attend une atomicité que le backend ne garantit pas. State `error` → image perdue.
- **Permission matrix names** : design ne se branche pas tel quel sur Spatie. Translation layer obligatoire.

---

## 12. Recommandations Phase β (amender ou confirmer le backlog actuel)

Le backlog actuel (handoff `RUN_CATALOG_STUDIO_FINAL_DESIGN_HANDOFF_2026-05-03.md` §6) est **largement pertinent** mais doit être **re-séquencé** pour traiter en priorité les S2 critiques :

### Re-séquençage proposé

| Cycle | Action | Modification vs handoff actuel |
|---|---|---|
| **β-PRE-1** (NOUVEAU) | Schema migration `add_published_steps_snapshot_to_item_wizard_profiles` + persister dans `ItemWizardProfile::publish()` | **AJOUT** — ESCALATE gate Schema Migration, doit passer AVANT β1.C4 (Diff Modal) |
| **β-PRE-2** (NOUVEAU) | Backend version conflict check 409 dans `ComposerProfileService::update` + sentinelle PHPUnit | **AJOUT** — couple avec β1.C5 (Conflict 409 banner) |
| **β-PRE-3** (NOUVEAU) | Atomic photo upload pattern dans `ItemPhotoController::store` (upload-then-swap) + sentinelle | **AJOUT** — couple avec β1.C3 (Image upload 5 états) |
| **β-PRE-4** (NOUVEAU) | Table de correspondance `studio_permissions_to_spatie.md` + middleware translation layer | **AJOUT** — précede β2.I12 (Permission-aware UI) |
| β1 — Critiques (5 angles) | C1 drag & drop, C2 branch overrides, C3 image upload, C4 diff modal, C5 conflict | **Garder ordre**, mais brancher C3/C4/C5 sur β-PRE-1/2/3 |
| β2 — Importantes (7 angles) | I6 source picker, I7 auto-save, I8 empty/error/no-perm, I9 toast, I10 RTL, I11 stock resolve, I12 permission UI | **Garder**, brancher I12 sur β-PRE-4 |
| β3 — Polish (5 angles) | P13 keyboard, P14 search, P15 1280px, P17 onboarding, P18 photos | **Garder** + décision humaine J/K/D/⌫ avant P13 |
| β4 — Caveats config | Noto Sans Arabic + keybindings | **Garder** |
| β5 — Verification | axe-core install + critical-flow étendu + PHPUnit régression | **Modifier T3** : `@axe-core/playwright` install **mandatoire pre-CI**, pas optionnel. Ajouter sentinelle E2E "create-product-end-to-end" |
| β6 (V2 candidat) | Refactor iframe drawer en mount Vue direct | Hors V1 — roadmap V2 |

### Actions hors-cycle (V2)

- **V2-CV1-FK-EXTRA-ADDON** : table canonique `item_extra_groups` + colonnes `source_extra_group_id`, `source_addon_id` typées FK pour finir le polymorphism.
- **V2-WIZARD-COMPOSER-DRAWER** : refactor iframe → Vue mount.
- **V2-POS-RTL** : refactor `pos-wizard.js` pour support RTL si demande marché.
- **V2-WIZARD-CHANNEL-SYMMETRY** : aligner les 4 paths stock (variation/extra/addon/item) sur même mécanisme `ItemAvailabilityChanged`.

---

## 13. Verdict final

**VERDICT : REWORK + ESCALATE (gate Schema Migration)**

Justification :

1. **5 risques S2 identifiés** (R1-R5), dont **1 critique** (R1 = `published_steps_snapshot` colonne absente). Le seuil PASS du brief §12 (≤ 2 S2 mitigés) est dépassé.
2. **R1 nécessite une migration DB nouvelle** (colonne JSON ou nouvelle table), ce qui par §12 du brief déclenche **automatiquement ESCALATE** (gate Schema Migration). L'audit ne peut pas approuver une DDL — gate humain requis.
3. La **fidélité design ↔ schéma BDD est ≥ 86 %** (excellent sur fixtures et structure, mais misalignment sur permission names = -10 %, et la promesse fonctionnelle du diff publish ne tient pas en prod = -4 %).
4. Les **6/6 invariants sont respectés** (I1, I2 N/A, I3, I4, I5 N/A, I6). Aucune violation `branch_id`, aucune frozen zone touchée, dispatch after commit conforme.
5. **0 risque S3 catastrophique**. Les S2 sont tous mitigeables sans architecture rewrite, mais R1 requiert un gate humain DDL.
6. La phase β backlog actuel est **80 % pertinent**, mais nécessite **4 nouveaux cycles β-PRE-1 à β-PRE-4** avant β1 pour fixer les fondations critiques (snapshot, version, atomic photo, permission mapping).
7. Les tests sont solides (1054 + 50 + 1) **mais T3 a un faux PASS silencieux** sur axe-core que CI doit corriger avant déploiement.

Le projet **n'est pas catastrophique** mais **n'est pas non plus prêt à intégration UI directe** sans les 4 fixes pre-β. La livraison Claude Design v2 est **fidèle et utilisable**, le backend Phase α est **robuste sur les paths exercés**, mais le diff publish fonctionnellement promis est **un point aveugle silencieux** qui invaliderait l'expérience admin la première fois qu'un publish réel est demandé.

---

## 14. Plan correction ordonné (REWORK)

| # | Fichier | Correction | Priorité | Effort estimé |
|---|---|---|---|---|
| 1 | `database/migrations/2026_05_04_000010_add_published_steps_snapshot_to_item_wizard_profiles.php` | Ajouter colonne `published_steps_snapshot JSON NULL` à `item_wizard_profiles` (**après gate humain DDL**) | **P0** | M |
| 2 | `app/Models/ItemWizardProfile.php` | Ajouter `published_steps_snapshot` à `$fillable` + `$casts['published_steps_snapshot' => 'array']`. Enrichir `publish()` pour persister `$this->steps->map(toArray)->all()` dans cette colonne | **P0** | S |
| 3 | `tests/Feature/Composer/ComposerDiffServiceTest.php` | Migrer du `setAttribute()` en mémoire vers vraie persistance DB (`refresh()` après `publish()`). Ajouter test `test_published_profile_with_drift_shows_modified` qui prouve le diff fonctionne sans injection mémoire | **P0** | S |
| 4 | `app/Http/Requests/ComposerProfileRequest.php` + `app/Services/Composer/ComposerProfileService.php:60-79` | Ajouter validation `version` requise dans `update` payload + check `$payload['version'] !== $profile->version → abort(409, ['expected' => $profile->version])` | **P0** | S |
| 5 | `app/Http/Controllers/Admin/ItemPhotoController.php:18-30` | Réécrire upload : (1) addMediaFromRequest vers collection temp ; (2) success → clearMediaCollection('item') + media->move('item') ; (3) catch → log + 500 sans toucher l'existante | **P0** | M |
| 6 | `02-plans-reports/STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md` (NOUVEAU) | Documenter table de correspondance studio role/permission ↔ Spatie réel | **P1** | S |
| 7 | `package.json` + `tests/e2e/catalog-studio-a11y-axe.spec.js` | `npm i -D @axe-core/playwright` ; modifier la spec pour FAIL si dep absente (pas skip) | **P1** | S |
| 8 | `tests/Feature/Composer/ComposerProfileVersionConflictTest.php` (NOUVEAU) | Sentinelle PHPUnit qui prouve update payload avec stale version → 409 + body `{expected: N}` | **P1** | S |
| 9 | `tests/Feature/Items/ItemPhotoUploadAtomicityTest.php` (NOUVEAU) | Test : forcer addMediaFromRequest à throw → vérifier que l'image originale est intacte | **P1** | M |
| 10 | `tests/js/i18nStudioKeyParityTest.spec.js` (NOUVEAU) | Sentinelle Vitest : compare `array_keys` du namespace `studio` entre 5 locales | **P2** | S |
| 11 | `database/migrations/2026_05_04_*_add_branch_id_fk_to_item_branch_availability.php` (NOUVEAU) | Ajouter FK formelle `branch_id` constrained sur `item_branch_availability` (nécessite migration de schema → ESCALATE secondaire) | **P2** | S |
| 12 | `tests/Feature/Items/ItemCreateContractTest.php` (NOUVEAU) | Sentinelle PHPUnit prouve que payloads quick (CatalogStudio) et full (ItemListComponent) créent un Item valide | **P2** | S |
| 13 | `resources/js/components/admin/items/CatalogStudioComponent.vue` | Ajouter `useFocusTrap` + `keydown.esc` handler sur drawer | **P2** | S |

---

## 15. Gate humain à ouvrir (ESCALATE)

- **Trigger** : `ComposerDiffService::projectPublishedProfile` lit `published_steps_snapshot` colonne qui n'existe pas en BDD ⇒ diff faux silencieux confirmé en production. Mitigation requiert migration DDL.
- **Type de gate** : **Schema Migration** (DDL).
- **Décision requise (1 question précise)** :
  > Autoriser une migration nouvelle ajoutant la colonne `published_steps_snapshot JSON NULL` à `item_wizard_profiles` (option A), OU une nouvelle table `item_wizard_step_versions` insert-only (option B), pour permettre au `ComposerDiffService` de produire un diff non-faux entre version publiée et brouillon — précondition au cycle β1 C4 (Diff Modal Vue) ?
- **Options** :
  1. **Approuver Option A** (colonne JSON sur table existante) — migration légère, low-risk, idempotent à backfill (`UPDATE … SET snapshot = (SELECT json_agg(steps) WHERE profile_id = id AND profile.is_published)` ou backfill au prochain publish).
  2. **Approuver Option B** (table d'historique) — plus lourd (table, FK, indexes) mais offre historique multi-version pour audit fiscal NF525 et rollback de publish. Recommandé si stratégie audit long-terme.
  3. **Annuler** — refactor `ComposerDiffService::diff()` pour retourner explicitement "snapshot indisponible — diff non possible" et désactiver le bouton Publish dans la modal Vue (UX dégradée mais honnête).
- **Recommandation auditeur** : **Option A** pour V1 (rapide, debloque β1), **Option B** envisagée V2 si audit fiscal le requiert.

### Gates secondaires (P2, non-blocking V1)

- **Gate Schema Migration mineur** : ajouter FK `branch_id` constrained sur `item_branch_availability` (R9). Hors blocking V1.
- **Gate Architecture Decision** : politique permission `items_edit` pour Branch Manager (peut-il modifier la photo globale d'un item ?). Décision humaine produit.

---

## 16. Mémoire Graphiti — facts ajoutés (proposés, à valider humain)

À graver via `add_memory(group_id="foodking")` après cycle :

- **Fact 1** : "Audit Catalog Tree 2026-05-03 a identifié `published_steps_snapshot` colonne absente ⇒ ComposerDiffService produit faux positif `is_clean=true` en production. Mitigation par migration DDL gate Schema Migration option A (colonne JSON) ou option B (table d'historique). Recommandé option A V1." (catégorie: invariant_risk)
- **Fact 2** : "ItemPhotoController.store ordre `clearMediaCollection` puis `addMediaFromRequest` non-atomique. Si 2e étape échoue, item perd son image. Mitigation : upload-then-swap atomique. Confirmé S2 medium." (catégorie: invariant_risk)
- **Fact 3** : "Permission matrix design Claude v2 utilise snake_case (`super_admin`, `branch_manager`, `kitchen_manager`) et préfixe `catalog.`. Spatie réel utilise capital (`Admin`, `Branch Manager`) et permissions sans préfixe (`items_edit`, `composer_publish`). Translation layer requis en β2 wiring `<v-can>`." (catégorie: integration_gap)
- **Fact 4** : "Backend `ComposerProfileService::update` incrémente `version++` unconditionally sans check de version mismatch. Design iter1 ⑤ promet conflict banner 409. Backend manque la logique 409 — à ajouter en β-PRE-2 avant β1.C5." (catégorie: integration_gap)
- **Fact 5** : "Items table mono-marque (no `branch_id`) confirmé `2022_11_17_110514_create_items_table.php` — par design SAAS_VISION.md. I3 ne s'applique pas à items, attributes, extras, addons (globaux). I3 s'applique à `item_branch_availability`, `stock_levels`, `stock_movements`, `item_wizard_profiles.branch_id_scope`. AdminController.authorize{Writable,}BranchScope défend correctement les paths branch-scoped." (catégorie: invariant_clarification)
- **Fact 6** : "Source_ref polymorphism Phase α partiellement résolu : `source_item_attribute_id` FK typée pour case `item_attribute`. `extra_group` matche par `group_label` lowercase (référentiel fragile). `addon` privilégie `addon_role` enum (FK virtuelle). `fixed` enum DB mais rejeté par service. Suffisant V1, à finir V2 avec table canonique `item_extra_groups`." (catégorie: technical_debt)
- **Fact 7** : "Tests Phase α : Vitest 1054/0/2 + PHPUnit 50/0/2 + Playwright 1 PASS. T3 sentinelle a11y axe-core skippe silencieusement si `@axe-core/playwright` absent → faux PASS CI. Installation OBLIGATOIRE avant déploiement." (catégorie: test_evidence_quality)
