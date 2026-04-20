# T13 — Hardware K-4 : fallback printer / camera / TPE / buzzer

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier que les 4 catégories hardware **dégradent gracieusement** sans perte de commande
ni double-débit, conformément K-4 (TPE UUIDv4 idempotency, `queryTpeStatus` ambiguous-only,
printer backoff, camera fallback, buzzer chain, banner 0-device).

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Lire :
   - resources/js/helpers/kioskHardware.js (façade simulateur K-4)
   - resources/js/helpers/printer*.js, camera*.js, tpe*.js, buzzer*.js
   - resources/js/composables/useKioskHardware*.js (s'il existe)
2) Vérifier :
   - TPE : idempotency UUID v4 + `queryTpeStatus` appelé QUE en cas de réponse ambiguë.
   - Printer : backoff (jitter exponentiel) + queue overflow protection.
   - Camera : fallback manuel si permission refusée / device busy.
   - Buzzer : chaîne hardware → fallback visuel.
   - Banner UI 0-device : visible quand aucun device branché (mode dégradé).
3) Tests :
   - tests/js/kioskHardware*.spec.js
   - tests/Feature/Hardware/* (s'il existe)
   - tests/js/tpeIdempotency.spec.js
4) Audit cross : reports/review/AUDIT_KIOSK_110_HARDWARE_OFFLINE_2026-04-19.md
5) Lire le checklist opérateur K-4 (script test manuel pas-à-pas — doc).
6) Whitelist `hardware.*` côté backend : `KioskEventController`.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK13_HARDWARE_K4_2026-04-20.md
```

## Lecture obligatoire

- `resources/js/helpers/kioskHardware.js`
- `tasks/k-hardening/PLAN_K4_HARDWARE_INTEGRATION_2026-04-18.md`
- `reports/execution/VERIFY_K4_HARDWARE_INTEGRATION_2026-04-18.md`
- `reports/review/AUDIT_KIOSK_110_HARDWARE_OFFLINE_2026-04-19.md`

## Checklist multi-points

- [ ] V1. TPE idempotency UUID v4 + tests anti-double-débit
- [ ] V2. `queryTpeStatus` appelé uniquement sur ambiguïté
- [ ] V3. Printer backoff + overflow guard
- [ ] V4. Camera fallback manuel
- [ ] V5. Buzzer fallback visuel
- [ ] V6. Banner 0-device cohérente UX
- [ ] V7. Whitelist `hardware.*` analytics
- [ ] V8. Checklist opérateur terrain présente + à jour

## Critères PASS / FAIL

- **PASS** : 8 V cochées.
- **FAIL** : ≥ 1 catégorie hardware sans fallback testé → risque order perdu.

## Output

`reports/audit-orchestration/REPORT_TASK13_HARDWARE_K4_2026-04-20.md`

## Si FAIL → action

→ T13b `generalPurpose` : ajouter test manquant + patch fallback.
