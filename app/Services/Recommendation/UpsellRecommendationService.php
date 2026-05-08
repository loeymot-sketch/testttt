<?php

namespace App\Services\Recommendation;

/**
 * V2-3 — AI Upsell Recommendation interface (greenfield).
 *
 * Cohabitation V1.x :
 *   - `App\Services\Kiosk\UpsellRuleService` reste l'autorité unique pour
 *     l'upsell servi en production kiosk (admin-curated, invariant §1.5).
 *   - Ce service vit en parallèle, configurable via
 *     `config('recommendation.strategy')` ; il N'EST PAS appelé par les
 *     composants frozen (KioskApp/Payment/Wizard, POS wizard) en V1.x.
 *
 * Phase A (cette wave) : 2 stratégies — RuleBased (heuristique catégories)
 *   + MlPlaceholder (délègue à RuleBased).
 * Phase B : A/B test côté composant kiosk non-frozen.
 * Phase C : MlPlaceholderStrategy → vraie intégration ML (Whisper / TF /
 *   SageMaker) avec timeout strict + circuit breaker + fallback obligatoire.
 *
 * Voir `plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md` pour le plan complet.
 */
interface UpsellRecommendationService
{
    /**
     * Retourne les suggestions d'upsell pour un panier donné.
     *
     * Invariants attendus de toute implémentation :
     *   - Branch isolation : aucune suggestion d'item indisponible sur la
     *     branche `branchId` (cf. table `item_branch_availability`).
     *   - Items déjà au panier sont dédupliqués.
     *   - Pricing : le prix retourné est SSOT backend (cf. F-011). Le client
     *     ne recompose JAMAIS le prix d'une suggestion.
     *
     * @param  array<int, array{item_id:int, quantity:int, category_id?:int}>  $cart
     *           Lignes panier (au minimum item_id + quantity ; category_id
     *           optionnel — si absent, l'implémentation peut hydrater).
     * @param  int       $branchId   ID branche (filtre disponibilité)
     * @param  int|null  $userId     Customer si loggué, null pour guest
     * @return array<int, array{
     *     item_id:int,
     *     name:string,
     *     price:float,
     *     reason:string,
     *     score:float
     * }>
     *           Liste triée par `score` desc, max 4 items.
     */
    public function recommend(array $cart, int $branchId, ?int $userId = null): array;
}
