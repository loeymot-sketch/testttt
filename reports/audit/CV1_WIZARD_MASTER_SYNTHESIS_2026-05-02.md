# Master Synthesis — Wizard Composable Admin V1
## CV1-WIZARD-COMPOSABLE-001 — 2026-05-02

**État :** REMPLI après 4 audits parallèles A.1-A.4 (sub-agents `explore` very-thorough).

---

## 1. Verdict consolidé

🟡 **GO_WITH_HEAVY_WORK** — backend complet mais admin UI **non plug-and-play** (3/10 user-friendly), traçabilité IDs **partielle**, runtime kiosk **piloté par heuristiques string-match**.

| Axe | Verdict | Score | Blockers principaux |
|---|---|---|---|
| A.1 — Admin composer UI | PARTIEL | 30/100 | `ProductComposerEditorComponent` orienté dev (`source_ref` brut, pas de picker, pas de drag&drop, pas de preview, libellés trompeurs « Publier brouillon ») ; `ProductCreateWizardComponent` SKELETON ; module Vuex `composer.js` non enregistré |
| A.2 — Stock-Product-Wizard ID traceability | PARTIEL | 55/100 | `item_wizard_steps.source_ref` sans FK ; `stock_levels` polymorphique sans FK ; resolution par nom (pas que par ID) ; rupture addon ≠ rupture variation/extra (paths différents) |
| A.3 — Workflow admin | FRICTIONS_MAJEURES | 35/100 | Catégories cachées sous Settings ; pas de route `/admin/items/create` ; pas de bouton "Configurer wizard" sur ligne ; permission `catalog.compose` séparée ; pas de guidage post-création |
| A.4 — Kiosk pages decomposition | OUI_PARTIEL | 60/100 | `composerStepType` heuristique sous-chaîne fragile ; 7 step components spécialisés lisent `item` (pas `choices` projection) ; seul `KioskStepGenericChoicesComponent` est piloté par admin ; `step_key='dessert'` → `null` (étape droppée si pas dans heuristique) |

---

## 2. Top 10 blockers V1 (P0 → P2)

| # | Blocker | Source | Tier | ID candidat |
|---|---|---|---|---|
| 1 | Pas de bouton "Configurer wizard" sur la ligne produit | A.3 #4 | routine S | `T-WC-LIST-01` |
| 2 | Pas de route `/admin/items/create` dédiée | A.3 #3 | routine S | `T-WC-CREATE-URL-01` |
| 3 | Catégories+Attributs+Extras cachés sous Settings (workflow brisé) | A.3 #1+#2 | routine M | `T-WC-MENU-CATALOG-01` |
| 4 | Composer UI sans picker, sans drag&drop, sans preview live | A.1 F2+F3+F7 | **complex L (XL)** | `T-WC-EDITOR-01` |
| 5 | Pas de guidage post-création (ouvrir wizard) | A.1 + A.3 #8 | routine S | `T-WC-AFTER-CREATE-01` |
| 6 | `source_ref` String non FK ⇒ orphelins possibles | A.2 #1 | complex M (migration + service) | `T-WC-SOURCE-FK-01` |
| 7 | `composerStepType` heuristique fragile ⇒ step_key arbitraire droppé | A.4 #1 | complex M | `T-WC-KIOSK-REGISTRY-01` |
| 8 | Permission `catalog.compose` séparée sans message clair | A.3 #5 | routine S | `T-WC-PERM-01` |
| 9 | Module Vuex `composer.js` mort (non registré) | A.1 §5 | routine S | `T-WC-VUEX-COMPOSER-01` |
| 10 | Templates wizard pré-remplis au create | §0 user demand | routine M | `T-WC-TEMPLATES-01` |

---

## 3. Plan Phase C — Implémentation (lots parallélisables)

### Lot C.1 — UX surface (4 tâches routine, parallèles)

| ID | Titre | Source | Effort |
|---|---|---|---|
| `T-WC-LIST-01` | `ItemListComponent` : ajouter colonne badge "wizard configuré: oui/non" + bouton "Configurer wizard" par ligne (lien `/admin/items/show/:id/composer`) | A.3 #4 | S |
| `T-WC-CREATE-URL-01` | Ajouter route `/admin/items/create` qui redirige vers `/admin/items?create=1` ou ouvre directement le drawer | A.3 #3 | S |
| `T-WC-AFTER-CREATE-01` | Après save dans `ItemCreateComponent`, proposer "Ouvrir la fiche" + "Configurer le wizard" via toast/modal | A.3 #8 | S |
| `T-WC-PERM-01` | Améliorer message d'erreur quand permission `catalog.compose` manquante (au lieu d'exception générique) | A.3 #5 | S |

### Lot C.2 — Templates + sourcing (2 tâches routine, parallèles)

| ID | Titre | Source | Effort |
|---|---|---|---|
| `T-WC-TEMPLATES-01` | Au create produit, choix template `simple/sandwich/tacos/assiette/snacking/menu/custom` qui pré-remplit les steps via API `POST /api/admin/composer/items/{id}/profile` avec template body | §0 user | M |
| `T-WC-SOURCE-PICKER-01` | Endpoint `GET /api/admin/composer/items/{id}/available-sources` + UI dropdown qui remplace le `source_ref` brut par picker labeled (variations / extras / addons / attributs) | A.1 F3 + A.2 #1 | M (back+front) |

### Lot C.3 — Éditeur composable (1 tâche complex XL, séquentielle)

| ID | Titre | Source | Effort |
|---|---|---|---|
| `T-WC-EDITOR-01` | Refonte complète `ProductComposerEditorComponent.vue` : header contexte produit (nom + catégorie + photo), liste pages drag&drop (`vue-draggable-next`), pickers source par type, sliders min/max, checkboxes visibilité POS/Kiosk, preview live `ItemPreviewComponent` embedded sidebar, bouton "Publier" clair vs "Sauver brouillon" | A.1 F1+F2+F3+F4+F6+F7 + A.4 | **XL** |

### Lot C.4 — Cleanup & menu (1 tâche routine M)

| ID | Titre | Source | Effort |
|---|---|---|---|
| `T-WC-MENU-CATALOG-01` | Réorganiser le menu admin : sous-section "Catalogue" sous "Items" qui regroupe Catégories + Attributs + Extras (si page) + Addons (si page). Retirer ces entrées de Settings ou créer un raccourci en double. + nettoyer module Vuex `composer.js` (registrer ou retirer) | A.3 #1+#6 + A.1 §5 | M |

---

## 4. Plan Phase D — Synchro POS+Kiosk+Stock (3 tâches)

| ID | Titre | Source | Effort | Tier |
|---|---|---|---|---|
| `T-WC-POS-RUNTIME-01` | Refactor `public/js/pos-wizard.js` pour lire `composer_profile.steps` et construire pages dynamiquement (rendu monolithique single-page accordéon) | Audit Axe 4 + §0 caisse | **XL** | complex |
| `T-WC-KIOSK-REGISTRY-01` | Remplacer `composerStepType` heuristique par registre explicite `step_kind → component` ; étendre `KioskStepGenericChoicesComponent` pour absorber les step_keys arbitraires (ex: `dessert`) avec `choices` projection | A.4 #1+§5 | complex M | complex |
| `T-WC-STOCK-PROPAGATION-01` | Aligner propagation rupture addon : émettre `CatalogChanged` (refetch complet) ou patcher `composer_profile.choices` directement dans `kioskMenu` mutator. Sentinel `WizardOptionStockSyncTest` | A.2 #4 (path B) + A.4 §4 | complex M | complex |

---

## 5. Plan Phase E — Audit-loop + sentinels + tests

| ID | Titre |
|---|---|
| `T-WC-AUDIT-LOOP-1` | Cross-référence Phase C résultats vs A.1-A.4 ; détecter lacunes restantes |
| `T-WC-AUDIT-LOOP-2` | Validation finale ; verdict GO/REWORK |
| `T-WC-SENTINELS-GLOBAL` | Suite globale PHPUnit + Vitest ; 0 régression |

---

## 6. Tier-routing récapitulatif

- **Routine S/M (Composer ou generalPurpose)** : `T-WC-LIST-01`, `T-WC-CREATE-URL-01`, `T-WC-AFTER-CREATE-01`, `T-WC-PERM-01`, `T-WC-TEMPLATES-01`, `T-WC-SOURCE-PICKER-01`, `T-WC-MENU-CATALOG-01`, `T-WC-VUEX-COMPOSER-01`
- **Complex L+XL (Codex Pro / fallback)** : `T-WC-EDITOR-01`, `T-WC-POS-RUNTIME-01`, `T-WC-KIOSK-REGISTRY-01`, `T-WC-STOCK-PROPAGATION-01`, `T-WC-SOURCE-FK-01`

---

## 7. Engagement non-régression

Pendant tout ce travail :
- **AUCUN** flip de flag `catalog_v15.unified_projection.enabled` en prod
- **AUCUNE** modification du runtime POS / Kiosk **actuellement fonctionnel** (sauf via flag opt-in pour `T-WC-POS-RUNTIME-01`)
- **AUCUNE** modification des frozen zones
- Tests verts à chaque commit (post-execute hook actif)

---

## 8. Risques & garde-fous

| Risque | Mitigation |
|---|---|
| `T-WC-EDITOR-01` casse existant | Feature flag `composer_editor_v2.enabled` ; route alternative `/admin/items/show/:id/composer-v2` |
| `T-WC-POS-RUNTIME-01` casse pos-wizard.js | Feature flag `pos_wizard_composer_aware.enabled` ; fallback automatique vers code legacy |
| `T-WC-SOURCE-FK-01` casse migrations | Phased migration (backfill puis ALTER) avec gate humain ; compatible legacy data |
| Race git index sub-agents parallèles | Lot C.1 sub-agents staggered + check `git diff --cached` avant commit |

---

**Statut :** SYNTHÈSE COMPLÈTE. Phase C lancée immédiatement après ce document.
