# T15 — Sécurité kiosk K-6

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier les 7 vecteurs hardenés K-6 :
- Abilities Sanctum `kiosk:order` fail-closed sur `/kiosk-event`
- `branch_id` server-authoritative
- Throttle per-machine
- Login lockout email∥username
- `kioskLockdown` DOM (no F12, no context menu, no print, etc.)
- CSP Report-Only + endpoint
- Whitelist `security.*` ×7
- Canal Monolog dédié

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Lire :
   - app/Http/Controllers/Frontend/KioskEventController.php (route + abilities check)
   - app/Http/Middleware/* (kiosk lockdown / throttle)
   - app/Services/Auth/LoginLockoutService.php (s'il existe)
   - resources/js/helpers/kioskLockdown.js
   - config/csp.php
2) Vérifier abilities :
   - `Route::post('/kiosk-event', ...)` exige `abilities:kiosk:order` ?
   - Test ability vide → 403 ; ability `pos:order` → 403 ; ability `kiosk:order` → 200
     (cf. KioskEventAbilityTest avec helper postEvent).
3) Throttle per-machine : middleware + clé inclut `KioskMachine::id`.
4) Login lockout : `email|username` collisions, fenêtre, déblocage.
5) `kioskLockdown` : F12, contextmenu, copy/paste, drag/drop, beforeunload, fullscreen.
6) CSP : header Report-Only avec endpoint, throttled, log canal `security`.
7) Whitelist `security.*` côté `kioskAnalytics` et backend `KioskEventController`.
8) Canal Monolog `security` : config/logging.php, retention.

Tests :
- tests/Feature/KioskSecurity/KioskEventAbilityTest.php
- tests/Feature/Auth/LoginLockoutTest.php
- tests/Feature/Security/CspReportTest.php

Audit cross : reports/review/AUDIT_KIOSK_110_SECURITY_2026-04-19.md (AX10-01 inclus).

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK15_SECURITY_K6_2026-04-20.md
```

## Lecture obligatoire

- `app/Http/Controllers/Frontend/KioskEventController.php`
- `tests/Feature/KioskSecurity/KioskEventAbilityTest.php`
- `config/csp.php`, `config/logging.php`
- `tasks/k-hardening/PLAN_K6_SECURITY_HARDENING_2026-04-18.md`

## Checklist multi-points

- [ ] V1. Abilities `kiosk:order` fail-closed (3 cas testés)
- [ ] V2. `branch_id` server-authoritative confirmé (croise T08)
- [ ] V3. Throttle per-machine actif
- [ ] V4. Login lockout email∥username
- [ ] V5. `kioskLockdown` DOM ≥ 5 protections
- [ ] V6. CSP Report-Only + endpoint actif
- [ ] V7. Whitelist `security.*` ×7
- [ ] V8. Canal Monolog `security` configuré

## Critères PASS / FAIL

- **PASS** : 8 V cochées + AX10-01 plan documenté pour CSP enforce.
- **FAIL** : ≥ 1 vecteur ouvert → revue sécurité humaine.

## Output

`reports/audit-orchestration/REPORT_TASK15_SECURITY_K6_2026-04-20.md`

## Si FAIL → action

→ T15b `generalPurpose` ou pentest manuel humain.
