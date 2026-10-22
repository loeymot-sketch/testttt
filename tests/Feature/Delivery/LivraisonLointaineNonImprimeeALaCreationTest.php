<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Printer;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Services\Kitchen\KitchenTicketAutoPrinter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M1-103 · chemin d'impression SERVEUR] Une livraison « au plus tôt » rattachée à une
 * tournée LOINTAINE ne doit pas sortir de ticket cuisine à sa création.
 *
 * e7efe07eb a appris à `KitchenTicketAutoPrinter::printOnce` à répondre `not_released` pour une commande
 * retenue (KitchenReleaseRule lit `delivery_slot_at`). Mais sur `OrderCreated`, l'écouteur qui IMPRIME
 * (PrintKioskKitchenTicketOnOrderCreated) passait AVANT celui qui affecte la tournée
 * (AssignDeliveryWaveOnOrderCreated) : au moment de l'impression, `delivery_slot_at` était encore NULL
 * → « au plus tôt » → libérée → papier en cuisine 6 h avant le départ.
 *
 * Bout en bout par l'API du site, chaîne d'écouteurs réelle.
 */
class LivraisonLointaineNonImprimeeALaCreationTest extends TestCase
{
    use RefreshDatabase;

    private int $envois = 0;
    private Branch $branch;
    private User $customer;
    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config([
            'app.api_key'                => 'test-api-key',
            'kds.scheduled_lead_minutes' => 20,
            'delivery.wave_minutes'      => 30,
            'kds.scheduled_window_open'  => '18:00',
            'kds.scheduled_window_close' => '00:30',
            'printing.bypass.enabled'    => false,
        ]);
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30, 'order_setup_delivery' => 5, 'order_setup_takeaway' => 5,
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

        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        Printer::create([
            'branch_id' => $this->branch->id, 'name' => 'Cuisine', 'type' => 'escpos_tcp',
            'host' => '192.168.192.168', 'port' => 9100, 'station' => 'kitchen_hot',
            'width_chars' => 48, 'status' => Status::ACTIVE,
        ]);
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->address = Address::query()->create([
            'user_id' => $this->customer->id, 'label' => 'Domicile', 'address' => '10 Rue Test, Hénin-Beaumont',
            'latitude' => '50.4300', 'longitude' => '2.9600', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110', 'geocode_status' => 'OK',
        ]);
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

    private function commandeLivraisonAuPlusTot(): FrontendOrder
    {
        $tax = Tax::factory()->create(['name' => 'TVA 10', 'code' => 'T10', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 10, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Eau Plate', 'price' => 10, 'status' => Status::ACTIVE]);

        $r = $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', [
                'branch_id' => $this->branch->id, 'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 0.01,
                'total' => 1, 'order_type' => (string) OrderType::DELIVERY, 'is_advance_order' => Ask::NO,
                'address_id' => $this->address->id, 'delivery_time' => 'ASAP', 'source' => Source::WEB,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
            ]);
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        return FrontendOrder::withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
    }

    /** @test */
    public function une_livraison_au_plus_tot_sur_une_tournee_lointaine_ne_s_imprime_pas_a_la_creation(): void
    {
        // 12:00 : hors service (18:00–00:30) → la première tournée disponible est 18:00, six heures plus tard.
        $this->horloge('2026-10-20 12:00:00');

        $order = $this->commandeLivraisonAuPlusTot();

        $stop = DeliveryStop::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($stop, 'La livraison doit être rattachée à sa tournée.');
        $this->assertSame('18:00', $stop->wave_at->format('H:i'), 'Tournée lointaine attendue (prémisse du test).');

        $this->assertSame(0, $this->envois, 'Aucun ticket cuisine ne doit sortir 6 h avant le départ.');
        $this->assertNull($order->fresh()->kitchen_ticket_printed_at, 'Non réclamée : elle sortira à sa libération.');
        $this->assertSame('not_released', app(KitchenTicketAutoPrinter::class)->printOnce($order->fresh(), 'order_created'));
    }

    /** @test — non-régression : une livraison au plus tôt sur la tournée PROCHE s'imprime toujours à la création. */
    public function une_livraison_au_plus_tot_sur_la_tournee_proche_s_imprime_a_la_creation(): void
    {
        $this->horloge('2026-10-20 19:02:00');

        $order = $this->commandeLivraisonAuPlusTot();

        $this->assertSame('19:30', DeliveryStop::query()->where('order_id', $order->id)->value('wave_at')?->format('H:i'));
        $this->assertSame(1, $this->envois, 'Départ proche : le ticket cuisine sort tout de suite, comme avant.');
    }
}
