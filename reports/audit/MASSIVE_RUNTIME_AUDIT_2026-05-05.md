# Massive runtime audit — 2026-05-05

## Scope executed (read-only + tests; corrections Vitest uniquement)

| Check | Result |
|--------|--------|
| `npm run verify:boucle` | OK (WARN: `ACTIVE_CYCLE.md` PHASE `(none)` vs canonique `none`) |
| `npm run pos:lint:pricing` | OK — WARN signoff-pending until 2026-05-10 (toléré) |
| `npm run pos:lint:status` | OK |
| `npm run i18n:audit` | **Exit 1** — clés manquantes (ex. Vue `en` 52, `fr` 28) ; CSV sous `reports/i18n/` |
| `php artisan test tests/Feature` | **1287 passed**, **24 skipped**, ~250s |
| `npx vitest run` (full) | **193 files**, **1162 passed**, **2 skipped** — après alignement URLs axios + compteur sidebar |

## Correctifs appliqués (Vitest)

- URLs mockées / assertions alignées sur le code produit : chemins **`admin/...` sans slash initial** (`ProductComposerEditorComponent`, `ComposerPublishDiffModal`).
- `sidebarV1Cleanup.spec.js` : **7** entrées `.db-sidebar-nav-menu` attendues (`buildMergedSidebarMenus` : dashboard, pos, rupture, items→studio+attributs, ingrédients, commandes POS).

## Risques / suites

- **i18n** : non bloquant CI ici mais signal fort pour parité V1.
- **Pricing guard** : date limite signoff 2026-05-10.
- Réservations **stale** dans `agent-activity-log` (agents historiques) : nettoyage humain si bruit.
