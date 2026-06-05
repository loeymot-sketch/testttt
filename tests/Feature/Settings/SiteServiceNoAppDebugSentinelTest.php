<?php

namespace Tests\Feature\Settings;

use Tests\TestCase;

/**
 * S7-03 — Site settings must NEVER write APP_DEBUG to .env.
 *
 * The Site settings "App Debug → Enable" control wrote APP_DEBUG=true to .env
 * via SiteService::update → a self-inflicted production boot failure (the
 * AppServiceProvider prod boot-guard refuses to boot when APP_DEBUG=true) and a
 * debug/secret-leak vector. Owner decision (2026-06-05): remove the control.
 * Backend neutralization: SiteService no longer propagates APP_DEBUG to .env at
 * all — APP_DEBUG is ops/deploy-managed, never app-managed. This source sentinel
 * locks that and prevents reintroduction. (The UI toggle removal is the
 * frontend follow-up; this closes the actual danger regardless of the UI.)
 */
class SiteServiceNoAppDebugSentinelTest extends TestCase
{
    public function test_site_service_does_not_write_app_debug_to_env(): void
    {
        $src = file_get_contents(app_path('Services/SiteService.php'));

        // The env-write used the array key 'APP_DEBUG' => ... ; assert that exact
        // quoted key is gone (an explanatory comment mentioning APP_DEBUG is fine).
        $this->assertStringNotContainsString(
            "'APP_DEBUG'",
            $src,
            'S7-03: SiteService must NOT write the APP_DEBUG env key — prod-boot-failure vector; APP_DEBUG is ops-managed.'
        );
    }
}
