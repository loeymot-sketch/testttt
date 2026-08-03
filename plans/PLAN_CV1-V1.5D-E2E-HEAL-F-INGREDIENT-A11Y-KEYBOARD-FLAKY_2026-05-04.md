# PLAN — CV1-V1.5D-E2E-HEAL-F-INGREDIENT-A11Y-KEYBOARD-FLAKY

**Date** : 2026-05-04
**Auteur** : Claude (PLAN)
**TASK_ID** : `CV1-V1.5D-E2E-HEAL-F-INGREDIENT-A11Y-KEYBOARD-FLAKY`
**PRIMARY_EXECUTION_MODEL** : `gpt-5.5-pro` (Codex extension)
**REASONING_EFFORT** : `xhigh`
**EXECUTION_TIER** : `complex` — touche **a11y** + **modal `prompt()`** ; risque de masquer un vrai bug d’UX clavier si raccourci à un patch trop simple.
**EXECUTE_DELEGATION** : `codex-extension`.
**PLAN_REVIEW** : pending GPT-5.5-pro.

---

## 1. Contexte (rapport source)

`reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md` §3 « flaky » ; log `~795-820`.

Spec : `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js:67` — `toggles the first ingredient switch from the keyboard`.

Échec **flaky** :

```
Locator:  getByRole('switch').first()
Expected: not "true"
Received: "true"
8 × locator resolved to <button role="switch" aria-checked="true" …>
```

→ Au passage `before = "true"` (ingrédient initialement disponible). Après `Space`, le toggle doit passer à `false`. **Mais** le composant `IngredientAvailabilityToggleComponent.vue` ouvre **`window.prompt(...)`** quand `nextAvailable === false` (cf. ligne 75) :

```js
if (!nextAvailable) {
  nextReason = window.prompt(...);
  if (nextReason === null) return; // ← annule la transition si dialog non-handle
}
```

La spec écoute le dialog **une seule fois** (`page.once('dialog', accept)`) et le rebranche **avant** `focus + Space`. Mais sur 8 résolutions de locator, le dialog est consommé sur la 1ère et le `Space` re-déclenche un autre `prompt` non handle → toggle reste à `true`.

## 2. SUBSYSTEMS_TOUCHED

| Sous-système | Fichier(s) | Intent |
|---|---|---|
| Spec a11y | `tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js` | **write** : robustifier le handler dialog (`page.on` + idempotent + `aria-checked` polling). |
| Composant toggle | `resources/js/components/admin/ingredients/IngredientAvailabilityToggleComponent.vue` | **read-only** par défaut ; **write minimal autorisé** uniquement si on remplace `window.prompt` par un dialog accessible (idéal a11y) — voir §6.2. |

## 3. SUBSYSTEMS_OFF_LIMITS

Tous services backend, KDS/Kiosk/POS/OSS, fiscal, pricing, schema, auth, branch_id.

## 4. INVARIANTS_AT_RISK

Aucun invariant FoodKing critique. **Mais** : transformer `window.prompt` en composant Vue dialog peut casser des Vitest existants (`tests/js/ingredient*.spec.js`). À auditer.

## 5. GATE_CONDITIONS

- **Gate UX** si on remplace `window.prompt` par un composant Vue dialog → s’assurer que la **boucle critique** kiosk/POS ne s’en sert pas (gate UX). Sinon, **statu quo prompt** + spec robuste suffit.

## 6. Stratégie technique (instructions d’intelligence)

### 6.1 Cas 1 — fix minimal côté **spec** (préféré)

1. Remplacer `page.once('dialog', …)` par `page.on('dialog', ...)` (multi-shots).
2. Garantir l’ordre : `focus → keydown Space (programmatic) → expect aria-checked toggling within timeout`.
3. Si le toggle prompt devient « disponible → indisponible » (flaky), figer le **before** dans un état neutre :
   ```js
   // Force le toggle à 'available=true' via API avant le test pour rendre le scénario déterministe.
   await request.put('/api/admin/ingredients/extra:42/availability', { is_available: true });
   ```
   (Endpoint réel : cf. `routes/api.php:649-660`.) Cela force un point de départ stable.
4. Polling explicite :
   ```js
   await expect.poll(async () => toggle.getAttribute('aria-checked')).not.toBe(before);
   ```

### 6.2 Cas 2 — fix qualité a11y (si gate UX OK)

Le toggle utilise `window.prompt` qui n’est **pas WCAG-friendly** (perte de focus contexte, lecteur d’écran imprévisible). Remplacer par :

- Vue dialog `<dialog role="dialog" aria-labelledby="…" aria-modal="true">` avec `<input type="text" aria-label="Reason">` et boutons Confirm/Cancel.
- Gestion focus (focus trap simple).

→ Ce changement résout aussi (partiellement) `v1-ingredients-a11y.spec.js:58` (drawer open) car un dialog Vue est inspectable par axe.

### 6.3 Décision

- **Étape A** : appliquer **Cas 1** (spec robuste).
- **Étape B** : si flaky persiste après 3 runs PASS consécutifs → ouvrir mini-cycle pour Cas 2 (composant prompt-replacement, gate UX requis).

### 6.4 Anti-flake (spec discipline)

- Pas de `waitForTimeout`.
- Toujours `expect.poll` pour les transitions optimistes (le store mute localement avant la 200 backend).
- Logger les **8 résolutions de locator** est un signal de virtual list / re-render → forcer `await page.waitForLoadState('networkidle')` avant `getByRole('switch').first()`.

## 7. TEST_STRATEGY

- **Local** : `E2E_BACKEND_AVAILABLE=1 npx playwright test tests/Playwright/critical-flow/v1-ingredients-a11y.spec.js --retries=0 --repeat-each=5` — exiger **5/5** PASS pour valider le fix anti-flaky.
- **Vitest** : `npx vitest run tests/js/ingredient*.spec.js` (régression composant).

## 8. ROLLBACK

Spec uniquement → git checkout.

## 9. ESCALATION

- Si remplacement `prompt` requis (Cas 2) → mini-cycle séparé avec gate UX.
- Si flaky reste après Cas 1 + 5 répétitions → escalader (probablement bug de re-rendu React… euh Vue, ou Pinia/Vuex état initial non-stable).

## 10. SYMMETRY_NOTE

Aucune.

## 11. Livrables

- Diff spec a11y.
- (Optionnel) Diff composant toggle (Cas 2) + gate brief UX.
- `reports/execution/RUN_CV1-V1.5D-E2E-HEAL-F-INGREDIENT-A11Y-KEYBOARD-FLAKY_2026-05-04.md` avec 5 runs `--repeat-each` archivés.

---
