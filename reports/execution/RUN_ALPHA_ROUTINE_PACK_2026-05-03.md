# Pack α routine — SOURCE-FK runbook + vérif backfill + sentinel tokens (2026-05-03)

| Contexte | CV1-V2-REMAINING-MISSIONS-001 |
|----------|-------------------------------|
| Type     | Routine (α1 + α2 + α3), sans migration réelle dans ce rapport |

Synthèse des trois livrables demandés et des validations locales exécutées par l’exécuteur routine.

---

## Livrables (chemins)

| ID | Description | Chemin |
|----|--------------|--------|
| **α1** | Script bash staging migration SOURCE-FK + runbook associé | `scripts/migrate-source-fk-staging.sh` · `reports/execution/RUN_SOURCE_FK_STAGING_RUNBOOK_2026-05-03.md` |
| **α2** | Rapport de vérification backfill (SQL + soak / rollback) | `reports/execution/RUN_SOURCE_FK_BACKFILL_VERIFICATION_2026-05-03.md` |
| **α3** | Sentinel Vitest tokens CV1 / additions `--studio-*` | `tests/js/studioTokensAdditions.spec.js` |

**Contraintes respectées** : `cv1-tokens.css` non modifié ; aucune migration artisan réelle lancée depuis ce pack ; périmètre hors POS/Kiosk runtime, `OrderService`, zones gelées.

---

## Validation exécutée

```bash
chmod +x scripts/migrate-source-fk-staging.sh
bash scripts/migrate-source-fk-staging.sh --dry-run-help 2>&1 | head -3
npm run vitest -- tests/js/studioTokensAdditions.spec.js --run
```

| Commande | Résultat |
|----------|-----------|
| `chmod +x scripts/migrate-source-fk-staging.sh` | OK (exit 0) |
| `bash scripts/migrate-source-fk-staging.sh --dry-run-help \| head -3` | OK — aide affichée, pas de pré-flight env |
| `npm run vitest -- tests/js/studioTokensAdditions.spec.js --run` | OK — 1 fichier, 1 test passé |

*Aucun* `migrate` / backup DB réel n’a été exécuté (exigence anti-dérive).

---

## Divergences doc / code

- **Aucune** détectée entre `docs/design/DESIGN_SYSTEM_FOUNDATIONS_CV1.md` §2 (valeurs AA) et `resources/css/foundations/cv1-tokens.css` pour les 14 tokens whitelistés du sentinel ; les couches documentées (**AAA** pour `--cv1-border-default`, **reduced-motion** pour `--cv1-motion-default`) sont reflétées dans le fichier tel que testé.

---

## Escalade

- **Aucune** blocage pendant l’implémentation de ce pack.
- Réintégration ops : avant `--dry-run` ou run complet du script bash, définir `STAGING_*` et un driver SQL cohérent ; le backup et `php artisan migrate` restent **à la charge** de l’opérateur sur la cible staging.

---

## Références croisées

- Plan parent (audit) : `reports/audit/CATALOG_STUDIO_AUDIT_AND_REMEDIATION_PLAN_2026-05-03.md` §3
- Migration : `database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php`
