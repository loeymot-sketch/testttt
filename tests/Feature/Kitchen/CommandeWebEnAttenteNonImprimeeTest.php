<?php

namespace Tests\Feature\Kitchen;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Listeners\PrintKioskKitchenTicketOnOrderCreated;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Printer;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [VG-2026-10-21 M1-103 · reste] Une commande WEB encore EN ATTENTE d'acceptation sortait son ticket cuisine
 * à sa création (#8385 : créée 17:06:40, imprimée 17:06:40) — refusée ensuite, son papier était déjà en cuisine.
 *
 * Règle du dépôt : la cuisine lit une commande à partir d'ACCEPTÉ (docs/ORDER_FLOW.md « 2. Validation
 * paiement — Qui lit : Cuisine (KDS) » ; KITCHEN-AUTOPRINT owner « chaque commande qui rentre sur l'écran de
 * cuisine ») ; le pont d'impression des PC (KitchenTicketQueueController) ne sort déjà que les statuts
 * cuisine. Le déclencheur « à la création » s'aligne : une commande en attente s'imprime à son acceptation.
 *
 * Bout en bout par l'API du site, chaîne d'écouteurs réelle.
 */
class CommandeWebEnAttenteNonImprimeeTest extends TestCase
{
    use RefreshDatabase;

    private int $envois = 0;
    private Branch $branch;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key', 'printing.bypass.enabled' => false]);
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30, 'order_setup_delivery' => 5, 'order_setup_takeaway' => 5,
        ]);
        $now = CarbonImmutable::parse('2026-10-22 19:02:00', 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function commandeWebAEmporter(): FrontendOrder
    {
        $tax = Tax::factory()->create(['name' => 'TVA 10', 'code' => 'T10', 'type' => TaxType::PERCENTAGE, 'tax_rate' => 10, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'name' => 'Eau Plate', 'price' => 10, 'status' => Status::ACTIVE]);

        $r = $this->actingAs($this->customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', [
                'branch_id' => $this->branch->id, 'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 0,
                'total' => 10, 'order_type' => (string) OrderType::TAKEAWAY, 'is_advance_order' => Ask::NO,
                'delivery_time' => 'ASAP', 'source' => Source::WEB,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
            ]);
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        return FrontendOrder::withoutGlobalScopes()->findOrFail((int) $r->json('data.id'));
    }

    /** @test */
    public function une_commande_web_en_attente_ne_s_imprime_pas_a_sa_creation_mais_a_son_acceptation(): void
    {
        $order = $this->commandeWebAEmporter();
        $this->assertSame(OrderStatus::PENDING, (int) $order->status, 'prémisse : en attente d\'acceptation.');

        $this->assertSame(0, $this->envois, 'Pas de papier en cuisine pour une commande pas encore acceptée.');
        $this->assertNull($order->fresh()->kitchen_ticket_printed_at, 'Non réclamée : elle doit pouvoir sortir à l\'acceptation.');

        // La caisse accepte : la commande entre en cuisine → le ticket sort, une fois.
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['status' => OrderStatus::ACCEPT]);
        event(new OrderStatusChanged(Order::withoutGlobalScopes()->find($order->id), OrderStatus::PENDING, OrderStatus::ACCEPT));

        $this->assertSame(1, $this->envois, 'Acceptée : le ticket cuisine sort.');
    }

    /** @test — une commande refusée en attente n'a jamais eu de ticket cuisine. */
    public function une_commande_web_refusee_avant_acceptation_n_a_jamais_ete_imprimee(): void
    {
        $order = $this->commandeWebAEmporter();
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['status' => OrderStatus::REJECTED]);

        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated(Order::withoutGlobalScopes()->find($order->id)));

        $this->assertSame(0, $this->envois);
    }

    /** @test — non-régression : une commande créée DÉJÀ acceptée (borne espèces, Uber) s'imprime à sa création. */
    public function une_commande_creee_acceptee_s_imprime_toujours_a_sa_creation(): void
    {
        $order = $this->commandeWebAEmporter();
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->update(['status' => OrderStatus::ACCEPT, 'source_surface' => 'kiosk']);

        (new PrintKioskKitchenTicketOnOrderCreated)->handle(new OrderCreated(Order::withoutGlobalScopes()->find($order->id)));

        $this->assertSame(1, $this->envois);
    }
}
