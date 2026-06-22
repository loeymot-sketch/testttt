<?php

namespace Tests\Feature\Abuse;

use App\Http\Middleware\IdempotencyKeyMiddleware;
use App\Models\User;
use App\Services\Idempotency\IdempotencyKeyRepository;
use App\Services\Idempotency\IdempotencyRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * VECTOR "idempotency" — ABUSE suite (sub-agent: idempotency).
 *
 * Targets the L7 IdempotencyKeyMiddleware (FROZEN — test-only) behaviours NOT
 * already pinned by tests/Feature/Idempotency/IdempotencyMiddlewareTest.php:
 *
 *   A. In-flight TWIN (PENDING placeholder, handler still running) → 425.
 *   B. Non-2xx responses are NOT cached → a 4xx/5xx then retry re-EXECUTES
 *      (intended: a failed mutation must be retryable). We pin it so a future
 *      change that starts caching errors is caught.
 *   C. Handler throwing → key RELEASED → retry re-executes (no permanent trap).
 *   D. In-flight twin with a DIFFERENT payload → 409 conflict (not 425, not
 *      silent re-exec).
 *   E. Method gate: a GET is never guarded even on a "required" route name.
 *   F. Key trim collision: "  key  " and "key" collapse to the SAME scoped key
 *      (trim) — a replay across whitespace variants. Documents the surface.
 *
 * NOTE on concurrency limits (:memory:/array): these tests drive the PENDING /
 * race branches by binding a repository stub that simulates a twin already
 * holding the key. A TRUE multi-process race is covered by the orchestrator
 * RECIPE against :8766 MySQL+Redis.
 */
class IdempotencyMiddlewareAbuseTest extends TestCase
{
    use RefreshDatabase;

    private static int $execCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        Config::set('idempotency.enabled', true);
        Config::set('idempotency.ttl_seconds', 3600);
        Config::set('idempotency.pending_ttl_seconds', 30);
        Config::set('idempotency.race_wait_ms', 50);
        Config::set('idempotency.required_routes', ['__abuse/required']);

        Cache::flush();
        self::$execCount = 0;

        Route::middleware(['api', 'auth:sanctum', 'idempotency'])
            ->prefix('__abuse')
            ->group(function (): void {
                Route::post('/required', function (\Illuminate\Http\Request $r) {
                    self::$execCount++;
                    return new JsonResponse(['ok' => true, 'count' => self::$execCount], 201);
                });
                // route that returns a 4xx (business validation failure)
                Route::post('/fails', function () {
                    self::$execCount++;
                    return new JsonResponse(['ok' => false], 422);
                });
                // route that throws (handler-level exception)
                Route::post('/throws', function () {
                    self::$execCount++;
                    throw new \RuntimeException('boom');
                });
                // GET on a "required"-named path — method gate must let it pass
                Route::get('/required', function () {
                    self::$execCount++;
                    return new JsonResponse(['ok' => true, 'method' => 'GET'], 200);
                });
            });
    }

    private function makeUser(int $branchId): User
    {
        return User::factory()->create(['branch_id' => $branchId]);
    }

    /**
     * Bind a repository whose acquire() always returns false (key already held
     * by a twin) and whose find()/waitForCompletion() behaviour is configurable
     * to simulate the twin's state (still PENDING, or COMPLETED, or conflicting).
     */
    private function bindTwinHolding(?IdempotencyRecord $completedRecord, string $twinPayloadHash = null): void
    {
        $this->app->bind(IdempotencyKeyRepository::class, function () use ($completedRecord, $twinPayloadHash) {
            return new class($completedRecord, $twinPayloadHash) implements IdempotencyKeyRepository {
                public function __construct(
                    private ?IdempotencyRecord $rec,
                    private ?string $twinHash,
                ) {}

                public function find(string $scopedKey): ?IdempotencyRecord
                {
                    return $this->rec; // null = nothing COMPLETED yet (PENDING)
                }

                public function acquire(string $scopedKey, string $payloadHash, int $ttlSeconds): bool
                {
                    return false; // twin already holds the key
                }

                public function complete(string $scopedKey, \Symfony\Component\HttpFoundation\Response $response, string $payloadHash, int $ttlSeconds): void {}

                public function release(string $scopedKey): void {}

                public function waitForCompletion(string $scopedKey, int $waitMs): ?IdempotencyRecord
                {
                    return $this->rec; // null = twin still running after wait
                }
            };
        });
    }

    /** A. In-flight twin still PENDING after race wait → 425 IN_FLIGHT, no exec. */
    public function test_inflight_twin_pending_returns_425_and_does_not_execute(): void
    {
        $this->bindTwinHolding(null);
        $user = $this->makeUser(3);

        $r = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'abuse-inflight-001')
            ->postJson('/__abuse/required', ['x' => 1]);

        $r->assertStatus(425)->assertJson(['code' => 'IDEMPOTENCY_IN_FLIGHT']);
        $this->assertSame(0, self::$execCount, 'In-flight 425 must NOT reach handler.');
    }

    /** D. In-flight twin completes with DIFFERENT payload during wait → 409. */
    public function test_inflight_twin_completed_with_different_payload_returns_409(): void
    {
        $twin = new IdempotencyRecord(
            status: 201,
            headers: ['Content-Type' => ['application/json']],
            bodyBase64: base64_encode('{"ok":true}'),
            payloadHash: hash('sha256', 'OTHER-PAYLOAD'),
            createdAt: now()->toIso8601String(),
            state: 'COMPLETED',
        );
        $this->bindTwinHolding($twin);
        $user = $this->makeUser(3);

        $r = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'abuse-conflict-race')
            ->postJson('/__abuse/required', ['x' => 2]); // different content

        $r->assertStatus(409)->assertJson(['code' => 'IDEMPOTENCY_KEY_CONFLICT']);
        $this->assertSame(0, self::$execCount);
    }

    /**
     * B. A 4xx response is NOT cached → retry RE-EXECUTES the handler.
     * This is the intended contract (a failed mutation must be retryable),
     * pinned so nobody silently starts caching errors as replayable 2xx.
     */
    public function test_4xx_is_not_cached_retry_re_executes(): void
    {
        $user = $this->makeUser(3);
        $key  = 'abuse-4xx-not-cached';

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__abuse/fails', ['x' => 1]);
        $first->assertStatus(422);

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__abuse/fails', ['x' => 1]);
        $second->assertStatus(422);

        $this->assertNull($second->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER),
            '4xx must not be replayed as a cached response.');
        $this->assertSame(2, self::$execCount, 'Both 4xx attempts must reach the handler (retryable).');
    }

    /** C. Handler throws → key released → retry re-executes (no permanent trap). */
    public function test_thrown_handler_releases_key_and_retry_re_executes(): void
    {
        $user = $this->makeUser(3);
        $key  = 'abuse-throw-release';

        try {
            $this->actingAs($user, 'sanctum')
                ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
                ->postJson('/__abuse/throws', ['x' => 1]);
        } catch (\Throwable) {
            // exception propagates out of the route in test harness
        }
        $this->assertSame(1, self::$execCount);

        // Retry: key must have been released, handler re-enters (and throws again).
        try {
            $this->actingAs($user, 'sanctum')
                ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
                ->postJson('/__abuse/throws', ['x' => 1]);
        } catch (\Throwable) {
        }
        $this->assertSame(2, self::$execCount,
            'After a throw the key must be released so a retry re-executes — no permanent 425 trap.');
    }

    /** E. Method gate: a GET on a required-named route is never guarded. */
    public function test_get_request_is_never_idempotency_guarded(): void
    {
        $user = $this->makeUser(3);

        // No header at all — a GET must pass the idempotency gate even though the
        // path is "required". The middleware short-circuits on non-mutating verbs
        // (line 45), so it must NOT emit a 422 MISSING_IDEMPOTENCY_KEY nor a 425.
        $r = $this->actingAs($user, 'sanctum')->getJson('/__abuse/required');
        // Load-bearing assertion: the idempotency middleware must NOT reject the
        // GET for a missing key (no 422 MISSING_IDEMPOTENCY_KEY, no 425). It
        // short-circuits on non-mutating verbs at line 45.
        $this->assertNotSame(422, $r->getStatusCode(),
            'GET must not be rejected for a missing idempotency key.');
        $this->assertNotSame(425, $r->getStatusCode());
    }

    /**
     * F. Key trim collision: leading/trailing whitespace is trimmed, so
     * "  key123456  " and "key123456" map to the SAME scoped key → the second
     * call replays the first. Documents that whitespace is NOT part of identity.
     */
    public function test_whitespace_variants_of_key_collide_and_replay(): void
    {
        $user = $this->makeUser(3);

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'trim-key-12345')
            ->postJson('/__abuse/required', ['x' => 1]);
        $first->assertStatus(201);

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, '   trim-key-12345   ')
            ->postJson('/__abuse/required', ['x' => 1]);
        $second->assertStatus(201);
        $this->assertSame('true', $second->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER),
            'Whitespace-only variant must collide with trimmed key and replay.');
        $this->assertSame(1, self::$execCount, 'Handler executed only once across whitespace variants.');
    }

    /**
     * G. Unauthenticated / branch-unresolvable caller with a key on a required
     * route must be REJECTED (cannot scope the key) — not silently bypassed.
     * branch_id<0 OR user_id<=0 → MissingIdempotencyKeyException (422).
     */
    public function test_unresolvable_scope_with_key_is_rejected_not_bypassed(): void
    {
        // A user with branch_id = -1 (unresolvable) and a key on a NON-required
        // route: the scope guard at middleware lines 70-74 must still 422
        // because a key was supplied but cannot be scoped.
        Config::set('idempotency.required_routes', []); // make route optional
        $user = User::factory()->create(['branch_id' => -1]);

        $r = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'unscopable-key-1')
            ->postJson('/__abuse/required', ['x' => 1]);

        $r->assertStatus(422);
        $this->assertSame(0, self::$execCount,
            'A key that cannot be scoped (branch_id<0) must not silently execute.');
    }
}
