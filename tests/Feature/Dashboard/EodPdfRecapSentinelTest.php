<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [V102-08 HEAL-3 2026-05-26] Sentinel: POST /api/admin/dashboard/eod-pdf
 *
 * Locks the one-click EOD PDF recap behaviour the comptable depends on.
 * Asserts:
 *   1. 200 + Content-Type application/pdf + %PDF- magic bytes
 *   2. company_name=null fallback to "Le Cayenne" does NOT crash the render
 *      (the very bug that motivated this cycle — sales_report blade fix)
 *   3. 403 without the `pos-manage-fiscal` permission (even with :dashboard)
 *   4. Empty-day case renders zeros without throwing
 *   5. Invalid date format returns 422 (no silent today-coercion)
 */
class EodPdfRecapSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
    }

    private function apiHeaders(): array
    {
        return ['x-api-key' => config('app.api_key')];
    }

    /**
     * Acceptance #1: happy path returns 200 + valid PDF magic bytes.
     */
    public function test_eod_pdf_returns_pdf_response(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos-manage-fiscal');

        $this->actingAs($admin, 'sanctum');

        $response = $this->withHeaders($this->apiHeaders())
            ->post('/api/admin/dashboard/eod-pdf');

        $response->assertStatus(200);
        $this->assertSame(
            'application/pdf',
            $response->headers->get('Content-Type'),
            'EOD recap MUST be served as application/pdf — comptable expects native PDF, not HTML preview.'
        );
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'),
            'PDF MUST be served as attachment (one-click download UX).');

        $body = $response->streamedContent();
        $this->assertStringStartsWith('%PDF-', $body,
            'Body MUST start with %PDF- magic bytes — proves dompdf produced a valid PDF and the blade rendered.');
    }

    /**
     * Acceptance #2: company_name=null does NOT crash the blade.
     * Reproduces the original bug (sales_report.blade.php) — null setting was
     * causing "Trying to access array offset on null" before the ?? fallback.
     */
    public function test_eod_pdf_renders_when_company_name_is_null(): void
    {
        // Force company_name to null in settings.
        DB::table('settings')->where('key', 'company_name')->update([
            'payload' => json_encode(null),
        ]);
        // Bust the Smartisan Settings in-memory cache.
        Settings::group('company')->all();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos-manage-fiscal');

        $this->actingAs($admin, 'sanctum');

        $response = $this->withHeaders($this->apiHeaders())
            ->post('/api/admin/dashboard/eod-pdf');

        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $body = $response->streamedContent();
        $this->assertStringStartsWith('%PDF-', $body,
            'Blade MUST gracefully fallback when company_name is null — owner reported the original sales_report bug.');
    }

    /**
     * Acceptance #3: a user with `dashboard` but NOT `pos-manage-fiscal`
     * MUST be rejected with 403. This is the disjoint-middleware check
     * (advisor flag — adding eodPdf to the :dashboard middleware list
     * would silently grant the wrong gate).
     */
    public function test_eod_pdf_denies_user_without_pos_manage_fiscal(): void
    {
        $operator = User::factory()->create(['branch_id' => $this->branch->id]);
        $operator->assignRole('POS Operator');
        // POS Operator gets `dashboard` per RolePermissionTableSeeder, NOT
        // `pos-manage-fiscal`. Verify the gate.
        $this->assertTrue($operator->can('dashboard'),
            'Test invariant: POS Operator MUST have :dashboard (otherwise this test is vacuous).');
        $this->assertFalse($operator->can('pos-manage-fiscal'),
            'Test invariant: POS Operator MUST NOT have :pos-manage-fiscal.');

        $this->actingAs($operator, 'sanctum');

        $response = $this->withHeaders($this->apiHeaders())
            ->post('/api/admin/dashboard/eod-pdf');

        $response->assertStatus(403);
    }

    /**
     * Acceptance #4: empty-day case (no orders) renders without crashing.
     * Edge case: brand-new branch, day with zero traffic — PDF must still
     * deliver with "Aucun encaissement enregistré ce jour."
     */
    public function test_eod_pdf_renders_empty_day_without_crash(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos-manage-fiscal');

        $this->actingAs($admin, 'sanctum');

        // Explicit yesterday (no fixtures created → guaranteed empty).
        $yesterday = Carbon::yesterday(config('app.timezone'))->toDateString();

        $response = $this->withHeaders($this->apiHeaders())
            ->post('/api/admin/dashboard/eod-pdf?date=' . $yesterday);

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF-', $response->streamedContent(),
            'Empty-day case MUST render cleanly — the PDF surface is the comptable\'s only UI for this data.');
    }

    /**
     * Acceptance #5: malformed date query returns 422, NOT silent fallback
     * to today (which would be a confusing UX — comptable thinks they pulled
     * yesterday but actually got today).
     */
    public function test_eod_pdf_rejects_invalid_date_format(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('pos-manage-fiscal');

        $this->actingAs($admin, 'sanctum');

        $response = $this->withHeaders($this->apiHeaders())
            ->post('/api/admin/dashboard/eod-pdf?date=not-a-date');

        $response->assertStatus(422);
    }

    /**
     * Bonus assertion: the service-layer payload shape matches the contract
     * the blade depends on. Catches silent shape drift before the PDF
     * regression manifests as a half-rendered comptable surface.
     */
    public function test_eod_synthesis_payload_shape_is_stable(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        // Seed one paid POS order.
        Order::factory()->create([
            'branch_id'           => $this->branch->id,
            'order_type'          => OrderType::POS,
            'source'              => Source::POS,
            'payment_status'      => PaymentStatus::PAID,
            'status'              => OrderStatus::DELIVERED,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'total'               => 12.50,
            'total_tax'           => 1.04,
            'order_datetime'      => Carbon::today(config('app.timezone'))->addHours(10),
        ]);

        $synthesis = app(\App\Services\DashboardService::class)->eodSynthesis(null);

        $this->assertIsArray($synthesis);
        foreach ([
            'date', 'branch_label', 'total_ca', 'total_tva', 'total_orders',
            'paid_orders', 'refunded_orders', 'avg_ticket',
            'by_payment', 'by_channel', 'top_items',
        ] as $key) {
            $this->assertArrayHasKey($key, $synthesis, "eodSynthesis payload MUST expose '{$key}' — blade contract.");
        }

        $this->assertSame(1, $synthesis['paid_orders']);
        $this->assertEqualsWithDelta(12.50, $synthesis['total_ca'], 0.01);
        $this->assertIsArray($synthesis['by_payment']);
        $this->assertGreaterThan(0, count($synthesis['by_payment']),
            'by_payment MUST contain at least the CASH bucket from the seeded POS order.');
    }
}
