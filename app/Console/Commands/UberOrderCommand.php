<?php

namespace App\Console\Commands;

use App\Services\Uber\UberClient;
use Illuminate\Console\Command;

/**
 * [UBER-VALIDATION 2026-08-02] Pilotage manuel d'une commande Uber (validation sandbox + ops).
 * accept/deny/cancel/ready sur la famille /v1/delivery — deny_reason et cancellation_reason
 * sont des objets {type, info} (schéma sourcé) ; jamais OTHER/UNKNOWN par défaut (règle
 * Uber : < 10 % des refus/annulations).
 */
class UberOrderCommand extends Command
{
    protected $signature = 'uber:order
        {action : accept | deny | cancel | ready}
        {uber_order_id : UUID de la commande côté Uber}
        {--type=ITEM_ISSUE : type de raison (deny/cancel)}
        {--info= : détail libre de la raison}';

    protected $description = 'Accepte / refuse / annule / marque prête une commande Uber Eats via l\'API';

    public function handle(UberClient $client): int
    {
        $id = (string) $this->argument('uber_order_id');
        $type = (string) $this->option('type');
        $info = (string) ($this->option('info') ?: 'Action from Le Cayenne POS');

        $ok = match ((string) $this->argument('action')) {
            'accept' => $client->acceptOrder($id, [
                'reason' => $info,
                'pickup_time' => now()->addMinutes(max(1, (int) config('uber.prep_time_minutes', 15)))->timestamp,
            ]),
            'deny' => $client->denyOrderDelivery($id, [
                'deny_reason' => ['type' => $type, 'info' => $info],
            ]),
            'cancel' => $client->cancelOrder($id, [
                'cancellation_reason' => ['type' => $type, 'info' => $info],
            ]),
            'ready' => $client->readyOrder($id),
            default => null,
        };

        if ($ok === null) {
            $this->error('Action inconnue (accept|deny|cancel|ready).');
            return self::INVALID;
        }
        $this->{$ok ? 'info' : 'error'}(($ok ? 'OK ✅ ' : 'ÉCHEC ❌ ') . $this->argument('action') . ' → ' . $id . ($ok ? '' : ' (voir storage/logs)'));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
