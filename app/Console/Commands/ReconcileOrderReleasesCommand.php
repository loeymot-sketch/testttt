<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Menu\AvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Console\Command;

/**
 * [DEEP-R2 2026-07-15 / P1 crash-reprise] Filet de rattrapage des libérations
 * perdues : OrderCanceled est un plain event (non-outboxé) dont les listeners
 * synchrones tournent APRÈS le commit — un crash dans cette fenêtre laisse une
 * commande annulée/détruite avec released_qty < quantity → stock physique
 * sous-évalué + quota journalier jamais rendu, sans AUCUNE trace.
 *
 * Cible STRICTE (pas de sur-libération) :
 *  - commandes en état terminal plein (CANCELED/REJECTED/RETURNED) OU soft-deleted,
 *  - fenêtre 48 h,
 *  - au moins une ligne (withTrashed) avec released_qty < quantity.
 * Les remboursements PARTIELS (status livré/terminé) sont exclus par construction.
 * Idempotent : la libération s'appuie sur le ledger released_qty existant.
 * Quota journalier : rendu UNIQUEMENT si la commande date d'aujourd'hui
 * (miroir du garde temps-minuit des listeners).
 */
class ReconcileOrderReleasesCommand extends Command
{
    protected $signature = 'foodking:reconcile-releases {--hours=48 : Fenêtre de scan} {--dry-run : Lister sans corriger}';

    protected $description = 'Rattrape les libérations stock/quota perdues (crash post-commit) sur les commandes annulées/détruites';

    public function handle(StockService $stockService, AvailabilityService $availabilityService): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        $terminal = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];

        $candidates = Order::withTrashed()
            ->where('updated_at', '>=', $since)
            ->where(function ($q) use ($terminal) {
                $q->whereIn('status', $terminal)->orWhereNotNull('deleted_at');
            })
            ->whereHas('orderItems', function ($q) {
                $q->withTrashed()->whereColumn('released_qty', '<', 'quantity');
            })
            ->orderBy('id')
            ->limit(200)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('RECONCILE RELEASES — rien à rattraper.');

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($candidates as $order) {
            $pending = $order->orderItems()->withTrashed()
                ->whereColumn('released_qty', '<', 'quantity')
                ->get(['id', 'item_id', 'branch_id', 'quantity', 'released_qty']);

            $this->line(sprintf(
                '- order #%d (status=%s%s) : %d ligne(s) non libérée(s)',
                $order->id,
                $order->status,
                $order->deleted_at ? ', trashed' : '',
                $pending->count()
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            // Stock physique (intemporel) — idempotent via ledger released_qty.
            $stockService->releaseForOrder($order, 'order_canceled');

            // Ledger released_qty écrit TOUJOURS (sinon la commande rematche la
            // requête candidate à chaque passage) ; le quota journalier n'est
            // crédité que si la commande date d'aujourd'hui. [DEEP-R2b]
            $lineItems = $pending->map(static fn ($oi) => [
                'order_item_id' => (int) $oi->id,
                'item_id' => (int) $oi->item_id,
                'branch_id' => (int) $oi->branch_id,
                'qty' => max(0, (int) $oi->quantity - (int) $oi->released_qty),
            ])->filter(static fn ($li) => $li['qty'] > 0)->values()->all();

            if ($lineItems !== []) {
                $availabilityService->releaseForOrderItems(
                    $lineItems,
                    $order->created_at !== null && $order->created_at->isToday()
                );
            }

            $fixed++;
        }

        $this->info(sprintf(
            'RECONCILE RELEASES — %d commande(s) %s.',
            $this->option('dry-run') ? $candidates->count() : $fixed,
            $this->option('dry-run') ? 'à rattraper (dry-run)' : 'rattrapée(s)'
        ));

        return self::SUCCESS;
    }
}
