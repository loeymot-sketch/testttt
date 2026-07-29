# GOAL S2 — CAISSE + STOCK — PROGRESS (2026-07-29)

## Session
- Lead : S2 (caisse + stock). Base `fa172d5f4` (= HEAD `pos/category-first-caisse-2026-06-23`).
- Isolation : worktree `worktree-s2-caisse-stock-2026-07-29` (garde bg-job — la branche partagée est
  checked-out par la session live). Intégration = rebase trivial de cette branche sur la partagée.
- Chaîne canonique lue : CONSTITUTION → BRAIN §2 → SYSTEM_MAP §2 → PARALLEL_PROTOCOL → DISCIPLINE → GOAL_S2.

## Fait
- Serveur local :8000 UP. Worktree réalignée sur fa172d5f4.
- **V1 cartographie TERMINÉE** : registre `V1-SURFACES.md` complet — ~30 surfaces (cachées incluses),
  14 capturées + LUES, 16 défauts visuels V-01→V-16 + 7 anomalies carto E1→E7.
- Baselines PHPUnit vertes : `Pos` 699/0 échec · `Stock|Purchas|RawMaterial|Encaissement` 248/0 échec.

- **RED dispute V1 terminée** (`V1-RED-VERDICTS.md`) : 0 P0/P1 structurel ; 9 heals appliqués
  commit `4872717e3` (tracker cash-pending toutes-dates + fusion file, header POS ≤1440, carnet
  fail-closed TDD 16/16, Oui/Non, pagination FR, libellés comptoir, pluriel, /m, descriptions FR).
  3 réfutés, 2 handoffs S6, 3 gates owner notées (DAILY_BOOK_PIN, €6.90 wizard frozen, purge E2E).
- Outillage worktree : vendor+node installés, `.env.testing` copié (garde DB-safe), serveur :8010,
  build prod OK. Vitest FULL : 2617 verts (2 échecs = course pendant build, re-run 10/10 verts).
  Régression F1 : `tests/js/posTrackerCashPendingAllStatuses.spec.js` 4/4.

## En cours
- Re-captures visuelles post-heals sur :8010 (agent, 8 points PASS/FAIL).
- **V2 money-path** : auditeur au centime lancé (encaissement/rendu, split, refund, remise, TVA 10%,
  Plan B borne/web COD, tiroir).

## Next exact
- Verdicts re-captures → si FAIL, self-correct (max 3). Puis scénarios V2 e2e réels + matrice centime.
