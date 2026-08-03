# PLAN — CV1-V1.5D-E2E-HEAL-C-POS-TACOS-CART-TOTAL

**Date** : 2026-05-04
**Auteur** : Claude (PLAN)
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-C-POS-TACOS-CART-TOTAL`
**PRIMARY_EXECUTION_MODEL** : `gpt-5.5-pro` (Codex extension)
**REASONING_EFFORT** : `xhigh`
**EXECUTION_TIER** : `complex` — flux POS encaissement espèces (touche pricing **lecture**, jamais écriture frontend ; toucher au pricing JS = HALT).
**EXECUTE_DELEGATION** : `codex-extension`.
**PLAN_REVIEW** : pending GPT-5.5-pro.

---

## 1. Contexte (rapport source)

`reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md` §3 ligne 6 ; log `~440-498`.

Échec : `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts:222`

```ts
const totalEl = posCartTotalLocator(page); // = #pos-cart li filter(/^Total\b/i) > span.text-primary
await expect(totalEl).toBeVisible(); // timeout 5000ms — element(s) not found
```

→ Le total n’apparaît **plus** dans `#pos-cart` sous la forme `<li>Total ... <span class="text-primary">…</span></li>`. Causes possibles :
1. Refonte POS V5 a déplacé le **total** vers un autre nœud (footer dédié, pas `<li>` dans `#pos-cart`).
2. La classe `text-primary` a été remplacée (cf. **PLAN-A** color contrast). **Risque de couplage**.
3. Le panier est **vide** au moment de l’assertion (parcours items + extras + tacos n’a pas abouti).

## 2. SUBSYSTEMS_TOUCHED

| Sous-système | Fichier(s) | Intent |
|---|---|---|
| Spec POS Tacos | `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` (helper `posCartTotalLocator`) | **write** : ajuster locator au DOM réel (résilient i18n + emplacement). |
| Helpers POS | `tests/e2e/pos/helpers.ts` ou inline (à confirmer en EXECUTE) | **read** d’abord ; **write** si la fonction y est. |
| Composant POS cart total | `resources/js/components/admin/pos/PosCart*.vue` ou `…/v5/PosCartTotal*.vue` | **read-only** (DON’T touch pricing logic — lecture seule pour le sélecteur). |

## 3. SUBSYSTEMS_OFF_LIMITS

**Pricing** (frontend ou backend), `PricingService`, `OrderService`, `FrontendOrderService`, fiscal, KDS, Kiosk, OSS, schema, auth, branch_id.

> **Pricing SSOT (invariant 1)** : aucune logique de calcul, formatage ou recomputation côté JS. **Lecture seule** d’un nœud DOM existant.

## 4. INVARIANTS_AT_RISK

| Invariant | Risque | Mitigation |
|---|---|---|
| Pricing SSOT | Tentation de « recalculer » côté spec si total introuvable. | **Interdit** : si le total backend n’apparaît pas, c’est un bug produit séparé → escalader, ne pas masquer. |
| Frozen zone | Si `PosCart*.vue` est frozen → halt. | Lire frozen list. |

## 5. GATE_CONDITIONS

- Si le diagnostic montre que le **total réel** ne s’affiche pas malgré quote backend OK → **gate produit P0** : régression POS V5 majeure (caisse en prod cassée). Ouvrir `GATE_CV1-V1.5D-POS-V5-TOTAL-MISSING_2026-05-04.md`.

## 6. Stratégie technique (instructions d’intelligence)

### 6.1 Diagnostic en 4 étapes

1. **Reproduire localement** (manuel) le scénario : login POS → ajouter Tacos 4 viandes → sélections → extra → ouvrir `#pos-cart`. Inspecter le DOM réel du total.
2. **Lire** la trace Playwright : `npx playwright show-trace test-results/e2e-pos-tacos-4-viandes-ca-2fa22-nt-espèces-composition-reçu-chromium-retry1/trace.zip`. Naviguer jusqu’à l’assert pour voir le DOM **au moment** de l’échec.
3. **Vérifier** le payload `POST /api/admin/pos/quote` (DevTools / trace) → si `total > 0` → bug d’**affichage** uniquement. Si quote KO → escalade gate produit (§5).
4. **Localiser** le composant qui rend le total :
   ```bash
   grep -rn "Total" resources/js/components/admin/pos/ | grep -i "vue"
   grep -rn 'id="pos-cart"' resources/js/components/admin/pos/
   ```

### 6.2 Solutions par cas

- **Cas 1 — Total déplacé hors `<li>`** : adapter `posCartTotalLocator` :
  ```ts
  page.locator('[data-testid="pos-cart-total"]').first()
  ```
  + **demander** au composant Vue d’ajouter un `data-testid="pos-cart-total"` stable (1 ligne, OK même hors frozen). Si frozen → fallback locator par texte (`getByText(/Total/i)` scoped au footer cart).
- **Cas 2 — Classe `text-primary` retirée par PLAN-A** : remplacer par locator **par texte** + **par testid** (préférer testid) — découpler le test du token couleur.
- **Cas 3 — Panier vide (parcours en amont défaillant)** : remonter aux assertions précédentes (`page.locator('#pos-cart li')` doit avoir au moins 1 item de tacos). Si une étape sélection extras a régressé, **escalader** car c’est un autre bug.

### 6.3 Anti-flake

- Ajouter `await page.waitForResponse(/\/api\/admin\/pos\/quote/)` avant l’assertion total — garantit que le quote backend est revenu et appliqué côté UI.
- Pas de `waitForTimeout` arbitraire.

## 7. TEST_STRATEGY

- **Local** :
  - `E2E_BACKEND_AVAILABLE=1 npx playwright test tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts --retries=0`
  - **Smoke régression POS** : `tests/e2e/02-pos-cash.spec.js`, `pos-full-process/c2-pos-process-audit.spec.js`.
- Critère PASS : flux Tacos espèces complet (paiement + reçu) en PASS, sans modification de la logique pricing.

## 8. ROLLBACK

Diff sur la spec + (1 ligne testid Vue si autorisé). Rollback git.

## 9. ESCALATION

- Quote backend ne renvoie pas le total → gate P0 pricing/sync (HORS scope, autre cycle).
- Composant total frozen + pas de testid possible → escalade vers gate produit pour modifier le composant.

## 10. SYMMETRY_NOTE

`OrderService` / `FrontendOrderService` non touchés (lecture seule via DOM).

## 11. Livrables

- Diff `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` (+ helpers le cas échéant) + (potentiellement) 1 ligne `data-testid` côté Vue.
- `reports/execution/RUN_CV1-V1.5D-E2E-HEAL-C-POS-TACOS-CART-TOTAL_2026-05-04.md` avec extrait DOM avant/après.

---
