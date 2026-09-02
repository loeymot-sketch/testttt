<?php

namespace Tests\Feature\Observability;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 5.1 · Codex P1-J]
 *
 * Depuis la migration 2026_08_04 (`broadcast_at`), le job pose `dispatched_at` au CLAIM
 * (avant la diffusion) et `broadcast_at` seulement quand la diffusion a réussi
 * (`DispatchDomainEventsJob.php:74-78` puis `:151-154`). `OutboxRescueCommand` lit déjà
 * `broadcast_at IS NULL` (crash-claimed, seuil 10 min). Le cockpit, lui, comptait encore
 * sur `dispatched_at` : un worker tué entre claim et broadcast faisait DISPARAÎTRE l'événement
 * des « en attente », le COMPTAIT parmi les livrés et affichait queue/websocket UP.
 *
 * Mesuré sur la base servie le 2026-09-02 : 2 149 lignes claimées sans diffusion, invisibles.
 *
 * Ces tests fixent la sémantique de LIVRAISON : pending = jamais claimé ; in_flight = claimé
 * depuis < 10 min sans diffusion ; stale_claimed = claimé depuis ≥ 10 min sans diffusion
 * (même seuil que rescue) ; delivered_24h = `broadcast_at` ; terminal_failures = `last_error`
 * non nul sans claim. Les sondes ne prennent JAMAIS un claim pour un signal positif.
 */
class OutboxDeliverySemanticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::forget('ws:heartbeat');
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => 0]);
        $u->assignRole('Admin');

        return $u;
    }

    private function event(array $overrides): void
    {
        DB::table('domain_events')->insert(array_merge([
            'event_type' => 'OrderUpdated',
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'branch_id' => 1,
            'payload' => json_encode([]),
            'channel' => 'orders',
            'broadcast_as' => 'order.updated',
            'correlation_id' => null,
            'occurred_at' => now()->subMinutes(20),
            'dispatched_at' => null,
            'broadcast_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ], $overrides));
    }

    private function overview(): array
    {
        return $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/admin/observability/outbox')
            ->assertOk()
            ->json();
    }

    public function test_un_claim_sans_diffusion_n_est_ni_en_attente_ni_livre(): void
    {
        // Worker tué entre Phase 1 (claim) et Phase 2 (broadcast), il y a 15 min.
        $this->event(['aggregate_id' => 1, 'dispatched_at' => now()->subMinutes(15), 'broadcast_at' => null]);

        $r = $this->overview();

        $this->assertSame(0, $r['pending']['count'], 'un claim n\'est pas « en attente »');
        $this->assertSame(0, $r['delivered_24h']['count'], 'un claim sans broadcast n\'est PAS une livraison');
        $this->assertSame(1, $r['stale_claimed']['count'], 'claim ≥ 10 min sans diffusion = orphelin');
        $this->assertSame(0, $r['in_flight']['count']);
        $this->assertSame(1, $r['stale_claimed']['rows'][0]['aggregate_id']);
    }

    public function test_un_claim_recent_est_en_vol_pas_orphelin(): void
    {
        $this->event(['aggregate_id' => 2, 'dispatched_at' => now()->subMinute(), 'broadcast_at' => null]);

        $r = $this->overview();

        $this->assertSame(1, $r['in_flight']['count']);
        $this->assertSame(0, $r['stale_claimed']['count']);
        $this->assertSame(0, $r['delivered_24h']['count']);
    }

    public function test_seule_une_diffusion_reelle_compte_dans_les_livres_24h(): void
    {
        $this->event(['aggregate_id' => 3, 'dispatched_at' => now()->subMinutes(2), 'broadcast_at' => now()->subMinutes(2)]);
        $this->event(['aggregate_id' => 4, 'dispatched_at' => now()->subHours(30), 'broadcast_at' => now()->subHours(30)]);

        $r = $this->overview();

        $this->assertSame(1, $r['delivered_24h']['count'], 'la livraison de 30 h sort de la fenêtre 24 h');
        $this->assertSame(0, $r['in_flight']['count']);
        $this->assertSame(0, $r['stale_claimed']['count']);
    }

    public function test_les_echecs_terminaux_sont_comptes_a_part(): void
    {
        $this->event(['aggregate_id' => 5, 'attempts' => 6, 'last_error' => 'broker unreachable']);
        $this->event(['aggregate_id' => 6, 'attempts' => 1, 'last_error' => 'contract_violation: bad payload']);
        $this->event(['aggregate_id' => 7]); // en attente saine

        $r = $this->overview();

        $this->assertSame(3, $r['pending']['count']);
        $this->assertSame(2, $r['terminal_failures']['count']);
        $this->assertSame(1, $r['terminal_failures']['contract_violations']);
    }

    public function test_les_sondes_ne_prennent_pas_un_claim_pour_un_signal_positif(): void
    {
        // Rien d'autre qu'un claim tout frais (30 s) jamais diffusé, aucun heartbeat.
        $this->event(['aggregate_id' => 8, 'dispatched_at' => now()->subSeconds(30), 'broadcast_at' => null]);

        $r = $this->overview();

        $this->assertSame('down', $r['health']['queue_work']['status'], 'un claim n\'est pas la preuve qu\'un worker vit');
        $this->assertSame('down', $r['health']['websockets_serve']['status'], 'un claim n\'est pas la preuve qu\'un broadcast a réussi');
    }

    public function test_une_diffusion_recente_est_un_signal_positif(): void
    {
        $this->event(['aggregate_id' => 9, 'dispatched_at' => now()->subSeconds(30), 'broadcast_at' => now()->subSeconds(25)]);

        $r = $this->overview();

        $this->assertSame('up', $r['health']['queue_work']['status']);
        $this->assertSame('up', $r['health']['websockets_serve']['status']);
    }
}
