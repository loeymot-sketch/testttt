<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL CAISSE/CUISINE #3 2026-10-02] File « en attente d'encaissement » :
 *  - par DÉFAUT, uniquement la journée de service courante (nouveau jour = liste vide) ;
 *  - filtre « jours précédents » (scope=previous) ;
 *  - purge (une par une ou toutes) des commandes JAMAIS PAYÉES, avec trace d'audit ;
 *  - NF525 : une commande payée / fiscalisée n'est JAMAIS touchée, aucun DELETE dur.
 */
class CounterCollectPurgePreviousDaysTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        // 14h00 : on est clairement après la bascule de 5 h → la veille n'est plus dans le jour.
        Carbon::setTestNow(Carbon::now(config('app.timezone'))->startOfDay()->setHour(14));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeOrder(array $attrs = [], int $daysAgo = 0): Order
    {
        $when = Carbon::now()->subDays($daysAgo);

        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_type' => OrderType::KIOSK,
            'source_surface' => 'kiosk',
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'order_datetime' => $when,
            'fiscal_sequence_no' => null,
        ], $attrs));
    }

    private function api()
    {
        return $this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', config('app.api_key'));
    }

    private function ids(string $query = ''): array
    {
        $res = $this->api()->getJson('/api/admin/pos/counter-collect/pending'.$query);
        $res->assertOk();

        return collect($res->json('data'))->pluck('id')->all();
    }

    /** @test */
    public function par_defaut_seul_le_jour_courant_est_liste(): void
    {
        $today = $this->makeOrder();
        $hier = $this->makeOrder([], 1);
        $avantHier = $this->makeOrder([], 2);

        $ids = $this->ids();

        $this->assertContains($today->id, $ids);
        $this->assertNotContains($hier->id, $ids, "la commande d'hier ne doit plus polluer la file du jour");
        $this->assertNotContains($avantHier->id, $ids);
    }

    /** @test */
    public function le_filtre_jours_precedents_liste_les_anciennes_et_compte_dans_meta(): void
    {
        $today = $this->makeOrder();
        $hier = $this->makeOrder([], 1);
        $avantHier = $this->makeOrder([], 2);

        $res = $this->api()->getJson('/api/admin/pos/counter-collect/pending?scope=previous');
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$hier->id, $avantHier->id], $ids);
        $this->assertNotContains($today->id, $ids);

        $defaut = $this->api()->getJson('/api/admin/pos/counter-collect/pending');
        $this->assertSame(2, (int) $defaut->json('meta.previous_count'), 'le compteur du filtre alimente le badge');
    }

    /** @test */
    public function une_commande_a_l_avance_dont_le_creneau_est_futur_reste_dans_la_file_du_jour(): void
    {
        $planned = $this->makeOrder(['scheduled_at' => Carbon::now()->addHours(3)], 1);

        $this->assertContains($planned->id, $this->ids());
    }

    /** @test */
    public function purge_unitaire_annule_la_commande_jamais_payee_et_ecrit_l_audit(): void
    {
        $old = $this->makeOrder([], 1);
        $other = $this->makeOrder([], 2);

        $res = $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'ids' => [$old->id],
            'reason' => 'Commande jamais réglée, client parti',
            'confirm' => true,
        ]);
        $res->assertOk();
        $this->assertSame(1, (int) $res->json('purged'));

        $old->refresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $old->status);
        $this->assertNull($old->fiscal_sequence_no, 'aucune séquence fiscale allouée à une commande jamais payée');
        $this->assertNotSame(PaymentStatus::PAID, (int) $old->payment_status);
        $this->assertNotSame(PaymentStatus::REFUNDED, (int) $old->payment_status, 'aucun argent n\'a bougé : REFUNDED serait un mensonge');
        $this->assertNull($old->pos_payment_method, 'marqueur counter-deferred cassé : plus encaissable');

        $this->assertSame(OrderStatus::ACCEPT, (int) $other->fresh()->status, "l'autre commande n'est pas touchée");

        $audit = AuditLog::where('action', 'order.counter_pending_purged')->where('resource_id', $old->id)->first();
        $this->assertNotNull($audit, 'trace d\'audit obligatoire');
        $this->assertStringContainsString('client parti', json_encode($audit->payload));
    }

    /** @test */
    public function purge_de_tout_annule_toutes_les_anciennes_et_jamais_celles_du_jour(): void
    {
        $today = $this->makeOrder();
        $a = $this->makeOrder([], 1);
        $b = $this->makeOrder(['status' => OrderStatus::PENDING], 3);
        $c = $this->makeOrder(['status' => OrderStatus::PREPARING], 5);

        $res = $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'all' => true,
            'reason' => 'Nettoyage des anciennes',
            'confirm' => true,
        ]);
        $res->assertOk();
        $this->assertSame(3, (int) $res->json('purged'));

        $this->assertSame(OrderStatus::ACCEPT, (int) $today->fresh()->status);
        $this->assertSame(OrderStatus::CANCELED, (int) $a->fresh()->status);
        $this->assertSame(OrderStatus::REJECTED, (int) $b->fresh()->status, 'PENDING → REJECTED (seule transition légale)');
        $this->assertSame(OrderStatus::CANCELED, (int) $c->fresh()->status);
        $this->assertSame(0, $this->countPrevious());
    }

    /** @test */
    public function jamais_de_purge_d_une_commande_payee_ou_fiscalisee(): void
    {
        $paid = $this->makeOrder(['payment_status' => PaymentStatus::PAID], 1);
        $sealed = $this->makeOrder(['fiscal_sequence_no' => 4242], 1);

        $res = $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'ids' => [$paid->id, $sealed->id],
            'reason' => 'test garde NF525',
            'confirm' => true,
        ]);
        $res->assertOk();
        $this->assertSame(0, (int) $res->json('purged'));
        $this->assertCount(2, $res->json('skipped'));

        $this->assertSame(OrderStatus::ACCEPT, (int) $paid->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, (int) $paid->fresh()->payment_status);
        $this->assertSame(4242, (int) $sealed->fresh()->fiscal_sequence_no);
        $this->assertSame(OrderStatus::ACCEPT, (int) $sealed->fresh()->status);
        $this->assertDatabaseHas('orders', ['id' => $paid->id, 'deleted_at' => null]);
    }

    /** @test */
    public function une_commande_du_jour_ne_se_purge_pas_par_ce_chemin(): void
    {
        $today = $this->makeOrder();

        $res = $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'ids' => [$today->id],
            'reason' => 'ne doit pas passer',
            'confirm' => true,
        ]);
        $res->assertOk();
        $this->assertSame(0, (int) $res->json('purged'));
        $this->assertSame(OrderStatus::ACCEPT, (int) $today->fresh()->status);
    }

    /** @test */
    public function la_confirmation_et_le_motif_sont_obligatoires(): void
    {
        $old = $this->makeOrder([], 1);

        $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'ids' => [$old->id],
            'reason' => 'motif ok',
        ])->assertStatus(422);

        $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'ids' => [$old->id],
            'confirm' => true,
        ])->assertStatus(422);

        $this->assertSame(OrderStatus::ACCEPT, (int) $old->fresh()->status);
    }

    /** @test */
    public function une_commande_livree_jamais_payee_n_est_pas_purgee_en_silence(): void
    {
        $delivered = $this->makeOrder(['status' => OrderStatus::DELIVERED], 1);

        $res = $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'all' => true,
            'reason' => 'nettoyage',
            'confirm' => true,
        ]);
        $res->assertOk();
        $this->assertSame(0, (int) $res->json('purged'));
        $this->assertSame(OrderStatus::DELIVERED, (int) $delivered->fresh()->status, 'une dette réelle ne disparaît pas sans décision humaine');
    }

    /** @test */
    public function purge_prepared_archive_par_soft_delete_sans_delete_dur(): void
    {
        $prepared = $this->makeOrder(['status' => OrderStatus::PREPARED], 2);

        $res = $this->api()->postJson('/api/admin/pos/counter-collect/purge-previous', [
            'ids' => [$prepared->id],
            'reason' => 'jamais retirée',
            'confirm' => true,
        ]);
        $res->assertOk();
        $this->assertSame(1, (int) $res->json('purged'));

        $this->assertSoftDeleted('orders', ['id' => $prepared->id]);
        $this->assertDatabaseHas('orders', ['id' => $prepared->id]);
    }

    private function countPrevious(): int
    {
        return (int) $this->api()->getJson('/api/admin/pos/counter-collect/pending?scope=previous')->json('meta.previous_count');
    }
}
