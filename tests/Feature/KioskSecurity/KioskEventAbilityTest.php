<?php

namespace Tests\Feature\KioskSecurity;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [T08b] — `abilities:kiosk:order` enforcement on kiosk event endpoints.
 *
 * Both `/api/frontend/kiosk-event` (legacy hyphen) and `/api/frontend/kiosk/event`
 * (slash alias) MUST require the Sanctum ability `kiosk:order`. Any other valid
 * token (e.g. POS / admin / customer) must be rejected fail-closed (403).
 *
 * Aligns testttt with testttt-kiosk-p93 reference and resolves T08 PARTIAL.
 */
class KioskEventAbilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeKioskUser(): User
    {
        $branch = Branch::forceCreate([
            'name' => 'KSec-'.uniqid(),
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75',
            'address' => 'addr',
            'status' => 1,
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::create([
            'machine_id' => 'm-'.uniqid(),
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'k'.uniqid(),
            'password' => bcrypt('x'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        return $user;
    }

    public function routeProvider(): array
    {
        return [
            'hyphen route' => ['/api/frontend/kiosk-event'],
            'slash route' => ['/api/frontend/kiosk/event'],
        ];
    }

    /** @dataProvider routeProvider */
    public function test_route_rejects_token_without_kiosk_order_ability(string $url): void
    {
        $user = $this->makeKioskUser();
        $token = $user->createToken('wrong', ['some:other:ability'])->plainTextToken;

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson($url, [
                'type' => 'analytics',
                'event_name' => 'menu_viewed',
            ]);

        $this->assertSame(403, $res->status(), "[$url] token sans kiosk:order doit être 403.");
    }

    /** @dataProvider routeProvider */
    public function test_route_accepts_token_with_kiosk_order_ability(string $url): void
    {
        $user = $this->makeKioskUser();
        $token = $user->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson($url, [
                'type' => 'analytics',
                'event_name' => 'menu_viewed',
            ]);

        $this->assertSame(200, $res->status(), "[$url] token avec kiosk:order doit être 200.");
    }

    /** @dataProvider routeProvider */
    public function test_route_rejects_unauthenticated_request(string $url): void
    {
        $res = $this->postJson($url, [
            'type' => 'analytics',
            'event_name' => 'menu_viewed',
        ]);

        $this->assertSame(401, $res->status(), "[$url] sans token → 401.");
    }
}
