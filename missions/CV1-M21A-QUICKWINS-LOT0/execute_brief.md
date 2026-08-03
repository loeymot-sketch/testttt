# Execute Brief — CV1-M21A-QUICKWINS-LOT0 (M-21a)

Mode: `codex-extension` (finition Caisse V1 — pas d’implémentation Composer sur chemins produit hors process).

## Objectif

Trois changements **minimaux** (voir `input.json` → `objective` et `allowlist`):

1. `PosComponent.vue` — lier `discountReason` au template (cohérence avec données / computed lues ailleurs).
2. `KitchenDisplaySystemComponent.vue` — `Swiper` : direction calculée selon la locale (RTL), pas de régression d’affichage KDS.
3. Supprimer un import inutilisé (ex. focustrap) si listé, sans toucher `PaymentComponent.vue` ni logique de prix.

## Périmètre

- Uniquement les chemins de `input.json` → `allowlist`.
- **Interdit** : toute logique de calcul de prix, mutation de props de paiement (hors scope M-21b), `app/`, `routes/`, `database/`.

## Tests

Lancer `mandatory_tests` du `input.json` (Vitest sur `tests/js/quickwins/` et smoke POS si indiqué).

## Validation

`npm run verify:boucle` / hooks du cycle. Auto-audit GPT + double audit du run-cycle. Tracer `EXECUTE_DELEGATION: codex-extension` dans le rapport d’exécution.
