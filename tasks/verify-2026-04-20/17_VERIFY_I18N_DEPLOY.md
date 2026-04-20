# VERIFY-17 — i18n complet + déploiement (env, secrets, build)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_I18N_DEPLOY_2026-04-19.md`  **Priorité :** P2  **Mode :** AUDIT-ONLY

## 1. Contexte
Vérifier i18n exhaustive (FR+EN minimum, autres si présents) sur surfaces POS/Kiosk/KDS/OSS, et que le pipeline de déploiement est sain (env vars, build assets, migrations, seeders).

## 2. Sources OBLIGATOIRES
- `lang/**`, `resources/js/languages/*.json`
- `package.json`, `vite.config.js`
- `.env.example`
- `composer.json`
- Scripts deploy si présents (`scripts/`, `Makefile`, `bin/`)
- Audit : `AUDIT_POS_110_I18N_DEPLOY_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : Clé i18n manquante en EN sur les nouveaux composants kiosk cash / TR.
- H2 : Hardcoded strings en FR dans `.vue`.
- H3 : `.env.example` incomplet (manque variables Pusher / fiscal).
- H4 : Build prod fail sur warnings.
- H5 : Aucune procédure documentée pour run migrations en prod sans downtime.

## 4. Plan multi-agent
1. **Explore A** : i18n (clés FR vs EN, hardcoded grep).
2. **Explore B** : pipeline deploy + env.
3. **GeneralPurpose** : matrice clé × locale + checklist deploy (12-factor like).

## 5. Vérifications obligatoires
- [ ] V1 : Toutes les clés des nouveaux composants (P1–P10) traduites FR+EN.
- [ ] V2 : Aucun string hardcodé dans templates Vue (recherche regex `>[A-Z][a-z]{3,}`).
- [ ] V3 : `.env.example` à jour (Pusher, fiscal, queue, mail, sentry, etc.).
- [ ] V4 : Build prod `npm run build` sans warning critique.
- [ ] V5 : `php artisan optimize` + `migrate --force` documentés.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V5 OK.
- WARN si V2 quelques exceptions.
- FAIL si V1 rouge sur surface client.

## 7. Livrables
- `reports/review/VERIFY_17_I18N_DEPLOY_2026-04-20.md`

## 8. Suite
- FAIL → `P11_I18N_COMPLETE_FR_EN`, `P12_DEPLOY_PROCEDURE_DOC`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/17_VERIFY_I18N_DEPLOY.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose matrice/checklist. 0 code modifié.
Livrable: reports/review/VERIFY_17_I18N_DEPLOY_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
