<?php

namespace Tests\Feature;

use App\Services\Order\OrderQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

class OrderQuoteHmacKeyRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_quote_hmac_key_uses_configured_app_key(): void
    {
        config(['app.key' => 'base64:test-order-quote-key']);

        $this->assertSame('base64:test-order-quote-key', $this->invokeHmacKey());
    }

    public function test_order_quote_hmac_key_fails_closed_when_app_key_missing(): void
    {
        config(['app.key' => '']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('APP_KEY missing for OrderQuote HMAC');

        $this->invokeHmacKey();
    }

    private function invokeHmacKey(): string
    {
        $method = new ReflectionMethod(OrderQuoteService::class, 'hmacKey');
        $method->setAccessible(true);

        return (string) $method->invoke(app(OrderQuoteService::class));
    }
}
