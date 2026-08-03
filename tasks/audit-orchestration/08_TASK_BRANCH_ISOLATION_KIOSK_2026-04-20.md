# T08 — Branch isolation kiosk (`KioskMachine` + multi-tenant K-8)

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier que **`branch_id` est server-authoritative** sur tous les flux kiosk authentifiés
(`KioskMachine`), que **5 branches** sont étanches (pentest K-6/K-8), et que le runtime
hybrid K-8 (`/kiosk/context`, locale, theme, capabilities) est cohérent.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Lire :
   - app/Models/KioskMachine.php
   - app/Http/Middleware/KioskAuth.php (ou équivalent)
   - app/Http/Controllers/Frontend/KioskContextController.php (route /kiosk/context)
   - app/Http/Middleware/KioskLocale.php
2) Recherche `request('branch_id')` ou `Auth::user()->branch_id` consommé comme vérité côté
   POST kiosk — la valeur authoritative doit venir de `KioskMachine::current()->branch_id`.
3) Tests :
   - tests/Feature/KioskSecurity/KioskEventAbilityTest.php (postEvent helper)
   - tests/Feature/Kiosk/MultiBranchIsolationTest.php
   - tests/Feature/K8/* (toute suite K-8 dédiée)
4) Lire reports :
   - reports/review/AUDIT_KIOSK_110_ISOLATION_STATE_2026-04-19.md
   - reports/execution/VERIFY_K8_MULTIBRANCH_DEPLOYMENT_2026-04-18.md (résultat pentest 5
     branches A/B/C/D/E)
5) Capabilities JSON multi-tenant : où est-il chargé ? thèmes hex-validés ?
6) Pusher per-branch : reporté K-10.1 (tracker). Documenter état actuel (toujours global ?).

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK08_BRANCH_ISOLATION_2026-04-20.md
```

## Lecture obligatoire

- `app/Models/KioskMachine.php`
- `app/Http/Middleware/Kiosk*.php`
- `tasks/k-hardening/PLAN_K8_MULTIBRANCH_DEPLOYMENT_2026-04-18.md`
- `tasks/k-hardening/ADR_K8_MULTIBRANCH_STRATEGY_2026-04-18.md`
- `reports/execution/VERIFY_K8_MULTIBRANCH_DEPLOYMENT_2026-04-18.md`

## Checklist multi-points

- [ ] V1. `branch_id` jamais lu depuis `request()` côté kiosk
- [ ] V2. `KioskMachine` middleware résout branch + cache scope
- [ ] V3. Pentest 5 branches : 0 fuite cross-branch
- [ ] V4. Endpoint `/kiosk/context` retourne theme/locale/capabilities scopés
- [ ] V5. CSS vars thème hex-validés (XSS-proof)
- [ ] V6. Tests d'isolation passent (K-6 + K-8)
- [ ] V7. Pusher channel name inclut `branch_id` (ou statut « global » documenté + risque chiffré)

## Critères PASS / FAIL

- **PASS** : 7 V cochées, pentest sans fuite.
- **FAIL** : ≥ 1 fuite cross-branch ou `branch_id` client trusted.

## Output

`reports/audit-orchestration/REPORT_TASK08_BRANCH_ISOLATION_2026-04-20.md`

## Si FAIL → action

→ T08b `generalPurpose` : patch middleware + test rouge → vert. Pusher per-branch reste
backlog K-10.1 documenté.
