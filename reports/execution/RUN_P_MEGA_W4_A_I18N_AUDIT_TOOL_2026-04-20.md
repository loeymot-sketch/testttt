# RUN — P_MEGA_W4_A — i18n audit tool (P-MEGA-11)

**Date** : 2026-04-20  
**Plan** : `plans/PLAN_P_MEGA_W4_2026-04-20.md` (section W4.A)

```
EXECUTE_DELEGATION: foodking-routine-implementer
```

## Livrables

| Artefact | Chemin |
|---|---|
| Script | `tools/i18n/audit_locale_keys.mjs` |
| Vitest | `tests/js/i18nAuditTool.spec.js` (6 cas) |
| npm | `npm run i18n:audit` |
| CSV missing | `reports/i18n/missing_keys_per_locale_2026-04-20.csv` |
| CSV dead | `reports/i18n/dead_keys_2026-04-20.csv` |
| CSV identical fr=en | `reports/i18n/identical_fr_en_2026-04-20.csv` |
| Log run | `reports/i18n/run_2026-04-20.log` |

## Résultat exécution (drift post-W3-remediation)

| Locale | Missing | Dead (global) |
|---|---:|---:|
| fr | 523 | 376 |
| en | 57 | 376 |
| ar | 87 | 376 |
| de | 87 | 376 |
| bn | 88 | 376 |

- **Total clés utilisées (statiques)** : 1094  
- **Clés dynamiques (skipped, `${}` dans template literal)** : 9  
- **Identical fr=en suspects** (longueur > 5) : 14 lignes dans le CSV bonus

### Échantillon — top 20 clés manquantes `fr` (ordre CSV)

1. ` Active your license code` (installer Blade — texte brut capturé comme clé)  
2. ` Go to iNilabs`  
3. ` Login to iNilabs`  
4. `Step1: ` … `Step3: `  
5. `You can easily get the activation code…`  
6. `all.label.back_to_home` … (suite dans CSV)

Beaucoup de clés `button.*`, `menu.*`, etc. reflètent l’usage admin + kiosk hors du sous-ensemble déjà présent dans `fr.json`.

## FINDING_W4A_DRIFT_QUANTIFIED

**Seuils plan (G2 / E1)** :

- **`fr` : 523 missing** — dépasse largement le seuil **100 / locale** (E1).
- **`en` : 57 missing** — sous le seuil G2 « >100 pour en ».
- **Cumul `de`+`ar`+`bn` : 262** — **> 250** (G2 : stop auto-trad, drift massif sur locales secondaires).

**Aucune traduction automatique** dans ce cycle. Suite : décision orchestrateur (W4.B RTL vs remédiation i18n par vagues).

Recommandations alignées plan : (a) cycles de traduction par langue plafonnés ; (b) fallback `en` documenté dans `i18n.js` si accepté ; (c) réduction périmètre langues si produit le permet.

## Tests

- Vitest tool : **6/6**  
- Vitest global : **546/546** (540 baseline + 6 nouveaux)

## Exit code outil

`npm run i18n:audit` → **1** si au moins une clé manquante dans une locale (CI futur). Ici : **1** (drift réel).
