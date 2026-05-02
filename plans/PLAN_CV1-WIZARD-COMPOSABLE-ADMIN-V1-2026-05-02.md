# ULTRA PLAN — Wizard Composable Admin V1
## CV1-WIZARD-COMPOSABLE-001 — 2026-05-02

**Auteur :** Claude in-session orchestrator
**Demande user (2026-05-02 22:22) :** « Wizard composable user-friendly per-product. Caisse = 1 page monolithique. Borne = N pages décomposées personnalisables (ajouter/retirer pages). Synchro stock + IDs traçables. Plug-and-play, pas de complexité technique. Tourne en boucle d'audits jusqu'à propre. Maximum ressources. »
**Phase :** PLAN (avant exécution)
**Cycle parent :** `CV1-V1-CLOSEOUT-001` (master orchestration close-out V1 fonctionnelle)

---

## §0 — Décomposition profonde de la demande

### Concept-clé

Un **produit** (Item) appartient à une **catégorie** (ItemCategory) et possède un **wizard de composition** (ItemWizardProfile). Ce wizard décrit le parcours que suit l'utilisateur (caissier ou client kiosk) pour configurer le produit avant ajout au panier.

### Surfaces différenciées

| Surface | Wizard cible | Use case | Format |
|---|---|---|---|
| **POS (caissier)** | **MONOLITHIQUE 1 page** | Caissier rapide, vue compacte densité élevée, toutes options visibles d'un coup, scroll vertical compact | Modale large, sections accordéon |
| **Kiosk (client)** | **MULTI-PAGES** (1-N pages) | Client tactile, parcours guidé, fullscreen, animation transition | 1 step = 1 page plein écran |

### Personnalisation par produit (le cœur de la demande user)

Pour chaque produit, l'admin doit pouvoir, **dans une UI plug-and-play SANS code** :

1. **Voir le wizard actuel** (liste de pages/steps avec leur nom et leur contenu)
2. **Ajouter une page** (step) — choisir source (variations / extras / addons / item attribute), nommer la page
3. **Supprimer une page** d'un produit spécifique (ex : "cette assiette n'a pas de menu → retire la page menu")
4. **Réordonner les pages** par drag & drop
5. **Configurer chaque page** :
   - Nom affiché client (ex : "Choisis ta sauce")
   - min/max sélections
   - Liste d'options (auto-générée depuis la source : variations item, extras item, addon items)
   - Visibilité par surface (POS uniquement / Kiosk uniquement / les 2)
6. **Tester en preview** (live preview Kiosk + POS)
7. **Publier** (flip `is_published`, version++, broadcast event)

### Templates (pour ne pas configurer chaque produit à la main)

L'admin doit pouvoir choisir un **template de wizard** au moment de la création produit :
- `simple` (pas de wizard, juste qty)
- `sandwich` (pain + viande + sauce + garnitures + suppléments)
- `tacos` (taille + viande + sauce + garnitures + suppléments + menu)
- `assiette` (viande + sauce + garnitures, PAS de menu, PAS de pain)
- `snacking` (juste suppléments)
- `menu` (formule complète)
- `custom` (vide, à composer manuellement)

Le template **pré-remplit** les pages, l'admin **personnalise ensuite** (retire ou ajoute selon le produit spécifique).

### Synchronisation centrale + stock + IDs traçables

Chaque option de wizard (variation, extra, addon) **est un Item du catalogue** (ou un attribut d'item). Donc :
- Quand admin crée une option (ex : "Boisson Coca-Cola 33cl"), c'est un Item normal dans le catalogue.
- Quand cet Item entre dans un wizard (ex : page "Boisson" du Tacos), c'est référencé par son **ID** (FK).
- Quand le stock de Coca-Cola change, **toutes les options "Coca-Cola"** des wizards de tous les produits réagissent (rupture, low stock, etc.).
- Quand admin renomme Coca-Cola → propagé partout via `CategoryUpdated` / `ItemAvailabilityChanged`.

### User-friendly = critères concrets V1

1. **Liste claire des produits par catégorie** (pas de page vide, pas de "où sont mes produits ?")
2. **Bouton "+" évident pour créer** une catégorie ou un produit
3. **Configurateur wizard accessible en 1 clic** depuis chaque produit
4. **Preview live** (voir comment ça rend POS + Kiosk en même temps qu'on configure)
5. **Drag & drop pages** (pas de formulaire technique)
6. **Pas de termes techniques** (`source_type`, `source_ref`, `is_active` — au max traduits en "Source : Variations du produit", "Activé : oui/non")
7. **Validation amicale** (messages d'erreur clairs, pas de stack trace JSON)
8. **Plug & play** : un nouvel admin doit pouvoir créer un produit + wizard en <5 min sans formation

---

## §1 — Structure backend ACTUELLE (audit Axe 4 + lectures complémentaires)

### Modèles
- `App\Models\Item` — produit (cf. `app/Models/Item.php`)
- `App\Models\ItemCategory` — catégorie
- `App\Models\ItemVariation` — variation produit (ex : taille S/M/L)
- `App\Models\ItemExtra` — extras (ex : sauces gratuites)
- `App\Models\ItemAddon` — addons (ex : boisson en supplément, lien `addonItem` vers Item)
- `App\Models\ItemAttribute` — attributs partagés (ex : viandes globales)
- `App\Models\ItemWizardProfile` — profil wizard d'un Item (versionné, publishable)
- `App\Models\ItemWizardStep` — step d'un profile (`step_key`, `label`, `source_type`, `source_ref`, `position`, `min_select`, `max_select`, `visible_on`, `addon_role`, `is_active`, `repeat`)
- `App\Models\StockLevel` — stock per `(branch_id, stockable_type, stockable_id)`
- `App\Models\ItemBranchAvailability` — disponibilité per `(item_id, branch_id)` + max_daily_qty

### Services
- `App\Services\ItemService` — CRUD Item (store/update/destroy/duplicate)
- `App\Services\ItemCategoryService` — CRUD Category
- `App\Services\Composer\ComposerProfileService` — CRUD ItemWizardProfile (create/update/publish/unpublish)
- `App\Services\Composer\ComposerStepService` — CRUD ItemWizardStep (create/update/delete)
- `App\Services\Composer\ComposerProfileProjection` — projection runtime (`project($profile, $item, $surface, $branchId)`) qui filtre par `visible_on` + dispo stock per surface

### Endpoints actuels
- `GET /api/admin/composer/items/{item}/profile` — `ComposerProfileController::show`
- `POST /api/admin/composer/items/{item}/profile` — create
- `PUT /api/admin/composer/profiles/{profile}` — update
- `POST /api/admin/composer/profiles/{profile}/publish` — publish
- `POST /api/admin/composer/profiles/{profile}/unpublish` — unpublish
- `POST /api/admin/composer/profiles/{profile}/steps` — add step
- `PUT /api/admin/composer/profiles/{profile}/steps/{step}` — update step
- `DELETE /api/admin/composer/profiles/{profile}/steps/{step}` — delete step
- `GET /api/admin/menu-projection?surface=pos|kiosk&branch_id=X&item_id=Y` — preview (ItemPreviewComponent M2 1.2)

### Event flow
- `ComposerProfileChanged` (publish/unpublish/update) → `PersistCatalogChangedToOutbox` + `InvalidateKioskMenuCacheOnCatalogChange` → broadcast Echo `branch.{X}` → `useCatalogChangeNotifier` côté Kiosk reçoit, prune cart, toast.

### Constat : **le backend EXISTE et est COMPLET**. Ce qui manque = l'**UI admin user-friendly**.

---

## §2 — Structure frontend ACTUELLE (état des lieux)

### Côté admin
- `resources/js/components/admin/items/ItemListComponent.vue` — liste produits (existant)
- `resources/js/components/admin/items/ItemCreateComponent.vue` — création produit (existant)
- `resources/js/components/admin/items/ItemShowComponent.vue` — détail produit avec onglets (existant — onglet Aperçu M2 1.2 ajouté)
- `resources/js/components/admin/items/ItemPreviewComponent.vue` — preview POS+Kiosk (M2 1.2)
- `resources/js/components/admin/items/wizard/ProductCreateWizardComponent.vue` — squelette (M2 V2 task 2.9 différé)
- **MANQUE** : éditeur composer wizard user-friendly (drag & drop pages, ajout/suppression page, naming, sourcing options)
- `resources/js/router/modules/adminRoutes.js:5-7` — route `/admin/items/show/:id/composer` → `ProductComposerEditorComponent` (à inspecter — état exact à confirmer)

### Côté POS (caisse)
- `public/js/pos-wizard.js` — wizard vanilla monolithe single-page S25 (audit Axe 4)
- 0 référence à `composer_profile` — **dette majeure** : le composer profile publié côté admin n'est PAS lu par le POS

### Côté Kiosk (borne)
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` — orchestrateur multi-steps (composer-aware lignes 442-516)
- `resources/js/components/frontend/kiosk/steps/Kiosk*Component.vue` — 8 step components (Pain, Sauce, Garnitures, Suppléments, Menu, GenericChoices, Taille, Viande)
- `resources/js/components/frontend/kiosk/KioskOrderSummaryComponent.vue` — récap
- État : **fonctionne, lit composer_profile, mais step components codés en dur** (pas dynamiquement personnalisables au runtime selon le composer admin)

### Constat
1. **Backend prêt** pour personnalisation per-product (modèles + services + projection).
2. **Kiosk runtime** lit composer_profile mais avec step components hardcodés (limitation acceptable V1 si on a couvert les templates standards).
3. **POS runtime** ne lit PAS composer_profile (gap critique — RT-02).
4. **Admin UI éditeur** = squelette ou inexistant — **CŒUR DU TRAVAIL V1 user-demanded**.

---

## §3 — Plan d'attaque en 5 phases (méthodologie ultra-détaillée)

### Phase A — Audit profond multi-axe (4 sub-agents parallèles, read-only)

| Sub-axe | Question | Sortie |
|---|---|---|
| A.1 | Quel est l'état EXACT de l'éditeur admin composer actuel ? Que manque-t-il pour user-friendly ? | `reports/audit/CV1_WIZARD_AXE_A1_ADMIN_COMPOSER_UI_2026-05-02.md` |
| A.2 | Stock + Items + Variations + Extras + Addons : tous traçables par ID via FK ? Quelles sont les requêtes pour résoudre "options d'une page de wizard depuis le catalogue" ? | `reports/audit/CV1_WIZARD_AXE_A2_STOCK_ID_TRACEABILITY_2026-05-02.md` |
| A.3 | Workflow admin actuel : création produit → assignation catégorie → configuration wizard → publish → vérification borne/caisse. Où sont les frictions ? | `reports/audit/CV1_WIZARD_AXE_A3_ADMIN_WORKFLOW_2026-05-02.md` |
| A.4 | Décomposition Kiosk pages actuelles : quels step components, quel mapping `step_key` → composant, comment ajouter/retirer une page côté Kiosk ? | `reports/audit/CV1_WIZARD_AXE_A4_KIOSK_PAGE_DECOMPOSITION_2026-05-02.md` |

### Phase B — Synthèse audits + Master spec UI/UX

Consolide les 4 audits en :
- `reports/audit/CV1_WIZARD_MASTER_SYNTHESIS_2026-05-02.md` (verdict + scores + blockers + plan)
- `docs/specs/SPEC_WIZARD_COMPOSABLE_ADMIN_UI_2026-05-02.md` (UI mockup textuel page-par-page de l'éditeur admin)

### Phase C — Implémentation Admin UI éditeur composable

#### C.1 — Liste produits par catégorie (cleanup + ergonomie)
**Tâche :** `T-WC-LIST-01` — `ItemListComponent` épuré : groupement par catégorie, badge "wizard configuré : oui/non", bouton "Configurer wizard" visible.
**Tier :** routine M.

#### C.2 — Page éditeur wizard (le cœur)
**Tâche :** `T-WC-EDITOR-01` — `WizardComposerEditorComponent.vue` :
- Liste pages actuelles (drag & drop sortable, `vuedraggable`)
- Bouton "+ Ajouter page" → modale avec choix template (taille / viande / sauce / etc.) ou step custom
- Chaque page : nom éditable, source (variations/extras/addons/attribute), min/max sliders, visibilité (POS/Kiosk checkboxes), bouton "Supprimer cette page"
- Sidebar droite : preview live POS + Kiosk (réutilise `ItemPreviewComponent` M2 1.2)
- Bouton "Publier" en bas (flip `is_published`)
**Tier :** complex L (composant Vue 3 large, drag & drop, preview live, intégration plusieurs API).

#### C.3 — Templates de wizard
**Tâche :** `T-WC-TEMPLATES-01` — Au moment de créer un produit, choisir template `sandwich/tacos/assiette/...`. Le template **pré-remplit** les pages standards. L'admin personnalise ensuite via C.2.
**Tier :** routine M.

#### C.4 — Sourcing options depuis catalogue
**Tâche :** `T-WC-SOURCE-01` — Pour chaque page (step), résoudre dynamiquement les options depuis :
- `source_type='item_attribute'` + `source_ref=ID` → toutes les variations qui partagent cet attribut
- `source_type='extra_group'` → tous les extras du produit
- `source_type='addon'` → tous les addons du produit
+ ajout d'un endpoint `GET /api/admin/composer/items/{item}/available-sources` qui liste les options sourçables.
**Tier :** routine M (backend) + routine S (frontend dropdown).

### Phase D — Synchro & invariants

#### D.1 — POS wizard runtime lit composer_profile
**Tâche :** `T-WC-POS-RUNTIME-01` — Refactor `public/js/pos-wizard.js` pour :
- Charger `item.composer_profile` (déjà projeté backend)
- Construire les pages dynamiquement depuis `composer_profile.steps`
- Garder le rendu monolithique single-page (sections accordéon par step)
**Tier :** complex L (vanilla JS legacy à refactor sans tout casser).

#### D.2 — Kiosk runtime piloté par admin
**Tâche :** `T-WC-KIOSK-RUNTIME-01` — Vérifier que `KioskWizardComponent` :
- Itère sur `composer_profile.steps` (déjà fait)
- Mappe chaque `step_key` → un step component générique OU spécialisé
- Si admin retire une page, elle disparaît automatiquement (déjà cas grâce à la projection)
- Sentinel : "admin retire la page Menu d'une assiette → kiosk ne montre plus Menu pour cette assiette"
**Tier :** routine M (vérification + sentinel).

#### D.3 — Synchro stock IDs
**Tâche :** `T-WC-STOCK-SYNC-01` — Vérifier que :
- Toutes les options projetées (variations/extras/addons) sont des FK vers Item ou ItemAttribute
- `ChoiceAvailabilityResolver` filtre correctement par stock per branch
- Une rupture de stock sur une option du wizard = option grisée/disabled côté POS+Kiosk
- Sentinel `WizardOptionStockSyncTest`
**Tier :** routine M.

### Phase E — Audit-loop + sentinels + tests

#### E.1 — Audit-loop 1 (post-implémentation)
Cross-référence des 4 audits Axe A.1-A.4 + résultats implémentation. Détecte lacunes restantes.

#### E.2 — Audit-loop 2 (validation finale)
Verdict GO / REWORK. Si REWORK, retour Phase C ou D selon le gap.

#### E.3 — Tests globaux
- Sentinels nouveaux (T-WC-EDITOR, T-WC-SOURCE, T-WC-POS-RUNTIME, T-WC-KIOSK-RUNTIME, T-WC-STOCK-SYNC)
- Vitest global + PHPUnit global = 0 régression
- E2E Playwright optionnel : "admin crée produit + wizard 4 pages → kiosk affiche les 4 pages"

---

## §4 — Engagement de non-régression

**Pendant tout ce travail :**
- AUCUN flip de flag `catalog_v15.unified_projection.enabled` en prod (reste à false par défaut)
- AUCUNE modification du runtime POS / Kiosk **actuellement fonctionnel** (sauf via flag opt-in)
- AUCUNE modification des frozen zones (Pricing, Payments, NF525)
- Toutes les nouvelles features sont **gated** par flag pour rollback O(1)

---

## §5 — Roadmap exécution avec sub-agents

| Étape | Quoi | Sub-agents | Synchro |
|---|---|---|---|
| Maintenant | Audit parallèle Axes A.1-A.4 | 4 × explore | parallèle |
| +30 min | Synthèse Phase B | Claude in-session | sequential |
| Après | Phase C : T-WC-LIST-01, T-WC-TEMPLATES-01 | 2 × routine (Composer ou generalPurpose) | parallèle |
| Après | Phase C : T-WC-EDITOR-01 (XL) | 1 × Codex complex (si Pro dispo) ou fallback Cursor | sequential |
| Après | Phase C : T-WC-SOURCE-01 | 1 × routine | indépendant |
| Après | Phase D : T-WC-POS-RUNTIME-01 (L) | 1 × Codex complex | bloquant |
| Après | Phase D : T-WC-KIOSK-RUNTIME-01 + T-WC-STOCK-SYNC-01 | 2 × routine | parallèle |
| Final | Audit-loop 1 + 2 + tests globaux | Claude in-session | sequential |

---

## §6 — Doctrine appliquée

- `.cursor/routing.md` § Tier-Routing 2026-05-02
- `docs/orchestration/MULTI_AGENT_LOOP_2026-05-02.md`
- `.cursor/rules/global.mdc` § Token Discipline (qualité, parallélisation efficace)
- `.cursor/rules/cross-agent-sync.mdc` (réservation systématique start/done)
- `.cursor/rules/project-invariants.mdc` (6 invariants)
- `.cursor/rules/scope.mdc` (allowlist par tâche)
- `.cursor/rules/human-gates.mdc` (gates si frozen zone touchée — ici aucune attendue)
- `CLAUDE.md` §6 architecture + §7 judgment standard

---

**Statut :** PLAN ÉCRIT. Phase A (4 audits parallèles) à lancer immédiatement.
