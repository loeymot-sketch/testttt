<?php

namespace App\Providers;

use App\Services\Uber\Vision\MockUberTicketVisionService;
use App\Services\Uber\Vision\OpenAiUberTicketVisionService;
use App\Services\Uber\Vision\UberTicketVisionContract;
use Illuminate\Support\ServiceProvider;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Choix du lecteur de ticket Uber, au démarrage.
 *
 *  - lecture RÉELLE ({@see OpenAiUberTicketVisionService}) si la vision est activée ET qu'une clé
 *    est présente ;
 *  - sinon la doublure locale ({@see MockUberTicketVisionService}) — DÉFAUT.
 *
 * Conséquence voulue : aucun appel réseau, aucune dépense, tant que l'owner n'a pas activé la
 * lecture. Le reste du parcours est identique dans les deux cas, donc entièrement testable.
 *
 * La clé est CELLE du lecteur de factures déjà en place (`services.openai`) : une seule clé à
 * fournir pour les deux usages. Un interrupteur dédié `UBER_VISION_ENABLED` permet malgré tout
 * d'activer l'un sans l'autre — lire un ticket client toutes les cinq minutes n'est pas le même
 * volume que lire une facture fournisseur de temps en temps.
 *
 * Isolé dans son propre provider pour ne PAS toucher AppServiceProvider, qui porte les
 * boot-guards NF525 (CLAUDE.md §8).
 */
class UberVisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UberTicketVisionContract::class, function ($app): UberTicketVisionContract {
            $key = (string) config('services.openai.key', '');
            $enabled = (bool) config('uber.vision_enabled', false);

            if ($enabled && $key !== '') {
                return $app->make(OpenAiUberTicketVisionService::class);
            }

            return $app->make(MockUberTicketVisionService::class);
        });
    }
}
