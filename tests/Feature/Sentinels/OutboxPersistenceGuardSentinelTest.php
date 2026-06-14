<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * RCH-03 (ultra-review 2026-06-14) — every Persist*ToOutbox listener MUST route its
 * synchronous projection through GuardsOutboxPersistence::runOutboxPersistenceGuarded.
 *
 * These listeners are registered FIRST in their event's cascade and run synchronously;
 * the events use DispatchableAfterCommit, so business-critical listeners (stock decrement,
 * loyalty award, receipt, cache invalidation, low-stock alert) run AFTER them. An
 * unguarded firstOrCreate throw aborts the whole cascade (oversell / lost loyalty /
 * stale kiosk 86 / POS 500). This is a CLASS bug — the campaign repeatedly fixed one
 * instance and missed the twins. This sentinel is the ratchet: a NEW Persist*ToOutbox,
 * or one that drops the guard, fails CI and forces a deliberate decision.
 */
class OutboxPersistenceGuardSentinelTest extends TestCase
{
    public function test_every_persist_to_outbox_listener_is_guarded(): void
    {
        $dir = base_path('app/Listeners');
        $files = glob($dir . '/Persist*ToOutbox.php');
        $this->assertNotEmpty($files, 'Expected Persist*ToOutbox listeners to exist.');

        // Lock the known count so a NEW listener forces an explicit review here.
        $this->assertCount(14, $files, 'Persist*ToOutbox listener count changed — guard the new one + bump this count.');

        $unguarded = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            $usesTrait = str_contains($src, 'GuardsOutboxPersistence');
            $delegates = (bool) preg_match('/runOutboxPersistenceGuarded\(\s*self::class\s*,/', $src);
            if (! $usesTrait || ! $delegates) {
                $unguarded[] = basename($file) . ' (trait=' . ($usesTrait ? 'y' : 'n') . ', delegates=' . ($delegates ? 'y' : 'n') . ')';
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "These Persist*ToOutbox listeners do NOT route through the outbox-persistence guard:\n" . implode("\n", $unguarded)
        );
    }
}
