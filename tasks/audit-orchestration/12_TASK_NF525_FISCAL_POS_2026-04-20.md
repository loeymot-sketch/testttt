# T12 — NF525 / fiscal POS readiness

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Évaluer la conformité **NF525** (logiciel de caisse FR) : inaltérabilité, sécurisation,
conservation, archivage, signature chaînée, JET (journal d'événements), ticket Z, archive
fiscale, ré-impression contrôlée. Lister les **gaps**.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt.

Étapes :
1) Lire :
   - reports/review/AUDIT_POS_110_FISCAL_NF525_2026-04-19.md
   - reports/review/AUDIT_POS_110_NF525_READINESS_2026-04-19.md
2) Pour chacun des 4 piliers NF525 (Inaltérabilité / Sécurisation / Conservation /
   Archivage), produire un état :
   - Composant code responsable
   - Couverture actuelle
   - Gaps + risque
3) Vérifier :
   - app/Services/Fiscal/ (s'il existe) : signature SHA-256 chaînée ?
   - app/Models/Receipt.php / OrderTicket.php
   - app/Jobs/Fiscal/ZTicketArchiveJob.php (s'il existe)
   - app/Models/JournalEvent.php (JET)
   - tasks/phase9-sync/BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md
4) Tests :
   - tests/Feature/Fiscal/*
   - tests/Feature/POS/ZTicketTest.php
5) Identifier obligations légales encore non couvertes (auto-tests obligatoires, certificat
   éditeur, attestation, etc.).

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK12_NF525_FISCAL_2026-04-20.md
```

## Lecture obligatoire

- `reports/review/AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`
- `reports/review/AUDIT_POS_110_NF525_READINESS_2026-04-19.md`
- `app/Services/Fiscal/*`, `app/Models/Receipt.php`
- `tasks/phase9-sync/BLOCKER_POS_9_4_10_destroy_after_Z_2026-04-18.md`

## Checklist multi-points

- [ ] V1. Inaltérabilité (signature chaînée + hash) couverte ou gap chiffré
- [ ] V2. Sécurisation (utilisateur, audit log, RBAC) couverte
- [ ] V3. Conservation (durée 6 ans, exportable) couverte
- [ ] V4. Archivage (clôture périodique, archive scellée) couverte
- [ ] V5. Ticket Z (clôture journalière) implémenté + testé
- [ ] V6. Ré-impression contrôlée (mention « duplicata » + journal)
- [ ] V7. Liste des gaps légaux + recommandation (certif éditeur, auto-test)

## Critères PASS / FAIL

- **PASS** : 4 piliers couverts (au moins niveau « MVP NF525 ») + roadmap claire pour gaps.
- **FAIL** : pilier critique manquant → POS **non déployable** en France.

## Output

`reports/audit-orchestration/REPORT_TASK12_NF525_FISCAL_2026-04-20.md`

## Si FAIL → action

→ T12b `generalPurpose` (ou escalade humaine — domaine légal) : roadmap NF525 priorisée.
