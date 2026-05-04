# FoodKing — Checklist accessibilité CV1 (WCAG 2.1 AA + EAA 2025)

| Champ | Valeur |
|---|---|
| Date | 2026-05-02 |
| Auteur | Claude |
| Niveau ciblé | WCAG 2.1 AA, escalation kiosk vers AAA via `data-kiosk-contrast="aaa"` |
| Norme | EAA (European Accessibility Act) — applicable au kiosk client final |
| Cycles couverts | CV1-CATALOG-CONVERGENCE-001, CV1-LIFECYCLE-UX-001 |
| Audit refs | `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md`, `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md` |

> Cette checklist liste, par **composant CV1**, les critères WCAG à vérifier lors de l'implémentation Codex. Chaque case cochée doit être confirmée par un sentinel automatisé (Vitest + Playwright + axe-core) ou par un protocole UAT manuel.

---

## 1. Critères transverses (s'appliquent à TOUS les composants CV1)

| # | Critère WCAG | Description | Sentinel automatisable |
|---|---|---|---|
| T1 | 1.4.3 (AA) | Contraste texte ≥ 4.5:1 ; titre ≥ 3:1 | `axe-core` rule `color-contrast` (Vitest + Playwright) |
| T2 | 1.4.6 (AAA) | Contraste texte ≥ 7:1 quand `data-kiosk-contrast="aaa"` | `axe-core` rule `color-contrast-enhanced` |
| T3 | 1.4.11 (AA) | Contraste éléments non-textuels ≥ 3:1 (focus rings, bordures actives) | Visual regression Playwright |
| T4 | 1.4.13 (AA) | Hover/focus content dismissible | Test clavier — Esc ferme l'overlay |
| T5 | 2.1.1 (A) | Toute interaction au clavier | Tab + Enter/Space sur tous les boutons |
| T6 | 2.1.2 (A) | Pas de piège clavier | Tab cycle complet sans coincer |
| T7 | 2.4.3 (A) | Ordre logique du focus | Vérifié visuellement + axe |
| T8 | 2.4.7 (AA) | Indicateur de focus visible | `:focus-visible` ring 3px + offset 2px (cv1-tokens.css) |
| T9 | 2.4.11 (AA) | Focus ne masque pas le contenu | Vérifier qu'aucun overlay sticky cache la zone focusée |
| T10 | 2.5.3 (A) | Label dans le name accessible | `aria-label` cohérent avec le texte visible |
| T11 | 2.5.5 (AAA) → AA EN 301 549 | Cible ≥ 24×24 px (V2 ≥ 44×44) | Vérification `data-testid` taille via Playwright |
| T12 | 3.2.2 (A) | Pas de soumission sur change | Aucune submission auto au focus/blur |
| T13 | 3.3.1 (A) | Erreurs identifiées | `role="alert"` + `aria-invalid` sur inputs invalides |
| T14 | 3.3.3 (AA) | Suggestions de correction | Message d'erreur explicite, pas seulement "champ invalide" |
| T15 | 4.1.2 (A) | Name, role, value programmatique | Tous les boutons ont un nom accessible |
| T16 | 4.1.3 (AA) | Status messages | Toasts avec `role="status"` + `aria-live="polite"` ou `assertive` |

**Sentinel transverse :** `tests/e2e/cv1-axe-sweep.spec.ts` qui exécute axe-core sur chacune des nouvelles routes admin et kiosk après injection des composants CV1.

---

## 2. ItemPreviewComponent.vue

| # | Critère | Statut squelette | À implémenter Codex (task 1.2) |
|---|---|---|---|
| IP1 | 1.4.3 contraste | ✅ tokens conformes | n/a |
| IP2 | 2.1.1 clavier | ✅ boutons natifs | Vérifier que select branch est navigable au clavier |
| IP3 | 2.4.6 entêtes hiérarchie | ✅ `<h3>` + `<h4>` | Garder la hiérarchie `<h2>` page → `<h3>` composant → `<h4>` carte |
| IP4 | 4.1.2 name accessible | ✅ `aria-labelledby="item-preview-title"` | Conserver |
| IP5 | 4.1.3 statuts | ✅ `aria-busy={loading}` | Émettre via lecteur d'écran le `parityWarning` |
| IP6 | 1.3.1 structure | ✅ `<section>`, `<article>`, `<header>` | Confirmer ordre de focus : title → branch select → refresh → POS card → Kiosk card |
| IP7 | 2.4.3 ordre focus | ✅ ordre DOM = ordre visuel | Tester avec lecteur d'écran à chaque rafraîchissement |
| IP8 | 1.4.11 contraste éléments | ✅ bordures via `--cv1-border-default` | Vérifier badge "available/unavailable" lisible sans la couleur (utiliser texte explicite) |

**Pièges :**
- L'avertissement de parité doit être annoncé dynamiquement → `aria-live="polite"` sur le bloc `parityWarning`.
- Quand `loading=true`, ne pas masquer le contenu précédent — afficher le spinner en overlay sans cacher les projections déjà chargées.

---

## 3. ComposerProfileWarningBadge.vue

| # | Critère | Statut squelette | À implémenter Codex (task 1.1, 1.4, 1.5) |
|---|---|---|---|
| WB1 | 4.1.3 statuts | ✅ `role="region"` + `aria-label` | **MUST** : si `severity=blocker`, escalader à `role="alert"` |
| WB2 | 1.3.1 structure | ✅ `<article>` par warning | Conserver |
| WB3 | 1.4.1 sans couleur seule | ⚠️ icône + texte présents | **MUST** : ne JAMAIS dépendre de la seule couleur du badge — texte explicite obligatoire |
| WB4 | 2.4.4 lien dans contexte | ✅ bouton avec libellé i18n | Codex : libellés différenciés par code (pas générique "Voir") |
| WB5 | 2.5.3 label dans le name | ✅ texte du `<button>` traduit | Conserver |
| WB6 | 4.1.2 name/role/value | ✅ `<button type="button">` | Conserver |
| WB7 | 3.2.2 pas de submission auto | ✅ click déclenche emit | Conserver |

**Pièges :**
- Plusieurs warnings côte-à-côte = plusieurs régions live → veiller à ne pas annoncer en cascade. Solution : un seul `aria-live` parent qui annonce le résumé "3 avertissements actifs" ; les badges individuels en `role="region"`.
- Le bouton "Dismiss" doit avoir un `aria-label` explicite (`$t('label.dismiss') + warning code`).

---

## 4. ProductCreateWizardComponent.vue

| # | Critère | Statut squelette | À implémenter Codex (task 2.9) |
|---|---|---|---|
| PW1 | 1.3.1 structure | ✅ `<nav aria-label="Wizard steps">` | Conserver |
| PW2 | 2.4.4 lien dans contexte | ✅ texte `i18n.steps.<key>` | Conserver |
| PW3 | 2.4.6 entêtes | ✅ `<h2>` titre wizard | Conserver |
| PW4 | 4.1.2 name/role/value | ✅ `aria-current="step"` sur le tab actif | **MUST** : changer aussi le focus visible quand l'étape change programmatiquement |
| PW5 | 2.4.7 focus visible | ✅ ring CV1 sur `:focus-visible` | Vérifier sur le stepper |
| PW6 | 4.1.3 statuts | ✅ `aria-live="polite"` sur progress | Annoncer "Étape 3 de 9" à chaque changement |
| PW7 | 2.5.3 label/name | ✅ libellés clairs Précédent/Suivant | Conserver |
| PW8 | 3.3.1 erreurs identifiées | ⚠️ TODO Codex | **MUST** : si validation backend échoue à `finishWizard`, afficher `role="alert"` + focus sur premier champ invalide |
| PW9 | 3.3.4 prévention erreurs | ⚠️ TODO Codex | **MUST** : ne jamais POST destructif sans confirmation explicite (cf. `<button type="button">` partout) |
| PW10 | 1.4.13 dismissible | ⚠️ TODO Codex | Si l'utilisateur ferme l'onglet, persistance localStorage 24h auto |

**Pièges :**
- Quand on passe à l'étape suivante, déplacer le focus sur le titre de la nouvelle étape (`<h3>` ou wrapper avec `tabindex="-1"` + `.focus()`), sinon les utilisateurs de lecteur d'écran perdent le contexte.
- Les étapes optionnelles doivent être annoncées comme telles (`Optional` dans le label, `aria-current="false"`).
- Le bouton "Suivant" désactivé doit avoir `aria-disabled="true"` plutôt que `disabled` si on veut quand même le focusable pour annoncer la raison.

---

## 5. CatalogChangeToastComponent.vue (Kiosk)

| # | Critère | Statut squelette | À implémenter Codex (task 1.3 + 2.3) |
|---|---|---|---|
| CT1 | 4.1.3 statuts | ✅ `role="status"` + `aria-live="polite"` | **MUST** : si `severity=warning`, escalader à `role="alert"` + `aria-live="assertive"` |
| CT2 | 1.4.13 dismissible | ✅ bouton fermer | **MUST** : raccourci Esc ferme aussi le toast |
| CT3 | 2.3.3 reduced motion | ✅ token CV1 motion neutralizable | Vérifier que `[data-kiosk-reduced-motion='true']` désactive bien l'animation slide |
| CT4 | 2.4.3 ordre focus | ⚠️ TODO Codex | Quand le toast s'ouvre, ne PAS voler le focus sauf si `severity=warning` |
| CT5 | 2.5.5 cible ≥ 24×24 | ⚠️ TODO Codex | Vérifier les boutons `kiosk-btn-icon` ≥ 44×44 px (kiosk = doigt) |
| CT6 | 3.3.1 erreurs identifiées | ✅ description du diff | **MUST** : message i18n explicite "X choix retirés" pas "Erreur" |
| CT7 | 1.4.3 contraste | ✅ tokens CV1 | Vérifier escalation AAA pour kiosk EAA |

**Pièges :**
- Sur kiosk borne, le toast est lu par la synthèse vocale (`useKioskSpeech.js`) — vérifier qu'il n'est pas annoncé deux fois (par l'aria-live et par la synthèse).
- En mode synthèse vocale active, le toast doit être verbalisé une seule fois (debounce le re-rendu identique pendant TOAST_TTL_MS).

---

## 6. StockRuptureDashboardComponent.vue

| # | Critère | Statut squelette | À implémenter Codex (task 2.1, 2.7) |
|---|---|---|---|
| SR1 | 1.3.1 structure | ✅ `<section>`, `<article>`, `<dl>` | Conserver |
| SR2 | 4.1.3 statuts | ✅ `aria-busy={loading}` | Annoncer `lastRunSummary` à chaque rafraîchissement |
| SR3 | 2.4.6 entêtes | ✅ `<h2>` page → `<h3>` cartes | Conserver |
| SR4 | 1.4.1 sans couleur seule | ⚠️ TODO Codex | Le badge `cron_enabled/cron_disabled` doit avoir un texte explicite (présent dans le squelette) |
| SR5 | 2.1.1 clavier | ✅ bouton run-now natif | Vérifier que le rafraîchissement automatique n'interrompt pas la navigation clavier |
| SR6 | 4.1.2 name accessible | ✅ libellés explicites | Conserver |

**Pièges :**
- Le polling `setInterval` ne doit JAMAIS rafraîchir si l'utilisateur est en train d'interagir (focus dans la liste). Mettre en pause via `document.activeElement` checks.
- Quand un nouvel item arrive en `currently_86`, l'annoncer via `aria-live="polite"` au niveau du conteneur.

---

## 7. Sentinels d'a11y attendus

| Sentinel | Type | Couvre |
|---|---|---|
| `tests/js/itemPreviewProjection.spec.js` | Vitest + axe | IP1-IP8 |
| `tests/js/itemShowComposerWarning.spec.js` | Vitest + axe | WB1-WB7 |
| `tests/js/productCreateWizardE2E.spec.js` | Vitest | PW1-PW10 (logique) |
| `tests/e2e/product-create-wizard.spec.ts` | Playwright + axe | PW1-PW10 (rendu + clavier complet) |
| `tests/js/kioskWizardCatalogChangedHandling.spec.js` | Vitest | CT1-CT7 |
| `tests/js/stockRuptureDashboard.spec.js` | Vitest | SR1-SR6 |
| `tests/e2e/cv1-axe-sweep.spec.ts` | Playwright + axe | Tous les T1-T16 transverses |

---

## 8. Protocoles UAT manuels (non automatisables)

À exécuter par un humain au moins une fois par cycle CV1 :

1. **Lecteur d'écran NVDA + Firefox** (Windows) — naviguer le wizard de bout en bout uniquement au clavier. Toutes les étapes doivent être annoncées et navigables.
2. **VoiceOver + Safari** (macOS) — idem sur le kiosk en mode portrait.
3. **Loupe Windows** zoom 200% — vérifier qu'aucun composant ne déborde ni ne perd l'ordre de focus.
4. **Daltonisme** — utiliser une extension comme "Stark" ou "Color Oracle" et vérifier que tous les warnings restent compréhensibles en monochrome (T11, WB3, SR4).
5. **Borne kiosk avec contraste AAA + reduced motion** — `data-kiosk-contrast="aaa"` + `data-kiosk-reduced-motion="true"` activés via le menu accessibilité kiosk. Vérifier visuellement le toast `CatalogChangeToastComponent`.

---

## 9. Référence WCAG abrégée (rappel)

- **A** = niveau minimum (obligatoire) ; **AA** = standard légal commun (obligatoire FoodKing) ; **AAA** = renforcé (kiosk EAA 2025).
- Les critères les plus critiques pour CV1 sont : `1.4.3`, `2.1.1`, `2.4.7`, `4.1.2`, `4.1.3`.
- EAA 2025 (Directive UE 2019/882) impose AA sur les bornes self-service à compter du 28 juin 2025. Le kiosk FoodKing est explicitement concerné.

---

**Fin checklist a11y CV1 WCAG AA.**
