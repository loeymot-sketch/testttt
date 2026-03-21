<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Force setup of the environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$apiKey = $_ENV['MIX_API_KEY'] ?? null;

function testLogin($kernel, $email, $password, $apiKey) {
    try {
        $request = Illuminate\Http\Request::create(
            '/api/auth/login',
            'POST',
            ['email' => $email, 'password' => $password]
        );
        $request->headers->set('Accept', 'application/json');
        
        if ($apiKey) {
            $request->headers->set('x-api-key', trim($apiKey));
        }
        
        $response = $kernel->handle($request);
        $content = $response->getContent();
        $data = json_decode($content, true);
        
        if ($response->getStatusCode() === 200) {
            $url = $data['data']['defaultPermission']['url'] ?? 'NULL';
            if (empty($url) || $url === 'NULL') {
                $urlDisplay = 'FRONTEND HOME (/)';
            } else {
                $urlDisplay = '/admin/' . $url;
            }
            echo "[SUCCESS] $email -> Redirects to: $urlDisplay\n";
        } else {
            echo "[FAILED] $email -> HTTP " . $response->getStatusCode() . " - " . ($data['errors']['validation'][0] ?? json_encode($data['errors'] ?? $data['message'])) . "\n";
        }
    } catch (\Exception $e) {
        echo "[ERROR] $email -> " . $e->getMessage() . "\n";
    }
}

echo "Running Login Redirection Tests...\n\n";
testLogin($kernel, 'caissier@lecayenne-henin-beaumont.fr', '123456', $apiKey);
testLogin($kernel, 'chef@lecayenne-henin-beaumont.fr', '123456', $apiKey);
testLogin($kernel, 'customer@example.com', '123456', $apiKey);
echo "\nTests Complete.\n";
