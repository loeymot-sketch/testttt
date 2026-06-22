# PLAN — Catalog Studio Wizard Core 001 — Refonte UX wizard éditeur

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V2-CATALOG-WIZARD-CORE-001` |
| Date | 2026-05-04 01:40 UTC+2 |
| Précédent | `CV1-V2-CATALOG-VISION-CLEANUP-001` (CLOSED) |
| RUNNER_MODE | single-session |
| PHASE | EXECUTE |
| EXECUTION_TIER | complex |

---

## 0. TL;DR

Le wizard éditeur produit (cœur métier FoodKing) est techniquement câblé mais **inutilisable pour un restaurateur**. 5 problèmes graves :
1. **`Label.Refresh`** affiché brut (i18n leak résiduel sur `label.refresh`).
2. **Template Tacos → "PAGES 0"** (apply silencieux, pas de feedback erreur).
3. **Panel édition page = jargon technique** (Source / Min / Max sans contexte métier).
4. **Aperçu live ne montre que nom+prix** (pas les étapes du wizard).
5. **Sidebar "Articles" legacy** réapparaît dans certaines configurations BDD.

5 sub-agents (V+Y+X parallèles round 1, U+W séquence round 2 sur fichier partagé).

---

## 1. Diagnostic technique précis

### 1.a. Bug "Tacos → 0 pages" (P0)

```
Frontend: ProductComposerEditorComponent.vue:628-636
   applyTemplate() {
       axios.post('/admin/composer/items/' + this.itemId + '/apply-template', {
           template: ...,
           branch_id_scope: this.branchIdScope || undefined,
       }).then(response => {
           this.hydrateProfile(response.data?.data || null);  // SI null/undefined => steps=[]
       });
       // ❌ pas de .catch()
   }
```

Backend (correct, testé) : `ComposerProfileController::applyTemplate` L86-104 → `ComposerTemplateService::TEMPLATES['tacos']` L71-94 = 6 steps (taille, viande, sauce, garnitures, supplements, menu). Test `ComposerTemplateApplyTest` L38-50 confirme 6 steps en BDD.

**Causes possibles "PAGES 0"** :
- (a) HTTP error silencieux (CSRF, 401, 422) → catch absent → hydrate(null).
- (b) Réponse JSON mal parsée (wrapper `data.data` vs `data`).
- (c) User clique "Simple" (qui crée 0 step par design) au lieu de "Tacos".

### 1.b. `label.refresh` manquant (P0)

`ItemPreviewComponent.vue:56` : `$t('label.refresh')` → fr.json section `label` (L255-359) ne contient pas `refresh`. Vue-i18n retourne `"label.refresh"`, CSS `text-transform: capitalize` → `"Label.Refresh"`.

### 1.c. UX panel édition page

`ComposerStepFormPanel.vue` champs :

| Champ | i18n | Lignes | Aide |
|---|---|---|---|
| Nom de page | `label.composer.step_label` | 4-12 | non |
| Source (type) | `label.composer.source_type` | 17-29 | non |
| Choix disponibles | `label.composer.source_ref` | 32-50 | message vide L47-49 |
| Min select | `label.composer.min_select` | 53-67 | non |
| Max select | `label.composer.max_select` | 70-84 | non |
| Visible sur | `label.composer.visible_on` | 87-110 | non, "POS"/"Kiosk" en dur |
| Active | `label.composer.is_active` | 113-128 | non |

Rien n'explique métier-mode : "C'est quoi un attribut produit vs extra group vs addon ?", "Min=0 ça veut dire optionnel, Max=1 ça veut dire 1 seul choix possible".

### 1.d. Aperçu live anémique

`ItemPreviewComponent.vue` props : `{ item, branches }` — **pas** de `steps`. Fetch `GET /admin/menu-projection?channel=pos` puis affiche nom + catégorie + prix + dispo. Le composant `KioskWizardComponent.vue` existe (runtime borne) mais pas réutilisé.

### 1.e. Sidebar "Articles" parfois visible

`BackendMenuComponent.vue:145-152` : `enrichedVisibleMenus` n'injecte les `VIRTUAL_CHILDREN_BY_URL` (Catalogue Studio + Attributs) que si `menu.children` est vide. **Si la BDD `menus` a déjà des children pour `url='items'`** (config legacy), les virtual children sautent → vieux liens "Articles, Liste Produits..." restent visibles.

---

## 2. SUBSYSTEMS_TOUCHED

| Subsystem | Fichiers | Read/Write |
|---|---|---|
| **i18n leaks** | `resources/js/languages/{fr,en,de,bn,ar}.json` (ajouts `label.refresh` + clés manquantes scan) | WRITE |
| **Sentinel renforcé i18n** | `tests/js/labelKeyParityFrontend.spec.js` (NEW) ou extension existante | WRITE |
| **Template apply fix** | `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` méthode `applyTemplate` zone U | WRITE |
| **Callout guidance + UX labels** | idem fichier zone W (panel central quand 0 step + tooltips champs) | WRITE |
| **Tooltips ComposerStepFormPanel** | `resources/js/components/admin/items/composer/ComposerStepFormPanel.vue` | WRITE |
| **Aperçu live wizard réel** | `resources/js/components/admin/items/ItemPreviewComponent.vue` (étendre props + render des steps) | WRITE |
| **Sidebar items injection forcée** | `resources/js/components/layouts/backend/BackendMenuComponent.vue` (forcer virtual children même si BDD has children) | WRITE |
| **Tests Vitest** | nouveaux specs pour chaque fix | WRITE |

## SUBSYSTEMS_OFF_LIMITS

- Backend (`app/`, `database/`, `routes/`) — déjà correct, tout est frontend.
- Frozen zones.

## INVARIANTS_AT_RISK

- RAS (cycle frontend pur).

---

## 3. STRATÉGIE — 5 sub-agents 2 rounds

```
ROUND 1 (3 parallèles, zéro conflit) :
┌──────────────────────────────┐ ┌──────────────────────────────┐ ┌──────────────────────────────┐
│ V (complex)                  │ │ Y (routine)                  │ │ X (complex)                  │
│ i18n leaks                   │ │ Sidebar Articles forced      │ │ Aperçu live wizard réel      │
│ + scan label.* manquants     │ │ virtual injection            │ │ (steps render)               │
│ + sentinel renforcé          │ │                              │ │                              │
└──────────────────────────────┘ └──────────────────────────────┘ └──────────────────────────────┘

ROUND 2 (séquentiel sur ProductComposerEditorComponent.vue) :
┌──────────────────────────────┐ ┌──────────────────────────────┐
│ U (complex) AVANT W          │ │ W (complex) APRÈS U          │
│ Fix applyTemplate try/catch  │ │ Callout guidance 0 step      │
│ + diagnostic + alert error   │ │ + tooltips ComposerStepForm  │
└──────────────────────────────┘ └──────────────────────────────┘
                            │
                            ▼
                  AUDIT CONSOLIDÉ
                  Vitest + PHPUnit + Playwright + axe
```

---

## 4. Sub-agent V — i18n leaks (complex, parallèle)

Fix `label.refresh` + scan exhaustif `label.*` manquants dans tous les composer/items/* + sentinel auto-protectif.

Détaillé dans le brief sub-agent.

## 5. Sub-agent Y — Sidebar Articles forced injection (routine, parallèle)

Modifier `BackendMenuComponent.vue:145-152` pour **toujours injecter** les virtual children quand l'URL parente a des virtual children définis, même si la BDD a déjà des children. Préférer les virtual children (alignement V1) sur les children DB legacy.

## 6. Sub-agent X — Aperçu live wizard avec steps (complex, parallèle)

Étendre `ItemPreviewComponent.vue` pour recevoir `steps` en props et afficher la liste des étapes avec leur source résolue (ex: "Étape 1 — Choisir la viande — 4 options : Tournedos, Filet, Picanha, Bavette"). Pas de runtime complet, mais informatif et lisible.

## 7. Sub-agent U — Fix applyTemplate (complex, séquence après round 1)

Wrapper try/catch + alert d'erreur surfacée + log diagnostic + forcer reload via `loadProfile()` après `hydrateProfile`. Test Vitest qui mock un 422 et vérifie l'alert.

## 8. Sub-agent W — Callout guidance + tooltips (complex, séquence après U)

Ajouter callout dans le panel central quand `steps.length === 0` qui guide l'utilisateur. Ajouter tooltips métier sur les champs techniques de `ComposerStepFormPanel.vue`. Renommer labels Source/Choix disponibles en formulation métier.

---

## 9. Audit consolidé

1. `npx vitest run` → +X PASS.
2. `php artisan test tests/Feature/Composer/` → 0 régression.
3. `npm run dev` → rebuild.
4. `npx playwright test tests/e2e/catalog-studio-create-product-flow.spec.js` → critical-flow.
5. **Bonus** : Playwright qui tente d'appliquer un template Tacos et vérifie 6 pages créées (si le test passe sans erreur, c'est validé).

---

## 10. Mémoire post-cycle

À pousser :
1. `label.refresh` ajouté dans 5 langues + scan exhaustif `label.*`.
2. `applyTemplate` désormais avec try/catch + alert + diagnostic.
3. Aperçu live affiche les étapes du wizard (pas juste nom+prix).
4. Sidebar virtual children injectés systématiquement (immune aux children DB legacy).
5. Callout guidance + tooltips dans panel page édition.
