# Audit final W0 — Claude terminal

## Contexte
Tu viens de produire HYPERREVIEW + 3 livrables W0 :
- reports/audit/HYPERREVIEW_CLAUDE_POS_V4_EXEC_FINAL_2026-04-26.md
- reports/audit/W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md (W0-A)
- docs/design/BINDING_MAP_POS_V4.md (W0-B, squelette)
- reports/baseline/POS_V4_PERF_BASELINE_W0.md (W0-C)
- resources/css/pos-v4.css (stub namespace, 23 occurrences `fk-pos-v4`)

## Tâche
Mode AUDIT FINAL W0 (rigoureux, pas complaisant). Lis ces 5 fichiers + revérifie le code source si nécessaire.

ÉCRIS `reports/audit/AUDIT_FINAL_W0_CLAUDE_2026-04-26.md` (français, dense) avec EXACTEMENT ces sections :

1. ## Verdict W0 (PASS / PASS-WITH-FIX / FAIL) + 3 raisons.
2. ## Vérification croisée des 4 critères PASS/FAIL HYPERREVIEW §7
   - W0-A : décision écrite (oui/non, complétude, signature manquante = OK car humain).
   - W0-B : 9 SFC présents avec ≥ 1 binding chacun, statut renseigné.
   - W0-C : grep contamination = 0, pos-v4.css absent ou stub conforme.
   - Bonus : pos-v4.css respecte la règle "aucun sélecteur hors `.fk-pos-v4`".
3. ## Risques résiduels avant W1 (top 5, chiffrés).
4. ## Quoi délivrer en plus avant d'ouvrir W1 (checklist 5 items max, vérifiable terminal).
5. ## Décision orchestration (continue / heal / block / human).

## Contraintes
- Aucune édition de SFC (`.vue`) ou de fichiers métier.
- Lecture seule sauf le rapport demandé.
- Densité maximale, chiffrer, pas de fluff.
