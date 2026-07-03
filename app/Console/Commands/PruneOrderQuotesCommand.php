<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [NUIT-A2 2026-07-03 — order_quotes unbounded growth] Purge les devis de prix ABANDONNÉS.
 *
 * `order_quotes` reçoit une ligne à CHAQUE aperçu de tarif (OrderQuoteService), sans purge programmée —
 * contrairement aux tables sœurs (`webhook_events` → PruneWebhookEventsCommand, `domain_events` →
 * PruneOutboxCommand). Croissance ~96/j observée.
 *
 * SAFE-SET (purge) : `expires_at < cutoff AND consumed_at IS NULL` = aperçus EXPIRÉS et JAMAIS consommés
 * (abandonnés). Un devis a un TTL court (minutes) ; un devis expiré non-consommé n'a aucune valeur.
 *
 * NE PAS purger ici : les devis CONSOMMÉS (`consumed_at NOT NULL`, liés à une commande via
 * `consumed_order_id`) = preuve de signature-prix pour litige. Leur rétention (durée) est une DÉCISION
 * OWNER (valeur de preuve de litige) — hors périmètre de cette purge. La COMMANDE (snapshot figé +
 * séquence fiscale + audit_logs 6 ans) reste la source fiscale ; ce devis n'est PAS une table fiscale.
 *
 * Idempotent + chunké + réversible (aucun effet de bord).
 */
class PruneOrderQuotesCommand extends Command
{
    protected $signature = 'foodking:order-quotes:prune'
        . ' {--older-than-days=7 : Devis expirés + non-consommés plus vieux que N jours sont éligibles}'
        . ' {--batch=1000 : Lignes supprimées par lot (contrôle de la fenêtre de lock)}'
        . ' {--dry-run : Rapporte les comptes sans supprimer}';

    protected $description = 'Purge les devis de prix abandonnés (expirés + non-consommés). Consommés préservés.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('older-than-days'));
        $batch = max(100, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $applyPredicate = function ($query) use ($cutoff) {
            return $query->whereNull('consumed_at')
                ->where('expires_at', '<', $cutoff);
        };

        $total = (int) $applyPredicate(DB::table('order_quotes'))->count();

        if ($dryRun) {
            $this->info(sprintf('[dry-run] %d devis abandonné(s) éligible(s) (expirés+non-consommés > %d j).', $total, $days));

            return self::SUCCESS;
        }

        $deletedTotal = 0;
        do {
            $deleted = $applyPredicate(DB::table('order_quotes'))
                ->limit($batch)
                ->delete();
            $deletedTotal += $deleted;
        } while ($deleted > 0);

        $this->info(sprintf('Devis abandonnés purgés : %d supprimé(s) / %d éligible(s) (> %d j).', $deletedTotal, $total, $days));

        Log::channel('observability')->info('order_quotes.prune.completed', [
            'event' => 'order_quotes.prune.completed',
            'deleted' => $deletedTotal,
            'older_than_days' => $days,
        ]);

        return self::SUCCESS;
    }
}
