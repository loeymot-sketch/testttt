<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KioskQuoteTokenRequiredOnCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_commit_without_quote_token_is_rejected(): void
    {
        [$kioskUser, $payload] = $this->fixture();

        Sanctum::actingAs($kioskUser, ['kiosk:order']);

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quote_token', 'quote_signature']);
    }

    public function test_kiosk_commit_without_quote_signature_is_rejected(): void
    {
        [$kioskUser, $payload] = $this->fixture();
        $quote = $this->quote($kioskUser, $payload);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);

        $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quote_signature']);
    }

    public function test_kiosk_commit_with_expired_quote_token_returns_410(): void
    {
        [$kioskUser, $payload] = $this->fixture();
        $quote = $this->quote($kioskUser, $payload);
        OrderQuote::where('quote_token', $quote['quote_token'])->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ])
            ->assertStatus(410);
    }

    public function test_kiosk_commit_with_valid_quote_pair_creates_order(): void
    {
        [$kioskUser, $payload] = $this->fixture();
        $quote = $this->quote($kioskUser, $payload);

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $this->assertNotNull(OrderQuote::where('quote_token', $quote['quote_token'])->value('consumed_at'));
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function fixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 8.25,
            'status' => Status::ACTIVE,
        ]);

        return [$kioskUser, [
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function quote(User $kioskUser, array $payload): array
    {
        return $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');
    }
}
