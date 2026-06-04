<?php

namespace Tests\Feature\Receipt;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Receipt\PosReceiptEscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POS PRINTER 2026-06-04] PosReceiptEscPosRenderer — NF525 ticket bytes.
 */
class PosReceiptEscPosRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Admin (branch_id=0) bypasses BranchScope so we can freely build /
        // read the order_items used by the renderer.
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);
    }

    private function makeOrder(): Order
    {
        $branch = Branch::factory()->create([
            'name' => 'LE CAYENNE',
            'siret' => '12345678901234',
            'vat_intra' => 'FR12345678901',
            'register_id' => 'CAISSE-01',
            'legal_footer' => 'TVA acquittee sur les debits',
        ]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_serial_no' => 'ORD-TEST-01',
            'subtotal' => 17.00,
            'total' => 17.00,
            'discount' => 0,
        ]);
        // Columns not in the factory default — set directly (bypasses $fillable).
        $order->forceFill([
            'queue_number' => 'A12',
            'fiscal_sequence_no' => 42,
            'total_tax' => 1.55,
            'pos_payment_method' => 'cash',
            'pos_received_amount' => 20.00,
        ])->save();

        $item = Item::factory()->create(['name' => 'Tacos Poulet']);
        OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'discount' => 0,
            'price' => 8.50,
            'total_price' => 17.00,
            'tax_name' => 'TVA',
            'tax_rate' => '10',
            'tax_type' => 1,
            'tax_amount' => 1.55,
            'composition_snapshot' => [
                'lines' => [['variation_name' => 'Sauce Algerienne']],
                'extras' => [['name' => 'Cheddar']],
            ],
            'instruction' => 'Sans oignons',
        ]);

        return $order->load(['orderItems.orderItem', 'branch', 'user']);
    }

    public function test_render_emits_escpos_structure_and_fiscal_fields(): void
    {
        $bytes = app(PosReceiptEscPosRenderer::class)->render($this->makeOrder());

        // Control sequences.
        $this->assertStringStartsWith("\x1B@", $bytes, 'must start with ESC @ init');
        $this->assertStringContainsString("\x1Bt", $bytes, 'must select a code page (ESC t)');
        $this->assertStringContainsString("\x1DV", $bytes, 'must end with a cut (GS V)');

        // Content (ASCII-only assertions to avoid CP858 transcode noise).
        $this->assertStringContainsString('LE CAYENNE', $bytes);
        $this->assertStringContainsString('Tacos Poulet', $bytes);
        $this->assertStringContainsString('2x', $bytes);
        $this->assertStringContainsString('#42', $bytes, 'fiscal sequence');
        $this->assertStringContainsString('12345678901234', $bytes, 'SIRET');
        $this->assertStringContainsString('TOTAL', $bytes);
        $this->assertStringContainsString('Sans oignons', $bytes, 'item instruction');
        $this->assertStringContainsString('Cheddar', $bytes, 'composition extra');
    }

    public function test_render_marks_duplicata_only_when_requested(): void
    {
        $order = $this->makeOrder();
        $renderer = app(PosReceiptEscPosRenderer::class);

        $this->assertStringNotContainsString('DUPLICATA', $renderer->render($order, ['is_duplicata' => false]));
        $this->assertStringContainsString('DUPLICATA', $renderer->render($order, ['is_duplicata' => true]));
    }

    public function test_render_includes_per_rate_vat_breakdown(): void
    {
        $bytes = app(PosReceiptEscPosRenderer::class)->render($this->makeOrder());

        $this->assertStringContainsString('TVA', $bytes);
        $this->assertStringContainsString('10%', $bytes, 'per-rate VAT line (CGI art. 242 nonies A)');
    }

    /**
     * Regression: pos_payment_method is stored as the NUMERIC PosPaymentMethod
     * code ("1", "2", …), not a string. A real-order render showed "1" on the
     * payment line before the numeric mapping was added.
     */
    public function test_render_labels_numeric_payment_method(): void
    {
        $order = $this->makeOrder();
        $order->forceFill(['pos_payment_method' => (string) \App\Enums\PosPaymentMethod::CARD])->save();

        $bytes = app(PosReceiptEscPosRenderer::class)->render($order->fresh()->load(['orderItems.orderItem', 'branch', 'user']));

        $this->assertStringContainsString('Carte bancaire', $bytes);
    }
}
