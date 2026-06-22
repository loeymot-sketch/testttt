<?php

namespace Tests\Feature\Observability;

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
 * [X2 / K-9 Phase C] End-to-end `correlation_id` propagation.
 *
 * Validates that:
 *  - the `X-Correlation-ID` header sent by the client is echoed back in
 *    the HTTP response (CorrelationIdMiddleware, registered in both web
 *    and api groups in `app/Http/Kernel.php` L40 + L50),
 *  - if no header is sent, the middleware generates a UUID v4,
 *  - the chain works on both an authenticated route (kiosk upsell) and
 *    an anonymous route (csp-report, ported in C2).
 *
 * Adapted from testttt-kiosk-p93/tests/Feature/Observability/
 * CorrelationIdEndToEndTest.php — pivots from `/kiosk/context` (route
 * not present in this worktree, see RUN_A5_*) to `/api/frontend/upsell`
 * (lightest of the kiosk-authed routes, same auth + ability stack).
 */
class CorrelationIdEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHED_URL    = '/api/frontend/upsell';
    private const ANONYMOUS_URL = '/api/frontend/csp-report';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    private function makeKioskToken(): array
    {
        $branch = Branch::forceCreate([
            'name' => 'Corr', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => 'x', 'status' => 1,
            'available_locales' => ['fr'],
        ]);
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::create([
            'machine_id' => 'KIOSK-CORR',
            'branch_id'  => $branch->id,
            'user_id'    => $user->id,
            'username'   => 'corr',
            'password'   => bcrypt('x'),
            'is_login'   => Ask::NO,
            'status'     => Status::ACTIVE,
        ]);
        $token = $user->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        return ['token' => $token, 'branch_id' => (int) $branch->id];
    }

    public function test_client_provided_correlation_id_is_echoed_in_response_header(): void
    {
        $ctx = $this->makeKioskToken();
        $given = 'client-7a3f-9f14-42d1-bc66-test-uuid';

        $res = $this->withHeaders([
            'Authorization'    => 'Bearer ' . $ctx['token'],
            'X-Correlation-ID' => $given,
        ])->getJson(self::AUTHED_URL);

        $res->assertStatus(200);
        $this->assertSame(
            $given,
            $res->headers->get('X-Correlation-ID'),
            'The client X-Correlation-ID header must be echoed back identically.'
        );
    }

    public function test_missing_correlation_id_is_generated_by_middleware(): void
    {
        $ctx = $this->makeKioskToken();

        $res = $this->withHeaders([
            'Authorization' => 'Bearer ' . $ctx['token'],
        ])->getJson(self::AUTHED_URL);

        $res->assertStatus(200);
        $generated = $res->headers->get('X-Correlation-ID');
        $this->assertNotNull($generated, 'Middleware must generate a correlation_id when none is sent.');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $generated,
            'Generated correlation_id must be a UUID v4.'
        );
    }

    public function test_correlation_id_works_on_anonymous_routes_csp_report(): void
    {
        $given = 'csp-report-correlation-abcd-efgh-1234';

        $res = $this->withHeaders(['X-Correlation-ID' => $given])
            ->postJson(self::ANONYMOUS_URL, ['csp-report' => [
                'document-uri'       => 'https://k',
                'violated-directive' => 'x',
                'blocked-uri'        => '',
            ]]);

        $res->assertStatus(204);
        $this->assertSame(
            $given,
            $res->headers->get('X-Correlation-ID'),
            'Anonymous CSP report endpoint must also propagate correlation_id.'
        );
    }

    public function test_log_listen_does_not_capture_a_different_correlation_id(): void
    {
        $ctx = $this->makeKioskToken();
        $given = 'log-ctx-correlation-test';

        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            $captured[] = $message->context ?? [];
        });

        $this->withHeaders([
            'Authorization'    => 'Bearer ' . $ctx['token'],
            'X-Correlation-ID' => $given,
        ])->getJson(self::AUTHED_URL)->assertStatus(200);

        // Soft assertion: the request may not emit any log, which is fine.
        // Hard guard: any emitted log MUST NOT carry a *different* correlation_id
        // than the one we sent — that would mean withContext was overwritten.
        foreach ($captured as $ctxArr) {
            if (isset($ctxArr['correlation_id']) && $ctxArr['correlation_id'] !== $given) {
                $this->fail(
                    'A log captured a correlation_id different from the one sent: '
                    . var_export($ctxArr['correlation_id'], true) . ' vs ' . $given
                );
            }
        }
        $this->assertTrue(true);
    }
}
