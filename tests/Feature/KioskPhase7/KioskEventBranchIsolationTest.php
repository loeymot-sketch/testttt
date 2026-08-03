<?php

namespace Tests\Feature\KioskPhase7;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 7.4 : Invariant §1 — `branch_id` isolation sur /kiosk/event.
 *
 * Le master prompt §1.2 stipule :
 *   > Toute requête utilise $user->branch_id serveur. Jamais lu dans le payload.
 *
 * Ce test vérifie que même si le frontend injecte un `branch_id` dans le payload
 * (malicious ou bug), le backend continue d'écrire dans ActionLog le `branch_id`
 * réellement associé à la borne via `KioskMachine::where('user_id', $user->id)`.
 *
 * Coverage : les 7 types d'events Phase 5 (analytics + hardware_* + consent_event +
 * idle_event + a11y_settings) + le type admin_action (Phase 7.1).
 */
class KioskEventBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenBranchA;

    private int $branchAId;

    private int $branchBId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        // Branch A — la borne authentifiée.
        $branchA = Branch::forceCreate([
            'name' => 'A', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75', 'address' => 'A1', 'status' => 1,
        ]);
        $userA = User::factory()->create(['branch_id' => $branchA->id]);
        KioskMachine::create([
            'machine_id' => 'kA', 'branch_id' => $branchA->id,
            'user_id' => $userA->id, 'username' => 'ka',
            'password' => bcrypt('x'),
            'is_login' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $this->tokenBranchA = $userA->createToken('ka', ['kiosk:order'])->plainTextToken;
        $this->branchAId = $branchA->id;

        // Branch B — que la borne A ne devrait JAMAIS pouvoir logger.
        $branchB = Branch::forceCreate([
            'name' => 'B', 'city' => 'Lyon', 'state' => 'ARA',
            'zip_code' => '69', 'address' => 'B1', 'status' => 1,
        ]);
        $this->branchBId = $branchB->id;
    }

    private function postEvent(array $body)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->tokenBranchA}"])
            ->postJson('/api/frontend/kiosk/event', $body);
    }

    public function test_analytics_event_with_forged_branch_id_logs_server_branch_A(): void
    {
        $this->postEvent([
            'type' => 'analytics',
            'event_name' => 'menu_viewed',
            // FORGE : la borne A tente de se faire passer pour B.
            'branch_id' => $this->branchBId,
            'payload' => ['screen' => 'menu'],
        ])->assertStatus(200);

        $log = ActionLog::latest()->first();
        // Le champ `branch=` doit désormais refléter la branche serveur
        // autoritaire, et le branch forgé ne vit que dans la méta forensic.
        $this->assertStringContainsString('branch=' . $this->branchAId, $log->details);
        $this->assertStringContainsString('"branch_id_claimed":' . $this->branchBId, $log->details);
        // MAIS le user_id persisté reste celui du token A — impossible de
        // voler l'identité d'un autre user.
        $userA = User::where('branch_id', $this->branchAId)->first();
        $this->assertSame($userA->id, $log->user_id);
    }

    public function test_branch_id_fallback_to_machine_branch_when_payload_absent(): void
    {
        $this->postEvent([
            'type' => 'hardware_health',
            'details' => 'ok',
            'payload' => ['state' => 'ok'],
        ])->assertStatus(200);

        $log = ActionLog::latest()->first();
        // Quand pas de branch_id injecté, le controller fallback sur la
        // branch de la borne (via KioskMachine::user_id). C'est l'origine
        // correcte.
        $this->assertStringContainsString('branch=' . $this->branchAId, $log->details);
    }

    public function test_unauthenticated_cannot_post_any_event(): void
    {
        $this->postJson('/api/frontend/kiosk/event', [
            'type' => 'analytics',
            'event_name' => 'menu_viewed',
        ])->assertStatus(401);
    }

    public function test_token_without_kiosk_order_ability_is_rejected(): void
    {
        // [T08b] route now ability-gated.
        // Auparavant : un token sanctum valide sans ability `kiosk:order` passait (200).
        // Désormais : middleware `abilities:kiosk:order` ajouté sur /kiosk-event ET /kiosk/event
        // → tout token sans cette ability est rejeté en 403 (fail-closed).
        $branch = Branch::forceCreate([
            'name' => 'X', 'city' => 'Marseille', 'state' => 'PACA',
            'zip_code' => '13', 'address' => 'X', 'status' => 1,
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $wideToken = $user->createToken('wrong', ['some:other:ability'])->plainTextToken;

        $res = $this->withHeaders(['Authorization' => "Bearer $wideToken"])
            ->postJson('/api/frontend/kiosk/event', [
                'type' => 'analytics',
                'event_name' => 'menu_viewed',
            ]);
        $this->assertSame(403, $res->status(), '[T08b] token without kiosk:order ability must be rejected.');
    }

    public function test_all_phase5_types_respect_branch_isolation(): void
    {
        // Pour chaque type Phase 5, vérifier que le user_id loggué reste celui
        // de la borne authentifiée, quelle que soit la tentative d'injection.
        $userA = User::where('branch_id', $this->branchAId)->first();

        $scenarios = [
            ['type' => 'a11y_settings', 'details' => 'contrast:aaa'],
            ['type' => 'hardware_health', 'details' => 'ok'],
            ['type' => 'hardware_event', 'details' => 'printer_jam'],
            ['type' => 'hardware_error', 'details' => 'tpe_timeout'],
            ['type' => 'consent_event', 'details' => 'accept'],
            ['type' => 'idle_event', 'details' => 'warning_shown'],
            ['type' => 'admin_action', 'subtype' => 'idle_timeouts'],
        ];

        foreach ($scenarios as $scenario) {
            $body = array_merge($scenario, [
                'branch_id' => $this->branchBId, // tentative d'injection
            ]);
            $response = $this->postEvent($body);
            $response->assertStatus(200);

            $log = ActionLog::latest()->first();
            $this->assertSame(
                $userA->id,
                $log->user_id,
                "Le user_id loggué doit toujours être la borne A pour type={$scenario['type']}"
            );
        }
    }
}
