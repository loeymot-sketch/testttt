<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Order;
use App\Services\OrderService;

echo "=== E2E SECURITY TEST: IDOR (Insecure Direct Object Reference) ===\n";

try {
    $userA = User::whereHas('roles', function($q) { $q->where('name', 'Customer'); })->first();
    $userB = User::whereHas('roles', function($q) { $q->where('name', 'Customer'); })->where('id', '!=', $userA->id)->first();
    
    if (!$userA || !$userB) {
        $userA = User::first();
        $userB = User::skip(1)->first();
    }
    
    $orderA = Order::where('user_id', $userA->id)->first();
    if (!$orderA) {
        $orderA = Order::create([
            'user_id' => $userA->id,
            'branch_id' => 1,
            'order_type' => 5, // Takeaway
            'status' => 5, // Pending
            'order_datetime' => now()
        ]);
        echo "Created dummy Order {$orderA->id} for User A.\n";
    }
    
    echo "User A ID: {$userA->id} | User B ID: {$userB->id} | Order ID: {$orderA->id}\n";
    
    echo "Logging in as User B...\n";
    auth()->login($userB);
    
    $orderService = app(OrderService::class);
    
    echo "User B attempting to show Order {$orderA->id}...\n";
    
    // OrderService::show usually takes ($order, $userId) or just $order depending on context
    // But KIMI changed it to check the currently authenticated user
    $result = $orderService->show($orderA, $userB->id);
    
    echo "❌ FAILED: Did not throw 403 Forbidden. Returned data instead.\n";
    
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    if ($e->getStatusCode() === 403) {
        echo "✅ SUCCESS: OrderService threw 403 Forbidden! IDOR is blocked.\n";
        echo "   Message: " . $e->getMessage() . "\n";
    } else {
        echo "❌ FAILED: Threw HTTP " . $e->getStatusCode() . " instead of 403.\n";
    }
} catch (\Exception $e) {
    // If it's another exception, print it
    echo "❌ FAILED: Unexpected exception: " . $e->getMessage() . "\n";
}

echo "---------------------------------------------------\n";
