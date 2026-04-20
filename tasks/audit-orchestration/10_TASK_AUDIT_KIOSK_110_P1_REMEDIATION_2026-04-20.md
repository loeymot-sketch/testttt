# T10 — Remédiation 5 P1 audit kiosk 110 %

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier l'état **réel** des **5 findings P1** documentés dans
`AUDIT_KIOSK_110_FINDINGS_TRACKER.md` : AX12-02, AX4-04, AX11-01, AX10-01, AX14-01.
Pour chacun : statut (open / partial / fixed), preuve fichier:ligne, recommandation.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Mission : auditer les 5 P1 du tracker AUDIT_KIOSK_110.

Étapes pour CHAQUE finding :

A) Lire le tracker :
   reports/review/AUDIT_KIOSK_110_FINDINGS_TRACKER.md (chercher AX12-02, AX4-04, AX11-01, AX10-01, AX14-01).

B) Pour chacun, ouvrir le fichier:ligne cité et confirmer :

1) AX12-02 (correlation outbox) : app/Listeners/PersistOrderCreatedToOutbox.php — usage
   `Str::uuid()` vs `X-Correlation-ID`. Voir T09.

2) AX4-04 (paiement total fallback) : resources/js/components/frontend/kiosk/KioskPaymentComponent.vue
   ~309-310 — fallback `cartTotal` si réponse API incomplète. Doit bloquer paiement, pas
   afficher fallback.

3) AX11-01 (NormalItemResource is_available global vs branche) :
   app/Http/Resources/NormalItemResource.php — `is_available` doit refléter dispo BRANCHE
   (pivot item_branch_availability) pas le flag global Item.

4) AX10-01 (CSP Report-Only + unsafe-inline/eval) : config/csp.php / Middleware CSP /
   header response. Plan d'enforce + nonces ?

5) AX14-01 (no Playwright golden path kiosk paiement) : tests/e2e/kiosk/golden-path*.spec.ts
   ou équivalent. Présent ? Couverture login → panier → preview → paiement (mock TPE) ?

Pour chaque P1 : statut + 5 lignes de preuve + action proposée.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK10_KIOSK_P1_REMEDIATION_2026-04-20.md

Tableau final : ID | Statut | Fichier | Action | Effort estimé.
```

## Lecture obligatoire

- `reports/review/AUDIT_KIOSK_110_FINDINGS_TRACKER.md`
- `reports/review/AUDIT_KIOSK_110_EXECUTIVE_2026-04-19.md`
- Fichiers ciblés par chaque AX (cf. tracker)

## Checklist multi-points

- [ ] V1. AX12-02 statut + preuve
- [ ] V2. AX4-04 statut + preuve
- [ ] V3. AX11-01 statut + preuve
- [ ] V4. AX10-01 statut + plan CSP
- [ ] V5. AX14-01 statut + plan Playwright
- [ ] V6. Estimation effort par P1
- [ ] V7. Recommandation ordre d'attaque (déjà dans handoff `03_DEMARRAGE_*` — confirmer ou ajuster)

## Critères PASS / FAIL

- **PASS** : 5 P1 documentés avec statut clair + plan.
- **FAIL** : ≥ 1 P1 sans preuve ni plan → audit insuffisant.

## Output

`reports/audit-orchestration/REPORT_TASK10_KIOSK_P1_REMEDIATION_2026-04-20.md`

## Si FAIL → action

→ T10b `generalPurpose` : générer un plan détaillé par P1 (fichiers, tests, estimation).
