# Vérification backfill — `source_item_attribute_id` (post-migration staging)

| Date  | 2026-05-03 |
|-------|------------|
| Table | `item_wizard_steps` |
| Colonne | `source_item_attribute_id` (FK nullable → `item_attributes`) |

Ce rapport **n’exécute aucune requête** : il les documente pour un opérateur avec accès SQL à la base **staging** (ou prod après go), après application de la migration.

---

## 1. Total lignes éligibles

Lignes dont `source_type` vise un attribut catalogue et `source_ref` est **uniquement numérique** (candidats backfill).

**PostgreSQL** (opérateur `~`) :

```sql
SELECT COUNT(*) FROM item_wizard_steps
WHERE source_type = 'item_attribute'
  AND source_ref ~ '^[0-9]+$';
```

**MySQL / MariaDB** :

```sql
SELECT COUNT(*) FROM item_wizard_steps
WHERE source_type = 'item_attribute'
  AND source_ref REGEXP '^[0-9]+$';
```

Interprétation : référence pour le volume attendu de lignes concernées par le backfill déterministe (IDs valides côté `item_attributes`).

---

## 2. Lignes backfillées correctement

Jointure sur `item_attributes` : la FK renseignée correspond à un attribut existant.

```sql
SELECT COUNT(*) FROM item_wizard_steps iws
INNER JOIN item_attributes ia ON ia.id = iws.source_item_attribute_id
WHERE iws.source_type = 'item_attribute'
  AND iws.source_item_attribute_id IS NOT NULL;
```

Interprétation : doit être cohérent avec le nombre de lignes effectivement mises à jour par la migration (et ≤ requête 1 si toutes les éligibles ont un ID valide).

---

## 3. Lignes éligibles non résolues (à investiguer)

**Attendu : 0 ligne** — tout `source_ref` strictement numérique devrait avoir reçu `source_item_attribute_id` si l’ID existe ; sinon orphelin / donnée incohérente.

**PostgreSQL** :

```sql
SELECT iws.id, iws.source_ref FROM item_wizard_steps iws
WHERE iws.source_type = 'item_attribute'
  AND iws.source_ref ~ '^[0-9]+$'
  AND iws.source_item_attribute_id IS NULL;
```

**MySQL / MariaDB** :

```sql
SELECT iws.id, iws.source_ref FROM item_wizard_steps iws
WHERE iws.source_type = 'item_attribute'
  AND iws.source_ref REGEXP '^[0-9]+$'
  AND iws.source_item_attribute_id IS NULL;
```

Si le résultat n’est pas vide : analyser chaque `source_ref` (attribut supprimé, mauvaise branche, fuite de données, etc.) avant production.

---

## Soak 24–48 h (staging)

**À monitorer**

- Taux d’erreur HTTP sur les routes **`/admin/composer/*`** (5xx, 4xx inattendus).
- Latence des requêtes touchant **`ItemWizardStep`** (APM, logs requêtes lentes).
- Exceptions agrégées (**Sentry** / **Bugsnag** / équivalent) liées au composer / wizard / attributs.

**Critères Go / No-Go vers production**

- **0** nouvelle erreur **500** attribuable au composer sur `/admin/composer/*` pendant la fenêtre de soak.
- **0** timeout de requête lié aux parcours composer (seuils conformes à la politique interne).
- **Requête #3** : **0 ligne** (ou écart **documenté et accepté** par le produit / data).

**Rollback**

```bash
php artisan migrate:rollback --path=database/migrations/2026_05_03_200500_add_source_item_attribute_id_to_item_wizard_steps_table.php --force
```

---

## Références

- Runbook opérationnel : `reports/execution/RUN_SOURCE_FK_STAGING_RUNBOOK_2026-05-03.md`
- Pack α : `reports/execution/RUN_ALPHA_ROUTINE_PACK_2026-05-03.md`
