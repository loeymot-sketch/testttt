# VERIFY-18 — Risques cachés (code mort, double source, dette tech, frozen zones)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
Détecter ce qui n'apparaît pas dans les axes officiels : fichiers obsolètes, double implémentation Order vs FrontendOrder, frozen zones touchées sans gate, TODOs critiques.

## 2. Sources OBLIGATOIRES
- `app/Services/OrderService.php` vs `FrontendOrderService.php` (symétrie)
- `resources/js/components/admin/pos/components/LoadingComponent` (le bug import .vue déjà aperçu)
- `tasks/orchestration/`, `.cursor/rules/` (frozen zones)
- Recherche `TODO|FIXME|HACK|XXX|legacy|deprecated`
- Audit : `AUDIT_POS_110_HIDDEN_RISKS_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : Une frozen zone a été modifiée sans gate (cf. `.cursor/rules/safety.mdc`).
- H2 : Double-source de calcul prix (front + back).
- H3 : Imports `.vue` cassés (Vue 3 vs 2 legacy).
- H4 : Cache LiteLLM ou Pusher commit accidentel (`reports/antigravity/...`).
- H5 : TODO P0 silencieux dans `OrderService`.

## 4. Plan multi-agent
1. **Explore A** : grep `TODO|FIXME|HACK|XXX|deprecated|legacy` priorisé par fichier critique.
2. **Explore B** : symétrie OrderService ↔ FrontendOrderService (méthode par méthode).
3. **GeneralPurpose** : produit liste `risque caché × gravité × fichier:ligne × cycle P`.

## 5. Vérifications obligatoires
- [ ] V1 : Liste TODO/FIXME P0 produite, < 10 si possible.
- [ ] V2 : Symétrie OrderService prouvée (ou diff documenté).
- [ ] V3 : Aucun frozen-zone modifié sans gate récent.
- [ ] V4 : Imports Vue tous explicites `.vue`.
- [ ] V5 : Aucune double-source prix (front pure presentation).

## 6. Critères d'acceptation
- ALL_GREEN si V1–V5 OK.
- WARN si V1 > 10.
- FAIL si V3 ou V5 cassables.

## 7. Livrables
- `reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md`

## 8. Suite
- FAIL → `P11_FROZEN_ZONE_GATE`, `P12_PRICING_FRONT_PURGE`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/18_VERIFY_HIDDEN_RISKS.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose synthèse. 0 code modifié.
Livrable: reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
