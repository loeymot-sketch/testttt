<?php

/**
 * [audit-360 W7 2026-06-22 — env() display-defaults systemic guard]
 *
 * Money/date/currency values rendered to users must NOT read env('KEY') without a default.
 * In production after `php artisan config:cache`, env() returns null for any var not in the
 * server environment (the .env file is not loaded at runtime), so:
 *   - number_format($x, env('CURRENCY_DECIMAL_POINT'))      → rounds money to an INTEGER
 *   - Carbon::format(env('DATE_FORMAT'|'TIME_FORMAT'))       → malformed/empty dates
 *   - FIXED ? env('CURRENCY') : '%'                          → null tax label
 *
 * The W5 fix defaulted AppLibrary's helpers; the W7 adversarial dispute then caught
 * OrderItemResource::tax_type (env('CURRENCY')) still undefaulted. This sentinel scans app/
 * and FAILS if any of these keys is read without a default (`, fallback` or `?? fallback`),
 * so the class can never silently regress. Comments are stripped; CURRENCY_POSITION is
 * exempt (null == RIGHT(10) is the FR-correct fallback-branch behaviour, verified).
 *
 * @group sentinel
 */

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

class EnvDisplayDefaultsSentinelTest extends TestCase
{
    /** keys that MUST carry a default wherever read at runtime (display/money/date). */
    private array $guardedKeys = ['CURRENCY', 'CURRENCY_DECIMAL_POINT', 'CURRENCY_SYMBOL', 'DATE_FORMAT', 'TIME_FORMAT'];

    public function test_no_undefaulted_money_or_date_env_in_app(): void
    {
        $appDir = base_path('app');
        $violations = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname());
            foreach ($lines as $no => $raw) {
                // strip line + block-ish comments so commented references don't trip it
                $code = preg_replace('#//.*$#', '', $raw);
                $code = preg_replace('#/\*.*?\*/#', '', $code);
                if (trim($code) === '' || str_starts_with(ltrim($code), '*')) {
                    continue;
                }
                foreach ($this->guardedKeys as $key) {
                    // env('KEY') NOT immediately followed by a comma-default, and the ) NOT followed by ??
                    if (preg_match("/env\\(\\s*['\"]" . $key . "['\"]\\s*\\)(?!\\s*\\?\\?)/", $code)) {
                        $violations[] = $file->getPathname() . ':' . ($no + 1) . "  →  env('$key') has no default (null after config:cache)";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Undefaulted money/date env() in app/ — these round money to integers / break dates after `config:cache`:\n" . implode("\n", $violations)
        );
    }
}
