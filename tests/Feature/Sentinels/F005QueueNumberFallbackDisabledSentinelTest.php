<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID F-005 — Queue Number Fallback Collision (SUPERSEDED by D-M13)
 * @source plans/PLAN_AUDIT_F005_QUEUE_NUMBER_FALLBACK_2026-05-07.md
 * @sprint S3
 * @severity P1 (closed-by-supersession)
 *
 * BACKGROUND
 * ----------
 * Plan F-005 (2026-05-07) prescribed adding a `Z`-prefixed monotonic fallback
 * queue number on `LockTimeoutException`, plus extending the lock TTL from 10s
 * to 30s. By the time this audit cycle ran (2026-05-08), the underlying code had
 * already evolved under decision D-M13 (HG-DM13-MIGRATION-SIGNOFF +
 * HG-FROZEN-ORDER-HUNKS-TRAIN-A-2026-04-27, see docs/gates/GATE_LOG.md):
 *
 *   - Lock TTL is already 30s (OrderService.php:2221, FrontendOrderService.php:867).
 *   - The `microtime(true)*10 % 9999` fallback was DELIBERATELY REMOVED.
 *   - On `LockTimeoutException`, the allocator now throws HttpException(409)
 *     "Queue number allocation is busy. Please retry." and asks the caller to retry.
 *   - DB unique guard `(branch_id, business_date, queue_number)` enforces
 *     non-collision at the storage layer.
 *
 * Reference: docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md §"Runtime Strategy":
 *   "Remove all microtime() fallback queue-number generation.
 *    On lock timeout, fail explicitly and ask the caller to retry."
 *
 * F-005 path (Z-prefixed fallback) is therefore in DIRECT contradiction with the
 * human-approved D-M13 architectural decision. Implementing F-005 would
 * REINTRODUCE a fallback that the human owner explicitly removed, and would
 * require touching frozen zones (OrderService / FrontendOrderService) without
 * a fresh gate. F-005's own header declares `Frozen-zone override: NO`.
 *
 * Decision: F-005 is closed-by-supersession (escalation report returned to
 * orchestrator inline, not written as file per executor protocol).
 *
 * PURPOSE OF THIS SENTINEL
 * ------------------------
 * Lock D-M13 against silent regression. Any future agent who attempts to
 * re-implement F-005 (Z-prefixed fallback) without first re-opening the gate
 * and updating the architectural decision will trip these structural checks.
 */
class F005QueueNumberFallbackDisabledSentinelTest extends TestCase
{
    public function test_order_service_does_not_use_microtime_fallback_for_queue_number(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/microtime\s*\(\s*true\s*\)\s*\*\s*10\s*%\s*9999/',
            $source,
            'F-005/D-M13 invariant: OrderService must not use microtime()*10 %% 9999 '
            . 'fallback for queue numbers. D-M13 deliberately removed this. '
            . 'See docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md.'
        );
    }

    public function test_frontend_order_service_does_not_use_microtime_fallback_for_queue_number(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/microtime\s*\(\s*true\s*\)\s*\*\s*10\s*%\s*9999/',
            $source,
            'F-005/D-M13 invariant: FrontendOrderService must not use microtime()*10 %% 9999 '
            . 'fallback for queue numbers. D-M13 deliberately removed this. '
            . 'See docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md.'
        );
    }

    public function test_order_service_does_not_emit_z_prefixed_fallback_queue_number(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // F-005 plan asked for `'Z' . str_pad($fallbackNum, 4, '0', STR_PAD_LEFT)`.
        // D-M13 forbids any fallback queue-number generation. Block any
        // Z-prefixed allocation pattern in OrderService.
        $this->assertDoesNotMatchRegularExpression(
            "/'Z'\s*\.\s*str_pad/",
            $source,
            'F-005/D-M13 invariant: OrderService must not emit Z-prefixed fallback queue numbers. '
            . 'D-M13 chose strict 409-retry over fallback. Re-introducing F-005 requires '
            . 'a new human gate (HG-DM13-FALLBACK-REOPEN or equivalent).'
        );
    }

    public function test_frontend_order_service_does_not_emit_z_prefixed_fallback_queue_number(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        $this->assertDoesNotMatchRegularExpression(
            "/'Z'\s*\.\s*str_pad/",
            $source,
            'F-005/D-M13 invariant: FrontendOrderService must not emit Z-prefixed fallback '
            . 'queue numbers. D-M13 chose strict 409-retry over fallback.'
        );
    }

    public function test_order_service_throws_409_on_lock_timeout(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // The current allocator must throw HttpException(409) on LockTimeoutException
        // (D-M13 strict-retry contract), inside the catch block.
        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*LockTimeoutException\s+\$\w+\s*\)\s*\{[^}]*throw\s+new\s+HttpException\s*\(\s*409/s',
            $source,
            'F-005/D-M13 invariant: OrderService::allocateQueueNumber must throw '
            . 'HttpException(409) on LockTimeoutException (strict retry contract). '
            . 'See docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md §"Runtime Strategy".'
        );
    }

    public function test_frontend_order_service_throws_409_on_lock_timeout(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        $this->assertMatchesRegularExpression(
            '/catch\s*\(\s*LockTimeoutException\s+\$\w+\s*\)\s*\{[^}]*throw\s+new\s+HttpException\s*\(\s*409/s',
            $source,
            'F-005/D-M13 invariant: FrontendOrderService::allocateQueueNumber must throw '
            . 'HttpException(409) on LockTimeoutException (strict retry contract).'
        );
    }

    public function test_order_service_lock_ttl_is_30_seconds(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));

        // F-005 §2.2 prescribed extending lock TTL from 10s to 30s. D-M13 implemented this.
        $this->assertMatchesRegularExpression(
            "/Cache::lock\s*\(\s*\\\$lockKey\s*,\s*30\s*\)/",
            $source,
            'F-005/D-M13 invariant: OrderService allocator lock TTL must be 30s. '
            . 'Already implemented under D-M13. Lowering it would re-open the lock-timeout window.'
        );
    }

    public function test_frontend_order_service_lock_ttl_is_30_seconds(): void
    {
        $source = file_get_contents(base_path('app/Services/FrontendOrderService.php'));

        $this->assertMatchesRegularExpression(
            "/Cache::lock\s*\(\s*\\\$lockKey\s*,\s*30\s*\)/",
            $source,
            'F-005/D-M13 invariant: FrontendOrderService allocator lock TTL must be 30s.'
        );
    }

    public function test_d_m13_decision_doc_exists_and_states_no_fallback(): void
    {
        $path = base_path('docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md');
        $this->assertFileExists(
            $path,
            'F-005/D-M13 invariant: D-M13 decision document must remain in repo as the source '
            . 'of truth for the no-fallback policy that supersedes F-005.'
        );

        $doc = file_get_contents($path);
        $this->assertStringContainsString(
            'Remove all `microtime()` fallback queue-number generation',
            $doc,
            'F-005/D-M13 invariant: D-M13 decision must continue to mandate removal of '
            . 'microtime() fallback. Any change to this policy must be reflected in a new '
            . 'decision document and re-open F-005.'
        );
    }
}
