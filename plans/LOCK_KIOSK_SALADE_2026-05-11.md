# LOCK_KIOSK_SALADE_2026-05-11 — Frozen-zone surgical patch

> Sub-agent : claude-opus-4-7 (orchestrator) · Owner : Kossay20
> Frozen-zone fichier : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
> Branche : `feature/mobile-app-le-cayenne-2026-05-10` · HEAD pre-patch `363173070`

## 1 · Scope

Modification **chirurgicale** d'un seul case dans le switch `wizard_template === 'salade'` du fichier frozen-zone `KioskWizardComponent.vue`, lignes 619-628.

**Avant** (6 steps obligatoires) :
```js
case 'salade':
  return [
    { type: 'garnitures', label: 'Garnitures', component: 'KioskStepGarnitures' },
    { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
    { type: 'menu', label: 'Menu', component: 'KioskStepMenu' },
    { type: 'frites_style', label: 'Style frites', component: 'KioskStepFritesStyle' },
    { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
    { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
  ].filter(s => this.shouldShowStep(s.type));
```

**Après** (3 steps optionnels + cascade) :
```js
case 'salade':
  // [Owner re-cadrage 2026-05-11] Salade simplifiée — composition de base
  // fixée par le nom (ex. Salade Royale = Laitue+Tomate+Maïs+Poulet+Olives).
  // Step "garnitures" supprimé (n'a pas de sens humain pour une salade composée).
  // Steps "sauce" et "supplements" deviennent optionnels (shouldShowStep filtre selon flags item).
  // Step "menu" + cascade frites_style/drink restent disponibles si item.has_menu_addon.
  return [
    { type: 'sauce', label: 'Sauce', component: 'KioskStepSauce' },
    { type: 'supplements', label: 'Suppléments', component: 'KioskStepSupplements' },
    { type: 'menu', label: 'Menu', component: 'KioskStepMenu' },
    { type: 'frites_style', label: 'Style frites', component: 'KioskStepFritesStyle' },
    { type: 'recap', label: 'Récap', component: 'KioskOrderSummary' }
  ].filter(s => this.shouldShowStep(s.type));
```

## 2 · Justification (owner gate cleared)

Owner statement explicite 2026-05-11 :
> « il y a une bêtise sur Kiosk qu'il faudra la corriger aussi les salades ça vient pas avec cinq tape possible »
> « il faudra vraiment un raisonnement, une vérification profonde »

Raisonnement humain validé :
1. **Salade Royale arrive déjà composée** : Laitue + Tomate + Maïs + Poulet + Olives. Pas besoin de step "garnitures" pour choisir des garnitures qui sont déjà imposées par le nom.
2. **Logique fast-food** : on choisit la salade par son nom, on ajoute éventuellement sauce, on ajoute éventuellement suppléments, on propose éventuellement de transformer en menu (frites+boisson).
3. **Mobile a déjà appliqué la version simplifiée** (commit `cbfea4fd7` D1 + commit pending Sprint A2 ajout menu addon). Kiosk doit s'aligner sur la même logique cross-surface.

## 3 · Fichiers touchés

| Fichier | Lignes | Type | Frozen-zone |
|---------|--------|------|-------------|
| `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` | 619-628 | Logic change | OUI ⚠️ |

**Aucun autre frozen-zone touché** (pas de FiscalSequenceService, BranchScope, PricingService, OrderStateMachine, KioskAppComponent, KioskUpsellComponent, pos-wizard.js).

## 4 · Anti-drift

- `shouldShowStep(type)` existe déjà dans KioskWizardComponent.vue (vérifier ligne ~410 ou similaire) — filtre selon flags item. Pas de modification de cette méthode.
- `KioskStepGarnitures.vue` reste utilisable par autres templates (mais aucun autre case ne l'utilise actuellement — c'était salade-only). Le composant peut rester dans `steps/` sans appel — pas de delete.
- Aucune migration DB, aucun seeder modifié.

## 5 · Test régression obligatoire

### PHPUnit
```bash
php artisan test --filter "Kiosk|Wizard|Salade" 2>&1 | tail -20
```
Expected : tests PHPUnit kiosk verts (705/705 cumulative iter14 doit rester vert).

### Playwright kiosk
```bash
PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/audit-mobile-wave-A-2026-05-11.spec.js --workers=1 2>&1 | tail -10
# + tout spec kiosk salade existant (à grep)
```
Expected : pas de régression sur les 16/16 E2E iter14 verts.

### Visual diff
- Re-capture `tests/e2e/__screenshots__/test-e2e-borne-A/*` (si salade dans la suite) → git diff.
- Verify : composition step 1 = Sauce (pas Garnitures) ; total steps réduit de 6 à ≤4.

## 6 · Rollback plan

```bash
git revert <commit-sha>
# OU manuel :
git show HEAD~1:resources/js/components/frontend/kiosk/KioskWizardComponent.vue > /tmp/restore.vue
# puis copier les lignes 619-628 d'origine
```

Aucune migration DB à rollback.

## 7 · Sub-agent execution rules

- ✅ READ `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` ligne 619-628 + contexte ±20 lignes
- ✅ EDIT seulement le case `'salade':` du switch (10 lignes max)
- ✅ COMMIT avec message explicite mentionnant LOCK_KIOSK_SALADE_2026-05-11
- ❌ NE PAS toucher autre case du switch
- ❌ NE PAS toucher shouldShowStep()
- ❌ NE PAS toucher autres frozen-zones
- ❌ NE PAS modifier package.json / config kiosk / migrations

## 8 · Human gate sign-off

| Gate | Owner | Status |
|------|-------|--------|
| Owner authorization to touch frozen-zone | Kossay20 | ✅ cleared 2026-05-11 (explicit verbal) |
| Visual regression review | Pending | Post-fix Playwright capture review |
| PHPUnit regression review | Pending | Post-fix `php artisan test` run |
| Final verdict | Owner | Post-E2E |

## 9 · Acceptance criteria

- ✅ Salade flow kiosk : Sauce (opt) → Suppléments (opt) → Menu (opt) → [cascade] → Recap (max 4 visible steps)
- ✅ Step "Garnitures" n'apparaît plus pour salade
- ✅ PHPUnit kiosk filter green
- ✅ Playwright kiosk green
- ✅ Visual screenshot kiosk salade flow shows ≤4 steps in stepper
- ✅ Pas de régression sur autres wizard templates (tacos/sandwich/burger/assiette/omelette/snacking/simple)

## 10 · Post-execution

- Update PROJECT_BRAIN.md §3 documenting frozen-zone touch
- Update CLAUDE.md §7 frozen-zones if scope changes (NO change expected)
- Graphiti episode : "Kiosk salade simplified — 6 steps → 3+cascade (LOCK_KIOSK_SALADE_2026-05-11)"
