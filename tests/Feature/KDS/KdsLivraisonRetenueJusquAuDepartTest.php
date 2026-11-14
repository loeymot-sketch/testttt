<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Printer;
use App\Models\User;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Services\KdsSyncService;
use App\Services\Kitchen\KitchenTicketAutoPrinter;
use App\Services\KitchenDisplaySystemOrderService;
use App\Services\WaitEstimateService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M2-101 / M1-103] Une LIVRAISON a son heure réelle dans `delivery_slot_at`
 * (départ de la tournée, DeliveryWaveService) — le site envoie `delivery_time = 'ASAP'` et
 * `scheduled_at = NULL` même pour une livraison du lendemain.
 *
 * Constat (commande 8100) : créée à 00:01 pour un départ à 20:30, elle était au board cuisine,
 * comptée dans l'attente des autres clients et imprimée dès sa création.
 *
 * Règle verrouillée : retenue jusqu'à départ − (délai cuisine 20 + une tournée 30) = 50 min ;
 * mêmes décisions sur TOUS les consommateurs (board, delta sync, bandeau, bump,
 * estimation d'attente, ancrage du chrono, pont d'impression, impression serveur).
 */
class KdsLivraisonRetenueJusquAuDepartTest extends TestCase
{
    use RefreshDatabase;

    private int $envois = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config([
            'kds.scheduled_lead_minutes' => 20,
            'delivery.wave_minutes'      => 30,
            'kds.counter_auto_print'     => true,
            'printing.bypass.enabled'    => false,
        ]);
        $this->envois = 0;
        $this->app->instance(PrinterTransportInterface::class, new class($this->envois) implements PrinterTransportInterface {
            public function __construct(private int &$compteur) {}

            public function send(string $bytes, array $config): bool
            {
                $this->compteur++;

                return true;
            }

            public function lastError(): ?string { return null; }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function horloge(string $paris): void
    {
        $now = CarbonImmutable::parse($paris, 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    private function branche(): Branch
    {
        return Branch::query()->find(1) ?? Branch::factory()->create(['id' => 1]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }

    /** Livraison payée, acceptée, comme la crée le site (scheduled_at NULL, delivery_time ASAP). */
    private function livraison(?string $depart, array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'        => $this->branche()->id,
            'source_surface'   => 'delivery',
            'order_type'       => OrderType::DELIVERY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'payment_method'   => PaymentGateway::CARD,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at'     => null,
            'delivery_time'    => 'ASAP',
            'delivery_slot_at' => $depart ? Carbon::parse($depart, 'Europe/Paris') : null,
            'queue_number'     => 'A' . random_int(100, 999),
            'kitchen_ticket_printed_at' => null,
        ], $overrides));
    }

    private function boardIds(): array
    {
        return app(KitchenDisplaySystemOrderService::class)
            ->list(new Request(['branch_id' => $this->branche()->id]))
            ->pluck('id')->all();
    }

    private function syncIds(): array
    {
        Cache::flush();

        return array_map(
            static fn ($o) => (int) ($o['id'] ?? 0),
            app(KdsSyncService::class)->sync($this->branche()->id, new DateTimeImmutable('2000-01-01T00:00:00'), true)['orders']
        );
    }

    private function bandeau(): array
    {
        return collect(app(KitchenDisplaySystemOrderService::class)->upcomingScheduled())->keyBy('id')->all();
    }

    private function fileImpression(): array
    {
        $caissier = User::factory()->create(['branch_id' => $this->branche()->id]);
        $caissier->assignRole('POS Operator');
        $res = $this->actingAs($caissier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending', ['destination' => 'kitchen']);
        $res->assertOk();

        return collect($res->json('orders'))->pluck('id')->all();
    }

    /** @test */
    public function livraison_du_soir_creee_a_minuit_est_retenue_partout_puis_liberee_a_depart_moins_50_min(): void
    {
        $this->horloge('2026-03-10 00:01:00');
        $this->actingAs($this->admin());
        $livraison = $this->livraison('2026-03-10 20:30:00');

        // À la création : ni board, ni delta sync, mais annoncée au bandeau. (Le mur client exclut
        // les livraisons par sa liste blanche de types : rien à y vérifier.)
        $this->assertNotContains($livraison->id, $this->boardIds(), 'Board : une livraison qui part à 20:30 ne doit pas être en cuisine à 00:01.');
        $this->assertNotContains($livraison->id, $this->syncIds(), 'Delta sync : même décision que le board.');
        $bandeau = $this->bandeau();
        $this->assertArrayHasKey($livraison->id, $bandeau, 'Bandeau « à venir » : la livraison retenue y est annoncée (complément exact).');
        $this->assertSame(
            Carbon::parse('2026-03-10 20:30:00', 'Europe/Paris')->toIso8601String(),
            $bandeau[$livraison->id]['scheduled_at'],
            'Le bandeau porte l\'heure de départ (jamais une ligne sans heure).'
        );
        $this->assertSame(0, app(WaitEstimateService::class)->estimate($this->branche()->id)['queue_count'],
            'Estimation d\'attente : une livraison de ce soir ne compte pas dans la file des autres clients.');

        // 19:39 : encore retenue (départ 20:30 − 50 min = 19:40).
        $this->horloge('2026-03-10 19:39:00');
        $this->assertNotContains($livraison->id, $this->boardIds(), 'À 19:39 la livraison de 20:30 est encore retenue.');

        // 19:40 : libérée — malgré une création 19 h 39 plus tôt (fenêtre glissante legacy 8 h).
        $this->horloge('2026-03-10 19:40:00');
        $this->assertContains($livraison->id, $this->boardIds(), 'À départ − 50 min la livraison DOIT entrer en cuisine, même créée à 00:01.');
        $this->assertContains($livraison->id, $this->syncIds(), 'Delta sync : parité avec le board à la libération.');
        $this->assertArrayNotHasKey($livraison->id, $this->bandeau(), 'Libérée, elle quitte le bandeau.');

        // Chrono de la carte ancré à la libération (pas un « en retard » de 19 h 39).
        $payload = (new KDSOrderDetailsResource($livraison->fresh()))->toArray(new Request());
        $this->assertSame(
            Carbon::parse('2026-03-10 19:40:00', 'Europe/Paris')->toIso8601String(),
            $payload['kitchen_timer_anchor_iso'],
            'Le chrono cuisine d\'une livraison retenue part de sa libération.'
        );
    }

    /** @test */
    public function bump_d_une_livraison_retenue_refuse_422(): void
    {
        $this->horloge('2026-03-10 12:00:00');
        $livraison = $this->livraison('2026-03-10 20:30:00');
        $chef = User::factory()->create(['branch_id' => $this->branche()->id]);
        $chef->assignRole('Chef');

        $res = $this->actingAs($chef, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/kds-order/change-status/' . $livraison->id, [
                'status'          => OrderStatus::PREPARING,
                'expected_status' => OrderStatus::ACCEPT,
            ]);

        $this->assertSame(422, $res->status(), 'Invisible au board ⟹ non bumpable (jumeau du filtre).');
        $this->assertSame(OrderStatus::ACCEPT, (int) $livraison->fresh()->status);
    }

    /** @test */
    public function livraison_au_plus_tot_entre_en_cuisine_tout_de_suite_comme_avant(): void
    {
        $this->horloge('2026-03-10 19:00:00');
        $this->actingAs($this->admin());
        // Au plus tôt : départ = maintenant + 20 min arrondi au cran suivant → 19:30 ; borne 19:50 incluse.
        $auPlusTot = $this->livraison('2026-03-10 19:30:00');
        $borne = $this->livraison('2026-03-10 19:50:00');
        $sansTournee = $this->livraison(null);

        $ids = $this->boardIds();
        $this->assertContains($auPlusTot->id, $ids, 'Une livraison au plus tôt n\'est pas retenue.');
        $this->assertContains($borne->id, $ids, 'Départ à now + 50 min : libérée (borne incluse).');
        $this->assertContains($sansTournee->id, $ids, 'Sans tournée (delivery_slot_at NULL) : comportement historique.');
        $this->assertNull((new KDSOrderDetailsResource($auPlusTot->fresh()))->toArray(new Request())['kitchen_timer_anchor_iso'],
            'Livraison jamais retenue : le chrono reste sur created_at (ancre null), inchangé.');
    }

    /** @test */
    public function un_depart_recule_d_un_cran_ne_fait_pas_disparaitre_une_livraison_deja_en_cuisine(): void
    {
        // Regroupement tardif (docs/LIVRAISON.md §2) : le départ de la 1ʳᵉ recule de 30 min.
        $this->horloge('2026-03-10 19:00:00');
        $this->actingAs($this->admin());
        $premiere = $this->livraison('2026-03-10 19:30:00');
        $this->assertContains($premiere->id, $this->boardIds());

        $this->horloge('2026-03-10 19:15:00');
        $premiere->forceFill(['delivery_slot_at' => Carbon::parse('2026-03-10 20:00:00', 'Europe/Paris')])->saveQuietly();
        $this->assertContains($premiere->id, $this->boardIds(),
            'Une commande déjà entrée en cuisine ne doit pas en ressortir parce que sa tournée a reculé d\'un cran.');
    }

    /** @test */
    public function le_pont_d_impression_sort_la_livraison_a_sa_liberation_et_pas_a_sa_creation(): void
    {
        $this->horloge('2026-03-10 00:01:00');
        $livraison = $this->livraison('2026-03-10 20:30:00');
        $programmee = $this->livraison(null, [
            'order_type'       => OrderType::TAKEAWAY,
            'source_surface'   => 'web',
            'scheduled_at'     => Carbon::parse('2026-03-10 20:00:00', 'Europe/Paris'),
            'is_advance_order' => Ask::YES,
        ]);

        $file = $this->fileImpression();
        $this->assertNotContains($livraison->id, $file, 'Pas de papier cuisine à 00:01 pour une livraison qui part à 20:30.');
        $this->assertNotContains($programmee->id, $file, 'Ni pour une programmée de 20:00.');

        // 19:45 : la livraison est libérée depuis 19:40 (5 min), la programmée depuis 19:40 aussi.
        $this->horloge('2026-03-10 19:45:00');
        $file = $this->fileImpression();
        $this->assertContains($livraison->id, $file, 'À sa libération, le papier cuisine DOIT sortir (créée 19 h plus tôt).');
        $this->assertContains($programmee->id, $file, 'Même règle pour une programmée créée des heures avant.');

        $this->assertNotContains($livraison->id, $this->fileImpression(), 'Réclamée une fois : pas de doublon.');
    }

    /** @test */
    public function l_impression_serveur_ne_sort_pas_une_commande_retenue(): void
    {
        Printer::create([
            'branch_id' => $this->branche()->id, 'name' => 'Cuisine', 'type' => 'escpos_tcp',
            'host' => '192.168.192.168', 'port' => 9100, 'station' => 'kitchen_hot',
            'width_chars' => 48, 'status' => Status::ACTIVE,
        ]);
        $this->horloge('2026-03-10 00:13:00');
        $livraison = $this->livraison('2026-03-10 20:30:00');
        $programmee = $this->livraison(null, [
            'order_type' => OrderType::TAKEAWAY, 'source_surface' => 'web', 'status' => OrderStatus::PENDING,
            'scheduled_at' => Carbon::parse('2026-03-12 19:00:00', 'Europe/Paris'), 'is_advance_order' => Ask::YES,
        ]);
        $printer = app(KitchenTicketAutoPrinter::class);

        $this->assertSame('not_released', $printer->printOnce($livraison, 'order_created'));
        $this->assertSame('not_released', $printer->printOnce($programmee, 'order_created'),
            'Commande 8135 : programmée 2 jours plus tard, pas de ticket cuisine à la création.');
        $this->assertSame(0, $this->envois);
        $this->assertNull($livraison->fresh()->kitchen_ticket_printed_at, 'Non réclamée : un déclencheur ultérieur pourra la sortir.');

        $this->horloge('2026-03-10 19:40:00');
        $this->assertSame('printed', $printer->printOnce($livraison->fresh(), 'kitchen_entry'));
        $this->assertSame(1, $this->envois);
    }
}
