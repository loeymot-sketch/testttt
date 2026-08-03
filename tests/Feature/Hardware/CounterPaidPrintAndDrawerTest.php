<?php

namespace Tests\Feature\Hardware;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Events\OrderPaidAtCounter;
use App\Listeners\PrintFiscalReceiptAndOpenDrawerOnCounterPaid;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [G10/G12 2026-06-28 — audit adversaire] À l'encaissement comptoir (borne payée à la
 * caisse), le reçu doit s'imprimer + le tiroir s'ouvrir si ESPÈCES. Service + renderer
 * sont `final` (non-mockables) → on espionne le TRANSPORT : 1 envoi = reçu, +1 (cash)
 * = kick tiroir. Ordre non-persisté (forceFill+setRelation) comme le test renderer.
 */
class CounterPaidPrintAndDrawerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private object $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->spy = new class implements PrinterTransportInterface {
            public array $sends = [];
            public function send(string $bytes, array $config): bool { $this->sends[] = strlen($bytes); return true; }
            public function lastError(): ?string { return null; }
        };
        $this->app->instance(PrinterTransportInterface::class, $this->spy);
    }

    private function makeOrder(): Order
    {
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1, 'total_price' => 13.80, 'tax_rate' => 10, 'tax_name' => 'TVA',
            'tax_type' => 1, 'tax_amount' => 1.25,
            'composition_snapshot' => ['lines' => [['attribute_name' => 'Viande 1', 'variation_name' => 'Cordon Bleu']], 'extras' => [], 'addons' => []],
        ]);
        $oi->name = 'Tacos L';
        $order = (new Order)->forceFill([
            'id' => 999, 'branch_id' => $this->branch->id, 'order_serial_no' => 'T-1', 'queue_number' => 'A0010',
            'order_type' => OrderType::TAKEAWAY, 'subtotal' => 13.80, 'total' => 13.80, 'total_tax' => 1.25,
            'pos_payment_method' => 1, 'pos_received_amount' => 13.80, 'order_datetime' => '2026-06-28 12:00:00',
            'fiscal_sequence_no' => 2560,
        ]);
        $order->setRelation('branch', $this->branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi])); // déjà chargé → pas de re-query DB
        return $order;
    }

    private function makeReceiptPrinter(): void
    {
        Printer::create([
            'branch_id' => $this->branch->id, 'name' => 'Caisse', 'station' => 'receipt',
            'status' => Status::ACTIVE, 'width_chars' => 48,
        ]);
    }

    public function test_cash_encashment_prints_receipt_AND_opens_drawer(): void
    {
        $this->makeReceiptPrinter();
        app(PrintFiscalReceiptAndOpenDrawerOnCounterPaid::class)
            ->handle(new OrderPaidAtCounter($this->makeOrder(), PosPaymentMethod::CASH));
        $this->assertCount(2, $this->spy->sends, 'cash = reçu + tiroir'); // [G10 + G12]
    }

    public function test_card_encashment_prints_receipt_but_NO_drawer(): void
    {
        $this->makeReceiptPrinter();
        app(PrintFiscalReceiptAndOpenDrawerOnCounterPaid::class)
            ->handle(new OrderPaidAtCounter($this->makeOrder(), 2)); // 2 = carte
        $this->assertCount(1, $this->spy->sends, 'carte = reçu seul, pas de tiroir');
    }

    public function test_no_receipt_printer_is_silent_noop(): void
    {
        app(PrintFiscalReceiptAndOpenDrawerOnCounterPaid::class)
            ->handle(new OrderPaidAtCounter($this->makeOrder(), PosPaymentMethod::CASH));
        $this->assertCount(0, $this->spy->sends, 'pas d\'imprimante = no-op');
    }

    public function test_listener_is_registered_on_counter_paid_event(): void
    {
        $this->assertNotEmpty(app('events')->getListeners(OrderPaidAtCounter::class));
    }
}
