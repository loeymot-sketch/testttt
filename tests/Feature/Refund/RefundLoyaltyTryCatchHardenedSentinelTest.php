<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\LoyaltyService;
use App\Services\Order\RefundWithCounterEntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * @FK-ID         FK-CV1-GOAL-K2-HEAL-03-2026-05-24
 * @source        Phase K.10 K10-NEW-01 P2 RED
 *
 * RefundWithCounterEntryService::execute called LoyaltyService::refundPoints
 * IN-TX with NO try/catch wrapper. The HEAL-A.2 NOOP at
 * app/Services/LoyaltyService.php:56+ handles only the alreadyRefunded
 * duplicate case and the customer-deleted silent-return case (line 47-54);
 * any OTHER throw (DB blip during increment, UNIQUE collision race,
 * lockForUpdate timeout, etc.) cascades and rolls back the entire mirror
 * refund INCLUDING the fiscal_sequence_no allocation.
 *
 * Fail-closed UX: cashier sees error → MUST retry → customer waits.
 *
 * Heal: wrap in try/catch + Log::error, matching the
 * ClawbackLoyaltyPointsOnRefund.php:82-93 cascade-isolation pattern.
 *
 * Invariants guarded by THIS sentinel (regression contract):
 *
 *   I-1 — When LoyaltyService::refundPoints throws an arbitrary
 *         \RuntimeException mid-transaction, the mirror refund order is
 *         STILL created (i.e. the outer DB::transaction is NOT rolled
 *         back by the loyalty failure).
 *
 *   I-2 — The mirror refund order's fiscal_sequence_no is allocated
 *         (proves the fiscal counter-entry survived the loyalty throw).
 *
 *   I-3 — A Log::error entry is emitted on the default channel with the
 *         GOAL-K2-HEAL-03 gate tag and the parent_order_id, so the
 *         operator's nightly log-grep can spot orphaned loyalty
 *         reversals AND tie each one back to a fiscal mirror.
 *
 *   I-4 — The error message from the original Throwable propagates into
 *         the log context (operator can diagnose without re-reading the
 *         stack trace).
 *
 * Anti-regression: removing the try/catch from
 * RefundWithCounterEntryService.php (or moving the LoyaltyService call
 * OUTSIDE the wrapper) will fail this test — the mirror order will not
 * exist after the throw because the outer transaction will have rolled
 * back the fiscal_sequence_no allocation along with everything else.
 */
class RefundLoyaltyTryCatchHardenedSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // No-op AuditLogService — chain head seeding not required for the
        // try/catch regression contract (we assert mirror persistence +
        // log emission, not chain bytes).
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct()
            {
            }

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });

        // Deterministic FiscalSequenceService so the mirror's allocated
        // fiscal_sequence_no is predictable (asserts I-2 cleanly).
        $this->app->instance(FiscalSequenceService::class, new class extends FiscalSequenceService {
            public function __construct()
            {
            }

            public function next(int $branchId): int
            {
                return 9101;
            }
        });
    }

    private function sealZ(Branch $branch, Carbon $opened, Carbon $closed): void
    {
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    /**
     * I-1 + I-2 + I-3 + I-4 — Mirror refund persists AND fiscal sequence
     * is allocated AND Log::error fires AND the error message round-trips
     * — all in one assertion battery on a single triggered exception.
     */
    public function test_loyalty_refund_throw_does_not_roll_back_mirror_and_emits_isolated_log(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('Admin');
        Auth::setUser($cashier);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);

        // Parent order WITH a loyalty_customer_code so the LoyaltyService
        // path is exercised (the no-loyalty-code early-return at
        // LoyaltyService.php:23-25 would otherwise NOOP and never throw).
        // The actual loyalty_code value doesn't need to match a real User
        // — we bind a throwing LoyaltyService BEFORE the service even
        // reaches the User lookup.
        $parent = Order::factory()->create([
            'branch_id'             => $branch->id,
            'order_type'            => OrderType::POS,
            'status'                => OrderStatus::ACCEPT,
            'payment_status'        => PaymentStatus::PAID,
            'subtotal'              => 24.00,
            'total'                 => 24.00,
            'total_tax'             => 0,
            'created_at'            => $opened->copy()->addHours(2),
            'loyalty_customer_code' => 'SENTINEL-K2-03',
        ]);
        $parent->fiscal_sequence_no = 700;
        $parent->save();

        // Bind a LoyaltyService stub that throws an arbitrary RuntimeException
        // — simulates the exact failure modes outside the HEAL-A.2 NOOPs
        // (DB blip during increment, UNIQUE collision race, lockForUpdate
        // timeout). Pre-heal this throw cascaded and rolled back the
        // outer DB::transaction; post-heal the try/catch isolates it.
        $throwingMessage = 'SENTINEL-K2-03 simulated DB blip during loyalty_points increment';
        $this->app->instance(LoyaltyService::class, new class($throwingMessage) extends LoyaltyService {
            public function __construct(private string $msg)
            {
            }

            public function refundPoints($order, string $sourceSurface = 'pos'): void
            {
                throw new \RuntimeException($this->msg);
            }
        });

        // Capture Log::error calls. Using shouldReceive('error') with a
        // closure so we can introspect the structured payload AFTER the
        // service runs (channel-tap mock would be over-engineered for
        // the default-channel default-error case).
        $logCapture = null;
        Log::shouldReceive('error')
            ->once()
            ->andReturnUsing(function (string $message, array $context = []) use (&$logCapture): void {
                $logCapture = ['message' => $message, 'context' => $context];
            });
        // Other log facade methods may be invoked during the mirror
        // creation path (e.g. fiscal channel info log) — allow them
        // through with NO expectation count constraint.
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);
        Log::shouldReceive('debug')->andReturn(null);

        // Execute the refund — pre-heal this would throw RuntimeException
        // and persist NOTHING; post-heal it returns the mirror order.
        $mirror = app(RefundWithCounterEntryService::class)
            ->execute($parent, 'sentinel-K2-HEAL-03-loyalty-throw');

        // I-1: Mirror order STILL created despite the loyalty throw.
        $this->assertNotNull(
            $mirror,
            'I-1 violated: mirror refund order MUST be created even when LoyaltyService::refundPoints throws. '
                . 'Pre-heal the outer DB::transaction was rolled back by the cascading loyalty exception, '
                . 'leaving the cashier with no fiscal record. Re-add the try/catch in '
                . 'RefundWithCounterEntryService.php to restore this invariant.'
        );
        $this->assertSame(
            (int) $parent->id,
            (int) $mirror->parent_order_id,
            'Mirror order must reference the parent via parent_order_id FK.'
        );
        // The mirror persists in the DB — fresh round-trip query confirms
        // commit (not just an in-memory model).
        $this->assertDatabaseHas('orders', [
            'id'              => $mirror->id,
            'parent_order_id' => $parent->id,
            'status'          => OrderStatus::RETURNED,
            'payment_status'  => PaymentStatus::REFUNDED,
        ]);

        // I-2: fiscal_sequence_no allocated for the mirror.
        $this->assertSame(
            9101,
            (int) $mirror->fiscal_sequence_no,
            'I-2 violated: mirror MUST carry the fiscal_sequence_no allocated by FiscalSequenceService::next. '
                . 'Pre-heal the rollback discarded this allocation along with the mirror row.'
        );

        // I-3: Log::error fired with the GOAL-K2-HEAL-03 gate tag and
        // forensic IDs (parent + mirror + branch + user).
        $this->assertNotNull(
            $logCapture,
            'I-3 violated: try/catch MUST emit Log::error on isolated loyalty failure '
                . '(matching ClawbackLoyaltyPointsOnRefund.php:82-93 pattern).'
        );
        $this->assertStringContainsString(
            'RefundWithCounterEntryService',
            $logCapture['message'],
            'Log message MUST identify the originating service for grep-ability.'
        );
        $this->assertStringContainsString(
            'loyalty refundPoints failed',
            $logCapture['message'],
            'Log message MUST identify the failed leg so operator does not confuse with fiscal-leg failures.'
        );
        $this->assertSame(
            'GOAL-K2-HEAL-03',
            $logCapture['context']['gate'] ?? null,
            'Log context MUST carry the GOAL-K2-HEAL-03 gate tag for SIEM filtering.'
        );
        $this->assertSame(
            (int) $parent->id,
            (int) ($logCapture['context']['parent_order_id'] ?? 0),
            'Log context MUST carry parent_order_id so operator can tie orphaned loyalty to a fiscal mirror.'
        );
        $this->assertSame(
            (int) $mirror->id,
            (int) ($logCapture['context']['mirror_order_id'] ?? 0),
            'Log context MUST carry mirror_order_id so operator can locate the surviving fiscal record.'
        );
        $this->assertSame(
            (int) $branch->id,
            (int) ($logCapture['context']['branch_id'] ?? 0),
            'Log context MUST carry branch_id for multi-tenant filtering.'
        );

        // I-4: original error message round-trips into the log context.
        $this->assertSame(
            $throwingMessage,
            (string) ($logCapture['context']['error'] ?? ''),
            'I-4 violated: original Throwable->getMessage() MUST round-trip into the log context '
                . 'so the operator can diagnose without re-reading the stack trace.'
        );
    }
}
