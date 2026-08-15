<?php

namespace Tests\Feature\Security;

use App\Services\Idempotency\IdempotencyKeyRepository;
use App\Services\Idempotency\RedisIdempotencyKeyRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Sentinel for HEAL F2-HEAL-04 / Phase F.6 finding F-6-6-FIND-04 P2.
 *
 * PROBLEM the heal addresses:
 *   IdempotencyKeyMiddleware::handle() Phase-2 calls Repository::acquire(
 *     $scopedKey, $payloadHash, config('idempotency.ttl_seconds', 86400)
 *   ). Before this fix, the Repository wrote the PENDING placeholder with
 *   the same 86400s TTL. If PHP-FPM was SIGKILL'd or the server restarted
 *   between Phase-2 acquire() and Phase-3 release(), the placeholder lived
 *   for 24h. Every legitimate client retry with the same Idempotency-Key
 *   received `425 IDEMPOTENCY_IN_FLIGHT` for 24 hours — stuck UX even
 *   though DB rollback / fiscal_sequence allocation correctness was intact.
 *
 * GUARANTEES enforced by this sentinel:
 *   1. `config('idempotency.pending_ttl_seconds')` is set and defaults to 30.
 *   2. `RedisIdempotencyKeyRepository::acquire()` reads from that config key
 *      (source-level assertion — catches future regressions that revert to
 *      the caller-supplied $ttlSeconds).
 *   3. Behavior: with `pending_ttl_seconds=1`, the placeholder self-expires
 *      after ~1s. A subsequent acquire() with the SAME key SUCCEEDS without
 *      a manual release() call. Before this fix it would FAIL until 86400s.
 *
 * Test driver: array cache store (the same one used by IdempotencyMiddlewareTest).
 * Array store respects TTL in real-time seconds; no Carbon::setTestNow magic
 * is needed because Cache::add() uses microtime() internally on this driver.
 */
final class IdempotencyPendingTtlSentinel extends TestCase
{
    public function test_config_key_present_and_defaults_to_30_seconds(): void
    {
        $value = config('idempotency.pending_ttl_seconds');

        $this->assertNotNull(
            $value,
            'config/idempotency.php must declare `pending_ttl_seconds` so the '
            . 'Repository can decouple PENDING-placeholder TTL from '
            . 'COMPLETED-cache TTL. See HEAL F2-HEAL-04.'
        );
        $this->assertIsInt($value);
        $this->assertSame(
            30,
            $value,
            'Default `pending_ttl_seconds` must remain 30. Raising it widens '
            . 'the SIGKILL stuck-window; lowering it under `race_wait_ms` '
            . '(1500ms) breaks the in-flight race wait.'
        );
    }

    public function test_pending_ttl_must_exceed_race_wait_to_keep_in_flight_race_intact(): void
    {
        $pendingSec = (int) config('idempotency.pending_ttl_seconds', 30);
        $raceMs     = (int) config('idempotency.race_wait_ms', 1500);

        $this->assertGreaterThan(
            $raceMs / 1000,
            $pendingSec,
            'pending_ttl_seconds must exceed race_wait_ms so a concurrent '
            . 'caller in waitForCompletion() never out-waits the PENDING '
            . 'placeholder (would erroneously return 425 instead of replay).'
        );
    }

    public function test_repository_acquire_source_reads_pending_ttl_config(): void
    {
        $source = file_get_contents(
            base_path('app/Services/Idempotency/RedisIdempotencyKeyRepository.php')
        );

        $this->assertStringContainsString(
            "config('idempotency.pending_ttl_seconds'",
            $source,
            'RedisIdempotencyKeyRepository::acquire() must read '
            . '`idempotency.pending_ttl_seconds` from config so the PENDING '
            . 'placeholder uses the decoupled short TTL. Reverting to '
            . '$ttlSeconds re-introduces the 24h-stuck bug (F-6-6-FIND-04 P2).'
        );

        // Belt-and-braces: ensure the cache `add()` call is NOT using the
        // raw $ttlSeconds parameter on its third argument any more (the line
        // is now `add($scopedKey, $placeholder, $pendingTtl)`).
        $this->assertMatchesRegularExpression(
            '/->add\(\s*\$scopedKey,\s*\$placeholder,\s*\$pendingTtl\s*\)/',
            $source,
            'Cache add() inside acquire() must use the local $pendingTtl '
            . 'variable, not $ttlSeconds. See HEAL F2-HEAL-04.'
        );
    }

    public function test_orphan_pending_placeholder_self_expires_so_retry_succeeds(): void
    {
        // Force a 1-second PENDING TTL so the sentinel can verify expiration
        // within the test budget. Production default is 30s; race_wait_ms
        // (1500ms) is irrelevant here because we never invoke the middleware.
        Config::set('idempotency.pending_ttl_seconds', 1);
        Config::set('cache.default', 'array');

        // Flush array store to keep cross-test isolation.
        Cache::store('array')->flush();

        /** @var IdempotencyKeyRepository $repo */
        $repo = new RedisIdempotencyKeyRepository(cacheStore: 'array');

        $scopedKey   = 'idempotency:v1:1:1:test-sigkill-scenario';
        $payloadHash = hash('sha256', '{"order":"x"}');

        // Step 1 — initial acquire mimics Phase-2 of the middleware.
        $firstAcquire = $repo->acquire($scopedKey, $payloadHash, 86400);
        $this->assertTrue(
            $firstAcquire,
            'First acquire() must succeed (key is fresh).'
        );

        // Step 2 — Simulate the failure mode. Caller is SIGKILL'd; Phase-3
        // release() never runs. The orphan PENDING placeholder is now sitting
        // in cache. Before this fix, it would persist for 86400s.
        // We do NOT call release(); we just wait past the PENDING TTL.
        // NOTE: Laravel's array store stores `expiresAt` as an INTEGER unix
        // timestamp via `Carbon::now()->addRealSeconds(1)->getTimestamp()`.
        // Expiration check is `currentTime() > expiresAt` (strict >, also int
        // seconds). So acquiring at T=12345.7 → expiresAt=12346. Sleeping
        // 1.2s reaches T=12346.9 → currentTime()=12346 → NOT > 12346.
        // We must sleep >=2.1s to guarantee currentTime() advances past
        // the stored expiresAt boundary on every test run regardless of
        // sub-second alignment.
        usleep(2_100_000); // 2.1s — guaranteed > 1s TTL even at second-boundary.

        // Step 3 — Client retry with the same key.
        $secondAcquire = $repo->acquire($scopedKey, $payloadHash, 86400);

        $this->assertTrue(
            $secondAcquire,
            'Second acquire() after PENDING TTL expiry MUST succeed without '
            . 'a manual release(). If this fails, orphan PENDING placeholders '
            . 'are stuck for the COMPLETED TTL (24h default) and legitimate '
            . 'retries get 425 IDEMPOTENCY_IN_FLIGHT — regression of '
            . 'F-6-6-FIND-04 P2.'
        );
    }

    public function test_pending_placeholder_blocks_concurrent_caller_within_pending_ttl(): void
    {
        // Regression guard for the OTHER direction: the placeholder must
        // still block a concurrent retry that arrives *while* the original
        // is in-flight (i.e. before the PENDING TTL expires).
        Config::set('idempotency.pending_ttl_seconds', 30);
        Config::set('cache.default', 'array');
        Cache::store('array')->flush();

        /** @var IdempotencyKeyRepository $repo */
        $repo = new RedisIdempotencyKeyRepository(cacheStore: 'array');

        $scopedKey   = 'idempotency:v1:1:1:test-concurrent-twin';
        $payloadHash = hash('sha256', '{"order":"y"}');

        $first  = $repo->acquire($scopedKey, $payloadHash, 86400);
        $second = $repo->acquire($scopedKey, $payloadHash, 86400);

        $this->assertTrue($first, 'First acquire() must succeed.');
        $this->assertFalse(
            $second,
            'Concurrent second acquire() inside the PENDING TTL window MUST '
            . 'return false so the middleware enters waitForCompletion() and '
            . 'either replays the COMPLETED record or returns 425. If true, '
            . 'the atomicity contract is broken — double-execute risk.'
        );
    }
}
