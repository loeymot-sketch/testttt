# VERIFY-07 — P10 OrderSetupRequest (paramètres commande/livraison)

**Date :** 2026-04-20  **Origine :** P10 (commit `c00a8cd61`)  **Priorité :** P2  **Mode :** AUDIT-ONLY

## 1. Contexte
P10 a appliqué `min:0` sur tous les champs numériques de `OrderSetupRequest`. Vérifier qu'aucun calcul de livraison ou seuil min ne devienne incohérent (ex. min order = 0 → no-op, distance gratuite mal interprétée).

## 2. Sources OBLIGATOIRES
- `app/Http/Requests/OrderSetupRequest.php`
- `app/Models/OrderSetup.php`
- `app/Services/DeliveryService.php` (si présent), `app/Services/PricingService.php` (delivery_charge)
- Tests : `OrderSetupRequestNegativeValuesTest.php`
- Settings UI : `resources/js/components/admin/setting/**` (recherche OrderSetup)

## 3. Hypothèses à challenger
- H1 : `minimum_order_amount = 0` désactive accidentellement la règle (au lieu de "aucun min").
- H2 : `free_delivery_distance = 0` rend toute commande livrée gratuite.
- H3 : Champs nullable mal gérés (cast 0 vs null).
- H4 : Pas d'invariant côté back qui fail-fast si combinaison absurde.

## 4. Plan multi-agent
1. **Explore A** : Request + modèle + service.
2. **Explore B** : front settings + tests.
3. **GeneralPurpose** : matrice champ × signification métier × cas limite (0, null, négatif).

## 5. Vérifications obligatoires
- [ ] V1 : `min:0` sur tous les champs numériques.
- [ ] V2 : Documentation des champs où "0 = désactivé" vs "0 = appliqué strict".
- [ ] V3 : Tests cas limite (0 et null) couverts.
- [ ] V4 : Front input correspond aux contraintes back.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V4 prouvés et sémantique 0/null clarifiée.
- WARN si sémantique floue.
- FAIL si une combinaison casse `PricingService`.

## 7. Livrables
- `reports/review/VERIFY_07_P10_ORDER_SETUP_2026-04-20.md`

## 8. Suite
- WARN sémantique → `P11_ORDER_SETUP_SEMANTIC_DOC`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/07_VERIFY_P10_ORDER_SETUP.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose synthèse. 0 code modifié.
Livrable: reports/review/VERIFY_07_P10_ORDER_SETUP_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
