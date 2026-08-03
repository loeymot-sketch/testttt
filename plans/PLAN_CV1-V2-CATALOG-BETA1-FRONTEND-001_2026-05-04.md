# PLAN — Catalog Studio Phase β1 Frontend Integration

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V2-CATALOG-BETA1-FRONTEND-001` |
| Date | 2026-05-04 00:00 UTC+2 |
| Auteur | Claude (orchestrateur — décisions techniques déléguées par utilisateur) |
| Précédents | `CV1-V2-CATALOG-REWORK-001` (round 1 PASS) + `CV1-V2-CATALOG-REWORK-002` (CLOSED, ItemWizardStepVersion ready) |
| RUNNER_MODE | single-session |
| PHASE | EXECUTE |
| EXECUTION_TIER | complex (multi-sub-agents, nouveaux composants Vue, intégration multi-fichiers) |
| EXECUTE_DELEGATION | sub-agents `foodking-complex-implementer` (H, I, J) + `foodking-routine-implementer` (L) parallèles |
| PRIMARY_EXECUTION_MODEL | mixed |
| REASONING_EFFORT | high |

---

## 0. TL;DR mission

Câbler côté Vue les 3 blocs C3/C4/C5 que le backend a livrés sur les cycles précédents (atomic photo upload + ComposerDiffService réel + 409 version conflict), plus permissions wiring et i18n parité. **C2 (branch overrides matrice)** explicitement reporté à V2 (cycle backend dédié requis). **C1 (drag&drop)** déjà câblé en production.

Résultat attendu : Phase β1 livrée à 80% (4/5 blocs), Catalog Studio plug-and-play opérationnel, tests verts (PHPUnit, Vitest, Playwright critical-flow + a11y axe).

---

## 1. État entrée

| Bloc β1 | Backend | Frontend | Action ce cycle |
|---|---|---|---|
| C1 drag&drop reorder | RAS (PATCH position) | ✅ VueDraggableNext câblé | aucune (smoke test seulement) |
| C2 branch overrides | ❌ table `item_wizard_step_branch_overrides` inexistante | ❌ | **DEFERRED V2** (cycle backend dédié) |
| C3 image upload 5 états | ✅ `POST /api/admin/items/{item}/photo` atomic | ❌ partiel | **LIVRER ce cycle** |
| C4 diff modal | ✅ `GET /api/admin/composer/profiles/{id}/diff` réel | ❌ | **LIVRER ce cycle** |
| C5 conflict 409 | ✅ `ComposerProfileService::update()` abort(409) | ❌ | **LIVRER ce cycle** |
| Permissions matrix | ✅ doc `STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md` | ⚠️ partiel (`appService.permissionChecker`) | **WIRE ce cycle** |
| i18n parité | ✅ test PHPUnit `StudioKeyParityTest` | ⚠️ JSON frontend `studio.*` à compléter | **AJOUT clés ce cycle** |

---

## 2. SUBSYSTEMS_TOUCHED

| Subsystem | Files | Read/Write |
|---|---|---|
| **Composer Editor** | `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` | WRITE (saveDraft + publish + diff trigger + 409 banner + photo) |
| **Diff Modal** (NEW) | `resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue` | WRITE (NEW) |
| **Conflict Banner** (NEW) | `resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue` | WRITE (NEW) |
| **Photo Upload** (NEW) | `resources/js/components/admin/items/ItemPhotoUpload.vue` | WRITE (NEW) |
| **Catalog Studio** | `resources/js/components/admin/items/CatalogStudioComponent.vue` | WRITE (intégrer ItemPhotoUpload + permissions wire-up) |
| **i18n** | `resources/js/languages/{fr,en,de,bn,ar}.json` | WRITE (ajouter clés studio.composer.diff.*, studio.composer.conflict.*, studio.image.*) |
| **Tests Vitest** | `tests/js/composerPublishDiffModal.spec.js`, `tests/js/composerVersionConflictBanner.spec.js`, `tests/js/itemPhotoUpload.spec.js`, `tests/js/studioFrontendI18nParity.spec.js` | WRITE (NEW) |
| **Playwright** | `tests/e2e/catalog-studio-publish-flow.spec.js` (NEW critical-flow extension) | WRITE (NEW) |

## SUBSYSTEMS_OFF_LIMITS

- `app/` (backend déjà consolidé) — read-only
- `database/migrations/` — read-only
- `resources/js/store/` — read-only sauf si nécessaire (lock)
- Toute zone frozen (`app/Services/Pricing`, `app/Services/Order/Lifecycle`, `app/Services/Fiscal`)
- `lang/{fr,en,de,bn,ar}/all.php` (déjà parité PHPUnit, ne pas re-toucher)
- `package.json` (sauf si nouvelle lib indispensable, qui requerrait gate)

## GATE_CONDITIONS

- Aucun gate anticipé (Schema migration, auth, frozen non touchés).
- C2 deferred → ouvrir cycle backend séparé `CV1-V2-CATALOG-BRANCH-OVERRIDES-001` quand utilisateur le décide. Pas un gate du cycle β1, juste une note de scope.

## INVARIANTS_AT_RISK

- **I1 Backend pricing SSOT** : RAS, aucune logique de prix côté Vue, on lit/affiche juste.
- **I2 OrderStatus enum** : N/A (catalog).
- **I3 branch_id** : RAS (lecture des steps via API qui filtre déjà côté backend).
- **I4 Dispatch after commit** : N/A (pas de modif backend).
- **I5 OrderService symmetry** : N/A.
- **I6 Frozen zones** : RAS.

---

## 3. STRATÉGIE — 4 sub-agents parallèles

```
┌────────────────┐ ┌────────────────┐ ┌────────────────┐ ┌────────────────┐
│ H (complex)    │ │ I (complex)    │ │ J (complex)    │ │ L (routine)    │
│ Diff Modal     │ │ 409 Conflict   │ │ Image Upload   │ │ i18n + permis. │
│ + intégration  │ │ + version track│ │ 5 états        │ │ wiring         │
└────────────────┘ └────────────────┘ └────────────────┘ └────────────────┘
        │                  │                  │                  │
        └──────────────────┴──────────────────┴──────────────────┘
                                   │
                                   ▼
                        AUDIT CONSOLIDÉ (ce cycle)
                        Vitest + PHPUnit + Playwright + axe
                                   │
                       ┌───────────┴───────────┐
                       │                       │
                   PASS │                   REWORK
                       │                       │
                  Rapport CLOSE         Plan correction
                                        Round 2 (max 5)
```

**Conflits potentiels** : H et I touchent `ProductComposerEditorComponent.vue` (multiplexé). J touche aussi `CatalogStudioComponent.vue`. Pour éviter les conflits de write parallèles, je sépare les sections du fichier dans le périmètre de chaque sub-agent :

- **H** ajoute : (1) bouton "Voir le diff" + import du modal + state pour ouvrir/fermer + handler `onDiffClick()`. (2) écoute événement `@confirm-publish` du modal qui déclenche `publish()` existant.
- **I** modifie : (1) `data().version`, (2) `loadProfile()` stocke version, (3) `saveDraft()` envoie version + handler 409, (4) banner conditionnel en haut, (5) bouton publish disabled.
- **J** ajoute : (1) section header avec `<ItemPhotoUpload>` au-dessus du nom du produit dans l'éditeur. (2) remplace `<input type="file">` dans CatalogStudioComponent quick-create.
- **L** modifie : (1) JSON langues, (2) ajoute `v-if="canX"` ou `disabled="!canX"` sur quelques boutons existants en lisant `appService.permissionChecker`.

→ **Stratégie pour ProductComposerEditorComponent** : H et I se réservent **DEUX zones différentes du fichier**. Je vais leur donner des "marker comments" explicites pour ne pas se marcher dessus :
- Zone H : header (avant le titre du produit) + bouton à côté du Publier dans le footer
- Zone I : tout en haut (banner sticky) + data().version + 409 handler dans saveDraft

→ **Lancer H + I en séquence** est plus sûr que parallèle pour ce fichier. **J et L** peuvent tourner en parallèle de tout.

**Plan séquencement final** :

```
Étape 1 : Lancer J + L en parallèle (zéro conflit)
Étape 2 : Lancer H seul (Diff Modal + intégration ProductComposerEditorComponent zone H)
Étape 3 : Lancer I seul (409 conflict + intégration zone I + saveDraft)
Étape 4 : Audit consolidé
```

Étape 1 et 2/3 séparées : ~5-8 min total au lieu de ~3-4 min en full parallèle, mais sans risque de conflit de merge sur le même fichier.

---

## 4. Sub-agent J — Lot 3 : Image Upload 5 états (complex)

**Mission** : créer `ItemPhotoUpload.vue` (5 états), l'intégrer dans `CatalogStudioComponent.vue` quick-create, et préparer la slot pour intégration dans le composer (sub-agent J ne touche PAS ProductComposerEditorComponent.vue).

**Livrables** :
1. **Composant** `resources/js/components/admin/items/ItemPhotoUpload.vue` (NEW) :
   - Props : `itemId` (Number, required), `currentImage` (String, optional URL).
   - Émissions : `@upload-success` (avec URL nouvelle image), `@upload-error` (avec message).
   - 5 états visuels :
     - **idle** : zone grise pointillée + icône camera + texte "Glisser une image ou cliquer"
     - **drag-over** : zone surlignée bleue + texte "Déposer maintenant"
     - **uploading** : progress bar 0-100 + spinner + texte "Upload en cours…"
     - **success** : tick vert 1500ms + auto-revert idle ou affichage thumbnail
     - **error** : bordure rouge + message + bouton "Réessayer"
   - Drag&drop natif HTML5 (`@dragover.prevent`, `@drop.prevent`).
   - Click-to-upload fallback via `<input type="file" accept="image/jpg,image/jpeg,image/png,image/webp" max-size validation côté client (4 Mo).
   - Validation client miroir backend : mimes (jpg/jpeg/png/webp), max 4 Mo. Si invalide → état error sans appel HTTP.
   - API : `POST /api/admin/items/{itemId}/photo` avec `FormData` champ `photo`. axios interceptor projet déjà gère CSRF/401.
   - Fallback ladder (si pas de photo et pas d'image dans les props) : SVG cat icon → initiale du nom (V2, juste structure pour l'instant + commentaire).
2. **Intégration `CatalogStudioComponent.vue`** :
   - Remplacer le `<input type="file">` actuel du quick-create produit (ligne ~94-96 + handler associé) par `<ItemPhotoUpload>` qui se déclenche **après** la création de l'item (parce que l'API photo a besoin de `itemId`).
   - Pattern recommandé : bouton "Ajouter une photo" qui apparaît seulement après `createProduct()` réussi (montre `<ItemPhotoUpload :item-id="lastCreatedItemId">`). Sinon, conserver le flux "image envoyée avec l'item" via FormData multipart sur le `POST /admin/items` original — choisir l'option qui demande le moins de refactor backend (probablement option 2 : garder le flux actuel pour la quick-create, et n'utiliser `<ItemPhotoUpload>` que dans l'éditeur composer ouvert sur un item existant).
   - **Décision claire** : sub-agent J doit choisir **option 2** par défaut (moins de refactor) et documenter le choix dans le rapport. Le composant `<ItemPhotoUpload>` sera utilisé surtout par sub-agent H/J's intégration composer (à voir).
3. **Tests** `tests/js/itemPhotoUpload.spec.js` (NEW) :
   - test_renders_idle_state_initially
   - test_drag_over_class_applied_on_dragover
   - test_uploading_state_during_axios_call (mock axios)
   - test_success_emits_upload_success_with_url
   - test_error_state_on_axios_failure_with_retry_button
   - test_validation_oversize_file_rejected_client_side_no_http_call
   - test_validation_invalid_mime_rejected_client_side
4. **Rapport** `reports/execution/RUN_BETA1_C3_IMAGE_UPLOAD_2026-05-04.md`.

**Périmètre allowlist** :
- `resources/js/components/admin/items/ItemPhotoUpload.vue` (NEW)
- `resources/js/components/admin/items/CatalogStudioComponent.vue` (intégration légère, ne pas refactorer le reste)
- `tests/js/itemPhotoUpload.spec.js` (NEW)
- `reports/execution/RUN_BETA1_C3_IMAGE_UPLOAD_2026-05-04.md` (NEW)

**PASS** : 7/7 tests + 0 régression Vitest + composant utilisable depuis le studio.

---

## 5. Sub-agent L — Lot 5 : i18n + Permissions wiring (routine)

**Mission** : compléter clés i18n manquantes pour β1 (5 langues), ajouter sentinel parité frontend, et brancher `appService.permissionChecker` sur 3-4 boutons critiques de `CatalogStudioComponent`.

**Livrables** :
1. **i18n** : ajouter dans `resources/js/languages/{fr,en,de,bn,ar}.json` la section `studio` (si absente) ou compléter avec :
   - `studio.composer.diff.title` ("Différences à publier" / "Pending changes" / etc.)
   - `studio.composer.diff.added` ("Ajouté" / "Added")
   - `studio.composer.diff.removed` ("Supprimé" / "Removed")
   - `studio.composer.diff.modified` ("Modifié" / "Modified")
   - `studio.composer.diff.no_changes` ("Aucun changement à publier" / "No pending changes")
   - `studio.composer.diff.confirm_publish` ("Publier maintenant" / "Publish now")
   - `studio.composer.diff.cancel` ("Annuler" / "Cancel")
   - `studio.composer.conflict.title` ("Conflit de version" / "Version conflict")
   - `studio.composer.conflict.message` ("Un autre utilisateur a modifié ce wizard. Recharge pour voir les changements." / etc.)
   - `studio.composer.conflict.reload` ("Recharger" / "Reload")
   - `studio.image.upload_idle` ("Glisser une image ou cliquer" / "Drag image or click")
   - `studio.image.upload_drag_over` ("Déposer maintenant" / "Drop now")
   - `studio.image.upload_uploading` ("Upload en cours…" / "Uploading…")
   - `studio.image.upload_success` ("Image téléversée" / "Uploaded")
   - `studio.image.upload_error` ("Erreur upload" / "Upload error")
   - `studio.image.upload_retry` ("Réessayer" / "Retry")
   - `studio.image.upload_invalid_mime` ("Format non supporté (JPG/PNG/WEBP)" / etc.)
   - `studio.image.upload_oversize` ("Image trop lourde (max 4 Mo)" / etc.)
2. **Sentinel parité frontend** `tests/js/studioFrontendI18nParity.spec.js` (NEW) :
   - Vérifie que tous les fichiers `resources/js/languages/{fr,en,de,bn,ar}.json` ont la même profondeur de clés sous `studio.*`.
   - Si une clé manque dans une langue, fail.
3. **Permissions wiring** `resources/js/components/admin/items/CatalogStudioComponent.vue` :
   - Vérifier les boutons "Créer catégorie", "Créer produit", "Configurer le wizard", "Supprimer" : tous doivent utiliser `v-if="permissions.itemsCreate"` etc. (les `computed` existent déjà ligne ~244-265, juste les utiliser dans le template si pas déjà).
   - **Lire** d'abord le fichier pour voir si c'est déjà fait. Si oui, juste rapport "RAS, déjà câblé".
4. **Rapport** `reports/execution/RUN_BETA1_I18N_PERMISSIONS_2026-05-04.md`.

**Périmètre allowlist** :
- `resources/js/languages/fr.json` (write)
- `resources/js/languages/en.json` (write)
- `resources/js/languages/de.json` (write)
- `resources/js/languages/bn.json` (write)
- `resources/js/languages/ar.json` (write)
- `resources/js/components/admin/items/CatalogStudioComponent.vue` (write léger, permissions wiring uniquement, ne pas refactorer)
- `tests/js/studioFrontendI18nParity.spec.js` (NEW)
- `reports/execution/RUN_BETA1_I18N_PERMISSIONS_2026-05-04.md` (NEW)

**PASS** : sentinel parité PASS + 0 régression Vitest.

---

## 6. Sub-agent H — Lot 4 : Diff Modal Vue (complex)

**Mission** : créer `ComposerPublishDiffModal.vue` qui consomme `GET /admin/composer/profiles/{id}/diff`, et l'intégrer dans `ProductComposerEditorComponent.vue` (zone H seulement).

**Livrables** :
1. **Composant** `resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue` (NEW) :
   - Props : `profileId` (Number, required), `isOpen` (Boolean, v-model).
   - Émissions : `@close`, `@confirm-publish`.
   - On open : `axios.get('/admin/composer/profiles/' + profileId + '/diff')`, stocke `diffPayload = { is_clean, added, removed, modified }`.
   - Render :
     - Si `is_clean` → message "Aucun changement à publier" + bouton "Fermer".
     - Sinon → 3 sections empilées : Added (vert), Removed (rouge), Modified (orange). Chaque entry montre `step_key`, et pour Modified affiche les `changed_fields` (key: old_value → new_value si fourni par le backend, sinon juste la liste des champs).
     - Footer : bouton "Annuler" (`@close`) + bouton "Publier maintenant" (`@confirm-publish`).
   - Loading state : skeleton pendant fetch.
   - Error state : message + bouton "Réessayer".
2. **Intégration `ProductComposerEditorComponent.vue` zone H** :
   - Importer `<ComposerPublishDiffModal>`.
   - Ajouter `data().diffModalOpen = false`.
   - Ajouter bouton "Voir le diff" à côté de "Publier" dans le footer (`admin-composer-publish` zone) avec `@click="diffModalOpen = true"`.
   - Insérer `<ComposerPublishDiffModal v-model:isOpen="diffModalOpen" :profileId="profile.id" @confirm-publish="publish()" />` dans le template.
   - **Ne pas toucher** la méthode `publish()` existante (sub-agent I s'en occupe pour le 409).
3. **Tests** `tests/js/composerPublishDiffModal.spec.js` (NEW) :
   - test_modal_fetches_diff_on_open
   - test_modal_shows_no_changes_when_is_clean_true
   - test_modal_shows_added_removed_modified_sections
   - test_confirm_publish_emits_event
   - test_cancel_closes_modal
   - test_error_state_shows_retry_button
4. **Rapport** `reports/execution/RUN_BETA1_C4_DIFF_MODAL_2026-05-04.md`.

**Périmètre allowlist** :
- `resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue` (NEW)
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` (write **zone H seulement** : data().diffModalOpen + import + bouton + slot modal — **ne pas toucher** `data().version`, `loadProfile()`, `saveDraft()`, `publish()` méthode interne, banner — c'est zone I)
- `tests/js/composerPublishDiffModal.spec.js` (NEW)
- `reports/execution/RUN_BETA1_C4_DIFF_MODAL_2026-05-04.md` (NEW)

**PASS** : 6/6 tests + 0 régression + diff visible end-to-end.

---

## 7. Sub-agent I — Lot 5 : 409 Conflict Banner + Version tracking (complex)

**Mission** : tracker la `version` du profil côté Vue, l'envoyer dans `saveDraft()`, gérer le 409 avec banner sticky et bouton Publier disabled.

**Livrables** :
1. **Composant** `resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue` (NEW) :
   - Props : `isVisible` (Boolean), `currentVersion` (Number), `expectedVersion` (Number).
   - Émissions : `@reload`.
   - Render : banner rouge sticky en haut, icône warning, texte "Conflit de version : un autre utilisateur a modifié ce wizard. Recharge pour voir les changements.", bouton "Recharger" (`@click="$emit('reload')"`).
   - i18n keys : `studio.composer.conflict.*` (sub-agent L doit les avoir ajoutées avant).
2. **Modifications `ProductComposerEditorComponent.vue` zone I** :
   - `data().version = 0` (Number).
   - `data().conflictDetected = false`.
   - `loadProfile()` → après réception : `this.version = response.data.version`.
   - `saveDraft()` → envoyer `version: this.version` dans le payload PATCH.
   - `saveDraft()` catch 409 :
     ```js
     if (error.response?.status === 409) {
         this.conflictDetected = true;
     }
     ```
   - Bouton Publier (`admin-composer-publish`) → ajouter `:disabled="conflictDetected || isPublishing"`.
   - Insérer `<ComposerVersionConflictBanner :is-visible="conflictDetected" :current-version="version" :expected-version="error.response.data.expected" @reload="reloadProfile" />` en haut du template (avant le titre).
   - Méthode `reloadProfile()` → relance `loadProfile()` + reset `conflictDetected = false`.
3. **Tests** `tests/js/composerVersionConflictBanner.spec.js` (NEW) :
   - test_banner_hidden_when_not_visible
   - test_banner_visible_with_message
   - test_reload_emits_event
4. **Tests intégration `ProductComposerEditorComponent`** : ajouter cas dans `tests/js/productComposerEditor.spec.js` ou `composerEditorV2.spec.js` (**lecture seule sur ces fichiers ajout uniquement test, pas refactor**) :
   - test_save_draft_sends_version_field
   - test_409_response_sets_conflict_detected_and_disables_publish
   - test_reload_clears_conflict_state
5. **Rapport** `reports/execution/RUN_BETA1_C5_CONFLICT_BANNER_2026-05-04.md`.

**Périmètre allowlist** :
- `resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue` (NEW)
- `resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue` (write **zone I seulement** — coordonner avec sub-agent H)
- `tests/js/composerVersionConflictBanner.spec.js` (NEW)
- `tests/js/productComposerEditor.spec.js` ou `composerEditorV2.spec.js` (write append-only nouveaux tests)
- `reports/execution/RUN_BETA1_C5_CONFLICT_BANNER_2026-05-04.md` (NEW)

**PASS** : tests PASS + bouton Publier disabled visible quand 409 + reload route fonctionnelle.

---

## 8. Audit consolidé (orchestrateur)

Après E2E des 4 sub-agents :
1. `npm run dev` (rebuild bundles).
2. `npm run vitest -- --run` (suite globale).
3. `php artisan test tests/Feature/Composer/ tests/Feature/Items/ tests/Feature/I18n/` (suite backend non régression).
4. `npx playwright test tests/e2e/catalog-studio-create-product-flow.spec.js` (existant).
5. `npx playwright test tests/e2e/catalog-studio-a11y-axe.spec.js` (a11y).
6. **Si pertinent** : créer `tests/e2e/catalog-studio-publish-flow.spec.js` (NEW) qui exerce le path complet : login → studio → produit existant → composer → modifier un step → ouvrir Diff Modal → confirmer → assert publish reussi → vérifier ItemWizardStepVersion en BDD via API ou re-publish. À voir selon le temps.
7. Rédaction `reports/execution/RUN_CV1_V2_CATALOG_BETA1_FRONTEND_001_2026-05-04.md`.

**CLOSE** :
- Vitest 1054+X PASS / 2 SKIP
- PHPUnit 76 (65+9+2) PASS, 0 régression
- Playwright critical-flow PASS
- a11y axe PASS (ou skip documenté)
- Aucun bug bloquant remonté.

**REWORK** : 1+ sub-agent FAIL → plan correction localisé, max 5 rounds.

---

## 9. Hors scope explicite (DEFERRED V2)

- **C2 branch overrides matrice** : nécessite cycle backend dédié (`CV1-V2-CATALOG-BRANCH-OVERRIDES-001`) — création table + service de résolution + projection. Ne pas implémenter de stub UI ce cycle. Trace dans le rapport final.
- Onboarding tooltips, Cmd+/ cheatsheet, dark mode (Iter 3 polish Claude Design) — V2.
- Photos de fallback (SVG cat / initiale ladder) : structure prête mais SVG assets V2.
- Variant 1280px responsive : V2.

---

## 10. Mémoire post-cycle (Graphiti)

À pousser à la fermeture :
- Phase β1 frontend livrée (4/5 blocs : C3, C4, C5, permissions/i18n).
- C1 confirmé déjà câblé (VueDraggableNext).
- C2 deferred V2 — cycle backend dédié requis.
- Composants nouveaux : ComposerPublishDiffModal, ComposerVersionConflictBanner, ItemPhotoUpload.
- Pattern Vue 3 utilisé : Options API (par cohérence avec le reste du repo).

