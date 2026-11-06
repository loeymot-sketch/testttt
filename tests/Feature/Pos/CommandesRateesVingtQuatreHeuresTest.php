<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL REMARQUES 2026-10-03 · T-2.3 R-059] Propriétaire, 24/09 : « les commandes que je veux directement
 * les annuler, je clique sur X ça s'annule directement » ; « ça va dans commande rater ça reste 24 heures » ;
 * « ça doit pas être enregistré fiscalement ».
 *
 * Liste en LECTURE SEULE des commandes TÉLÉPHONE annulées par la croix (ou par « supprimer les commandes
 * téléphone ») depuis moins de 24 h. Au-delà, elles sortent de la liste — sans jamais être effacées (NF525).
 * Source : la trace d'audit chaînée de l'annulation, qui date précisément le geste. Rien n'est écrit.
 */
class CommandesRateesVingtQuatreHeuresTest extends TestCase
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
        Carbon::setTestNow(Carbon::now(config('app.timezone'))->startOfDay()->setHour(14));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api()
    {
        return $this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', config('app.api_key'));
    }

    private function enAttente(string $surface, array $attrs = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_type' => $surface === 'kiosk' ? OrderType::KIOSK : OrderType::TAKEAWAY,
            'source_surface' => $surface,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'order_datetime' => Carbon::now(),
            'fiscal_sequence_no' => null,
            'pos_customer_name' => 'Karim',
            'pos_customer_phone' => '0600000000',
        ], $attrs));
    }

    private function croix(Order $o): void
    {
        $this->api()->withHeader('X-Idempotency-Key', 'test-croix-'.$o->id)
            ->postJson('/api/admin/pos/counter-collect/'.$o->id.'/cancel', ['reason' => 'Client non venu'])
            ->assertSuccessful();
    }

    private function ratees(): array
    {
        $res = $this->api()->getJson('/api/admin/pos/counter-collect/missed');
        $res->assertOk();

        return $res->json('data');
    }

    /** @test */
    public function une_commande_telephone_annulee_par_la_croix_apparait_avec_son_client(): void
    {
        $tel = $this->enAttente('phone');
        $this->croix($tel);

        $liste = $this->ratees();

        $this->assertCount(1, $liste);
        $this->assertSame($tel->id, $liste[0]['id']);
        $this->assertSame('Karim', $liste[0]['client']);
        $this->assertSame('0600000000', $liste[0]['telephone']);
        $this->assertSame('Client non venu', $liste[0]['motif']);
        $this->assertNotEmpty($liste[0]['annulee_a']);
    }

    /** @test */
    public function les_commandes_supprimees_d_un_geste_y_figurent_aussi(): void
    {
        $tel = $this->enAttente('phone');
        $this->api()->postJson('/api/admin/pos/counter-collect/purge-phone-today', ['confirm' => true])->assertOk();

        $this->assertSame([$tel->id], array_column($this->ratees(), 'id'));
    }

    /** @test */
    public function apres_24_heures_elle_sort_de_la_liste_sans_etre_effacee(): void
    {
        $tel = $this->enAttente('phone');
        $this->croix($tel);

        Carbon::setTestNow(Carbon::now()->addHours(25));

        $this->assertSame([], $this->ratees());
        $this->assertNotNull(Order::withTrashed()->find($tel->id), 'NF525 : la commande n\'est jamais effacée');
    }

    /** @test */
    public function une_commande_borne_annulee_n_est_pas_une_commande_ratee(): void
    {
        $borne = $this->enAttente('kiosk');
        $this->croix($borne);

        $this->assertSame([], $this->ratees());
    }

    /** @test */
    public function jamais_la_commande_d_une_autre_branche(): void
    {
        $autre = Branch::factory()->create();
        $chezLesAutres = $this->enAttente('phone', ['branch_id' => $autre->id]);
        app(\App\Services\PaymentService::class)->cancelCounterPayment($chezLesAutres, 'Client non venu');

        $this->assertSame([], $this->ratees());
    }

    /** @test */
    public function la_liste_ne_modifie_rien(): void
    {
        $tel = $this->enAttente('phone');
        $this->croix($tel);
        $avant = $tel->fresh()->toArray();

        $this->ratees();

        $this->assertEquals($avant, $tel->fresh()->toArray());
    }
}
