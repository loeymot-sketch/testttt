<?php

namespace App\Console\Commands;

use App\Services\Wheel\WheelClaimService;
use Illuminate\Console\Command;

/**
 * Inscrit dans les charges le coût des cadeaux de roue RÉELLEMENT consommés.
 *
 * Idempotente : la relancer n'écrit rien de plus. Rattrapante : si elle n'a pas tourné pendant
 * trois jours, elle rattrape les trois jours. C'est ce qui permet de la planifier sans crainte et
 * de la relancer à la main quand on doute.
 */
class WheelReconcileClaimsCommand extends Command
{
    protected $signature = 'wheel:reconcile-claims {--branch= : limiter à une branche}';

    protected $description = 'Roue : inscrit la charge des produits offerts effectivement consommés';

    public function handle(WheelClaimService $service): int
    {
        $branche = $this->option('branch');
        $r = $service->reconcile($branche !== null ? (int) $branche : null);

        $this->info(sprintf(
            'Roue — %d lot(s) examiné(s), %d charge(s) inscrite(s), %d ignoré(s) (pas encore consommés ou remise en %%).',
            $r['examines'], $r['inscrits'], $r['ignores']
        ));

        if (! empty($r['a_configurer'])) {
            // On le RÉPÈTE à chaque passage : un cadeau non chiffré est un trou dans les charges,
            // et un trou qu'on ne nomme pas se découvre à l'inventaire six mois plus tard.
            $this->warn('Segments SANS produit de référence de coût — leurs cadeaux ne sont pas chiffrés : '
                . implode(', ', $r['a_configurer']));
            $this->line('  Renseigne WHEEL_COST_ITEM_<SEGMENT> dans .env avec l\'identifiant du produit qui sert de référence.');
        }

        // Étapes demandées mais SANS adresse : le jeu tourne, mais il ne vérifie pas ce que
        // l'exploitant croit qu'il vérifie. On le répète à chaque passage.
        $manquantes = app(\App\Services\Wheel\WheelStepService::class)->missingLinks();
        if (! empty($manquantes)) {
            $this->warn('Etapes DEMANDEES mais SANS lien configure — elles sont SAUTEES : '
                . implode(', ', $manquantes));
            $this->line('  Renseigne WHEEL_REVIEW_URL / WHEEL_INSTAGRAM_URL / WHEEL_SNAPCHAT_URL.');
        }

        return self::SUCCESS;
    }
}
