<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

echo "=== E2E SECURITY TEST: THROTTLE (Rate Limiting) ===\n";

$throttleCount = 0;
$successCount = 0;

for ($i = 1; $i <= 7; $i++) {
    // Creating a fresh request internally
    $request = Illuminate\Http\Request::create('/api/auth/login', 'POST', [
        'email' => 'test@test.com',
        'password' => 'wrong'
    ]);
    
    // Process through the HTTP kernel (runs middlewares)
    $response = $kernel->handle($request);
    $httpCode = $response->getStatusCode();
    
    echo "Attempt $i: HTTP $httpCode\n";
    if ($httpCode == 429) {
        $throttleCount++;
        echo "✅ SUCCESS: Throttle triggered at attempt $i (Expected around 6)\n";
        break;
    } else {
        $successCount++;
    }
}

if ($throttleCount === 0) {
    echo "❌ FAILED: Throttle not triggered after 7 attempts.\n";
}

echo "---------------------------------------------------\n";
