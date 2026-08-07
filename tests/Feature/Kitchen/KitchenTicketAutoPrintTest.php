<?php

namespace Tests\Feature\Kitchen;

use App\Enums\OrderStatus;
use App\Enums\Status;
use App\Events\OrderStatusChanged;
use App\Listeners\AutoPrintKitchenTicketOnKitchenEntry;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Printer;
use App\Models\User;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Services\Kitchen\KitchenTicketAutoPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * [KITCHEN-AUTOPRINT 2026-08-07 owner] Le ticket cuisine sort SEUL, sans clic, pour toute
 * commande qui entre en cuisine — borne, caisse ou site.
 *
 * Ces tests APPELLENT le service et comptent les envois réels à l'imprimante (transport
 * mocké). Ils ne relisent pas le source : une sentinelle en expression régulière resterait
 * verte alors que plus rien ne s'imprime.
 *
 * Le point le plus risqué est la garde anti-doublon : plusieurs chemins mènent à la même
 * commande, et un rouleau qui sort deux fois fait préparer deux fois le plat.
 */
class KitchenTicketAutoPrintTest extends TestCase
{
    use RefreshDatabase;

    private int $envois = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // On intercepte au niveau du TRANSPORT, pas du service : c'est le dernier maillon avant
        // le câble, donc ce qu'on compte est bien ce qui sortirait du rouleau. (Le service, lui,
        // est `final` — et le mocker aurait de toute façon court-circuité sa vraie logique.)
        $this->envois = 0;
        $this->app->instance(PrinterTransportInterface::class, new class($this->envois) implements PrinterTransportInterface {
            public array $captures = [];

            public function __construct(private int &$compteur) {}

            public function send(string $bytes, array $config): bool
            {
                $this->compteur++;
                $this->captures[] = $bytes;

                return true;
            }

            public function lastError(): ?string { return null; }
        });

        // Le mode bypass du poste de dev court-circuiterait l'envoi : on le désactive ici,
        // sinon ces tests seraient verts sans rien prouver.
        config(['printing.bypass.enabled' => false]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** La branche est créée une seule fois : l'imprimante y est rattachée par clé étrangère. */
    private function branche(): Branch
    {
        return Branch::query()->find(1) ?? Branch::factory()->create(['id' => 1]);
    }

    private function imprimanteCuisine(): Printer
    {
        return Printer::create([
            'branch_id' => $this->branche()->id,
            'name' => 'Epson TM-m30 Cuisine',
            'type' => 'escpos_tcp',
            'host' => '192.168.192.168',
            'port' => 9100,
            'station' => 'kitchen_hot',
            'width_chars' => 48,
            'status' => Status::ACTIVE,
        ]);
    }

    private function commande(string $surface = 'pos'): Order
    {
        $branch = $this->branche();

        return Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'source_surface' => $surface,
            'status' => OrderStatus::ACCEPT,
        ]);
    }

    private function printer(): KitchenTicketAutoPrinter
    {
        return app(KitchenTicketAutoPrinter::class);
    }

    // ── Le cœur de la demande ─────────────────────────────────────────────────

    /** Une commande CAISSE s'imprime seule : c'était la grande oubliée, elle exigeait un clic. */
    public function test_une_commande_caisse_simprime_sans_aucun_clic(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commande('pos');

        $statut = $this->printer()->printOnce($order, 'test');

        $this->assertSame('printed', $statut);
        $this->assertSame(1, $this->envois, 'Le ticket cuisine doit partir tout seul pour une commande caisse.');
    }

    /**
     * @dataProvider surfaces
     */
    public function test_toutes_les_surfaces_impriment(string $surface): void
    {
        $this->imprimanteCuisine();

        $this->assertSame('printed', $this->printer()->printOnce($this->commande($surface), 'test'));
        $this->assertSame(1, $this->envois, "La surface « {$surface} » doit imprimer comme les autres.");
    }

    public static function surfaces(): array
    {
        return [['pos'], ['kiosk'], ['web'], ['delivery']];
    }

    // ── La garde anti-doublon ─────────────────────────────────────────────────

    /** Deux déclencheurs sur la même commande = UN seul rouleau. */
    public function test_deux_declencheurs_nimpriment_quune_seule_fois(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commande('pos');

        $this->assertSame('printed', $this->printer()->printOnce($order, 'order_created'));
        $this->assertSame('already', $this->printer()->printOnce($order, 'kitchen_entry'));

        $this->assertSame(1, $this->envois, 'Un ticket qui sort deux fois fait préparer le plat deux fois.');
    }

    /**
     * La réclamation est ATOMIQUE : la base arbitre. Ce test simule le cas gagné par un autre
     * chemin entre-temps — un test-puis-écrit en PHP laisserait passer les deux.
     */
    public function test_une_commande_deja_reclamee_nest_jamais_reimprimee(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commande('pos');
        DB::table('orders')->where('id', $order->id)->update(['kitchen_ticket_printed_at' => now()]);

        $this->assertSame('already', $this->printer()->printOnce($order, 'test'));
        $this->assertSame(0, $this->envois);
    }

    /**
     * SENS DE LA DÉFAILLANCE — une commande qui n'est pas (encore) en base ne peut pas être
     * dédupliquée. Elle doit alors s'imprimer QUAND MÊME : un ticket manquant fait oublier un
     * plat, un ticket en double ne coûte qu'un bout de papier. Mon premier jet supprimait
     * silencieusement toute impression borne et web à cause de ce cas.
     */
    public function test_une_commande_non_persistee_simprime_quand_meme(): void
    {
        $this->imprimanteCuisine();
        $order = (new Order)->forceFill([
            'id' => 987654, 'branch_id' => $this->branche()->id,
            'order_serial_no' => 'TMP-1', 'queue_number' => 'A0001',
            'order_type' => \App\Enums\OrderType::TAKEAWAY, 'subtotal' => 10, 'total' => 10,
            'order_datetime' => now(),
        ]);
        $order->setRelation('branch', $this->branche());
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect());

        $this->assertSame('printed', $this->printer()->printOnce($order, 'test'));
        $this->assertSame(1, $this->envois);
    }

    /**
     * Un ÉCHEC doit LIBÉRER la commande : sinon une imprimante momentanément hors tension
     * condamnerait le ticket à ne jamais sortir, même une fois le câble rebranché.
     */
    public function test_un_echec_dimpression_ne_condamne_pas_le_ticket(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commande('pos');

        $this->app->instance(PrinterTransportInterface::class, new class implements PrinterTransportInterface {
            public function send(string $bytes, array $config): bool
            {
                throw new \RuntimeException('imprimante hors tension');
            }

            public function lastError(): ?string { return 'imprimante hors tension'; }
        });

        $this->assertSame('failed', $this->printer()->printOnce($order, 'test'));
        $this->assertNull(
            DB::table('orders')->where('id', $order->id)->value('kitchen_ticket_printed_at'),
            'Après un échec, la commande doit rester imprimable.'
        );
    }

    // ── Absence d'imprimante ──────────────────────────────────────────────────

    /**
     * Sans imprimante cuisine, on ne RÉCLAME pas la commande : le jour où elle est branchée,
     * le prochain déclencheur doit pouvoir imprimer au lieu de la croire déjà faite.
     */
    public function test_sans_imprimante_la_commande_reste_imprimable_plus_tard(): void
    {
        $order = $this->commande('pos');

        $this->assertSame('no_printer', $this->printer()->printOnce($order, 'test'));
        $this->assertNull(DB::table('orders')->where('id', $order->id)->value('kitchen_ticket_printed_at'));

        $this->imprimanteCuisine();
        $this->assertSame('printed', $this->printer()->printOnce($order->refresh(), 'test'));
        $this->assertSame(1, $this->envois);
    }

    // ── Le déclencheur « entrée en cuisine » ──────────────────────────────────

    /** Le passage au statut ACCEPTÉ déclenche l'impression, quelle que soit la surface. */
    public function test_le_passage_en_cuisine_declenche_limpression(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commande('pos');

        (new AutoPrintKitchenTicketOnKitchenEntry())
            ->handle(new OrderStatusChanged($order, OrderStatus::PENDING, OrderStatus::ACCEPT));

        $this->assertSame(1, $this->envois);
    }

    /** Un statut qui n'est PAS une entrée en cuisine ne doit rien imprimer. */
    public function test_un_statut_hors_cuisine_nimprime_rien(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commande('pos');
        $listener = new AutoPrintKitchenTicketOnKitchenEntry();

        foreach ([OrderStatus::PREPARED, OrderStatus::DELIVERED, OrderStatus::CANCELED] as $statut) {
            $listener->handle(new OrderStatusChanged($order, OrderStatus::ACCEPT, $statut));
        }

        $this->assertSame(0, $this->envois, 'Une commande prête ou annulée ne doit pas ressortir du rouleau.');
    }

    /** Le bandeau CUISSON doit se retrouver sur le ticket réellement envoyé. */
    public function test_le_ticket_envoye_porte_le_bandeau_cuisson(): void
    {
        $this->imprimanteCuisine();
        $order = $this->commande('pos');

        $this->printer()->printOnce($order, 'test');

        $transport = $this->app->make(PrinterTransportInterface::class);
        $this->assertNotEmpty($transport->captures, 'Rien n\'est parti vers l\'imprimante.');
        $this->assertStringContainsString(
            'CUISINE',
            (string) iconv('CP858', 'UTF-8//IGNORE', $transport->captures[0])
        );
    }
}
