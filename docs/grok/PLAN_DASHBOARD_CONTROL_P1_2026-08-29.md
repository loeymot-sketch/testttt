# PLAN — Dashboard / contrôle P1 (2026-08-29)

Audit : NEEDS_FIX. Discipline Grok : tests qui mordent, hors frozen, hors rebuild Mix,
hors npm/composer audit, hors suite PHPUnit globale.

## Hors scope (owner / autre voie)

- Frozen kiosk / POS wizard / fiscal.
- Rebuild `app.js`, i18n AR/DE/BN, `npm audit`, `composer audit`.
- E2E Playwright bloqué par safety-check frozen staged (ne pas `--no-verify`).
- P1-06 campagne E2E A→E : preuve HTTP + spec dédiée PHPUnit, pas un run frozen.

## SHARED (déclarer JOURNAL)

`DashboardService`, `DashboardController`, `SyncOverviewController`,
`InterrupteurController`, `HealthzController`, `HealthzCheckCommand`,
`SystemHealthComponent.vue`, `observabilityRoutes.js`,
`AdminRoutePermissionFloorTest`, `FilesSurveilleesTest`.

## Correctifs

| ID | Attendu |
|---|---|
| P1-01 | GET system-health + interrupteurs : Admin/Tenant Admin only. POS/Chef 403. |
| P1-02 | Non-admin `branch_id<=0` : 403, jamais le dashboard global. |
| P1-03 | `first_date > last_date` : 422, pas 500. Fenêtre max 366 j. |
| P1-04 | Cockpit backup = `*.sql.gz` + 26 h (même vérité que `/health/ready`). Copy Vue sans faux « restaurée ». |
| P1-05 | Si toutes les files `Queue::size` échouent : `queue_pending=unknown`, pas 0 healthy. |

Tests : `tests/Feature/Grok/DashboardControlAuditFixesTest.php`.
