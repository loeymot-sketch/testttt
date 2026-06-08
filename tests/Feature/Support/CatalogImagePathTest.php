<?php

namespace Tests\Feature\Support;

use App\Support\CatalogImagePath;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER W7 — security] The per-option stored image path
 * is rendered UNESCAPED into a frozen pos-wizard.js innerHTML `src="..."` sink
 * (CLAUDE.md §7 — un-patchable). CatalogImagePath::safeResolve is the
 * authoritative output guard: hostile/malformed paths MUST collapse to null so
 * the caller falls back to the safe config image — never reflect a breakout
 * payload, a traversal probe, or a non-http scheme.
 */
class CatalogImagePathTest extends TestCase
{
    public function test_rejects_attribute_breakout_payload(): void
    {
        // The exact empirical probe from the audit: closes the src attr + adds onerror.
        $this->assertNull(CatalogImagePath::safeResolve('https://evil.example/x.png" onerror="alert(document.cookie)'));
        $this->assertNull(CatalogImagePath::safeResolve('x.png"><script>alert(1)</script>'));
        $this->assertNull(CatalogImagePath::safeResolve("x.png' onload='x"));
        $this->assertNull(CatalogImagePath::safeResolve('images/`backtick`.png'));
        $this->assertNull(CatalogImagePath::safeResolve('images/with space.png'));
    }

    public function test_rejects_path_traversal(): void
    {
        $this->assertNull(CatalogImagePath::safeResolve('../../etc/passwd'));
        $this->assertNull(CatalogImagePath::safeResolve('images/../../secret'));
    }

    public function test_rejects_non_http_schemes(): void
    {
        $this->assertNull(CatalogImagePath::safeResolve('javascript:alert(1)'));
        $this->assertNull(CatalogImagePath::safeResolve('javascript:foo'));
        $this->assertNull(CatalogImagePath::safeResolve('data:image/svg+xml;base64,AAA'));
        $this->assertNull(CatalogImagePath::safeResolve('file:///etc/passwd'));
    }

    public function test_rejects_control_characters_and_empty(): void
    {
        $this->assertNull(CatalogImagePath::safeResolve("images/menu/x.png\n"));
        $this->assertNull(CatalogImagePath::safeResolve("\t"));
        $this->assertNull(CatalogImagePath::safeResolve(''));
        $this->assertNull(CatalogImagePath::safeResolve(null));
    }

    public function test_passes_clean_https_url_verbatim(): void
    {
        $url = 'https://cdn.lecayenne.fr/options/poulet.png';
        $this->assertSame($url, CatalogImagePath::safeResolve($url));
    }

    public function test_missing_relative_file_falls_back_to_null(): void
    {
        $this->assertNull(CatalogImagePath::safeResolve('images/menu/this-file-does-not-exist-zzz.png'));
    }

    public function test_existing_relative_file_resolves_to_cache_busted_url(): void
    {
        $resolved = CatalogImagePath::safeResolve('images/menu/item-default.svg');

        $this->assertNotNull($resolved);
        $this->assertStringContainsString('images/menu/item-default.svg', $resolved);
        $this->assertStringContainsString('?v=', $resolved);
        // No breakout characters survive into the rendered value.
        $this->assertDoesNotMatchRegularExpression('/["\'<>`\s]/', $resolved);
    }
}
