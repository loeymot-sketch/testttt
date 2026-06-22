# VERIFY-06 — P8 + P9 Coupons (public + admin)

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (zero code modified)  **Origine :** P8 (`4113423fb`), P9 (`649d18d06`)
**Task :** `tasks/verify-2026-04-20/06_VERIFY_P8_P9_COUPONS.md`
**Verdict global :** **FAIL** (V2 partiel sur kiosk + V4 absent + V6 partiel ; V1/V5/V7 OK ; V3 partiel)

---

## 1. Plan exécuté (5 lignes)
1. Lecture intégrale de la tâche + `safety.mdc` + `scope.mdc` + `project-invariants.mdc` (aucune écriture hors livrable).
2. Pass A backend : `CouponService`, `Pricing\PricingService`, `Pricing\DiscountCalculator`, `OrderService` (3 flux), `FrontendOrderService`, migrations `coupons` / `order_coupons`, `Coupon` model, `CouponCheckRequest`, `CouponRequest`.
3. Pass B frontend : `frontend/checkout/CouponComponent.vue`, `admin/pos/PosComponent.vue` + `PaymentComponent.vue`, store `frontendCoupon.js`, `lang/fr/all.php` + `lang/en/all.php`.
4. Construction matrice scénarios : expiré / hors-fenêtre / branche / limit_per_user / double-apply / cumul loyalty / max_discount / 100 %.
5. Vérification V1–V7 avec preuves file:line, conclusion + cycles P de remédiation.

---

## 2. Sources OBLIGATOIRES — relevé file:line

| Artefact | Chemin | Lignes clés |
|---|---|---|
| Request public | `app/Http/Requests/CouponCheckRequest.php` | 25-32 (`total min:0`) |
| Request admin | `app/Http/Requests/CouponRequest.php` | 28-48 (P9 `min:0` partout) ; 50-78 (`withValidator` : %≤100, min_order≥discount fixe, dates) |
| Controller public | `app/Http/Controllers/Frontend/CouponController.php` | 36-43 (`couponChecking`) |
| Controller admin | `app/Http/Controllers/Admin/CouponController.php` | 24-29 (`permission:coupons*`) ; 40-79 (CRUD) |
| Service métier | `app/Services/CouponService.php` | 233-241 (`couponChecking`) ; 248-263 (`resolveById/ByCode`) ; 268-280 (`calculateDiscountAmount` borné) ; 287-321 (`validateCouponForOrder`) |
| Pricing SSOT | `app/Services/Pricing/PricingService.php` | 217-234 (coupon / manuel / `max(0.0, $rawTotal)`) |
| Discount delegate | `app/Services/Pricing/DiscountCalculator.php` | 12-29 (coupon/manuel) ; 36-64 (loyalty exclu si coupon) |
| Order POS | `app/Services/OrderService.php` | 611-627 (SSOT POS) ; 779-793 (table legacy) ; 880-888 (insert OrderCoupon) ; 921-949 (`ActionLog` + `AuditLogService` NF525) ; 1168-1182 ; 1235-1242 |
| Order Frontend/Kiosk | `app/Services/FrontendOrderService.php` | 416-433 (resolve) ; 449-450 (loyalty skipped si coupon) ; 451-514 (loyalty redeem ledger) ; 519-533 (totaux + `max(0,…)`) ; 555-562 (insert OrderCoupon) |
| Modèle coupon | `app/Models/Coupon.php` | 16-40 (fillable / casts) — **pas de `branch_id`** |
| Migration coupons | `database/migrations/2022_11_17_110910_create_coupons_table.php` | 16-33 — **pas de `branch_id`, pas de `total_uses_max`** |
| Migration order_coupons | `database/migrations/2022_11_17_120625_create_order_coupons_table.php` | 16-27 — pas d’index unique `(coupon_id,order_id)` |
| Tests P8 | `tests/Feature/CouponCheckNegativeTotalTest.php` | 21-31 |
| Tests P9 | `tests/Feature/CouponRequestNegativeAmountsTest.php` | 40-68 |
| Test cumul loyalty | `tests/Feature/FrontendDiscountIntegrityTest.php` | 128-199 |
| Test discount calc | `tests/Unit/Services/Pricing/DiscountCalculatorTest.php` | 86-89 (mock expiré) |
| UI public | `resources/js/components/frontend/checkout/CouponComponent.vue` | 41-49 (form) ; 155-167 (POST `/api/frontend/coupon/coupon-checking`) |
| Store front | `resources/js/store/modules/frontend/frontendCoupon.js` | 50 (POST checking) |
| Permission UI POS | `resources/js/components/admin/pos/PosComponent.vue` | 1341-1366 (motif obligatoire + caps client) |
| Request POS | `app/Http/Requests/PosOrderRequest.php` | 51-78 (`coupon_id` numérique nullable, `discount min:0`) ; 123-161 (gates 10 %/50 %/100 %) |
| i18n FR | `lang/fr/all.php` | 70-72, 95 (5 clés coupon) |
| i18n EN | `lang/en/all.php` | 70-72, 95 (5 clés coupon) |

---

## 3. Hypothèses challengées

| H | Énoncé | Verdict | Preuve |
|---|---|---|---|
| H1 | `discount` admin > `maximum_discount` via override | **PARTIEL** | `CouponRequest::withValidator` borne le pourcentage à 100 (l.62) et impose `minimum_order ≥ discount` pour FIXED (l.66), mais **aucune** règle n’impose `discount ≤ maximum_discount` ou `maximum_discount > 0`. `maximum_discount = 0` désactive le plafond (`CouponService.php:274-277`). Mitigé par `min(amount, subtotal)` final. |
| H2 | `limit_per_user` non vérifié sur tous les chemins | **PARTIEL/FAIL** | Vérifié partout via `validateCouponForOrder` mais le **`user_id`** comparé varie : web `Auth::id()` (client) ✓ ; POS `customer_id` ✓ ; **Table : `customer_id ?? 0`** → si commande sans client, compteur basé sur user_id=0, **limite contournée** (`OrderService.php:783, 1172`). **Kiosk : `Auth::id()` = utilisateur machine kiosk**, pas le client loyalty (`FrontendOrderService.php:419-431, 559`) → un même coupon peut être consommé N fois par N clients différents via la même borne. |
| H3 | Coupon expiré applicable côté kiosk legacy | **OK** | `validateCouponForOrder` rejette si `now > end_date` ou `now < start_date` (`CouponService.php:300-306`). Chemin unique appelé par tous les flux (legacy + SSOT). |
| H4 | Cumul coupon + loyalty produit total négatif | **OK** | Coupon prioritaire, loyalty mise à 0 quand coupon présent (`DiscountCalculator.php:38-40` ; `FrontendOrderService.php:449-450`). Total final plancher : `max(0, …)` (`PricingService.php:234`, `FrontendOrderService.php:521`, `OrderService.php:1225`). Test `FrontendDiscountIntegrityTest::test_coupon_takes_priority_over_loyalty_discount_on_frontend_order` (l.165-199). |
| H5 | Coupon par branche → check `branch_id` | **FAIL** | **Aucune colonne `branch_id` dans `coupons`** (migration l.16-33). `CouponService` ne filtre jamais par branche. Un coupon créé pour la branche A est applicable sur la branche B. Invariant `safety.mdc#règle 2` (Isolation branche) **violé sur le périmètre coupon**. |

---

## 4. Matrice de scénarios

| # | Scénario | Comportement réel | Attendu | Statut |
|---|---|---|---|---|
| S1 | `couponChecking({total: -10})` | 422 `total must be ≥ 0` (P8) | reject | ✓ |
| S2 | Admin POST coupon `discount=-5` ou `minimum_order=-1` | 422 (P9) | reject | ✓ |
| S3 | Coupon `end_date < now` appliqué via `/api/frontend/order` | Throw `coupon_date_expired` 422 | reject | ✓ |
| S4 | Coupon `start_date > now` | Throw `coupon_not_yet_active` 422 | reject | ✓ |
| S5 | Coupon `minimum_order=100`, panier 50 € | Throw `minimum_order_amount` 422 | reject | ✓ |
| S6 | Coupon `discount=100 % PERCENTAGE`, panier 50 € | `amount = 50` puis `min(amount, subtotal) = 50`, total final `max(0, 50−50) = 0` | total=0, jamais <0 | ✓ |
| S7 | Coupon `discount=200 € FIXED`, panier 50 € | `min(200, 50) = 50` → total 0 | ≤ subtotal | ✓ |
| S8 | Coupon `discount=20 €`, `maximum_discount=5` | Plafond 5 (`CouponService.php:274-277`) | cap appliqué | ✓ |
| S9 | Coupon `discount=20 €`, `maximum_discount=0` | **Pas de cap** appliqué (cap actif uniquement si > 0) ; bridé in-fine par subtotal | mitigé | WARN |
| S10 | `limit_per_user=1`, second usage par même client web | Throw `coupon_limit_exceeded` 422 | reject | ✓ |
| S11 | `limit_per_user=1`, deuxième usage **kiosk** par client B (autre `loyalty_code`) sur même borne | **Accepté** (compteur basé sur `Auth::id()` = machine) | reject | **FAIL** |
| S12 | `limit_per_user=1`, table_order **anonyme** (`customer_id = null/0`) | **Accepté** indéfiniment (compteur basé sur user_id=0) | reject | **FAIL** |
| S13 | Cumul coupon + loyalty (kiosk) | Loyalty ignorée, coupon seul (log info) | priorité coupon | ✓ |
| S14 | Double-apply : 2 requêtes concurrentes même client (race) | Aucune unicité DB sur `(coupon_id, user_id)` ni lock — limite testée par `count() < limit` non transactionnel | risque race | WARN |
| S15 | Coupon créé sans `branch_id` appliqué sur branche distincte | **Accepté** (pas de scope) | reject ou opt-in cross-branch | **FAIL** |
| S16 | Insert OrderCoupon : POS écrit `Auth` (cashier) ? | Non, `user_id = customer_id` ✓ ; mais kiosk `user_id = machine` | tracer le client réel | WARN |
| S17 | Audit log NF525 sur usage coupon kiosk/web | **Absent** côté `FrontendOrderService` (pas d’`AuditLogService::write`) | écrit | WARN |
| S18 | Audit log NF525 sur usage coupon POS | Présent `order.discount_applied` (`OrderService.php:933-949`) | écrit | ✓ |

---

## 5. Vérifications obligatoires (V1–V7)

### V1 — Discount borné par `min(maximum_discount, total)` — **OK**
Preuve : `app/Services/CouponService.php:268-280`.
```
$amount = ($subtotal * discount)/100 | discount;
if (max_discount > 0 && amount > max_discount) amount = max_discount;
return round(max(0, min(amount, subtotal)), 2);
```
Plus floor global `max(0.0, $rawTotal)` à `PricingService.php:234`, `FrontendOrderService.php:521`, `OrderService.php:1225`. Aucun chemin ne peut produire de total < 0.

### V2 — `limit_per_user` vérifié sur tous chemins — **PARTIEL → FAIL**
Path unique `validateCouponForOrder` (`CouponService.php:308-318`) compte `OrderCoupon` par `(user_id, coupon_id)`.
- Web `OrderService::myOrderStore` : `Auth::id()` (client) ✓ (l.319-320)
- POS `OrderService::posOrderStore` : `customer_id` ✓ (l.617-618)
- Table `OrderService::tableOrderStore` legacy : `customer_id ?? 0` ❌ (l.783, 1172)
- Kiosk/web `FrontendOrderService::frontendOrderStore` : `Auth::id()` = utilisateur kiosk machine ❌ (l.420, 431)
- Insert `OrderCoupon` côté kiosk : `user_id = Auth::user()->id` (machine) — incohérent avec le concept « par client ».

**Conséquence métier :** un coupon `limit_per_user=1` peut être réutilisé indéfiniment via une seule borne kiosk, ou via des table-orders anonymes.

### V3 — Test E2E/Feature pour expiration coupon — **PARTIEL (WARN)**
- Unitaire mockant l’exception : `tests/Unit/Services/Pricing/DiscountCalculatorTest.php:86-89`.
- Aucun test Feature explicite ne POST sur `/api/frontend/coupon/coupon-checking` ou `/api/frontend/order` avec un coupon dont `end_date < now`. Couverture indirecte uniquement.

### V4 — Coupon par branche → check `branch_id` — **FAIL**
- Migration `coupons` : aucune colonne `branch_id` (l.16-33).
- Modèle `Coupon` : aucun cast/relation `branch_id`.
- `CouponService::validateCouponForOrder` n’examine jamais la branche commande.
- Violation directe de `safety.mdc` Règle 2 (Isolation branche) et invariant 3 (`branch_id` data isolation).
- **Toute branche peut consommer tout coupon global.**

### V5 — Pas de calcul prix coupon côté front (SSOT back) — **OK**
- `frontendCoupon.js:50` POST le code → réponse serveur.
- `CouponComponent.vue:155-167` affiche `res.data.data.currency_discount` (formaté backend).
- POS : `discount` envoyé par UI mais recalculé serveur (`PricingService.php:217-230` ; `PosOrderRequest.php:51`).
- `discount_amount` de coupon n’est **jamais** calculé en JS.

### V6 — Audit log écrit le coupon utilisé — **PARTIEL (WARN)**
- POS : `\App\Models\ActionLog::create` (l.921-926) **et** `AuditLogService::write` NF525 (l.933-949) avec `coupon_id` + `discount_amount` + `discount_type`.
- Table : `ActionLog` + (selon branche) `AuditLogService` similaire (l.1245-…).
- Frontend/Kiosk : **aucun `AuditLogService::write`** dans `FrontendOrderService` malgré insertion `OrderCoupon`. Trace technique présente (table `order_coupons`), trace fiscale chainée HMAC absente côté kiosk/web.

### V7 — i18n des messages d’erreur coupon FR/EN — **OK**
`lang/fr/all.php:70-72,95` et `lang/en/all.php:70-72,95` couvrent : `coupon_not_exist`, `coupon_date_expired`, `coupon_not_yet_active`, `coupon_limit_exceeded`, `minimum_order_amount`. Symétrie totale FR↔EN sur les 5 clés émises par `validateCouponForOrder`.

---

## 6. Verdict par invariant FoodKing

| Invariant | Statut |
|---|---|
| Backend Pricing SSOT | OK (V5) |
| OrderStatus enum | N/A (hors scope coupon) |
| `branch_id` isolation | **VIOLÉ** — coupons globaux (V4) |
| Dispatch after commit | OK (notifications post-`DB::transaction`) |
| OrderService / FrontendOrderService symmetry | **Asymétrie** — audit log + cible `user_id` divergent (V2, V6) |
| Frozen zones | non touchées (audit-only) |

---

## 7. Conclusion

**GLOBAL : FAIL**

P8 et P9 (validations `min:0`) sont **prouvés** par tests dédiés et bornes serveur ; le pipeline de calcul est SSOT et le total ne peut pas devenir négatif. En revanche, deux écarts dépassent la portée de P8/P9 et sont bloquants pour la mise en production :
- **Aucun scoping `branch_id` sur les coupons** (V4 FAIL — viole invariant 3).
- **`limit_per_user` contournable** sur kiosk (machine user) et table anonyme (V2 FAIL).
- WARN : audit NF525 absent sur kiosk/web (V6) ; absence de test Feature pour expiration (V3) ; admin peut créer un coupon `maximum_discount=0` qui désactive le plafond.

### Cycles P à programmer
- `P11_COUPON_BRANCH_SCOPE_2026-04-XX` — ajouter `coupons.branch_id` (nullable = global, sinon scoped) + filtre `CouponService` + Eloquent global scope optionnel + UI admin (V4).
- `P11_COUPON_LIMIT_PER_USER_GLOBAL_2026-04-XX` — passer le `userId` au validateur depuis le **client métier réel** (kiosk = utilisateur loyalty si présent, table = exiger `customer_id`) + index DB sur `order_coupons (coupon_id, user_id)` + lock transactionnel sur le `count` (V2 / S11 / S12 / S14).
- `P11_COUPON_AUDIT_NF525_FRONTEND_2026-04-XX` — symétrie : `AuditLogService::write('order.discount_applied')` dans `FrontendOrderService` après commit ; aligner schéma avec POS (V6 / S17).
- (optionnel `P12_COUPON_HARDENING_2026-04-XX`) — `maximum_discount > 0` requis quand `discount_type = FIXED` ; ajouter test Feature E2E expiration (V3).

---

*Rapport rédigé en mode AUDIT-ONLY ; aucune modification de code applicatif. Seul write effectué : ce fichier.*
