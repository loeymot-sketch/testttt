# TASK_V1_PRICING_SSOT_001 — Extraction PricingService centralisé

## Meta
- **Priority** : P0 (critique business)
- **Vague** : 2 — Domaine SSOT
- **PRIMARY_MODEL** : GPT-5.4 (complexe, frozen zone)
- **TEST_STRATEGY** : `local-validation`
- **DEPENDS_ON** : (indépendant des tasks vague 1 mais recommandé après)
- **BLOCKS** : TASK_V1_TEST_PRICING_STATE_001
- **Estimation** : 3 j-h
- **⚠️ GATE OBLIGATOIRE avant EXECUTE**

## Contexte

`OrderService.php` et `FrontendOrderService.php` sont deux services qui gèrent respectivement les commandes POS/admin et les commandes kiosk/frontend. **Les deux calculent les prix en parallèle**. La règle de symétrie (`docs/AGENTS.md` §Non-Negotiables) impose qu'ils produisent les mêmes totaux, mais l'audit 360 a identifié :
- Calculs TVA distincts (arrondis différents dans certains cas).
- Gestion des promos/options différente selon la surface.
- Risque métier majeur : un client paye un prix différent selon qu'il passe au POS, au kiosk, ou via une future surface.

La V1 impose **un seul** `PricingService` que OrderService et FrontendOrderService appellent. Les deux services deviennent de simples adaptateurs contexte-spécifiques (POS vs kiosk) autour d'un cœur commun.

## Acceptance Criteria
- [ ] `App\Services\Pricing\PricingService` créé, méthode publique unique `calculateOrder(PricingRequest $req): PricingResult`.
- [ ] `PricingRequest` value object immutable (items, branch, customer, promotions, context).
- [ ] `PricingResult` value object immutable (lines, subtotal, taxes, discounts, total).
- [ ] **Zéro calcul de prix** (subtotal/tax/discount) dans un contrôleur ou composant Vue (grep strict CI).
- [ ] OrderService + FrontendOrderService utilisent exclusivement `PricingService` pour tout total monétaire.
- [ ] Test de parité POS/Kiosk : 50+ fixtures de paniers → **même** résultat bit-à-bit entre les deux surfaces.
- [ ] Rétrocompatibilité API : `/api/order/*` retourne la même shape JSON qu'avant (test snapshot).
- [ ] Benchmark : 20-lignes panier < 50ms p95 (mesure avec `X-Debug-Timing` header).
- [ ] Couverture PHPUnit ≥ 95% branches sur `App\Services\Pricing\*` (déléguée à V1_TEST_PRICING_STATE_001).

## Scope

### SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `app/Services/Pricing/PricingService.php` | nouveau service cœur | Write | Yes (branch impacte TVA / config) | No |
| `app/Services/Pricing/PricingRequest.php` | value object | Write | No | No |
| `app/Services/Pricing/PricingResult.php` | value object | Write | No | No |
| `app/Services/Pricing/TaxCalculator.php` | sous-composant | Write | Yes | No |
| `app/Services/Pricing/DiscountCalculator.php` | sous-composant | Write | No | No |
| `app/Services/OrderService.php` | **frozen — refactor contrôlé vers PricingService** | Write | Yes | Yes |
| `app/Services/FrontendOrderService.php` | **frozen — refactor contrôlé vers PricingService** | Write | Yes | Yes |
| `tests/Unit/Services/Pricing/*` | tests exhaustifs | Write | No | No |
| `tests/Integration/PricingParityTest.php` | parité POS/Kiosk | Write | No | No |

### SUBSYSTEMS_OFF_LIMITS
- OrderStatus transitions — V1_STATUS_MACHINE_001.
- Events / broadcast — V1 vagues 1.
- UI (composants Vue) — cette task ne modifie aucune vue.
- Migrations DB — aucune.

## Invariants at Risk
- [ ] None
- [x] **Backend pricing SSOT** — c'est précisément l'invariant renforcé par cette task.
- [ ] OrderStatus enum
- [x] branch_id data isolation — les règles TVA peuvent varier par branche.
- [ ] Dispatch after DB commit
- [x] **OrderService / FrontendOrderService symmetry** — task centrale pour cet invariant.
- [x] **Frozen zone** — OrderService + FrontendOrderService.

## GATE_CONDITIONS (⚠️ pré-EXECUTE)
- **Gate requise : OUI.**
- Rédiger `docs/gates/GATE_V1_PRICING_SSOT_001_YYYY-MM-DD.md` AVANT démarrage EXECUTE.
- Le gate brief doit contenir :
  1. Cartographie exhaustive des usages actuels (`grep` priceCalculation, calculatePrice, getTotal, computeTax).
  2. Liste explicite des divergences connues POS vs Kiosk (fixtures failing avant refactor).
  3. Stratégie de migration : ajout du service + bascule **interne** (OrderService appelle PricingService, pas de changement d'API publique).
  4. Rollback plan : feature-flag `pricing.use_ssot_service` permettant retour à l'ancien chemin.
  5. Validation humaine explicite avant EXECUTE.

## Execution Steps

### E1 — Gate brief (Claude planner)
Rédiger le gate. Humain valide.

### E2 — Création PricingService (greenfield)
1. `PricingRequest` — value object :
   ```php
   final class PricingRequest {
       public function __construct(
           public readonly array $items,         // Item[] : product_id, quantity, options, wizard_state
           public readonly Branch $branch,
           public readonly ?Customer $customer,
           public readonly array $promotions,    // Promotion[]
           public readonly string $context,      // 'pos' | 'kiosk' | 'delivery' (V1 : pos|kiosk)
       ) {}
   }
   ```
2. `PricingResult` immutable.
3. `PricingService::calculateOrder(PricingRequest): PricingResult`.
4. `TaxCalculator` + `DiscountCalculator` internes.

### E3 — Tests fixtures (avant refactor frozen zone)
1. Construire 50+ fixtures JSON : paniers simples, multi-lignes, options wizard, promos, TVA multi-taux, arrondis.
2. Test snapshot : `PricingService::calculateOrder($fixture)` → résultat attendu.
3. Doit être vert **avant** de toucher OrderService / FrontendOrderService.

### E4 — Bascule OrderService (frozen zone, controlée)
1. Dans OrderService, remplacer la logique de calcul interne par :
   ```php
   $result = $this->pricingService->calculateOrder(
       new PricingRequest(items: $items, branch: $branch, customer: $customer, promotions: $promos, context: 'pos')
   );
   ```
2. Les autres responsabilités (persistance Order, transitions status, events) restent intactes.
3. Feature-flag `config('pricing.use_ssot_service', true)` — si false, ancien chemin.

### E5 — Bascule FrontendOrderService (frozen zone, symétrique)
1. Idem E4 avec `context: 'kiosk'`.
2. Vérifier que les points d'entrée (wizard, options) alimentent la même structure `PricingRequest`.

### E6 — Test de parité
1. `tests/Integration/PricingParityTest.php` :
   - Pour chaque fixture, construire le panier équivalent côté POS et côté Kiosk.
   - `OrderService::calculate($panier)` == `FrontendOrderService::calculate($panier)` **bit-à-bit**.
   - Exécution CI bloquante.

### E7 — Guard CI contre régression
1. Règle `grep` CI :
   ```
   grep -rn "[\$]total[[:space:]]*=[[:space:]]*[\$]subtotal" app/ resources/js/ \
       --exclude-dir=Pricing && exit 1
   ```
2. Toute réapparition de calcul prix ailleurs casse la CI.

### E8 — Rétrocompatibilité API
1. Tests snapshot sur réponses `/api/order/*` : **identiques** avant/après.

### E9 — Documentation
Update `docs/BUSINESS_RULES.md` : section pricing pointe vers `App\Services\Pricing\*`.

## SYMMETRY_NOTE
Cette task **renforce** la symétrie OrderService / FrontendOrderService en les faisant dériver d'un service unique. C'est l'objectif explicite.

Obligation : toute modification dans l'un des deux services passe par le PricingService commun, pas par duplication.

## Status
- [ ] Pending plan
- [ ] Gate brief written
- [ ] Gate approved by human
- [ ] Plan approved
- [ ] In execution
- [ ] Validation
- [ ] Audit
- [ ] Closed
