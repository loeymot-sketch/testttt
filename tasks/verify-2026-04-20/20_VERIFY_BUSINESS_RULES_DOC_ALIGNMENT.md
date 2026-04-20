# VERIFY-20 — Alignement `docs/BUSINESS_RULES.md` ↔ code (P1 stock, P3 RETURNED, coupons, NF525)

**Date :** 2026-04-20  **Origine :** finding doc `F-DOC-001` + dérives constatées  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
La passation indique `docs/BUSINESS_RULES.md` potentiellement obsolète vs code après P1 (stock/dispo branche), P3 (RETURNED), P8/P9 (coupons), audit fiscal NF525. Si la doc devient source d'erreur pour les nouveaux contributeurs, on régresse silencieusement.

## 2. Sources OBLIGATOIRES
- `docs/BUSINESS_RULES.md`
- `docs/ORDER_FLOW.md`, `docs/ARCHITECTURE.md`, `docs/PROJECT_CONTINUITY_AND_VISION.md`
- Code de référence : services impactés par P1/P3/P4/P8/P9
- Audit : `AUDIT_POS_110_HIDDEN_RISKS_*`, `AUDIT_POS_110_FISCAL_NF525_*`

## 3. Hypothèses à challenger
- H1 : Section "Stock" évoque seulement quantité globale (pas dispo branche).
- H2 : Section "Annulation" ne mentionne pas RETURNED ni motif obligatoire.
- H3 : Section "Coupon" ne couvre pas `limit_per_user` ni `min:0`.
- H4 : Aucune section NF525 / Z.open / hash chain.
- H5 : Vision SaaS multi-branche / multi-tenant non claire dans la doc.

## 4. Plan multi-agent
1. **Explore A** : lecture intégrale doc + extraction des règles.
2. **Explore B** : lecture code services + extraction des règles réellement appliquées.
3. **GeneralPurpose** : produit diff doc/code section par section + propose patch doc.

## 5. Vérifications obligatoires
- [ ] V1 : Diff section stock (P1) écrit.
- [ ] V2 : Diff section annulation/RETURNED (P3) écrit.
- [ ] V3 : Diff section coupon (P8/P9) écrit.
- [ ] V4 : Section NF525 présente ou note manquante explicite.
- [ ] V5 : Patch doc proposé en annexe (markdown ready-to-commit), sans toucher au fichier doc.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V5 OK avec patch attaché.
- WARN si V4 manque (juste à roadmap doc).
- FAIL si la doc induit en erreur sur P1/P3 (priorité immédiate).

## 7. Livrables
- `reports/review/VERIFY_20_BUSINESS_RULES_DOC_ALIGNMENT_2026-04-20.md` (avec patch markdown intégré, **non appliqué**)

## 8. Suite
- FAIL/WARN → cycle `P11_DOC_BUSINESS_RULES_SYNC` (Composer routine, application du patch + revue).

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/20_VERIFY_BUSINESS_RULES_DOC_ALIGNMENT.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose diff + patch proposé en annexe (NON appliqué).
0 modification de docs/BUSINESS_RULES.md (patch seulement dans le rapport).
Livrable: reports/review/VERIFY_20_BUSINESS_RULES_DOC_ALIGNMENT_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
