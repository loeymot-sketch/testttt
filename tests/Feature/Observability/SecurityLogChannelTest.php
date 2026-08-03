<?php

namespace Tests\Feature\Observability;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [C6 / K-6] Smoke-test the dedicated `security` log channel.
 *
 * The channel is shipped as INFRASTRUCTURE only — it must be resolvable
 * by `Log::channel('security')` so any future enforcement code (K-6
 * branch isolation, ability denial logging, lockdown violation) can
 * write to it immediately without rediscovering the wiring.
 *
 * Forensic invariants documented in `config/logging.php`:
 *  - daily rotation, 90 days retention,
 *  - `info` level threshold,
 *  - separate from `observability` (SLO) and `hardware` (TPE/printer),
 *    so ops can route alerts independently.
 */
class SecurityLogChannelTest extends TestCase
{
    public function test_security_channel_is_configured(): void
    {
        $cfg = config('logging.channels.security');

        $this->assertIsArray($cfg, 'Expected `security` channel to exist in config/logging.php.');
        $this->assertSame('daily', $cfg['driver'], 'Security channel must use the `daily` driver for rotation.');
        $this->assertSame(90, $cfg['days'], 'NF525 / K-6 forensic retention requires 90 days.');
        $this->assertSame('info', $cfg['level'], 'Security channel threshold must be `info`.');
        $this->assertStringContainsString('security.log', (string) $cfg['path']);
    }

    public function test_security_channel_is_resolvable_and_writable(): void
    {
        $logger = Log::channel('security');

        $this->assertNotNull($logger, '`Log::channel(\'security\')` must resolve without throwing.');

        // Should not throw; we don't assert on the file because tests run
        // against the array driver in CI but a real daily driver locally —
        // both must accept the call.
        $logger->info('[C6] security channel smoke test', [
            'category' => 'self_test',
            'reason'   => 'infrastructure_ready',
        ]);

        $this->assertTrue(true);
    }
}
