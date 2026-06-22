# PLAN — CV1-V1.5D-E2E-HEAL-A-A11Y-COLOR-CONTRAST

**Date** : 2026-05-04
**Auteur** : Claude (PLAN)
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-A-A11Y-COLOR-CONTRAST`
**PRIMARY_EXECUTION_MODEL** : `gpt-5.5-pro` (Codex extension)
**REASONING_EFFORT** : `xhigh`
**EXECUTION_TIER** : `complex` — touche **design system / token couleur** + 4 specs WCAG ; impacts visuels potentiels cross-surface (POS, Kiosk, OSS) → routine interdite.
**PLAN_REVIEW** : pending GPT-5.5-pro/xhigh challenge avant EXECUTE.
**EXECUTE_DELEGATION** : `codex-extension` (PRIMARY) / `foodking-complex-implementer` (FALLBACK).

---

## 1. Contexte (rapport source)

`reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md` §3 lignes 1, 8, 9, 10.

Échecs Playwright concernés (run massif 2026-05-04 ; log `reports/e2e-massive/FINAL_2026-05-04/logs/playwright_full.log`) :

| # | Spec | Violation `axe-core` |
|---|---|---|
| 1 | `tests/e2e/catalog-studio-a11y-axe.spec.js:56` | 1× **serious** sur état « catégorie sélectionnée » |
| 8 | `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js:53` | `color-contrast` **serious** sur `.text-primary` (`#ff006b` sur `#fff`, ratio 3.84:1) + onglet `#tab-all` (`#fff` sur `#ff006b`, 3.84:1) |
| 9 | `…:58` | Idem +/- timeout sur `getByRole('button', { name: /voir les détails|view details/i })` quand la liste est vide → cause amont = liste, mais axe-violation identique au 1er round |
| 10 | `…:82` (empty state) | Idem `color-contrast` (header + tabs) |

> Cible WCAG 2 AA = ratio **≥ 4.5:1** texte normal, **≥ 3:1** texte large (≥ 18 pt ou ≥ 14 pt bold). Notre primaire `#FF006B` actuel = **3.84:1** sur blanc → **fail** des deux côtés (premier-plan ou arrière-plan).

## 2. SUBSYSTEMS_TOUCHED

| Sous-système | Fichier(s) | Intent |
|---|---|---|
| Vue / IngredientList | `resources/js/components/admin/ingredients/IngredientListComponent.vue` | **write** : remplacer `text-primary`, `border-primary`, `bg-primary text-white` sur header + tabs par variantes accessibles (token `--primary-aa` ou classe Tailwind `bg-rose-700` / `text-rose-700` selon contraste mesuré). |
| Tailwind config | `tailwind.config.js` (si `primary` y est défini) **ou** `public/themes/default/css/custom.css` (variable globale) | **read** d’abord ; **write** uniquement si nécessaire pour ajouter un token `primary-aa` sans casser l’identité visuelle V1. |
| Catalog Studio (state catégorie sélectionnée) | spec `catalog-studio-a11y-axe.spec.js` + composant Studio impliqué (à identifier en EXECUTE via lecture `error-context.md` du test) | **write** : 1 nœud à corriger (à lire, **ne pas** présumer). |
| (lecture seule) | `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js` | aucune modif spec — fix vient du composant. |

## 3. SUBSYSTEMS_OFF_LIMITS

POS, Kiosk, KDS, OSS, Composer, fiscal, pricing, OrderService/FrontendOrderService, schema DB, auth, branch_id. **Aucune** mutation backend. **Aucun** changement i18n. Si le primaire `#FF006B` est **partagé** avec POS V4 (audit `AUDIT_POS_V4_EXPORT_DESIGN_2026-04-24.md` §`#FF006B` vs `#0084FF` non tranché) → **NE PAS** changer la valeur globale ; introduire un alias dérivé (cf. §6 Stratégie).

## 4. INVARIANTS_AT_RISK

| Invariant | Risque | Mitigation |
|---|---|---|
| Frozen zones | Possible si la token primary est dans un fichier marqué frozen (cf. `docs/gates/`). | EXECUTE doit `grep` d’abord ; si frozen → **HALT + GATE BRIEF**. |
| FoodKing Pricing SSOT | Aucun risque (UI seulement). | — |
| OrderStatus / branch_id / dispatch / Service symmetry | Aucun risque. | — |

## 5. GATE_CONDITIONS

- **Gate produit** si l’on doit **changer la valeur** du primaire global `#FF006B` → ouvre `GATE_CV1-V1.5D-PRIMARY-COLOR-DECISION_2026-05-04.md` (option 1 : nouveau primaire AA / option 2 : alias `--primary-aa-text` réservé aux usages typographiques / option 3 : refonte design system). **Préférer option 2** (impact minimal).
- **Gate frozen** si modification d’un token global protégé.

## 6. Stratégie technique (instructions d’intelligence)

### 6.1 Diagnostic (avant tout patch)

1. **Lire** `error-context.md` de **chaque** échec :
   - `test-results/Playwright-critical-flow-v-9f7a6-olations-on-the-empty-state-chromium-retry1/error-context.md`
   - `test-results/Playwright-critical-flow-v-811b0-ations-on-admin-ingredients-chromium*/error-context.md`
   - `test-results/Playwright-critical-flow-v-0e798--with-the-usage-drawer-open-chromium-retry1/error-context.md`
   - `test-results/e2e-catalog-studio-a11y-ax-3d2b2--on-category-selected-state-chromium*/error-context.md`
2. **Cartographier** chaque nœud `target` `axe` :
   - `.text-primary.uppercase.tracking-wide` (« FoodKing V1 ») → 9px = texte normal, exige 4.5:1.
   - `#tab-all` (`bg-primary text-white`) → 14px normal, exige 4.5:1.
   - Catalog Studio nœud (à découvrir) → ne **pas** présumer.
3. **Vérifier** où `text-primary` / `bg-primary` sont définis :
   - `tailwind.config.js` (si présent) `theme.extend.colors.primary`
   - `public/themes/default/css/custom.css` (déjà 7 occurrences `#FF006B` / `#ff006b33`)
4. **Confirmer** : si le primary token est partagé par d’autres surfaces, **option 2** obligatoire (cf. §5).

### 6.2 Solution préconisée (option 2 — minimal blast radius)

- **Header « FoodKing V1 »** (`text-primary` sur fond blanc) : remplacer la classe par `text-rose-700` (ratio ≈ 6.0:1 sur `#fff`) **ou** par `text-pink-700`. Préserver l’intention V1 — c’est un libellé d’identification, pas l’accent CTA.
- **Onglet actif** `#tab-all` (`bg-primary text-white`) : appliquer une variante AA. Deux choix :
  - **6.2.a (préféré)** Garder `bg-primary` (`#ff006b`) + `text-white` mais **augmenter la taille de police** à 16 px et **bold** ne suffit pas (ratio 3.84:1 reste < 3:1 large/bold ? large = ≥ 18.66 px — donc tab @ 14 px reste fail). → **fallback** : utiliser un **dark accent** (`bg-rose-700 text-white`, ratio ≈ 6.0:1).
  - **6.2.b** Inverser : tab actif = `bg-white text-rose-700 border-2 border-rose-700` (texte sur blanc, ratio ≈ 6.0:1) — cohérent avec a11y mais perd le « fill » plein.
- **Catalog Studio** : appliquer la **même** stratégie au nœud unique signalé.

### 6.3 Garde-fou (anti-régression visuelle)

1. **Avant** EXECUTE : capturer un screenshot **before** de `/admin/ingredients` (Playwright `--update-snapshots` non, capture manuelle dans `reports/e2e-massive/`).
2. **Après** EXECUTE : capturer **after** ; déposer côte à côte dans `reports/audit/A11Y_CONTRAST_BEFORE_AFTER_2026-05-04.md`.
3. Si la perception « rose flashy V1 » est jugée altérée → option 1 (gate produit) requise.

### 6.4 Cas hors-scope identifié

`v1-ingredients-a11y.spec.js:58` (drawer) échoue **aussi** parce que la liste est vide quand le bouton « voir les détails » est cherché. Ce sous-problème est **traité dans PLAN-F** (data + permissions). Ce plan-A se concentre exclusivement sur les violations `color-contrast`.

## 7. TEST_STRATEGY

- **Local validation (obligatoire)** :
  1. `npm run dev` (Mix re-build).
  2. `E2E_BACKEND_AVAILABLE=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8000 npx playwright test tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js --retries=0`
  3. `… npx playwright test tests/e2e/catalog-studio-a11y-axe.spec.js --retries=0`
- **Vitest** : exécuter les snapshot tests existants si `IngredientListComponent` est snappé.
- **Static a11y check** : `npx axe http://127.0.0.1:8000/admin/ingredients` (CLI axe en option, pas obligatoire).
- **Critère PASS** : 0 violation `serious|critical` sur les 4 tests cités.

## 8. ROLLBACK

Diff git localisé sur `IngredientListComponent.vue` (et éventuellement Studio). `git checkout HEAD~1 -- <fichier>` suffit. Aucun changement DB → rollback instantané.

## 9. ESCALATION

- Modification du token global `primary` → **STOP + gate produit** (cf. §5).
- Si plus de **3** nœuds Studio à corriger → escalader (refonte locale Studio).
- Si Tailwind purge supprime des classes ajoutées (`bg-rose-700` non détectée en prod-build) → ajouter à la safelist Tailwind ; documenter en `ESCALATION:` du plan file.

## 10. SYMMETRY_NOTE

Aucune (pas d’`OrderService` / `FrontendOrderService` touché).

## 11. Livrables attendus

- Diff sur `IngredientListComponent.vue` + (potentiellement) un fichier Studio + safelist Tailwind si nécessaire.
- `reports/audit/A11Y_CONTRAST_BEFORE_AFTER_2026-05-04.md`.
- `reports/execution/RUN_CV1-V1.5D-E2E-HEAL-A-A11Y-COLOR-CONTRAST_2026-05-04.md`.

---
