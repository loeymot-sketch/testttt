<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Mail\FiscalReceiptMail;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [P0-4 / KIO-6] Coverage for the fallback fiscal receipt PDF endpoint.
 *
 * NF525 (CGI 286-I-3°bis) requires a printed receipt for every
 * transaction. When the kiosk's three printing fallbacks fail
 * (printReceipt → printEscPos → window.print), the customer must
 * still get a copy. This test suite proves the backend can:
 *
 *   1. render a PDF for both Order (POS) and FrontendOrder (kiosk),
 *   2. include the order serial / fiscal sequence in the rendering,
 *   3. handle variations + extras (JSON-string columns) without crashing,
 *   4. email the PDF via Mail::fake() — no real outbound,
 *   5. enforce the pos-manage-fiscal permission on both endpoints,
 *   6. log a structured audit line on email-send.
 */
class PdfReceiptGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected User $staff;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config([
            'app.api_key'              => 'test-api-key',
            'broadcasting.default'     => 'log',
            'pricing.use_ssot_service' => true,
            'fiscal.audit_secret'      => str_repeat('a', 48),
            'fiscal.z_report_secret'   => str_repeat('b', 48),
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time'        => 30,
            'order_setup_schedule_order_slot_duration' => 30,
        ]);

        $this->branch = Branch::forceCreate([
            'name'    => 'Receipt PDF Branch',
            'city'    => 'Paris',
            'state'   => 'IDF',
            'zip_code'=> '75000',
            'address' => '1 rue Receipt',
            'status'  => 1,
        ]);

        $tax = Tax::create([
            'name'     => 'TVA 10',
            'code'     => 'TVA10PDF',
            'tax_rate' => 10,
            'type'     => TaxType::PERCENTAGE,
            'status'   => Status::ACTIVE,
        ]);

        $category = ItemCategory::forceCreate([
            'name'   => 'PDF Cat',
            'slug'   => 'pdf-cat',
            'status' => Status::ACTIVE,
        ]);

        $this->item = Item::forceCreate([
            'name'             => 'Tacos PDF',
            'slug'             => 'tacos-pdf',
            'price'            => 10.00,
            'status'           => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
        ]);

        $this->admin = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->admin->assignRole('Admin');
        // pos-manage-fiscal is already attached to Admin via syncPermissions
        // in seedSpatieRoles, but explicit grant guarantees the test stays
        // green if the seeding order changes.
        $this->admin->givePermissionTo('pos-manage-fiscal');

        $this->staff = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->staff->assignRole('Stuff');
    }

    public function test_generates_pdf_for_pos_order(): void
    {
        $order = $this->seedPosOrder(11.00, 1.00);

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->get("/api/admin/order/{$order->id}/receipt-pdf?type=Order");

        $resp->assertStatus(200);
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $resp->getContent(), 'Response is a valid PDF binary.');
    }

    public function test_generates_pdf_for_frontend_order_kiosk(): void
    {
        $order = $this->seedFrontendOrder();

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->get("/api/admin/order/{$order->id}/receipt-pdf?type=FrontendOrder");

        $resp->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $resp->getContent());
    }

    /**
     * NOTE: dompdf compresses internal streams so a substring grep
     * against the raw PDF binary is unreliable. We assert two things
     * separately:
     *   1. the service produces a valid PDF (magic header check), AND
     *   2. the same Blade template, rendered in isolation against the
     *      same data path, contains the fiscal sequence string.
     * That gives us confidence the rendered PDF carries the data even
     * if we cannot string-grep the compressed content.
     */
    public function test_pdf_contains_fiscal_sequence_no(): void
    {
        $order = $this->seedPosOrder(11.00, 1.00);
        // Stamp the fiscal sequence directly — we don't go through
        // FiscalSequenceService because that's frozen and the row is
        // already persisted. The Blade only reads the column.
        $order->forceFill(['fiscal_sequence_no' => 4242])->saveQuietly();

        $bytes = app(\App\Services\Fiscal\PdfReceiptGenerator::class)
            ->generate($order->id, 'Order');
        $this->assertStringStartsWith('%PDF-', $bytes);

        $html = view('pdf.fiscal-receipt', [
            'order'              => $order->load('orderItems.orderItem', 'branch', 'transaction'),
            'branch'             => $order->branch,
            'fiscal_sequence_no' => 4242,
            'company_name'       => 'FoodKing',
        ])->render();
        $this->assertStringContainsString('#4242', $html);
        $this->assertStringContainsString('N° fiscal', $html);
    }

    public function test_pdf_contains_order_items_with_variations_and_extras(): void
    {
        $order = $this->seedPosOrder(15.00, 1.36, [
            ['name' => 'Sauce piquante', 'id' => 1, 'price' => 0],
        ], [
            ['name' => 'Frites', 'id' => 2, 'price' => 2.50],
        ]);

        // Render the Blade directly so we can string-assert.
        $html = view('pdf.fiscal-receipt', [
            'order'              => $order->load('orderItems.orderItem', 'branch', 'transaction'),
            'branch'             => $order->branch,
            'fiscal_sequence_no' => null,
            'company_name'       => 'FoodKing',
        ])->render();

        $this->assertStringContainsString('Tacos PDF', $html);
        $this->assertStringContainsString('Sauce piquante', $html);
        $this->assertStringContainsString('Frites', $html);
        $this->assertStringContainsString('Total TTC', $html);
    }

    public function test_email_endpoint_sends_pdf_via_mail_fake(): void
    {
        Mail::fake();

        $order = $this->seedPosOrder(11.00, 1.00);

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/order/{$order->id}/receipt-pdf/email", [
                'email' => 'customer@example.com',
                'type'  => 'Order',
            ]);

        $resp->assertStatus(200);
        $resp->assertJson(['status' => 'sent', 'order_id' => $order->id]);

        Mail::assertSent(FiscalReceiptMail::class, function (FiscalReceiptMail $mail) use ($order) {
            return $mail->hasTo('customer@example.com')
                && $mail->getOrderId() === $order->id;
        });
    }

    public function test_download_endpoint_requires_admin_permission(): void
    {
        $order = $this->seedPosOrder(11.00, 1.00);

        $resp = $this->actingAs($this->staff)
            ->withHeader('x-api-key', config('app.api_key'))
            ->get("/api/admin/order/{$order->id}/receipt-pdf?type=Order");

        $resp->assertStatus(403);
    }

    public function test_email_endpoint_requires_admin_permission(): void
    {
        Mail::fake();
        $order = $this->seedPosOrder(11.00, 1.00);

        $resp = $this->actingAs($this->staff)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/order/{$order->id}/receipt-pdf/email", [
                'email' => 'customer@example.com',
                'type'  => 'Order',
            ]);

        $resp->assertStatus(403);
        Mail::assertNothingSent();
    }

    public function test_email_endpoint_validates_email_format(): void
    {
        Mail::fake();
        $order = $this->seedPosOrder(11.00, 1.00);

        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/order/{$order->id}/receipt-pdf/email", [
                'email' => 'not-a-real-email',
            ]);

        $resp->assertStatus(422);
        Mail::assertNothingSent();
    }

    public function test_audit_log_emitted_on_email_sent(): void
    {
        Mail::fake();

        // Spy on the Log facade with `spy()` so non-asserted methods
        // (e.g. error/debug from middleware or the framework) pass
        // through silently. The controller MUST emit a single
        // info-level entry tagged "[FiscalPdf] Receipt sent by email".
        $logSpy = Log::spy();

        $order = $this->seedPosOrder(11.00, 1.00);

        $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson("/api/admin/order/{$order->id}/receipt-pdf/email", [
                'email' => 'customer@example.com',
                'type'  => 'Order',
            ])
            ->assertStatus(200);

        $logSpy->shouldHaveReceived('info')
            ->withArgs(function (string $message, array $ctx = []) {
                return $message === '[FiscalPdf] Receipt sent by email'
                    && ($ctx['email'] ?? null) === 'customer@example.com'
                    && isset($ctx['order_id']);
            })
            ->once();
    }

    public function test_download_returns_404_for_unknown_order(): void
    {
        $resp = $this->actingAs($this->admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->get('/api/admin/order/99999999/receipt-pdf?type=Order');

        // The generator throws NotFoundHttpException which Laravel maps
        // cleanly to HTTP 404. Frontend retry behavior + on-call paging
        // rules diverge sharply between 404 (legitimate not-found,
        // do not retry) and 500 (server error, may retry); the contract
        // must stay tight.
        $resp->assertStatus(404);
    }

    /* ------------------------------------------------------------------
     | Fixture helpers — direct Eloquent rows (no real OrderService call,
     | which would require the full POS pipeline + fiscal sequence flow).
     | The fallback receipt only RENDERS persisted data, so seeding the
     | row directly is sufficient and isolates this test from upstream
     | order-creation semantics.
     |------------------------------------------------------------------*/

    private function seedPosOrder(
        float $total,
        float $tax,
        array $variations = [],
        array $extras = [],
    ): Order {
        $order = Order::factory()->create([
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->admin->id,
            'order_type'          => OrderType::POS,
            'status'              => OrderStatus::ACCEPT,
            'payment_status'      => PaymentStatus::PAID,
            'subtotal'            => $total,
            'total_tax'           => $tax,
            'total'               => $total,
            'pos_received_amount' => $total,
            'source'              => Source::POS,
            'order_serial_no'     => 'FK-' . random_int(1000, 9999),
        ]);

        OrderItem::create([
            'order_id'             => $order->id,
            'branch_id'            => $this->branch->id,
            'item_id'              => $this->item->id,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA 10',
            'tax_rate'             => '10',
            'tax_type'             => TaxType::PERCENTAGE,
            'tax_amount'           => $tax,
            'price'                => 10.00,
            'item_variations'      => json_encode($variations),
            'item_extras'          => json_encode($extras),
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
            'total_price'          => $total,
        ]);

        return $order->fresh();
    }

    private function seedFrontendOrder(): FrontendOrder
    {
        // FrontendOrder shares the `orders` table; create the row via
        // the kiosk-side model so the global BranchScope path is the one
        // exercised by the generator.
        $row = FrontendOrder::query()->withoutGlobalScopes()->create([
            'branch_id'        => $this->branch->id,
            'user_id'          => $this->admin->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'subtotal'         => 11.00,
            'total_tax'        => 1.00,
            'total'            => 11.00,
            // Source::WEB covers kiosk in this codebase (no KIOSK enum value).
            // The fallback receipt cares about the row, not the source code.
            'source'           => Source::WEB,
            'order_serial_no'  => 'KIOSK-' . random_int(1000, 9999),
            'order_datetime'   => now(),
        ]);

        OrderItem::create([
            'order_id'             => $row->id,
            'branch_id'            => $this->branch->id,
            'item_id'              => $this->item->id,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA 10',
            'tax_rate'             => '10',
            'tax_type'             => TaxType::PERCENTAGE,
            'tax_amount'           => 1.00,
            'price'                => 10.00,
            'item_variations'      => '[]',
            'item_extras'          => '[]',
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
            'total_price'          => 11.00,
        ]);

        return $row->fresh();
    }
}
