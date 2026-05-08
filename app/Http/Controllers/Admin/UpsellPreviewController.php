<?php

namespace App\Http\Controllers\Admin;

use App\Services\Recommendation\Strategies\MlPlaceholderStrategy;
use App\Services\Recommendation\Strategies\RuleBasedStrategy;
use App\Services\Recommendation\UpsellRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * V2-3 Phase A — Admin Upsell preview tool (greenfield).
 *
 * `POST /api/admin/upsell-preview`
 *
 * Permet à un admin (permission `settings`) de tester le service de
 * recommandation sans modifier le panier ni publier en production. Sert à :
 *   - QA des heuristiques RuleBasedStrategy
 *   - Comparer rule_based vs ml_placeholder en pré-prod
 *   - Mesurer la latence (objectif <200ms en prod, mais peut spike sur
 *     SQLite test/cold-start — le contrat ici est "on mesure").
 *
 * Cohabitation V1.x : ce controller n'est PAS appelé par les frozen
 * components kiosk (KioskUpsellComponent admin-curated). C'est un outil
 * d'aperçu admin, idempotent (read-only sur la DB).
 *
 * Voir plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md (preview tool).
 */
class UpsellPreviewController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:settings'])->only('preview');
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cart_items'             => 'required|array|min:1',
            'cart_items.*.item_id'   => 'required|integer|min:1',
            'cart_items.*.quantity'  => 'required|integer|min:1',
            'cart_items.*.category_id' => 'sometimes|integer|min:1',
            'cart_items.*.price'     => 'sometimes|numeric|min:0',
            'branch_id'              => 'required|integer|min:1',
            'strategy'               => 'sometimes|in:rule_based,ml_placeholder',
        ]);

        $strategy = $validated['strategy'] ?? 'rule_based';

        // Strategy resolution with explicit map — safer than dynamic class
        // resolution (no input class injection even though Str::studly is
        // sanitised by the validation rule above).
        $service = $this->resolveStrategy($strategy);

        $startTime = microtime(true);

        $recommendations = $service->recommend(
            $validated['cart_items'],
            (int) $validated['branch_id'],
            null
        );

        $latencyMs = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'recommendations' => $recommendations,
            'latency_ms'      => $latencyMs,
            'strategy_used'   => $strategy,
            'cart_size'       => count($validated['cart_items']),
        ]);
    }

    private function resolveStrategy(string $strategy): UpsellRecommendationService
    {
        return match ($strategy) {
            'ml_placeholder' => app(MlPlaceholderStrategy::class),
            default          => app(RuleBasedStrategy::class),
        };
    }
}
