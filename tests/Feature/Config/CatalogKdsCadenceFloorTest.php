<?php

namespace Tests\Feature\Config;

use Tests\TestCase;

/**
 * Sentinel: owner-misconfig of FK_CATALOG_KDS_*_BASE_MS to a tiny value
 * (e.g. 10ms) would yield ~80 req/s/station and saturate PHP-FPM on the
 * local Le Cayenne dev box. config/catalog_v15.php must clamp every base
 * cadence to a 250ms floor (4 req/s max) and every jitter to a 0 floor.
 *
 * Audit: reports/audit/critical-focus-2026-05-18/wave-1/kds-adversarial.md
 *        — finding KDS-RED-09 (Wave 3 P1)
 */
class CatalogKdsCadenceFloorTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function unsetEnv(string $key): void
    {
        if (! array_key_exists($key, $this->savedEnv)) {
            $this->savedEnv[$key] = getenv($key);
        }
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    /**
     * Re-evaluates config/catalog_v15.php so test-mutated env is visible.
     * Laravel pins env at boot; the config file uses `env()` only at
     * top-level, so a fresh require returns a clamped snapshot.
     *
     * @return array<string, mixed>
     */
    private function freshKdsConfig(): array
    {
        $config = require base_path('config/catalog_v15.php');

        return $config['kds_fallback_polling'];
    }

    public function test_owner_misconfig_below_floor_is_clamped_to_250ms(): void
    {
        $this->setEnv('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', '10');
        $this->setEnv('FK_CATALOG_KDS_DEGRADED_BASE_MS', '50');
        $this->setEnv('FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS', '0');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(250, $cfg['disconnected_base_ms'], 'DISCONNECTED base must clamp to 250ms (not 10)');
        $this->assertSame(250, $cfg['degraded_base_ms'], 'DEGRADED base must clamp to 250ms (not 50)');
        $this->assertSame(250, $cfg['high_activity_base_ms'], 'HIGH_ACTIVITY base must clamp to 250ms (not 0)');
    }

    public function test_owner_value_above_floor_is_preserved(): void
    {
        $this->setEnv('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', '5000');
        $this->setEnv('FK_CATALOG_KDS_DEGRADED_BASE_MS', '2000');
        $this->setEnv('FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS', '1500');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(5000, $cfg['disconnected_base_ms']);
        $this->assertSame(2000, $cfg['degraded_base_ms']);
        $this->assertSame(1500, $cfg['high_activity_base_ms']);
    }

    public function test_missing_env_falls_back_to_safe_defaults(): void
    {
        $this->unsetEnv('FK_CATALOG_KDS_DISCONNECTED_BASE_MS');
        $this->unsetEnv('FK_CATALOG_KDS_DEGRADED_BASE_MS');
        $this->unsetEnv('FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS');
        $this->unsetEnv('FK_CATALOG_KDS_DISCONNECTED_JITTER_MS');
        $this->unsetEnv('FK_CATALOG_KDS_DEGRADED_JITTER_MS');
        $this->unsetEnv('FK_CATALOG_KDS_HIGH_ACTIVITY_JITTER_MS');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(10_000, $cfg['disconnected_base_ms']);
        $this->assertSame(5_000, $cfg['degraded_base_ms']);
        $this->assertSame(3_000, $cfg['high_activity_base_ms']);
        $this->assertSame(3_000, $cfg['disconnected_jitter_ms']);
        $this->assertSame(2_000, $cfg['degraded_jitter_ms']);
        $this->assertSame(1_000, $cfg['high_activity_jitter_ms']);
    }

    public function test_negative_jitter_misconfig_is_clamped_to_zero(): void
    {
        $this->setEnv('FK_CATALOG_KDS_DISCONNECTED_JITTER_MS', '-500');
        $this->setEnv('FK_CATALOG_KDS_DEGRADED_JITTER_MS', '-1');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(0, $cfg['disconnected_jitter_ms']);
        $this->assertSame(0, $cfg['degraded_jitter_ms']);
    }

    /**
     * Wave 3b P1 / KDS-ADV3B-04 — silent-blind upper cap.
     * Symmetric to the 250ms floor: a runaway base value (e.g. 999999999ms ≈
     * 11.5 days between polls) would silence KDS during a WS outage. Cap base
     * at 60_000ms (1 poll/min minimum) and jitter at 30_000ms.
     */
    public function test_silent_blind_misconfig_above_ceiling_is_clamped_to_60000ms(): void
    {
        $this->setEnv('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', '999999999');
        $this->setEnv('FK_CATALOG_KDS_DEGRADED_BASE_MS', '999999999');
        $this->setEnv('FK_CATALOG_KDS_HIGH_ACTIVITY_BASE_MS', '999999999');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(60_000, $cfg['disconnected_base_ms'], 'DISCONNECTED base must cap at 60_000ms (silent-blind guard)');
        $this->assertSame(60_000, $cfg['degraded_base_ms'], 'DEGRADED base must cap at 60_000ms');
        $this->assertSame(60_000, $cfg['high_activity_base_ms'], 'HIGH_ACTIVITY base must cap at 60_000ms');
    }

    public function test_owner_value_120000ms_is_clamped_down_to_ceiling(): void
    {
        $this->setEnv('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', '120000');
        $this->setEnv('FK_CATALOG_KDS_DEGRADED_BASE_MS', '120000');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(60_000, $cfg['disconnected_base_ms']);
        $this->assertSame(60_000, $cfg['degraded_base_ms']);
    }

    public function test_owner_value_30000ms_below_ceiling_is_preserved(): void
    {
        $this->setEnv('FK_CATALOG_KDS_DISCONNECTED_BASE_MS', '30000');
        $this->setEnv('FK_CATALOG_KDS_DEGRADED_BASE_MS', '30000');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(30_000, $cfg['disconnected_base_ms'], '30_000ms is below ceiling and must be preserved');
        $this->assertSame(30_000, $cfg['degraded_base_ms']);
    }

    public function test_jitter_above_ceiling_is_clamped_to_30000ms(): void
    {
        $this->setEnv('FK_CATALOG_KDS_DISCONNECTED_JITTER_MS', '999999');
        $this->setEnv('FK_CATALOG_KDS_DEGRADED_JITTER_MS', '60000');
        $this->setEnv('FK_CATALOG_KDS_HIGH_ACTIVITY_JITTER_MS', '50000');

        $cfg = $this->freshKdsConfig();

        $this->assertSame(30_000, $cfg['disconnected_jitter_ms'], 'jitter must cap at 30_000ms (silent-blind widening guard)');
        $this->assertSame(30_000, $cfg['degraded_jitter_ms']);
        $this->assertSame(30_000, $cfg['high_activity_jitter_ms']);
    }
}
