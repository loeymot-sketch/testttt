<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [G-LICENSE-KEY 2026-06-21] Sentinel — a license save must NEVER rotate the global
 * x-api-key (MIX_API_KEY == config('app.api_key')).
 *
 * Healed footgun: BOTH LicenseService::update AND LicenseRequest::withValidator wrote
 * `MIX_API_KEY = license_key`. config('app.api_key') (ApiKeyMiddleware) gates ALL /api
 * requests and is injected into the SPA at RUNTIME (master.blade.php:134 /
 * admin-pos-v4.blade.php). So saving a license rotated the API key → every already-open
 * tab instantly got 400 invalid_api_key, and the pre-built public/js fallback broke until
 * reload. The license string is informational (Settings group 'license'); MIX_API_KEY is
 * owned by .env and set once at install. They must stay decoupled.
 *
 * The behavioural path is blocked offline by the remote licenseCodeChecker
 * (support.inilabs.net), so this is a source-level guard (same robust style as the
 * phantom-gate / phantom-route sentinels). It fails the moment anyone re-introduces the
 * coupling.
 */
class LicenseKeyApiKeyDecouplingSentinelTest extends TestCase
{
    /** @var string[] the two files that historically rotated the api key */
    private const GUARDED_FILES = [
        'Services/LicenseService.php',
        'Http/Requests/LicenseRequest.php',
    ];

    public function test_license_flow_never_writes_mix_api_key_into_env(): void
    {
        foreach (self::GUARDED_FILES as $relative) {
            $src = file_get_contents(app_path($relative));
            $this->assertIsString($src, "Could not read app/{$relative}");

            // Target the WRITE pattern specifically (addData(['MIX_API_KEY' ...]) /
            // putenv / ->set('MIX_API_KEY')), NOT mere mentions — the heal comments
            // legitimately reference MIX_API_KEY to document why the write was removed.
            $this->assertDoesNotMatchRegularExpression(
                '/addData\(\s*\[\s*[\'"]MIX_API_KEY/',
                $src,
                "app/{$relative} must NOT write MIX_API_KEY via EnvEditor::addData — a license "
                . 'save would rotate the global x-api-key and brick the SPA (G-LICENSE-KEY).'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/(putenv|->set)\(\s*[\'"]MIX_API_KEY/',
                $src,
                "app/{$relative} must NOT set MIX_API_KEY by any path (G-LICENSE-KEY)."
            );
        }
    }
}
