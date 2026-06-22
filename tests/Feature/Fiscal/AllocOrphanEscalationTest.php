<?php

/**
 * [GENIE Wave 1 — T1.2 2026-06-16] RetryFiscalAllocCommand returned SUCCESS unconditionally — a kiosk order
 * stuck PAID + fiscal_alloc_error_at across many ticks sat off-book forever with no pager. Now it escalates:
 * an orphan older than the threshold → Log::error + exit FAILURE so monitoring alerts. Fresh flags don't
 * escalate (the retry just keeps trying).
 */

namespace Tests\Feature\Fiscal;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AllocOrphanEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        // Make finalize a no-op so the seeded orphan stays flagged (isolates the escalation logic).
        $mock = Mockery::mock(FrontendOrderService::class);
        $mock->shouldReceive('finalizePaidKioskOrder')->andReturn(false);
        $this->app->instance(FrontendOrderService::class, $mock);
    }

    private function orphan(int $flagAgeMinutes): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        return Order::factory()->create([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'order_type' => OrderType::KIOSK,
            'payment_status' => PaymentStatus::PAID, 'fiscal_sequence_no' => null,
            'fiscal_alloc_error_at' => now()->subMinutes($flagAgeMinutes),
        ]);
    }

    /** @test */
    public function test_escalates_to_failure_when_orphan_stuck_past_threshold(): void
    {
        $this->orphan(40); // > 30min default threshold

        $exit = $this->artisan('foodking:fiscal:retry-alloc')->run();

        $this->assertSame(1, $exit, 'a stuck-past-threshold orphan must escalate to FAILURE, not silent SUCCESS');
    }

    /** @test */
    public function test_does_not_escalate_for_a_fresh_flag(): void
    {
        $this->orphan(2); // well under the threshold — retry keeps trying, no escalation

        $exit = $this->artisan('foodking:fiscal:retry-alloc')->run();

        $this->assertSame(0, $exit, 'a fresh flag must NOT escalate (SUCCESS)');
    }
}
