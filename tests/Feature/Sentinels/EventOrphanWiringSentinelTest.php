<?php

/**
 * [audit-360 W7 2026-06-22 — orphaned-event systemic guard]
 *
 * A domain event that is dispatched but (a) has NO listener registered in
 * EventServiceProvider::$listen AND (b) does not implement ShouldBroadcast fans out to
 * nothing — a "fire-and-vanish" event. That is the exact shape that causes silent sync loss
 * / dashboard false-green the day someone relies on it (or forgets to wire a new listener).
 *
 * The W7 adversarial find-new sweep surfaced two such events. Both were verified to be
 * INTENTIONAL, documented no-op hook-points (not bugs), so they are explicitly allowlisted
 * with rationale below. This sentinel FAILS if any OTHER event becomes a dispatched orphan —
 * catching a genuinely-unwired future event before it ships as a real P1.
 *
 * @group sentinel
 */

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

class EventOrphanWiringSentinelTest extends TestCase
{
    /**
     * Events that are dispatched with no listener + no ShouldBroadcast ON PURPOSE.
     * Each must stay documented as an intentional hook-point in its class docblock.
     */
    private array $intentionalHooks = [
        // Semantic "wizard profile published" signal. The outbox + kiosk-cache invalidation are
        // carried by the sibling ComposerProfileChanged dispatched on the next line
        // (ComposerProfileService::publish). Kept as a future listener hook-point; has an
        // assertDispatched test contract (ComposerProfileApiTest).
        'ComposerProfilePublished',
        // Documented "no-op by default, structured ops hook" — a stock-decrement failure is already
        // Log::error'd + isolated inline in DecrementStockOnOrderCreated / DecrementItemAvailabilityOnOrder
        // (the order + outbox SSOT still persist); the event exists so ops can attach alerting later.
        'StockDecrementFailedEvent',
    ];

    public function test_no_orphaned_dispatched_events(): void
    {
        $appPath = base_path('app');

        // Concatenate all app/ source once for dispatch detection.
        $allSource = '';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $allSource .= "\n" . file_get_contents($file->getPathname());
            }
        }
        $provider = file_get_contents($appPath . '/Providers/EventServiceProvider.php');

        $violations = [];
        foreach (glob($appPath . '/Events/*.php') as $eventFile) {
            $name = basename($eventFile, '.php');
            $eventSrc = file_get_contents($eventFile);

            $isBroadcast = str_contains($eventSrc, 'ShouldBroadcast');
            $isListed    = str_contains($provider, $name . '::class');
            $isDispatched = (bool) preg_match('/\b' . preg_quote($name, '/') . '::dispatch\b|new\s+' . preg_quote($name, '/') . '\s*\(/', $allSource);

            if ($isDispatched && ! $isListed && ! $isBroadcast && ! in_array($name, $this->intentionalHooks, true)) {
                $violations[] = $name . ' (dispatched, but no listener in EventServiceProvider::$listen, not ShouldBroadcast, not allowlisted)';
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Orphaned domain event(s) — dispatched but fan out to nothing (silent-sync-loss / false-green hazard). "
            . "Register a listener, implement ShouldBroadcast, or (if intentional) add to \$intentionalHooks with a documented rationale:\n"
            . implode("\n", $violations)
        );
    }
}
