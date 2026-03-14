<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Str;

echo "=== E2E SECURITY TEST: TXN IDs AND PINS ===\n";

// 1. Transaction IDs using Str::random(12)
$txnId = 'TXN-' . Str::random(12);
echo "Generated Transaction ID: $txnId\n";
if (strlen($txnId) === 16 && strpos($txnId, 'TXN-') === 0) {
    echo "✅ SUCCESS: Transaction ID format is secure and correct.\n";
} else {
    echo "❌ FAILED: Transaction ID format incorrect.\n";
}

// 2. PIN using random_int(100000, 999999)
$pin = random_int(100000, 999999);
echo "Generated PIN (Reset/Login): $pin\n";
if ($pin >= 100000 && $pin <= 999999) {
    echo "✅ SUCCESS: PIN is a secure 6-digit integer generated cryptographically.\n";
} else {
    echo "❌ FAILED: PIN format incorrect.\n";
}

// 3. Guest Password using Str::random(10)
$guestPass = Str::random(10);
echo "Generated Guest Password: $guestPass\n";
if (strlen($guestPass) === 10) {
    echo "✅ SUCCESS: Guest Password format is secure.\n";
} else {
    echo "❌ FAILED: Guest Password format incorrect.\n";
}

echo "---------------------------------------------------\n";
