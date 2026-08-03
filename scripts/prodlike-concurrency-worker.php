<?php

use App\Models\FrontendOrder;
use App\Models\Order;
use App\Services\FrontendOrderService;
use App\Services\OrderService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$id = (int) ($argv[2] ?? 0);
$surface = $argv[3] ?? '';
$outputPath = $argv[4] ?? '';

$write = static function (array $payload) use ($outputPath): void {
    if ($outputPath !== '') {
        file_put_contents($outputPath, json_encode($payload));
    }
};

try {
    DB::purge();
    DB::reconnect();

    if (config('cache.default') === 'redis') {
        Cache::store('redis')->getStore()->connection()->client()->ping();
    }

    if ($mode === 'stock') {
        $order = Order::withoutGlobalScopes()->findOrFail($id);
        app(StockService::class)->decrementForOrder($order);
        $write(['result' => 'success']);
        exit(0);
    }

    if ($mode === 'queue' && $surface === 'pos') {
        $service = app(OrderService::class);
        $service->order = Order::withoutGlobalScopes()->findOrFail($id);
        $method = new ReflectionMethod($service, 'saveOrderWithQueueNumber');
        $method->setAccessible(true);
        $method->invoke($service, function () use ($service): void {
            $service->order->order_serial_no = 'PQ-' . $service->order->id;
        }, 'prodlike-pos-worker');

        $write(['result' => 'success', 'queue_number' => $service->order->queue_number]);
        exit(0);
    }

    if ($mode === 'queue' && $surface === 'kiosk') {
        $service = app(FrontendOrderService::class);
        $service->frontendOrder = FrontendOrder::withoutGlobalScopes()->findOrFail($id);
        $method = new ReflectionMethod($service, 'saveFrontendOrderWithQueueNumber');
        $method->setAccessible(true);
        $method->invoke($service, function () use ($service): void {
            $service->frontendOrder->order_serial_no = 'KQ-' . $service->frontendOrder->id;
        }, 'prodlike-kiosk-worker');

        $write(['result' => 'success', 'queue_number' => $service->frontendOrder->queue_number]);
        exit(0);
    }

    $write(['result' => 'error', 'message' => 'Unknown worker mode.']);
    exit(2);
} catch (Throwable $e) {
    $write([
        'result' => str_contains($e->getMessage(), 'Stock insuffisant') ? 'stock_unavailable' : 'error',
        'class' => $e::class,
        'message' => $e->getMessage(),
    ]);
    exit(0);
}
