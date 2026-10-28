<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\Pilotage\InterrupteurService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [CAISSE-SANS-PAPIER-AUTO 2026-10-19 · owner] Plus AUCUN papier automatique à la caisse.
 *
 * Constat : imprimante caisse débranchée trois jours, rebranchée → le papier sort « sans arrêt »
 * (le pont retentait chaque ticket toutes les 5 s). Décision : la caisse n'imprime plus seule les
 * commandes borne / site / livraison ; la CUISINE, elle, ne change pas.
 */
class CaisseSansPapierAutoTest extends TestCase
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
    }

    private function commande(string $surface): Order
    {
        return Order::factory()->create([
            'branch_id'                 => $this->branch->id,
            'source_surface'            => $surface,
            'order_type'                => OrderType::TAKEAWAY,
            'status'                    => OrderStatus::PREPARING,
            'payment_status'            => PaymentStatus::PAID,
            'payment_method'            => PaymentGateway::CARD,
            'kitchen_ticket_printed_at' => null,
        ]);
    }

    private function reclamer(string $destination): array
    {
        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending', ['destination' => $destination]);
        $res->assertOk();

        return collect($res->json('orders'))->pluck('id')->all();
    }

    /** @test Par défaut, la caisse ne réclame AUCUN ticket, quelle que soit la surface. */
    public function la_caisse_ne_sort_aucun_papier_automatique_par_defaut(): void
    {
        $this->assertFalse((bool) config('kds.counter_auto_print'), 'le défaut doit être ÉTEINT');

        $ids = [];
        foreach (['kiosk', 'web', 'online', 'delivery', 'uber_eats'] as $surface) {
            $ids[] = $this->commande($surface)->id;
        }

        $this->assertSame([], $this->reclamer('counter'));
        // Et rien n'a été réclamé en base : le jour où on rallume, les tickets récents peuvent sortir.
        $this->assertSame(0, DB::table('kitchen_ticket_claims')->whereIn('order_id', $ids)->where('destination', 'counter')->count());
    }

    /** @test Un ancien paquet qui n'envoie aucune destination est traité comme la caisse : coupé aussi. */
    public function un_poste_sans_destination_est_coupe_aussi(): void
    {
        $this->commande('kiosk');

        $res = $this->actingAs($this->cashier, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/kitchen-tickets/pending', []);

        $res->assertOk();
        $this->assertSame([], $res->json('orders'));
    }

    /** @test La CUISINE ne change pas : ses tickets sortent toujours, y compris pour la caisse/téléphone. */
    public function la_cuisine_imprime_toujours_pendant_que_la_caisse_est_coupee(): void
    {
        $kiosk = $this->commande('kiosk');
        $pos = $this->commande('pos');

        $this->assertSame([], $this->reclamer('counter'));

        $cuisine = $this->reclamer('kitchen');
        $this->assertContains($kiosk->id, $cuisine);
        $this->assertContains($pos->id, $cuisine);
    }

    /** @test Une réimpression DEMANDÉE à la main reste servie à la caisse : un humain l'a voulue. */
    public function la_reimpression_manuelle_reste_servie_a_la_caisse(): void
    {
        $kiosk = $this->commande('kiosk');
        DB::table('kitchen_ticket_claims')->insert([
            'order_id' => $kiosk->id, 'destination' => 'counter', 'printed_at' => now()->subMinutes(5),
            'reprint_requested_at' => now(), 'created_at' => now()->subMinutes(5), 'updated_at' => now(),
        ]);

        $this->assertContains($kiosk->id, $this->reclamer('counter'));
    }

    /** @test Rallumer l'interrupteur rend le comportement d'avant (réversible sans déploiement). */
    public function l_interrupteur_du_proprietaire_rallume_la_caisse(): void
    {
        $this->assertArrayHasKey('impression_caisse_auto', InterrupteurService::CATALOGUE);
        $this->assertSame('kds.counter_auto_print', InterrupteurService::CATALOGUE['impression_caisse_auto']['cle']);

        $kiosk = $this->commande('kiosk');
        $this->assertSame([], $this->reclamer('counter'));

        app(InterrupteurService::class)->regler('impression_caisse_auto', true);
        $this->assertContains($kiosk->id, $this->reclamer('counter'));

        app(InterrupteurService::class)->regler('impression_caisse_auto', false);
        $autre = $this->commande('web');
        $this->assertNotContains($autre->id, $this->reclamer('counter'));
    }
}
