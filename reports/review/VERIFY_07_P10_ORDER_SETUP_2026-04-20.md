# VERIFY-07 — P10 OrderSetupRequest (paramètres commande / livraison)

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (zéro modification de code applicatif)  **Origine task :** `tasks/verify-2026-04-20/07_VERIFY_P10_ORDER_SETUP.md`  **Commit P10 référencé :** `c00a8cd61`
**Verdict :** **GLOBAL: WARN**

---

## 1. Plan exécuté (5 lignes)

1. Localisation `OrderSetupRequest` + test négatif P10 + consommateurs (`OrderService`, front Pos/Checkout, settings UI).
2. Pass A backend : règles `min:0`, modèle, sémantique de chaque champ, fail-fast sur combinaisons absurdes.
3. Pass B frontend : composant admin settings, Vuex `orderSetup`, formules `delivery_charge` (POS + Checkout).
4. Construction matrice champ × signification × cas limite (0, null, négatif, hors-enum).
5. Synthèse + verdict + cycles P de remédiation.

## 2. Périmètre P10 réellement implémenté

P10 ne couvre **pas** un wizard "OrderSetup" produit (pas de modèle Eloquent `OrderSetup`). Il s'agit d'une **page de paramètres admin** (`Settings::group('order_setup')`) regroupant 7 réglages globaux :

| Champ | Sémantique réelle | Type métier |
|---|---|---|
| `order_setup_food_preparation_time` | minutes (preparation_time copié sur chaque commande) | duration ≥ 0 |
| `order_setup_schedule_order_slot_duration` | minutes (slot d'agenda d'avance) | duration ≥ 0 |
| `order_setup_takeaway` | code activité (`Activity::ENABLE=5` / `DISABLE=10`) | **enum** |
| `order_setup_delivery` | code activité (`Activity::ENABLE=5` / `DISABLE=10`) | **enum** |
| `order_setup_free_delivery_kilometer` | rayon en km de livraison gratuite | distance ≥ 0 |
| `order_setup_basic_delivery_charge` | flat fee livraison | montant ≥ 0 |
| `order_setup_charge_per_kilo` | tarif par km au-delà du rayon gratuit | montant ≥ 0 |

Source de vérité : `app/Http/Resources/OrderSetupResource.php:27-35`, `database/seeders/OrderSetupTableSeeder.php:19-27`.

> Note : la `Note 1` du task (`minimum_order_amount`, `free_delivery_distance`) **n'existe pas** dans `OrderSetupRequest`. Les hypothèses H1/H2 du task portent donc sur des champs **hypothétiques** ; la vérification est reportée sur les champs réels.

## 3. Pass A — Backend

### 3.1 Validation (`OrderSetupRequest`)

`app/Http/Requests/OrderSetupRequest.php:24-36` :

```26:35:app/Http/Requests/OrderSetupRequest.php
            // [P10] All values are durations, activity codes (≥0), or money/distances — none may be negative.
            'order_setup_food_preparation_time'        => ['required', 'numeric', 'min:0'],
            'order_setup_schedule_order_slot_duration' => ['required', 'numeric', 'min:0'],
            'order_setup_takeaway'                     => ['required', 'numeric', 'min:0'],
            'order_setup_delivery'                     => ['required', 'numeric', 'min:0'],
            'order_setup_free_delivery_kilometer'      => ['required', 'numeric', 'min:0'],
            'order_setup_basic_delivery_charge'        => ['required', 'numeric', 'min:0'],
            'order_setup_charge_per_kilo'              => ['required', 'numeric', 'min:0'],
```

### 3.2 Consommateurs critiques

- `Settings::group('order_setup')->get('order_setup_food_preparation_time')` → écrit dans `Order.preparation_time` lors de `myOrderStore`, `posOrderStore`, `tableOrderStore` (`app/Services/OrderService.php:303,600,1004`).
- `Settings::group('order_setup')->get('order_setup_schedule_order_slot_duration')` → utilisé par `Carbon::addMinutes()` pour produire `delivery_time` ("HH:MM - HH:MM") dans POS et Table (`app/Services/OrderService.php:863, 1229`).
- Aucun usage backend direct de `basic_delivery_charge`, `charge_per_kilo`, `free_delivery_kilometer` : le `delivery_charge` est calculé **côté front** puis envoyé. Il est ensuite re-validé par `OrderRequest:41-45` et `PosOrderRequest:59-63` avec `min:0` (défense en profondeur OK).
- `PricingService::calculateOrder` (`app/Services/Pricing/PricingService.php:232-234`) : `$delivery = $req->deliveryCharge` (passe-plat de la valeur Order), additionné à `realSubtotal + totalTax - discount` puis bornée par `max(0.0, ...)`. **Aucun NaN/négatif possible** côté SSOT pricing même si `delivery_charge=0`.

### 3.3 Invariants à risque (FoodKing)

- **SSOT prix** : non régressé. Les 3 montants livraison ne sont pas re-source-of-truth côté back (calculs faits front). À durcir hors scope P10.
- **branch_id** : aucun impact (réglage global non scopé branche — c'est volontaire ici).
- **OrderStatus enum** : non touché.
- **Dispatch après commit** : non touché.

## 4. Pass B — Frontend

### 4.1 UI admin (`OrderSetupComponent.vue`)

- Tous les inputs numériques utilisent `floatNumber($event)` au keypress (`resources/js/services/appService.js:65-69`) — regex `^[.]?[0-9]*$` qui **bloque déjà la saisie d'un signe `-`** (défense en profondeur côté UI).
- `takeaway` / `delivery` sont des **radios** liés à `activityEnum.ENABLE=5` / `DISABLE=10` (`resources/js/components/admin/settings/OrderSetup/OrderSetupComponent.vue:46-84`) — l'utilisateur ne peut **pas** envoyer `0` via la UI.
- Aucun attribut `min`, `max`, ni guidance "mettre 0 = …" sur les champs durée / distance / tarif.

### 4.2 Consommation des valeurs (POS + Checkout)

`resources/js/components/admin/pos/PosComponent.vue:1734-1738` et `resources/js/components/frontend/checkout/CheckoutComponent.vue:763-767` (formule identique) :

```text
if (distance > free_delivery_kilometer)
    delivery_charge = (distance - free_delivery_kilometer) * charge_per_kilo + basic_delivery_charge
else
    delivery_charge = basic_delivery_charge
```

### 4.3 Switches d'activation

`resources/js/store/modules/frontend/frontendCart.js:172-176` : sélection `orderType` basée sur `payload.order_setup_delivery === activityEnum.ENABLE` / `order_setup_takeaway === activityEnum.ENABLE`. Toute valeur ≠ 5 (incluant `0`, `10`, `7`, `42`) est traitée comme "désactivé". Cohérent par défaut, mais **la valeur sémantique n'est validée nulle part** côté back.

## 5. Matrice champ × cas limite

| Champ | `0` | `null` | Négatif | Hors-enum (ex. `7`) | Verdict |
|---|---|---|---|---|---|
| `food_preparation_time` | accepté → KDS affiche prép = 0 min (cosmétique, pas de crash) | rejeté (`required`) | rejeté (`min:0`) — couvert par test P10 | n/a | OK avec **WARN doc** : "0 = aucune attente" non documenté |
| `schedule_order_slot_duration` | accepté → `addMinutes(0)` → `delivery_time = "HH:MM - HH:MM"` (slot vide) | rejeté | rejeté | n/a | **WARN** : slot vide affiché client (cosmétique, pas d'erreur) |
| `takeaway` | accepté ❌ → traité comme DISABLE par hasard côté front | rejeté | rejeté | accepté ❌ | **WARN sémantique** : règle devrait être `in:5,10` (Activity enum) |
| `delivery` | idem `takeaway` | rejeté | rejeté | accepté ❌ | **WARN sémantique** : idem |
| `free_delivery_kilometer` | accepté → toute distance > 0 paie `(d × charge_per_kilo) + basic` (zéro gratuit) | rejeté | rejeté | n/a | OK |
| `basic_delivery_charge` | accepté → 0 € flat (légitime) | rejeté | rejeté — couvert par test P10 | n/a | OK |
| `charge_per_kilo` | accepté → seul `basic` s'applique (légitime) | rejeté | rejeté | n/a | OK |

**Combinaison "absurde" non détectée par le back** : `delivery=ENABLE` + `basic=0` + `per_kilo=0` + `free_km=0` ⇒ toutes les livraisons à 0 €, sans warning. Voir Conclusion §8 / §9.

## 6. Vérifications obligatoires (§5 du task)

- [x] **V1 — `min:0` sur tous les champs numériques** : 7/7 champs couverts. Preuve : `app/Http/Requests/OrderSetupRequest.php:28-34`.
- [ ] **V2 — Documentation "0 = désactivé" vs "0 = strict"** : **NON FAIT**. Aucun docblock, aucun commentaire, aucune doc `docs/BUSINESS_RULES.md`. Sémantique implicite seulement (défaut seeder = "30/30/ENABLE/ENABLE/2/1/1"). → **WARN**.
- [x] **V3 — Tests cas limite (0 et null) couverts** : partiellement. Preuves : `tests/Feature/OrderSetupRequestNegativeValuesTest.php:37-77` couvre **3 cas** : (a) rejet `basic_delivery_charge=-1`, (b) rejet `food_preparation_time=-5`, (c) acceptation payload valide. **Manquent** : couverture explicite `=0` (acceptation), `null` (rejet `required`), 5 autres champs négatifs, et combinaisons absurdes (`takeaway=0`, `delivery=42`). → **WARN**.
- [x] **V4 — Front input ↔ contraintes back** : **OK structurellement** (radios pour `takeaway`/`delivery`, `floatNumber` keypress empêche le `-`), **mais** aucun attribut HTML `min="0"` ni validation Vue dédiée — la défense ne tient que par le keypress filter. Acceptable, **sans régression P10**.

## 7. Critères d'acceptation (§6 du task)

- ALL_GREEN si V1–V4 prouvés et sémantique 0/null clarifiée.
- WARN si sémantique floue → **CAS COURANT**.
- FAIL si une combinaison casse `PricingService` → **NON observé** (`PricingService::calculateOrder` borne le total final par `max(0.0, …)`, ne consomme jamais directement les 3 montants `order_setup_*`, et `Carbon::addMinutes(0)` est inoffensif).

## 8. Conclusion

**GLOBAL : WARN**

P10 atteint son objectif de surface (`min:0` partout, test négatif sur 2 champs, défense en profondeur via `OrderRequest`/`PosOrderRequest`). Aucune régression `PricingService` détectée, aucun risque d'invariant FoodKing (SSOT prix, branch isolation, statuts). **Mais** :

- Sémantique 0 vs DISABLE non documentée pour les durées et activités.
- `takeaway`/`delivery` validés comme `numeric|min:0` au lieu de `in:5,10` (`Activity` enum) → l'API accepte des codes non métier.
- Couverture de tests partielle (cas `=0`, cas `null`, autres champs).
- Aucun garde-fou sur la combinaison `free_km=0 ∧ basic=0 ∧ per_kilo=0` qui rend toute livraison gratuite silencieusement.

## 9. Cycles P de suite recommandés

1. **`P11_ORDER_SETUP_SEMANTIC_DOC`** (priorité Composer / routine) — Ajouter docblock dans `OrderSetupRequest` + section `docs/BUSINESS_RULES.md` clarifiant pour chacun des 7 champs : "0 = …" vs "valeur active = …".
2. **`P12_ORDER_SETUP_ENUM_HARDENING`** (priorité Composer / routine) — Remplacer `numeric|min:0` par `Rule::in([Activity::ENABLE, Activity::DISABLE])` sur `order_setup_takeaway` et `order_setup_delivery` ; étendre `OrderSetupRequestNegativeValuesTest` aux 5 champs restants + cas `null` + cas hors-enum.
3. **`P13_DELIVERY_FEE_INVARIANT_GUARD`** (priorité GPT / complexe) — Ajouter une règle de cohérence (ex. `after rules`) qui rejette la combinaison `order_setup_delivery=ENABLE ∧ basic_delivery_charge=0 ∧ charge_per_kilo=0` (ou loggue un avertissement admin) ; faire remonter au front un warning visible dans `OrderSetupComponent.vue`.

## 10. Annexe — Preuves file:line condensées

- `app/Http/Requests/OrderSetupRequest.php:14-36` (autorise tout user → règles `min:0`)
- `app/Services/OrderService.php:303,600,1004` (`preparation_time` lu depuis settings, copié sur chaque commande)
- `app/Services/OrderService.php:862-866, 1228-1232` (`delivery_time` calculé via `addMinutes(slot_duration)`)
- `app/Services/Pricing/PricingService.php:232-234` (`delivery` passé tel quel, total bornes `max(0.0, ...)`)
- `app/Http/Requests/OrderRequest.php:41-45` & `app/Http/Requests/PosOrderRequest.php:59-63` (`delivery_charge: min:0` côté commande — défense en profondeur)
- `tests/Feature/OrderSetupRequestNegativeValuesTest.php:37-77` (3 tests P10)
- `database/seeders/OrderSetupTableSeeder.php:19-27` (valeurs par défaut)
- `app/Http/Resources/OrderSetupResource.php:25-36` (transformation API GET)
- `app/Enums/Activity.php:7-8` (`ENABLE=5`, `DISABLE=10` — non utilisé dans la validation P10)
- `resources/js/components/admin/settings/OrderSetup/OrderSetupComponent.vue:46-130` (UI inputs + radios)
- `resources/js/services/appService.js:65-69` (`floatNumber` bloque le `-` au keypress)
- `resources/js/components/admin/pos/PosComponent.vue:1734-1738` (formule `delivery_charge` POS)
- `resources/js/components/frontend/checkout/CheckoutComponent.vue:763-767` (formule `delivery_charge` Checkout — symétrique)
- `resources/js/store/modules/frontend/frontendCart.js:172-176` (sélection `orderType` selon `ENABLE`)
- `routes/api.php:252-255` (route `admin/setting/order-setup` GET + PUT/PATCH)
