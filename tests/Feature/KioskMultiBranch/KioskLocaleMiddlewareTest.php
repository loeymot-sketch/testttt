<?php

namespace Tests\Feature\KioskMultiBranch;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [C4 / K-8] Validates the `kiosk.locale` middleware.
 *
 * A locale outside `Branch.available_locales` must be rejected with
 * HTTP 400 + structured code `LOCALE_NOT_ALLOWED_FOR_BRANCH`. Locale
 * absent ⇒ passthrough. Malformed locale ⇒ 400 LOCALE_FORMAT_INVALID.
 *
 * Target endpoint: `/api/frontend/upsell` (frontend.upsell.suggest) —
 * lightest of the 4 endpoints carrying `kiosk.locale`. The same
 * invariants apply to /menu, /pricing/preview, /promo/validate.
 *
 * Note: this worktree does not expose `/api/frontend/kiosk/context`
 * (a known divergence from testttt-kiosk-p93 — see RUN_A5_*),
 * hence the test pivots to `/upsell` which exists and returns 200
 * with an empty list when no upsell rules are seeded.
 */
class KioskLocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/frontend/upsell';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    /**
     * @return array{branch: Branch, user: User, token: string}
     */
    private function makeMachine(array $locales = ['fr', 'en']): array
    {
        $branch = Branch::forceCreate([
            'name' => 'Locale', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75', 'address' => 'A', 'status' => 1,
            'available_locales' => $locales,
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::create([
            'machine_id' => 'KIOSK-LOC',
            'branch_id'  => $branch->id,
            'user_id'    => $user->id,
            'username'   => 'ka',
            'password'   => bcrypt('x'),
            'is_login'   => Ask::NO,
            'status'     => Status::ACTIVE,
        ]);
        $token = $user->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        return compact('branch', 'user', 'token');
    }

    public function test_no_locale_header_nor_query_passes_through(): void
    {
        $ctx = $this->makeMachine(['fr', 'en']);
        $this->withHeaders(['Authorization' => "Bearer {$ctx['token']}"])
            ->getJson(self::URL)
            ->assertStatus(200);
    }

    public function test_allowed_locale_via_header_is_accepted(): void
    {
        $ctx = $this->makeMachine(['fr', 'en', 'ar']);
        $this->withHeaders([
            'Authorization'  => "Bearer {$ctx['token']}",
            'X-Kiosk-Locale' => 'ar',
        ])->getJson(self::URL)->assertStatus(200);
    }

    public function test_denied_locale_via_header_returns_400_with_structured_code(): void
    {
        $ctx = $this->makeMachine(['fr', 'en']);
        $res = $this->withHeaders([
            'Authorization'  => "Bearer {$ctx['token']}",
            'X-Kiosk-Locale' => 'ar',
        ])->getJson(self::URL);

        $res->assertStatus(400);
        $this->assertSame('LOCALE_NOT_ALLOWED_FOR_BRANCH', $res->json('code'));
        $this->assertSame('ar', $res->json('meta.requested'));
        $this->assertSame(['fr', 'en'], $res->json('meta.allowed'));
    }

    public function test_denied_locale_via_query_returns_400(): void
    {
        $ctx = $this->makeMachine(['fr']);
        $res = $this->withHeaders(['Authorization' => "Bearer {$ctx['token']}"])
            ->getJson(self::URL . '?lang=xx');

        $res->assertStatus(400);
        $this->assertSame('LOCALE_NOT_ALLOWED_FOR_BRANCH', $res->json('code'));
    }

    public function test_malformed_locale_is_rejected_400(): void
    {
        $ctx = $this->makeMachine(['fr', 'en']);
        $res = $this->withHeaders([
            'Authorization'  => "Bearer {$ctx['token']}",
            'X-Kiosk-Locale' => 'not-a-locale-code-123',
        ])->getJson(self::URL);

        $res->assertStatus(400);
        $this->assertSame('LOCALE_FORMAT_INVALID', $res->json('code'));
    }

    public function test_header_takes_precedence_over_query(): void
    {
        $ctx = $this->makeMachine(['fr', 'en']);
        // Header = fr (allowed), query = ar (denied) → header wins, 200.
        $this->withHeaders([
            'Authorization'  => "Bearer {$ctx['token']}",
            'X-Kiosk-Locale' => 'fr',
        ])->getJson(self::URL . '?lang=ar')->assertStatus(200);
    }
}
