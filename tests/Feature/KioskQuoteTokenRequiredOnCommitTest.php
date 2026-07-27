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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KioskQuoteTokenRequiredOnCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Smartisan\Settings\Facades\Settings::group('pos')->set(['pos_dine_in_enabled' => true]); // [2026-07-27] garde V1 sur-place (47f3ad545) : OFF par défaut — ce test exerce un flux sur-place/table derrière son flag
    }


    public function test_kiosk_commit_without_quote_token_is_rejected(): void
    {
        [$kioskUser, $payload] = $this->fixture();

        // [ULTRA-AUDIT 2026-07-02] Vrai token machine (PersonalAccessToken) — le guard
        // KIOSK rejette TransientToken/null (kioskMachineForToken) ; NE PAS revert le guard.
        $this->kioskToken($kioskUser);

        $this->postJson('/api/frontend/order', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quote_token', 'quote_signature']);
    }

    public function test_kiosk_commit_without_quote_signature_is_rejected(): void
    {
        [$kioskUser, $payload] = $this->fixture();
        $quote = $this->quote($kioskUser, $payload);

        $this->kioskToken($kioskUser);

        $this->postJson('/api/frontend/order', $payload + [
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

        $this->kioskToken($kioskUser)
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

        $response = $this->kioskToken($kioskUser)
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $this->assertNotNull(OrderQuote::where('quote_token', $quote['quote_token'])->value('consumed_at'));
    }

    /**
     * [ULTRA-AUDIT 2026-07-02] Authentifie comme une VRAIE borne enregistrée : un
     * PersonalAccessToken réel avec l'ability kiosk:order (pas TransientToken/session).
     * kioskMachineForToken() exige un vrai token → c'est le seul chemin qui reflète la
     * production (A0001 prouvé live). Retourne $this pour chaîner ->postJson().
     */
    private function kioskToken(User $kioskUser): self
    {
        $plain = $kioskUser->createToken('kiosk-e2e', ['kiosk:order'])->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $plain);
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
        return $this->kioskToken($kioskUser)
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');
    }
}
