<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static CI guard for TASK_V1_SEC_XSS_001.
 *
 * Replaces the ESLint "vue/no-v-html" rule without requiring the full ESLint
 * toolchain in CI. For each .vue file under resources/js:
 *
 *  - Every occurrence of v-html MUST be wrapped by safeHtml(...) OR be a known
 *    explicit exception listed in self::EXPLICIT_EXCEPTIONS.
 *  - Every occurrence of v-html MUST have an `eslint-disable-next-line vue/no-v-html`
 *    comment immediately above, documenting why the sanitized use is allowed.
 *
 * Breaks the build if a developer reintroduces a raw v-html.
 *
 * @see docs/SECURITY_NOTES.md §XSS Prevention
 */
class VHtmlStaticGuardTest extends TestCase
{
    /**
     * Absolute file paths where a non-safeHtml() v-html is accepted.
     * Adding here requires a security-review justification in SECURITY_NOTES.md.
     *
     * @var list<string>
     */
    private const EXPLICIT_EXCEPTIONS = [];

    public function test_every_v_html_usage_is_sanitized(): void
    {
        $violations = [];

        foreach ($this->vueFiles() as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            $lines = preg_split('/\R/u', $source) ?: [];
            foreach ($lines as $lineIndex => $line) {
                if (!preg_match('/v-html\s*=/', $line)) {
                    continue;
                }

                $relPath = $this->shortenPath($path);

                // Allow explicit exceptions (with documentation).
                if (in_array($relPath, self::EXPLICIT_EXCEPTIONS, true)) {
                    continue;
                }

                // Must call safeHtml(...) on the rhs.
                if (!preg_match('/v-html\s*=\s*"\s*safeHtml\s*\(/', $line)) {
                    $violations[] = sprintf(
                        "%s:%d — v-html must be wrapped by safeHtml(...)\n    %s",
                        $relPath,
                        $lineIndex + 1,
                        trim($line)
                    );
                    continue;
                }

                // Must have an eslint-disable-next-line comment directly above.
                $previousLine = $lines[$lineIndex - 1] ?? '';
                if (!preg_match('/eslint-disable-next-line\s+vue\/no-v-html/', $previousLine)) {
                    $violations[] = sprintf(
                        "%s:%d — missing `<!-- eslint-disable-next-line vue/no-v-html -->` comment above sanitized v-html\n    %s",
                        $relPath,
                        $lineIndex + 1,
                        trim($line)
                    );
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "XSS guard violations:\n" . implode("\n", $violations)
        );
    }

    public function test_no_inner_html_dom_mutations(): void
    {
        $violations = [];

        foreach ($this->vueFiles() as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            $lines = preg_split('/\R/u', $source) ?: [];
            foreach ($lines as $lineIndex => $line) {
                if (!preg_match('/\.innerHTML\s*=/', $line)) {
                    continue;
                }

                // Allow DOMPurify-sanitized innerHTML writes explicitly.
                if (preg_match('/DOMPurify\.sanitize|safeHtml\s*\(/', $line)) {
                    continue;
                }

                $violations[] = sprintf(
                    "%s:%d — raw .innerHTML assignment (use textContent or DOMPurify.sanitize)\n    %s",
                    $this->shortenPath($path),
                    $lineIndex + 1,
                    trim($line)
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Raw innerHTML assignments detected:\n" . implode("\n", $violations)
        );
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function vueFiles(): iterable
    {
        $root = $this->repoRoot() . '/resources/js';
        if (!is_dir($root)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isFile() && $file->getExtension() === 'vue') {
                yield $file;
            }
        }
    }

    private function shortenPath(string $abs): string
    {
        $root = $this->repoRoot();
        return str_starts_with($abs, $root) ? substr($abs, strlen($root) + 1) : $abs;
    }

    /**
     * Resolve the repository root without relying on Laravel helpers (the test
     * extends PHPUnit\Framework\TestCase, Laravel container is not bootstrapped).
     */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }
}
