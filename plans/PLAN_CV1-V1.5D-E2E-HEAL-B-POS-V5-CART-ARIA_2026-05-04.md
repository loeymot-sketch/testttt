# PLAN — CV1-V1.5D-E2E-HEAL-B-POS-V5-CART-ARIA

**Date** : 2026-05-04
**Auteur** : Claude (PLAN)
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-B-POS-V5-CART-ARIA`
**PRIMARY_EXECUTION_MODEL** : `gpt-5.5-pro` (Codex extension)
**REASONING_EFFORT** : `xhigh`
**EXECUTION_TIER** : `complex` — surface **POS** (sensible UX/caisse), risque de régression `data-testid`, mémoire DOM lourde des sentinelles existantes.
**PLAN_REVIEW** : pending GPT-5.5-pro.
**EXECUTE_DELEGATION** : `codex-extension`.

---

## 1. Contexte (rapport source)

`reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md` §3 ligne 5 ; log §`5)` (lignes ~380-430).

Échec : `tests/e2e/design/pos/d2-pos-design-audit.spec.js:27` — **6× violations axe `aria-required-children`** **critical** sur :

```html
<div data-v-e79a74ec="" class="pos-v5-cart__body flex-1 min-h-0 overflow-y-auto thin-scrolling" role="list">
```

→ Le `role="list"` impose des **enfants directs** `role="listitem"` non trouvés par axe (probablement parce que les enfants sont des `<article>` / wrappers Vue avec un autre rôle implicite ou aucun).

## 2. SUBSYSTEMS_TOUCHED

| Sous-système | Fichier(s) | Intent |
|---|---|---|
| POS V5 cart | Composant Vue avec data-v-`e79a74ec` (à localiser : `resources/js/components/admin/pos/PosCart*.vue` ou `resources/js/components/admin/pos/v5/PosCartBodyComponent.vue`) | **write** : aligner contrat ARIA `list` ↔ `listitem`. |
| Spec audit design | `tests/e2e/design/pos/d2-pos-design-audit.spec.js` | **read-only** (pas de modif sauf si la regex `criticalAxe` doit white-lister un cas légitime — peu probable). |

## 3. SUBSYSTEMS_OFF_LIMITS

KDS, Kiosk, OSS, fiscal, pricing, schema, auth, branch_id, OrderService, dispatch, Composer.

## 4. INVARIANTS_AT_RISK

| Invariant | Risque | Mitigation |
|---|---|---|
| Frozen zones | POS V5 cart peut être partiellement frozen (vérifier `docs/gates/`). | Lecture frozen list AVANT EXECUTE ; halt si frozen. |
| Pricing SSOT | Aucun (modif HTML uniquement). | — |
| OrderService symmetry | Aucun (UI). | — |

## 5. GATE_CONDITIONS

- **Gate UX si** : la modification rompt un sélecteur `data-testid` consommé par d’autres specs Playwright POS. **Audit obligatoire** des `data-testid="pos-cart-*"` AVANT toute modification de structure DOM.

## 6. Stratégie technique (instructions d’intelligence)

### 6.1 Diagnostic ciblé

1. **Localiser** le composant Vue avec scope `data-v-e79a74ec` :
   ```bash
   grep -r "pos-v5-cart__body" resources/js/components/admin/pos/
   ```
2. **Lire** le template du composant cart : observer ce que le `v-for` rend dans le `<div role="list">`.
3. **Identifier** le pattern :
   - **Cas A** : `<article>` ou `<div>` enfants → ajouter `role="listitem"` explicite OU restructurer en `<ul role="list"><li role="listitem">…`.
   - **Cas B** : un wrapper `<TransitionGroup>` / `<keep-alive>` insère un nœud non-listitem → re-organiser.
   - **Cas C** : la liste est vide au moment de l’audit (axe ne tolère pas `role="list"` vide) → conditionner `role="list"` (ne le poser **que si** `items.length > 0`) OU mettre toujours un `<li role="listitem" aria-hidden="true">` placeholder accessible.

### 6.2 Solution préférée

- **6.2.a (cleaner)** : transformer le `<div role="list">` en **élément sémantique natif** `<ul class="pos-v5-cart__body …">` avec enfants `<li>`. Cela rend `role="list"` redondant (à supprimer) et fixe la violation par construction.
- **6.2.b (minimal)** : ajouter `role="listitem"` à chaque enfant Vue + s’assurer qu’ils sont **enfants directs** (pas de wrapper).

Décider en EXECUTE selon coût (CSS dépendant du tag).

### 6.3 Anti-régression

- Avant : `grep -r "pos-v5-cart__body" tests/` pour repérer les specs qui ciblent ce sélecteur ; **ne pas** changer la classe CSS.
- Après EXECUTE : relancer les specs **POS critical-flow** complètes (cf. §7).

## 7. TEST_STRATEGY

- `npm run dev`
- `E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test tests/e2e/design/pos/d2-pos-design-audit.spec.js --retries=0`
- **Régression POS** : `… tests/e2e/02-pos-cash.spec.js tests/e2e/05-pos-card.spec.js tests/e2e/pos-full-process tests/e2e/pos-receipt-kds-instruction-sync.spec.js`
- Critère PASS : 0 violation `aria-required-children` ; toutes specs POS sus-citées en PASS.

## 8. ROLLBACK

Diff Vue localisé. `git checkout HEAD~1 -- <fichier>` instant. Aucun impact backend.

## 9. ESCALATION

- Si fichier POS V5 cart marqué **frozen** dans `docs/gates/` → **STOP + gate brief**.
- Si la régression touche `data-testid` consommés par > 2 specs → escalader pour refactor coordonné (CV1-V1.5D-POS-DOM-AUDIT).

## 10. SYMMETRY_NOTE

Aucune (pas d’`OrderService` / `FrontendOrderService`).

## 11. Livrables

- Diff composant POS V5 cart.
- Capture before/after de la liste cart (a11y inspector).
- `reports/execution/RUN_CV1-V1.5D-E2E-HEAL-B-POS-V5-CART-ARIA_2026-05-04.md`.

---
