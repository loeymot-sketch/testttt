<?php

namespace App\Console\Commands;

use App\Services\Menu\EtapesBloquantesDetector;
use Illuminate\Console\Command;

/**
 * [INCIDENT CAISSE 2026-09-03] À lancer APRÈS toute modification de la carte.
 *
 * Le 2026-09-03 à 22:27:08, une opération a éteint les 45 lignes de viande de
 * Cayenne / Suprême / Sandwich Classique en laissant l'étape « Viande 1 » obligatoire.
 * Les trois produits phares sont devenus invendables et personne ne l'a su avant le
 * service. Cette commande répond en deux secondes à la seule question qui compte :
 * « un client peut-il encore commander chaque produit de la carte ? »
 *
 * Sort en code 1 si un produit est bloqué : utilisable comme porte dans un script.
 */
class MenuVerifierEtapesCommand extends Command
{
    protected $signature = 'menu:verifier-etapes
                            {--surface=* : Surfaces à contrôler (kiosk, pos, web). Toutes par défaut.}';

    protected $description = 'Signale les produits invendables : une étape obligatoire sans choix satisfaisant.';

    public function handle(EtapesBloquantesDetector $detecteur): int
    {
        $surfaces = $this->option('surface') ?: EtapesBloquantesDetector::SURFACES;

        $inconnues = array_diff($surfaces, EtapesBloquantesDetector::SURFACES);
        if ($inconnues !== []) {
            $this->error('Surface inconnue : '.implode(', ', $inconnues)
                .'. Attendu : '.implode(', ', EtapesBloquantesDetector::SURFACES).'.');

            return self::INVALID;
        }

        $total = 0;

        foreach ($surfaces as $surface) {
            $constats = $detecteur->detecter($surface);

            if ($constats === []) {
                $this->line("  <fg=green>OK</> {$surface} — tous les produits de la carte restent commandables.");

                continue;
            }

            $total += count($constats);
            $this->newLine();
            $this->line("  <fg=red>BLOQUÉ</> {$surface} — ".count($constats).' produit(s) invendable(s) :');

            $this->table(
                ['Produit', 'Étape', 'Pourquoi', 'Choix', 'Exigé'],
                array_map(fn (array $c) => [
                    $c['produit'],
                    $c['etape'],
                    $this->explication($c['raison'], $surface),
                    $c['choix_disponibles'],
                    $c['minimum_exige'],
                ], $constats)
            );
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('Carte saine : aucune étape obligatoire ne se retrouve sans choix.');

            return self::SUCCESS;
        }

        $this->error("{$total} blocage(s). Un client ne peut pas terminer sa commande sur ces produits.");
        $this->line('Remède : rallumer des choix, retirer l\'obligation, ou détacher l\'étape du produit.');

        return self::FAILURE;
    }

    private function explication(string $raison, string $surface): string
    {
        return match ($raison) {
            'tous_les_choix_eteints'      => 'tous les choix sont éteints',
            'reserve_a_une_autre_surface' => "tous les choix sont réservés à une autre surface que « {$surface} »",
            'choix_insuffisants'          => 'moins de choix disponibles que le minimum exigé',
            default                       => $raison,
        };
    }
}
