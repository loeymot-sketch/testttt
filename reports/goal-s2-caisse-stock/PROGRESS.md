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

## En cours
- **RED dispute** (2 agents parallèles) : F1-F6 logique/comptage (tracker=0 vs encaissement=5, ordre grille
  Tacos, header overlap, €6.90 frozen…) + G1-G8 sécu/config/labels (pos-v4 sans auth serveur, PIN carnet
  2468, Oui/N°, composants morts…).

## Next exact
- Consolider verdicts RED → liste de heals scope-minimal (hors frozen ; frozen = signalement gate owner).
- Vague de heals + tests régression + re-captures → puis V2 money-path.
