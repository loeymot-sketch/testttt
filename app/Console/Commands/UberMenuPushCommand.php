<?php

namespace App\Console\Commands;

use App\Services\Uber\UberClient;
use App\Services\Uber\UberMenuBuilder;
use Illuminate\Console\Command;

/**
 * [UBER-BASIC-PROD 2026-08-02] Upload manuel du menu vers Uber Eats.
 * `--dry-run` affiche le payload sans l'envoyer (contrôle avant push).
 */
class UberMenuPushCommand extends Command
{
    protected $signature = 'uber:menu-push {--dry-run : Affiche le payload sans l\'envoyer}';

    protected $description = 'Construit le menu Uber Eats depuis la DB (SSOT) et le pousse via PUT /v2/eats/stores/{id}/menus';

    public function handle(UberMenuBuilder $builder, UberClient $client): int
    {
        $menu = $builder->build();
        $this->info(sprintf(
            'Menu construit : %d items, %d catégories.',
            count($menu['items']),
            count($menu['categories'])
        ));

        if (! (bool) config('uber.menu_managed', true) && ! $this->option('dry-run')) {
            $this->error('REFUSÉ : UBER_MENU_MANAGED=false — le menu Uber officiel est géré par le menu-maker (décision owner, Option A). Le push écraserait le menu dédié.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line(json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        if ($client->putMenu($menu)) {
            $this->info('Menu poussé vers Uber ✅');
            return self::SUCCESS;
        }

        $this->error('Échec du push menu (voir storage/logs — PUT non-2xx).');
        return self::FAILURE;
    }
}
