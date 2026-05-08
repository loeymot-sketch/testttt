# PLAN — V2-3 : AI Upsell (rule-based V1 → ML V2)

> Wave gamma G2. Owner: orchestrator (Claude). Created: 2026-05-08.
> Greenfield scaffolding livré dans le même commit. Pas de modification de
> frozen zones (KioskApp/KioskPayment/KioskWizard/POS wizard/F-001..F-017).

---

## 1. Vision

Aujourd'hui le kiosk applique **deux** mécaniques d'upsell :
- legacy `Item::is_upsell = YES` (route `frontend.item.upsell.{id}`)
- `upsell_rules` admin-curated (`UpsellController::suggest` + `UpsellRuleService`,
  Phase 1.7, branch-scoped, 100 % statique — invariant doc service §1.5).

Les deux tournent en V1.x et resteront **autoritaires** : zéro ML, zéro stat
implicite. Le rôle de cette wave est de **préparer le rail de migration vers
un moteur de recommandation V2** sans déstabiliser l'existant :

- **V1.x** (now) : nouveau service `UpsellRecommendationService` posé en
  parallèle, branché sur un strategy pattern, **désactivé par défaut**
  (`config('recommendation.strategy') = 'rule_based'`, mais le bind reste
  une stratégie heuristique côté `App\Services\Recommendation`, **PAS**
  utilisée en production tant que le feature flag d'intégration kiosk reste
  off).
- **V1.y** (planifié) : on permet un A/B test côté UI pour comparer
  `UpsellRuleService` (admin curé) vs `RuleBasedStrategy` (heuristique
  catégorie) vs `MlPlaceholderStrategy` (stub).
- **V2** (cible production) : la `MlPlaceholderStrategy` est remplacée par
  un appel à un service ML externe (OpenAI / TF Serving / SageMaker) avec
  contrats stables (request payload + response shape).

Cible métier : **+5 à +12 % de panier moyen kiosk** sur food-court avec
upsell ciblé "side + drink + dessert". Évidence à collecter en pilote
branche unique avant rollout.

---

## 2. Architecture

### 2.1 Couche service (greenfield)

```
app/Services/Recommendation/
├── UpsellRecommendationService.php          # interface
└── Strategies/
    ├── RuleBasedStrategy.php                # heuristique cart-categorie (V1.x stub)
    └── MlPlaceholderStrategy.php            # délègue à RuleBased en V1.x ; appel ML en V2
```

`UpsellRecommendationService` **est une interface neuve** :

```php
interface UpsellRecommendationService
{
    /**
     * @param array $cart      [['item_id'=>1,'quantity'=>2,'category_id'=>3], ...]
     * @param int   $branchId
     * @param int|null $userId Customer si loggué, null sinon
     * @return array<int, array{item_id:int,name:string,price:float,reason:string,score:float}>
     */
    public function recommend(array $cart, int $branchId, ?int $userId = null): array;
}
```

### 2.2 Cohabitation avec UpsellRuleService

| Service | Rôle | Quand l'appeler |
| --- | --- | --- |
| `App\Services\Kiosk\UpsellRuleService` | V1 admin-curated | Production kiosk (branch scoped, invariant 100 % statique) |
| `App\Services\Recommendation\UpsellRecommendationService` | V2 evolutif | A/B test futur, pilote branche |

**Règle d'or** : `UpsellRuleService` reste l'**unique source d'upsell** servi
par le contrat kiosk en V1.x. Le nouveau service vit en parallèle, contrôlé
par feature flag (cf. §3 phases). Aucun composant kiosk frozen n'appelle
`UpsellRecommendationService` dans cette wave.

### 2.3 Strategy resolution (DI propre)

```php
// AppServiceProvider::register()
$this->app->bind(
    \App\Services\Recommendation\UpsellRecommendationService::class,
    function ($app) {
        $strategy = config('recommendation.strategy', 'rule_based');
        return match ($strategy) {
            'ml_placeholder' => $app->make(\App\Services\Recommendation\Strategies\MlPlaceholderStrategy::class),
            'rule_based'     => $app->make(\App\Services\Recommendation\Strategies\RuleBasedStrategy::class),
            default          => $app->make(\App\Services\Recommendation\Strategies\RuleBasedStrategy::class),
        };
    }
);
```

Le controller fait alors `__construct(UpsellRecommendationService $service)`
— DI standard, pas de `Str::studly` / class-string dynamique fragile.

### 2.4 Endpoint API

```
POST /api/frontend/recommendations/upsell
```

Middleware : `auth:sanctum` + `kiosk:order` ability check (parité avec
`UpsellController::suggest`) + `throttle:30,1`. Pas guest pour cette wave —
on alignera plus tard si nécessaire.

Payload :
```json
{
  "cart": [{"item_id": 12, "quantity": 1}, ...],
  "branch_id": 7
}
```

Réponse :
```json
{
  "data": [
    {"item_id": 21, "name": "Frites moyennes", "price": 2.5, "reason": "side_for_burger", "score": 0.82}
  ]
}
```

### 2.5 Branch isolation

`Item` n'a pas de `branch_id`. La portée branche se fait via la pivot
`item_branch_availability` (`App\Models\ItemBranchAvailability`). Toute
recommandation **doit** filtrer sur les items disponibles pour la branche
demandée :

```php
Item::query()
    ->whereIn('id', $candidateIds)
    ->whereHas('branchAvailability', fn ($q) => $q
        ->where('branch_id', $branchId)
        ->where('is_available', true)
    )
    ->get();
```

---

## 3. Phases

### Phase A — V1.x scaffolding (CETTE WAVE, livré 2026-05-08)
**Livraison** :
- interface + 2 stratégies + controller + route + tests + bind config-driven
- `RECOMMENDATION_STRATEGY=rule_based` par défaut (config/recommendation.php)
- Endpoint **non câblé** dans le wizard kiosk frozen — il est seulement
  appelable de l'extérieur ou via futur composant Vue gated.
- Pas de modification de `UpsellRuleService` ni du `UpsellController`.

**Acceptance** :
- composer + phpunit verts (suite Recommendation green)
- pas de régression sur `UpsellApiTest` ni `KioskPhase1/UpsellRule*`

### Phase B — V1.y A/B test (planifié, gate manuel)
- introduire un composable Vue `useRecommendationABTest` qui choisit entre
  `UpsellRuleService` (légacy) et `UpsellRecommendationService` (nouveau)
  côté composant `KioskUpsellComponent` (NB: ce composant N'est PAS frozen,
  vérifier liste de référence avant edit).
- ajouter cohort assignment (50/50 par session id) + analytics opt-in.
- Branch unique pilot: 2 semaines, lecture KPIs (CTR upsell, panier moyen).

### Phase C — V2 production ML (cible 2026-Q3)
- `MlPlaceholderStrategy` → vraie implémentation appelant un service ML
  externe (HTTP) avec timeout strict (≤200ms) + circuit-breaker + fallback
  obligatoire vers `RuleBasedStrategy` si le service ML est down.
- Latence kiosk SLO : `recommend()` < 250 ms p95 (sinon timeout + fallback).
- Cache local Redis 60s par (branch_id, cart hash) pour amortir les appels.

---

## 4. Sub-tasks atomiques (Phase A — cette wave)

| # | Tâche | Fichiers | Owner | Tests |
| --- | --- | --- | --- | --- |
| A1 | Crée interface `UpsellRecommendationService` | `app/Services/Recommendation/UpsellRecommendationService.php` | Claude | unit (interface contract) |
| A2 | Crée `RuleBasedStrategy` heuristique catégories | `app/Services/Recommendation/Strategies/RuleBasedStrategy.php` | Claude | feature/recommendation × 4 |
| A3 | Crée `MlPlaceholderStrategy` (délégation V1.x) | `app/Services/Recommendation/Strategies/MlPlaceholderStrategy.php` | Claude | feature/recommendation × 1 |
| A4 | Crée `config/recommendation.php` | `config/recommendation.php` | Claude | n/a |
| A5 | Bind dans `AppServiceProvider::register()` | `app/Providers/AppServiceProvider.php` | Claude | smoke test container |
| A6 | Crée `UpsellRecommendationController@recommend` | `app/Http/Controllers/Frontend/UpsellRecommendationController.php` | Claude | feature × 1 |
| A7 | Route additive `POST /api/frontend/recommendations/upsell` | `routes/api.php` | Claude | route exists test |
| A8 | Suite tests Feature/Recommendation | `tests/Feature/Recommendation/UpsellRecommendationTest.php` | Claude | 6 tests min |

---

## 5. Tests required

### 5.1 PHPUnit (Feature/Recommendation/UpsellRecommendationTest)

Cas couverts (au minimum 6) :

1. `recommends_sides_drinks_when_cart_contains_burger`
   — panier 1× burger → suggestion contient au moins 1 side ou drink dispo branche.
2. `recommends_dessert_when_cart_has_3_plus_items`
   — panier 3 items, aucune cat dessert → suggestion contient ≥1 dessert.
3. `recommends_combo_when_cart_under_10_eur`
   — panier total < 10€ → suggestion contient ≥1 item flag "combo" ou top-seller.
4. `endpoint_validates_input`
   — payload sans `cart` ou `branch_id` → HTTP 422.
5. `respects_branch_id_isolation`
   — items disponibles seulement en branche A ne sont jamais suggérés en B.
6. `ml_placeholder_strategy_falls_back_to_rule_based`
   — switch `RECOMMENDATION_STRATEGY=ml_placeholder` → comportement identique
     à `rule_based` (assertion sur taille et structure du payload).

Tests utilisent `RefreshDatabase` + `forceCreate`. Auth via Sanctum stub
(`actingAs($user, 'sanctum')` + token ability `kiosk:order` ; cf. helper
existant dans la suite kiosk).

### 5.2 Régression
- `UpsellApiTest` doit rester vert.
- `KioskPhase1/UpsellRuleModelTest` doit rester vert.
- `composer dump-autoload` après ajout du namespace.

---

## 6. Risks

| Risque | Sévérité | Mitigation |
| --- | --- | --- |
| **Contradiction invariant V1** : `UpsellRuleService` invariant §1.5 dit "100% admin statiques, jamais ML/stats". Le nouveau `RuleBasedStrategy` fait des heuristiques (cart→category) qui ne sont **pas** admin-curées. | HIGH | Service neuf vit en parallèle, **non utilisé** par `UpsellController`/wizard kiosk en V1.x. Plan §2.2 acte la cohabitation. Doc claire dans le service neuf. |
| Over-recommendation → cart abandonment kiosk | MEDIUM | Phase B : limiter à 3 items max, A/B test cohort, kill-switch via config |
| Latence Vue → API > 250ms perceptible | MEDIUM | Cache redis Phase C ; timeouts stricts ; fallback obligatoire vers `UpsellRuleService` si l'endpoint timeout |
| Pricing recalculé côté Vue | HIGH (F-011) | **Le prix renvoyé par l'endpoint est SSOT backend**. Le frontend ne recompose **jamais** le prix. Si `KioskUpsellComponent` ajoute au panier, il passe par `pricing/preview` (déjà SSOT). |
| Branch isolation violation (recommendation cross-branche) | HIGH | Test `respects_branch_id_isolation` obligatoire. Filter via `item_branch_availability`. |
| Strategy switch en prod sans rollback | MEDIUM | Config-driven, env var `RECOMMENDATION_STRATEGY`, peut être bumpé sans déploiement code |
| Bot d'audit / scraping endpoint sans throttle | LOW | `throttle:30,1` aligné existing endpoints kiosk |

---

## 7. Effort & rollout

- **Phase A scaffolding (cette wave)** : 0.5j orchestrateur (livré dans ce
  même commit avec V2-4).
- **Phase B A/B test** : 3-4j (composable Vue + analytics + cohort).
- **Phase C ML production** : 4-6j (intégration ML service + cache + circuit
  breaker + monitoring) — gated par succès Phase B.

**Effort total estimé : 8-10j ouvrés**, étalé sur 6-8 semaines avec gates
manuels entre phases.

---

## 8. References

- [`app/Services/Kiosk/UpsellRuleService.php`](../app/Services/Kiosk/UpsellRuleService.php) — V1 admin-curated upsell (à NE PAS modifier dans cette wave)
- [`app/Http/Controllers/Frontend/UpsellController.php`](../app/Http/Controllers/Frontend/UpsellController.php) — controller V1 (légacy + admin-rules)
- `plans/PLAN_AUDIT_F011_PRICING_SSOT_DUPLICATION_2026-05-07.md` — invariant pricing SSOT backend
- `docs/PROJECT_CONTINUITY_AND_VISION.md` — vision long terme (référence indirecte)

---

## 9. Anti-drift checklist

- [x] Plan ne touche **aucun** fichier frozen (KioskApp/Payment/Wizard/POS wizard/F-001..F-017)
- [x] Plan ne dépend pas de modifications dans `UpsellController.php` ou `UpsellRuleService.php`
- [x] Plan acte la cohabitation et le respect de l'invariant V1 §1.5
- [x] Plan respecte branch isolation (item_branch_availability)
- [x] Plan respecte SSOT pricing (F-011)
- [x] Plan a un kill-switch (config RECOMMENDATION_STRATEGY)
- [x] Plan a 6 tests Feature minimum, branchés sur conventions repo (RefreshDatabase + forceCreate)
