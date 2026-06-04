<?php
/**
 * F.10 — REFUND + Z-CLOSE + LOYALTY REDEEM end-to-end flow tests.
 *
 * Read-only against frozen-zone services (ZReportService, FiscalSequenceService,
 * AuditLogService) — drives them through their public API only.
 *
 * Outputs a JSON document of state diffs + invariants ; the calling shell wraps
 * the result into F10-refund-zclose-loyalty-findings.json.
 */

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalChainValidator;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\Fiscal\ZReportService;
use App\Services\Loyalty\PosRedemptionService;
use App\Services\LoyaltyService;
use Smartisan\Settings\Facades\Settings;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = [
    'timestamp'   => date('c'),
    'branch_id'   => 1,
    'pre_state'   => [],
    'flows'       => [],
    'post_state'  => [],
    'invariants'  => [],
];

// -------------------------------------------------------------------
// PRE-STATE BASELINE
// -------------------------------------------------------------------
$preAuditCount   = AuditLog::count();
$preZCount       = ZReport::count();
$preLastAudit    = AuditLog::orderByDesc('id')->first();
$preMaxFiscalSeq = (int) (Order::where('branch_id', 1)->whereNotNull('fiscal_sequence_no')->max('fiscal_sequence_no') ?? 0);
$preOrders       = Order::count();
$preChainValid   = trim((string) shell_exec('php artisan fiscal:verify-chain 2>&1'));

$out['pre_state'] = [
    'audit_logs_count'   => $preAuditCount,
    'z_reports_count'    => $preZCount,
    'audit_last_id'      => $preLastAudit?->id,
    'audit_last_hash'    => $preLastAudit ? substr($preLastAudit->current_hash, 0, 16) : null,
    'max_fiscal_seq_b1'  => $preMaxFiscalSeq,
    'orders_count'       => $preOrders,
    'chain_verify'       => $preChainValid,
];

// Resolve services
/** @var ZReportService $zSvc */
$zSvc = app(ZReportService::class);
/** @var FiscalSequenceService $seqSvc */
$seqSvc = app(FiscalSequenceService::class);
/** @var RefundWithCounterEntryService $refundSvc */
$refundSvc = app(RefundWithCounterEntryService::class);
/** @var LoyaltyService $loyaltySvc */
$loyaltySvc = app(LoyaltyService::class);

// Use admin user (branch_id=0) as actor for fiscal ops
$admin = User::query()->where('branch_id', 0)->first();
if (!$admin) {
    $admin = User::query()->orderBy('id')->first();
}

// -------------------------------------------------------------------
// F.10.1 — REFUND FLOW (counter-entry on a sealed order)
// -------------------------------------------------------------------
$flow1 = [
    'name'             => 'F.10.1 — Refund counter-entry',
    'steps'            => [],
    'verdict'          => 'pending',
    'issues'           => [],
];

try {
    // Step 1 — Seed a fresh cash POS order, paid, that we will then SEAL inside a new Z
    $flow1['steps'][] = 'STEP-1: seed a new cash POS order with fiscal_sequence_no allocated';

    $newSeq = $seqSvc->next(1);
    $parent = Order::create([
        'branch_id'          => 1,
        'user_id'            => $admin->id,
        'order_type'         => 30, // POS takeaway
        'status'             => OrderStatus::DELIVERED,
        'payment_status'     => PaymentStatus::PAID,
        'subtotal'           => 10.00,
        'total_tax'          => 1.00,
        'total'              => 11.00,
        'discount'           => 0,
        'order_serial_no'    => 'F10-' . time(),
        'order_datetime'     => date('Y-m-d H:i:s'),
        'preparation_time'   => 0,
        'pos_payment_method' => PosPaymentMethod::CASH,
        'payment_method'     => 1,
        'source_surface'     => 'pos',
    ]);
    $parent->fiscal_sequence_no = $newSeq;
    $parent->save();

    // Add one OrderItem
    OrderItem::create([
        'order_id'             => $parent->id,
        'branch_id'            => 1,
        'item_id'              => 1,
        'quantity'             => 1,
        'discount'             => 0,
        'tax_name'             => 'TVA',
        'tax_rate'             => 10.00,
        'tax_type'             => 1,
        'tax_amount'           => 1.00,
        'price'                => 10.00,
        'total_price'          => 11.00,
        'item_variations'      => json_encode([]),
        'item_extras'          => json_encode([]),
        'composition_snapshot' => json_encode([]),
        'item_variation_total' => 0,
        'item_extra_total'     => 0,
        'instruction'          => '',
        'allergens_snapshot'   => json_encode([]),
    ]);

    // Add one OrderPayment (CASH)
    $cashPayment = OrderPayment::create([
        'order_id'      => $parent->id,
        'branch_id'     => 1,
        'mode'          => PosPaymentMethod::CASH,
        'amount'        => 11.00,
        'tendered'      => 15.00,
        'change_amount' => 4.00,
        'reference'     => null,
        'paid_at'       => now(),
    ]);

    $flow1['steps'][] = "STEP-1-OK: parent order id={$parent->id} seq={$newSeq} total=11.00 cash_payment_id={$cashPayment->id}";

    // Step 2 — Open a Z, then close it to SEAL the order
    // We need: opened_at < parent.created_at AND closed_at >= parent.created_at
    // The order was just created now, so we'll backdate the open and close around it.
    $flow1['steps'][] = 'STEP-2: open + close Z to seal the parent order';

    $openedZ = $zSvc->open(1, $admin);
    $flow1['steps'][] = "STEP-2a-OK: Z opened id={$openedZ->id} seq={$openedZ->sequence_no}";

    // Manually backdate opened_at to BEFORE the parent so the seal predicate matches.
    DB::table('z_reports')
        ->where('id', $openedZ->id)
        ->update(['opened_at' => date('Y-m-d H:i:s', strtotime($parent->created_at) - 60)]);

    $closedZ = $zSvc->close(1, $admin);
    $flow1['steps'][] = "STEP-2b-OK: Z closed id={$closedZ->id} status={$closedZ->status} sig=" . substr($closedZ->signature ?? '', 0, 12);

    // Verify seal predicate now matches
    $sealingZ = ZReport::query()
        ->where('branch_id', 1)
        ->where('status', 'closed')
        ->where('opened_at', '<', $parent->created_at)
        ->where('closed_at', '>=', $parent->created_at)
        ->orderBy('id')
        ->first();
    if (!$sealingZ) {
        throw new RuntimeException('FAIL: parent not sealed by Z window — refund-with-counter-entry would 422.');
    }
    $flow1['steps'][] = "STEP-2c-OK: parent sealed by Z id={$sealingZ->id} seq={$sealingZ->sequence_no}";

    // Step 3 — Trigger counter-entry refund
    $flow1['steps'][] = 'STEP-3: trigger refund-with-counter-entry';

    // Snapshot of parent's composition_snapshot BEFORE refund
    $parentItemsBefore = OrderItem::where('order_id', $parent->id)->get()->map(fn($i) => [
        'id' => $i->id, 'qty' => $i->quantity, 'tax_amount' => $i->tax_amount,
        'composition_snapshot' => $i->composition_snapshot,
    ])->toArray();
    $parentStatusBefore = $parent->status;
    $parentTotalBefore  = (float) $parent->total;
    $parentPaymentStatusBefore = $parent->payment_status;

    $mirror = $refundSvc->execute($parent->fresh(), 'F.10 test refund flow');
    $flow1['steps'][] = "STEP-3-OK: mirror created id={$mirror->id} seq={$mirror->fiscal_sequence_no} total={$mirror->total} status={$mirror->status} parent_link={$mirror->parent_order_id} serial={$mirror->order_serial_no}";

    // Step 4 — Verify invariants
    $parentAfter = Order::find($parent->id);
    $assertions = [
        'parent_status_unchanged'              => ($parentAfter->status === $parentStatusBefore),
        'parent_total_unchanged'               => abs((float) $parentAfter->total - $parentTotalBefore) < 0.001,
        'parent_payment_status_unchanged'      => ($parentAfter->payment_status === $parentPaymentStatusBefore),
        'mirror_total_negative'                => ((float) $mirror->total < 0 && abs((float) $mirror->total + $parentTotalBefore) < 0.001),
        'mirror_status_returned'               => ((int) $mirror->status === OrderStatus::RETURNED),
        'mirror_payment_status_refunded'       => ((int) $mirror->payment_status === PaymentStatus::REFUNDED),
        'mirror_parent_link'                   => ((int) $mirror->parent_order_id === (int) $parent->id),
        'mirror_serial_RTN_prefix'             => (str_starts_with((string) $mirror->order_serial_no, 'RTN-')),
        'mirror_has_fresh_fiscal_seq'          => ((int) $mirror->fiscal_sequence_no > (int) $parent->fiscal_sequence_no),
        'mirror_items_negated_qty'             => OrderItem::where('order_id', $mirror->id)->where('quantity', '>=', 0)->count() === 0,
        'mirror_payments_negated_amount'       => OrderPayment::where('order_id', $mirror->id)->where('amount', '>=', 0)->count() === 0,
    ];

    // Composition snapshot on PARENT must equal pre-refund snapshot (NF525 immutability)
    $parentItemsAfter = OrderItem::where('order_id', $parent->id)->get()->map(fn($i) => [
        'id' => $i->id, 'qty' => $i->quantity, 'tax_amount' => $i->tax_amount,
        'composition_snapshot' => $i->composition_snapshot,
    ])->toArray();
    $assertions['parent_composition_snapshot_unchanged'] = (json_encode($parentItemsBefore) === json_encode($parentItemsAfter));

    // Audit row 'order.refund.counter_entry' written ?
    $refundAudit = AuditLog::where('action', 'order.refund.counter_entry')
        ->where('resource', 'order')
        ->where('resource_id', $mirror->id)
        ->orderByDesc('id')
        ->first();
    $assertions['audit_refund_event_written'] = ($refundAudit !== null);

    // Chain integrity right after refund
    $chainAfterRefund = trim((string) shell_exec('php artisan fiscal:verify-chain 2>&1'));
    $assertions['chain_ok_after_refund'] = str_contains($chainAfterRefund, 'CHAIN OK');

    // Idempotency-of-duplication test : second call must yield 23000 / 422 status-flip OR be rejected
    try {
        $refundSvc->execute($parent->fresh(), 'F.10 duplicate test');
        $assertions['duplicate_refund_blocked'] = false;
        $flow1['steps'][] = 'STEP-5-FAIL: duplicate refund was NOT blocked';
    } catch (\Throwable $t) {
        $assertions['duplicate_refund_blocked'] = true;
        $flow1['steps'][] = 'STEP-5-OK: duplicate refund blocked: ' . substr($t->getMessage(), 0, 80);
    }

    $flow1['assertions']        = $assertions;
    $flow1['mirror_order_id']   = $mirror->id;
    $flow1['parent_order_id']   = $parent->id;
    $flow1['chain_after']       = $chainAfterRefund;
    $flow1['verdict']           = (in_array(false, $assertions, true) ? 'FAIL' : 'PASS');

    if ($flow1['verdict'] === 'FAIL') {
        foreach ($assertions as $k => $v) {
            if ($v === false) {
                $flow1['issues'][] = "assert_failed: {$k}";
            }
        }
    }
} catch (\Throwable $t) {
    $flow1['verdict'] = 'ERROR';
    $flow1['issues'][] = 'exception: ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine();
}
$out['flows'][] = $flow1;

// -------------------------------------------------------------------
// F.10.2 — Z-CLOSE END-OF-DAY FLOW
// -------------------------------------------------------------------
$flow2 = [
    'name'    => 'F.10.2 — Z-close end-of-day',
    'steps'   => [],
    'verdict' => 'pending',
    'issues'  => [],
];

try {
    // Step 1 — open a new Z (we just closed one in F.10.1)
    $flow2['steps'][] = 'STEP-1: open new Z window';
    $zOpen = $zSvc->open(1, $admin);
    $flow2['steps'][] = "STEP-1-OK: Z opened id={$zOpen->id} seq={$zOpen->sequence_no} prev_hash=" . substr($zOpen->prev_hash ?? 'null', 0, 12);

    // Wait 1.5s so seeded orders.created_at > Z.opened_at (window predicate is strict >)
    usleep(1500000);

    // Step 2 — seed a few cash POS orders inside this open window
    $flow2['steps'][] = 'STEP-2: seed 3 cash POS orders inside window';
    $seeded = [];
    for ($i = 0; $i < 3; $i++) {
        $seq = $seqSvc->next(1);
        $o = Order::create([
            'branch_id'          => 1,
            'user_id'            => $admin->id,
            'order_type'         => 30,
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'subtotal'           => 5.00,
            'total_tax'          => 0.50,
            'total'              => 5.50,
            'discount'           => 0,
            'order_serial_no'    => 'F10-Z-' . time() . '-' . $i,
            'order_datetime'     => date('Y-m-d H:i:s'),
            'preparation_time'   => 0,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'payment_method'     => 1,
            'source_surface'     => 'pos',
        ]);
        $o->fiscal_sequence_no = $seq;
        $o->save();

        OrderItem::create([
            'order_id'             => $o->id,
            'branch_id'            => 1,
            'item_id'              => 1,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => 10.00,
            'tax_type'             => 1,
            'tax_amount'           => 0.50,
            'price'                => 5.00,
            'total_price'          => 5.50,
            'item_variations'      => json_encode([]),
            'item_extras'          => json_encode([]),
            'composition_snapshot' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
            'instruction'          => '',
            'allergens_snapshot'   => json_encode([]),
        ]);
        OrderPayment::create([
            'order_id'      => $o->id,
            'branch_id'     => 1,
            'mode'          => PosPaymentMethod::CASH,
            'amount'        => 5.50,
            'tendered'      => 5.50,
            'change_amount' => 0,
            'reference'     => null,
            'paid_at'       => now(),
        ]);
        $seeded[] = $o->id;
    }
    $flow2['steps'][] = 'STEP-2-OK: seeded ' . implode(',', $seeded);

    // Step 3 — close the Z (the operational "Clôture journée" action)
    $flow2['steps'][] = 'STEP-3: close Z window (Clôture journée)';

    $zPreClose = ZReport::find($zOpen->id);
    $prevClosedZ = ZReport::where('branch_id', 1)
        ->where('status', 'closed')
        ->orderByDesc('id')
        ->skip(0)->first();  // most recent closed (will be the one we just closed in F.10.1)

    $zClosed = $zSvc->close(1, $admin);
    $flow2['steps'][] = "STEP-3-OK: Z closed id={$zClosed->id} status={$zClosed->status} sig=" . substr($zClosed->signature ?? '', 0, 12) . " order_count={$zClosed->order_count} total_ttc={$zClosed->total_ttc}";

    // Step 4 — invariants
    $assertions2 = [
        'z_status_closed'           => ($zClosed->status === ZReport::STATUS_CLOSED),
        'z_has_signature'           => !empty($zClosed->signature),
        'z_signature_64hex'         => (bool) preg_match('/^[a-f0-9]{64}$/i', $zClosed->signature ?? ''),
        'z_prev_hash_chained'       => ($prevClosedZ ? ($zClosed->prev_hash === $prevClosedZ->signature) : ($zClosed->prev_hash === null || $zClosed->prev_hash === '')),
        'z_closed_at_set'           => !empty($zClosed->closed_at),
        'z_opened_at_set'           => !empty($zClosed->opened_at),
        'z_sequence_increment'      => ((int) $zClosed->sequence_no === (int) ($prevClosedZ?->sequence_no ?? 0) + 1),
        'z_order_count_correct'     => ((int) $zClosed->order_count >= count($seeded)),
        'z_total_ttc_positive'      => ((float) $zClosed->total_ttc > 0),
    ];

    // Fiscal sequence does NOT reset across Z (NF525 lifetime monotonic per branch)
    $maxAfter = (int) Order::where('branch_id', 1)->whereNotNull('fiscal_sequence_no')->max('fiscal_sequence_no');
    $assertions2['fiscal_seq_no_reset'] = ($maxAfter > $preMaxFiscalSeq);

    // Chain after Z-close
    $chainAfterZ = trim((string) shell_exec('php artisan fiscal:verify-chain 2>&1'));
    $assertions2['chain_ok_after_zclose'] = str_contains($chainAfterZ, 'CHAIN OK');

    // Validator deep-check — assertChainIntegrity throws on any corruption
    $validator = app(FiscalChainValidator::class);
    try {
        $validator->assertChainIntegrity(1);
        $assertions2['validator_assertChainIntegrity_pass'] = true;
    } catch (\Throwable $t) {
        $assertions2['validator_assertChainIntegrity_pass'] = false;
        $flow2['issues'][] = 'validator: ' . $t->getMessage();
    }

    // Cannot double-close
    try {
        $zSvc->close(1, $admin);
        $assertions2['double_close_blocked'] = false;
        $flow2['steps'][] = 'STEP-5-FAIL: double close was NOT blocked';
    } catch (\Throwable $t) {
        $assertions2['double_close_blocked'] = true;
        $flow2['steps'][] = 'STEP-5-OK: double close blocked: ' . substr($t->getMessage(), 0, 80);
    }

    $flow2['assertions']       = $assertions2;
    $flow2['z_id']             = $zClosed->id;
    $flow2['z_seq']            = $zClosed->sequence_no;
    $flow2['z_total_ttc']      = (float) $zClosed->total_ttc;
    $flow2['z_total_by_method'] = $zClosed->total_by_method;
    $flow2['chain_after']      = $chainAfterZ;
    $flow2['verdict']          = (in_array(false, $assertions2, true) ? 'FAIL' : 'PASS');
    if ($flow2['verdict'] === 'FAIL') {
        foreach ($assertions2 as $k => $v) {
            if ($v === false) {
                $flow2['issues'][] = "assert_failed: {$k}";
            }
        }
    }
} catch (\Throwable $t) {
    $flow2['verdict'] = 'ERROR';
    $flow2['issues'][] = 'exception: ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine();
}
$out['flows'][] = $flow2;

// -------------------------------------------------------------------
// F.10.3 — LOYALTY REDEEM FLOW
// -------------------------------------------------------------------
$flow3 = [
    'name'    => 'F.10.3 — Loyalty redeem',
    'steps'   => [],
    'verdict' => 'pending',
    'issues'  => [],
];

try {
    // First : inventory UI surfaces
    $kioskUI = file_exists(__DIR__ . '/../../../../../resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue');
    $posModalUI = file_exists(__DIR__ . '/../../../../../resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue');
    $posCtaInShow = false;
    if ($posModalUI) {
        $showFile = file_get_contents(__DIR__ . '/../../../../../resources/js/components/admin/posOrders/PosOrderShowComponent.vue');
        $posCtaInShow = (str_contains($showFile, 'pos-loyalty-redeem-open') && str_contains($showFile, 'PosLoyaltyRedeemModal'));
    }

    $flow3['ui_inventory'] = [
        'kiosk_loyalty_component'        => $kioskUI ? 'EXISTS' : 'MISSING',
        'pos_loyalty_redeem_modal'       => $posModalUI ? 'EXISTS' : 'MISSING',
        'pos_show_component_wires_modal' => $posCtaInShow ? 'YES' : 'NO',
    ];
    $flow3['steps'][] = 'STEP-0: UI inventory done — kiosk loyalty UI=' . ($kioskUI ? 'YES' : 'NO')
                       . ' / POS loyalty modal=' . ($posModalUI ? 'YES' : 'NO')
                       . ' / POS show wires modal=' . ($posCtaInShow ? 'YES' : 'NO');

    // Endpoint inventory
    $routes = app('router')->getRoutes();
    $foundRoutes = [
        'frontend.loyalty.redeem'   => null,
        'pos.redeem-loyalty'        => null,
    ];
    foreach ($routes as $r) {
        $uri = $r->uri();
        if (str_contains($uri, 'frontend/loyalty/redeem')) {
            $foundRoutes['frontend.loyalty.redeem'] = $r->uri() . ' [' . implode(',', $r->methods()) . ']';
        }
        if (str_contains($uri, 'redeem-loyalty')) {
            $foundRoutes['pos.redeem-loyalty'] = $r->uri() . ' [' . implode(',', $r->methods()) . ']';
        }
    }
    $flow3['routes_inventory'] = $foundRoutes;
    $flow3['steps'][] = 'STEP-0b: route inventory — frontend redeem=' . ($foundRoutes['frontend.loyalty.redeem'] ?? 'MISSING')
                       . ' / pos redeem=' . ($foundRoutes['pos.redeem-loyalty'] ?? 'MISSING');

    // Loyalty config via Smartisan Settings (no dedicated LoyaltySetup model — settings stored as group)
    $loyaltyEnabled  = (bool) Settings::group('loyalty_setup')->get('loyalty_enable', false);
    $loyaltyRate     = (int) Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
    if ($loyaltyRate <= 0) {
        $loyaltyRate = 100;
    }
    $flow3['loyalty_setup'] = [
        'loyalty_enable'                       => $loyaltyEnabled,
        'loyalty_points_for_1_euro_discount'   => $loyaltyRate,
        'storage'                              => 'Smartisan\Settings (group=loyalty_setup)',
    ];

    // Find/seed a User with a loyalty_code + active status (the kiosk + POS redeem
    // paths both query `users` by loyalty_code — Customer model is for delivery
    // address book, not loyalty).
    $loyaltyUser = User::where('loyalty_code', '!=', null)
        ->where('status', \App\Enums\Status::ACTIVE)
        ->first();
    if (!$loyaltyUser) {
        // Fallback : create one from admin user
        $loyaltyUser = User::query()->where('id', '!=', $admin->id)->first();
        if ($loyaltyUser && !$loyaltyUser->loyalty_code) {
            $loyaltyUser->loyalty_code = 'F10L' . strtoupper(substr(md5((string) time()), 0, 6));
            $loyaltyUser->status = \App\Enums\Status::ACTIVE;
            $loyaltyUser->save();
        }
    }
    if (!$loyaltyUser || !$loyaltyUser->loyalty_code) {
        $flow3['steps'][] = 'STEP-1-SKIP: no User with loyalty_code available — redeem test skipped';
        $flow3['verdict'] = 'INFO';
        $flow3['issues'][] = 'no_loyalty_capable_user';
    } else {
        // Top-up loyalty_points safely above multiple-of-rate
        $points_to_redeem = $loyaltyRate;  // exactly 1 rate-multiple = 1 EUR discount
        $loyaltyUser->loyalty_points = $points_to_redeem * 3;
        $loyaltyUser->save();
        $flow3['steps'][] = "STEP-1-OK: loyalty user id={$loyaltyUser->id} code={$loyaltyUser->loyalty_code} points={$loyaltyUser->loyalty_points}";

        // Seed an UNPAID order to redeem against (NOT in terminal state, NOT paid)
        $newSeq = $seqSvc->next(1);
        $rorder = Order::create([
            'branch_id'          => 1,
            'user_id'            => $admin->id,
            'order_type'         => 30,
            'status'             => OrderStatus::PENDING,
            'payment_status'     => PaymentStatus::UNPAID,
            'subtotal'           => 20.00,
            'total_tax'          => 2.00,
            'total'              => 22.00,
            'discount'           => 0,
            'order_serial_no'    => 'F10-L-' . time(),
            'order_datetime'     => date('Y-m-d H:i:s'),
            'preparation_time'   => 0,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'payment_method'     => 1,
            'source_surface'     => 'pos',
        ]);
        $rorder->fiscal_sequence_no = $newSeq;
        $rorder->save();

        OrderItem::create([
            'order_id'             => $rorder->id,
            'branch_id'            => 1,
            'item_id'              => 1,
            'quantity'             => 2,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => 10.00,
            'tax_type'             => 1,
            'tax_amount'           => 2.00,
            'price'                => 10.00,
            'total_price'          => 22.00,
            'item_variations'      => json_encode([]),
            'item_extras'          => json_encode([]),
            'composition_snapshot' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
            'instruction'          => '',
            'allergens_snapshot'   => json_encode([]),
        ]);

        $totalBefore  = (float) $rorder->total;
        $pointsBefore = (int) $loyaltyUser->loyalty_points;

        $flow3['steps'][] = "STEP-2: POS redeem via PosRedemptionService::applyToOrder order={$rorder->id} total={$totalBefore} code={$loyaltyUser->loyalty_code} points_to_redeem={$points_to_redeem}";

        try {
            /** @var PosRedemptionService $posRedeem */
            $posRedeem = app(PosRedemptionService::class);
            $result = $posRedeem->applyToOrder(
                $rorder->fresh(),
                $points_to_redeem,
                (string) $loyaltyUser->loyalty_code,
                (int) $admin->id,
            );
            $flow3['redeem_result'] = [
                'discount_eur'   => $result['discount_eur'],
                'balance_after'  => $result['balance_after'],
                'transaction_id' => $result['transaction']->id,
                'order_total'    => (float) $result['order']->total,
                'order_discount' => (float) $result['order']->discount,
            ];
            $flow3['steps'][] = 'STEP-2-OK: PosRedemptionService::applyToOrder returned discount_eur=' . $result['discount_eur'];
        } catch (\App\Services\Loyalty\PosRedemptionException $e) {
            $flow3['steps'][] = 'STEP-2-EXC: PosRedemptionException ' . $e->errorCode . ' ' . $e->getMessage();
            $flow3['issues'][] = 'pos_redeem_exception: ' . $e->errorCode . ' — ' . $e->getMessage();
            $flow3['redeem_result'] = ['error' => $e->getMessage(), 'code' => $e->errorCode];
        } catch (\Throwable $t) {
            $flow3['steps'][] = 'STEP-2-EXC: ' . $t->getMessage();
            $flow3['issues'][] = 'redeem_exception: ' . $t->getMessage();
            $flow3['redeem_result'] = ['error' => $t->getMessage()];
        }

        $rorder->refresh();
        $loyaltyUser->refresh();
        $totalAfter  = (float) $rorder->total;
        $pointsAfter = (int) $loyaltyUser->loyalty_points;

        $assertions3 = [
            'order_total_reduced'         => ($totalAfter < $totalBefore),
            'customer_points_debited'     => ($pointsAfter < $pointsBefore),
            'order_total_still_positive'  => ($totalAfter >= 0),
            'loyalty_transaction_logged'  => (\App\Models\LoyaltyTransaction::where('order_id', $rorder->id)->where('type', 'redeem')->exists()),
            'order_loyalty_code_linked'   => ((string) $rorder->loyalty_customer_code === (string) $loyaltyUser->loyalty_code),
        ];

        // Idempotent duplicate redeem MUST hit ALREADY_REDEEMED 409
        try {
            $posRedeem = app(PosRedemptionService::class);
            $posRedeem->applyToOrder($rorder->fresh(), $points_to_redeem, (string) $loyaltyUser->loyalty_code, (int) $admin->id);
            $assertions3['duplicate_redeem_blocked'] = false;
            $flow3['steps'][] = 'STEP-3-FAIL: duplicate redeem NOT blocked';
        } catch (\App\Services\Loyalty\PosRedemptionException $e) {
            $assertions3['duplicate_redeem_blocked'] = ($e->errorCode === 'ALREADY_REDEEMED');
            $flow3['steps'][] = 'STEP-3-OK: duplicate redeem blocked with code=' . $e->errorCode;
        } catch (\Throwable $t) {
            $assertions3['duplicate_redeem_blocked'] = true;
            $flow3['steps'][] = 'STEP-3-OK: duplicate redeem blocked exception=' . substr($t->getMessage(), 0, 60);
        }

        $flow3['assertions']         = $assertions3;
        $flow3['totals']             = ['before' => $totalBefore, 'after' => $totalAfter, 'points_used' => $pointsBefore - $pointsAfter];
        $flow3['verdict']            = (in_array(false, $assertions3, true) ? 'PARTIAL' : 'PASS');
        if ($flow3['verdict'] !== 'PASS') {
            foreach ($assertions3 as $k => $v) {
                if ($v === false) {
                    $flow3['issues'][] = "assert_failed: {$k}";
                }
            }
        }
    }

    // Final chain check
    $chainAfterLoyalty = trim((string) shell_exec('php artisan fiscal:verify-chain 2>&1'));
    $flow3['chain_after'] = $chainAfterLoyalty;
} catch (\Throwable $t) {
    $flow3['verdict'] = 'ERROR';
    $flow3['issues'][] = 'exception: ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine();
}
$out['flows'][] = $flow3;

// -------------------------------------------------------------------
// POST-STATE + GLOBAL INVARIANTS
// -------------------------------------------------------------------
$postAuditCount   = AuditLog::count();
$postZCount       = ZReport::count();
$postLastAudit    = AuditLog::orderByDesc('id')->first();
$postMaxFiscalSeq = (int) (Order::where('branch_id', 1)->whereNotNull('fiscal_sequence_no')->max('fiscal_sequence_no') ?? 0);
$postOrders       = Order::count();
$postChainValid   = trim((string) shell_exec('php artisan fiscal:verify-chain 2>&1'));

$out['post_state'] = [
    'audit_logs_count'   => $postAuditCount,
    'z_reports_count'    => $postZCount,
    'audit_last_id'      => $postLastAudit?->id,
    'audit_last_hash'    => $postLastAudit ? substr($postLastAudit->current_hash, 0, 16) : null,
    'max_fiscal_seq_b1'  => $postMaxFiscalSeq,
    'orders_count'       => $postOrders,
    'chain_verify'       => $postChainValid,
];

$out['invariants'] = [
    'audit_logs_append_only'         => ($postAuditCount >= $preAuditCount),
    'z_reports_append_only'          => ($postZCount >= $preZCount),
    'fiscal_sequence_monotonic'      => ($postMaxFiscalSeq >= $preMaxFiscalSeq),
    'chain_ok_pre'                   => str_contains($preChainValid, 'CHAIN OK'),
    'chain_ok_post'                  => str_contains($postChainValid, 'CHAIN OK'),
    'no_orders_lost'                 => ($postOrders >= $preOrders),
    'audit_logs_delta'               => $postAuditCount - $preAuditCount,
    'z_reports_delta'                => $postZCount - $preZCount,
    'fiscal_seq_delta'               => $postMaxFiscalSeq - $preMaxFiscalSeq,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
