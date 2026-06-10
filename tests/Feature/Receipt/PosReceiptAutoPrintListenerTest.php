<?php

namespace Tests\Feature\Receipt;

use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCreated;
use App\Events\OrderPaidAtCounter;
use App\Listeners\PrintPosReceiptOnOrderCreated;
use App\Listeners\PrintPosReceiptOnOrderPaidAtCounter;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Printer;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\PrinterTransport\NullPrinterTransport;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use App\Services\Receipt\PosReceiptAutoPrinter;
use App\Services\Receipt\PosReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POS PRINTER 2026-06-04] Auto-print listeners + PosReceiptAutoPrinter core.
 *
 * Listeners are invoked DIRECTLY: under RefreshDatabase every test runs inside
 * an open transaction, so the DispatchableAfterCommit callbacks would never
 * fire. Direct invocation tests the logic deterministically.
 */
class PosReceiptAutoPrintListenerTest extends TestCase
{
    use RefreshDatabase;

    private NullPrinterTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->transport = new NullPrinterTransport();
    }

    private function autoPrinter(?PrinterTransportInterface $transport = null): PosReceiptAutoPrinter
    {
        return new PosReceiptAutoPrinter(
            new EscPosPrinterService($transport ?? $this->transport),
            app(PosReceiptEscPosRenderer::class),
            app(AuditLogService::class),
        );
    }

    private function counterListener(?PrinterTransportInterface $t = null): PrintPosReceiptOnOrderPaidAtCounter
    {
        return new PrintPosReceiptOnOrderPaidAtCounter($this->autoPrinter($t));
    }

    private function createdListener(?PrinterTransportInterface $t = null): PrintPosReceiptOnOrderCreated
    {
        return new PrintPosReceiptOnOrderCreated($this->autoPrinter($t));
    }

    private function makeOrder(array $overrides = [], bool $withPrinter = true): Order
    {
        $branch = Branch::factory()->create(['name' => 'LE CAYENNE']);

        if ($withPrinter) {
            Printer::query()->create([
                'branch_id' => $branch->id,
                'name' => 'SAGA SGPR-200II',
                'type' => 'escpos_tcp',
                'host' => '127.0.0.1',
                'port' => 9100,
                'station' => 'receipt',
                'width_chars' => 48,
                'status' => 1,
                'options' => ['code_page' => 19],
            ]);
        }

        $order = Order::factory()->create(array_merge([
            'branch_id' => $branch->id,
            'subtotal' => 10.00,
            'total' => 10.00,
            'discount' => 0,
            'payment_status' => PaymentStatus::PAID,
        ], $overrides));
        $order->forceFill([
            'fiscal_sequence_no' => 7,
            'total_tax' => 0.91,
            'source_surface' => $overrides['source_surface'] ?? 'pos',
            'pos_payment_method' => 'cash',
            'pos_received_amount' => 10.00,
        ])->save();

        $item = Item::factory()->create(['name' => 'Menu Burger']);
        OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'discount' => 0,
            'price' => 10.00,
            'total_price' => 10.00,
            'tax_name' => 'TVA',
            'tax_rate' => '10',
            'tax_type' => 1,
            'tax_amount' => 0.91,
        ]);

        return $order->fresh();
    }

    // ── OrderPaidAtCounter (deferred counter-collection) ──────────────────────

    public function test_counter_payment_auto_prints_and_claims_original(): void
    {
        config(['pos.auto_print_receipt' => true]);
        $order = $this->makeOrder();

        $this->counterListener()->handle(new OrderPaidAtCounter($order, PosPaymentMethod::CASH));

        $this->assertCount(1, $this->transport->sent);
        $this->assertStringStartsWith("\x1B@", $this->transport->sent[0]['bytes']);
        $this->assertSame('127.0.0.1', $this->transport->sent[0]['config']['host']);
        $this->assertSame(1, (int) $order->fresh()->receipt_print_count);
    }

    // ── OrderCreated (default inline POS sale) ────────────────────────────────

    public function test_inline_pos_sale_auto_prints(): void
    {
        config(['pos.auto_print_receipt' => true]);
        $order = $this->makeOrder(['source_surface' => 'pos']);

        $this->createdListener()->handle(new OrderCreated($order));

        $this->assertCount(1, $this->transport->sent, 'inline POS (source=pos, PAID) prints');
        $this->assertSame(1, (int) $order->fresh()->receipt_print_count);
    }

    public function test_kiosk_order_does_not_print_on_caisse_via_order_created(): void
    {
        config(['pos.auto_print_receipt' => true]);
        $order = $this->makeOrder(['source_surface' => 'kiosk']);

        $this->createdListener()->handle(new OrderCreated($order));

        $this->assertCount(0, $this->transport->sent, 'kiosk has its own printer — skipped here');
        $this->assertSame(0, (int) $order->fresh()->receipt_print_count);
    }

    public function test_web_order_does_not_print(): void
    {
        config(['pos.auto_print_receipt' => true]);
        $order = $this->makeOrder(['source_surface' => 'web']);

        $this->createdListener()->handle(new OrderCreated($order));

        $this->assertCount(0, $this->transport->sent);
    }

    public function test_unpaid_pos_order_does_not_print_on_creation(): void
    {
        config(['pos.auto_print_receipt' => true]);
        // Deferred-to-counter: created PENDING_COUNTER, will print on payment.
        $order = $this->makeOrder(['source_surface' => 'pos', 'payment_status' => PaymentStatus::PENDING_COUNTER]);

        $this->createdListener()->handle(new OrderCreated($order));

        $this->assertCount(0, $this->transport->sent, 'unpaid order not printed at creation');
        $this->assertSame(0, (int) $order->fresh()->receipt_print_count);
    }

    // ── Cross-path idempotency + guards ───────────────────────────────────────

    public function test_both_events_for_same_order_print_once(): void
    {
        config(['pos.auto_print_receipt' => true]);
        $order = $this->makeOrder(['source_surface' => 'pos']);

        $this->createdListener()->handle(new OrderCreated($order));
        $this->counterListener()->handle(new OrderPaidAtCounter($order->fresh(), PosPaymentMethod::CASH));

        $this->assertCount(1, $this->transport->sent, 'atomic claim → exactly one impression across both paths');
        $this->assertSame(1, (int) $order->fresh()->receipt_print_count);
    }

    public function test_disabled_feature_prints_nothing(): void
    {
        config(['pos.auto_print_receipt' => false]);
        $order = $this->makeOrder();

        $this->createdListener()->handle(new OrderCreated($order));
        $this->counterListener()->handle(new OrderPaidAtCounter($order, PosPaymentMethod::CASH));

        $this->assertCount(0, $this->transport->sent);
        $this->assertSame(0, (int) $order->fresh()->receipt_print_count);
    }

    public function test_no_printer_is_a_safe_no_op(): void
    {
        config(['pos.auto_print_receipt' => true]);
        $order = $this->makeOrder([], withPrinter: false);

        $this->createdListener()->handle(new OrderCreated($order));

        $this->assertCount(0, $this->transport->sent);
        $this->assertSame(0, (int) $order->fresh()->receipt_print_count);
    }

    public function test_send_failure_releases_claim_for_manual_reprint(): void
    {
        config(['pos.auto_print_receipt' => true]);
        $order = $this->makeOrder();

        $this->createdListener(new FailingReceiptTransport())->handle(new OrderCreated($order));

        $this->assertSame(0, (int) $order->fresh()->receipt_print_count, 'claim released on send failure');
    }
}

/** Transport that always fails — exercises the claim-release path. */
class FailingReceiptTransport implements PrinterTransportInterface
{
    public function send(string $bytes, array $config): bool
    {
        return false;
    }

    public function lastError(): ?string
    {
        return 'forced_failure';
    }
}
