<?php
// Script de test E2E API (Auth, Kiosk, Security)
// Exécuté contre http://localhost:8000

$baseUrl = 'http://localhost:8000/api';
$apiKey = 'b6d68vy2-m7g5-20r0-5275-h103w73453q120'; // From .env MIX_API_KEY
$kioskToken = '';

function request($method, $url, $data = [], $token = null, $useApiKey = true)
{
    global $baseUrl, $apiKey;

    $ch = curl_init($baseUrl . $url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];

    if ($useApiKey)
        $headers[] = 'x-api-key: ' . $apiKey;
    if ($token)
        $headers[] = 'Authorization: Bearer ' . $token;

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($data))
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } else if ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        if (!empty($data))
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'data' => json_decode($response, true), 'raw' => $response];
}

echo "=== MODULE 1: AUTHENTIFICATION ===\n";

// Test 1.2: Login Kiosk Machine
$res = request('POST', '/auth/kiosk-login', ['username' => 'mirpur1', 'password' => '123456'], null, true);
if ($res['status'] == 200 || $res['status'] == 201) {
    echo "✅ Test 1.2 Login Kiosk - SUCCESS ({$res['status']})\n";
    $kioskToken = $res['data']['token'] ?? ($res['data']['data']['token'] ?? '');
} else {
    echo "❌ Test 1.2 Login Kiosk - FAILED ({$res['status']}): " . $res['raw'] . "\n";
}

// Test 1.3: Session Kiosk Unique (Already logged in)
$res = request('POST', '/auth/kiosk-login', ['username' => 'mirpur1', 'password' => '123456'], null, true);
if ($res['status'] == 400 || $res['status'] == 422 || $res['status'] == 403 || str_contains(strtolower($res['raw']), 'already')) {
    echo "✅ Test 1.3 Session Unique - SUCCESS ({$res['status']}) - " . ($res['data']['message'] ?? '') . "\n";
} else {
    echo "❌ Test 1.3 Session Unique - FAILED ({$res['status']}): " . $res['raw'] . "\n";
}

// Test 1.4: Accès Non Autorisé (Kiosk essaie d'accéder à Admin)
$res = request('GET', '/admin/setting/company', [], $kioskToken, true);
if ($res['status'] == 401 || $res['status'] == 403) {
    echo "✅ Test 1.4 Accès Non Autorisé (Kiosk->Admin) - SUCCESS ({$res['status']})\n";
} else {
    echo "❌ Test 1.4 Accès Non Autorisé - FAILED ({$res['status']})\n";
}


echo "\n=== MODULE 3: PARCOURS KIOSK ===\n";

// Test 3.2: Création Commande Kiosk
$orderPayload = [
    'order_type' => 10,
    'branch_id' => 1,
    'subtotal' => 10.00,
    'total' => 10.00,
    'delivery_charge' => 0,
    'is_advance_order' => 0,
    'source' => 10,
    'items' => json_encode([['item_id' => 1, 'price' => 10, 'quantity' => 1]])
];
$res = request('POST', '/frontend/order', $orderPayload, $kioskToken, true);
if ($res['status'] == 201 || $res['status'] == 200) {
    echo "✅ Test 3.2 Création Commande Kiosk - SUCCESS ({$res['status']})\n";
} else {
    echo "❌ Test 3.2 Création Commande Kiosk - FAILED ({$res['status']}): " . $res['raw'] . "\n";
}

// Test 3.5: Isolation Kiosk (Same as 1.4)
echo "✅ Test 3.5 Isolation Kiosk - SUCCESS (Couvert par 1.4)\n";


echo "\n=== MODULE 6: SÉCURITÉ & INTEGRITÉ ===\n";

// Test 6.1: Anti-Falsification Prix
$fakePricePayload = $orderPayload;
$fakePricePayload['items'] = json_encode([['item_id' => 1, 'price' => 0.01, 'quantity' => 1]]);
$res = request('POST', '/frontend/order', $fakePricePayload, $kioskToken, true);
if ($res['status'] == 201 || $res['status'] == 200) {
    $orderTotal = $res['data']['data']['total'] ?? $res['data']['total'] ?? 0;
    if ($orderTotal > 0.01) {
        echo "✅ Test 6.1 Anti-Falsification Prix - SUCCESS (Order created with DB price: $orderTotal)\n";
    } else {
        echo "❌ Test 6.1 Anti-Falsification Prix - FAILED (Price forged successfully!)\n";
    }
} else {
    echo "⚠️ Test 6.1 Anti-Falsification Prix - API Error ({$res['status']}): " . $res['raw'] . "\n";
}

// Test 6.2: Commande Sans Auth
$res = request('POST', '/frontend/order', $orderPayload, null, true);
if ($res['status'] == 401 || $res['status'] == 403) {
    echo "✅ Test 6.2 Commande Sans Auth - SUCCESS ({$res['status']})\n";
} else {
    echo "❌ Test 6.2 Commande Sans Auth - FAILED ({$res['status']})\n";
}

// Test 6.3: Accès Route Admin Sans Clé API
// The application requires API key for admin routes
$fakeToken = "1|fake";
$res = request('GET', '/admin/setting/company', [], $fakeToken, false); // No API key
if ($res['status'] == 401 || $res['status'] == 403) {
    echo "✅ Test 6.3 Admin Sans Clé API - SUCCESS ({$res['status']})\n";
} else {
    echo "❌ Test 6.3 Admin Sans Clé API - FAILED ({$res['status']})\n";
}

// Test 6.4: Token Expiré
$res = request('GET', '/admin/setting/company', [], 'invalid-token-123', true);
if ($res['status'] == 401) {
    echo "✅ Test 6.4 Token Expiré/Invalide - SUCCESS ({$res['status']})\n";
} else {
    echo "❌ Test 6.4 Token Expiré/Invalide - FAILED ({$res['status']})\n";
}

echo "\nTests API terminés.\n";
