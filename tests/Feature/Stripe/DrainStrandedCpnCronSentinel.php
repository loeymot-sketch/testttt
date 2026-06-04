<?php

namespace Tests\Feature\Stripe;

use App\Console\Commands\Stripe\DrainStrandedCpnCommand;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CapturePaymentNotification;
use App\Models\GatewayOption;
use App\Models\Order;
use App\Models\PaymentGateway as PaymentGatewayModel;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL-K2-HEAL-05 2026-05-24] Sentinel: stripe:drain-stranded-cpn cron + behavior.
 *
 * Phase K.8 K8-F-01 P1: Stripe charge.succeeded webhook stages a
 * CapturePaymentNotification row but the Order.payment_status flip is
 * deferred to Stripe::success() which only fires when the customer
 * browser visits payment.success. Browser-death (kiosk crash, walk-away,
 * network drop) strands the CPN and leaves the order PENDING despite
 * Stripe having charged the card.
 *
 * This sentinel pins the operational contract of the drain cron:
 *
 *  (1) The artisan command class exists and is registered.
 *  (2) Kernel.php registers the lane every 5 minutes Europe/Paris with
 *      onOneServer + withoutOverlapping + runInBackground defenses and a
 *      dedicated stripe-drain-cpn.log capture.
 *  (3) Exactly ONE lane is registered (drift / duplicate guard).
 *  (4) Behavior — happy path: stranded CPN (>5 min old) + UNPAID order →
 *      command flips order to PAID, creates Transaction row (slug stripe),
 *      writes NF525 audit_logs entry `order.payment.drained_by_cron`,
 *      DELETEs the CPN row.
 *  (5) Behavior — idempotency: re-running the command after a successful
 *      drain is a no-op (CPN already gone), no double-audit, no double-
 *      Transaction.
 *  (6) Behavior — dry-run does NOT mutate.
 *  (7) Behavior — fresh CPN (< 5 min) is NOT drained (in-flight browser
 *      protection window).
 *
 * @group stripe
 * @group cron
 * @group nf525
 */
class DrainStrandedCpnCronSentinel extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureInstalledFlag();
        $this->seedStripeGateway();

        // Simulate the post-V1.0.X Stripe-activation environment the drain
        // is designed to serve. V1 LOCAL Le Cayenne configures
        // payment.pilot_restrict.allowed_methods=['credit'] which structurally
        // blocks Stripe through PaymentService::payment(). The drain command
        // operates against the post-activation pilot extension.
        Config::set('payment.pilot_restrict.allowed_methods', ['credit', 'stripe']);
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }

        parent::tearDown();
    }

    /**
     * The Stripe gateway row is required because PaymentService::payment()
     * does not directly read it, but mirroring Stripe::success's setup keeps
     * the sentinel realistic and protects against future couplings.
     */
    private function seedStripeGateway(): void
    {
        if (PaymentGatewayModel::query()->where('slug', 'stripe')->exists()) {
            return;
        }

        $gateway = PaymentGatewayModel::create([
            'name'   => 'Stripe',
            'slug'   => 'stripe',
            'misc'   => json_encode([]),
            'status' => 1,
        ]);

        GatewayOption::create([
            'model_id'   => $gateway->id,
            'model_type' => PaymentGatewayModel::class,
            'option'     => 'stripe_secret',
            'value'      => 'sk_test_stub',
            'type'       => 1,
            'activities' => '',
        ]);
    }

    private function ensureInstalledFlag(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }
        touch(storage_path('installed'));
        $this->createdInstalledFlag = true;
    }

    private function findDrainEvent(): ?\Illuminate\Console\Scheduling\Event
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'stripe:drain-stranded-cpn')) {
                return $event;
            }
        }

        return null;
    }

    // ---- Schedule wiring sentinels --------------------------------------

    public function test_command_class_exists_and_is_registered_in_artisan(): void
    {
        $this->assertTrue(
            class_exists(DrainStrandedCpnCommand::class),
            'DrainStrandedCpnCommand must exist (GOAL-K2-HEAL-05).'
        );

        $signatures = collect(Artisan::all())->keys()->all();
        $this->assertContains(
            'stripe:drain-stranded-cpn',
            $signatures,
            'stripe:drain-stranded-cpn must be registered in Artisan command list. '
            . 'If this fails, the Kernel::commands() auto-loader has been disabled '
            . 'or the namespace moved out of App\\Console\\Commands.'
        );
    }

    public function test_kernel_registers_drain_lane_every_5_min_paris(): void
    {
        $event = $this->findDrainEvent();

        $this->assertNotNull(
            $event,
            'stripe:drain-stranded-cpn must be scheduled in app/Console/Kernel.php (K2-HEAL-05). '
            . 'Without this lane, the Stripe browser-death window leaves stranded CPN + '
            . 'Stripe-charged-but-order-UNPAID forever. See Phase K.8 K8-F-01 P1 finding.'
        );

        $this->assertSame(
            '*/5 * * * *',
            (string) $event->expression,
            "Lane must run every 5 minutes. Got: '{$event->expression}'."
        );

        $this->assertSame(
            'Europe/Paris',
            (string) $event->timezone,
            'Timezone must be Europe/Paris for parity with the NF525 quartet lanes.'
        );
    }

    public function test_lane_has_concurrency_defenses(): void
    {
        $event = $this->findDrainEvent();
        $this->assertNotNull($event, 'Pre-condition: drain lane must exist.');

        $this->assertNotEmpty(
            $event->mutex,
            'withoutOverlapping required: a slow Stripe API call in one run must not race the '
            . 'next every-5-min re-fire (would double-charge audit chain on an already-drained row).'
        );

        $this->assertTrue(
            (bool) $event->onOneServer,
            'onOneServer required: V2 horizontal scale must NEVER double-drain the same CPN.'
        );

        $this->assertTrue(
            (bool) $event->runInBackground,
            'runInBackground required: a slow drain must not block schedule:run tick.'
        );
    }

    public function test_lane_captures_output_to_dedicated_log(): void
    {
        $event = $this->findDrainEvent();
        $this->assertNotNull($event, 'Pre-condition: drain lane must exist.');

        $this->assertNotNull($event->output, 'Lane must capture output (appendOutputTo).');

        $this->assertStringContainsString(
            'stripe-drain-cpn.log',
            (string) $event->output,
            'Output must target stripe-drain-cpn.log so ops can audit the drain cadence '
            . 'without polluting schedule.log. Got: ' . (string) $event->output
        );
    }

    public function test_command_carries_older_than_5_minutes_default(): void
    {
        $event = $this->findDrainEvent();
        $this->assertNotNull($event, 'Pre-condition: drain lane must exist.');

        $this->assertStringContainsString(
            '--older-than-minutes=5',
            (string) $event->command,
            'Lane MUST pass --older-than-minutes=5 — the safety window for in-flight browser '
            . 'redirects to complete before we drain. Got: ' . (string) $event->command
        );
    }

    public function test_exactly_one_drain_lane_is_registered(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $count = collect($schedule->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'stripe:drain-stranded-cpn'))
            ->count();

        $this->assertSame(1, $count, "Exactly 1 stripe:drain-stranded-cpn lane expected, found {$count}.");
    }

    // ---- Behavior sentinels --------------------------------------------

    public function test_stranded_cpn_flips_unpaid_order_to_paid(): void
    {
        $branch = Branch::factory()->create();
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $order  = $this->makeStripeUnpaidOrder($branch->id, $user->id);

        $this->seedStrandedCpn($order->id, 'txn_drain_test_1', minutesAgo: 10);

        $auditCountBefore = AuditLog::query()->count();

        $exit = Artisan::call('stripe:drain-stranded-cpn');

        $this->assertSame(0, $exit, 'Drain command must exit SUCCESS on a clean flip.');

        $order->refresh();
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $order->payment_status,
            'Stranded CPN must flip order.payment_status → PAID.'
        );

        $transaction = Transaction::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($transaction, 'PaymentService::payment must create a Transaction row.');
        $this->assertSame('stripe', (string) $transaction->payment_method);
        $this->assertSame('txn_drain_test_1', (string) $transaction->transaction_no);
        $this->assertSame('payment', (string) $transaction->type);

        $cpnAfter = DB::table('capture_payment_notifications')
            ->where('order_id', $order->id)
            ->count();
        $this->assertSame(0, $cpnAfter, 'CPN row must be DELETEd after successful drain (matches Stripe::success flush).');

        $auditCountAfter = AuditLog::query()->count();
        $this->assertGreaterThan(
            $auditCountBefore,
            $auditCountAfter,
            'AuditLogService::write must extend the NF525 chain with a drain entry.'
        );

        $drainAudit = AuditLog::query()
            ->where('action', 'order.payment.drained_by_cron')
            ->where('resource_id', $order->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($drainAudit, 'audit_logs must carry the order.payment.drained_by_cron entry.');
        $this->assertSame((int) $branch->id, (int) $drainAudit->branch_id);
        $this->assertSame('stripe', $drainAudit->payload['gateway'] ?? null);
        $this->assertSame('flipped', $drainAudit->payload['outcome'] ?? null);
    }

    public function test_already_paid_order_is_skipped_and_residue_cpn_removed(): void
    {
        $branch = Branch::factory()->create();
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $order  = $this->makeStripeUnpaidOrder($branch->id, $user->id);

        // Order arrived PAID before the cron fires (browser flush won the race).
        $order->payment_status = PaymentStatus::PAID;
        $order->save();

        $this->seedStrandedCpn($order->id, 'txn_residue_1', minutesAgo: 10);

        $exit = Artisan::call('stripe:drain-stranded-cpn');
        $this->assertSame(0, $exit);

        $cpnAfter = DB::table('capture_payment_notifications')
            ->where('order_id', $order->id)
            ->count();
        $this->assertSame(0, $cpnAfter, 'Residue CPN row on an already-PAID order must still be cleaned up.');

        // No second Transaction created (idempotency).
        $this->assertSame(
            0,
            Transaction::query()->where('order_id', $order->id)->count(),
            'No new Transaction must be created on an already-PAID skip.'
        );

        // No drain audit row written (we only audit actual flips / dead rows, not no-op skips).
        $drainAudits = AuditLog::query()
            ->where('action', 'order.payment.drained_by_cron')
            ->where('resource_id', $order->id)
            ->count();
        $this->assertSame(0, $drainAudits, 'No audit row on an already-paid skip — keeps the chain compact.');
    }

    public function test_dry_run_does_not_mutate_state(): void
    {
        $branch = Branch::factory()->create();
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $order  = $this->makeStripeUnpaidOrder($branch->id, $user->id);

        $this->seedStrandedCpn($order->id, 'txn_dryrun_1', minutesAgo: 10);

        $exit = Artisan::call('stripe:drain-stranded-cpn', ['--dry-run' => true]);
        $this->assertSame(0, $exit);

        $order->refresh();
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $order->payment_status,
            'Dry-run must NOT flip payment_status.'
        );

        $this->assertSame(
            1,
            DB::table('capture_payment_notifications')->where('order_id', $order->id)->count(),
            'Dry-run must NOT delete the CPN row.'
        );

        $this->assertSame(
            0,
            Transaction::query()->where('order_id', $order->id)->count(),
            'Dry-run must NOT create a Transaction row.'
        );
    }

    public function test_fresh_cpn_under_threshold_is_not_drained(): void
    {
        $branch = Branch::factory()->create();
        $user   = User::factory()->create(['branch_id' => $branch->id]);
        $order  = $this->makeStripeUnpaidOrder($branch->id, $user->id);

        // 2 min old — below the default 5 min threshold (in-flight browser window).
        $this->seedStrandedCpn($order->id, 'txn_fresh_1', minutesAgo: 2);

        $exit = Artisan::call('stripe:drain-stranded-cpn');
        $this->assertSame(0, $exit);

        $order->refresh();
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $order->payment_status,
            'Fresh CPN (< 5 min) must NOT be drained — protects in-flight browser flush.'
        );

        $this->assertSame(
            1,
            DB::table('capture_payment_notifications')->where('order_id', $order->id)->count(),
            'Fresh CPN must remain in place for the next drain cycle.'
        );
    }

    public function test_non_stripe_order_is_skipped_and_cpn_retained(): void
    {
        $branch = Branch::factory()->create();
        $user   = User::factory()->create(['branch_id' => $branch->id]);

        // Order whose payment_method is NOT CARD/Stripe — out of K.8 scope.
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'user_id'        => $user->id,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 12.50,
        ]);

        $this->seedStrandedCpn($order->id, 'txn_non_stripe_1', minutesAgo: 10);

        $exit = Artisan::call('stripe:drain-stranded-cpn');
        $this->assertSame(0, $exit);

        $order->refresh();
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) $order->payment_status,
            'Non-Stripe order must not be flipped — K.8 K8-F-01 is Stripe-only.'
        );

        $this->assertSame(
            1,
            DB::table('capture_payment_notifications')->where('order_id', $order->id)->count(),
            'CPN of a non-Stripe order must be left in place for the appropriate gateway lane.'
        );
    }

    // ---- Helpers --------------------------------------------------------

    private function makeStripeUnpaidOrder(int $branchId, int $userId): Order
    {
        return Order::factory()->create([
            'branch_id'      => $branchId,
            'user_id'        => $userId,
            'payment_method' => PaymentGateway::CARD, // Stripe surface
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 24.99,
        ]);
    }

    private function seedStrandedCpn(int $orderId, string $token, int $minutesAgo): void
    {
        // Use direct insert to bypass timestamp auto-fill — we need a
        // deterministic created_at older than now().
        DB::table('capture_payment_notifications')->insert([
            'order_id'   => $orderId,
            'token'      => $token,
            'created_at' => Carbon::now()->subMinutes($minutesAgo),
        ]);
    }
}
