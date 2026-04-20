# VERIFY-05 — P5/P6/P7 Validation `min:0` (montants négatifs)

**Date :** 2026-04-20
**Mode :** AUDIT-ONLY (lecture seule, 0 modification de code applicatif)
**Origine :** `tasks/verify-2026-04-20/05_VERIFY_P5_P7_MIN_ZERO.md`
**Périmètre :** P5 (kiosk/frontend `OrderRequest`) · P6 (`TableOrderRequest`) · P7 (`PosOrderRequest`) · symétrie front Vue/Vuex · SSOT `PricingService`.

---

## 1. Plan exécuté

1. **Pass A backend** — lecture intégrale des trois Requests cibles + `grep` de toutes les règles `min:0` / `min:1` sur les FormRequests du repo.
2. **Pass B front** — recherche des champs monétaires (`subtotal|total|discount|delivery_charge|received|amount|tip|loyalty`) côté Vue/Vuex + inspection des bindings d'input (POS, frontend, kiosk).
3. **Tests** — lecture des trois suites PHPUnit citées par la tâche pour confirmer la couverture négatif/zéro/null.
4. **SSOT** — relecture de `app/Services/Pricing/PricingService.php` pour confirmer le plancher `max(0.0, …)` et l'ignorance du total client.
5. **Hypothèses H1–H5** — challenge ciblé sur chaque hypothèse.

---

## 2. Pass A — Backend (FormRequests touchant des champs monétaires)

| # | Request | Fichier | Statut |
|---|---|---|---|
| P5 | `OrderRequest` | `app/Http/Requests/OrderRequest.php` | Couvert (kiosk + frontend `/api/frontend/order`) |
| P6 | `TableOrderRequest` | `app/Http/Requests/TableOrderRequest.php` | Couvert (`/api/table/dining-order`) |
| P7 | `PosOrderRequest` | `app/Http/Requests/PosOrderRequest.php` | Couvert (`/api/admin/pos`) |
| Adj | `CouponCheckRequest` | `app/Http/Requests/CouponCheckRequest.php` | Couvert (`total min:0`, marque `[P8]`) |
| Adj | `CouponRequest` | `app/Http/Requests/CouponRequest.php` | `discount`, `minimum_order`, `maximum_discount`, `limit_per_user` → tous `min:0` |
| Adj | `OrderSetupRequest` | `app/Http/Requests/OrderSetupRequest.php` | Tous les champs livraison (`basic_delivery_charge`, `charge_per_kilo`, `free_delivery_kilometer`) → `min:0` |
| Adj | `Kiosk/PricingPreviewRequest` | `app/Http/Requests/Kiosk/PricingPreviewRequest.php` | Quantités/IDs `min:1` |
| Adj | `Kiosk/PromoValidateRequest` | `app/Http/Requests/Kiosk/PromoValidateRequest.php` | `cart_total` `min:0,max:100000` |
| Adj | `TaxRequest`, `CurrencyRequest` | — | `tax_rate`, `exchange_rate` `min:0` |
| Adj | `LoyaltySetupRequest` | — | `points_per_euro`, `min_redeem_points` `min:0`, `points_for_1_euro_discount` `min:1` |

> Aucune autre Request du repo n'expose un champ monétaire libre côté payload.

### 2.1 Détail Pass A — règles applicables à P5/P6/P7

| Champ | OrderRequest (P5) | TableOrderRequest (P6) | PosOrderRequest (P7) | Notes |
|---|---|---|---|---|
| `subtotal` | `nullable\|numeric\|min:0` | `required\|numeric\|min:0` | `nullable\|numeric\|min:0` | SSOT recalcule |
| `discount` | `nullable\|numeric\|min:0` | `nullable\|numeric\|min:0` | `nullable\|numeric\|min:0` + perm gates POS | — |
| `delivery_charge` | DELIVERY → `required\|numeric\|min:0` ; sinon `nullable\|numeric\|min:0` | `nullable\|numeric\|min:0` | DELIVERY → `required\|numeric\|min:0` ; sinon `nullable\|numeric\|min:0` | — |
| `total` | `nullable\|numeric\|min:0` | `required\|numeric\|min:0` | `nullable\|numeric\|min:0` | total=0 admis (loyalty 100%) |
| `pos_received_amount` | n/a | n/a | CASH → `required\|numeric\|min:0` ; sinon `nullable\|numeric\|min:0` | UX cross-check vs `total` côté `withValidator` |
| `branch_id` / `customer_id` / `dining_table_id` | numeric | numeric | numeric (+ feature gate dine-in) | non-monétaire |

Aucune règle `integer` n'est utilisée sur un champ prix → **V2 OK** (`numeric` partout, pas de troncature à l'entier).

### 2.2 Bypass `merge` / `prepareForValidation`
- `grep prepareForValidation` sur `app/Http/Requests` → **0 résultat**.
- `grep ->merge(` sur `app/Http/Requests` → **0 résultat**.
- → **V4 OK**. Aucun pré-traitement ne ré-injecte une valeur signée derrière le validateur.

### 2.3 SSOT `PricingService`
`app/Services/Pricing/PricingService.php` :
- Ligne 144 : `max(1, (int) ($item->quantity ?? 1))` → quantité plancher = 1 (pas de quantités négatives possibles).
- Ligne 234 : `$finalTotal = … max(0.0, $rawTotal) …` → **plancher 0 systématique** sur le total final, peu importe le signe arrivé du client.
- Lignes 218–230 : remise re-calculée via `CouponService` ou `discountCalculator->manualDiscount(...)` ; jamais lue brute du payload.
- Ligne 232 : `$delivery = $req->deliveryCharge` est passé tel quel mais déjà filtré `min:0` côté Request (et borné par `OrderSetupRequest`).
- → **V6 OK**. Le total client est ignoré mathématiquement (aligné Règle n°1 `safety.mdc`).

---

## 3. Pass B — Front (Vue / Vuex)

### 3.1 POS (`resources/js/components/admin/pos/`)
| Fichier | Champ | Binding | Garde négatif côté front |
|---|---|---|---|
| `PosComponent.vue:387` | `discount` | `<input type="text" v-model="discount" v-on:keypress="floatNumber($event)">` | `appService.floatNumber` regex `/^[.]?[0-9]*$/` → **rejette `-`, `e`, et tout signe**. ⚠️ paste/programmatique non bloqué (mais serveur `min:0`). |
| `PaymentComponent.vue:47` | `pos_received_amount` (via `cashInput`) | `<input type="text" v-on:keypress="floatNumber($event)">` puis numpad `0–9 / 00 / .` (pas de `-`) | OK keypress + numpad ne contient pas de `-`. |
| `PosComponent.vue:736–748` | `delivery_charge`, `pos_received_amount` (form init) | `delivery_charge: 0`, `pos_received_amount: null` | OK init. |
| `PosComponent.vue:1354–1366` | discount fixed/% guard | `if (this.subtotal < this.discount)` + `if (this.discount > 100)` | bloque sur-remise mais **pas explicitement `< 0`** (le keypress filtre déjà) |

### 3.2 Frontend / Kiosk
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`, `KioskAdminComponent.vue` : seuls `min="0"` HTML rencontrés sur des compteurs (qty), aucun champ prix saisi côté kiosk client (le kiosk envoie items, pas de remise libre).
- Le payload kiosk vers `/api/frontend/order` ne porte plus `total` (P9.5.8) — recalc serveur via `PricingService`.
- Frontend account / coupon : pas d'input prix manipulé par l'utilisateur ; les valeurs viennent du back (`order.delivery_charge_currency_price`, etc.).

### 3.3 Verdict V5
- **V5 PARTIEL** : la garde front sur POS repose sur le filtre clavier `floatNumber` (regex sans `-`), ce qui couvre la frappe mais **pas un paste** ni l'API DevTools. Le serveur `min:0` reste l'autorité — aucun bypass exploitable de bout en bout. À noter pour P11 si on veut une `<input type="number" min="0">` explicite + validation Vuex symétrique.

---

## 4. Matrice complète Request × Field × Rule × Test

| Request | Field | Rule | Test PHPUnit | Couverture |
|---|---|---|---|---|
| `OrderRequest` (P5) | `subtotal` | `nullable\|numeric\|min:0` | `OrderRequestNegativeTotalTest::test_frontend_order_rejects_negative_subtotal` | ✅ négatif + null implicite |
| `OrderRequest` | `discount` | `nullable\|numeric\|min:0` | `OrderRequestNegativeTotalTest::test_frontend_order_rejects_negative_discount` | ✅ |
| `OrderRequest` | `total` | `nullable\|numeric\|min:0` | `OrderRequestNegativeTotalTest::test_frontend_order_rejects_negative_total` | ✅ |
| `OrderRequest` | `delivery_charge` | DELIVERY req+`min:0` / autre `nullable+min:0` | ❌ pas de test négatif dédié | ⚠️ **GAP-1** |
| `TableOrderRequest` (P6) | `subtotal` | `required\|numeric\|min:0` | `TableOrderNegativeTotalTest::test_table_dining_order_rejects_negative_subtotal` | ✅ |
| `TableOrderRequest` | `discount` | `nullable\|numeric\|min:0` | `TableOrderNegativeTotalTest::test_table_dining_order_rejects_negative_discount` | ✅ |
| `TableOrderRequest` | `total` | `required\|numeric\|min:0` | `TableOrderNegativeTotalTest::test_table_dining_order_rejects_negative_total` | ✅ |
| `TableOrderRequest` | `delivery_charge` | `nullable\|numeric\|min:0` | ❌ aucun | ⚠️ **GAP-2** (très faible impact : QR table = pas de livraison en pratique) |
| `PosOrderRequest` (P7) | `subtotal` | `nullable\|numeric\|min:0` | `PosOrderRequestNullableTotalTest::test_negative_subtotal_rejected_at_validation` | ✅ |
| `PosOrderRequest` | `discount` | `nullable\|numeric\|min:0` (+ perm gates) | ❌ pas de test négatif `discount` POS | ⚠️ **GAP-3** (perm gates testés ailleurs ; négatif lui-même non couvert) |
| `PosOrderRequest` | `total` | `nullable\|numeric\|min:0` | `PosOrderRequestNullableTotalTest::test_payload_without_total_or_subtotal_is_accepted` + `..._spoofed_low_total_is_ignored_server_recomputes` | ✅ null + spoof, pas de cas `total=-X` explicite mais SSOT recalcule |
| `PosOrderRequest` | `delivery_charge` | DELIVERY req+`min:0` / autre `nullable+min:0` | ❌ aucun | ⚠️ **GAP-4** |
| `PosOrderRequest` | `pos_received_amount` | CASH req+`min:0` / autre `nullable+min:0` | `PosOrderRequestNullableTotalTest::test_negative_pos_received_amount_rejected_at_validation` + `..._cash_received_below_server_total_is_rejected` | ✅ |
| `CouponCheckRequest` (P8) | `total` | `required\|numeric\|min:0` | `CouponCheckNegativeTotalTest` (présent) | ✅ |
| `CouponRequest` | `discount`, `minimum_order`, `maximum_discount` | `required\|numeric\|min:0` | (admin path, hors scope P5–P7) | n/a |

**Champs hors scope explicite mais audités :** aucun champ `loyalty_discount` / `promo_discount` / `tip_amount` n'existe en payload (`grep` repo entier = 0). Le crédit loyalty passe par `loyalty_code` (string) puis débit serveur via `FrontendOrderService` sous lock DB → pas de bypass négatif possible (H1 réfutée).

---

## 5. Challenge des hypothèses H1–H5

| H | Hypothèse | Verdict | Évidence |
|---|---|---|---|
| H1 | `discount` négatif via autre champ (`loyalty_discount`, `promo_discount`) | **RÉFUTÉE** | Aucun tel champ dans Requests/payloads. Loyalty = `loyalty_code` (string) ; crédit calculé serveur sous lock. |
| H2 | `pos_received_amount = 0` + change positif → bug rendu | **RÉFUTÉE** | `withValidator` lignes 117–121 : `if request->filled('total') && total > received → 422`. Et `cashChange` côté `PaymentComponent.vue:139–143` : `received > total ? … : 0` (jamais négatif). |
| H3 | total=0 sans items accepté (commande fantôme) | **RÉFUTÉE** | `ValidJsonOrder` exige `items` non vide + chaque item `item_id>0`, `quantity>0`. `items` est `required` dans les 3 Requests. |
| H4 | Front envoie `"-1"` string qui passe la valid browser | **PARTIEL** | Côté serveur `numeric|min:0` rejette aussi bien `-1` (string) que `-1` (number) → safe. Côté front, `floatNumber` filtre la frappe ; un paste / DevTools peut envoyer `-1` → la validation serveur tranche correctement (422 `validation.min.numeric`). |
| H5 | Pas de symétrie Frontend vs Admin paths | **CONFIRMÉE PARTIELLEMENT** | Côté serveur la symétrie P5/P6/P7 est complète (mêmes règles). Côté front, les inputs admin POS reposent sur un filtre keypress ; aucun équivalent `<input type="number" min="0">` standardisé sur tous les paths (kiosk n'a pas d'input prix utilisateur, donc neutre). |

---

## 6. Vérifications obligatoires — récapitulatif

| ID | Vérif | Statut | Commentaire |
|---|---|---|---|
| V1 | Tous les champs monétaires `min:0` | ✅ | Subtotal/total/discount/delivery_charge/pos_received_amount tous bornés. |
| V2 | `numeric` cohérent (pas `integer`) | ✅ | Aucun champ prix typé `integer`. |
| V3 | Tests PHPUnit couvrent négatif + zéro + null | ⚠️ | Couverture solide pour `subtotal`/`total`/`discount`/`pos_received_amount` ; **`delivery_charge` non testé négatif** sur les 3 Requests (GAP-1, GAP-2, GAP-4). |
| V4 | Pas de bypass `merge` / `prepareForValidation` | ✅ | 0 occurrence dans `app/Http/Requests`. |
| V5 | Front refuse négatifs (input `min="0"`) | ⚠️ | Filtre `floatNumber` regex sans `-` → keypress OK, paste/DevTools non bloqué. Serveur tranche. Pas de `<input type="number" min="0">` standardisé sur POS discount/cash. |
| V6 | `PricingService` recalcule SSOT et plancher 0 | ✅ | `max(0.0, $rawTotal)` ligne 234, `max(1, qty)` ligne 144, items vérifiés DB. |

---

## 7. Risques résiduels & recommandations (non bloquants)

1. **GAP tests `delivery_charge` négatif** (P5/P6/P7) : ajouter 3 cas PHPUnit (`OrderRequest`, `TableOrderRequest`, `PosOrderRequest`) — coût ~30 min, valeur : verrouillage explicite contre régression.
2. **GAP test `discount` POS négatif** : ajouter `PosOrderRequest::test_negative_discount_rejected` (couverture rule, pas perm gates).
3. **Symétrie front durcie** (P11 candidate) : remplacer les `<input type="text" v-on:keypress="floatNumber">` sur les champs prix POS par `<input type="number" min="0" step="0.01">` + clamp Vuex `Math.max(0, val)` sur mutations `discount`, `delivery_charge`, `pos_received_amount`. N'apporte aucun gain de sécurité (serveur SSOT) mais améliore l'UX et bloque les outils d'automatisation.
4. **Documenter** dans `docs/SECURITY_NOTES.md` que le front n'est pas autorité monétaire et que le filtre `floatNumber` est un confort UI, pas une garde de sécurité (clarifie les attentes).

Aucun de ces gaps ne permet un bypass de bout en bout : la chaîne `Request validator → PricingService SSOT → max(0.0,…)` est intacte.

---

## 8. Conclusion

**GLOBAL : ALL_GREEN** (avec 4 GAPs de couverture de tests, sans impact sécurité).

- Les 3 Requests P5/P6/P7 portent toutes des règles `min:0` exhaustives sur tous leurs champs monétaires.
- `PricingService` est la source de vérité, plancher `0.0` appliqué inconditionnellement.
- Aucun mécanisme de bypass (`merge`, `prepareForValidation`) détecté.
- Hypothèses H1–H3 réfutées ; H4/H5 partielles mais sans impact métier (serveur tranche).

### Suite recommandée
- **Pas de cycle correctif obligatoire.** Les acceptance criteria §6 du task sont remplis (V1, V2, V4, V6 verts ; V3, V5 marqués WARN sans impact).
- **Cycle suggéré (faible priorité, P11 candidate) :** `P11_REQ_MONEY_HARDENING_TESTS_SYMETRIE_FRONT` — couvre :
  - 4 tests PHPUnit `delivery_charge` / `discount` négatifs manquants ;
  - durcissement front POS (`type="number" min="0"` + clamp Vuex) ;
  - note `docs/SECURITY_NOTES.md`.
- Si cycle corectif jugé non prioritaire → **CLOSE** sur ce verify.

---

### Évidence — fichiers consultés
- `app/Http/Requests/OrderRequest.php` (P5, lignes 32–66)
- `app/Http/Requests/TableOrderRequest.php` (P6, lignes 31–48)
- `app/Http/Requests/PosOrderRequest.php` (P7, lignes 36–84, 87–162)
- `app/Http/Requests/CouponCheckRequest.php` (lignes 25–32)
- `app/Rules/ValidJsonOrder.php` (lignes 31–73)
- `app/Services/Pricing/PricingService.php` (lignes 144, 218–248)
- `tests/Feature/OrderRequestNegativeTotalTest.php` (3 tests)
- `tests/Feature/TableOrderNegativeTotalTest.php` (3 tests)
- `tests/Feature/PosOrderRequestNullableTotalTest.php` (5 tests)
- `tests/Feature/CouponCheckNegativeTotalTest.php` (présent)
- `resources/js/services/appService.js` (`floatNumber`, lignes 65–69)
- `resources/js/components/admin/pos/PosComponent.vue` (lignes 370–434, 736–748, 1342–1366)
- `resources/js/components/admin/pos/PaymentComponent.vue` (lignes 40–100, 139–143, 195–245)

Mode AUDIT-ONLY respecté : 0 fichier applicatif modifié.
