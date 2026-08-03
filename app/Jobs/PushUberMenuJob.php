<?php

namespace App\Jobs;

use App\Services\Uber\UberClient;
use App\Services\Uber\UberMenuBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-BASIC-PROD 2026-08-02] Pousse le menu complet vers Uber (PUT /menus v2).
 * Déclenché par : webhook store.menu_refresh_request (Uber demande un re-upload),
 * la commande artisan uber:menu-push, ou tout flux futur de synchro catalogue.
 * Best-effort : un échec est loggé (retries queue), jamais bloquant pour l'appelant.
 */
class PushUberMenuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function handle(UberMenuBuilder $builder, UberClient $client): void
    {
        if ((string) config('uber.client_id') === '' || (string) config('uber.store_id') === '') {
            Log::info('[Uber] menu push sauté — intégration non configurée.');
            return;
        }
        if (! (bool) config('uber.menu_managed', true)) {
            // [OWNER OPTION A] Menu Uber = menu-maker officiel, la caisse ne l'écrase jamais.
            Log::info('[Uber] menu push REFUSÉ — UBER_MENU_MANAGED=false (menu Uber géré par le menu-maker, décision owner).');
            return;
        }
        $ok = $builder->push($client);
        if (! $ok) {
            Log::warning('[Uber] menu push échec (voir logs PUT non-2xx).');
            $this->release(60); // retry via la queue
            return;
        }
        Log::info('[Uber] menu push OK.');
    }
}
