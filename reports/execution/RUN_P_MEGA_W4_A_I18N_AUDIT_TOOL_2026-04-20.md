# RUN — P_MEGA_W4_A — i18n audit tool (P-MEGA-11)

**Date** : 2026-04-20  
**Plan** : `plans/PLAN_P_MEGA_W4_2026-04-20.md` (section W4.A)

```
EXECUTE_DELEGATION: foodking-routine-implementer
```

## REMEDIATION_ATTEMPT_2

- **outcome**: PASSED
- **delegated_to**: foodking-routine-implementer
- **bug_signature**: `i18n_audit_tool_blade_php_conflation`
- **root_cause**: design tool : ne distingue pas source path → cible locale (Vue `resources/js/languages/*.json` vs Laravel `lang/*/*.php`)
- **correction_plan**: deux passes séparées (Vue/JS → JSON ; Blade/app PHP → fichiers PHP par locale) ; parser PHP best-effort (clés chaîne + nested) ; CSV `missing_keys_per_locale_VUE_*` et `missing_keys_per_locale_LARAVEL_*` ; regex Laravel `__`, `@lang`, `Lang::get`, `trans` ; exports tests `parsePhpLocaleFile`, `resolveBladeKeyToPhp`

## Livrables

| Artefact | Chemin |
|---|---|
| Script | `tools/i18n/audit_locale_keys.mjs` |
| Vitest | `tests/js/i18nAuditTool.spec.js` (10 cas) |
| npm | `npm run i18n:audit` |
| CSV missing VUE | `reports/i18n/missing_keys_per_locale_VUE_2026-04-20.csv` |
| CSV missing LARAVEL | `reports/i18n/missing_keys_per_locale_LARAVEL_2026-04-20.csv` |
| CSV dead / identical | `dead_keys_VUE_*`, `dead_keys_LARAVEL_*`, `identical_fr_en_VUE_*`, `identical_fr_en_LARAVEL_*` |

## Drift RÉEL post-fix (quantifié)

### VUE (`resources/js/languages`)

| Locale | Missing | Notes |
|---|---:|---|
| fr | 510 | **> 50** — drift réel clés Vue/JS absentes de `fr.json` (PRIMARY attendu plus complet ; hors bug conflation Blade) |
| en | 44 | |
| ar | 74 | |
| de | 74 | |
| bn | 75 | |

- **Used (statiques)** : 1081  
- **Dead** : 376  
- **Identical fr=en** (> 5 chars) : 14  

### LARAVEL (`lang/`)

| Locale | Missing | Notes |
|---|---:|---|
| fr | 20 | **< 50** — acceptable vs seuil « fr massif » E1 |
| en | 20 | |
| ar | 25 | |
| de | 36 | |
| bn | 33 | |

- **Used (statiques)** : 154  
- **Dead** : 142  
- **Identical fr=en** : 169  
- **Fichiers PHP lus** : 80 | **Échecs parse** : 0 (fichiers à clés enum / non-chaîne ignorés sans crash)

## Verdict drift réel

- **Conflation Blade/JSON** : corrigée (fr Laravel ~20 vs ~523 faux positifs « missing dans fr.json »).
- **Vue `fr` 510 missing** : **cycle correctif W4.A++ ou vagues trad** requis pour aligner JSON sur les clés réellement utilisées en Vue/JS — pas bloquant pour la validité de l’outil post-fix.
- **Laravel** : drift modéré, cohérent avec PRIMARY `lang/fr`.

## Tests

- Vitest tool : **10/10**  
- Vitest global : **550/550** (546 baseline + 4 nouveaux)

## Exit code outil

`npm run i18n:audit` → **1** si au moins une clé manquante (Vue ou Laravel).

## Historique — exécution initiale (avant REMEDIATION_ATTEMPT_2)

| Locale | Missing (mélangé) | Dead (global) |
|---|---:|---:|
| fr | 523 | 376 |

Ces chiffres mélangeaient Laravel Blade avec les JSON Vue ; ne pas utiliser pour décision produit.
