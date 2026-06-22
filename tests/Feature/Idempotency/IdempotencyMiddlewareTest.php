<?php

namespace Tests\Feature\Idempotency;

use App\Http\Middleware\IdempotencyKeyMiddleware;
use App\Services\Idempotency\IdempotencyKeyRepository;
use App\Services\Idempotency\IdempotencyStorageUnavailableException;
use App\Services\Idempotency\IdempotencyRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * F-VERIFY-09-02 / PLAN_P11 §3.6 — HTTP-level idempotency middleware tests.
 *
 * Covers 8 scenarios: replay, missing/invalid header, opt-in enforcement,
 * branch isolation, TTL expiry, payload conflict, fail-closed and fail-open
 * behaviour when the storage is unavailable.
 *
 * Drives the middleware against synthetic routes (`__test/*`) registered in
 * setUp() — keeps the test independent of POS controller wiring while still
 * exercising the real Kernel + middleware stack.
 */
class IdempotencyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private static int $execCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        Config::set('idempotency.enabled', true);
        Config::set('idempotency.ttl_seconds', 3600);
        Config::set('idempotency.race_wait_ms', 50);
        Config::set('idempotency.required_routes', [
            '__test/required',
        ]);

        Cache::flush();
        self::$execCount = 0;

        // Synthetic routes that exercise the real Kernel middleware stack.
        // Auth:sanctum is mounted at GROUP level so it runs BEFORE the
        // 'idempotency' alias — mirroring the production wrapping in
        // routes/api.php (`prefix('admin')->middleware([..., 'auth:sanctum', ...])`).
        Route::middleware(['api', 'auth:sanctum', 'idempotency'])
            ->prefix('__test')
            ->group(function (): void {
                Route::post('/required', function (\Illuminate\Http\Request $r) {
                    self::$execCount++;
                    return new JsonResponse([
                        'ok'    => true,
                        'count' => self::$execCount,
                        'echo'  => $r->input('echo', null),
                    ], 201);
                });

                Route::post('/optional', function (\Illuminate\Http\Request $r) {
                    self::$execCount++;
                    return new JsonResponse(['ok' => true, 'count' => self::$execCount], 200);
                });
            });
    }

    private function makeUser(int $branchId): User
    {
        return User::factory()->create(['branch_id' => $branchId]);
    }

    public function test_two_identical_posts_create_only_once_and_replay_second(): void
    {
        $user = $this->makeUser(7);
        $key  = 'sentinel-key-001';
        $payload = ['echo' => 'hello'];

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', $payload);

        $first->assertStatus(201)->assertJson(['ok' => true, 'count' => 1, 'echo' => 'hello']);
        $this->assertNull($first->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER));

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', $payload);

        $second->assertStatus(201)->assertJson(['ok' => true, 'count' => 1, 'echo' => 'hello']);
        $this->assertSame('true', $second->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER));
        $this->assertNotNull($second->headers->get(IdempotencyKeyMiddleware::STORED_AT_HEADER));
        $this->assertSame(1, self::$execCount, 'Handler must be invoked exactly once.');
    }

    public function test_post_without_header_on_required_route_returns_422(): void
    {
        $user = $this->makeUser(7);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/__test/required', ['echo' => 'no-header']);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'code'    => 'MISSING_IDEMPOTENCY_KEY',
        ]);
        $this->assertSame(0, self::$execCount);
    }

    public function test_post_without_header_on_unprotected_route_passes_through(): void
    {
        $user = $this->makeUser(7);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/__test/optional', []);

        $response->assertStatus(200)->assertJson(['ok' => true, 'count' => 1]);
    }

    public function test_cross_branch_same_key_results_in_distinct_executions(): void
    {
        $userA = $this->makeUser(1);
        $userB = $this->makeUser(2);
        $key   = 'cross-branch-key';

        $a = $this->actingAs($userA, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', ['echo' => 'A']);
        $b = $this->actingAs($userB, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', ['echo' => 'B']);

        $a->assertStatus(201)->assertJson(['count' => 1, 'echo' => 'A']);
        $b->assertStatus(201)->assertJson(['count' => 2, 'echo' => 'B']);
        $this->assertNull($a->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER));
        $this->assertNull($b->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER));
        $this->assertSame(2, self::$execCount);
    }

    public function test_replay_after_ttl_expired_executes_anew(): void
    {
        Config::set('idempotency.ttl_seconds', 1);

        $user = $this->makeUser(7);
        $key  = 'ttl-expiry-key';

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', ['echo' => 'x']);
        $first->assertStatus(201);

        // Force-expire the cache entry without a real `sleep` (array store).
        Cache::flush();

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', ['echo' => 'x']);
        $second->assertStatus(201);
        $this->assertNull($second->headers->get(IdempotencyKeyMiddleware::REPLAY_HEADER));
        $this->assertSame(2, self::$execCount);
    }

    public function test_same_key_different_payload_returns_409(): void
    {
        $user = $this->makeUser(7);
        $key  = 'conflict-key';

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', ['echo' => 'one']);
        $first->assertStatus(201);

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, $key)
            ->postJson('/__test/required', ['echo' => 'TWO']);
        $second->assertStatus(409)->assertJson([
            'success' => false,
            'code'    => 'IDEMPOTENCY_KEY_CONFLICT',
        ]);
        $this->assertSame('true', $second->headers->get(IdempotencyKeyMiddleware::CONFLICT_HEADER));
        $this->assertSame(1, self::$execCount, 'Second call must NOT reach the handler.');
    }

    public function test_redis_unavailable_fail_closed_returns_503(): void
    {
        Config::set('idempotency.fail_open', false);
        $this->bindBrokenRepository();

        $user = $this->makeUser(7);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'unavailable-key')
            ->postJson('/__test/required', ['echo' => 'x']);

        $response->assertStatus(503)->assertJson([
            'success' => false,
            'code'    => 'IDEMPOTENCY_STORAGE_UNAVAILABLE',
        ]);
        $this->assertSame(0, self::$execCount, 'Handler must NOT execute when storage is down + fail-closed.');
    }

    public function test_redis_unavailable_fail_open_passes_through(): void
    {
        Config::set('idempotency.fail_open', true);
        $this->bindBrokenRepository();

        $user = $this->makeUser(7);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader(IdempotencyKeyMiddleware::HEADER, 'fail-open-key')
            ->postJson('/__test/required', ['echo' => 'x']);

        $response->assertStatus(201)->assertJson(['count' => 1]);
        $this->assertSame(1, self::$execCount);
    }

    private function bindBrokenRepository(): void
    {
        $this->app->bind(IdempotencyKeyRepository::class, function () {
            return new class implements IdempotencyKeyRepository {
                public function find(string $scopedKey): ?IdempotencyRecord
                {
                    throw new IdempotencyStorageUnavailableException('storage down');
                }

                public function acquire(string $scopedKey, string $payloadHash, int $ttlSeconds): bool
                {
                    throw new IdempotencyStorageUnavailableException('storage down');
                }

                public function complete(string $scopedKey, \Symfony\Component\HttpFoundation\Response $response, string $payloadHash, int $ttlSeconds): void
                {
                }

                public function release(string $scopedKey): void
                {
                }

                public function waitForCompletion(string $scopedKey, int $waitMs): ?IdempotencyRecord
                {
                    return null;
                }
            };
        });
    }
}
