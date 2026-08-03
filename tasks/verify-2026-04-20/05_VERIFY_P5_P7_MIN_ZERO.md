# VERIFY-05 — P5/P6/P7 Validation `min:0` (kiosk / table / POS — montants)

**Date :** 2026-04-20  **Origine :** P5/6/7 (commits `87491043c`, `952b840b1`, `19476d56b`)  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
P5–P7 ont durci `OrderRequest`, `TableOrderRequest`, `PosOrderRequest` avec `min:0` sur subtotal/total/discount/delivery_charge/pos_received_amount. Vérifier exhaustivité, absence de régression sur paiements partiels (avoirs), contrôle côté front, cohérence avec PricingService SSOT.

## 2. Sources OBLIGATOIRES
- `app/Http/Requests/OrderRequest.php`
- `app/Http/Requests/TableOrderRequest.php`
- `app/Http/Requests/PosOrderRequest.php`
- `app/Services/PricingService.php`
- Tests : `OrderRequestNegativeTotalTest`, `TableOrderNegativeTotalTest`, `PosOrderRequestNullableTotalTest`
- Audit : `AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : `discount` peut être négatif via un autre champ (ex. `loyalty_discount`, `promo_discount`).
- H2 : `pos_received_amount` 0 + change positif → bug rendu monnaie.
- H3 : Un total à 0 sans items est accepté (commande fantôme).
- H4 : Front envoie une string "-1" qui passe la validation côté browser.
- H5 : Pas de symétrie sur Frontend vs Admin paths.

## 4. Plan multi-agent
1. **Explore A** : énumère TOUS les Requests / DTOs avec champs monétaires (recherche `subtotal|total|discount|delivery|received|amount|tax|tip`).
2. **Explore B** : énumère côté front les champs envoyés au back et leur validation.
3. **GeneralPurpose** : matrice champ × Request × min:0 × test.

## 5. Vérifications obligatoires
- [ ] V1 : Tous les champs monétaires ont `min:0` (ou justification).
- [ ] V2 : `numeric|decimal:0,2` cohérent (pas de `integer` sur prix).
- [ ] V3 : Tests PHPUnit couvrent négatif + zéro + null.
- [ ] V4 : Pas de bypass via `merge` ou `prepareForValidation`.
- [ ] V5 : Front (Vue) refuse aussi les valeurs négatives (input `min="0"`).
- [ ] V6 : `PricingService` recalcule SSOT et corrige toute incohérence reçue.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V6 OK et matrice exhaustive.
- WARN si 1-2 champs sans `min:0` mais sans impact métier.
- FAIL si bypass possible.

## 7. Livrables
- `reports/review/VERIFY_05_P5_P7_MIN_ZERO_2026-04-20.md`

## 8. Suite
- FAIL → cycle `P11_REQ_MONEY_HARDENING_FULL`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/05_VERIFY_P5_P7_MIN_ZERO.md, applique §4-§7.

OBLIGATIONS: 2 subagents `explore` parallèles + 1 `generalPurpose` synthèse, matrice complète. 0 code modifié.
Livrable: reports/review/VERIFY_05_P5_P7_MIN_ZERO_2026-04-20.md
Plan d'abord (5 lignes). Conclusion "GLOBAL: ..." + cycles P.
```
