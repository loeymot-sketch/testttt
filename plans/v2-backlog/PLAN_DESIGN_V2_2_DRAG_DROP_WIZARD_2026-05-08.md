# Plan Design — V2-2 — Drag-drop ingrédients wizard
**Date :** 2026-05-08 | **Wave :** Gamma G1 | **Type :** Feature V2 differenciation UX | **Status :** ⏸️ Plan-only — owner gate explicit requis (8 wizards frozen)

> **OWNER GATE EXPLICIT REQUIS** — 8 wizards kiosk frozen. Phase A POC executable greenfield ; Phase B+C requièrent gate.

## §1 — Vision

User drague ingrédients depuis "zone source" vers "zone burger" (visualisation layers). Pattern : Burger King app, McDonald's "Make it yours", Subway customizer.

**V2 (pas V1)** : touche zones owner-frozen, POC + intégration > 1j isolé, risk a11y drag-drop fragile, performance 5+ layers à valider sur kiosk hardware.

**Asset positif** : `vue-draggable-next@^2.2.1` **déjà installé** (`package.json:43`), utilisé dans `ItemCateogryListComponent.vue`. Aucune nouvelle dépendance.

## §2 — Composants impactés

| Composant | Path | Frozen | Impact |
|---|---|---|---|
| `KioskWizardComponent` | (1659 lignes) | 🔒 Owner | Intégration wrapper / slot |
| `KioskPosWizardComponent` | (36 lignes) | 🔒 Owner | Wrapper léger, importe wizard |
| `KioskCartComponent` | (couvert V1x-1/3/6) | 🔒 Owner | Display composition burger (item_extras déjà supporté) |
| `KioskBurgerBuilder` (NEW) | `resources/js/components/frontend/kiosk/builder/` | 🟢 Greenfield | Composant standalone wrapper |
| `kiosk-wizard.css` | `resources/css/` | 🟢 Modifiable additif | Animations drag, drop zones |

## §3 — Architecture

### 3.1 Pattern wrapper greenfield

```
KioskBurgerBuilder.vue (greenfield)
├── Source pool (drag origin) — [Cheese][Bacon][Salad]...
├── Burger preview (drop target — visual layers stack)
│   🍞 top bun
│   🥬 Salad / 🧀 Cheese / 🥩 Patty
│   🍞 bottom bun
└── Fallback : <KioskWizardComponent /> (frozen) tap-to-add classique
```

### 3.2 Stack
- **Drag** : VueDraggableNext (déjà installé) ou native HTML5 drag API
- **State** : composant local `data() { sourceIngredients, droppedIngredients }`
- **Events** : `@change` draggable → emit `update:item_extras` parent wizard
- **Touch+mouse** : VueDraggableNext support natif via Sortable.js
- **Visual** : Ghost CSS `:dragging`, drop zone surlignée `border: var(--kiosk-primary)`, snap-to-grid layer stack

### 3.3 Fallback a11y critical
Drag-drop fragile en accessibility (WCAG 2.1.1 Keyboard).
- Conserver tap-to-add classique en parallèle (toggle "Mode drag-drop" / "Mode classique")
- Mode classique = `KioskWizardComponent` original (frozen — non modifié)
- Persistance localStorage (`kiosk_wizard_mode = 'drag' | 'tap'`)
- Default : `'tap'` (zéro régression existants)

### 3.4 Feature flag
```js
const wizardMode = computed(() => {
  if (!featureFlags.kiosk_drag_drop_enabled) return 'tap';
  return localStorage.getItem('kiosk_wizard_mode') || 'tap';
});
```
Permet rollout progressif + rollback rapide.

## §4 — Phases d'exécution

### Phase A — POC standalone (greenfield, 2 jours-agent)
**Objectif** : prouver faisabilité sans toucher frozen.
- A1 : créer `KioskBurgerBuilder.vue` greenfield
- A2 : VueDraggableNext source pool + drop zone
- A3 : visual layers (z-index ingredients stack)
- A4 : touch test sur tablette physique
- A5 : a11y review : Tab impossible drag — implémenter alternative keyboard (Enter "lift", flèches "move", Enter "drop")
- A6 : Vitest mount + emit assertions
- A7 : route test `/kiosk/burger-builder-poc` (admin toggle)

### Phase B — Intégration wizard (gated, 2 jours-agent)
- B1 : **Owner gate** validation — autorisation modif `KioskWizardComponent`
- B2 : intégrer `<KioskBurgerBuilder>` via slot ou `<component :is>`
- B3 : event mapping drag-drop → wizard `item_extras` state
- B4 : fallback toggle UI (drag / tap)
- B5 : feature flag `kiosk_drag_drop_enabled` (config Laravel + localStorage user choice)
- B6 : tests Playwright `page.dragAndDrop(source, target)`

### Phase C — Polish + a11y validation (1-2 jours)
- C1 : axe-core full audit
- C2 : NVDA + VoiceOver run-through + alternative keyboard fonctionnelle
- C3 : performance audit 5 ingredients dragged simultaneously kiosk hardware
- C4 : visual polish (easings, ghost, drop zone feedback)
- C5 : i18n strings drag-drop tutorial / hints
- C6 : Documentation `docs/kiosk-burger-builder.md`

## §5 — Effort total

| Phase | Effort |
|---|---|
| A POC | 2 jours-agent |
| B Intégration (post-gate) | 2 jours-agent |
| C Polish + a11y | 1-2 jours-agent |
| **Total** | **5-6 jours-agent** |

Si POC Phase A invalidé (touch perf ratée) → arrêt à 2 jours.

## §6 — Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| Touch sensitivity tablette vs kiosk capacitive | High | Phase A test sur kiosk physique avant Phase B. Threshold drag-start ajustable |
| Performance 5+ layers | Medium | CSS transforms only, pas reflow. requestAnimationFrame move. Limit max 8 visibles |
| A11y drag-drop fragile | High WCAG 2.1.1 | Fallback tap obligatoire. Keyboard alternative Phase A. axe-core CI |
| Owner gate refusé post-POC | Medium sunk cost limited | Phase A greenfield ne touche pas frozen — sunk cost limité au composant standalone |
| Cart compatibility item_extras enrichis | Low | `getItemSelectionSummary` (cart 434) gère déjà array d'extras |
| RTL dir="rtl" drag direction | Medium | Tester en arabe. VueDraggableNext native support |
| Reduced motion | Low | `@media (prefers-reduced-motion: reduce)` (token déjà tokens.css 179-186) |

## §7 — Owner gate

8 wizard kiosk + POS frozen. **Phase A POC NE touche pas frozen-zones — exécutable sans gate.** Phase B + C requièrent gate explicit owner.

**Recommandation** : exécuter Phase A en greenfield, puis re-pitch owner avec POC live. Décision Phase B post-démo POC.

## §8 — Tests

### Vitest unit
- `tests/unit/KioskBurgerBuilder.test.js` — mount + mock ingredients, emit `update:extras` on drop, keyboard alternative working

### Playwright E2E
```js
test('drag ingredient to burger', async ({ page }) => {
  await page.goto('/kiosk/wizard/123');
  const ingredient = page.locator('[data-testid="ingredient-cheese"]');
  const dropZone = page.locator('[data-testid="burger-drop-zone"]');
  await ingredient.dragTo(dropZone);
  await expect(page.locator('[data-testid="burger-layer-cheese"]')).toBeVisible();
});
```

### Axe-core a11y
- `tests/a11y/burger-builder.test.js` : zéro nouvelle violation WCAG AA, keyboard nav alternative, ARIA 1.2 pattern

### Manual QA
- NVDA Windows screen reader run-through
- VoiceOver Mac
- Touch test kiosk hardware physique (Phase A end)

## §9 — GSTACK

THINK ✅ vision + asset audit · PLAN ✅ · BUILD ⏸️ Phase A greenfield → B gated → C polish · REVIEW ⏸️ per-phase anti-drift + a11y obligatoire · TEST ⏸️ Vitest + Playwright + axe + manual SR + manual touch · SHIP ⏸️ commits multiples (feat-kiosk-builder POC ; feat-kiosk-wizard integrate behind flag B ; feat-kiosk-builder a11y polish C) · REFLECT ⏸️ KPI engagement % users using drag mode

## §10 — Décisions owner anticipées

1. GO/NO-GO Phase A POC : greenfield, peu risk — recommandé GO direct
2. GO/NO-GO Phase B post-POC : decision après démo Phase A
3. Feature flag rollout 0% / 10% / 50% / 100% ou A/B test
4. Default mode drag (push innovation) vs tap (zéro régression)
5. i18n premium tutorial drag-drop overlay first-use ?
6. Voice differenciation V2-4 complémentaire ?

## §11 — Status

[ ] Pending Phase A go-ahead · [ ] Phase A delivered · [ ] Owner gate Phase B opened · [ ] Phase B integrated · [ ] Phase C polish closed feature shipped behind flag · [ ] Rollout 100% — cycle closed
