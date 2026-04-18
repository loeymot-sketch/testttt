<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\KioskMachine;
use App\Services\Kiosk\UpsellRuleService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Kiosk Design V1 — Phase 1.7
 *
 * `GET /api/frontend/upsell?cart_item_ids=1,5,12&cart_total=24.50[&limit=4]`
 *
 * Prise en compte des règles `upsell_rules` branch-scoped. Si 0 règle ne
 * matche, fallback sur la suggestion legacy via `Item::where('is_upsell',1)`
 * (aucune régression UX tant que les règles ne sont pas configurées).
 */
class UpsellController extends Controller
{
    public function __construct(private readonly UpsellRuleService $service = new UpsellRuleService())
    {
    }

    public function suggest(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->tokenCan('kiosk:order')) {
            return response()->json([
                'status'  => false,
                'message' => 'Accès kiosk requis.',
            ], 403);
        }

        $kioskMachine = KioskMachine::query()->where('user_id', $user->id)->first();
        if (!$kioskMachine) {
            return response()->json([
                'status'  => false,
                'message' => 'Borne non associée.',
                'code'    => 'KIOSK_MACHINE_NOT_FOUND',
            ], 503);
        }

        $branchId = (int) $kioskMachine->branch_id;

        // Parse `cart_item_ids` — CSV ou tableau querystring.
        $rawIds = $request->input('cart_item_ids', '');
        $ids = [];
        if (is_array($rawIds)) {
            $ids = array_map('intval', $rawIds);
        } elseif (is_string($rawIds) && $rawIds !== '') {
            $ids = array_map('intval', array_filter(explode(',', $rawIds), 'strlen'));
        }
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v > 0)));

        $cartTotal = (float) $request->input('cart_total', 0);
        $limit     = (int) $request->input('limit', UpsellRuleService::DEFAULT_LIMIT);

        // Enrichit avec category_id (nécessaire au matching `category_in_cart`).
        $cartLines = [];
        if ($ids) {
            $items = Item::query()
                ->whereIn('id', $ids)
                ->get(['id', 'item_category_id']);
            foreach ($items as $it) {
                $cartLines[] = [
                    'item_id'     => (int) $it->id,
                    'category_id' => (int) $it->item_category_id,
                ];
            }
        }

        try {
            $suggestions = $this->service->suggest($branchId, $cartLines, $cartTotal, $limit);

            if (empty($suggestions)) {
                // Fallback legacy (Phase 1 non-régression) — items `is_upsell=1`
                // scope channel kiosk, limitées à `limit`, hors items déjà au panier.
                $suggestions = $this->legacyFallback($ids, $limit);
            }

            return response()->json([
                'status' => true,
                'data'   => $suggestions,
            ], 200);
        } catch (Exception $e) {
            Log::error('[Upsell] '.$e->getMessage(), ['branch_id' => $branchId]);
            return response()->json([
                'status'  => false,
                'message' => 'Erreur serveur.',
            ], 500);
        }
    }

    /**
     * @param  int[]  $cartIds
     * @return array<int, array<string, mixed>>
     */
    private function legacyFallback(array $cartIds, int $limit): array
    {
        $query = Item::query()
            ->where('is_upsell', 1)
            ->where('status', \App\Enums\Status::ACTIVE);

        if ($cartIds) {
            $query->whereNotIn('id', $cartIds);
        }

        return $query->limit($limit)->get()->map(fn (Item $it) => [
            'id'           => (int) $it->id,
            'name'         => (string) $it->name,
            'slug'         => (string) $it->slug,
            'price'        => (float) $it->price,
            'kiosk_emoji'  => $it->kiosk_emoji,
            'is_chef_pick' => (bool) ($it->is_chef_pick ?? false),
            'category_id'  => (int) $it->item_category_id,
            'upsell_rule'  => null,
        ])->values()->all();
    }
}
