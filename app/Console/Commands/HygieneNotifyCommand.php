<?php

namespace App\Console\Commands;

use App\Services\Hygiene\AlertNotifier;
use Illuminate\Console\Command;

/** [CAYENNE HYGIÈNE 2026-10-18] Envoie les alertes d'hygiène aux responsables (planifié toutes les 5 minutes ; ne fait rien si les alertes sont désactivées). */
class HygieneNotifyCommand extends Command
{
    protected $signature = 'hygiene:notify';

    protected $description = 'Envoie par e-mail les rappels d\'hygiène « à traiter maintenant » aux destinataires réglés (désactivé par défaut)';

    public function handle(AlertNotifier $notifier): int
    {
        $r = $notifier->run();
        $this->line(sprintf('hygiene:notify — %s · dus %d · envoyés %d · échecs %d', $r['status'], $r['due'], $r['sent'], $r['failed']));

        return $r['failed'] > 0 && $r['sent'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
