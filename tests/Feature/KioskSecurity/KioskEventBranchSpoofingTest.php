<?php

namespace Tests\Feature\KioskSecurity;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskEventBranchSpoofingTest extends TestCase
{
    use RefreshDatabase;

    private string $tokenBranchA;

    private int $branchAId;

    private int $branchBId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $branchA = Branch::forceCreate([
            'name' => 'A',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75',
            'address' => 'A1',
            'status' => 1,
        ]);

        $userA = User::factory()->create(['branch_id' => $branchA->id]);

        KioskMachine::create([
            'machine_id' => 'kA',
            'branch_id' => $branchA->id,
            'user_id' => $userA->id,
            'username' => 'ka',
            'password' => bcrypt('x'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        $this->tokenBranchA = $userA->createToken('ka', ['kiosk:order'])->plainTextToken;
        $this->branchAId = $branchA->id;

        $branchB = Branch::forceCreate([
            'name' => 'B',
            'city' => 'Lyon',
            'state' => 'ARA',
            'zip_code' => '69',
            'address' => 'B1',
            'status' => 1,
        ]);

        $this->branchBId = $branchB->id;
    }

    private function postEvent(string $url, array $body, array $headers = [])
    {
        return $this
            ->withHeaders(array_merge([
                'Authorization' => "Bearer {$this->tokenBranchA}",
            ], $headers))
            ->postJson($url, $body);
    }

    private function assertLatestActionLogUsesServerBranch(): ActionLog
    {
        /** @var ActionLog $log */
        $log = ActionLog::latest()->firstOrFail();

        $this->assertMatchesRegularExpression('/\bbranch=' . $this->branchAId . '\b/', $log->details);
        $this->assertDoesNotMatchRegularExpression('/\bbranch=' . $this->branchBId . '\b/', $log->details);

        return $log;
    }

    private function readSecurityLogContents(): string
    {
        $contents = [];

        foreach (glob(storage_path('logs/security*.log')) ?: [] as $path) {
            $contents[] = file_get_contents($path) ?: '';
        }

        return implode("\n", $contents);
    }

    public function test_match_no_security_log_branch_authoritative(): void
    {
        $requestId = 'req-k6-match-' . uniqid();
        $before = $this->readSecurityLogContents();

        $this->postEvent('/api/frontend/kiosk-event', [
            'type' => 'analytics',
            'event_name' => 'menu_viewed',
            'branch_id' => $this->branchAId,
            'payload' => ['screen' => 'menu'],
        ], [
            'X-Request-Id' => $requestId,
        ])->assertStatus(200);

        $log = $this->assertLatestActionLogUsesServerBranch();
        $this->assertStringNotContainsString('"branch_id_claimed"', $log->details);
        $this->assertStringNotContainsString($requestId, str_replace($before, '', $this->readSecurityLogContents()));
    }

    public function test_mismatch_logs_security_and_uses_server_branch_kiosk_event_route(): void
    {
        $requestId = 'req-k6-2-' . uniqid();
        $before = $this->readSecurityLogContents();

        $this->postEvent('/api/frontend/kiosk-event', [
            'type' => 'analytics',
            'event_name' => 'menu_viewed',
            'branch_id' => $this->branchBId,
            'payload' => ['screen' => 'menu'],
        ], [
            'X-Request-Id' => $requestId,
        ])->assertStatus(200);

        $log = $this->assertLatestActionLogUsesServerBranch();
        $this->assertStringContainsString('"branch_id_claimed":' . $this->branchBId, $log->details);

        $securityDelta = str_replace($before, '', $this->readSecurityLogContents());
        $this->assertStringContainsString('Kiosk branch_id mismatch detected', $securityDelta);
        $this->assertStringContainsString('kiosk.branch_mismatch', $securityDelta);
        $this->assertStringContainsString('server_branch_id', $securityDelta);
        $this->assertStringContainsString('claimed_branch_id', $securityDelta);
        $this->assertStringContainsString('api/frontend/kiosk-event', $securityDelta);
        $this->assertStringContainsString($requestId, $securityDelta);
    }

    public function test_mismatch_logs_security_and_uses_server_branch_kiosk_slash_event_route(): void
    {
        $requestId = 'req-k6-3-' . uniqid();
        $before = $this->readSecurityLogContents();

        $this->postEvent('/api/frontend/kiosk/event', [
            'type' => 'analytics',
            'event_name' => 'menu_viewed',
            'branch_id' => $this->branchBId,
            'payload' => ['screen' => 'menu'],
        ], [
            'X-Request-Id' => $requestId,
        ])->assertStatus(200);

        $log = $this->assertLatestActionLogUsesServerBranch();
        $this->assertStringContainsString('"branch_id_claimed":' . $this->branchBId, $log->details);

        $securityDelta = str_replace($before, '', $this->readSecurityLogContents());
        $this->assertStringContainsString('Kiosk branch_id mismatch detected', $securityDelta);
        $this->assertStringContainsString('kiosk.branch_mismatch', $securityDelta);
        $this->assertStringContainsString('server_branch_id', $securityDelta);
        $this->assertStringContainsString('claimed_branch_id', $securityDelta);
        $this->assertStringContainsString('api/frontend/kiosk/event', $securityDelta);
        $this->assertStringContainsString($requestId, $securityDelta);
    }

    public function test_no_branch_id_in_payload_uses_server_branch_no_security_log(): void
    {
        $requestId = 'req-k6-nobranch-' . uniqid();
        $before = $this->readSecurityLogContents();

        $this->postEvent('/api/frontend/kiosk/event', [
            'type' => 'analytics',
            'event_name' => 'menu_viewed',
            'payload' => ['screen' => 'menu'],
        ], [
            'X-Request-Id' => $requestId,
        ])->assertStatus(200);

        $log = $this->assertLatestActionLogUsesServerBranch();
        $this->assertStringNotContainsString('"branch_id_claimed"', $log->details);
        $this->assertStringNotContainsString($requestId, str_replace($before, '', $this->readSecurityLogContents()));
    }
}
