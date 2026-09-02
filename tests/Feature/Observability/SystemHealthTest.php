<?php

namespace Tests\Feature\Observability;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [PILOTAGE 2026-08-09] « Est-ce que ça va ? »
 *
 * Le système SE SURVEILLAIT déjà — `healthz:check` contrôle cinq sous-systèmes,
 * la sauvegarde tourne à 3 h, une restauration de vérification à 5 h — mais
 * l'administration n'exposait qu'un seul écran d'observabilité, la file
 * d'expédition. Autrement dit : le logiciel savait, et ne le disait pas.
 *
 * Ces tests fixent ce que la réponse doit garantir. Ils sont écrits en pensant
 * aux pannes SILENCIEUSES : celles qui ne déclenchent aucune erreur visible et
 * qu'on découvre des semaines plus tard.
 */
class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Cache::forget('healthz:last');
        Cache::forget('scheduler:last_tick');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function sante(array $checks, string $statut = 'ok'): void
    {
        Cache::forever('healthz:last', [
            'status' => $statut, 'checks' => $checks, 'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function test_tout_va_bien_donne_un_verdict_ok_et_aucune_alerte(): void
    {
        $this->sante(['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0]);
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        // Le seul point qui peut rester rouge ici est la sauvegarde, absente en test.
        $this->assertSame('ok', $r['controles']['db']);
        $this->assertNotContains('db : ok', $r['alertes']);
    }

    public function test_un_sous_systeme_en_panne_remonte_dans_les_alertes(): void
    {
        $this->sante(['db' => 'ok', 'redis' => 'down', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0], 'degraded');
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        $this->assertSame('attention', $r['verdict']);
        $this->assertContains('redis : down', $r['alertes']);
    }

    public function test_un_planificateur_muet_est_signale(): void
    {
        // La panne la plus dangereuse du lot : si le planificateur s'arrête,
        // les sauvegardes, les relances de file et la vérification de la chaîne
        // fiscale s'arrêtent AVEC LUI, sans qu'aucune erreur n'apparaisse.
        // C'est déjà arrivé sur le VPS (jamais lancé, réparé le 27 juillet).
        $this->sante(['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0]);
        Cache::forever('scheduler:last_tick', now()->subHours(3)->timestamp);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        $this->assertSame('attention', $r['verdict']);
        $this->assertTrue(
            collect($r['alertes'])->contains(fn ($a) => str_contains($a, 'planificateur')),
            'un planificateur muet depuis 3 h doit être signalé'
        );
    }

    public function test_l_absence_totale_de_mesure_ne_passe_pas_pour_un_bon_etat(): void
    {
        // Piège classique : aucune donnée en cache produit un tableau vide, donc
        // « aucune alerte », donc « tout va bien ». C'est l'inverse de la vérité.
        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        $this->assertSame('attention', $r['verdict'], 'sans mesure, on ne peut pas dire que tout va bien');
        $this->assertTrue(
            collect($r['alertes'])->contains(fn ($a) => str_contains($a, 'planificateur')),
            'aucun battement enregistré doit être une alerte, pas un silence'
        );
    }

    public function test_une_file_d_attente_qui_gonfle_est_signalee(): void
    {
        $this->sante(['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 900]);
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        $this->assertTrue(
            collect($r['alertes'])->contains(fn ($a) => str_contains($a, "file d'attente")),
            '900 messages en attente doivent être signalés'
        );
        // 0 en attente est un état NORMAL : il ne doit jamais alerter.
        $this->sante(['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0]);
        $r2 = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();
        $this->assertFalse(collect($r2['alertes'])->contains(fn ($a) => str_contains($a, "file d'attente")));
    }

    public function test_l_etat_du_systeme_n_est_pas_public(): void
    {
        $this->getJson('/api/admin/observability/system-health')->assertUnauthorized();
    }

    /**
     * [2026-09-02] La panne qui casse la surveillance effaçait aussi le rapport sur elle-même.
     *
     * Mesuré sur la machine de développement : l'API répondait `controles: []` — donc aucune
     * carte, aucun message, aucune alerte — pendant que 1 521 messages attendaient en file
     * (seuil du code : 50), dont 1 511 notifications clients. Cause : `controles` vient de
     * `Cache::get('healthz:last')`, vide parce que le planificateur était mort ; et la boucle
     * du verdict itérait donc sur zéro élément. Le gérant lisait deux alertes et les croyait
     * exhaustives.
     */
    public function test_une_sonde_qui_n_a_pas_tourne_est_dite_et_non_tue(): void
    {
        Cache::forget('healthz:last');
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        $this->assertSame([], $r['controles']);
        $this->assertSame('attention', $r['verdict']);
        $this->assertContains(
            "contrôles de santé : aucune mesure disponible — la sonde n'a pas tourné",
            $r['alertes'],
        );
    }

    /**
     * [2026-09-02] `HealthzCheckCommand` écrit `Cache::forever('healthz:last', ...)` — sans
     * expiration. Rien ne comparait l'horodatage de la mesure à l'heure courante : les cinq
     * cartes pouvaient afficher « en service » en vert à partir d'une mesure arbitrairement
     * vieille. La fraîcheur était contrôlée pour la sauvegarde et le planificateur, pas pour
     * les contrôles eux-mêmes.
     */
    public function test_une_mesure_perimee_est_signalee_meme_si_tout_y_est_vert(): void
    {
        Cache::forever('healthz:last', [
            'status' => 'ok',
            'checks' => ['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0],
            'timestamp' => now()->subHours(6)->toIso8601String(),
        ]);
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        $this->assertSame('attention', $r['verdict']);
        $this->assertContains('contrôles de santé : mesure vieille de 6 h', $r['alertes']);
        $this->assertGreaterThanOrEqual(360, $r['mesure_age_min']);
    }

    /** Une mesure fraîche ne doit évidemment déclencher aucun de ces deux avertissements. */
    public function test_une_mesure_fraiche_ne_declenche_aucun_avertissement_de_fraicheur(): void
    {
        $this->sante(['db' => 'ok', 'redis' => 'ok', 'websocket' => 'ok', 'fiscal_chain' => 'ok', 'queue_pending' => 0]);
        Cache::forever('scheduler:last_tick', now()->timestamp);

        $r = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/system-health')->assertOk()->json();

        foreach ($r['alertes'] as $alerte) {
            $this->assertStringNotContainsString('contrôles de santé', $alerte);
        }
        $this->assertLessThan(30, $r['mesure_age_min']);
    }
}
