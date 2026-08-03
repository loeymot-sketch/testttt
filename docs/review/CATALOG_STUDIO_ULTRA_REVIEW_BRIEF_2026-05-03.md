# Ultra-Review — Catalog Studio Phase α (design + backend) — 2026-05-03

> **Audience** : Claude Opus 4.7 (via Claude extension Cursor ou `claude` terminal CLI)
> **Auteur** : Claude orchestrateur (in-session)
> **Mission** : audit indépendant — challenger les choix techniques avec rigueur, pointer les angles morts, donner un verdict double-PASS / REWORK / ESCALATE

---

## 0. Mission du reviewer

Tu es le **reviewer indépendant** de la Phase α du Catalog Studio FoodKing. Ton rôle :

1. **Challenger** chaque livrable contre les invariants FoodKing (§3) et les meilleures pratiques Laravel/Vue.
2. **Pointer** explicitement les angles morts non couverts par les sentinelles ou tests existants.
3. **Refuser de complaire** : si tu trouves un point faible, dis-le clairement, même si les tests passent.
4. **Donner un verdict opérable** : `PASS` / `REWORK` (avec liste précise des corrections) / `ESCALATE` (gate humain à ouvrir).
5. **Token discipline** : zéro effet négatif. Substance avant brièveté. Si tu trouves un risque, documente-le ; ne le tais pas pour économiser.

**Sortie attendue** : rapport markdown structuré (§9) écrit dans `reports/audit/ULTRA_REVIEW_CATALOG_STUDIO_PHASE_ALPHA_2026-05-03.md`.

---

## 1. Contexte FoodKing (rappel court — ne pas re-relire)

- Stack : Laravel 10 + Vue 3 + Vuex + Laravel Mix + Tailwind + Spatie Permission + Spatie Media Library + Sanctum.
- Architecture : POS (admin) + Kiosk (frontend autonome) + KDS + OSS + Centrale + Fiscal NF525.
- Multi-branche : `branch_id` est une donnée business ; isolation stricte requise sur **toutes** les queries / mutations multi-branche.
- Mémoire de cycle SSOT : `.cursor/ACTIVE_CYCLE.md` + `plans/` + `reports/execution/`.
- Phase actuelle : `HANDOFF_DESIGN_INTEGRATION` après clôture Phase α (4 sub-agents PASS + Claude Design v2 livré 17/17 angles).

---

## 2. Documents de fond à lire EN PREMIER (ordre obligatoire)

1. `AGENTS.md` — contrat opérateur (sections § *Parcours obligatoire*, § *Workflow*, § *Model Roles*).
2. `.cursor/rules/project-invariants.mdc` — 6 invariants FoodKing (pricing SSOT, OrderStatus, branch_id, dispatch-after-commit, OrderService symmetry, frozen zones).
3. `.cursor/ACTIVE_CYCLE.md` — état actuel du cycle (`CV1-V2-REMAINING-MISSIONS-001`, phase `HANDOFF_DESIGN_INTEGRATION`).
4. `reports/execution/RUN_CATALOG_STUDIO_FINAL_DESIGN_HANDOFF_2026-05-03.md` — synthèse Phase α (ce qui a été livré, par qui, avec quels résultats de tests).
5. `reports/audit/CATALOG_STUDIO_AUDIT_AND_REMEDIATION_PLAN_2026-05-03.md` — audit antérieur des 18 angles morts du design (référence des questions résolues / déférées).

**Ne pas relire** : tous les autres rapports antérieurs (cycles W4-W10, etc.) — sauf si l'audit en cours révèle une dépendance directe.

---

## 3. Invariants FoodKing à challenger explicitement

| # | Invariant | À vérifier sur la Phase α |
|---|---|---|
| I1 | Backend = SSOT pricing | Aucun calcul de prix côté Vue ajouté ; `ItemPhotoController` ne touche pas au prix |
| I2 | `OrderStatus` enum autoritaire | Hors scope direct, mais vérifier que `ComposerDiffService` ou Studio ne réintroduit aucun status string littéral |
| I3 | **`branch_id` = isolation business** | **POINT CHAUD** : `ItemPhotoController::store(Item $item)` n'a pas de check que l'admin authentifié a accès au `branch_id` de l'item. À challenger. |
| I4 | Dispatch après DB commit | `ComposerStepService::create/update/delete` dispatche `ComposerProfileChanged`. Vérifier que le dispatch est bien post-commit (trait `DispatchableAfterCommit`) |
| I5 | `OrderService` / `FrontendOrderService` symétrie | Hors scope (Phase α ne touche pas ces services) — confirmer dans le rapport |
| I6 | Frozen zones | Vérifier qu'aucun fichier sous `app/Services/Pricing/`, `app/Services/Order/Lifecycle/`, `app/Services/Fiscal/` n'a été modifié (non-FZ : `app/Services/Composer/`, `app/Http/Controllers/Admin/`) |

---

## 4. Périmètre exact à auditer — Phase α

### 4.1 Backend — α4 ComposerDiffService (`codex-extension` complex)

**Fichiers** :
- `app/Services/Composer/ComposerDiffService.php` (NEW, 282 LOC)
- `app/Http/Controllers/Admin/ComposerProfileController.php` (méthode `diff()` ajoutée — chercher)
- `routes/api.php` (route `POST /api/admin/composer/profiles/{profile}/diff` ajoutée — chercher)
- `tests/Feature/Composer/ComposerDiffServiceTest.php` (NEW)

**Tests** : 6/6 PASS local.

**Questions challenge prioritaires** :
- Q-α4-1. `projectPublishedProfile()` instancie un `Item` synthétique avec `setRawAttributes(['id' => …], true)` et `setRelation('variations'|'extras'|'addons', collect())`. **Est-ce un fallback dangereux ?** Si la projection a besoin des relations réelles (variations, extras, addons), elle produira un diff faux mais silencieux (pas d'exception). Risque : un user voit un diff vide alors qu'il y a vraiment des changements. **Proposer une solution** : forcer un snapshot à la publication (`published_steps_snapshot` dans `item_wizard_profiles`) plutôt que reprojeter.
- Q-α4-2. `comparable('position')` cast `(int) $value`. Si `before.position = null` et `after.position = 0`, `comparable` retourne `0` des deux côtés → **diff masqué**. Idem `min_select`, `max_select`. C'est un faux négatif possible. Vérifier si c'est intentionnel.
- Q-α4-3. `comparable('visible_on')` fait `sort($array)` puis return — c'est bien pour normaliser ['pos','kiosk'] vs ['kiosk','pos']. Mais si `null` vs `[]`, les deux retournent `[]` → diff masqué. Acceptable ?
- Q-α4-4. `COMPARED_FIELDS` whitelist 12 champs. Y a-t-il des champs `ItemWizardStep` sémantiquement importants **omis** ? (ex : `description`, `image_url`, `addon_default_quantity` si existe). Lis la migration `2026_04_27_143110_create_item_wizard_steps_table.php` pour cartographier les colonnes réelles.
- Q-α4-5. La route a-t-elle un middleware `permission:items_edit` ou équivalent ? Auth Sanctum suffit-il ? Risque : un user authentifié sans droit edit voit le diff de toutes les profiles.

### 4.2 Backend — α5-bis ItemPhotoUpload via Spatie (`codex-extension` complex, fallback après α5 bloqué)

**Fichiers** :
- `app/Http/Requests/ItemPhotoUploadRequest.php` (NEW)
- `app/Http/Controllers/Admin/ItemPhotoController.php` (NEW)
- `routes/api.php` (route `POST /api/admin/items/{item}/photo`)
- `tests/Feature/Items/ItemPhotoUploadTest.php` (NEW, 5 cas)

**Tests** : 5/5 PASS local.

**Questions challenge prioritaires** :
- Q-α5-1. **CRITICAL — branch_id isolation (I3)**. Le contrôleur fait `$item->clearMediaCollection('item')` et `$item->addMediaFromRequest('photo')` sans vérifier que `Auth::user()->branch_id === $item->branch_id` (ou que l'admin a un scope multi-branche). **Un admin de branche 2 peut-il polluer l'item de branche 3 ?** Lire `app/Http/Controllers/Admin/AdminController.php` (parent) pour voir si un middleware `branch:current` est appliqué globalement. Si non → **REWORK obligatoire**.
- Q-α5-2. Ordre `clearMediaCollection()` puis `addMediaFromRequest()` n'est **pas atomique**. Si le 2e échoue (disque plein, mime fail Spatie après FormRequest, conversions échouent), l'ancienne image est perdue sans remplacement. **Proposer** : add d'abord, supprimer les anciennes après succès, ou wrap dans une transaction filesystem (Spatie `TemporaryUpload`).
- Q-α5-3. Les `thumb_url` / `cover_url` / `preview_url` retournés — y a-t-il bien des **conversions Spatie configurées** sur `Item::registerMediaConversions()` pour ces 3 noms ? Sinon ce seront des chaînes vides et le frontend cassera silencieusement. Lire `app/Models/Item.php` pour confirmer.
- Q-α5-4. `mimes:jpg,jpeg,png,webp` — pas de **HEIC/HEIF iOS**, pas de **AVIF moderne**. C'est un choix UX. Acceptable pour V1 ? Documenter.
- Q-α5-5. Pas de **max_dimension** (image 8K x 8K passe si <4 Mo). Spatie va générer les conversions, ce qui peut OOM PHP. Recommander `max_image_dimension` ou un `dimensions:max_width=4000`.
- Q-α5-6. Le test `RefreshDatabase` + `Storage::fake('public')` — vérifier que la conf prod utilise bien le disque `public` ou un disque S3 ; si S3, le test ne couvre pas le chemin réel.

### 4.3 Backend — SOURCE-FK migration model-correction

**Fichiers** :
- `database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php` (NEW)
- `app/Models/ItemWizardStep.php` — `$fillable` + `$casts` modifiés
- `app/Services/Composer/ComposerStepService.php` — `resolveSourceItemAttributeId()` + dual-write dans `normalize()`
- `app/Http/Requests/ComposerStepRequest.php` — règle validation
- `app/Http/Requests/ComposerProfileRequest.php` — règle validation steps.*
- `app/Http/Resources/ComposerStepResource.php` — exposition API
- `tests/Feature/Composer/ComposerStepServiceContractTest.php` — 2 nouveaux tests dérivation

**Questions challenge prioritaires** :
- Q-SFK-1. **HUMAN GATE déjà cleared** : `docs/gates/GATE_CV1-WC-T-WC-SOURCE-FK-01_2026-05-03.md` (Option 2 staging only). Vérifier que la migration n'a **PAS** été lancée en prod (le runbook `scripts/migrate-source-fk-staging.sh` est-il bien staging-only ?).
- Q-SFK-2. `resolveSourceItemAttributeId()` (ComposerStepService:60-80) : la dérivation depuis `source_ref` (ctype_digit) est-elle **robuste** quand `source_ref = "  42  "` (espaces) ? Le `trim()` est appliqué, OK. Mais si `source_ref = "42abc"` ? `ctype_digit("42abc")` → false, OK. Mais si l'attribut n'existe pas (ID 42 deleted) ? Pas de check FK runtime ici. Le `foreignId('source_item_attribute_id')->constrained()->nullOnDelete()` couvre ce cas en BDD. Bien.
- Q-SFK-3. Backfill SQL dans la migration `up()` : peut-il bloquer ? Sur 100k+ rows en prod, la migration deviendra lente. Doc dit "staging only" pour V1. Vérifier que l'avertissement est dans le runbook.
- Q-SFK-4. Pas de migration `down()` de backfill (rollback laisse les données telles quelles si on drop la colonne). Acceptable car le drop suffit ; mais le confirmer.

### 4.4 Frontend Vue — Catalog Studio component (cycle CV1-V2-CATALOG-STUDIO-FIX-01/02 antérieur, mais Phase α a re-validé)

**Fichiers** :
- `resources/js/components/admin/items/CatalogStudioComponent.vue` (~700 LOC) — **central**
- `resources/js/router/modules/itemRoutes.js` — route `admin.items.studio`
- `resources/js/components/layouts/backend/BackendMenuComponent.vue` — menu redirigé
- `resources/js/config/v1-hidden-modules.js` — modules cachés
- `tests/js/catalogStudioRouting.spec.js` — sentinelles UI

**Questions challenge prioritaires** :
- Q-CS-1. Le composant utilise `<iframe>` pour embarquer `ProductComposerEditorComponent.vue` dans un drawer. Pourquoi iframe et pas mount Vue direct ? Risque : double app Vue, double Vuex store, double Pusher echo, double KdsSyncService. Lire `catalog-studio-composer-frame` dans le composant.
- Q-CS-2. `createProduct()` ajoute `order: this.nextItemOrder` et `tax_id` est nullable backend-side. Vérifier que ce comportement est cohérent avec `ItemListComponent.vue::createProduct` (le canal historique). Sinon dérive UX : Studio crée des produits différemment que la page legacy.
- Q-CS-3. La grille produit applique `filteredProducts` via `searchTerm`. Cas RTL (Arabic) : le filtre `String.toLowerCase()` casse-t-il sur l'arabe ? À tester.
- Q-CS-4. `data-testid` couvre catégories, produits, drawer composer, stock inline. Mais `catalog-studio-composer-open-full` (ouvrir en plein écran) — le bouton existe-t-il ? L'iframe est-elle navigable au clavier ? Tab piège ? Focus trap ?
- Q-CS-5. La sentinelle `tests/js/catalogStudioRouting.spec.js` couvre 9 assertions. Est-elle exhaustive ou superficielle ? Regarder ce qu'elle vérifie réellement.

### 4.5 Sentinelles + scripts ops — α1+α2+α3+α6 (routine)

**Fichiers** :
- `scripts/migrate-source-fk-staging.sh` (NEW, executable) — runbook humain staging
- `reports/execution/RUN_SOURCE_FK_STAGING_RUNBOOK_2026-05-03.md`
- `reports/execution/RUN_SOURCE_FK_BACKFILL_VERIFICATION_2026-05-03.md`
- `tests/js/studioTokensAdditions.spec.js` (NEW) — sentinelle tokens
- `tests/e2e/catalog-studio-a11y-axe.spec.js` (NEW) — sentinelle a11y

**Questions challenge prioritaires** :
- Q-OPS-1. Le script `migrate-source-fk-staging.sh` est-il **idempotent** (relancable sans casser) ? Que fait-il si la colonne existe déjà ?
- Q-OPS-2. Le rapport `RUN_SOURCE_FK_BACKFILL_VERIFICATION` contient-il les **3 critères Go/No-Go** clairs (count match, NULL = expected, integrity FK) ?
- Q-OPS-3. La sentinelle tokens vérifie-t-elle qu'aucune variable `--cv1-*` n'a été touchée (ajout/suppression/redéfinition) ? Ou seulement que `--studio-*` existe ? La 2e couvre moins.
- Q-OPS-4. La sentinelle a11y axe-core skippe si `@axe-core/playwright` absent. Acceptable pour V1 mais : **est-ce dans `package.json` devDependencies** ? Si non, la sentinelle est un faux test (toujours skip en CI).

### 4.6 E2E Playwright — critical-flow

**Fichier** : `tests/e2e/catalog-studio-create-product-flow.spec.js` (NEW, 1 PASS 12.2s local)

**Questions challenge prioritaires** :
- Q-E2E-1. Le spec **pivote sur produit existant** au lieu de créer un nouveau produit (la création échouait silencieusement sur env local — probable absence de tax seed). **C'est un trou de coverage** : la création produit end-to-end via `CatalogStudioComponent.createProduct()` n'est pas testée E2E. Recommander : ajouter un seeder dédié `E2ESeeder` qui garantit tax/branch défaut, puis remettre la création dans le spec.
- Q-E2E-2. Le spec ne vérifie pas que le **drawer composer (iframe)** charge réellement le composer ProductComposerEditor avec les bonnes données du produit cliqué. Il vérifie seulement `iframe visible`. Renforcer : `frame.getByText(itemName)` dans l'iframe pour confirmer le wiring.
- Q-E2E-3. Cleanup best-effort de la catégorie test — si le test passe à 50% (drawer ouvre, fail au close), la catégorie test reste en BDD locale. Acceptable pour CI éphémère, à documenter pour locale dev.

---

## 5. Caveats déjà identifiés (NE PAS re-flag)

Ces 3 caveats sont **déjà documentés** comme backlog β. Inutile de les pointer à nouveau :

1. Side-sheet ⑥ source-create de Claude Design = démo, à brancher Vue → β1
2. Police Noto Sans Arabic → β4 config `@font-face`
3. Keybindings J/K/D/⌫ → décision humaine équipe POS

---

## 6. Tests existants (pour cross-check)

Avant le verdict, **vérifier** que les tests passent localement :

```bash
# PHPUnit ciblé Phase α
php artisan test tests/Feature/Composer/ tests/Feature/Items/ItemPhotoUploadTest.php

# Vitest global
npm run vitest -- --run

# E2E sentinelles Studio (besoin Laravel up)
npx playwright test tests/e2e/catalog-studio-create-product-flow.spec.js
npx playwright test tests/e2e/catalog-studio-a11y-axe.spec.js
```

**Résultats locaux 2026-05-03 22:00 UTC+2** :
- PHPUnit Composer + Items : **50 passed / 0 failed / 2 skipped** (2 skipped pré-existants)
- Vitest global : **1054 passed / 0 failed / 2 skipped** (163 fichiers, +6 vs baseline 1048)
- Playwright critical-flow : **1 passed (12.2s)**
- Playwright a11y axe : **deps optionnel non installé → skip propre**

Si tu trouves un divergence avec ces chiffres : flag immédiat (régression introduite).

---

## 7. Design Claude Design v2 — fichiers à inspecter

**Localisation** : `/Users/1millnonstop/Downloads/gestion (1)/`

**12 fichiers livrés** :
- `Catalog Studio.html` (16 KB) — canvas pannable HTML
- `design-canvas.jsx` (31 KB) — 7 sections, 12 artboards initiaux
- `studio-iter1.jsx` (29 KB) — 5 artboards Critiques (drag&drop, branch overrides, image upload, diff modal, conflict)
- `studio-iter2.jsx` (38 KB) — 7 artboards Importants (source picker, autosave, empty/loading, toasts, permission, stock resolve, search)
- `studio-iter3.jsx` (36 KB) — 7 artboards Polish (cheatsheet, 1280px, RTL, onboarding, photos, micro-interactions, README v2)
- `studio-screen1/2/3.jsx` — composants par écran réutilisables
- `studio-data.jsx` (17 KB) — fixtures fidèles au schéma `ItemWizardStep`
- `studio-extras.jsx` (28 KB) — composants secondaires
- `tokens.css` (10 KB) — additions seules `--studio-*` (pas d'override CV1)
- `uploads/` — assets

**Questions challenge design** :
- Q-DSGN-1. Les fixtures `studio-data.jsx` utilisent-elles **exactement** les noms de champs `ItemWizardStep` (snake_case backend) ? Ou les renomment (camelCase) ? Si renomming : risque de friction à l'intégration Vue.
- Q-DSGN-2. `tokens.css` ajoute-t-il bien **uniquement** des `--studio-*` ? Pas de redéfinition `--cv1-*` ou `--brand-*` ou `--ks-*` ? `grep -c "^--cv1\|^--brand\|^--ks" tokens.css` doit retourner 0.
- Q-DSGN-3. Le diff modal Iter 1 ④ est-il **compatible** avec la sortie de `ComposerDiffService::diff()` (champs `added` / `removed` / `modified` avec `before` / `after` / `changed_fields`) ? Sinon : friction à β1.
- Q-DSGN-4. Le source picker Iter 2 ⑥ side-sheet — quels endpoints backend appelle-t-il (mockés ou réels) ? Cohérence avec `ItemAttributeController` / `ExtraGroupController` existants.
- Q-DSGN-5. La permission matrix Iter 2 ⑩ — les permissions sont-elles **alignées** avec `Spatie\Permission` (noms exacts : `items_edit`, `items_delete`, `composer_publish`, etc.) ? Sinon : matrice théorique non implémentable.

---

## 8. Questions transversales

- Q-T-1. Dispatch après commit (I4) : `ComposerStepService::create()` fait `$profile->steps()->create(...)` puis `$this->dispatchProfileChanged($profile->fresh(), ...)`. Le `dispatchProfileChanged()` utilise-t-il bien `DispatchableAfterCommit` ? Lire `app/Events/ComposerProfileChanged.php`.
- Q-T-2. Vue + Vuex : `CatalogStudioComponent` dispatche `item/save`, `itemCategory/save`, `item/reset`, `itemCategory/reset`. Les `reset` ont-ils été ajoutés dans cette Phase ou existaient-ils ? Vérifier `resources/js/store/modules/item.js`.
- Q-T-3. i18n : nouveau namespace `studio` dans `lang/{fr,en,de,bn,ar}/all.php`. Les 5 locales ont-elles **les mêmes clés** ? `diff` entre locales pour vérifier.
- Q-T-4. Routes : `routes/api.php` a 2 nouvelles routes (`/composer/profiles/{profile}/diff`, `/items/{item}/photo`). Sont-elles dans le bon middleware group (`auth:sanctum` + `branch:current` si applicable) ?
- Q-T-5. Cache : `ComposerProfileChanged` invalide-t-il le cache catalog (`menu_kiosk:*`, `pos_menu:*`) ? Si oui, le diff endpoint déclenche-t-il aussi cette invalidation ? Le diff est read-only mais s'il est utilisé pour publish, le wiring de la publication est-il symétrique ?

---

## 9. Format de rapport attendu

Écrire dans `reports/audit/ULTRA_REVIEW_CATALOG_STUDIO_PHASE_ALPHA_2026-05-03.md` avec exactement cette structure :

```markdown
# Ultra-Review — Catalog Studio Phase α — Verdict

| Champ | Valeur |
|---|---|
| Date audit | 2026-05-03 |
| Reviewer | Claude Opus 4.7 |
| Périmètre | Phase α (4 sub-agents + Claude Design v2 + sentinelles) |
| Verdict global | PASS / REWORK / ESCALATE |
| Score qualité (0-100) | ___ |

## 1. Verdict détaillé par sous-livrable

### α4 ComposerDiffService
- Statut : PASS / REWORK
- Points forts : ___
- Points faibles bloquants : ___
- Points faibles non-bloquants : ___
- Recommandations : ___

### α5-bis ItemPhotoUpload
(idem)

### SOURCE-FK migration
(idem)

### Frontend Vue Studio
(idem)

### Sentinelles α1+α2+α3+α6
(idem)

### E2E Playwright critical-flow
(idem)

### Claude Design v2
(idem)

## 2. Invariants FoodKing — table de conformité

| Invariant | Statut | Évidence | Risque résiduel |
|---|---|---|---|
| I1 Pricing SSOT | OK / VIOLATION | ___ | ___ |
| I2 OrderStatus enum | OK / N/A | ___ | ___ |
| I3 branch_id isolation | **OK / VIOLATION** | ___ | ___ |
| I4 Dispatch after commit | OK / VIOLATION | ___ | ___ |
| I5 OrderService symmetry | OK / N/A | ___ | ___ |
| I6 Frozen zones | OK / VIOLATION | ___ | ___ |

## 3. Risques résiduels (matrice)

| # | Risque | Sévérité (S0-S3) | Probabilité (P0-P3) | Mitigation proposée |
|---|---|---|---|---|
| R1 | ___ | S2 | P1 | ___ |
| ... | | | | |

## 4. Recommandations de Phase β

(liste ordonnée des items à intégrer en β1, β2, β3 — confirmer ou amender le backlog du rapport handoff §6)

## 5. Verdict final

(PASS / REWORK / ESCALATE — avec justification 3-5 lignes)

## 6. Si REWORK : plan correction précis

(liste des fichiers à modifier, par ordre de priorité)

## 7. Si ESCALATE : gate humain à ouvrir

(template `docs/gates/GATE_CV1-V2-CATALOG-ALPHA-REVIEW-XX_2026-05-XX.md` avec trigger précis)
```

---

## 10. Critères PASS / REWORK / ESCALATE

- **PASS** : 6/6 invariants OK, 0 risque S3, ≤ 2 risques S2 mitigés, tests reproduits localement, design fidèle au schéma.
- **REWORK** : ≥ 1 risque S2 non mitigé OU 1 invariant VIOLATION sans gate cleared OU divergence tests vs claim.
- **ESCALATE** : violation `branch_id` (I3) confirmée OU frozen zone touchée sans gate OU ambiguïté que le reviewer ne peut pas trancher seul.

---

## 11. Pour lancer l'ultra-review en terminal

Si tu veux exécuter la review via le wrapper Anthropic terminal (modèle Opus 4.7 high par défaut) :

```bash
# Depuis la racine du dépôt
bash scripts/foodking-claude-orchestrate.sh check
bash scripts/foodking-claude-orchestrate.sh context

# Audit non-interactif sur ce brief (output dans reports/audit/)
claude -p \
  --add-dir /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt \
  --add-dir "/Users/1millnonstop/Downloads/gestion (1)" \
  --model claude-opus-4-7 \
  --effort high \
  "$(cat docs/review/CATALOG_STUDIO_ULTRA_REVIEW_BRIEF_2026-05-03.md)" \
  > reports/audit/ULTRA_REVIEW_CATALOG_STUDIO_PHASE_ALPHA_2026-05-03.md
```

Si tu utilises l'extension Claude Code dans Cursor : ouvre une nouvelle conversation, colle le contenu de ce fichier (`docs/review/CATALOG_STUDIO_ULTRA_REVIEW_BRIEF_2026-05-03.md`) comme premier message, et laisse Claude lire les fichiers eux-mêmes via les outils.

---

**FIN DU BRIEF.** Procède maintenant à l'ultra-review. Substance avant brièveté. Pas de complaisance.
