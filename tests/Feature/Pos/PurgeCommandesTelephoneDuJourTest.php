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
 * [GOAL REMARQUES 2026-10-03 · T-2.2 R-060] Propriétaire, 29/09 : « les commandes en attente ceux qui sont
 * pris par téléphone, il y aurait plein d'entre eux qui sont annulés, les clients ils viennent pas […] Dans
 * l'attente je veux tout supprimer, je supprime tout ». La purge en bloc n'existait que pour les JOURS
 * PRÉCÉDENTS (R-061). Ici : « Supprimer toutes les commandes TÉLÉPHONE en attente » du jour.
 *
 * Bornes non négociables :
 *  - TÉLÉPHONE seulement : un client de la BORNE ou du SITE peut être devant le comptoir — jamais touché ;
 *  - jamais une commande payée ou portant un numéro fiscal (garde sous verrou du service) ;
 *  - jamais une autre branche ; une trace d'audit par commande ; confirmation explicite.
 */
class PurgeCommandesTelephoneDuJourTest extends TestCase
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

    private function commande(string $surface, array $attrs = [], int $joursAvant = 0): Order
    {
        $defauts = [
            'branch_id' => $this->branch->id,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_type' => $surface === 'kiosk' ? OrderType::KIOSK : OrderType::TAKEAWAY,
            'source_surface' => $surface,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'order_datetime' => Carbon::now()->subDays($joursAvant),
            'fiscal_sequence_no' => null,
        ];

        return Order::factory()->create(array_merge($defauts, $attrs));
    }

    private function purger(array $payload = ['confirm' => true])
    {
        return $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/counter-collect/purge-phone-today', $payload);
    }

    /** @test */
    public function toutes_les_commandes_telephone_du_jour_partent_et_rien_d_autre(): void
    {
        $tel1 = $this->commande('phone');
        $tel2 = $this->commande('phone', ['status' => OrderStatus::PENDING]);
        $borne = $this->commande('kiosk');
        $web = $this->commande('web');
        $telHier = $this->commande('phone', [], 1);

        $res = $this->purger();
        $res->assertOk();
        $this->assertSame(2, (int) $res->json('purged'));

        $this->assertSame(OrderStatus::CANCELED, (int) $tel1->fresh()->status);
        $this->assertSame(OrderStatus::REJECTED, (int) $tel2->fresh()->status, 'PENDING → REJECTED (seule transition légale)');
        $this->assertSame(OrderStatus::ACCEPT, (int) $borne->fresh()->status, 'un client de la BORNE peut être au comptoir : jamais touché');
        $this->assertSame(OrderStatus::ACCEPT, (int) $web->fresh()->status, 'une commande du SITE n\'est jamais touchée');
        $this->assertSame(OrderStatus::ACCEPT, (int) $telHier->fresh()->status, 'les jours précédents ont leur propre onglet');
        $this->assertNull($tel1->fresh()->fiscal_sequence_no, 'aucune séquence fiscale allouée');
    }

    /** @test */
    public function une_trace_d_audit_par_commande_avec_le_motif(): void
    {
        $tel = $this->commande('phone');

        $this->purger(['confirm' => true, 'reason' => 'Client non venu'])->assertOk();

        $audit = AuditLog::where('action', 'order.counter_pending_purged')->where('resource_id', $tel->id)->first();
        $this->assertNotNull($audit, 'trace d\'audit obligatoire');
        $this->assertStringContainsString('Client non venu', json_encode($audit->payload, JSON_UNESCAPED_UNICODE));
    }

    /** @test */
    public function jamais_une_commande_payee_ou_fiscalisee(): void
    {
        $payee = $this->commande('phone', ['payment_status' => PaymentStatus::PAID]);
        $scellee = $this->commande('phone', ['fiscal_sequence_no' => 4243]);

        $this->purger()->assertOk();

        $this->assertSame(OrderStatus::ACCEPT, (int) $payee->fresh()->status);
        $this->assertSame(OrderStatus::ACCEPT, (int) $scellee->fresh()->status);
    }

    /** @test */
    public function jamais_une_autre_branche(): void
    {
        $autre = Branch::factory()->create();
        $chezLesAutres = $this->commande('phone', ['branch_id' => $autre->id]);

        $this->purger()->assertOk();

        $this->assertSame(OrderStatus::ACCEPT, (int) $chezLesAutres->fresh()->status);
    }

    /**
     * [Revue adverse vague 2 · P1-2] Une commande téléphone À L'AVANCE (ce soir 20 h, demain midi) n'est pas
     * un « client non venu » : la supprimer annulerait la commande d'un client qui viendra — et une
     * annulation ne se défait pas. Elle reste dans la file, intouchée.
     *
     * @test
     */
    public function une_commande_a_l_avance_n_est_jamais_supprimee(): void
    {
        $ceSoir = $this->commande('phone', ['scheduled_at' => Carbon::now()->setHour(20)]);
        $demain = $this->commande('phone', ['scheduled_at' => Carbon::now()->addDay()->setHour(12)], 1);
        $maintenant = $this->commande('phone');

        $res = $this->purger();
        $res->assertOk();

        $this->assertSame(1, (int) $res->json('purged'));
        $this->assertSame(OrderStatus::ACCEPT, (int) $ceSoir->fresh()->status);
        $this->assertSame(OrderStatus::ACCEPT, (int) $demain->fresh()->status);
        $this->assertSame(OrderStatus::CANCELED, (int) $maintenant->fresh()->status);
    }

    /**
     * [Revue adverse vague 2 · P2-1] Le serveur ne supprime QUE ce que le caissier a vu et confirmé : une
     * commande arrivée entre l'affichage et le clic n'est pas emportée.
     *
     * @test
     */
    public function seules_les_commandes_montrees_au_caissier_partent(): void
    {
        $vue = $this->commande('phone');
        $arriveeEntreTemps = $this->commande('phone');

        $res = $this->purger(['confirm' => true, 'ids' => [$vue->id]]);
        $res->assertOk();

        $this->assertSame(1, (int) $res->json('purged'));
        $this->assertSame(OrderStatus::CANCELED, (int) $vue->fresh()->status);
        $this->assertSame(OrderStatus::ACCEPT, (int) $arriveeEntreTemps->fresh()->status);
    }

    /** @test */
    public function sans_confirmation_rien_ne_part(): void
    {
        $tel = $this->commande('phone');

        $this->purger([])->assertStatus(422);

        $this->assertSame(OrderStatus::ACCEPT, (int) $tel->fresh()->status);
    }
}
