<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\FrontendOrderService;
use App\Services\OrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class QueueNumberConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_duplicate_queue_number_within_same_branch_and_business_date(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-04-27',
            'queue_number' => 'A0001',
        ]);

        $this->expectException(QueryException::class);

        Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-04-27',
            'queue_number' => 'A0001',
        ]);
    }

    public function test_database_allows_same_queue_number_for_same_branch_on_different_business_dates(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-04-27',
            'queue_number' => 'A0001',
        ]);
        Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-04-28',
            'queue_number' => 'A0001',
        ]);

        $this->assertSame(
            2,
            DB::table('orders')->where('branch_id', $branch->id)->where('queue_number', 'A0001')->count()
        );
    }

    public function test_database_allows_same_queue_number_across_different_branches_on_same_business_date(): void
    {
        $firstBranch = Branch::factory()->create();
        $secondBranch = Branch::factory()->create();
        $firstUser = User::factory()->create(['branch_id' => $firstBranch->id]);
        $secondUser = User::factory()->create(['branch_id' => $secondBranch->id]);

        Order::factory()->create([
            'user_id' => $firstUser->id,
            'branch_id' => $firstBranch->id,
            'business_date' => '2026-04-27',
            'queue_number' => 'A0001',
        ]);
        Order::factory()->create([
            'user_id' => $secondUser->id,
            'branch_id' => $secondBranch->id,
            'business_date' => '2026-04-27',
            'queue_number' => 'A0001',
        ]);

        $this->assertSame(
            2,
            DB::table('orders')->where('queue_number', 'A0001')->count()
        );
    }

    public function test_null_queue_numbers_remain_allowed_for_legacy_rows(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-04-27',
            'queue_number' => null,
        ]);
        Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'business_date' => '2026-04-27',
            'queue_number' => null,
        ]);

        $this->assertSame(
            2,
            DB::table('orders')->where('branch_id', $branch->id)->whereNull('queue_number')->count()
        );
    }

    public function test_pos_and_kiosk_allocators_share_gapless_sequence_across_50_creations(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $businessDate = '2026-04-28';

        $posAllocator = app(OrderService::class);
        $kioskAllocator = app(FrontendOrderService::class);
        $expected = [];

        for ($i = 1; $i <= 50; $i++) {
            $allocator = $i % 2 === 0 ? $kioskAllocator : $posAllocator;
            $context = $i % 2 === 0 ? 'kiosk-stress' : 'pos-stress';
            $queueNumber = $this->allocateQueueNumber($allocator, $branch->id, $businessDate, $context);
            $expected[] = 'A' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);

            $this->assertSame(end($expected), $queueNumber);

            Order::factory()->create([
                'user_id' => $user->id,
                'branch_id' => $branch->id,
                'business_date' => $businessDate,
                'queue_number' => $queueNumber,
            ]);
        }

        $actual = DB::table('orders')
            ->where('branch_id', $branch->id)
            ->where('business_date', $businessDate)
            ->orderBy('queue_number')
            ->pluck('queue_number')
            ->all();

        $this->assertSame($expected, $actual);
    }

    private function allocateQueueNumber(object $service, int $branchId, string $businessDate, string $context): string
    {
        $method = new ReflectionMethod($service, 'allocateQueueNumber');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $branchId, $businessDate, $context);
    }
}
