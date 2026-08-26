<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-5.3.1]
 *
 * `foodking:ensure-admin` crée ou RÉINITIALISE un compte administrateur, avec un mot de passe
 * dont la valeur par défaut est `123456` (voir la signature de la commande). Elle n'avait
 * AUCUNE garde de production : `grep` sur `environment|isProduction|APP_ENV|confirmToProceed`
 * dans le fichier ne retournait rien le 2026-08-25.
 *
 * Conséquence concrète : sur une machine en production, un `php artisan foodking:ensure-admin`
 * lancé par réflexe — ou par un script de déploiement hérité — pose un administrateur joignable
 * avec un mot de passe connu de tous. C'est une élévation de privilège en une commande.
 *
 * Le reste du projet applique déjà ce motif : `AppServiceProvider` REFUSE DE DÉMARRER en
 * production sur `POS_SIMULATION_HARDWARE`, `APP_DEBUG`, `IDEMPOTENCY_MIDDLEWARE_ENABLED`,
 * `CACHE_DRIVER` (voir CLAUDE.md §8). Cette commande était le trou dans cette rangée.
 *
 * Comportement attendu :
 *   - hors production : inchangé, la commande passe sans friction (le dev ne doit pas ralentir) ;
 *   - en production sans `--force` : refus explicite, code de sortie non nul, AUCUNE écriture ;
 *   - en production avec `--force` : autorisée, car l'intention est alors explicite et tracée.
 */
class EnsureAdminGardeProductionTest extends TestCase
{
    use RefreshDatabase;

    private function forcerProduction(): void
    {
        app()->detectEnvironment(fn () => 'production');
    }

    private function forcerTesting(): void
    {
        app()->detectEnvironment(fn () => 'testing');
    }

    public function test_la_commande_refuse_de_tourner_en_production_sans_force(): void
    {
        $this->forcerProduction();
        $avant = User::withoutGlobalScopes()->count();

        $code = $this->artisan('foodking:ensure-admin', ['--email' => 'garde@lecayenne.fr'])
            ->assertFailed()
            ->run();

        $this->assertNotSame(0, $code, 'La commande doit sortir en échec en production.');
        $this->assertSame(
            $avant,
            User::withoutGlobalScopes()->count(),
            'Aucun utilisateur ne doit être créé quand la garde refuse.',
        );
        $this->assertNull(
            User::withoutGlobalScopes()->where('email', 'garde@lecayenne.fr')->first(),
            'Le compte visé ne doit pas exister après un refus.',
        );
    }

    public function test_le_refus_nomme_le_risque_plutot_que_de_sortir_en_silence(): void
    {
        $this->forcerProduction();

        $this->artisan('foodking:ensure-admin')
            ->expectsOutputToContain('production')
            ->assertFailed();
    }

    public function test_la_commande_reste_utilisable_hors_production(): void
    {
        $this->forcerTesting();

        $this->artisan('foodking:ensure-admin', [
            '--email'   => 'admin@lecayenne.fr',
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    public function test_force_leve_la_garde_car_l_intention_devient_explicite(): void
    {
        $this->forcerProduction();

        $this->artisan('foodking:ensure-admin', [
            '--email'   => 'admin@lecayenne.fr',
            '--dry-run' => true,
            '--force'   => true,
        ])->assertSuccessful();
    }

    public function test_dry_run_seul_ne_suffit_pas_a_lever_la_garde(): void
    {
        // Un `--dry-run` n'écrit rien, mais l'autoriser en production entretiendrait l'habitude
        // de lancer cette commande sur la machine qui sert. La garde reste devant.
        $this->forcerProduction();

        $this->artisan('foodking:ensure-admin', ['--dry-run' => true])
            ->assertFailed();
    }
}
