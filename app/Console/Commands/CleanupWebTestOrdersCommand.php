<?php

namespace App\Console\Commands;

use App\Enums\Source;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * [WEB-WIREUP 2026-06-26] NF525-safe cleanup of WEB e2e/test orders.
 *
 * Deletes (soft) ONLY orders that are:
 *   - source = WEB (5)
 *   - NOT fiscalised (fiscal_sequence_no IS NULL) — a fiscalised order is sealed and
 *     MUST NEVER be deleted (NF525 append-only audit chain, 6y retention).
 * Optionally scoped to a test phone (recommended for e2e). Dry-run by default; pass
 * --confirm to actually soft-delete. Every deletion is logged.
 */
class CleanupWebTestOrdersCommand extends Command
{
    protected $signature = 'foodking:cleanup-web-test-orders
        {--confirm : Actually soft-delete (default is dry-run)}
        {--phone= : Restrict to orders whose user has this phone (test guest, e.g. 600000099)}
        {--older-than-hours= : Only orders created more than N hours ago}';

    protected $description = 'NF525-safe soft-delete of non-fiscalised WEB (source=5) test orders';

    public function handle(): int
    {
        $confirm = (bool) $this->option('confirm');
        $phone = $this->option('phone');
        $olderThanHours = $this->option('older-than-hours');

        // [WEB-WIREUP 2026-06-26] Drop only the BranchScope (guest test orders span branch_id);
        // keep the SoftDeletingScope so already soft-deleted orders are not re-listed/re-touched.
        $query = Order::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('source', Source::WEB)
            ->whereNull('fiscal_sequence_no') // hard NF525 guard: never touch fiscalised orders
            // [SELF-AUDIT D 2026-07-05 — perte de commande PAYÉE Uber] Le canal Uber réutilise
            // source=WEB (collision). Une commande Uber LIVE est source=WEB + fiscal_sequence_no NULL
            // → elle matchait cette purge de commandes de TEST web → soft-delete → le dédup Uber
            // (createFromUber, plural withoutGlobalScopes) retrouve le tombstone et REFUSE de recréer
            // → perte DÉFINITIVE d'une commande aggregateur PAYÉE. Un vrai invité e2e web est NON payé
            // et sans source_surface 'uber_eats'. On exclut les DEUX signatures (défense en profondeur).
            ->where('payment_status', '!=', \App\Enums\PaymentStatus::PAID)
            ->where(function ($q) {
                $q->whereNull('source_surface')->orWhere('source_surface', '!=', 'uber_eats');
            });

        if ($phone) {
            $query->whereHas('user', fn ($q) => $q->withoutGlobalScopes()->where('phone', $phone));
        }
        if ($olderThanHours !== null && is_numeric($olderThanHours)) {
            $query->where('created_at', '<', now()->subHours((int) $olderThanHours));
        }

        $orders = $query->orderByDesc('id')->get();

        if ($orders->isEmpty()) {
            $this->info('No matching non-fiscalised WEB orders found.');

            return self::SUCCESS;
        }

        $this->info(($confirm ? 'DELETING' : 'DRY-RUN — would delete')." {$orders->count()} WEB order(s):");
        $deleted = 0;
        $skipped = 0;
        foreach ($orders as $order) {
            // Defensive double-check: a non-null fiscal sequence here would mean sealed → skip.
            if (! is_null($order->fiscal_sequence_no)) {
                $this->warn("  SKIP order #{$order->id} — fiscalised (sealed, NF525).");
                $skipped++;

                continue;
            }
            $line = "  order #{$order->id} branch={$order->branch_id} total={$order->total} status={$order->status} @{$order->created_at}";
            if ($confirm) {
                $order->delete(); // soft-delete
                Log::info('[cleanup-web-test-orders] soft-deleted', [
                    'order_id' => $order->id, 'branch_id' => $order->branch_id, 'source' => Source::WEB,
                ]);
                $deleted++;
                $this->line($line.'  → DELETED');
            } else {
                $this->line($line);
            }
        }

        $this->newLine();
        if ($confirm) {
            $this->info("Soft-deleted {$deleted} order(s), skipped {$skipped} (sealed).");
        } else {
            $this->comment("Dry-run only. Re-run with --confirm to soft-delete. (skipped sealed: {$skipped})");
        }

        return self::SUCCESS;
    }
}
