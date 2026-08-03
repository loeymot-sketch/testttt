# RUN — P_POS Phase 10 — E2E T22 + audit UI ciblé — 2026-04-24

| Lot | Outil | Cible / suite | Résultat | Notes |
| --- | --- | --- | --- | --- |
| A | `after-execute-memory` | (cycle) | OK | |
| A | `check-invariants.sh` | 6/6 invariants | OK | |
| B | Playwright | `npx playwright install chromium` | OK | binaires manquants en local, install exécutée |
| C | Playwright E2E | `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` | **Échec (login)** | reste sur `/admin/login` — identifiants par défaut / seed non valides sur l’URL testée ; utiliser `E2E_POS_USER` / `E2E_POS_PASS` + compte rôle POS |
| D | `foodking-claude-orchestrate.sh` `context` + `audit` (5 améliorations UI) | — | GAPS | voir sortie intégrée ci-dessous (idées UI **non** implémentées ici) |

**EXECUTE_DELEGATION:** routine — alimentation mémoire, invariants, préparation E2E (navigateur), `RUN`, traçabilité en-tête `tacos-4-viandes-cash-flow.spec.ts` ; **aucun** ajustement massif d’UI (livrable design = **≤ 8 composants** s’il y a correctifs, hors scope ici).

**GATE (plan)** : E2E **opt-in** — `workflows/qa-loop.md` ; pas d’E2E « vert » sans compte/seed cohérents sur `PLAYWRIGHT_BASE_URL` (défaut `http://localhost:8000`).

**Livrable code (ce lot)** : seulement le bloc de commentaire dans `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` (Phase 10, GATE, env).

**Audit terminal (5 pistes UI — 2026-04-24, sortie orchestreur, non mergeées)** :  
(1) badge panier (compteur) en barre ; (2) bloc erreur paiement structuré ; (3) cibles tactiles ≥ 44px via util CSS ; (4) indicateur parked fixe en topbar ; (5) micro-feedback tap sur tuile article. **VERDICT synthétique** : GAPS (E2E non prouvé + dettes a11y Phase 9 rappelées) — vérification humaine / seed requis avant de considérer T22 **PASS**.

**Clôture plan 10 phases** : cycles Phases 1–10 exécutés côté orchestration continue avec `RUN_P_POS_PHASE*_2026-04-24` ; reprise E2E = re-lancer le spec après compte/seed, ou CI avec secrets managés.
