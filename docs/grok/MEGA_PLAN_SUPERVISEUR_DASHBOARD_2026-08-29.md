# MEGA-PLAN SUPERVISEUR — Dashboard, contrôle, admin Grok

Patron : Grok. Employés : audits parallèles, pas 100 clones fantômes.
Claude = Big Boss. Frozen §7 intouchable. Pas de `--no-verify`, pas de wipe DB.

## Ce que « 10 boucles » veut dire ici

Dix cycles **bornés** sur le même périmètre (dashboard + cockpit + admin
Grok), chacun : preuve → adversaire → correctif → re-preuve.

Pas : réécrire POS, kiosk, KDS, fiscal, Mix 7 Mo, npm audit.

## Périmètre

| In | Out |
|---|---|
| P1 déjà patchés à re-prouver | Frozen kiosk/POS wizard/fiscal |
| Menu cockpit visible caissier | Rebuild app.js 7 Mo |
| Fail-open Vue dashboard | i18n AR/DE/BN |
| SLA unbounded, a11y live, confirm interrupteur | E2E si safety-check HALT frozen |
| Tests HTTP qui mordent | Inventer des produits |

## 10 boucles

1. Auth cockpit (API déjà 403) + menu/route
2. Fail-closed Vue dashboard permissions
3. Dates / 403 HttpException (déjà) + SLA cap serveur
4. Copy backup honnête + aria-live
5. Confirm interrupteur
6. Overview loader/erreur
7. Navigateur Admin cockpit
8. Navigateur POS : pas de cockpit
9. Tests régression dashboard + grok
10. Reasoner : ce qui reste BLOCK vs VERT ciblé
