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
        // [owner 2026-07-07] Ce test vérifie la propriété SANS-TROU / SANS-DOUBLON
        // du partage POS↔kiosk — orthogonale à l'offset métier kiosk.queue_start_number
        // (32 en prod). On épingle le départ à 1 pour asserter la séquence pure A0001…A0050.
        config(['kiosk.queue_start_number' => 1]);

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

    /**
     * [owner 2026-07-07] Le compteur quotidien démarre à kiosk.queue_start_number.
     * Jour vierge → 1er ordre = A0032 (POS ET kiosk), puis suit le max (33, 34…).
     */
    public function test_daily_queue_starts_at_configured_start_number_both_surfaces(): void
    {
        config(['kiosk.queue_start_number' => 32]);

        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $businessDate = '2026-07-07';

        // 1er ordre du jour via le POS (caisse) = A0032.
        $first = $this->allocateQueueNumber(app(OrderService::class), $branch->id, $businessDate, 'pos');
        $this->assertSame('A0032', $first, 'le 1er ordre du jour doit être A0032');
        Order::factory()->create([
            'user_id' => $user->id, 'branch_id' => $branch->id,
            'business_date' => $businessDate, 'queue_number' => $first,
        ]);

        // 2e ordre via la borne (kiosk) = A0033 (suit le max, pas de re-saut à 32).
        $second = $this->allocateQueueNumber(app(FrontendOrderService::class), $branch->id, $businessDate, 'kiosk');
        $this->assertSame('A0033', $second, 'le 2e ordre suit A0033');

        // Un AUTRE jour repart à A0032 (reset quotidien).
        $otherDay = $this->allocateQueueNumber(app(OrderService::class), $branch->id, '2026-07-08', 'pos');
        $this->assertSame('A0032', $otherDay, 'chaque jour repart à A0032');
    }

    private function allocateQueueNumber(object $service, int $branchId, string $businessDate, string $context): string
    {
        $method = new ReflectionMethod($service, 'allocateQueueNumber');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $branchId, $businessDate, $context);
    }
}
