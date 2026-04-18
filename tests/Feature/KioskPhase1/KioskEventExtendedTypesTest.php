<?php

namespace Tests\Feature\KioskPhase1;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 3 : nouveaux types d'events (CashInstruction + 4
 * écrans erreur) acceptés par `KioskEventController::store`.
 * Validation : types whitelist, métadata structurée loggée proprement.
 */
class KioskEventExtendedTypesTest extends TestCase
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

    public function test_cash_instruction_shown_type_accepted(): void
    {
        $this->postEvent([
            'type' => 'cash_instruction_shown',
            'order_ref' => '#000123',
        ])->assertStatus(200);

        $this->assertDatabaseHas('action_logs', [
            'action' => 'Kiosk event: cash_instruction_shown',
        ]);
    }

    public function test_cash_instruction_ack_with_reason(): void
    {
        $this->postEvent([
            'type' => 'cash_instruction_ack',
            'reason' => 'user',
            'order_ref' => '#000123',
        ])->assertStatus(200);

        $log = ActionLog::latest()->first();
        $this->assertStringContainsString('meta=', $log->details);
        $this->assertStringContainsString('user', $log->details);
    }

    public function test_all_4_error_subtypes_accepted(): void
    {
        $subtypes = ['network', 'menu_unavailable', 'product_removed', 'payment_refused'];
        foreach ($subtypes as $subtype) {
            $this->postEvent([
                'type' => 'error_shown',
                'subtype' => $subtype,
            ])->assertStatus(200, "subtype {$subtype} rejeté");
        }
    }

    public function test_error_context_payload_serialized_to_json(): void
    {
        $this->postEvent([
            'type' => 'error_shown',
            'subtype' => 'product_removed',
            'context' => ['item_id' => 42, 'sku' => 'ABC'],
        ])->assertStatus(200);

        $log = ActionLog::latest()->first();
        $this->assertStringContainsString('item_id', $log->details);
        $this->assertStringContainsString('42', $log->details);
    }

    public function test_unknown_type_rejected(): void
    {
        $this->postEvent([
            'type' => 'totally_bogus_type_xyz',
        ])->assertStatus(422);
    }

    public function test_details_overflow_truncated(): void
    {
        $bigContext = ['msg' => str_repeat('X', 1000)];
        $this->postEvent([
            'type' => 'error_shown',
            'subtype' => 'network',
            'context' => $bigContext,
        ])->assertStatus(200);

        $log = ActionLog::latest()->first();
        $this->assertLessThanOrEqual(500, mb_strlen($log->details),
            'details doit être plafonné à 500 chars pour protéger la colonne DB.');
    }

    public function test_context_array_rejected_when_not_array(): void
    {
        $this->postEvent([
            'type' => 'error_shown',
            'subtype' => 'network',
            'context' => 'injection_attempt_string',
        ])->assertStatus(422);
    }

    public function test_legacy_types_still_work(): void
    {
        // Garantie non-régression des types historiques.
        foreach (['order_abandoned', 'sync_failed', 'auth_error', 'menu_cache_used', 'admin_action'] as $t) {
            $this->postEvent(['type' => $t])->assertStatus(200, "legacy {$t} cassé");
        }
    }
}
