# RUN — P_MEGA_W4_REMEDIATION_3 (locale desync + i18n audit tool)

```
EXECUTE_DELEGATION: foodking-routine-implementer
REMEDIATION_ATTEMPT_3: outcome: PASSED, delegated_to: foodking-routine-implementer
```

## Bug signatures

| ID | Severity | Symptom |
|----|----------|---------|
| SEV-1 | Critical | Après reload kiosk, `kioskSettings.locale=ar` réhydraté → `<html dir/lang>` en ar mais `vue-i18n` restait sur `fr` (`ensureKioskLocale` + absence de `setLocale` dans le chemin a11y). |
| MED-2 | Medium | L’outil d’audit comptait des `$t('…')` présents dans des commentaires HTML / blocs `/* */`. |
| MED-3 | Medium | `t('key', { … })` et variantes `i18n.global.t` / `useI18n().t` non capturées par le regex strict `…)`. |
| LOW-4 | Low | Regex template literal `$t(\`…\`)` sans prise en charge des newlines dans le contenu backtick. |

## Root causes

- **SEV-1** : `ensureKioskLocale()` forçait `KIOSK_LOCALE` (`fr`) à chaque navigation `/kiosk` ; `applyKioskA11yFromStore` et `_wireA11yWatchers.applyLocale` mettaient seulement les attributs HTML sans synchroniser `i18n.global.locale`.
- **MED-2** : Extraction regex sur le fichier brut, sans retirer les zones commentées.
- **MED-3** : Motif `t('k',)` exigeait `)` immédiatement après la quote fermante.
- **LOW-4** : Classe de caractères `[^`]` n’inclut pas le saut de ligne.

## Correction plan (applied)

1. **SEV-1** : `setLocale` depuis `applyKioskA11yFromStore` (SSOT avec `i18n` + `setDocumentDirection`) ; même logique dans le watcher locale de `useKioskA11y` ; `applyLocale` dans `KioskAppComponent` appelle `setLocale` ; `ensureKioskLocale` rendu no-op pour ne plus écraser la locale persistée.
2. **MED-2** : `stripVueSourceForAudit` — suppression `<!-- … -->` et `/* … */` avant les regex.
3. **MED-3** : `reUtilT` assoupli avec `\s*[,)]` ; ajout `i18n.global.t` et `useI18n().t` avec le même suffixe.
4. **LOW-4** : `[\s\S]*?` pour le contenu des template literals dans `reTpl` / `reI18nTpl`.

## Tests

- `tests/js/i18nAuditTool.spec.js` : cas 11, 12, 13 (+ couverture strip via `extractI18nKeysFromVue`).
- `tests/js/kioskRtl.spec.js` : reload (store `ar`, i18n initial `fr`) + `ensureKioskLocale` après `applyKioskA11yFromStore`.

## Vitest

- Ciblés (`i18nAuditTool` + `kioskRtl`) : **19/19** verts.
- Suite complète : **565/566** verts au moment du run ; **1** échec préexistant hors périmètre (`tests/js/posNormalizeIds.spec.js`, attente `item_variations` vs implémentation avec `item_attribute_id: null`).

## Findings nouveaux

- 0 (hors limitation documentée : commentaires `//` non stripés).

## Commit

Message : `[P-MEGA-W4-REMEDIATION] Fix locale desync reload + i18n tool regex (strip comments + t with opts + multiline)` — hash : `git log -1 --oneline` sur la branche courante.
