# VERIFY-06 — P8 + P9 Coupons (public + admin)

**Date :** 2026-04-20  **Origine :** P8 (`4113423fb`), P9 (`649d18d06`)  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
P8 = `min:0` sur `CouponCheckRequest::total`. P9 = `min:0` sur `CouponRequest` (discount, minimum_order, maximum_discount, limit_per_user). Vérifier que la **logique métier coupon** est intègre : pas de discount > total, pas de cumul dangereux, pas de bypass via `loyalty_customer_code`.

## 2. Sources OBLIGATOIRES
- `app/Http/Requests/CouponCheckRequest.php`, `CouponRequest.php`
- `app/Http/Controllers/Frontend/CouponController.php`, `Admin/CouponController.php`
- `app/Services/CouponService.php` (s'il existe)
- `app/Services/PricingService.php` (application coupon)
- Tests : `CouponCheckNegativeTotalTest`, `CouponRequestNegativeAmountsTest`, autres tests coupon
- Doc : `docs/BUSINESS_RULES.md`
- Audits : `AUDIT_POS_110_PAYMENTS_REFUND_2026-04-19.md`, `..._SECURITY_*`

## 3. Hypothèses à challenger
- H1 : `discount` admin peut excéder `maximum_discount` via override.
- H2 : `limit_per_user` non vérifié sur reorder ou commande staff.
- H3 : Un coupon expiré reste applicable côté kiosk legacy.
- H4 : Cumul coupon + loyalty produit total négatif (devrait être min:0 final).
- H5 : Coupon par branche : pas de check `branch_id`.

## 4. Plan multi-agent
1. **Explore A** : back — flux check + apply, formules, garde-fous.
2. **Explore B** : tests + UI (input admin + input kiosk).
3. **GeneralPurpose** : matrice cas (cumul, expiré, branche, limit, pourcentage vs montant fixe).

## 5. Vérifications obligatoires
- [ ] V1 : Discount appliqué borné par `min(maximum_discount, total)`.
- [ ] V2 : `limit_per_user` vérifié dans **tous** les chemins (kiosk, POS, online, reorder).
- [ ] V3 : Test E2E ou Feature pour expiration coupon.
- [ ] V4 : Coupon par branche → check `branch_id` dans CouponService.
- [ ] V5 : Pas de calcul prix coupon côté front (SSOT back).
- [ ] V6 : Audit log écrit le coupon utilisé.
- [ ] V7 : i18n des messages d'erreur coupon présents (FR/EN).

## 6. Critères d'acceptation
- ALL_GREEN si V1–V7 prouvés.
- WARN si V6 partiel.
- FAIL si bypass `limit_per_user` ou cumul mal borné.

## 7. Livrables
- `reports/review/VERIFY_06_P8_P9_COUPONS_2026-04-20.md`

## 8. Suite
- FAIL cumul → `P11_COUPON_CUMUL_BOUND`.
- FAIL limit → `P11_COUPON_LIMIT_PER_USER_GLOBAL`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/06_VERIFY_P8_P9_COUPONS.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose synthèse. 0 code modifié.
Livrable: reports/review/VERIFY_06_P8_P9_COUPONS_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```
