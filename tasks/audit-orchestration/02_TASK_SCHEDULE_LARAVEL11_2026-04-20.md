# T02 — Schedule Laravel 11 : SLO / Outbox rescue / OTP purge

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Confirmer que les **3 schedules** documentés (SloEvaluatorJob 5 min, `foodking:outbox:rescue`
1 min, OTP purge 15 min) sont **toujours actifs** dans `testttt-kiosk-p93` malgré la
suppression de `app/Console/Kernel.php`. Laravel 11 supporte la migration vers
`bootstrap/app.php` ou `routes/console.php`.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Lis :

A = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93/bootstrap/app.php
B = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93/routes/console.php
C = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Kernel.php (référence)
D = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93/composer.json (version Laravel)
E = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93/app/Jobs/Observability/SloEvaluatorJob.php

Vérifications :
1) Version Laravel installée (composer.json + composer.lock si dispo).
2) `bootstrap/app.php` utilise-t-il `withSchedule(...)` ou `->withCommands` ou
   `->withRouting(commands: ...)` ? Quels closures/jobs sont enregistrés ?
3) `routes/console.php` contient-il `Schedule::job(...)` / `Schedule::command(...)` /
   `Schedule::call(...)` pour : (a) SloEvaluatorJob, (b) `foodking:outbox:rescue`,
   (c) purge OTP ?
4) Recherche globale (`rg "SloEvaluatorJob|foodking:outbox:rescue|purge-expired-otps" --type php`)
   pour trouver toute autre déclaration.
5) Si aucune déclaration trouvée → REGRESSION confirmée.
6) Optionnel : `php artisan schedule:list` (si tu peux l'exécuter via shell sandboxé) ;
   sinon, simuler la lecture statique.

Sortie attendue :
/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK02_SCHEDULE_LARAVEL11_2026-04-20.md

Format : tableau "Schedule | Présent ? | Path | Cadence | Notes" + verdict global PASS/FAIL.
```

## Lecture obligatoire

- `testttt-kiosk-p93/bootstrap/app.php`
- `testttt-kiosk-p93/routes/console.php`
- `testttt/app/Console/Kernel.php` (baseline)
- `testttt-kiosk-p93/composer.json`

## Checklist multi-points

- [ ] V1. Version Laravel confirmée (10.x ou 11.x)
- [ ] V2. Mécanique de schedule détectée (Kernel vs bootstrap vs routes/console)
- [ ] V3. SloEvaluatorJob enregistré 5 min `withoutOverlapping` ✓ ou ✗
- [ ] V4. `foodking:outbox:rescue` enregistré 1 min `withoutOverlapping` ✓ ou ✗
- [ ] V5. Purge OTPs enregistrée 15 min ✓ ou ✗
- [ ] V6. `php artisan schedule:list` exécuté ou simulé (résultat copié)
- [ ] V7. Diff explicite vs `testttt/app/Console/Kernel.php`

## Critères PASS / FAIL

- **PASS** : 3 schedules présents et configurés correctement, mécanique validée.
- **FAIL** : ≥ 1 schedule manquant. → **régression observabilité K-9 + outbox K-9**.

## Output

`reports/audit-orchestration/REPORT_TASK02_SCHEDULE_LARAVEL11_2026-04-20.md`

## Si FAIL → action

→ T02b `generalPurpose` : proposer le **patch minimal** pour ré-enregistrer les schedules
manquants dans `bootstrap/app.php` ou `routes/console.php` (Laravel 11 idiomatique). Patch
non exécuté tant que l'humain ne valide pas.
