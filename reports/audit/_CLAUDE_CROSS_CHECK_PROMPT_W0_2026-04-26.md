# Audit final 2 W0 — cross-check (rôle GPT-5.5 simulation)

## Contexte
Codex API a échoué 2 fois en 5XX (HTTP 504 Gateway Timeout, Cloudflare proxy) sur la mission `POS_V4_FINAL_AUDIT_W0_001` malgré des briefs réduits puis enrichis (746 lignes). Un re-audit Codex live n'est pas possible aujourd'hui.

Pour respecter la consigne "2 audits à la finition", joue ici le rôle de **second auditeur indépendant** avec posture **antagoniste à Claude** : ne valide rien parce que Claude l'a écrit. Cherche les angles morts que Claude a manqués.

## Inputs à relire
- reports/audit/AUDIT_FINAL_W0_CLAUDE_2026-04-26.md (audit 1, à challenger)
- reports/audit/HYPERREVIEW_CLAUDE_POS_V4_EXEC_FINAL_2026-04-26.md
- reports/audit/W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md
- docs/design/BINDING_MAP_POS_V4.md
- reports/baseline/POS_V4_PERF_BASELINE_W0.md
- resources/css/pos-v4.css

## Tâche
ÉCRIS `reports/audit/AUDIT_FINAL_W0_CROSSCHECK_2026-04-26.md` (français, dense, posture critique) avec EXACTEMENT ces sections :

1. ## Verdict cross-check (CONCUR / PARTIAL / DIVERGE) + 3 points où tu pourrais diverger de Claude.
2. ## 5 angles morts du livrable W0 que Claude a sous-estimés ou ratés (chiffrer).
3. ## Quoi corriger absolument avant W1 (P0 / P1 différencié de l'audit 1).
4. ## Risque coordination multi-agent (cursor-claude + cursor-composer + codex-terminal HS + claude-terminal + human) — y compris : la double dépendance Claude (orchestrateur ET auditeur) constitue-t-elle une faiblesse de gouvernance ?
5. ## Sign-off (GO / AMEND / STOP).

## Contraintes
- Aucune édition de SFC.
- Lecture seule sauf le rapport demandé.
- Ne reformule pas l'audit 1 ; challenge-le.
- Rapport court mais incisif (max ~200 lignes).
