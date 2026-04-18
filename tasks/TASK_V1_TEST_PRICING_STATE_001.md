# TASK_V1_TEST_PRICING_STATE_001 — PHPUnit exhaustif Pricing + StateMachine

## Meta
- **Priority** : P0 (verrouille la qualité du cœur business)
- **Vague** : 4 — Data, observabilité, tests
- **PRIMARY_MODEL** : Composer (test writing routine)
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : TASK_V1_PRICING_SSOT_001, TASK_V1_STATUS_MACHINE_001
- **BLOCKS** : —
- **Estimation** : 1 j-h

## Contexte

Les deux composants les plus critiques du domaine V1 sont :
1. **`PricingService`** — calcul de tous les prix (si ça pète, on perd de l'argent).
2. **`OrderStateMachine`** — transitions des commandes (si ça pète, on perd des commandes).

Pour livrer V1 avec confiance, ces deux composants doivent avoir une couverture PHPUnit proche de 100% (logique pure, sans DB lente).

## Acceptance Criteria
- [ ] `tests/Unit/Services/Pricing/PricingServiceTest.php` avec **50+ cases** :
  - Paniers simples : 1 article, 1 quantité.
  - Multi-lignes : plusieurs articles, quantités variables.
  - Options wizard : avec et sans options payantes.
  - Promotions : pourcentage, montant fixe, gratuité N+1.
  - Taxes : TVA simple, TVA multi-taux (alimentaire vs boisson).
  - Arrondis : centimes, cas à la frontière.
  - Remises manuelles caissier.
  - Cumul promos (règle "meilleure promo").
  - Edge cases : panier vide → total 0, quantité 0 ignorée, quantité négative rejetée.
- [ ] `tests/Unit/Domain/Order/OrderStateMachineTest.php` avec **tous cas** :
  - Pour chaque transition légale → test vert avec vérification event émis.
  - Pour chaque transition illégale → `IllegalTransitionException` attendue.
  - Transition avec `reason` obligatoire (cancel post-preparing) → throw si absent.
  - Transition avec actor non autorisé → throw policy.
- [ ] **Coverage 100% lignes** sur `App\Services\Pricing\*` (mesurable via `php artisan test --coverage`).
- [ ] **Coverage ≥ 95% branches** sur `App\Domain\Order\OrderStateMachine`.
- [ ] Rapport coverage artifact généré par CI.
- [ ] CI fail si coverage descend sous les seuils.
- [ ] Durée exécution tests **< 10s** (pas de DB lente — tout en mémoire ou fake repository).

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `tests/Unit/Services/Pricing/PricingServiceTest.php` | nouveau | Write | No | No |
| `tests/Unit/Services/Pricing/TaxCalculatorTest.php` | nouveau | Write | No | No |
| `tests/Unit/Services/Pricing/DiscountCalculatorTest.php` | nouveau | Write | No | No |
| `tests/Unit/Domain/Order/OrderStateMachineTest.php` | nouveau | Write | No | No |
| `tests/Fixtures/Pricing/*.json` | fixtures paniers | Write | No | No |
| `phpunit.xml` | ajout coverage config | Write | No | No |
| CI workflow | ajout step coverage report | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- Code de production — aucune modification. Si un test révèle un bug dans PricingService, ouvrir un ticket follow-up (ou traiter dans la task V1_PRICING_SSOT_001 elle-même).
- Tests e2e Playwright — V1_TEST_PW_5FLOWS_001.

## Invariants at Risk
- [x] None
- [ ] Backend pricing SSOT
- [ ] OrderStatus enum
- [ ] branch_id data isolation
- [ ] Dispatch after DB commit
- [ ] OrderService / FrontendOrderService symmetry
- [ ] Frozen zone

## Execution Steps

### E1 — Préparer fixtures fin
Créer JSON fixtures dans `tests/Fixtures/Pricing/` :
```
simple_single_item.json
multi_items_same_tax.json
multi_items_mixed_tax.json
promo_percentage.json
promo_fixed_amount.json
promo_bogo.json
options_free.json
options_paid.json
wizard_complex_tacos.json
discount_manual.json
edge_empty_cart.json
edge_negative_qty.json
rounding_borderline.json
...
```
Chaque fixture contient `{ items: [...], branch: {...}, promotions: [...], expected: { subtotal: ..., tax: ..., total: ... } }`.

### E2 — PricingServiceTest
```php
/** @dataProvider pricingFixtures */
public function testCalculateOrder(array $input, array $expected): void {
    $svc = app(PricingService::class);
    $req = PricingRequest::fromArray($input);
    $result = $svc->calculateOrder($req);
    $this->assertSame($expected['subtotal'], $result->subtotal);
    $this->assertSame($expected['tax'], $result->tax);
    $this->assertSame($expected['total'], $result->total);
    // line-by-line assertion
}
public function pricingFixtures(): array {
    return collect(glob(__DIR__.'/../../../Fixtures/Pricing/*.json'))
        ->mapWithKeys(fn($f) => [basename($f, '.json') => [...json_decode(file_get_contents($f), true)]])
        ->all();
}
```

### E3 — TaxCalculator + DiscountCalculator tests unitaires isolés
Tests des sous-composants en isolation (pas via PricingService) pour couvrir les branches pures.

### E4 — OrderStateMachineTest
```php
public function legalTransitions(): array {
    return [
        ['draft', 'pending'],
        ['pending', 'preparing'],
        ['pending', 'cancelled'],
        ['preparing', 'ready'],
        ['preparing', 'cancelled'],
        ['ready', 'served'],
        ['ready', 'cancelled'],
        ['served', 'completed'],
    ];
}
public function illegalTransitions(): array {
    // cartesian product - legal = illegal
    return [['draft', 'completed'], ['pending', 'served'], ['completed', 'pending'], ['cancelled', 'pending'], ...];
}
/** @dataProvider legalTransitions */
public function testLegalTransition(string $from, string $to): void { /* vérifie pas d'exception + event */ }
/** @dataProvider illegalTransitions */
public function testIllegalTransitionThrows(string $from, string $to): void {
    $this->expectException(IllegalTransitionException::class);
    // ...
}
```

### E5 — Coverage seuils
`phpunit.xml` :
```xml
<coverage>
    <report>
        <clover outputFile="coverage.xml"/>
        <html outputDirectory="coverage-html"/>
    </report>
</coverage>
```

CI step :
```yaml
- run: php artisan test --coverage --min=95
- name: enforce pricing coverage
  run: php scripts/check-coverage.php App\\Services\\Pricing 100
- name: enforce state machine coverage
  run: php scripts/check-coverage.php App\\Domain\\Order\\OrderStateMachine 95
```

### E6 — Durée exécution
Tests tournent contre des fakes / in-memory repositories. Pas de hit DB réelle. Suite doit passer en < 10s.

## SYMMETRY_NOTE
N/A.

## GATE_CONDITIONS
- **Gate requise** : NON.
- Stop-gate si : propose de désactiver coverage threshold pour faire passer CI — non, fixer les gaps.
- Stop-gate si : découvre un vrai bug dans PricingService ou StateMachine → ne PAS corriger ici, ouvrir ticket follow-up scoped sur V1_PRICING_SSOT_001 / V1_STATUS_MACHINE_001.

## Status
- [ ] Pending plan
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Gate open
- [ ] Closed
