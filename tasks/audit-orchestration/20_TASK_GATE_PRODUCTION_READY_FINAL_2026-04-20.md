# T20 — Gate production-ready final : synthèse + verdict

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `generalPurpose`

> **À ne lancer QU'APRÈS** que T01 → T19 soient tous passés (PASS ou FIXED).

## Objectif unique

Synthétiser les 19 rapports précédents en un **verdict global** : kiosk production-ready
oui/non, POS production-ready oui/non, dette résiduelle priorisée, plan de mise en
production (canary, rollback, runbook).

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `generalPurpose`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt.

Étapes :
1) Lire les 19 rapports T01 → T19 sous reports/audit-orchestration/REPORT_TASK*_2026-04-20.md.
2) Pour chaque rapport : extraire verdict PASS/FAIL, points résiduels, dette.
3) Construire la matrice « surface × axe » :
   Lignes : Kiosk, POS, Backend SSOT, Observabilité, Sécurité, Fiscal, Hardware, Offline.
   Colonnes : Tests, SSOT, Isolation, Idempotency, Observability, Audits P1.
   Cellules : OK / Partial / KO + lien rapport.
4) Verdict :
   - **GO PROD KIOSK** : conditions à valider (5–10 items concrets)
   - **GO PROD POS** : idem, en gardant gap NF525 si non couvert
   - **NO-GO** : liste blockers absolus
5) Plan de mise en prod :
   - Canary (1 branche pilote N jours)
   - Rollback procedure
   - Runbook on-call SLO breach (cf. K-9)
   - Communication équipe (release notes)
6) Roadmap K-10.1 actualisée avec items détectés par les 19 audits.
7) Recommander : merger `feat/kiosk-phase-9-3` vers `main` ? Garder worktrees séparés ?

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK20_GATE_PROD_FINAL_2026-04-20.md
```

## Lecture obligatoire

- 19 rapports `reports/audit-orchestration/REPORT_TASK01_*` … `REPORT_TASK19_*`
- `reports/acceptance/ACCEPTANCE_KIOSK_FINAL_2026-04-19.md`
- `reports/review/AUDIT_KIOSK_110_EXECUTIVE_2026-04-19.md`
- `reports/review/AUDIT_POS_110_EXECUTIVE_2026-04-19.md`

## Checklist multi-points

- [ ] V1. Matrice « surface × axe » complète
- [ ] V2. Verdict GO/NO-GO Kiosk argumenté
- [ ] V3. Verdict GO/NO-GO POS argumenté (avec NF525)
- [ ] V4. Plan canary documenté
- [ ] V5. Procedure rollback documentée
- [ ] V6. Runbook on-call (lien K-9 SLO)
- [ ] V7. Roadmap K-10.1 actualisée
- [ ] V8. Recommandation branches/worktrees

## Critères PASS / FAIL

- **PASS** : verdict argumenté avec preuves (tous rapports cités), pas de zone grise.
- **FAIL** : verdict imprécis ou rapport amont manquant → relancer la tâche manquante.

## Output

`reports/audit-orchestration/REPORT_TASK20_GATE_PROD_FINAL_2026-04-20.md`

## Si FAIL → action

→ Relancer la tâche T01–T19 manquante. T20 ne peut conclure que sur des inputs complets.
