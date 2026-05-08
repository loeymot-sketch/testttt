<?php

/**
 * V2-3 — AI Upsell recommendation strategy switch.
 *
 * Cohabitation V1.x :
 *   - `App\Services\Kiosk\UpsellRuleService` reste l'autorité unique pour
 *     l'upsell servi en production kiosk (admin-curated).
 *   - Cette config contrôle le binding interface
 *     `App\Services\Recommendation\UpsellRecommendationService` qui vit en
 *     parallèle (endpoint frontend.recommendations.upsell). Tant que
 *     l'A/B test n'est pas activé, ce service n'est PAS appelé par les
 *     composants frozen kiosk.
 *
 * Strategies disponibles :
 *   - 'rule_based'     → RuleBasedStrategy (heuristique catégories baseline)
 *   - 'ml_placeholder' → MlPlaceholderStrategy (V1.x : délègue à rule_based ;
 *                        V2 : vraie intégration ML externe)
 *
 * Voir plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default upsell recommendation strategy
    |--------------------------------------------------------------------------
    |
    | Switch piloté par variable d'environnement RECOMMENDATION_STRATEGY pour
    | rollback instantané sans déploiement code. Valeurs supportées :
    | "rule_based", "ml_placeholder".
    |
    */

    'strategy' => env('RECOMMENDATION_STRATEGY', 'rule_based'),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'max_recommendations' => env('RECOMMENDATION_MAX_ITEMS', 4),

];
