# V14 #5 — T06 — `P14_VARIATION_VALIDATION_BACKEND`

## Header

```
TASK_ID: V14_05_T06_FORM_REQUEST_MULTI_QTY
WAVE: B — POS Backend Validation
GATE_REFERENCE: aucun (Form Request layer, pas de zone gelée)
PRIMARY_MODEL: Composer (foodking-routine-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_04_T02_T20_POS_UI_MULTI_QTY_FUSED, V14_06_T04_FIXTURES_REPAIR_DRY_RUN
DEPENDS_ON: V14 Vague A (T01 colonnes + T05 PricingService::assertVariationConstraints — déjà en place)
SEVERITY: P1
EFFORT_EST: 2h
```

## Contexte

`PricingService::assertVariationConstraints` (V1 / T05) lance déjà une `\InvalidArgumentException` 422 si `min_select / max_select / allow_repeat` sont violés. Mais **cette validation est tardive** : elle court au milieu du calcul SSOT, pas au niveau Form Request. Conséquence :
- L'erreur 422 affichée au front n'est pas structurée Laravel (`{"errors": {"items.X.item_variations": [...]}}`)
- Le client ne peut pas associer l'erreur à un champ précis du formulaire
- Pas de localisation FR/EN/AR

T06 ajoute une **règle Laravel dédiée** `MultiVariationConstraint` qui préjuge le payload côté Form Request, AVANT l'appel pricing. Le check pricing reste en defense-in-depth.

## SUBSYSTEMS_TOUCHED

- `app/Rules/MultiVariationConstraint.php` (CREATE — implémente `Illuminate\Contracts\Validation\Rule` ou `ValidationRule`)
- `app/Rules/ValidJsonOrder.php` (EDIT minimal — étendre pour appeler `MultiVariationConstraint` dans la boucle items, OU rester séparé)
- `app/Http/Requests/PlaceFrontEndOrderRequest.php` ou équivalent kiosk (EDIT — ajouter règle sur `items.*.item_variations`)
- `app/Http/Requests/Admin/...` request POS si elle existe (EDIT)
- `tests/Feature/MultiVariationValidationTest.php` (CREATE — 8 cas)
- `resources/lang/{fr,en,ar}/validation.php` (EDIT — clés de traduction)

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/Pricing/PricingService.php` (Vague A frozen — déjà valide)
- `app/Services/OrderService.php`, `FrontendOrderService.php` (frozen)
- `app/Models/**` (T01 territoire)
- `database/migrations/**` (Vague A territoire)
- `resources/js/**` (T02 territoire — autre subagent en parallèle)
- `database/seeders/**` (T04 territoire — autre subagent)

## INVARIANTS_AT_RISK

1. **Backward compat** : un payload legacy `[{id: 42}]` (single-select sans quantity) doit passer sans erreur (interpréter `quantity = 1` par défaut).
2. **Erreur 422 structurée** : la réponse doit suivre le contrat Laravel `{"message": "...", "errors": {"items.0.item_variations": ["..."]}}`.
3. **Messages localisés** : FR par défaut, EN et AR si clés présentes (sinon fallback FR).
4. **Defense-in-depth** : ne pas supprimer la validation côté `PricingService` (Vague A) — la Form Request est en amont, le service reste un garde-fou.
5. **Performance** : la règle doit lazy-load les `item_attributes` (pas de N+1 par variation).

## TÂCHES À EXÉCUTER

### 1. Créer `app/Rules/MultiVariationConstraint.php`

```php
namespace App\Rules;

use App\Models\ItemVariation;
use App\Models\ItemAttribute;
use Illuminate\Contracts\Validation\ValidationRule;
use Closure;

class MultiVariationConstraint implements ValidationRule
{
    /**
     * @param  array  $variations  Format SSOT : [{id, quantity?}]
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return; // pas de variation → rien à valider (legacy / produit simple)
        }

        // Récupère les ids variation, charge attributes en batch
        $varIds = collect($value)->pluck('id')->filter()->unique()->values()->all();
        if ($varIds === []) return;

        $variations = ItemVariation::query()->whereIn('id', $varIds)->get()->keyBy('id');
        $attrIds = $variations->pluck('item_attribute_id')->unique()->values()->all();
        $attributes = ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id');

        // Group quantité par attribute_id
        $byAttr = [];
        foreach ($value as $v) {
            $varId = (int) ($v['id'] ?? 0);
            $qty = max(1, (int) ($v['quantity'] ?? 1));
            $var = $variations[$varId] ?? null;
            if (! $var) continue;
            $attrId = (int) $var->item_attribute_id;
            $byAttr[$attrId][$varId] = ($byAttr[$attrId][$varId] ?? 0) + $qty;
        }

        foreach ($byAttr as $attrId => $quantities) {
            $attr = $attributes[$attrId] ?? null;
            if (! $attr) continue;
            $totalQty = array_sum($quantities);
            $min = (int) ($attr->min_select ?? 0);
            $max = (int) ($attr->max_select ?? 1);
            $allowRepeat = (bool) ($attr->allow_repeat ?? false);

            if ($min > 0 && $totalQty < $min) {
                $fail(__('validation.multi_variation.min', [
                    'attribute' => $attr->name, 'min' => $min, 'actual' => $totalQty,
                ]));
            }
            if ($max > 0 && $totalQty > $max) {
                $fail(__('validation.multi_variation.max', [
                    'attribute' => $attr->name, 'max' => $max, 'actual' => $totalQty,
                ]));
            }
            if (! $allowRepeat) {
                foreach ($quantities as $qty) {
                    if ($qty > 1) {
                        $fail(__('validation.multi_variation.no_repeat', [
                            'attribute' => $attr->name,
                        ]));
                    }
                }
            }
        }
    }
}
```

### 2. Brancher la règle dans la Form Request

Trouver la (ou les) Form Request qui valide la création d'une commande Kiosk / POS / Frontend. Probablement :
- `app/Http/Requests/PlaceFrontEndOrderRequest.php` ou `app/Http/Requests/Frontend/OrderStoreRequest.php`
- Idem côté POS si présent (`PosOrderStoreRequest`)
- Possiblement intégré dans `ValidJsonOrder` (rule custom) — si oui, ajouter le check à l'intérieur de la boucle items au lieu de créer une règle séparée

Brancher :
```php
'items.*.item_variations' => ['array', new MultiVariationConstraint()],
```

### 3. Localisation

Ajouter dans `resources/lang/fr.json` (ou `lang/fr/validation.php` si existe) :
```json
{
  "validation.multi_variation.min": "Sélectionnez au moins :min :attribute (actuel : :actual)",
  "validation.multi_variation.max": "Sélectionnez au maximum :max :attribute (actuel : :actual)",
  "validation.multi_variation.no_repeat": "L'attribut :attribute n'autorise pas la répétition d'une même variation"
}
```

EN : "Select at least :min :attribute (current: :actual)"
AR : "اختر على الأقل :min :attribute (الحالي: :actual)"

### 4. Tests Feature

CREATE `tests/Feature/MultiVariationValidationTest.php` couvrant 8 cas :
1. Payload valide single (legacy) → 200
2. Payload valide multi (3+1 viandes, allow_repeat=true) → 200
3. `total < min_select` → 422 + message FR contient `:min` et `:actual`
4. `total > max_select` → 422
5. `allow_repeat=false` mais `quantity>1` → 422 + message no_repeat
6. Mix variations valide cross-attribute → 200
7. `item_variations` vide ou absent → 200 (pas d'erreur sur produit sans variation)
8. Variation `id` inconnue → règle ignore (autres règles s'en chargent)

### 5. Régression
- `php artisan test --filter='Pricing|Order|Multi'` → 100% vert
- Aucune route legacy ne doit régresser (créer une commande POS/Kiosk avec un payload valide actuel)

## ACCEPTANCE

- [ ] `app/Rules/MultiVariationConstraint.php` créé et conforme à `ValidationRule` Laravel 10/11
- [ ] Branché dans **toutes** les Form Request qui acceptent un payload commande
- [ ] 8/8 tests Feature verts
- [ ] Régression `Pricing|OrderItem|FrontendOrder|PosOrder` : 100% vert
- [ ] Messages d'erreur FR + EN + AR (si fichiers existent)
- [ ] Aucun N+1 (utilise `whereIn` en batch)

## RUN_REPORT

`reports/execution/RUN_V14_T06_FORM_REQUEST_MULTI_QTY_2026-04-20.md`

Doit contenir : list des Form Request modifiées + diff + résultat tests + check absence N+1 (laravel debugbar ou `DB::enableQueryLog`).

## NOTES AUDITEUR

- Si la Form Request POS n'existe pas (POS passe par un controller direct), créer un test équivalent qui POST sur la route POS et vérifie le 422 structuré.
- Vérifier que la règle ne casse pas les tests existants `Pricing|OrderItem` qui peuvent envoyer des payloads minimaux.
- Si plusieurs Form Request partagent la même logique, factoriser via un trait ou helper plutôt que dupliquer.
