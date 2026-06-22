// [test-e2e fix A-006 round-1 2026-05-21] Seed a kiosk PENDING_COUNTER
// cash order so the Wave A spec can exercise the SSOT confirm POST path
// end-to-end without depending on stale DB rows or destructive-once
// behaviour (any earlier solo run will have PAID the order, leaving the
// next run with 422 "not a pending kiosk counter payment").
//
// The seeded row satisfies BOTH:
//   1. /counter-collect/pending list filter (source_surface=kiosk +
//      order_type IN (KIOSK, TAKEAWAY) + payment_status=PENDING_COUNTER).
//   2. PaymentService::assertCounterDeferredOrder invariants
//      (payment_method=CASH_ON_DELIVERY=1 + pos_payment_method=
//      COUNTER_DEFERRED=6 + source_surface=kiosk).
//
// Token prefix `WAVE-X-A-CASH-` makes the iter15:cleanup-test-orders
// reaper sweep these rows between runs.

const path = require('path');
const { execFileSync } = require('child_process');

const repoRoot = path.resolve(__dirname, '../../..');

/**
 * Seed a fresh kiosk-cash PENDING_COUNTER order. Returns { id, total, token }.
 * Throws if tinker fails so the caller surfaces a real test failure (no
 * silent skip).
 */
function seedKioskCashPendingOrder({ total = 7.5, branchId = 1 } = {}) {
    const token = `WAVE-X-A-CASH-${Date.now()}`;
    const script = `
        $branch = \\App\\Models\\Branch::find(${branchId});
        if (! $branch) { echo 'NO_BRANCH'; return; }
        $user = \\App\\Models\\User::where('email', 'admin@lecayenne.fr')->first();
        if (! $user) { echo 'NO_USER'; return; }

        $order = new \\App\\Models\\Order();
        $order->branch_id = ${branchId};
        $order->user_id = $user->id;
        $order->order_type = \\App\\Enums\\OrderType::KIOSK;
        $order->source_surface = 'kiosk';
        $order->payment_method = \\App\\Enums\\PaymentGateway::CASH_ON_DELIVERY;
        $order->pos_payment_method = \\App\\Enums\\PosPaymentMethod::COUNTER_DEFERRED;
        $order->payment_status = \\App\\Enums\\PaymentStatus::PENDING_COUNTER;
        $order->status = \\App\\Enums\\OrderStatus::PENDING;
        $order->subtotal = ${total};
        $order->total = ${total};
        $order->total_tax = 0;
        $order->discount = 0;
        $order->delivery_charge = 0;
        $order->token = '${token}';
        $order->order_serial_no = '${token}';
        $order->order_datetime = now();
        $order->queue_number = (string) (9000 + (int) (microtime(true) * 10 % 999));
        $order->saveQuietly();

        echo json_encode([
            'id' => $order->id,
            'total' => (float) $order->total,
            'token' => $order->token,
        ]);
    `;

    const out = execFileSync('php', ['artisan', 'tinker', '--execute', script], {
        cwd: repoRoot,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        timeout: 30_000,
    });

    // Tinker prints the script's echo on the last line. Some Laravel versions
    // prefix with a `=>` line; tolerate both.
    const lines = out.trim().split(/\r?\n/).filter((l) => l.trim().length > 0);
    const lastLine = lines[lines.length - 1] || '';
    const jsonStart = lastLine.indexOf('{');
    if (jsonStart < 0) {
        throw new Error(`seedKioskCashPendingOrder: unexpected tinker output: ${out.slice(0, 500)}`);
    }
    const payload = JSON.parse(lastLine.slice(jsonStart));
    if (!payload.id) {
        throw new Error(`seedKioskCashPendingOrder: tinker did not return an order id (out=${out.slice(0, 500)})`);
    }
    return payload;
}

module.exports = { seedKioskCashPendingOrder };
