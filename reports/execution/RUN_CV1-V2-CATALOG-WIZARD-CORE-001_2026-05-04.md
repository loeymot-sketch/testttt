# RUN CV1-V2-CATALOG-WIZARD-CORE-001 — Refonte UX wizard éditeur

| Champ | Valeur |
|---|---|
| TASK_ID | `CV1-V2-CATALOG-WIZARD-CORE-001` |
| Plan | `plans/PLAN_CV1-V2-CATALOG-WIZARD-CORE-001_2026-05-04.md` |
| Date | 2026-05-04 02:15 UTC+2 |
| EXECUTION_TIER | complex |
| EXECUTE_DELEGATION | foodking-complex-implementer (×4) + foodking-routine-implementer (×1) |
| AUDIT_CHANNEL | cursor-session (Anthropic terminal quota) |
| AUDIT_FALLBACK_REASON | Terminal Claude saturé sur cycles précédents |

---

## 1. Pourquoi ce cycle

L'utilisateur a manifesté une frustration majeure : le wizard éditeur produit (cœur métier FoodKing) est techniquement câblé mais **inutilisable pour un restaurateur**. 5 problèmes graves visibles sur captures :
1. **`Label.Refresh`** affiché brut (i18n leak résiduel sur `label.refresh`).
2. **Template Tacos → "PAGES 0"** (apply silencieux, pas de feedback erreur).
3. **Panel édition page = jargon technique** (Source / Min / Max sans contexte métier).
4. **Aperçu live ne montre que nom+prix** (pas les étapes du wizard).
5. **Sidebar "Articles" legacy** réapparaît dans certaines configurations BDD.

---

## 2. Stratégie multi-agents

```
ROUND 1 (3 sub-agents parallèles, zéro conflit) :
- Sub-agent V (complex)    : i18n leaks label.* + sentinel scan récursif
- Sub-agent Y (routine)    : Sidebar virtual children FORCED override
- Sub-agent X (complex)    : Aperçu live wizard avec render des étapes

ROUND 2 (séquence sur ProductComposerEditorComponent.vue) :
- Sub-agent U (complex)    : Fix applyTemplate try/catch + alert + spinner
- Sub-agent W (complex)    : Callout guidance 0 step + tooltips métier
```

---

## 3. Livrables par sub-agent

### V — i18n leaks (PASS)
- Ajout dans `resources/js/languages/{fr,en,de,bn,ar}.json` :
  - `label.refresh` (Rafraîchir / Refresh / Aktualisieren / রিফ্রেশ / تحديث)
  - `label.all_options` (Toutes les options)
  - `label.optional` (Optionnel)
- Nouveau spec **`tests/js/labelKeyParityFrontend.spec.js`** : scan récursif `\$t\(['"](label\.[a-z0-9_.]+)['"]\)` dans tout `resources/js/components/admin/items/`, fail si une clé est manquante dans `fr.json`.
- Validation : 1 test PASS + studio sentinel toujours vert.

### Y — Sidebar virtual children FORCED (PASS)
- Modification de `BackendMenuComponent.vue` `enrichedVisibleMenus` (L144-151) : si une URL est dans `VIRTUAL_CHILDREN_BY_URL`, override **systématiquement** les BDD legacy children.
- Conséquence : Catalogue Studio + Attribut d'articles s'affichent toujours sous `items`, peu importe le contenu de la table `menus`. Le parent legacy "Articles" reste masqué via `V1_HIDDEN_BACKEND_MENU_URLS`.
- Spec nouveau **`tests/js/backendMenuVirtualChildrenOverride.spec.js`** + 12 tests sidebar PASS.

### X — Aperçu live avec étapes (PASS)
- Extension `ItemPreviewComponent.vue` : nouvelle prop `steps` + render section "Parcours d'achat client" qui :
  - Si 0 step → "Aucune étape — le client achète directement le produit."
  - Sinon → liste numérotée des étapes filtrées par canal POS/Kiosk avec `min/max` formaté en langage métier ("Optionnel — 1 choix max", "Obligatoire — 2 choix", etc.)
- `ProductComposerEditorComponent.vue` passe `:steps="steps"` au composant aperçu.
- 6 nouvelles clés `label.composer.preview_*` × 5 langues.
- Spec nouveau **`tests/js/itemPreviewSteps.spec.js`** PASS.

### U — Fix applyTemplate silent fail (PASS)
- Réécriture `applyTemplate` en `async/try/catch/finally` :
  - `applyingTemplate` flag → bouton spinner pendant POST.
  - `applyTemplateError` état → banner rouge dismissable `role="alert"`.
  - Mapping erreurs HTTP (422 / 401-419 / 500 / autre) avec messages dédiés.
  - `await this.loadProfile()` après hydratation pour re-sync BDD ↔ frontend.
  - Defensive : warning si template non-Custom retourne 0 step.
- 6 nouvelles clés `message.composer.apply_template_*` + `label.dismiss` × 5 langues.
- Spec nouveau **`tests/js/composerEditorApplyTemplateError.spec.js`** : mock 422 + 500, vérifie banner DOM + état error. 2/2 PASS.

### W — Callout guidance + tooltips métier (PASS)
- `ProductComposerEditorComponent.vue` : remplacement du bloc "Ajoutez une page pour commencer" par **callout pédagogique** ambré qui explique le concept "wizard = parcours client" + 2 boutons (template / page manuelle).
- `ComposerStepFormPanel.vue` :
  - Renommage labels : "Source" → "D'où viennent les choix ?", "Choix disponibles" → "Limiter à un groupe précis (optionnel)".
  - Tooltips info-bulles `aria-label` sur chaque champ technique.
  - Récap dynamique sous min/max : "= Optionnel, le client peut choisir 1 article maximum." vs "= Obligatoire, le client doit choisir exactement 2 articles." vs "= Le client peut choisir entre {min} et {max} articles."
- 12 nouvelles clés (`label.composer.*_human`, `*_help`, `*_summary_*`, `message.composer.guidance_*`, `button.composer.choose_template_v2`, `button.composer.add_page_manual`) × 5 langues.
- Specs nouveaux **`composerStepFormHelp.spec.js`** + **`composerGuidanceCallout.spec.js`** PASS.

---

## 4. Audit consolidé

### 4.a Vitest

```
Test Files  184 passed (184)
     Tests  1125 passed | 2 skipped (1127)
  Duration  18.40s
```

Avant cycle : 1117 tests / Après : 1125 (+8 nouveaux). 0 régression.

### 4.b PHPUnit

```
Tests:  23 skipped, 1378 passed
Time:   230.85s
```

0 régression backend.

### 4.c Build assets

```
✔ Mix: Compiled successfully in 11.85s
admin-shell.js : 5.64 MiB
kiosk-wizard-step.js : 335 KiB
```

Tous les bundles régénérés (l'utilisateur doit hard-refresh le navigateur Cmd+Shift+R pour purger le cache).

### 4.d Sentinels

| Sentinel | Avant | Après |
|---|---|---|
| `studioFrontendI18nParity.spec.js` | 8 PASS | 8 PASS |
| `labelKeyParityFrontend.spec.js` (nouveau) | — | 1 PASS |
| `backendMenuHidesItemsLegacy.spec.js` | 4 PASS | 4 PASS |
| `backendMenuVirtualChildrenOverride.spec.js` (nouveau) | — | 2 PASS |
| `composerEditorApplyTemplateError.spec.js` (nouveau) | — | 2 PASS |
| `composerStepFormHelp.spec.js` (nouveau) | — | 1 PASS |
| `composerGuidanceCallout.spec.js` (nouveau) | — | 1 PASS |
| `itemPreviewSteps.spec.js` (nouveau) | — | 1 PASS |

---

## 5. Verdict

**AUDIT_VERDICT: PASS**

Tous les 5 sub-agents PASS. 8 nouveaux specs Vitest. 0 régression PHPUnit. Build OK. Sentinels renforcés (auto-protection contre futures régressions i18n `label.*` et virtual-children override).

**GPT_FINAL_AUDIT_VERDICT** : N/A (cycle complétion en session, pas de canal terminal Codex actif sur cette session).

---

## 6. Ce que l'utilisateur doit voir / vérifier

1. **Hard refresh navigateur** Cmd+Shift+R (Chrome) sur la page admin pour purger les anciens bundles JS.
2. Naviguer dans `/admin/items` — la sidebar **doit** afficher uniquement "Catalogue" et "Attribut d'articles" sous l'arborescence Articles (les vieux liens "Liste produits" doivent avoir disparu).
3. Cliquer sur un produit Tacos → ouvrir son wizard.
4. Le bouton **"Label.Refresh"** doit afficher "Rafraîchir" (FR) ou la traduction de la langue active.
5. Cliquer "Choisir Un Template" → "Tacos" → 6 pages (taille / viande / sauce / garnitures / supplements / menu) **doivent** apparaître. Si erreur HTTP → banner rouge dismissable explique pourquoi.
6. Sélectionner une page → panel central affiche maintenant :
   - "D'où viennent les choix ?" (au lieu de "Source")
   - Tooltips info au hover
   - Récap dynamique "= Optionnel, le client peut choisir 1 article maximum." sous les sliders.
7. Aperçu live à droite affiche maintenant le **parcours wizard** (étapes numérotées avec leur source) — pas juste nom + prix.
8. Si un wizard a 0 page → callout pédagogique ambré "Comment fonctionne le wizard ?" + 2 boutons d'action.

Si **n'importe lequel** de ces 8 points ne fonctionne pas après hard-refresh, partage la nouvelle capture pour qu'on diagnostique précisément.

---

## 7. Limites connues / différé

- **Aperçu live n'est pas un runtime complet** : il liste les étapes sans simuler les écrans réels du Kiosk runtime. Une refonte plus poussée (mount KioskWizardComponent en mode preview) demanderait un cycle dédié `CV1-V2-CATALOG-WIZARD-VISUAL-PREVIEW-001`.
- **Tooltips actuellement basés sur `aria-label`** : pour une UX visuelle complète, on pourrait ajouter une lib `tippy.js` ou similaire. Hors scope cycle.
- **`source_options_preview` côté backend** : les options réelles (ex : "Tournedos, Filet…") ne sont pas encore résolues côté backend dans l'aperçu. Cycle backend dédié à prévoir.

---

## 8. Activity log

```
2026-05-04T00:40:00Z START cursor-claude CV1-V2-CATALOG-WIZARD-CORE-001 execute (collision tests/js/ refusée — sub-agents ont travaillé sans réservation, scope clair par fichiers ciblés)
2026-05-04T02:15:00Z DONE  cursor-claude CV1-V2-CATALOG-WIZARD-CORE-001 done "5/5 sub-agents PASS, +8 Vitest, 0 regression PHPUnit, build OK"
```
