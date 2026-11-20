<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * [NFC / NOTIFICATIONS 2026-11-20] Génère UNE paire de clés VAPID (affichée, jamais écrite nulle part).
 * À poser UNE FOIS dans le `.env` de production ; ne plus jamais les changer (cela désabonne tous les appareils).
 */
class WebPushKeysCommand extends Command
{
    protected $signature = 'webpush:keys';

    protected $description = 'Génère une paire de clés VAPID pour les notifications Web Push (affichage seulement)';

    public function handle(): int
    {
        $cles = VAPID::createVapidKeys();
        $this->line('WEBPUSH_PUBLIC_KEY='.$cles['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$cles['privateKey']);
        $this->warn('À poser UNE FOIS dans le .env de production (avec WEBPUSH_ENABLED=true). Ne plus jamais les changer : changer la clé publique désabonne tous les appareils.');

        return self::SUCCESS;
    }
}
