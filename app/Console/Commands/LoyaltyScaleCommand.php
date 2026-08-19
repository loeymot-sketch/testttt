<?php

namespace App\Console\Commands;

use App\Services\Loyalty\LoyaltyRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;

/**
 * LE BARÈME DE FIDÉLITÉ, AVEC SES CONSÉQUENCES ÉCRITES NOIR SUR BLANC.
 *
 * ── POURQUOI CETTE COMMANDE EXISTE ───────────────────────────────────────────────────────────
 * Le barème se règle par trois nombres qui ne se lisent pas seuls. « 10 points par euro » et
 * « 100 points = 1 € » sonnent tous deux raisonnables ; multipliés, ils donnent **10 % du chiffre
 * d'affaires rendu au client** — un taux très élevé pour de la restauration rapide. Et « minimum
 * 1000 points » sonne modeste jusqu'à ce qu'on le divise par le panier moyen : **13 visites**
 * avant la première récompense.
 *
 * Mesuré sur la base réelle le 2026-08-19 : 10 % de retour, 12,9 visites avant la première
 * récompense, **0 client sur 156** en mesure de dépenser quoi que ce soit. Le pire des deux
 * mondes — un programme cher sur le papier et invisible en salle.
 *
 * Aucun écran n'affichait cette arithmétique. Cette commande la met sous les yeux avant tout
 * changement, et refuse d'en appliquer un sans dire ce qu'il coûte.
 *
 * ── LA PRÉCAUTION QUI COMPTE ─────────────────────────────────────────────────────────────────
 * Changer le TAUX (points pour 1 €) modifie ce que valent les points DÉJÀ ACQUIS. Doubler le
 * taux, c'est diviser par deux la valeur du solde de chaque client, sans que personne ne le lui
 * dise. La commande l'annonce et demande confirmation. Le PLANCHER, lui, ne dévalue rien : il
 * décide seulement à partir de quand on peut s'en servir.
 */
class LoyaltyScaleCommand extends Command
{
    protected $signature = 'fidelite:bareme
                            {--gain= : Points gagnés par euro dépensé}
                            {--taux= : Points nécessaires pour 1 € de remise}
                            {--plancher= : Minimum de points utilisable}
                            {--force : Appliquer sans confirmation interactive}';

    protected $description = 'Affiche le barème de fidélité et ses conséquences réelles ; applique un nouveau réglage si demandé.';

    public function handle(): int
    {
        $regles = app(LoyaltyRules::class);

        $gainActuel = $regles->pointsPerEuro();
        $tauxActuel = $regles->rate();
        $plancherActuel = $regles->floorSetting();

        $this->info('══ BARÈME DE FIDÉLITÉ ══');
        $this->afficher('ACTUEL', $gainActuel, $tauxActuel, $regles->effectiveFloor());

        $gain = $this->option('gain') !== null ? max(1, (int) $this->option('gain')) : null;
        $taux = $this->option('taux') !== null ? max(1, (int) $this->option('taux')) : null;
        $plancher = $this->option('plancher') !== null ? max(0, (int) $this->option('plancher')) : null;

        if ($gain === null && $taux === null && $plancher === null) {
            $this->newLine();
            $this->recommandation($gainActuel, $tauxActuel);

            return self::SUCCESS;
        }

        $nouveauGain = $gain ?? $gainActuel;
        $nouveauTaux = $taux ?? $tauxActuel;
        $nouveauPlancher = $plancher ?? $plancherActuel;

        // Le plancher EFFECTIF est le premier multiple du taux ≥ réglage : c'est lui qu'on
        // oppose au client, donc lui qu'on doit montrer (leçon du 2026-08-05).
        $plancherEffectif = (int) (max(1, (int) ceil($nouveauPlancher / $nouveauTaux)) * $nouveauTaux);

        $this->newLine();
        $this->afficher('DEMANDÉ', $nouveauGain, $nouveauTaux, $plancherEffectif);

        if ($nouveauTaux !== $tauxActuel) {
            $soldes = (int) DB::table('users')->whereNotNull('loyalty_code')->sum('loyalty_points');
            $avant = round($soldes / $tauxActuel, 2);
            $apres = round($soldes / $nouveauTaux, 2);

            $this->newLine();
            $this->warn('⚠ CE CHANGEMENT TOUCHE LES POINTS DÉJÀ ACQUIS.');
            $this->line(sprintf(
                '   %d points sont détenus par vos clients. Ils valent %s € aujourd\'hui, ils vaudraient %s € après.',
                $soldes,
                number_format($avant, 2, ',', ' '),
                number_format($apres, 2, ',', ' ')
            ));
            $this->line($apres < $avant
                ? '   C\'est une DÉVALUATION du solde de chaque client. Personne ne le lui dira à sa place.'
                : '   C\'est une revalorisation : chaque client gagne du pouvoir d\'achat sans rien faire.');
        }

        if (! $this->option('force') && ! $this->confirm('Appliquer ce barème ?', false)) {
            $this->line('Abandonné — rien n\'a été modifié.');

            return self::SUCCESS;
        }

        Settings::group('loyalty_setup')->set([
            'loyalty_points_per_euro' => $nouveauGain,
            'loyalty_points_for_1_euro_discount' => $nouveauTaux,
            'loyalty_min_redeem_points' => $nouveauPlancher,
        ]);

        $this->newLine();
        $this->info('✔ Barème appliqué.');
        $this->line('   Vérifiez l\'effet en salle : php artisan fidelite:verifier');

        return self::SUCCESS;
    }

    private function afficher(string $titre, int $gain, int $taux, int $plancherEffectif): void
    {
        $retour = ($gain / $taux) * 100;
        $panier = (float) DB::table('orders')
            ->where('source_surface', 'pos')
            ->whereIn('status', [8, 13])
            ->avg('total');
        $panier = $panier > 0 ? $panier : 10.0;

        $euroPourPremiereRecompense = $plancherEffectif / max(1, $gain);
        $recompense = $plancherEffectif / max(1, $taux);

        $this->line("── {$titre} ──");
        $this->line(sprintf('   %d point(s) par euro · %d points = 1 € · utilisable dès %d points', $gain, $taux, $plancherEffectif));
        $this->line(sprintf('   → retour client : %s %% du chiffre d\'affaires', number_format($retour, 1, ',', ' ')));
        $this->line(sprintf(
            '   → première récompense : %s € offerts après %s € d\'achats, soit ~%s visites (panier moyen %s €)',
            number_format($recompense, 2, ',', ' '),
            number_format($euroPourPremiereRecompense, 2, ',', ' '),
            number_format($euroPourPremiereRecompense / $panier, 1, ',', ' '),
            number_format($panier, 2, ',', ' ')
        ));

        $ca = (float) DB::table('orders')
            ->where('source_surface', 'pos')
            ->whereIn('status', [8, 13])
            ->sum('total');
        if ($ca > 0) {
            $this->line(sprintf(
                '   → coût sur le chiffre d\'affaires caisse déjà réalisé (%s €) : %s €',
                number_format($ca, 2, ',', ' '),
                number_format($ca * ($gain / $taux), 2, ',', ' ')
            ));
        }
    }

    /**
     * CE QUE JE RECOMMANDE, ET POURQUOI — sans le décider à la place de l'exploitant.
     *
     * Deux leviers, deux natures. Le PLANCHER ne coûte rien à personne : il décide seulement
     * quand le client peut se servir. Le TAUX, lui, arbitre la marge du restaurant contre le
     * pouvoir d'achat du client, ET dévalue rétroactivement les soldes existants s'il monte.
     * Le premier se règle sans hésiter ; le second appartient à celui dont c'est l'argent.
     */
    private function recommandation(int $gain, int $taux): void
    {
        $retour = ($gain / $taux) * 100;

        $this->info('── Ce que je recommande ──');

        if ($retour >= 8.0) {
            $this->line(sprintf(
                '   1. LE TAUX. %s %% de retour est très élevé pour de la restauration rapide (usage : 2 à 5 %%).',
                number_format($retour, 1, ',', ' ')
            ));
            $this->line(sprintf(
                '      Pour viser 5 %% sans toucher au gain affiché : --taux=%d.',
                (int) round($gain / 0.05)
            ));
            $this->line('      ⚠ Ce choix DÉVALUE les points déjà acquis — c\'est votre argent contre celui du client,');
            $this->line('        je ne le tranche pas à votre place. La commande vous montrera l\'impact exact.');
        }

        $this->line('   2. LE PLANCHER. C\'est lui qui rend le programme visible, et il ne dévalue rien.');
        $this->line(sprintf(
            '      Une première récompense à ~3 visites donne : --plancher=%d.',
            (int) (round(3 * 8 * $gain / 50) * 50)
        ));
        $this->line('      Un client qui voit une récompense arriver revient ; un client qui doit dépenser');
        $this->line('      100 € avant de voir quoi que ce soit ne revient pas — il ne sait même pas qu\'il y a un programme.');
        $this->newLine();
        $this->line('   Exemple concret, à copier tel quel :');
        $this->line(sprintf('      php artisan fidelite:bareme --plancher=%d', (int) (round(3 * 8 * $gain / 50) * 50)));
    }
}
