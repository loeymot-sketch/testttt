<?php

namespace App\Console\Commands\Stripe;

use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\OrderPaymentStatusChanged;
use App\Models\CapturePaymentNotification;
use App\Models\Order;
use App\Services\Fiscal\AuditLogService;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [GOAL-K2-HEAL-05 2026-05-24] Phase K.8 K8-F-01 P1.
 *
 * Browser-death drain for Stripe-staged CapturePaymentNotification rows.
 *
 * Stripe::handleWebhook (charge.succeeded) writes a CPN row carrying
 * (order_id, token) and immediately returns 200 — the Order.payment_status
 * flip is deferred to Stripe::success(), which runs only when the
 * customer's browser visits payment.success. If the kiosk crashes /
 * customer walks away / network drops between the charge and the
 * redirect, the CPN row strands in the table and the Order stays UNPAID
 * even though Stripe successfully charged the card.
 *
 * Pre-heal: there was NO recovery path. The DLQ retry lane
 * (foodking:webhook:retry-failed) only re-fires the webhook handler,
 * which already wrote the CPN — replay is a no-op for this state. The
 * stranded CPN sat forever; the order never flipped to PAID; the kitchen
 * never saw it; the customer was silently charged but never served.
 *
 * Post-heal: this drain cron runs every 5 minutes and, for any CPN row
 * older than --older-than-minutes (default 5) whose Order is still UNPAID,
 * delegates to PaymentService::payment() through the documented
 * `payment.service.allow_direct_call` opt-in flag. PaymentService writes
 * the Transaction row and flips payment_status=PAID under the same
 * envelope as Stripe::success would — there is no second code path to
 * keep in sync. On success we DELETE the CPN row (matching the existing
 * flush semantics at Stripe.php:81/126/303) so the row's presence
 * remains the authoritative "stranded" signal.
 *
 * Scope:
 *   - Stripe-only: filters orders with payment_method=PaymentGateway::CARD.
 *     Other gateways with CPN parity (Senangpay, Credit) are NOT touched
 *     because the K.8 finding is Stripe-specific and their browser-flush
 *     semantics may differ (separate finding required if drain is needed).
 *   - Already-PAID orders are skipped (idempotent for retries).
 *   - Missing orders / non-Stripe orders → CPN deleted as dead row +
 *     audit log entry for forensic.
 *
 * NF525:
 *   - audit_logs entry `order.payment.drained_by_cron` extends the HMAC
 *     chain via AuditLogService::write() (sole authorised writer).
 *   - PaymentService::payment() itself emits no audit row, so this drain
 *     is the only forensic trail for these events.
 *
 * Frozen zones touched: NONE.
 *   - Stripe.php read-only.
 *   - AuditLogService::write() called via public single-array API only.
 *   - PaymentService::payment() called via documented opt-in flag.
 *
 * Production prerequisite:
 *   - `payment.pilot_restrict.allowed_methods` must include `'stripe'` AND
 *     `payment.stripe.activation_guard.activation_gate_cleared = true`.
 *     Until that gate clears, the V1 LOCAL Le Cayenne pilot blocks Stripe
 *     end-to-end and the drain logs `ValidationException` per stranded row
 *     by design — a stranded CPN in a Stripe-disabled environment is a
 *     real fault (out-of-band webhook, manual import) that SHOULD page
 *     ops rather than be silently absorbed. Once Stripe is added to the
 *     pilot allowlist (post-activation gate) the drain auto-activates
 *     without any code change.
 *
 * Sentinel: tests/Feature/Stripe/DrainStrandedCpnCronSentinel.php
 *   - Asserts Kernel.php registers `stripe:drain-stranded-cpn` every 5 min Europe/Paris.
 *   - Behaviour: create stranded CPN, run command, verify Order PAID + audit row + CPN gone.
 */
class DrainStrandedCpnCommand extends Command
{
    protected $signature = 'stripe:drain-stranded-cpn
        {--older-than-minutes=5 : Minimum CPN age (minutes) before it qualifies as stranded}
        {--dry-run : Report what would be drained without making changes}
        {--limit=100 : Maximum number of CPN rows to process per run}';

    protected $description = 'Drain stranded Stripe CapturePaymentNotification rows whose Order.payment_status was never flipped because the customer browser did not visit payment.success.';

    public function handle(AuditLogService $auditLog, PaymentService $paymentService): int
    {
        $minutes = max(1, (int) $this->option('older-than-minutes'));
        $dryRun  = (bool) $this->option('dry-run');
        $limit   = max(1, (int) $this->option('limit'));

        $cutoff = now()->subMinutes($minutes);

        // CapturePaymentNotification has NO primary key column (see
        // 2023_03_23_170303_create_capture_payment_notifications_table.php
        // — only order_id, token, created_at). Iterate by composite
        // (order_id, token) — order_id alone is unique because Stripe::payment
        // and the webhook both DELETE prior rows for the same order_id
        // before insert, so duplicates by order_id are impossible.
        $stale = CapturePaymentNotification::query()
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $this->info(sprintf(
            '[drain-cpn] Found %d stale CPN(s) older than %d min',
            $stale->count(),
            $minutes
        ));

        if ($stale->isEmpty()) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            foreach ($stale as $cpn) {
                $this->line(sprintf(
                    '  [dry-run] would inspect CPN for order #%d (token=%s, age=%d min)',
                    $cpn->order_id,
                    substr((string) $cpn->token, 0, 12) . '...',
                    $cpn->created_at->diffInMinutes(now())
                ));
            }
            return self::SUCCESS;
        }

        $drained        = 0;
        $skippedPaid    = 0;
        $skippedNoOrder = 0;
        $skippedNonStripe = 0;
        $failed         = 0;

        foreach ($stale as $cpn) {
            try {
                $outcome = DB::transaction(function () use ($cpn, $auditLog, $paymentService): string {
                    /** @var Order|null $order */
                    $order = Order::query()
                        ->whereKey($cpn->order_id)
                        ->lockForUpdate()
                        ->first();

                    if ($order === null) {
                        // Dead CPN — order gone (probably manually deleted).
                        // Remove the CPN row + audit the cleanup so it
                        // doesn't return on subsequent runs.
                        $this->deleteCpn($cpn);
                        $this->writeDrainAudit($auditLog, null, $cpn, 'order_not_found');
                        return 'order_not_found';
                    }

                    if ((int) $order->payment_status === PaymentStatus::PAID) {
                        // Already paid (browser arrived after all, or earlier
                        // drain succeeded). Just clean the residue row.
                        $this->deleteCpn($cpn);
                        return 'already_paid';
                    }

                    if ((int) $order->payment_method !== PaymentGateway::CARD) {
                        // Out of scope: K.8 K8-F-01 is Stripe (CARD) only.
                        // Leave the CPN row in place — a future gateway-specific
                        // drain command (Senangpay / Credit) will own its lane.
                        return 'non_stripe_skip';
                    }

                    $oldStatus = (int) $order->payment_status;

                    // PaymentService::payment() is gated by assertGatewayContext()
                    // which inspects the backtrace for a PaymentAbstract subclass.
                    // From an artisan command no such frame exists, so we use the
                    // documented opt-in flag (PaymentService.php:693-697). The
                    // flag is auto-cleared on assert so a leak across calls is
                    // impossible — one bind == one call.
                    app()->bind('payment.service.allow_direct_call', fn () => true);

                    // Writes the Transaction row (slug='stripe') and flips
                    // payment_status=PAID. Mirrors Stripe::success exactly so
                    // we are bit-identical to a browser-completed flush.
                    $paymentService->payment($order, 'stripe', (string) $cpn->token);

                    $order->refresh();

                    $this->writeDrainAudit($auditLog, $order, $cpn, 'flipped');

                    // Remove the residue CPN row (mirrors Stripe::success line 126).
                    $this->deleteCpn($cpn);

                    // Broadcast the transition so KDS / OSS / suivi UIs see the
                    // flip without waiting for a poll cycle. DispatchableAfterCommit
                    // defers until this DB::transaction commits — a rollback would
                    // discard the dispatch.
                    OrderPaymentStatusChanged::dispatch(
                        $order,
                        $oldStatus,
                        (int) $order->payment_status
                    );

                    return 'flipped';
                });

                switch ($outcome) {
                    case 'flipped':         $drained++; break;
                    case 'already_paid':    $skippedPaid++; break;
                    case 'order_not_found': $skippedNoOrder++; break;
                    case 'non_stripe_skip': $skippedNonStripe++; break;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::channel('fiscal')->error('stripe.drain_cpn.failed', [
                    'event'    => 'stripe_drain_cpn_failed',
                    'order_id' => (int) $cpn->order_id,
                    'message'  => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            '[drain-cpn] Drained: %d | already-paid: %d | order-missing: %d | non-stripe: %d | failed: %d',
            $drained,
            $skippedPaid,
            $skippedNoOrder,
            $skippedNonStripe,
            $failed
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The CPN table has no primary key, so we delete by the composite
     * (order_id, token) that uniquely identifies the row written by the
     * webhook / payment() flow.
     */
    private function deleteCpn(CapturePaymentNotification $cpn): void
    {
        DB::table('capture_payment_notifications')
            ->where('order_id', $cpn->order_id)
            ->where('token', $cpn->token)
            ->delete();
    }

    /**
     * Append a single NF525-chained audit row for forensic trail. Branch_id
     * is mandatory (AuditLogService rejects null) — fall back to 0 (system
     * chain) when the order has been removed.
     */
    private function writeDrainAudit(
        AuditLogService $auditLog,
        ?Order $order,
        CapturePaymentNotification $cpn,
        string $outcome
    ): void {
        $auditLog->write([
            'branch_id'   => $order !== null ? (int) ($order->branch_id ?? 0) : 0,
            'user_id'     => null,
            'action'      => 'order.payment.drained_by_cron',
            'resource'    => 'order',
            'resource_id' => $order !== null ? (int) $order->id : (int) $cpn->order_id,
            'payload'     => [
                'reason'           => 'browser_death_window',
                'outcome'          => $outcome,
                'token_prefix'     => substr((string) $cpn->token, 0, 12),
                'cpn_created_at'   => $cpn->created_at?->toIso8601String(),
                'stranded_minutes' => $cpn->created_at ? (int) $cpn->created_at->diffInMinutes(now()) : null,
                'gateway'          => 'stripe',
            ],
        ]);
    }
}
