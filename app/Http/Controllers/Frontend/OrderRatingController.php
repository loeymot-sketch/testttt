<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\FrontendOrder;
use App\Models\OrderRating;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * [WAVE-ALPHA-A3 / M-3] CSAT 5-star inline endpoint.
 *
 * POST /api/frontend/order/{orderId}/rating
 *
 * Reçoit une note 1..5 + commentaire optionnel ; idempotent par
 * (order_id, order_type) via updateOrCreate. Throttle externe
 * (10/min) défini dans routes/api.php pour limiter le spam.
 *
 * @see database/migrations/2026_05_08_050000_create_order_ratings_table.php
 */
class OrderRatingController extends Controller
{
    public function store(Request $request, $orderId): JsonResponse
    {
        // [SEC-HEAL-2026-05-08] Ultra-review P0 finding : ce endpoint manquait
        // une vérification d'ability + ownership intra-branche. Sans ça, n'importe
        // quel sanctum-user authentifié sur la même branche pouvait écraser le
        // rating d'une commande d'un autre customer (intra-branch tampering).
        // Parité corrigée avec UpsellController + UpsellRecommendationController
        // qui requièrent tokenCan('kiosk:order'). CLAUDE.md non-négociable #8 :
        // "Branch isolation must never be weakened" — le trou intra-branche est
        // également une faiblesse d'autorisation.
        $user = $request->user();
        if (!$user || !$user->tokenCan('kiosk:order')) {
            return response()->json([
                'status'  => false,
                'message' => 'Accès kiosk requis pour noter une commande.',
            ], 403);
        }

        // [SEC-HEAL-2026-05-08 iter2] Ultra-review HEAL data integrity finding :
        // `order_type` n'est PLUS accepté en body. L'exploit était : POSTer
        // `order_type=Order` puis `order_type=FrontendOrder` créait 2 rows
        // pour la même commande physique (Order et FrontendOrder partagent la
        // table `orders`), polluant les agrégats CSAT/NPS de la branche.
        // Désormais : 1 rating par order_id, point. La colonne `order_type`
        // reste fixée à 'FrontendOrder' (kiosk default) pour traçabilité.
        $request->validate([
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
            'source'  => 'sometimes|in:kiosk,web,mobile,pos',
        ]);

        // [SEC] Lecture branch-scoped (BranchScope global sur FrontendOrder) —
        // ne JAMAIS strip le scope ici, sinon n'importe quel sanctum-user
        // d'une autre branche pourrait noter (et écraser) la commande d'une
        // branche tierce. Le kiosk est lié à un user dont `branch_id` matche
        // l'order via KioskMachine, donc le find() résout correctement.
        // CLAUDE.md non-négociable #8 : "Branch isolation must never be weakened."
        $order = FrontendOrder::find($orderId);
        if (!$order) {
            return response()->json([
                'status'  => false,
                'message' => 'Order not found',
            ], 404);
        }

        try {
            $rating = OrderRating::updateOrCreate(
                [
                    'order_id' => (int) $orderId,
                ],
                [
                    'branch_id'      => (int) $order->branch_id,
                    'order_type'     => 'FrontendOrder', // forced — plus jamais user input
                    'rating'         => (int) $request->input('rating'),
                    'comment'        => $request->input('comment'),
                    'source_surface' => $request->input('source', 'kiosk'),
                ]
            );

            return response()->json([
                'status' => true,
                'data'   => $rating,
            ], 201);
        } catch (QueryException $e) {
            // [SEC-HEAL-2026-05-08 iter2] Race condition idempotency : 2 POSTs
            // simultanés sur le même order_id peuvent perdre le SELECT-then-INSERT
            // race d'updateOrCreate, hitter l'unique key, et lever 1062 (MySQL)
            // ou "UNIQUE constraint failed" (SQLite). Re-fetch le rating gagnant
            // pour préserver le contrat idempotent du endpoint.
            if ($this->isUniqueViolation($e)) {
                $rating = OrderRating::where('order_id', (int) $orderId)->first();
                if ($rating !== null) {
                    return response()->json([
                        'status' => true,
                        'data'   => $rating,
                    ], 201);
                }
            }
            Log::error('[OrderRating] write failed (query)', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Could not save rating',
            ], 500);
        } catch (\Throwable $e) {
            Log::error('[OrderRating] write failed', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Could not save rating',
            ], 500);
        }
    }

    /**
     * Détecte une violation d'unique constraint cross-driver (MySQL 1062 +
     * SQLite "UNIQUE constraint failed" + Postgres 23505 par défensivité).
     *
     * [SEC-HEAL-2026-05-08 iter2] Pattern défensif : on n'avale qu'un cas
     * spécifique, jamais une erreur DB générique.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        // MySQL ER_DUP_ENTRY = 1062 ; Postgres unique_violation = 23505
        if ($driverCode === 1062 || $sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        // SQLite : pas d'errorInfo[1] standard, fallback sur le message.
        if (str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'order_rating_unique')
            || str_contains($message, 'Duplicate entry')) {
            return true;
        }

        return false;
    }
}
