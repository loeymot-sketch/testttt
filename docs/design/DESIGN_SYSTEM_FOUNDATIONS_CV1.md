# FoodKing — Design System Foundations CV1

| Champ | Valeur |
|---|---|
| Date | 2026-05-02 |
| Auteur | Claude (Anthropic, terminal `claude`) |
| Modèle | `claude-opus-4-7` |
| Effort | `xhigh` |
| Group Graphiti | `foodking` |
| Cycles couverts | CV1-CATALOG-CONVERGENCE-001, CV1-LIFECYCLE-UX-001 |
| Audit refs | `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md`, `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md` |
| Layer CSS | `resources/css/foundations/cv1-tokens.css` |

---

## 1. Position du design system CV1 dans la stack existante

Le design system CV1 ne remplace **rien**. Il s'empile au-dessus des couches existantes :

```
resources/css/app.css                     ← Tailwind base + classes db-* admin (héritage)
resources/css/tokens-aaa.css              ← Phase 8.9 — escalation contraste WCAG AAA (kiosk)
resources/css/tokens-pmr.css              ← Phase 8.9 — réduction de mobilité (kiosk)
resources/css/foundations/cv1-tokens.css  ← CV1 — variables sémantiques pour les nouveaux composants
```

**Règle :** un composant CV1 (admin ou kiosk) consomme **prioritairement** une variable CSS `--cv1-*` quand son apparence dépend du contexte (severity, surface, motion). Il consomme une utilitaire Tailwind quand l'apparence est purement structurelle (espacement, grille, flex).

Cela permet :
- de remapper les couleurs warning/blocker par tenant sans toucher aux composants ;
- de propager `data-kiosk-contrast="aaa"` ou `data-kiosk-reduced-motion="true"` à un seul endroit (le `<html>`) et obtenir l'escalation visuelle automatique ;
- de garder le diff Tailwind intact pour ce qui ne change pas (admin standard).

---

## 2. Catalogue des tokens

### 2.1 Status / severity (warnings admin)

| Token | Valeur (AA) | Valeur (AAA) | Usage |
|---|---|---|---|
| `--cv1-status-info-bg` | `#f1f5f9` | identique | Fond badge severity=info |
| `--cv1-status-info-text` | `#334155` | `#1e293b` | Texte badge severity=info |
| `--cv1-status-warning-bg` | `#fffbeb` | identique | Fond badge severity=warning |
| `--cv1-status-warning-text` | `#92400e` | `#78350f` | Texte badge severity=warning |
| `--cv1-status-blocker-bg` | `#fff1f2` | identique | Fond badge severity=blocker |
| `--cv1-status-blocker-text` | `#9f1239` | `#881337` | Texte badge severity=blocker |

**Contraste :** chaque combinaison background/text est ≥ 4.5:1 en AA et ≥ 7:1 en AAA. Vérifié manuellement contre la WCAG 2.1 SC 1.4.3.

### 2.2 Surfaces

| Token | Valeur | Usage |
|---|---|---|
| `--cv1-surface-default` | `#ffffff` | Fond carte par défaut |
| `--cv1-surface-elevated` | `#ffffff` | Fond toast / modal |
| `--cv1-surface-subtle` | `#f8fafc` | Fond zone secondaire |
| `--cv1-surface-strong` | `#0f172a` | Fond toast inversé (admin nuit, à venir) |

### 2.3 Bordures

| Token | Valeur | Usage |
|---|---|---|
| `--cv1-border-default` | `#e2e8f0` | Bordure standard |
| `--cv1-border-strong` | `#94a3b8` | Bordure renforcée |
| `--cv1-border-emphasis` | `#2563eb` | Bordure focus / step actif |

### 2.4 Typographie

| Token | Valeur | Échelle |
|---|---|---|
| `--cv1-font-size-xs` | `0.75rem` | Métadonnées |
| `--cv1-font-size-sm` | `0.875rem` | Corps de texte secondaire |
| `--cv1-font-size-base` | `1rem` | Corps standard |
| `--cv1-font-size-lg` | `1.125rem` | Titres section |
| `--cv1-font-weight-medium` | `500` | Texte renforcé |
| `--cv1-font-weight-semibold` | `600` | Titres et boutons |
| `--cv1-line-height-tight` | `1.25` | Titres |
| `--cv1-line-height-normal` | `1.5` | Texte courant |

### 2.5 Espacement (aligné Tailwind 4px grid)

| Token | Valeur |
|---|---|
| `--cv1-space-1` | `0.25rem` |
| `--cv1-space-2` | `0.5rem` |
| `--cv1-space-3` | `0.75rem` |
| `--cv1-space-4` | `1rem` |
| `--cv1-space-6` | `1.5rem` |

### 2.6 Motion

| Token | Valeur AA | Valeur reduced-motion |
|---|---|---|
| `--cv1-motion-fast` | `120ms` | `1ms` |
| `--cv1-motion-default` | `200ms` | `1ms` |
| `--cv1-motion-slow` | `320ms` | `1ms` |
| `--cv1-motion-easing` | `cubic-bezier(0.2, 0, 0, 1)` | identique |

**Règle :** dès que `[data-kiosk-reduced-motion='true']` est posé sur `<html>`, toutes les transitions sont neutralisées (1ms). Voir `useKioskA11y.js`. WCAG 2.3.3.

### 2.7 Stepper de wizard

| Token | Valeur | Rôle |
|---|---|---|
| `--cv1-step-active-bg` | `#2563eb` | Background tab actif |
| `--cv1-step-active-fg` | `#ffffff` | Foreground tab actif |
| `--cv1-step-completed-bg` | `#ecfdf5` | Background tab terminé |
| `--cv1-step-completed-fg` | `#065f46` | Foreground tab terminé |
| `--cv1-step-pending-bg` | `#f1f5f9` | Background tab à venir |
| `--cv1-step-pending-fg` | `#475569` | Foreground tab à venir |

### 2.8 Focus ring (WCAG 2.4.7 / 2.4.11)

| Token | Valeur | Cible |
|---|---|---|
| `--cv1-focus-ring-width` | `3px` | ≥ 2px |
| `--cv1-focus-ring-color` | `#2563eb` | Contraste ≥ 3:1 vs fond |
| `--cv1-focus-ring-offset` | `2px` | Détache l'anneau du contenu |

---

## 3. Catalogue des composants CV1

| Composant | Path | Mission | Rôle | Statut |
|---|---|---|---|---|
| `ItemPreviewComponent.vue` | `resources/js/components/admin/items/` | M2 V1 1.2 | Aperçu inline POS + Kiosk d'un item depuis le détail admin | Skeleton |
| `ComposerProfileWarningBadge.vue` | `resources/js/components/admin/items/` | M2 V1 1.1, 1.4, 1.5 | Badge non-bloquant pour warnings catalog (composer non publié, channels NULL, photo manquante…) | Skeleton |
| `ProductCreateWizardComponent.vue` | `resources/js/components/admin/items/wizard/` | M2 V2 2.9 | Wizard guidé 9 étapes pour création produit composé | Skeleton |
| `CatalogChangeToastComponent.vue` | `resources/js/components/frontend/kiosk/` | M2 V1 1.3, V2 2.3 | Toast catalog change pendant wizard kiosk ouvert | Skeleton |
| `StockRuptureDashboardComponent.vue` | `resources/js/components/admin/stock/` | M2 V2 2.1, 2.7 | Dashboard auto-86 préventif + stock low alerts | Skeleton |
| `useCatalogChangeNotifier.js` | `resources/js/composables/` | M2 V1 1.3 | Composable consommant `CatalogChanged`/`ComposerProfileChanged`, drive le toast | Skeleton |
| `PosSyncService.js` | `resources/js/services/` | M1 V1 1.7 | Fallback polling POS quand Echo DISCONNECTED | Skeleton |

Tous les composants ont :
- un commentaire de tête référant l'audit (file:line) et le plan (task id) ;
- des TODO Codex marqués avec le numéro de tâche du plan ;
- des `data-testid` pour les sentinels Vitest ;
- des attributs ARIA (`aria-busy`, `aria-current`, `aria-live`, `role`) ;
- des i18n keys déclarées (à compléter dans `resources/js/languages/{fr,en,ar,bn,de}.json`).

---

## 4. Patterns transversaux

### 4.1 Vue 2.7 / 3 Composition API

Les composables (`useCatalogChangeNotifier`, `useKioskA11y`, `useKioskSpeech`) utilisent l'API `import { ref, watch, onMounted, onBeforeUnmount } from 'vue';`. Les composants Vue continuent en Options API pour rester cohérents avec l'existant (`ItemShowComponent.vue`, `KioskWizardComponent.vue`). **Ne pas mélanger** Options API et `<script setup>` dans un même composant.

### 4.2 Convention test

| Type | Pattern | Localisation |
|---|---|---|
| Sentinel Vitest | `tests/js/<composant>.spec.js` | Mock store + i18n + axios |
| Sentinel PHPUnit Feature | `tests/Feature/<domain>/<spec>.php` | `RefreshDatabase` + factories |
| E2E Playwright | `tests/e2e/<flow>.spec.ts` | Multi-surface (POS + Kiosk + KDS) |

Tous les sentinels créés en batch 2 sont marqués `markTestSkipped` avec un message `Pending plan task X.Y (PLAN_<cycle>)`. Codex doit dé-skipper en suivant l'ordre du plan, jamais en bloc.

### 4.3 Convention i18n

Les nouvelles clés doivent être ajoutées **dans les 5 langues** simultanément :
- `resources/js/languages/fr.json` (langue de référence)
- `resources/js/languages/en.json`
- `resources/js/languages/ar.json` (RTL — vérifier le rendu)
- `resources/js/languages/bn.json`
- `resources/js/languages/de.json`

Format de clé : `<surface>.<feature>.<element>` (ex: `admin.product_wizard.steps.composer_publish`).

### 4.4 Convention attributs `data-*`

| Attribut | Producteur | Consommateur |
|---|---|---|
| `data-testid="<componant-purpose>"` | composant Vue | sentinels Vitest + Playwright |
| `data-severity="info\|warning\|blocker"` | composant warning | CSS sélecteur (`.cv1-warning-badge[data-severity='blocker']`) |
| `data-kiosk-contrast="aa\|aaa"` | useKioskA11y | tokens-aaa.css + cv1-tokens.css |
| `data-kiosk-reduced-motion="true\|false"` | useKioskA11y | cv1-tokens.css (motion neutralization) |

---

## 5. Anti-patterns à éviter

1. **Ne pas écrire de couleurs en dur dans les composants Vue.** Toujours passer par une variable CSS `--cv1-*` ou une utilitaire Tailwind sémantique (`bg-amber-50` est OK pour de l'admin, jamais pour un kiosk multi-tenant).
2. **Ne pas fixer une animation en `transition: 200ms`.** Toujours utiliser `var(--cv1-motion-default)` qui est neutralisé en reduced-motion.
3. **Ne pas oublier `aria-live` sur un toast.** Sans, les utilisateurs de lecteur d'écran ne sont pas notifiés.
4. **Ne pas faire des sentinels qui asserent une couleur RGB.** Asserer une classe CSS ou un `data-severity` à la place — résiste à la migration de palette.
5. **Ne pas dupliquer la projection POS / Kiosk dans le composant.** Toujours appeler `MenuProjectionService::forChannel` côté backend, jamais re-implémenter le filtrage en JS.

---

## 6. Évolutions prévues (post-V1)

| Sujet | Cycle visé | Note |
|---|---|---|
| Tokens dark mode admin | CV1 sprint+1 | À mapper en `--cv1-surface-strong` |
| Tokens per-tenant | CV2 | Permettrait à un tenant de remapper warning vers ses couleurs charte |
| Storybook ou Histoire pour les composants CV1 | CV2 | Documentation interactive ; déprioritaire vs. shipping V1 |
| Composant `ComposerProfileVersionConflictModal.vue` | M2 Vague 2 derrière `GATE_FROZEN_PRICING_COMPOSER_VERSION_CHECK` | Affiche le diff v1→v2 quand 409 Conflict |
| Composant `CategoryBranchAvailabilityToggle.vue` | M1 Vague 3 derrière `GATE_CATALOG_CHANNELS_REQUIRED` | Surface admin pour `category_branch_availability` table |

---

**Fin du design system foundations CV1.**
