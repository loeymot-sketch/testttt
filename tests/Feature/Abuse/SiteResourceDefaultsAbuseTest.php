<?php

namespace Tests\Feature\Abuse;

use App\Enums\CurrencyPosition;
use App\Http\Resources\SiteResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ABUSE #4 — SiteResource missing `??` defaults (P3).
 *
 * SiteResource::toArray() read $this->info['site_xxx'] with NAKED array access.
 * If a 'site' setting is unset (fresh install, partial seed, deleted row), the
 * resource emitted "Undefined array key" warnings and serialized nulls for
 * fields the frontend assumes are present (date format, timezone, currency
 * position…), corrupting display.
 *
 * This renders the resource against an EMPTY info array and asserts it (a)
 * raises no error/notice and (b) falls back to FR-correct defaults mirroring
 * the SettingResource pattern.
 *
 * @see app/Http/Resources/SiteResource.php
 * @see app/Http/Resources/SettingResource.php
 */
class SiteResourceDefaultsAbuseTest extends TestCase
{
    /** @test */
    public function it_renders_with_empty_info_without_raising_an_error(): void
    {
        // Promote PHP notices/warnings to exceptions so naked array access fails loudly.
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        });

        try {
            $payload = (new SiteResource([]))->toArray(Request::create('/'));
        } finally {
            restore_error_handler();
        }

        $this->assertIsArray($payload);
        // Every declared key must be present (no missing keys, no undefined-key notices).
        $this->assertArrayHasKey('site_default_timezone', $payload);
        $this->assertArrayHasKey('site_date_format', $payload);
        $this->assertArrayHasKey('site_currency_position', $payload);
    }

    /** @test */
    public function it_falls_back_to_fr_correct_defaults_when_info_is_empty(): void
    {
        $payload = (new SiteResource([]))->toArray(Request::create('/'));

        $this->assertSame('Europe/Paris', $payload['site_default_timezone']);
        $this->assertSame('d-m-Y', $payload['site_date_format']);
        $this->assertSame('€', $payload['site_default_currency_symbol']);
        // Currency position default must match the recent RIGHT (10) enum fix, not the en-US 'left' string.
        $this->assertSame(CurrencyPosition::RIGHT, $payload['site_currency_position']);
    }

    /** @test */
    public function it_prefers_provided_values_over_defaults(): void
    {
        $payload = (new SiteResource([
            'site_default_timezone' => 'America/New_York',
            'site_date_format'      => 'Y/m/d',
        ]))->toArray(Request::create('/'));

        $this->assertSame('America/New_York', $payload['site_default_timezone']);
        $this->assertSame('Y/m/d', $payload['site_date_format']);
    }
}
