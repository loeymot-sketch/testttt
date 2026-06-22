# VERIFY-13 — Data integrity (migrations, contraintes, soft-delete, schema)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_DATA_2026-04-19.md`  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
Vérifier que la base est cohérente : indexes utiles, contraintes (NOT NULL, FK, unique), soft-deletes correctement gérés, migrations idempotentes, schema doc à jour.

## 2. Sources OBLIGATOIRES
- `database/migrations/**`
- `app/Models/**`
- `docs/DATABASE_SCHEMA_CORE.md`
- Tests : `tests/Unit/Domain/**`, `tests/Feature/V1_DATA*`
- Audit : `AUDIT_POS_110_DATA_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : Pas d'index sur `(branch_id, status)` ou `(branch_id, created_at)` → perf KDS/POS.
- H2 : `Order.restore()` bloqué côté code mais soft-delete actif → fuite données.
- H3 : FK manquantes (orphelins possibles : OrderItem sans Order).
- H4 : `unique` manquant sur séquences fiscales.
- H5 : Schema doc divergent avec migrations actuelles.
- H6 : Cast Eloquent incorrects (decimal vs integer).

## 4. Plan multi-agent
1. **Explore A** : migrations + models (back).
2. **Explore B** : doc schema + tests data.
3. **GeneralPurpose** : matrice table × indexes × FK × soft-delete + diff doc/code.

## 5. Vérifications obligatoires
- [ ] V1 : Indexes sur colonnes scope branch + filtre fréquent.
- [ ] V2 : FK avec `onDelete` documenté pour chaque table critique.
- [ ] V3 : Unique sur séquences fiscales `(branch_id, year, sequence)`.
- [ ] V4 : Soft-delete activé seulement si justifié (Order doc dit non-restorable).
- [ ] V5 : Casts Eloquent: prix en `decimal:2` ou string (jamais float).
- [ ] V6 : Doc `DATABASE_SCHEMA_CORE.md` aligné avec dernier état.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V6 OK.
- WARN si V6 doc non alignée.
- FAIL si FK manquante critique ou cast float sur prix.

## 7. Livrables
- `reports/review/VERIFY_13_DATA_INTEGRITY_2026-04-20.md`

## 8. Suite
- FAIL → `P11_DATA_INDEXES_FK`, `P12_DATA_DOC_SYNC`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/13_VERIFY_DATA_INTEGRITY.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose matrice + diff doc/code. 0 code modifié.
Livrable: reports/review/VERIFY_13_DATA_INTEGRITY_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
