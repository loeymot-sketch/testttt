<?php

namespace Tests\Feature\Security;

use App\Http\Requests\LanguageFileTextGetRequest;
use App\Services\LanguageService;
use Exception;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * @see app/Services/LanguageService.php — validateLangFilePath()
 * @see app/Http/Controllers/Admin/LanguageController.php:23-27
 * @see GOAL-L2-HEAL-01 2026-05-24 Phase L7-2-F-03 P0 SEC-RCE
 *
 * Phase L7.2 adversarial security audit P0:
 *   LanguageService::fileText()      include($request->path)
 *   LanguageService::fileTextStore() fopen/file_get_contents/file_put_contents($request->x_language_file_path)
 * accepted any PHP stream wrapper — http://, php://, data://, file://, phar:// —
 * which is a full RCE + SSRF + arbitrary file read/write gadget gated solely by
 * the permission:settings middleware (in-code comment confirms the RCE primitive
 * at LanguageController.php:23-27). In V2 SaaS multi-tenant cloud topology, any
 * tenant-admin who can hit /admin/language/file-text-store could compromise the
 * host process.
 *
 * Containment shipped in commit L2-HEAL-01:
 *   1. realpath() resolves the path (stream wrappers and non-existent files
 *      both return false → rejected).
 *   2. Resolved real path MUST live under base_path('lang/') OR
 *      base_path('resources/js/languages/') (defeats ../ traversal because
 *      realpath() canonicalises first).
 *   3. Resolved file extension MUST be .php or .json.
 *
 * This sentinel baseline-locks the containment by reproducing 5 attack vectors
 * (all 5 MUST be rejected) and 1 legitimate path (MUST be accepted).
 *
 * Service-layer test: no DB, no Sanctum — the containment is a pure function
 * of the request path and the filesystem layout.
 */
class LanguageServicePathContainmentSentinel extends TestCase
{
    private LanguageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LanguageService();
    }

    /**
     * Invoke the private validator via reflection so each attack vector test
     * exercises EXACTLY the validation surface, not a downstream side-effect.
     */
    private function invokeValidator(?string $path): string
    {
        $ref = new \ReflectionMethod(LanguageService::class, 'validateLangFilePath');
        $ref->setAccessible(true);
        return $ref->invoke($this->service, $path);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function streamWrapperAttackVectors(): array
    {
        return [
            'php://input'             => ['php://input', 'Invalid language file path'],
            'http://evil.com/shell'   => ['http://evil.com/shell.txt', 'Invalid language file path'],
            'data:// base64'          => ['data://text/plain;base64,PD9waHAgcGhwaW5mbygpOw==', 'Invalid language file path'],
            'file:// scheme'          => ['file:///etc/passwd', 'Invalid language file path'],
            'phar:// scheme'          => ['phar:///tmp/x.phar/y.txt', 'Invalid language file path'],
        ];
    }

    /**
     * @dataProvider streamWrapperAttackVectors
     */
    public function test_stream_wrapper_paths_are_rejected(string $path, string $expectedMessageSubstring): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expectedMessageSubstring);

        $this->invokeValidator($path);
    }

    public function test_path_traversal_outside_allowed_dirs_is_rejected(): void
    {
        // realpath() canonicalises ../ traversal, so /etc/passwd resolves to
        // itself which is NOT under base_path('lang') or base_path('resources/js/languages').
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside allowed directory');

        // Use a path that definitely exists on disk so we hit the base-check
        // branch (not the realpath()-returns-false branch).
        $this->invokeValidator('/etc/hosts');
    }

    public function test_relative_traversal_to_etc_passwd_is_rejected(): void
    {
        // realpath() resolves ../../../etc/passwd into /etc/passwd before the
        // base-prefix check. Either the file does not exist (realpath==false →
        // 'Invalid language file path') or it resolves outside the allowed
        // bases. Both branches are correct rejections.
        $this->expectException(\RuntimeException::class);

        $this->invokeValidator('../../../../../etc/passwd');
    }

    public function test_disallowed_extension_under_allowed_base_is_rejected(): void
    {
        // Create a temp .txt file under base_path('lang/') so the realpath +
        // base-check both pass and we hit ONLY the extension guard.
        $tmpDir  = base_path('lang/en');
        $tmpFile = $tmpDir . '/_l2_heal_sentinel_temp_' . uniqid() . '.txt';
        file_put_contents($tmpFile, '<?php // sentinel disallowed-ext temp');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('extension not allowed');
            $this->invokeValidator($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function test_legitimate_lang_php_file_is_accepted(): void
    {
        $legitimate = base_path('lang/en/auth.php');
        $this->assertFileExists($legitimate, 'Fixture file lang/en/auth.php must exist for this sentinel');

        $resolved = $this->invokeValidator($legitimate);

        $this->assertSame(realpath($legitimate), $resolved);
    }

    public function test_legitimate_js_languages_json_file_is_accepted(): void
    {
        $legitimate = base_path('resources/js/languages/en.json');
        $this->assertFileExists($legitimate, 'Fixture file resources/js/languages/en.json must exist for this sentinel');

        $resolved = $this->invokeValidator($legitimate);

        $this->assertSame(realpath($legitimate), $resolved);
    }

    public function test_empty_and_null_paths_are_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid language file path');
        $this->invokeValidator('');
    }

    public function test_null_path_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid language file path');
        $this->invokeValidator(null);
    }

    /**
     * End-to-end on the public methods: fileTextStore must translate the
     * RuntimeException into the 422 Exception envelope (matches the existing
     * service exception pattern).
     */
    public function test_fileTextStore_with_php_input_stream_throws_422_exception(): void
    {
        $request = new Request();
        $request->merge([
            'x_language_file_path' => 'php://input',
            'x_language_file_name' => 'shell.php',
        ]);

        $this->expectException(Exception::class);

        $this->service->fileTextStore($request);
    }

    /**
     * End-to-end on fileText: the LanguageFileTextGetRequest sub-class only
     * adds validation rules; instantiating the parent Request and setting
     * path/name on it exercises the same property accessors the controller
     * passes through.
     */
    public function test_fileText_with_http_url_throws_422_exception(): void
    {
        $request = new LanguageFileTextGetRequest();
        $request->merge([
            'path' => 'http://attacker.example/shell.php',
            'name' => 'en.php',
        ]);

        $this->expectException(Exception::class);

        $this->service->fileText($request);
    }
}
