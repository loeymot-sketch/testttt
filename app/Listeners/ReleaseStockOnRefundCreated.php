<?php

namespace App\Listeners;

use App\Events\RefundCreated;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReleaseStockOnRefundCreated
{
    public function handle(RefundCreated $event): void
    {
        // [Audit abusif stock P2 · 2026-11-06] Jumeau de ReleaseStockOnOrderCanceled::materialAlreadyCommitted() :
        // une commande déjà PRÉPARÉE (prepared_at posé dès « prête », « en livraison » ou « livrée », effacé si la
        // cuisine la rouvre) a transformé sa marchandise. La rendre au stock surestimait on_hand (canette vendable qui
        // n'existe plus) alors que le chemin d'annulation inscrivait déjà une perte (reproduit : commande 9928,
        // refund +1 ET stock_outflows « perte »). On ne restitue RIEN dans ce cas.
        $prepareeLe = \Illuminate\Support\Facades\DB::table('orders')->where('id', (int) ($event->order->id ?? 0))->value('prepared_at');
        if ($prepareeLe !== null) {
            Log::info('ReleaseStockOnRefundCreated: commande déjà préparée — aucune restitution de stock', ['order_id' => $event->order->id ?? null]);

            return;
        }

        try {
            app(StockService::class)->releaseForOrder($event->order, 'refund', $event->refundedItems);
        } catch (Throwable $e) {
            // [GOAL-I2-HEAL-01 2026-05-24] Phase I.4 AMBER I4-CONCERN-02 sibling.
            //
            // Pre-heal : ZERO try/catch. RefundCreated uses DispatchableAfterCommit and
            // EventServiceProvider:179-186 lists Stock BEFORE Availability — a Throwable
            // here halts Laravel's sync dispatcher and skips ReleaseAvailabilityOnRefundCreated
            // → divergent stock vs availability ledgers on refund failure.
            //
            // Mirror the OrderCanceled cascade hardening : Log::error + return,
            // sibling availability release listener now guaranteed to fire.
            Log::error('ReleaseStockOnRefundCreated isolated (cascade continues)', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
