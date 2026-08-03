<?php

namespace Tests\Feature\Observability;

use Tests\TestCase;

/**
 * K-9 ADR-4 — Canal Monolog `observability` doit exister et utiliser le
 * `JsonFormatter` pour ingestion future (Loki/Logtail/stdout conteneur).
 */
class ObservabilityLogChannelTest extends TestCase
{
    public function test_observability_channel_is_configured(): void
    {
        $config = config('logging.channels.observability');

        $this->assertIsArray($config, 'Le canal observability doit exister dans config/logging.php.');
        $this->assertSame('daily', $config['driver']);
        $this->assertSame(90, $config['days']);
        $this->assertSame('info', $config['level']);
        $this->assertStringContainsString('observability.log', $config['path']);
        $this->assertSame(\App\Logging\JsonFormatter::class, $config['formatter']);
    }

    public function test_security_and_hardware_channels_preserved_for_K6_K4_heritage(): void
    {
        // Garde-fou : l'ajout du canal observability ne doit pas casser les
        // canaux security (K-6) et hardware (K-4) existants.
        $this->assertIsArray(config('logging.channels.security'));
        $this->assertIsArray(config('logging.channels.hardware'));
        $this->assertSame(90, config('logging.channels.security.days'));
        $this->assertSame(30, config('logging.channels.hardware.days'));
    }
}
