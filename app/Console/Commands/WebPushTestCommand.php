<?php

namespace App\Console\Commands;

use App\Models\WebPushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Console\Command;

/** [NFC / NOTIFICATIONS 2026-11-20] Envoie « Essai » aux appareils d'UN client nommé (jamais à tous). */
class WebPushTestCommand extends Command
{
    protected $signature = 'webpush:test {user_id : identifiant du client}';

    protected $description = 'Envoie une notification d\'essai aux appareils d\'un client';

    public function handle(WebPushSender $sender): int
    {
        if (! WebPushSender::actif()) {
            $this->error('Web Push inactif (WEBPUSH_ENABLED=false ou clés absentes).');

            return self::FAILURE;
        }
        $id = (int) $this->argument('user_id');
        $n = WebPushSubscription::query()->where('user_id', $id)->count();
        if ($n === 0) {
            $this->error('Aucun appareil abonné pour ce client.');

            return self::FAILURE;
        }
        $acceptes = $sender->sendToUser($id, ['v' => 1, 'type' => 'test', 'title' => 'Le Cayenne', 'body' => 'Essai de notification.', 'tag' => 'essai', 'url' => '/']);
        $this->info($acceptes.' appareil(s) sur '.$n.' ont accepté la notification.');

        return $acceptes > 0 ? self::SUCCESS : self::FAILURE;
    }
}
