<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-FCM-TEMPLATE-NAMING
 * @source Wave J WJ-6 WI-1 R1 P1 (2026-05-19)
 *
 * Sentinel: docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt MUST use the same
 * env-var names that config/services.php reads. Otherwise an operator who
 * copies the template verbatim ends up with FCM_SECRET_KEY= / FCM_TOPIC=
 * exported into the process environment while the Laravel container reads
 * env('FCM_SERVER_KEY') / env('FCM_TOPIC_PREFIX') — both resolve to '' and
 * mobile push silently no-ops (no exception, no log).
 *
 * Source of truth: config/services.php:55 + :57.
 * Mirror surface: .env.example (already aligned 2026-05-16+, sentinel
 * pinned here to catch future drift).
 *
 * Anti-régression: any rename in services.php or template/.env.example
 * without updating the partners trips this immediately.
 */
class FcmTemplateNamingSentinelTest extends TestCase
{
    private function templateContents(): string
    {
        $path = base_path('docs/cloud/PRODUCTION_ENV_TEMPLATE.env.txt');
        $this->assertFileExists($path, 'PRODUCTION_ENV_TEMPLATE.env.txt missing');

        return file_get_contents($path);
    }

    private function envExampleContents(): string
    {
        $path = base_path('.env.example');
        $this->assertFileExists($path, '.env.example missing');

        return file_get_contents($path);
    }

    public function test_production_env_template_uses_fcm_server_key_not_fcm_secret_key(): void
    {
        $contents = $this->templateContents();

        $this->assertMatchesRegularExpression(
            '/^FCM_SERVER_KEY=/m',
            $contents,
            'PRODUCTION_ENV_TEMPLATE.env.txt must declare FCM_SERVER_KEY= '
            .'(config/services.php:55 reads env(\'FCM_SERVER_KEY\'))'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^FCM_SECRET_KEY=/m',
            $contents,
            'PRODUCTION_ENV_TEMPLATE.env.txt still contains FCM_SECRET_KEY= '
            .'— mismatched with config/services.php, push will silently no-op'
        );
    }

    public function test_production_env_template_uses_fcm_topic_prefix_not_fcm_topic(): void
    {
        $contents = $this->templateContents();

        $this->assertMatchesRegularExpression(
            '/^FCM_TOPIC_PREFIX=/m',
            $contents,
            'PRODUCTION_ENV_TEMPLATE.env.txt must declare FCM_TOPIC_PREFIX= '
            .'(config/services.php:57 reads env(\'FCM_TOPIC_PREFIX\'))'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^FCM_TOPIC=/m',
            $contents,
            'PRODUCTION_ENV_TEMPLATE.env.txt still contains FCM_TOPIC= '
            .'— mismatched with config/services.php, topic_prefix will fall back '
            .'to default "foodking" regardless of operator override'
        );
    }

    public function test_env_example_aligned_with_config_services(): void
    {
        $contents = $this->envExampleContents();

        $this->assertMatchesRegularExpression(
            '/^FCM_SERVER_KEY=/m',
            $contents,
            '.env.example must declare FCM_SERVER_KEY= for parity with template'
        );

        $this->assertMatchesRegularExpression(
            '/^FCM_TOPIC_PREFIX=/m',
            $contents,
            '.env.example must declare FCM_TOPIC_PREFIX= for parity with template'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^FCM_SECRET_KEY=/m',
            $contents,
            '.env.example contains stale FCM_SECRET_KEY= — drift from config/services.php'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/^FCM_TOPIC=/m',
            $contents,
            '.env.example contains stale FCM_TOPIC= — drift from config/services.php'
        );
    }
}
