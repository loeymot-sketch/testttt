# VERIFY-08 — Fiscal NF525 (Z.open hardening, X/Z, hash chain, audit log)

**Date :** 2026-04-20  **Origine :** Audit POS 110 % (`AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`, `..._NF525_READINESS_*`) + finding `F-FISC-001`  **Priorité :** P0  **Mode :** AUDIT-ONLY

## 1. Contexte
L'audit a soulevé un risque sur l'ouverture du Z (Z.open / clôture interrompue → état incohérent), la chaîne de hashs, et l'absence de verdict NF525 ferme. Toute commande POS / RETURNED touche à la chaîne fiscale.

## 2. Sources OBLIGATOIRES
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Fiscal/ZReportService.php`, `XReportService.php`
- `app/Services/Fiscal/AuditLogService.php`
- `app/Models/Fiscal/*` (FiscalSequence, ZReport, XReport, AuditLog)
- Migrations correspondantes
- Tests : `tests/Feature/Fiscal/*`
- Audits : `AUDIT_POS_110_FISCAL_NF525_2026-04-19.md`, `AUDIT_POS_110_NF525_READINESS_2026-04-19.md`, `AUDIT_POS_110_HIDDEN_RISKS_*`
- Doc : `docs/BUSINESS_RULES.md`, éventuellement `docs/SECURITY_NOTES.md`

## 3. Hypothèses à challenger
- H1 : Z.open laisse l'état journalier ouvert si interruption (crash, timeout) → pas de recovery.
- H2 : Hash chain peut être cassée par un RETURNED tardif post-Z.
- H3 : Une commande non signée passe quand même en Z.
- H4 : Pas d'idempotence sur génération Z (double Z possible le même jour).
- H5 : Pas de séparation `branch_id` pour la séquence fiscale.
- H6 : Audit log peut être muté par un seed ou une migration.
- H7 : Format export NF525 (JET, archives) absent ou non testé.

## 4. Plan multi-agent
1. **Explore A** : services fiscaux + tests existants, recense les états transitoires et points de défaillance.
2. **Explore B** : analyse migrations / contraintes DB (immutabilité, index unique branch+sequence).
3. **GeneralPurpose** : produit checklist NF525 (signature, chaînage, archivage, exports JET/PIAF, droits) avec OK/WARN/FAIL par item.

## 5. Vérifications obligatoires
- [ ] V1 : Z.open atomique avec recovery (lock + état `OPEN/CLOSING/CLOSED`).
- [ ] V2 : Génération Z idempotente (jour × branche unique).
- [ ] V3 : Hash chain validée à l'ouverture du Z suivant.
- [ ] V4 : Toute mutation `Order` post-DELIVERED interdite hors RETURNED + audit.
- [ ] V5 : Séquence fiscale par branche, contrainte unique DB.
- [ ] V6 : Audit log immutable (no UPDATE/DELETE) — vérifier policies / triggers.
- [ ] V7 : Tests couvrant : Z normal, Z avec RETURNED, Z double-call, recovery après crash.
- [ ] V8 : Export JET (NF525) présent ou WARN explicite avec ticket P.
- [ ] V9 : Permission `pos-manage-fiscal` correctement appliquée sur toutes les routes fiscales (cf. `F-PERM-001`).

## 6. Critères d'acceptation
- ALL_GREEN si V1–V9 OK.
- WARN si V8 absent (export) mais reste à roadmap.
- FAIL si V1, V3, V4 ou V6 défaillants.

## 7. Livrables
- `reports/review/VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md`

## 8. Suite
- FAIL → cycles `P11_FISCAL_Z_OPEN_HARDENING`, `P12_FISCAL_AUDIT_LOG_IMMUTABLE`, `P13_FISCAL_EXPORT_JET`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/08_VERIFY_FISCAL_NF525_Z_OPEN.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles (A services+tests, B DB+migrations) + 1 generalPurpose synthèse + checklist NF525 par item.
0 code modifié.
Livrable: reports/review/VERIFY_08_FISCAL_NF525_Z_OPEN_2026-04-20.md
Plan 5 lignes d'abord. Conclusion "GLOBAL: ..." + cycles P proposés.
```
