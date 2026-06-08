<?php

namespace App\Support;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER W7 — security] Resolve an admin-authored,
 * per-option stored image path (ItemVariation/ItemExtra->image_path, set via the
 * wizard builder) to a SAFE, cache-busted public URL.
 *
 * Load-bearing XSS guard: the resolved value flows UNESCAPED into a FROZEN
 * innerHTML <img src="..."> sink (public/js/pos-wizard.js renderOptionIcon,
 * CLAUDE.md §7 — cannot be patched). So any path able to break out of the
 * src attribute (quotes, angle brackets, backtick, backslash, whitespace,
 * control chars) or traverse the filesystem ("..") or carry a non-http scheme
 * (javascript:, data:) is REJECTED -> null, and the caller falls back to the
 * safe config/default image. Request-time validation (ItemVariationRequest /
 * ItemExtraRequest) is the first layer; this is the authoritative second layer
 * at output, shared so the two models cannot drift apart.
 */
final class CatalogImagePath
{
    /**
     * Characters that can break out of an HTML attribute value or are otherwise
     * unsafe inside a URL/path that will be rendered into `src="..."`.
     */
    private const FORBIDDEN = ['"', "'", '<', '>', '`', '\\', ' ', "\t", "\n", "\r", "\0"];

    public static function safeResolve(?string $path): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        foreach (self::FORBIDDEN as $bad) {
            if (str_contains($path, $bad)) {
                return null;
            }
        }
        // Path traversal (oracle via file_exists + breakout of public/).
        if (str_contains($path, '..')) {
            return null;
        }
        // Any remaining control character (0x00-0x1F, 0x7F).
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            // Must be a syntactically valid http(s) URL (no embedded markup).
            return filter_var($path, FILTER_VALIDATE_URL) !== false ? $path : null;
        }

        // Reject any other scheme (javascript:, data:, file:, …) — only relative
        // public paths and http(s) URLs are allowed.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $path) === 1) {
            return null;
        }

        $relative = ltrim($path, '/');
        if ($relative !== '' && file_exists(public_path($relative))) {
            $hash = @filemtime(public_path($relative)) ?: 0;

            return asset($relative) . "?v={$hash}";
        }

        return null;
    }
}
