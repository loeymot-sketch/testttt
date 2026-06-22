<?php

namespace Tests\Feature\ProdLike;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProdLikeConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Prod-like concurrency test requires DB_CONNECTION=mysql.');
        }

        if (config('cache.default') !== 'redis') {
            $this->markTestSkipped('Prod-like concurrency test requires CACHE_DRIVER=redis for Cache::lock().');
        }

        $this->artisan('migrate:fresh', ['--force' => true])->run();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    public function test_mysql_redis_stock_decrement_survives_50_parallel_workers(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $item->id,
            'on_hand' => 20,
            'reserved' => 0,
        ]);

        $orderIds = [];
        for ($i = 0; $i < 50; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::ACCEPT,
            ]);
            OrderItem::query()->create([
                'order_id' => $order->id,
                'branch_id' => $branch->id,
                'item_id' => $item->id,
                'quantity' => 1,
                'price' => 1,
                'discount' => 0,
                'total_price' => 1,
                'item_variations' => json_encode([]),
                'item_extras' => json_encode([]),
            ]);
            $orderIds[] = $order->id;
        }

        $results = $this->runWorkers(
            collect($orderIds)->map(fn (int $orderId): array => ['stock', (string) $orderId, 'none'])->all()
        );

        $summary = collect($results)->countBy('result');

        $this->assertSame(20, (int) ($summary['success'] ?? 0), json_encode($results));
        $this->assertSame(30, (int) ($summary['stock_unavailable'] ?? 0), json_encode($results));
        $this->assertSame(0, (int) StockLevel::query()->where('stockable_id', $item->id)->value('on_hand'));
        $this->assertSame(20, StockMovement::query()->where('branch_id', $branch->id)->where('delta', -1)->count());
    }

    public function test_mysql_redis_queue_numbers_stay_unique_for_50_parallel_pos_and_kiosk_workers(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $businessDate = now()->toDateString();
        $work = [];

        for ($i = 0; $i < 50; $i++) {
            $model = $i % 2 === 0
                ? Order::query()->create($this->baseOrderPayload($branch->id, $user->id, 'pos'))
                : FrontendOrder::query()->create($this->baseOrderPayload($branch->id, $user->id, 'kiosk'));

            $work[] = [
                'id' => $model->id,
                'surface' => $i % 2 === 0 ? 'pos' : 'kiosk',
                'business_date' => $businessDate,
            ];
        }

        $results = $this->runWorkers(
            collect($work)
                ->map(fn (array $payload): array => ['queue', (string) $payload['id'], $payload['surface']])
                ->all()
        );

        $summary = collect($results)->countBy('result');
        $queueNumbers = DB::table('orders')
            ->where('branch_id', $branch->id)
            ->where('business_date', $businessDate)
            ->whereNotNull('queue_number')
            ->orderBy('queue_number')
            ->pluck('queue_number')
            ->all();

        $this->assertSame(50, (int) ($summary['success'] ?? 0), json_encode($results));
        $this->assertCount(50, $queueNumbers);
        $this->assertSame($queueNumbers, array_values(array_unique($queueNumbers)));
        $this->assertSame('A0001', $queueNumbers[0]);
        $this->assertSame('A0050', $queueNumbers[49]);
    }

    /**
     * @param array<int,mixed> $payloads
     * @return array<int,array<string,mixed>>
     */
    private function runWorkers(array $payloads): array
    {
        $dir = storage_path('framework/testing/prodlike-' . uniqid('', true));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $processes = [];
        $php = PHP_BINARY;
        $worker = base_path('scripts/prodlike-concurrency-worker.php');
        $env = array_filter(array_merge($_ENV, $_SERVER, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'CACHE_DRIVER' => 'redis',
            'REDIS_CLIENT' => 'predis',
        ]), static fn ($value): bool => is_scalar($value) || $value === null);
        $env = array_map(static fn ($value): string => (string) $value, $env);

        foreach ($payloads as $index => $payload) {
            $output = $dir . '/' . $index . '.json';
            $stdout = $dir . '/' . $index . '.out';
            $stderr = $dir . '/' . $index . '.err';
            $command = implode(' ', array_map('escapeshellarg', [
                $php,
                $worker,
                (string) $payload[0],
                (string) $payload[1],
                (string) $payload[2],
                $output,
            ]));

            $process = proc_open($command, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ], $pipes, base_path(), $env);

            if (! is_resource($process)) {
                $this->fail('Unable to start prod-like worker.');
            }

            $processes[$index] = [$process, $output, $stderr];
        }

        $deadline = microtime(true) + 60;
        foreach ($processes as [$process]) {
            while (($status = proc_get_status($process)) && $status['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($process);
                    $this->fail('Prod-like worker timed out.');
                }

                usleep(100000);
            }

            proc_close($process);
        }

        $results = [];
        foreach ($processes as $index => [, $path, $stderr]) {
            $results[$index] = is_file($path)
                ? json_decode((string) file_get_contents($path), true)
                : ['result' => 'missing_worker_result', 'stderr' => is_file($stderr) ? file_get_contents($stderr) : null];
        }

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseOrderPayload(int $branchId, int $userId, string $surface): array
    {
        return [
            'user_id' => $userId,
            'branch_id' => $branchId,
            'subtotal' => 1,
            'discount' => 0,
            'delivery_charge' => 0,
            'total_tax' => 0,
            'total' => 1,
            'order_type' => $surface === 'kiosk' ? OrderType::KIOSK : OrderType::TAKEAWAY,
            'order_datetime' => now(),
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => $surface,
        ];
    }
}
