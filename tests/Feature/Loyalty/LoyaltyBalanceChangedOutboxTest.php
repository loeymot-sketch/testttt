<?php

namespace Tests\Feature\Loyalty;

use App\Enums\EventType;
use App\Events\LoyaltyBalanceChanged;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * [GOAL LOYALTY_UNIFIED_SYNC L2 2026-06-11]
 *
 * Le solde fidélité se synchronise désormais : chaque mouvement émet
 * LoyaltyBalanceChanged → PersistLoyaltyBalanceChangedToOutbox →
 * domain_events (channel private-branch.{id}). Avant : AUCUN event fidélité
 * sur le bus (caissière modal ouvert = solde périmé).
 */
class LoyaltyBalanceChangedOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_persists_one_outbox_row_with_pii_free_payload(): void
    {
        Bus::fake();

        LoyaltyBalanceChanged::dispatch(42, 1, 265, 15, 'earn', 'corr-1');

        $row = DomainEvent::query()->where('event_type', EventType::LOYALTY_BALANCE_CHANGED)->first();
        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row->aggregate_id);
        $payload = is_array($row->payload) ? $row->payload : json_decode($row->payload, true);
        $this->assertSame(265, (int) $payload['balance_after']);
        $this->assertSame(15, (int) $payload['delta']);
        $this->assertSame('earn', $payload['reason']);
        // PII-free: no name/phone/loyalty_code in the broadcast payload.
        $this->assertArrayNotHasKey('name', $payload);
        $this->assertArrayNotHasKey('phone', $payload);
        $this->assertArrayNotHasKey('loyalty_code', $payload);
        $this->assertStringContainsString('private-branch.1', (string) $row->channel);
        $this->assertSame('LoyaltyBalanceChanged', $row->broadcast_as);
    }

    public function test_replay_collapses_on_idempotency_key(): void
    {
        Bus::fake();

        LoyaltyBalanceChanged::dispatch(42, 1, 265, 15, 'earn', 'corr-dup');
        LoyaltyBalanceChanged::dispatch(42, 1, 265, 15, 'earn', 'corr-dup');

        $this->assertSame(1, DomainEvent::query()
            ->where('event_type', EventType::LOYALTY_BALANCE_CHANGED)->count());
    }

    public function test_distinct_movements_get_distinct_rows(): void
    {
        Bus::fake();

        LoyaltyBalanceChanged::dispatch(42, 1, 265, 15, 'earn', 'corr-a');
        LoyaltyBalanceChanged::dispatch(42, 1, 165, -100, 'redeem', 'corr-b');

        $this->assertSame(2, DomainEvent::query()
            ->where('event_type', EventType::LOYALTY_BALANCE_CHANGED)->count());
    }

    public function test_welcome_registration_emits_balance_event(): void
    {
        Bus::fake();
        $this->seedMinimalSettings();

        $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson('/api/frontend/loyalty/register', ['phone' => '0611224488', 'name' => 'E2E Sync'])
            ->assertSuccessful();

        $row = DomainEvent::query()->where('event_type', EventType::LOYALTY_BALANCE_CHANGED)->first();
        $this->assertNotNull($row, 'welcome bonus must push the balance to the bus');
        $payload = is_array($row->payload) ? $row->payload : json_decode($row->payload, true);
        $this->assertSame('welcome', $payload['reason']);
        $this->assertSame(25, (int) $payload['balance_after']);
    }
}
