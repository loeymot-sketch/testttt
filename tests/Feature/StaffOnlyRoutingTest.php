<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * [STAFF-ONLY-V1] Smoke tests pour la restructuration V1.
 *
 * Focus : valider que les flags ENV sont définis et lisibles.
 * Les tests HTTP complets sont couverts par les E2E Playwright
 * (tests/e2e/06-staff-only-routing.spec.js) qui s'exécutent
 * contre l'app réelle avec la vraie DB MySQL — contrairement
 * au harness PHPUnit qui utilise sqlite in-memory et ne
 * migre pas certaines tables analytiques.
 */
class StaffOnlyRoutingTest extends TestCase
{
    public function test_staff_only_mode_flag_is_defined(): void
    {
        $value = env('STAFF_ONLY_MODE');
        $this->assertNotNull($value, 'STAFF_ONLY_MODE must be defined in .env');
    }

    public function test_kiosk_use_pos_wizard_flag_is_defined(): void
    {
        $value = env('KIOSK_USE_POS_WIZARD');
        $this->assertNotNull($value, 'KIOSK_USE_POS_WIZARD must be defined in .env');
    }

    public function test_kiosk_credentials_defined(): void
    {
        $this->assertNotEmpty(env('KIOSK_MACHINE_USERNAME'), 'KIOSK_MACHINE_USERNAME required');
        $this->assertNotEmpty(env('KIOSK_MACHINE_PASSWORD'), 'KIOSK_MACHINE_PASSWORD required');
    }

    public function test_mix_pusher_credentials_defined_for_echo_client(): void
    {
        // [V5-BUGFIX] Sans ces MIX_ vars, Laravel Echo (webpack) n'initialise pas
        // le WebSocket → temps réel cassé.
        $this->assertNotEmpty(env('MIX_PUSHER_APP_KEY'), 'MIX_PUSHER_APP_KEY required for Echo client');
        $this->assertNotEmpty(env('MIX_PUSHER_HOST'), 'MIX_PUSHER_HOST required for Echo client');
        $this->assertNotEmpty(env('MIX_PUSHER_PORT'), 'MIX_PUSHER_PORT required for Echo client');
    }

    public function test_kiosk_config_loads_auto_login_payload(): void
    {
        // Sans require_form, la config doit exposer spa_payload.
        $requireForm = filter_var(env('KIOSK_REQUIRE_MACHINE_LOGIN', false), FILTER_VALIDATE_BOOLEAN);
        if ($requireForm) {
            $this->markTestSkipped('KIOSK_REQUIRE_MACHINE_LOGIN=true — auto-login payload is intentionally null.');
        }

        $payload = config('kiosk.spa_payload');
        $this->assertIsArray($payload, 'spa_payload must be an array when KIOSK_REQUIRE_MACHINE_LOGIN=false');
        $this->assertArrayHasKey('username', $payload);
        $this->assertArrayHasKey('password', $payload);
    }
}
