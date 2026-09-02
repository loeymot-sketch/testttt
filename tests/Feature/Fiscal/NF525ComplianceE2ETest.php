<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Fiscal\ZReportService;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [F-017 Wave 4.1 — Suite 9 / NF525 Compliance E2E]
 *
 * Source plan: plans/PLAN_AUDIT_F017_MASSIVE_E2E_TEST_SUITE_2026-05-08.md §1 Suite 9
 *
 * 8 scenarios — fiscal NF525 compliance, exercised through the public API
 * surface (HTTP routes) where possible, and through the underlying services
 * where HTTP would only add noise (factory-built orders are sufficient
 * evidence for aggregate / chain assertions, per existing pattern in
 * RefundPostZTest + ZReportControllerTest).
 *
 * AC mapping (plan §2):
 *   - AC8  : F-001 NF525 invariant (kiosk paid orders aggregated in Z)
 *   - AC9  : F-003 cash variance present in Z aggregate
 *   - AC11 : multi-branch isolation (only own-branch orders aggregated)
 *
 * Anti-drift:
 *   - No frozen-zone touched (ZReportService, RefundWithCounterEntryService,
 *     FiscalSequenceService, AuditLogService — all read-only consumed).
 *   - No migration.
 *   - cash_unacknowledged_count is NOT asserted because that column does not
 *     exist on the z_reports table today (verified via grep). Plan §1
 *     Suite 9 #4 mentions it as "visible" — downgraded to a presence
 *     check on cash_variance only (which DOES exist via F-003 enrichment).
 */
class NF525ComplianceE2ETest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Branch $otherBranch;
    private User $manager;
    private User $admin;
    private User $cashier;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set([
            'app.api_key'            => 'test-api-key',
            'broadcasting.default'   => 'log',
            'fiscal.z_report_secret' => str_repeat('z', 48),
            'fiscal.audit_secret'    => str_repeat('a', 48),
            // Disable strict chain validation in tests so factory-seeded Z
            // rows (with synthetic signatures) do not trip verifyChain().
            'fiscal.verify_chain_strict'         => false,
            'fiscal.chain_validation_enabled'    => false,
        ]);

        $this->branch      = Branch::factory()->create(['name' => 'Branch A']);
        $this->otherBranch = Branch::factory()->create(['name' => 'Branch B']);

        $this->manager = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->manager->givePermissionTo('pos-manage-fiscal');

        $this->admin = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('pos-destroy-paid');
        $this->admin->givePermissionTo('pos-manage-fiscal');
        // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] New pos-refund permission
        // gate added to PosOrderController::refundWithCounterEntry. Scenario 6
        // (refund post-Z mirror) calls the route → requires the new permission.
        if (!\Spatie\Permission\Models\Permission::where('name', 'pos-refund')->where('guard_name', 'sanctum')->exists()) {
            \Spatie\Permission\Models\Permission::create(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        }
        $this->admin->givePermissionTo('pos-refund');

        $this->cashier = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->cashier->assignRole('POS Operator');

        // Minimal item (used only when test wants to exercise the HTTP POST
        // /api/admin/pos path; aggregate / chain tests bypass via factory).
        $category = ItemCategory::forceCreate([
            'name' => 'NF525 E2E Cat',
            'slug' => 'nf525-e2e-cat',
            'status' => 1,
        ]);
        $this->item = Item::forceCreate([
            'name'             => 'NF525 E2E Item',
            'slug'             => 'nf525-e2e-item',
            'price'            => 10.00,
            'status'           => 1,
            'item_category_id' => $category->id,
        ]);
    }

    private function apiHeaders(): array
    {
        return ['x-api-key' => config('app.api_key')];
    }

    private function makeFiscalOrder(int $branchId, int $sequenceNo, float $total, ?Carbon $createdAt = null, int $status = OrderStatus::DELIVERED): Order
    {
        $order = Order::factory()->create([
            'branch_id'          => $branchId,
            'user_id'            => $this->cashier->id,
            'order_type'         => OrderType::POS,
            'status'             => $status,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => $total,
            'total'              => $total,
            'total_tax'          => 0,
            'discount'           => 0,
            'fiscal_sequence_no' => $sequenceNo,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'created_at'         => $createdAt ?? now()->subMinutes(5),
            'updated_at'         => $createdAt ?? now()->subMinutes(5),
        ]);

        // Defensive: order_items needed by tax breakdown query (taxBreakdownForOrders).
        // discount is NOT NULL in schema → must be supplied explicitly.
        OrderItem::create([
            'order_id'    => $order->id,
            'branch_id'   => $branchId,
            'item_id'     => $this->item->id,
            'quantity'    => 1,
            'price'       => $total,
            'discount'    => 0,
            'tax_rate'    => '10',
            'tax_amount'  => 0,
            'total_price' => $total,
        ]);

        return $order;
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 1 — Day open
     *   POST /api/admin/fiscal/z-report/open → ZReport row INSERT,
     *   sequence_no monotonique (1, 2, 3, …).
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_1_day_open_inserts_z_with_monotonic_sequence(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        $first = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/open');

        $first->assertStatus(201);
        $this->assertSame(1, (int) $first->json('data.sequence_no'));
        $this->assertSame(ZReport::STATUS_OPEN, $first->json('data.status'));
        $this->assertDatabaseHas('z_reports', [
            'id'          => (int) $first->json('data.id'),
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'status'      => ZReport::STATUS_OPEN,
        ]);

        // Close the first one so we can open a second (only one OPEN allowed
        // at a time per branch).
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/close')
            ->assertStatus(200);

        $second = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/open');
        $second->assertStatus(201);
        $this->assertSame(
            2,
            (int) $second->json('data.sequence_no'),
            'Second open() must reserve sequence_no=2 (monotonic + gap-free).'
        );
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 2 — N orders processed inside Z window
     *   20 mixed POS+Kiosk orders created between open and close.
     *   open → seed 20 orders → close → assert order_count = 20.
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_2_n_orders_processed_in_window_aggregated(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        // Freeze time so the orders we seed land strictly INSIDE
        // (opened_at, closed_at] without depending on real-clock progression.
        Carbon::setTestNow(Carbon::parse('2026-05-08 09:00:00'));

        $open = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/open');
        $open->assertStatus(201);

        // Travel forward and seed 20 orders inside the window.
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00'));
        for ($i = 1; $i <= 20; $i++) {
            $order = $this->makeFiscalOrder(
                $this->branch->id,
                $i,
                10.00,
                Carbon::parse('2026-05-08 10:00:00')->addSeconds($i),
            );
            // Half POS half KIOSK source (NF525 invariant: kiosk PAID orders
            // must be in the Z aggregate — F-001).
            if ($i % 2 === 0) {
                $order->forceFill(['order_type' => OrderType::KIOSK])->saveQuietly();
            }
        }

        // Travel forward to close.
        Carbon::setTestNow(Carbon::parse('2026-05-08 11:00:00'));
        $close = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/admin/fiscal/z-report/close');
        $close->assertStatus(200);
        $this->assertSame(
            20,
            (int) $close->json('data.order_count'),
            '20 orders inside the Z window must be aggregated (POS+KIOSK both included).'
        );
        $this->assertEqualsWithDelta(200.0, (float) $close->json('data.total_ttc'), 0.01);

        Carbon::setTestNow(); // reset
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 3 — Day close
     *   POST /api/admin/fiscal/z-report/close → HMAC chain signature,
     *   total_sales = sum(orders.total).
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_3_day_close_signs_and_chains_hmac(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        Carbon::setTestNow(Carbon::parse('2026-05-08 09:00:00'));
        $open = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/open');
        $open->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00'));
        $this->makeFiscalOrder($this->branch->id, 1, 25.50, Carbon::parse('2026-05-08 10:00:01'));
        $this->makeFiscalOrder($this->branch->id, 2, 14.50, Carbon::parse('2026-05-08 10:00:02'));

        Carbon::setTestNow(Carbon::parse('2026-05-08 11:00:00'));
        $close = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/close');
        $close->assertStatus(200);
        $close->assertJsonPath('data.status', ZReport::STATUS_CLOSED);

        $signature = (string) $close->json('data.signature');
        $this->assertNotEmpty($signature, 'Closed Z must carry an HMAC signature.');
        $this->assertSame(64, strlen($signature), 'HMAC-SHA256 hex signature must be 64 chars.');
        $this->assertEqualsWithDelta(40.0, (float) $close->json('data.total_ttc'), 0.01);

        // Open a second window and close — prev_hash chains onto the previous signature.
        Carbon::setTestNow(Carbon::parse('2026-05-08 12:00:00'));
        $open2 = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/open');
        $open2->assertStatus(201);
        Carbon::setTestNow(Carbon::parse('2026-05-08 13:00:00'));
        $close2 = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/close');
        $close2->assertStatus(200);
        $this->assertSame(
            $signature,
            (string) $close2->json('data.prev_hash'),
            'Z chain must link prev_hash of Z2 to signature of Z1.'
        );

        Carbon::setTestNow();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 4 — Z aggregate completeness
     *   All POS orders included.
     *   All Kiosk PAID orders included (F-001 invariant).
     *   cash_variance (F-003) computed via enrichment service.
     *
     * Note: cash_unacknowledged_count column does not exist today; the
     * presence assertion focuses on cash_variance which IS persisted by
     * ZReportCashEnrichmentService::persistForClosedReport().
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_4_z_aggregate_completeness_pos_kiosk_and_cash_variance(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        Carbon::setTestNow(Carbon::parse('2026-05-08 09:00:00'));
        $open = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/open');
        $open->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00'));
        // 3 POS PAID + 2 Kiosk PAID + 1 Kiosk UNPAID (must NOT be aggregated).
        for ($i = 1; $i <= 3; $i++) {
            $this->makeFiscalOrder($this->branch->id, $i, 12.00, Carbon::parse('2026-05-08 10:00:0' . $i));
        }
        for ($i = 4; $i <= 5; $i++) {
            $o = $this->makeFiscalOrder($this->branch->id, $i, 18.00, Carbon::parse('2026-05-08 10:00:0' . $i));
            $o->forceFill(['order_type' => OrderType::KIOSK])->saveQuietly();
        }
        // UNPAID kiosk — must be filtered out by ZReportService::aggregate().
        Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'user_id'            => $this->cashier->id,
            'order_type'         => OrderType::KIOSK,
            'status'             => OrderStatus::ACCEPT,
            'payment_status'     => PaymentStatus::UNPAID,
            'subtotal'           => 99.0,
            'total'              => 99.0,
            'total_tax'          => 0,
            'discount'           => 0,
            'fiscal_sequence_no' => 999,
            'created_at'         => Carbon::parse('2026-05-08 10:00:07'),
            'updated_at'         => Carbon::parse('2026-05-08 10:00:07'),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-08 11:00:00'));
        $close = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/close');
        $close->assertStatus(200);

        // 3 POS + 2 Kiosk PAID = 5 orders aggregated; UNPAID kiosk excluded.
        $this->assertSame(5, (int) $close->json('data.order_count'));
        $this->assertEqualsWithDelta(72.0, (float) $close->json('data.total_ttc'), 0.01,
            '3×12 (POS) + 2×18 (Kiosk PAID) = 72; UNPAID 99 must be excluded.');

        // Direct DB check: cash_variance column EXISTS on z_reports schema
        // (added by 2026_05_08_140200_alter_z_reports_add_cash_columns).
        // Default value when no cash sessions reconciled in window = null/0.
        $z = ZReport::find((int) $close->json('data.id'));
        $this->assertNotNull($z, 'Closed Z must be persisted in z_reports.');
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('z_reports', 'cash_variance'),
            'F-003 enrichment column cash_variance MUST exist in z_reports schema.'
        );

        Carbon::setTestNow();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 5 — Audit chain integrity verifyChain() PASS
     *   AuditLogService::verifyChain() returns null when chain intact.
     *   Returns int (id of first tampered row) on tampering.
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_5_audit_chain_integrity_verify_chain_passes(): void
    {
        /** @var AuditLogService $audit */
        $audit = app(AuditLogService::class);

        // Write a small, well-formed chain (3 entries, same branch).
        $audit->write([
            'branch_id'   => $this->branch->id,
            'user_id'     => $this->admin->id,
            'action'      => 'nf525.e2e.scenario5.first',
            'resource'    => 'order',
            'resource_id' => 1,
            'payload'     => ['scenario' => 5, 'step' => 1],
        ]);
        $audit->write([
            'branch_id'   => $this->branch->id,
            'user_id'     => $this->admin->id,
            'action'      => 'nf525.e2e.scenario5.second',
            'resource'    => 'order',
            'resource_id' => 2,
            'payload'     => ['scenario' => 5, 'step' => 2],
        ]);
        $audit->write([
            'branch_id'   => $this->branch->id,
            'user_id'     => $this->admin->id,
            'action'      => 'nf525.e2e.scenario5.third',
            'resource'    => 'order',
            'resource_id' => 3,
            'payload'     => ['scenario' => 5, 'step' => 3],
        ]);

        $tamperedId = $audit->verifyChain($this->branch->id);
        $this->assertNull(
            $tamperedId,
            'AuditLogService::verifyChain() must return null when chain is intact (returns int row id on tampering).'
        );

        // ZReportService::verifyChain() (different signature — array result).
        $chainResult = app(ZReportService::class)->verifyChain($this->branch->id, false);
        $this->assertTrue($chainResult['valid'],
            'ZReportService::verifyChain() must report valid=true on an empty / well-formed chain.');
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 6 — Refund post-Z via counter-entry
     *   Closed Z + parent order with fiscal_sequence_no →
     *   refund creates a mirror order (negated totals, fresh sequence,
     *   parent_order_id link, status=RETURNED).
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_6_refund_post_z_creates_counter_entry_mirror(): void
    {
        // Build a parent order in a sealed window (yesterday).
        $parent = $this->makeFiscalOrder(
            $this->branch->id,
            1,
            55.00,
            now()->subDay()->setTime(12, 0),
        );

        // Seal the window with a closed Z report (forced row, signature
        // is synthetic — we disabled chain validation in setUp).
        ZReport::forceCreate([
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'opened_at'   => now()->subDay()->setTime(8, 0),
            'closed_at'   => now()->subDay()->setTime(23, 59),
            'opened_by'   => $this->admin->id,
            'closed_by'   => $this->admin->id,
            'total_ht'    => 55.0,
            'total_ttc'   => 55.0,
            'total_tva'   => 0.0,
            'total_by_method'   => ['cash' => 55.0],
            'total_by_tax_rate' => ['10' => 0.0],
            'order_count' => 1,
            'cancel_count' => 0,
            'refund_count' => 0,
            'prev_hash'   => null,
            'signature'   => str_repeat('a', 64),
            'status'      => ZReport::STATUS_CLOSED,
        ]);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->withHeaders($this->apiHeaders())
            ->postJson("/api/admin/pos-order/{$parent->id}/refund-with-counter-entry", [
                'reason' => 'NF525 E2E counter-entry refund test',
            ]);

        $resp->assertStatus(201);
        $mirrorId = (int) $resp->json('data.id');
        $this->assertNotSame($parent->id, $mirrorId, 'Mirror order must be a new row.');

        $mirror = Order::findOrFail($mirrorId);
        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);
        $this->assertSame(PaymentStatus::REFUNDED, (int) $mirror->payment_status);
        $this->assertEqualsWithDelta(-55.0, (float) $mirror->total, 0.01);
        $this->assertSame((int) $parent->id, (int) $mirror->parent_order_id);
        $this->assertNotNull($mirror->fiscal_sequence_no, 'Mirror must carry a fresh fiscal_sequence_no.');
        $this->assertNotSame((int) $parent->fiscal_sequence_no, (int) $mirror->fiscal_sequence_no);

        // Parent stays IMMUTABLE (NF525-compliant).
        $parent->refresh();
        $this->assertNotSame(OrderStatus::RETURNED, (int) $parent->status,
            'Parent order MUST remain in its original status after counter-entry refund.');
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 7 — Tentative destroy order PAID post-Z → 409
     *   POS-9.4.BL.3 — orders sealed by a closed Z are immutable.
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_7_destroy_paid_post_z_returns_409(): void
    {
        $sealed = $this->makeFiscalOrder(
            $this->branch->id,
            1,
            33.0,
            now()->subDay()->setTime(12, 30),
        );

        ZReport::forceCreate([
            'branch_id'   => $this->branch->id,
            'sequence_no' => 1,
            'opened_at'   => now()->subDay()->setTime(8, 0),
            'closed_at'   => now()->subDay()->setTime(23, 59),
            'opened_by'   => $this->admin->id,
            'closed_by'   => $this->admin->id,
            'total_ht'    => 33.0,
            'total_ttc'   => 33.0,
            'total_tva'   => 0.0,
            'total_by_method'   => ['cash' => 33.0],
            'total_by_tax_rate' => ['10' => 0.0],
            'order_count' => 1,
            'cancel_count' => 0,
            'refund_count' => 0,
            'prev_hash'   => null,
            'signature'   => str_repeat('b', 64),
            'status'      => ZReport::STATUS_CLOSED,
        ]);

        $resp = $this->actingAs($this->admin, 'sanctum')
            ->withHeaders($this->apiHeaders())
            ->deleteJson("/api/admin/pos-order/{$sealed->id}", [
                'destroy_reason' => 'attempt-destroy-after-z',
            ]);

        $resp->assertStatus(409);
        $this->assertDatabaseHas('orders', [
            'id'         => $sealed->id,
            'deleted_at' => null,
        ]);
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Scenario 8 — Receipt PDF
     *   GET /api/admin/fiscal/z-report/{id}/pdf → signed bundle
     *   with verified=true.
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_scenario_8_receipt_pdf_returns_verified_signed_bundle(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        Carbon::setTestNow(Carbon::parse('2026-05-08 09:00:00'));
        $open = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/open');
        $open->assertStatus(201);
        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00'));
        $this->makeFiscalOrder($this->branch->id, 1, 17.0, Carbon::parse('2026-05-08 10:00:01'));

        Carbon::setTestNow(Carbon::parse('2026-05-08 11:00:00'));
        $close = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/close');
        $close->assertStatus(200);
        $zId = (int) $close->json('data.id');

        // [AUDIT-COMPTA 2026-08-29] Ce scénario vérifiait `data.verified` dans du JSON, avec
        // le commentaire « for downstream PDF rendering » : l'auteur savait qu'un PDF devait
        // exister en aval, et ne testait que le paquet. Le PDF, lui, n'a jamais été écrit —
        // le bouton téléchargeait 793 octets de JSON nommés `.pdf`. On mesure désormais le
        // document que le comptable reçoit, sans rien perdre de l'intention NF525.
        $pdf = $this->withHeaders($this->apiHeaders())
            ->get("/api/admin/fiscal/z-report/{$zId}/pdf");

        $pdf->assertStatus(200);
        $pdf->assertHeader('content-type', 'application/pdf');

        $corps = $pdf->streamedContent();
        $this->assertStringStartsWith('%PDF-', $corps,
            'La pièce fiscale remise au comptable doit être un PDF. Reçu : ' . substr($corps, 0, 40));

        // L'intention d'origine, conservée et vérifiée à la source : le rapport porte bien
        // son empreinte, elle est conforme, et c'est celle de CE rapport.
        $zReport = \App\Models\ZReport::find($zId);
        $this->assertNotEmpty($zReport->signature,
            'Un rapport Z clos doit porter son empreinte de scellement.');
        $this->assertTrue(
            app(\App\Services\Fiscal\ZReportService::class)->verifySignature($zReport),
            'La signature du rapport doit être conforme au moment de l\'édition.',
        );
        $this->assertStringContainsString(
            'rapport-z-' . $zReport->sequence_no,
            $pdf->headers->get('content-disposition') ?? '',
            'Le fichier doit être nommé d\'après le numéro de rapport, pour être classable.',
        );

        Carbon::setTestNow();
    }

    /**
     * ─────────────────────────────────────────────────────────────────
     * Bonus invariant — branch isolation in the aggregate (AC11)
     *   Orders created on otherBranch MUST NOT leak into branch's Z.
     * ─────────────────────────────────────────────────────────────────
     */
    public function test_branch_isolation_in_z_aggregate(): void
    {
        $this->actingAs($this->manager, 'sanctum');

        Carbon::setTestNow(Carbon::parse('2026-05-08 09:00:00'));
        $open = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/open');
        $open->assertStatus(201);

        Carbon::setTestNow(Carbon::parse('2026-05-08 10:00:00'));
        // 2 orders on this manager's branch.
        $this->makeFiscalOrder($this->branch->id, 1, 10.0, Carbon::parse('2026-05-08 10:00:01'));
        $this->makeFiscalOrder($this->branch->id, 2, 20.0, Carbon::parse('2026-05-08 10:00:02'));
        // 5 orders on the OTHER branch — must NOT be aggregated here.
        for ($i = 100; $i < 105; $i++) {
            $this->makeFiscalOrder($this->otherBranch->id, $i, 999.0, Carbon::parse('2026-05-08 10:00:03'));
        }

        Carbon::setTestNow(Carbon::parse('2026-05-08 11:00:00'));
        $close = $this->withHeaders($this->apiHeaders())->postJson('/api/admin/fiscal/z-report/close');
        $close->assertStatus(200);

        $this->assertSame(
            2,
            (int) $close->json('data.order_count'),
            'Z aggregate MUST be strictly scoped to the manager\'s branch.'
        );
        $this->assertEqualsWithDelta(30.0, (float) $close->json('data.total_ttc'), 0.01);

        Carbon::setTestNow();
    }
}
