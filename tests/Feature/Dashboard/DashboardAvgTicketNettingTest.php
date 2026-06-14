<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DUX-1 (ultra-review 2026-06-15): the dashboard "ticket moyen" denominator must share the
 * numerator's net-realized base. daily_sales uses realizedRevenue() (drops cancelled-but-
 * paid); the old daily_paid_orders counted ALL payment_status=PAID rows incl. cancelled-
 * paid → a misleading micro-ticket. Now both use the same realizedRevenue() scope.
 */
class DashboardAvgTicketNettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_average_ticket_excludes_cancelled_paid_from_denominator(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $branch = Branch::factory()->create();
        $today = Carbon::now()->setTime(12, 0);

        $mk = fn (int $status, float $total) => Order::factory()->create([
            'branch_id' => $branch->id, 'status' => $status, 'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK, 'order_datetime' => $today, 'total' => $total,
            'is_advance_order' => Ask::NO, 'source' => Source::APP,
        ]);

        // One realized sale (30) + one cancelled-but-paid (100, must NOT dilute the avg).
        $mk(OrderStatus::DELIVERED, 30.00);
        $mk(OrderStatus::CANCELED, 100.00);

        $report = app(DashboardService::class)->realtimeReport();

        // daily_sales nets to 30 → avg over the SINGLE realized order = 30, not 30/2 = 15.
        $this->assertStringContainsString('30', (string) $report['daily_sales']);
        $this->assertStringContainsString('30', (string) $report['average_ticket'],
            'avg ticket must be 30 (one realized order), not 15 (the old denominator counted the cancelled-paid order).');
        $this->assertStringNotContainsString('15,00', (string) $report['average_ticket'],
            'the cancelled-but-paid order must not appear in the avg-ticket denominator.');
    }
}
