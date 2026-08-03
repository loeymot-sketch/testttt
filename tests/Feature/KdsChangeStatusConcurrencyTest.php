<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * P4 — KDS change-status: stale in-memory order vs DB must abort with 409.
 *
 * HTTP route model binding always loads a fresh Order, so concurrency is asserted
 * against {@see KitchenDisplaySystemOrderService::changeStatus()} (same code the controller calls).
 */
class KdsChangeStatusConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $chef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->chef = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('pwd'),
        ]);
        $this->chef->assignRole('Chef');
    }

    public function test_kds_service_aborts_409_when_in_memory_order_status_differs_from_locked_row(): void
    {
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::POS,
            'source' => Source::POS,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
        ]);

        Order::query()->whereKey($order->id)->update(['status' => OrderStatus::PREPARING]);

        $this->actingAs($this->chef, 'sanctum');

        $base = Request::create('/api/admin/kds-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::PREPARING,
        ]);
        $formRequest = OrderStatusRequest::createFrom($base);
        $formRequest->setContainer($this->app);
        $formRequest->setRedirector($this->app->make('redirect'));
        $formRequest->validateResolved();

        try {
            app(KitchenDisplaySystemOrderService::class)->changeStatus($order, $formRequest);
            $this->fail('Expected HttpException 409 (stale expected-from vs locked row).');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsStringIgnoringCase('refresh', $e->getMessage());
        }
    }
}
