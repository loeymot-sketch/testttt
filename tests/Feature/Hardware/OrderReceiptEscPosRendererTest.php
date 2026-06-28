<?php

namespace Tests\Feature\Hardware;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use App\Services\Hardware\PrinterTransport\WindowsRawPrinterTransport;
use Tests\TestCase;

/**
 * [PRINT-SAGA 2026-06-24] Pure (no-DB) coverage for the ESC/POS ticket renderer
 * and the Windows raw transport command builder.
 */
class OrderReceiptEscPosRendererTest extends TestCase
{
    private function makeOrder(): Order
    {
        $branch = (new Branch)->forceFill([
            'name' => 'Le Cayenne',
            'address' => '437 Rue Élie Gruyelle',
            'phone' => '+33600000000',
        ]);

        // 2 distinct meats + an ACCENTED paid extra ("Viande supplémentaire") — the
        // exact shape that regressed (accented label dropped by double-encoding).
        $oi = (new OrderItem)->forceFill([
            'quantity' => 1,
            'total_price' => 13.80,
            'tax_rate' => 10,
            'tax_name' => 'TVA',
            'tax_type' => 1,
            'tax_amount' => 1.25,
            'composition_snapshot' => [
                'lines' => [
                    ['attribute_name' => 'Viande 1', 'variation_name' => 'Cordon Bleu'],
                    ['attribute_name' => 'Viande 2', 'variation_name' => 'Fricadelle'],
                    ['attribute_name' => 'Sauce (1ère Gratuite)', 'variation_name' => 'Samouraï'],
                ],
                'extras' => [
                    ['extra_name' => 'Cheddar', 'line_total' => 0.90, 'quantity' => 1],
                    ['extra_name' => 'Viande supplémentaire', 'line_total' => 2.50, 'quantity' => 1],
                ],
                'addons' => [],
            ],
        ]);
        $oi->name = 'Tacos L';

        $order = (new Order)->forceFill([
            'order_serial_no' => 'TEST-1',
            'queue_number' => 'A0010',
            'order_type' => \App\Enums\OrderType::TAKEAWAY,
            'subtotal' => 13.80,
            'total' => 13.80,
            'total_tax' => 1.25,
            'pos_payment_method' => 1,
            'pos_received_amount' => 13.80,
            'order_datetime' => '2026-06-24 17:03:00',
            'fiscal_sequence_no' => 2550,
        ]);
        $order->setRelation('branch', $branch);
        $order->setRelation('user', null);
        $order->setRelation('orderItems', collect([$oi]));

        return $order;
    }

    public function test_client_ticket_keeps_accented_extra_label_and_price(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());

        // The accented label MUST survive (regression: double-encoding dropped it).
        $this->assertStringContainsString('Viande suppl', $bytes, 'accented extra label dropped');
        $this->assertStringContainsString('Cheddar', $bytes);
        $this->assertStringContainsString('2,50 EUR', $bytes, 'paid supplement price missing');
        $this->assertStringContainsString('0,90 EUR', $bytes);
        // 2 distinct meats present.
        $this->assertStringContainsString('Cordon Bleu', $bytes);
        $this->assertStringContainsString('Fricadelle', $bytes);
        // Totals + ESC/POS framing.
        $this->assertStringContainsString('TOTAL', $bytes);
        $this->assertStringContainsString('13,80 EUR', $bytes);
        $this->assertStringContainsString("\x1B\x40", $bytes, 'missing ESC @ init');
        $this->assertStringContainsString("\x1D\x56", $bytes, 'missing GS V cut');
        // Fiscal footer.
        $this->assertStringContainsString('2550', $bytes);
    }

    public function test_client_ticket_shows_order_type_queue_and_tva_breakdown(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder());
        // Queue number prominent (customer pickup number).
        $this->assertStringContainsString('A0010', $bytes);
        // Order type (sur place / à emporter / livraison).
        $this->assertStringContainsString('emporter', $bytes);
        // NF525: TVA ventilated by rate + base HT (not a lone lump sum).
        $this->assertStringContainsString('TVA 10', $bytes, 'TVA rate line missing');
        $this->assertStringContainsString('12,55', $bytes, 'base HT per rate missing');
        $this->assertStringContainsString('TOTAL', $bytes);
    }

    public function test_client_ticket_shows_unit_price_when_qty_above_one(): void
    {
        $order = $this->makeOrder();
        $oi = $order->orderItems->first();
        $oi->quantity = 2;
        $oi->total_price = 15.80;
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($order);
        // 15,80 / 2 = 7,90 unit price shown.
        $this->assertStringContainsString('7,90', $bytes, 'unit price for qty>1 missing');
    }

    public function test_client_ticket_marks_duplicata(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderClientTicket($this->makeOrder(), ['is_duplicata' => true]);
        $this->assertStringContainsString('DUPLICATA', $bytes);
    }

    public function test_kitchen_ticket_uses_symbolic_format_no_prices(): void
    {
        $bytes = (new OrderReceiptEscPosRenderer)->renderKitchenTicket($this->makeOrder());
        $this->assertStringContainsString('CUISINE', $bytes);
        // [KITCHEN-SYMBOLS 2026-06-28] Tacos L, Cordon Bleu + Fricadelle, Samouraï.
        $this->assertStringContainsString('G | TACOS | L | Cordon Frec | SAM', $bytes);
        // Paid supplement kept full-name on its own line (accent must survive CP858).
        $this->assertStringContainsString('+ Cheddar', $bytes);
        $this->assertStringContainsString('+ Viande suppl', $bytes);
        // No prices on the kitchen ticket.
        $this->assertStringNotContainsString('EUR', $bytes, 'kitchen ticket must not show prices');
    }

    public function test_windows_raw_transport_builds_winspool_command(): void
    {
        $t = new WindowsRawPrinterTransport;
        $cmd = $t->buildSpoolCommand('SAGA-80mm', 'C:\\tmp\\fk.bin');

        $this->assertStringContainsString('powershell', $cmd);
        $this->assertStringContainsString('-EncodedCommand', $cmd);

        $b64 = trim(str_replace([
            'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand ',
            ' 2>&1',
        ], '', $cmd));
        $decoded = mb_convert_encoding(base64_decode($b64), 'UTF-8', 'UTF-16LE');

        $this->assertStringContainsString('SAGA-80mm', $decoded, 'printer name not in spool command');
        $this->assertStringContainsString('WritePrinter', $decoded);
        $this->assertStringContainsString('RAW', $decoded);
        $this->assertStringContainsString('C:\\tmp\\fk.bin', $decoded);
    }

    public function test_windows_raw_transport_refuses_non_windows_host(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('host is Windows');
        }
        $t = new WindowsRawPrinterTransport;
        $this->assertFalse($t->send('bytes', ['host' => 'SAGA']));
        $this->assertStringContainsString('requires_windows_host', (string) $t->lastError());
    }
}
