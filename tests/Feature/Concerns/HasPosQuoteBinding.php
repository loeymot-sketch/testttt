<?php

namespace Tests\Feature\Concerns;

use App\Models\User;

trait HasPosQuoteBinding
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function payloadWithPosQuote(User $operator, array $payload, ?float $posReceivedAmount = null): array
    {
        $quote = $this
            ->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', $this->quoteBindingApiKey())
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $bound = $payload + [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
        ];

        if ($posReceivedAmount !== null) {
            $bound['pos_received_amount'] = $posReceivedAmount;
        }

        return $bound;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function payloadWithKioskQuote(User $kioskUser, array $payload): array
    {
        $quote = $this
            ->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', $this->quoteBindingApiKey())
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        return $payload + [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
        ];
    }

    private function quoteBindingApiKey(): string
    {
        return (string) (config('app.api_key') ?: env('MIX_API_KEY', 'test-api-key'));
    }
}
