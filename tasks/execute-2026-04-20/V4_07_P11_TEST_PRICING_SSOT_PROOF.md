# EXECUTE V4 #7 — P11_TEST_PRICING_SSOT_PROOF

TASK_ID: P11_TEST_PRICING_SSOT_PROOF
WAVE: V4 salve 4a (test sentinelle, zéro risque applicatif)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE_FINDING: F-VERIFY-16-02 (cf. `reports/review/VERIFY_16_TESTS_REGRESSIONS_2026-04-20.md`)
RELATED_FINDING: F-VERIFY-18-* (form.total envoyé client = bombe latente si SSOT off)

---

## Goal

Créer **un test PHPUnit** qui prouve que le serveur **recalcule et écrase** le prix unitaire / `subtotal` / `total` envoyés dans le payload POS, même si l'auth passe.

Le test existant `tests/Feature/PricingIntegrityTest.php` ne teste que le **rejet** côté frontend (codes 401/403/422). Il ne prouve pas l'**écrasement** côté POS authentifié — qui est le cas d'attaque réaliste.

---

## Scope

| Fichier | Action |
|---|---|
| `tests/Feature/PosPricingSsotProofTest.php` | **NEW** — un seul test feature, RefreshDatabase, factories. |

**SUBSYSTEMS_TOUCHED**: tests Feature uniquement.
**SUBSYSTEMS_OFF_LIMITS**: app/, routes/, database/migrations/, scripts/. Aucune ligne d'app touchée.
**INVARIANTS_AT_RISK**: aucun (test only).

---

## Spécification du test

### Setup
1. `RefreshDatabase` trait.
2. Créer via factories : `Branch`, `User` admin avec permission `pos`, `Item` avec `price = 10.00`, `tax = 0`.
3. Authentifier l'user en API (Sanctum / token / `actingAs`).

### Action
POST `api/admin/pos-order` (route : `routes/api.php:625`, controller `PosController::store`) avec un payload où **subtotal/total/item_price sont truqués vers le bas** :

```php
$payload = [
    'branch_id' => $branch->id, // ignoré côté serveur (invariant 2/6)
    'subtotal' => 0.01,         // truqué
    'total'    => 0.01,         // truqué
    'items' => [
        ['item_id' => $item->id, 'price' => 0.01, 'quantity' => 2],
    ],
    // + champs minimum requis par PosOrderRequest
];
```

### Asserts
1. Le response HTTP est **2xx** (200 ou 201) — l'auth passe, le serveur ne rejette pas en 422.
2. En DB : `Order::latest()->first()->total` ≈ **20.00** (= 2 × 10.00, prix réel item), pas 0.01.
3. En DB : `OrderItem::where('order_id', $order->id)->first()->price` ≈ **10.00**, pas 0.01.

Si l'une des asserts échoue → fail. C'est le test sentinelle anti-régression SSOT.

### Fallback discovery
Si `PosOrderRequest` exige des champs non documentés (table, customer, …), regarder `tests/Feature/Pos*` existants (s'il y en a) ou `app/Http/Requests/PosOrderRequest.php` pour s'inspirer. **Si une dépendance bloquante (ex: pas de factory User avec permission `pos` disponible)**, écrire un Markdown report d'analyse au lieu d'un test partiel : `BLOCKED_NO_FACTORY` dans le RUN report.

---

## VALIDATE
1. `vendor/bin/phpunit --filter PosPricingSsotProofTest tests/Feature/PosPricingSsotProofTest.php` → **vert**.
2. `bash scripts/check-invariants.sh` → reste OK 6/6.
3. `git status --short` : exactement `?? tests/Feature/PosPricingSsotProofTest.php` + `?? reports/execution/RUN_P11_TEST_PRICING_SSOT_PROOF_2026-04-20.md`.

---

## REPORT_FILE

`reports/execution/RUN_P11_TEST_PRICING_SSOT_PROOF_2026-04-20.md` — sortie inline du run phpunit + diff.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier `OrderService::posOrderStore` ni `PricingService` ni `PosOrderRequest` — c'est un test qui DOIT marcher contre le code existant. Si le code ne respecte pas l'invariant SSOT → le test échoue → on a découvert un bug, on le documente dans le RUN report (`BUG_FOUND_INVARIANT_BROKEN`), on **ne corrige pas** ici.
- ❌ Pas de `git add/commit`.
- ❌ Pas d'altération de `tests/Feature/PricingIntegrityTest.php` existant — créer un nouveau fichier dédié.
- ❌ Pas de balayage d'autres pricing tests.
