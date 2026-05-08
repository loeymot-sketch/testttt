<?php

namespace App\Services\Recommendation\Strategies;

use App\Services\Recommendation\UpsellRecommendationService;

/**
 * V2-3 Phase A — MlPlaceholderStrategy.
 *
 * V1.x : délègue à `RuleBasedStrategy` pour permettre un câblage immédiat
 *   du switch de stratégie sans casser les contrats.
 * V2 (Phase C cible Q3) : remplacement par un appel HTTP vers un service
 *   ML externe (Whisper / TF Serving / SageMaker) avec :
 *     - timeout strict ≤ 200ms
 *     - circuit breaker (laravel/horizon ou Cache::lock)
 *     - fallback obligatoire vers RuleBasedStrategy si circuit ouvert
 *     - cache redis 60s par (branch_id, cart hash)
 *
 * Voir `plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md` §3 Phase C.
 */
final class MlPlaceholderStrategy implements UpsellRecommendationService
{
    public function __construct(private readonly RuleBasedStrategy $fallback)
    {
    }

    public function recommend(array $cart, int $branchId, ?int $userId = null): array
    {
        // V1.x : délégation pure. La signature et le shape de retour sont
        // identiques au contrat de l'interface, garantissant que le switch
        // RECOMMENDATION_STRATEGY=ml_placeholder en V1.x est neutre.
        //
        // V2 (Phase C) :
        //   1. Hash cart + branch_id pour cache key
        //   2. Cache::remember(60, fn() => $this->callMlService(...))
        //   3. Try/catch avec fallback explicit sur $this->fallback->recommend(...)
        return $this->fallback->recommend($cart, $branchId, $userId);
    }
}
