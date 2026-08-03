<?php

namespace Tests\Feature\KioskMultiBranch;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /**
     * [C5] Denials must be logged to the `observability` channel so canary
     * dashboards can detect misconfigured kiosks (drift between
     * `Branch.available_locales` and the locale the front actually requests).
     */
    public function test_off_allowlist_denial_is_logged_to_observability_channel(): void
    {
        $captured = $this->captureObservabilityLogs();

        $ctx = $this->makeMachine(['fr', 'en']);
        $this->withHeaders([
            'Authorization'  => "Bearer {$ctx['token']}",
            'X-Kiosk-Locale' => 'ar',
        ])->getJson(self::URL)->assertStatus(400);

        $hits = array_values(array_filter(
            $captured->entries,
            fn ($e) => ($e[1] ?? null) === 'kiosk_locale.not_allowed'
        ));
        $this->assertNotEmpty($hits, 'Expected an observability log for the denied locale.');
        $this->assertSame('kiosk_locale_rejected', $hits[0][2]['category']);
        $this->assertSame('LOCALE_NOT_ALLOWED_FOR_BRANCH', $hits[0][2]['reason']);
        $this->assertSame('ar', $hits[0][2]['requested']);
        $this->assertSame(['fr', 'en'], $hits[0][2]['allowed']);
        $this->assertSame((int) $ctx['branch']->id, $hits[0][2]['branch_id']);
        $this->assertSame('frontend.frontend.upsell.suggest', $hits[0][2]['route_name']);
    }

    public function test_malformed_locale_is_also_logged_to_observability_channel(): void
    {
        $captured = $this->captureObservabilityLogs();

        $ctx = $this->makeMachine(['fr', 'en']);
        $this->withHeaders([
            'Authorization'  => "Bearer {$ctx['token']}",
            'X-Kiosk-Locale' => 'WTF-not-a-locale',
        ])->getJson(self::URL)->assertStatus(400);

        $hits = array_values(array_filter(
            $captured->entries,
            fn ($e) => ($e[1] ?? null) === 'kiosk_locale.format_invalid'
        ));
        $this->assertNotEmpty($hits, 'Expected an observability log for the malformed locale.');
        $this->assertSame('kiosk_locale_rejected', $hits[0][2]['category']);
        $this->assertSame('LOCALE_FORMAT_INVALID', $hits[0][2]['reason']);
        $this->assertSame('WTF-not-a-locale', $hits[0][2]['requested']);
    }

    /**
     * Swap the `observability` channel with an in-memory capture so we can
     * inspect what the middleware emitted without touching real log files.
     * Mirrors the pattern used in CspReportEndpointTest.
     */
    private function captureObservabilityLogs(): object
    {
        $captured = new class {
            /** @var array<int, array{0:string,1:string,2:array}> */
            public array $entries = [];
        };

        $sink = new class($captured) {
            private object $store;
            public function __construct(object $s) { $this->store = $s; }
            public function info(string $msg, array $ctx = []): void { $this->store->entries[] = ['info', $msg, $ctx]; }
            public function warning(string $msg, array $ctx = []): void { $this->store->entries[] = ['warning', $msg, $ctx]; }
            public function error(string $msg, array $ctx = []): void { $this->store->entries[] = ['error', $msg, $ctx]; }
            public function alert(string $msg, array $ctx = []): void { $this->store->entries[] = ['alert', $msg, $ctx]; }
            public function log(string $level, string $msg, array $ctx = []): void { $this->store->entries[] = [$level, $msg, $ctx]; }
            public function __call(string $m, array $args): void { $this->store->entries[] = [$m, (string) ($args[0] ?? ''), (array) ($args[1] ?? [])]; }
        };

        $manager = app('log');
        Log::swap(new class($manager, $sink) {
            private $real;
            private $sink;
            public function __construct($r, $s) { $this->real = $r; $this->sink = $s; }
            public function channel(?string $name = null) {
                if ($name === 'observability') return $this->sink;
                return $this->real->channel($name);
            }
            public function stack(array $c, ?string $n = null) { return $this->real->stack($c, $n); }
            public function driver(?string $n = null) { return $this->real->driver($n); }
            public function __call($m, $args) { return $this->real->{$m}(...$args); }
        });

        return $captured;
    }
}
