<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 3.3 · Codex P2-D]
 *
 * `DashboardController::dashboardFailure()` renvoyait `$exception->getMessage()` tel quel
 * dans une réponse 422. Pour une exception de base de données, ce message contient la
 * REQUÊTE SQL complète, le code SQLSTATE, le nom du pilote et souvent des chemins de
 * fichiers du serveur — servis à toute personne ayant la permission `dashboard`, et
 * affichés en clair par l'écran (le sélecteur de dates cassé les faisait apparaître à
 * chaque essai de période, cf. commit précédent).
 *
 * Le message public devient stable et français ; le détail part dans le journal serveur,
 * où il sert au diagnostic sans être exposé.
 */
class DashboardFailureDoesNotLeakInternalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    /** Le service est remplacé par un double qui échoue comme échouerait MySQL. */
    private function serviceQuiCasse(string $message): void
    {
        $double = $this->createMock(DashboardService::class);
        $double->method('orderStatistics')->willThrowException(new \RuntimeException($message));
        $this->app->instance(DashboardService::class, $double);
    }

    public function test_le_message_interne_n_est_pas_renvoye_au_navigateur(): void
    {
        $interne = "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'foodking.orders' "
            ."doesn't exist (SQL: select count(*) from `orders`) at /var/www/app/Services/DashboardService.php:118";
        $this->serviceQuiCasse($interne);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/dashboard/order-statistics');

        $r->assertStatus(422);

        $corps = json_encode($r->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('SQLSTATE', $corps);
        $this->assertStringNotContainsString('foodking.orders', $corps);
        $this->assertStringNotContainsString('/var/www/', $corps);
        $this->assertStringNotContainsString('select count(*)', $corps);
    }

    public function test_le_message_public_est_stable_et_en_francais(): void
    {
        $this->serviceQuiCasse('SQLSTATE[HY000] connection refused');

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/dashboard/order-statistics')
            ->assertStatus(422)
            ->json();

        $this->assertSame(trans('all.message.database_error_message'), $r['message'] ?? null);
        $this->assertFalse($r['status'] ?? true);
    }

    /**
     * Ne rien dire au navigateur ne doit pas vouloir dire ne rien savoir : le détail doit
     * rester consultable côté serveur, sinon on a juste déplacé la panne dans le noir.
     */
    public function test_le_detail_reste_journalise_cote_serveur(): void
    {
        Log::spy();
        $this->serviceQuiCasse('SQLSTATE[42S02] table manquante');

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/dashboard/order-statistics')
            ->assertStatus(422);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $contexte = []) => str_contains((string) $message, 'dashboard')
                && str_contains(json_encode($contexte), 'SQLSTATE[42S02]'))
            ->atLeast()->once();
    }

    /**
     * Les refus MÉTIER, eux, doivent rester lisibles : « la date de fin doit être
     * postérieure » n'est pas une fuite, c'est la seule information utile à l'opérateur.
     * Une correction de sécurité qui rendrait toutes les erreurs muettes serait pire que
     * le défaut qu'elle corrige.
     */
    public function test_les_refus_de_validation_restent_explicites(): void
    {
        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/dashboard/order-statistics?first_date=2026-03-31&last_date=2026-03-01')
            ->assertStatus(422)
            ->json();

        $this->assertStringContainsString(
            'date de fin',
            json_encode($r, JSON_UNESCAPED_UNICODE),
            "le refus métier doit rester compréhensible par l'opérateur"
        );
    }
}
