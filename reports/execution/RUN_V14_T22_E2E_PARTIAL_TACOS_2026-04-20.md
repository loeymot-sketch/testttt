# RUN — V14 T22 E2E partiel (tacos 4 viandes, espèces)

**Cycle** : `V14_VAGUE_D_PHASE2_2026-04-20`  
**Tâche** : `T22_E2E_TACOS_4_VIANDES_FULL_FLOW_PARTIAL`  
**Statut** : **PARTIAL_DELIVERED**  
**Date** : 2026-04-20  

## Résumé

Livraison du scénario Playwright **partiel** : catalogue → item tacos (mix viandes 3+1) → extra → paiement **espèces sans retry** → assertions sur le reçu (composition + SIRET/TVA).  
**Hors scope** (bloqué gates T17 / C9 / G14-B) : résilience paiement, multi-tender, retries.

## Fichiers livrés

| Fichier | Rôle |
|--------|------|
| `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` | Spec principal (TypeScript) |
| `tests/e2e/README.md` | Instructions ops (env, commandes, prérequis data) |
| `reports/execution/RUN_V14_T22_E2E_PARTIAL_TACOS_2026-04-20.md` | Ce rapport |

## Config Playwright

- Fichier existant à la racine : **`playwright.config.js`** (non dupliqué en `.ts`).  
- `baseURL` : `process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8000'`.

## Prérequis ops

1. **Serveur + DB + seed** cohérents avec les libellés (tacos, viandes, extra) ou ajuster les variables `E2E_POS_*_RE` (voir `tests/e2e/README.md`).
2. **Branche / commande** : champs fiscaux exposés sur le reçu (`pos_siret`, `pos_vat_intra`, ou bloc NF525) pour que les assertions `SIRET|TVA` passent ; sinon le test échouera sur cette étape.
3. **`npm install`** dans le repo ; **`npx playwright install chromium`** sur la machine d’exécution.
4. Créer le dossier **`reports/e2e`** (ou laisser le spec le créer) pour la capture `pos-tacos-4-viandes-cash-end.png`.
5. **TypeScript** : le projet n’embarque pas `typescript` npm ; la validation `npx tsc --noEmit` n’est pas applicable telle quelle. **Contrôle effectué** : `npx playwright test tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts --list` → 1 test détecté.

## Sélecteurs / hooks

- **Pas de `data-testid="pos-cart-total"`** dans l’UI POS actuelle : le spec utilise la ligne **Total** (`#pos-cart li` + `span.text-primary`). **TODO** : ajouter un hook dédié (p. ex. tâche T18 a11y / testability).
- Libellés **items / viandes / extras** sont **paramétrables par env** ; si le seed diffère, adapter les regex sans modifier le backend.

## Exécution E2E

**Non exécutée** dans cette livraison (dépendance serveur + données). Lancer localement :

`npx playwright test tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts`

## TODO — full T22

- Réintégrer **multi-tender / retry** lorsque **T17** et gates **C9 / G14-B** seront levés.
- Renforcer les assertions total/reçu une fois **`pos-cart-total`** (ou équivalent) disponible côté UI.

## Blocages connus

- **Gates humains** C9 + G14-B sur la résilience paiement → pas de E2E « full flow » avec retry dans ce livrable.
- **Données catalogue** : échec probable si aucun item ne matche les regex par défaut (ops doit caler seed ou env).
