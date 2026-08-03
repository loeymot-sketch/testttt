# T03 — Sentry front : régression K-9

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Évaluer l'impact de la **suppression** de `resources/js/observability/sentry.js` sous
`testttt-kiosk-p93` (livrable K-9 ADR-1+ADR-9). Confirmer si remplacement, désactivation
volontaire, ou régression silencieuse.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Mission : tracer l'usage de Sentry front et l'impact de la
suppression du module sous /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Confirmer absence : `find resources/js/observability -name "*sentry*"` → vide.
2) Rechercher toute référence : `rg -n "installSentry|@sentry/vue|sentry\.js|sentry\.init" -g '!node_modules'`.
3) Lire `resources/js/app.js` (et tout entrypoint Vue détecté) → l'init Sentry y est-il
   encore appelé ? Si oui → ImportError build silencieux.
4) Lire `package.json` → `@sentry/vue` toujours dans dependencies ?
5) Lire les tests Vitest référençant Sentry (s'il y en a) : `rg -l sentry tests/js/`.
6) Lire la copie référence : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/observability/sentry.js
   (si elle existe sous testttt — si oui, comparer avec ce qui est attendu par K-9 ADR-9 :
   beforeSend scrub PII, beforeBreadcrumb, opt-in DSN).
7) Vérifier compilation : tenter `npm run production` (ou lire reports/execution/RUN_K9*
   pour la dernière trace de build).

Verdict :
- A. Remplacement par autre stack (ex. Datadog, console.error guard) → documenter.
- B. Désactivation volontaire (toggle config) → trouver le toggle.
- C. Régression silencieuse → ALERTE P0.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK03_SENTRY_FRONT_2026-04-20.md
```

## Lecture obligatoire

- `testttt-kiosk-p93/resources/js/observability/` (entier)
- `testttt-kiosk-p93/resources/js/app.js`
- `testttt-kiosk-p93/package.json`
- `testttt-kiosk-p93/tasks/k-hardening/PLAN_K9_OBSERVABILITY_2026-04-18.md`
- `testttt-kiosk-p93/tasks/k-hardening/ADR_K9_OBSERVABILITY_STRATEGY_2026-04-18.md`

## Checklist multi-points

- [ ] V1. Absence de `sentry.js` confirmée
- [ ] V2. Aucun `import` orphelin pointant vers le module supprimé
- [ ] V3. Statut de `@sentry/vue` dans `package.json` documenté
- [ ] V4. ADR-9 K-9 lu et invariants PII scrub vérifiés (sont-ils encore couverts ailleurs ?)
- [ ] V5. Build prod testé ou trace dernier build relue
- [ ] V6. Verdict A / B / C documenté avec preuves

## Critères PASS / FAIL

- **PASS** : verdict A ou B avec preuve. PII scrub toujours assuré.
- **FAIL** : verdict C ou aucune preuve → P0 régression.

## Output

`reports/audit-orchestration/REPORT_TASK03_SENTRY_FRONT_2026-04-20.md`

## Si FAIL → action

→ T03b `generalPurpose` : restaurer `sentry.js` depuis `git log -- resources/js/observability/sentry.js`
(commit antérieur), ou écrire un module **stub** conforme ADR-9 (no-op si DSN absent).
Patch proposé, non appliqué.
