# T03b — Restauration / implémentation sentry.js K-9 ADR-9 (2026-04-20)
## task_id: T03b
## PRIMARY_MODEL: gpt-5.4-high
## cycle scope: réimpl observability front (≤ 3 fichiers)

## Recherche historique
- git log all : aucun commit trouvé pour `resources/js/observability/sentry.js` (`git log --all --oneline -- ...` vide, `git log --all --diff-filter=A -- ...` vide)
- reflog/stash : aucun indice de version restaurable ; reflog limité aux commits P9.3, `stash@{0}` et `stash@{1}` ne contiennent pas `sentry.js`
- Décision : réimpl from scratch

## Fichiers modifiés
| Path | Δ | Justification |
|---|---|---|
| `testttt-kiosk-p93/resources/js/observability/sentry.js` | create | Restaurer le bridge frontend Sentry ADR-1/ADR-9 avec no-op DSN absent, import dynamique optionnel, scrub PII et hooks `beforeSend` / `beforeBreadcrumb` |
| `reports/execution/RUN_T03B_SENTRY_FRONT_2026-04-20.md` | create | Tracer l'exécution T03b, la décision de réimplémentation et les résultats Vitest |
| `reports/post_execute_latest.log` | append | Ajouter `EXECUTE_DELEGATION: foodking-complex-implementer` pour traçabilité d'exécution |

## Décisions clés
- `@sentry/vue` : laissé en dynamic-import-only
- Patterns PII : `email`, `phone`, `card 13-19`, `cvv` en contexte clé `cvv`, masquage récursif des clés sensibles (`password`, `token`, `email`, `phone`, `cookie`, `device_id`, `ip_address`, `session_id`, `username`, `card`, `cvv`, `secret`, `authorization`)
- No-op DSN absent : oui

## Tests
- Vitest kioskSentryBoot.spec.js : 12/12 pass
- Vitest full : non lancé

## SYMMETRY_NOTE: N/A (pas de OrderService touché)
## ESCALATION (le cas échéant)
Aucune

## Anomalies flagged
- `@sentry/vue` reste absent du `package.json` ; comportement voulu dans ce hotfix via import dynamique + fallback no-op
- Vitest affiche un warning `baseline-browser-mapping` obsolète, sans impact sur le résultat de la spec
- Quand `@sentry/vue` est absent, Vite journalise l'échec de résolution pendant l'import dynamique ; le code le capture et retourne `false` sans crash applicatif

## Audit status: PENDING_CLAUDE_REVIEW
