<?php

namespace Tests\Feature\KioskPhase5;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 5 : KioskEventController whitelist extensions.
 *
 * Vérifie l'acceptation des nouveaux types `a11y_settings`, `analytics`,
 * `hardware_health`, `hardware_event`, `hardware_error`, `consent_event`,
 * `idle_event`, plus la validation `event_name` pour analytics et le
 * rejet des clés PII dans `payload` (guard RGPD).
 */
class KioskEventPhase5WhitelistTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $branch = Branch::forceCreate([
            'name' => 'B', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75', 'address' => '1', 'status' => 1,
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::create([
            'machine_id' => 't', 'branch_id' => $branch->id,
            'user_id' => $user->id, 'username' => 'k',
            'password' => bcrypt('x'),
            'is_login' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $this->token = $user->createToken('k', ['kiosk:order'])->plainTextToken;
    }

    private function postEvent(array $body)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/frontend/kiosk/event', $body);
    }

    public function test_a11y_settings_type_accepted(): void
    {
        $this->postEvent([
            'type' => 'a11y_settings',
            'details' => 'contrast_change:aaa',
        ])->assertStatus(200);

        $this->assertDatabaseHas('action_logs', [
            'action' => 'Kiosk event: a11y_settings',
        ]);
    }

    public function test_hardware_health_type_accepted_with_payload(): void
    {
        $this->postEvent([
            'type' => 'hardware_health',
            'details' => 'state=ok',
            'payload' => ['state' => 'ok', 'components' => ['tpe' => true, 'printer' => true]],
        ])->assertStatus(200);

        $log = ActionLog::latest()->first();
        $this->assertStringContainsString('hardware_health', $log->action);
    }

    public function test_hardware_event_accepted(): void
    {
        $this->postEvent([
            'type' => 'hardware_event',
            'details' => 'printer_paper_low',
            'payload' => ['component' => 'printer', 'severity' => 'warning', 'code' => 'paper_low'],
        ])->assertStatus(200);
    }

    public function test_consent_event_accepted(): void
    {
        $this->postEvent([
            'type' => 'consent_event',
            'details' => 'accept',
        ])->assertStatus(200);
    }

    public function test_analytics_event_requires_event_name(): void
    {
        // Sans event_name → 422
        $this->postEvent([
            'type' => 'analytics',
        ])->assertStatus(422);
    }

    public function test_analytics_accepts_whitelisted_event_name(): void
    {
        $this->postEvent([
            'type' => 'analytics',
            'event_name' => 'add_to_cart',
            'payload' => [
                'item_id' => 'xyz',
                'qty' => 1,
                'extras_total_cents' => 0,
                'line_total_cents' => 950,
                'has_menu_upgrade' => false,
            ],
            'session_id' => '11111111-2222-4333-8444-555555555555',
        ])->assertStatus(200);
    }

    public function test_analytics_accepts_composer_heuristic_fallback_event_name(): void
    {
        $this->postEvent([
            'type' => 'analytics',
            'event_name' => 'wizard.composer_heuristic_fallback',
            'payload' => [
                'item_id' => 123,
                'reason' => 'missing_published_composer_profile',
            ],
            'session_id' => '11111111-2222-4333-8444-555555555555',
        ])->assertStatus(200);
    }

    public function test_analytics_rejects_unknown_event_name(): void
    {
        $this->postEvent([
            'type' => 'analytics',
            'event_name' => 'not_a_real_event',
            'payload' => [],
        ])->assertStatus(422);
    }

    public function test_payload_with_email_is_rejected(): void
    {
        $this->postEvent([
            'type' => 'analytics',
            'event_name' => 'add_to_cart',
            'payload' => [
                'item_id' => 'xyz',
                'qty' => 1,
                'email' => 'leaking@example.com',
            ],
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Payload contains forbidden PII keys');
    }

    public function test_payload_with_phone_nested_is_rejected(): void
    {
        $this->postEvent([
            'type' => 'analytics',
            'event_name' => 'add_to_cart',
            'payload' => [
                'item_id' => 'xyz',
                'meta' => ['nested' => ['phone' => '+33600']],
            ],
        ])->assertStatus(422);
    }

    public function test_unknown_top_level_type_is_rejected(): void
    {
        $this->postEvent([
            'type' => 'not_a_type_at_all',
            'details' => 'bad',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Unknown event type');
    }

    public function test_legacy_types_still_accepted_after_phase5(): void
    {
        // Régression : les types historiques fonctionnent toujours.
        $this->postEvent([
            'type' => 'order_abandoned',
            'count' => 1,
        ])->assertStatus(200);

        $this->postEvent([
            'type' => 'cash_instruction_shown',
            'order_ref' => '#000999',
        ])->assertStatus(200);
    }

    public function test_all_6_new_phase5_types_accepted(): void
    {
        foreach (['a11y_settings', 'analytics', 'hardware_health', 'hardware_event', 'hardware_error', 'consent_event', 'idle_event'] as $type) {
            if ($type === 'analytics') {
                $res = $this->postEvent([
                    'type' => 'analytics',
                    'event_name' => 'idle_reset',
                    'payload' => ['had_cart' => false, 'last_screen' => 'menu'],
                ]);
            } else {
                $res = $this->postEvent(['type' => $type, 'details' => 'ok']);
            }
            $res->assertStatus(200, "type=$type should be accepted");
        }
    }
}
