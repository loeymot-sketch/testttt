<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
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

class KioskQuoteForgesBranchIdSilentlyOverriddenTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_forged_branch_id_is_silently_overridden_by_machine_branch(): void
    {
        [$kioskUser, $payload, $machineBranch, $foreignBranch] = $this->kioskFixture();

        $data = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload + ['branch_id' => $foreignBranch->id])
            ->assertOk()
            ->json('data');

        $quote = OrderQuote::where('quote_token', $data['quote_token'])->firstOrFail();
        $this->assertSame($machineBranch->id, (int) $quote->branch_id);
        $this->assertSame($machineBranch->id, (int) $quote->canonical_payload['branch_id']);
    }

    public function test_kiosk_without_branch_id_uses_machine_branch(): void
    {
        [$kioskUser, $payload, $machineBranch] = $this->kioskFixture();

        $data = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $quote = OrderQuote::where('quote_token', $data['quote_token'])->firstOrFail();
        $this->assertSame($machineBranch->id, (int) $quote->branch_id);
        $this->assertSame($machineBranch->id, (int) $quote->canonical_payload['branch_id']);
    }

    public function test_pos_quote_still_accepts_coherent_branch_payload(): void
    {
        [$operator, $payload, $branch] = $this->posFixture();

        $data = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $quote = OrderQuote::where('quote_token', $data['quote_token'])->firstOrFail();
        $this->assertSame($branch->id, (int) $quote->branch_id);
        $this->assertSame($branch->id, (int) $quote->canonical_payload['branch_id']);
    }

    public function test_kiosk_quote_without_kiosk_order_ability_is_rejected(): void
    {
        [$kioskUser, $payload] = $this->kioskFixture();

        Sanctum::actingAs($kioskUser, []);

        $this->postJson('/api/frontend/order/quote', $payload)
            ->assertStatus(403);
    }

    public function test_inactive_kiosk_machine_cannot_receive_quote(): void
    {
        [$kioskUser, $payload] = $this->kioskFixture();
        KioskMachine::query()
            ->where('user_id', $kioskUser->id)
            ->update(['status' => Status::INACTIVE]);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);

        $this->postJson('/api/frontend/order/quote', $payload)
            ->assertStatus(403);
    }

    /**
     * @return array{0: User, 1: array<string, mixed>, 2: Branch, 3: Branch}
     */
    private function kioskFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $foreignBranch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
        ]);

        $item = $this->item();

        return [$kioskUser, [
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
        ], $branch, $foreignBranch];
    }

    /**
     * @return array{0: User, 1: array<string, mixed>, 2: Branch}
     */
    private function posFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $item = $this->item();

        return [$operator, [
            'branch_id' => $branch->id,
            'customer_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::POS,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $branch];
    }

    private function item(): Item
    {
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 8.25,
            'status' => Status::ACTIVE,
        ]);
    }
}
