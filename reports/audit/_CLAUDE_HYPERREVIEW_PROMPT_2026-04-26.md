# Hyper-review prompt — POS v4 final exec plan

## Mission
Tu es Claude orchestrateur FoodKing. Mode HYPER-REVIEW.

Cible :
- plans/PLAN_POS_V4_IMPL_EXEC_FINAL_2026-04-26.md
- plans/PLAN_POS_V4_IMPL_MASTER_2026-04-26.md
- reports/audit/RAPPORT_ECARTS_DESIGN_POS_V4_SYSTEMIQUE_2026-04-25.md
- reports/audit/REAUDIT_G55PRO_POS_V4_PRECLAUDE_2026-04-26.md (proxy)

## Contraintes
- Invariants FoodKing : pricing_ssot, order_status (enum), branch_id, commit_before_dispatch, OrderService/FrontendOrderService symétrie, frozen zones.
- 9 SFC réels sous resources/js/components/admin/pos. Script gelé. Intégration = template + style + namespace `.fk-pos-v4`.
- Budgets : LCP < 1.2s, CLS < 0.05, TTI < 1.8s, JS first-paint < 220 KB gzipped, focus-trap RGAA AA.
- KIOSK + KDS + branch banner doivent rester cohérents (pas de drift).

## Livrable attendu (un seul fichier)
ÉCRIRE `reports/audit/HYPERREVIEW_CLAUDE_POS_V4_EXEC_FINAL_2026-04-26.md` (français, dense, pas de fluff) avec EXACTEMENT ces sections :

1. ## Verdict orchestration (GO / GO-WITH-AMENDS / STOP) + 3 raisons.
2. ## Cohérence inter-plans (delta MASTER vs EXEC FINAL — quoi a été perdu/ajouté).
3. ## Couverture invariants (matrice 6 invariants × phases W0..W4 → OK/À-renforcer/Manquant).
4. ## 12 lacunes intelligentes non triviales (au-delà des 7 du proxy GPT). Chacune : titre, impact concret, mitigation chiffrée.
5. ## Ordre SFC re-justifié (les 9 réels) avec critère de risque (binding density × surface clavier × invariants touchés).
6. ## Plan de test combat (red-team) : 10 scénarios (offline, double-tap, kiosk crash mid-paiement, branch swap, fiscal duplicata, KDS desync, color contrast, RTL ar, multi-screen, race condition pricing).
7. ## Découpage micro-exits W0 (3 étapes les plus critiques à lancer immédiatement) — pour chacune : commande exacte, fichier livré, critère pass/fail vérifiable terminal.
8. ## Coordination multi-agent (cursor-claude / cursor-composer / codex-terminal / claude-terminal / human) : qui fait quoi sur W0, gates start/done, anti-collision.
9. ## Politique de rollback par SFC (kill switch CSS classe + flag back).
10. ## STOP triggers terminaux (5 max) qui doivent aussi rendre la main au humain.

## Style
- Pas d'introduction.
- Listes denses, tableaux markdown.
- Chiffrer dès que possible (KB, ms, %, lignes).
- Si contradiction trouvée entre les deux plans, écrire `CONTRADICTION:` en gras et citer la ligne.

## Interdits
- Aucune autre écriture/édition. Lecture seule sur le code.
- Pas de plan parallèle, pas de nouveau workstream — tu raffines l'existant.
