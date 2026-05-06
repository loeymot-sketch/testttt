# Playwright — dernier run

> **2026-05-04 — Rapport consolidé unique (tout E2E + synthèse échecs)**  
> **SSOT** : [`reports/e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md`](../e2e-massive/RAPPORT_CONSOLIDE_E2E_COMPLET_2026-05-04.md) (run massif 76 tests + correctifs sidebar / rupture / global-setup + tableau des 9 échecs restants).  
> Archive P0→P5 : [`reports/e2e-massive/20260504_1956_E2E_MASSIVE/RAPPORT_CONSOLIDE_P0_P5.md`](../e2e-massive/20260504_1956_E2E_MASSIVE/RAPPORT_CONSOLIDE_P0_P5.md) · manifeste : [`manifest.md`](../e2e-massive/20260504_1956_E2E_MASSIVE/manifest.md)  
> **Verdict run massif** : 63 passed / 11 failed / 1 flaky / 1 skipped (~17,9 min) — log : `reports/e2e-massive/FINAL_2026-05-04/logs/playwright_full.log`. **Reprise** : sidebar + rupture ingrédient **PASS** après correctifs (voir SSOT).

---

# Playwright — run archivé (kiosk SPA black screen)

**Spec** : `tests/e2e/kiosk-spa-black-screen-guard.spec.js`  
**Date** : 2026-05-03  
**Commande** : `npx playwright test tests/e2e/kiosk-spa-black-screen-guard.spec.js --retries=0`  
**Résultat** : **2 passed** (~4.4s)

## Ce qui est couvert

1. **idle → categories → abandon** répété (×3 par défaut, `KIOSK_SPA_GUARD_ROUNDS`) : pas de DOM vide sous le shell, `opacity` ≥ 0.92 sur les racines `kiosk-idle-root` / `kiosk-categories-root`.
2. **Cold deep-link** `/kiosk/categories` : attente sur **visible** idle **ou** catalogue (évite la course URL `/categories` vs redirection immédiate vers idle sans type de commande).

## Correctifs produit associés

- Suppression du `<transition>` sur le `router-view` du shell (`KioskAppComponent.vue`).
- Imports **synchrones** dans `kioskRoutes.js` pour `KioskAppComponent`, `KioskIdleScreenComponent`, `KioskCategoriesComponent` (lazy-only → URL mise à jour sans montage de vue jusqu’à F5).

Recompiler après changement routeur : `npm run dev`.

---

## Boucle « tout kiosk » (2026-05-03 — validation agent)

**Mix** : `npm run dev` — OK  

**Vitest** (bash pour expansion des globs) :

```bash
bash -lc 'npx vitest run tests/js/kiosk*.spec.js tests/js/Kiosk*.spec.js tests/js/sentinels/kioskOfflineIdPrefix.spec.js tests/js/posKioskVariationParity.spec.js'
```

→ **76 fichiers**, **608 tests** — tous verts.

**Playwright** (`--retries=0`) :

- `tests/e2e/kiosk-spa-black-screen-guard.spec.js`
- `tests/e2e/03-kiosk-wizard.spec.js`
- `tests/Playwright/kiosk-order-type-required.spec.js`
- `tests/Playwright/kiosk-quote-pin.spec.js`
- `tests/Playwright/kiosk-errors.spec.js`
- `tests/Playwright/kiosk-legacy-redirect.spec.js`

→ **12 tests** — tous verts.

**Note** : ne pas utiliser `set -o noglob` avec les motifs `kiosk*.spec.js` (sinon Vitest ne reçoit aucun fichier kiosk).

---

## Audit boucle demandée — 2026-05-03 (session agent)

| Contrôle | Verdict |
|----------|---------|
| `npm run dev` (Mix) | **VERT** |
| Vitest kiosk — 76 fichiers / 608 tests | **VERT** |
| Playwright kiosk — 12 tests (`--retries=0`) | **VERT** |
| `npm run verify:boucle` | **VERT** |

Aucune correction code nécessaire sur cette passe ; pas de nouvelle régression détectée.
