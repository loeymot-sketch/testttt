<?php

namespace App\Console\Commands;

use App\Models\WebPushSubscription;
use Illuminate\Console\Command;

/** [NFC / NOTIFICATIONS 2026-11-20] Supprime les abonnements sans succès depuis `webpush.prune_after_days` jours. */
class WebPushPruneCommand extends Command
{
    protected $signature = 'webpush:prune';

    protected $description = 'Supprime les abonnements Web Push morts depuis trop longtemps';

    public function handle(): int
    {
        $limite = now()->subDays(max(1, (int) config('webpush.prune_after_days', 90)));
        // `updated_at` avance à chaque réabonnement (le site renvoie son abonnement au moins une fois par jour d'ouverture) et à chaque
        // envoi / échec : un appareil dont le client commande rarement mais ouvre le site reste. Seul un appareil SILENCIEUX
        // (jamais rouvert, jamais notifié) depuis `prune_after_days` jours est supprimé.
        $n = WebPushSubscription::query()->where('updated_at', '<', $limite)->delete();
        $this->info($n.' abonnement(s) supprimé(s).');

        return self::SUCCESS;
    }
}
