<?php

namespace Tests\Feature;

use App\Http\Requests\LanguageFileTextGetRequest;
use App\Services\LanguageService;
use Tests\TestCase;

/**
 * @see app/Services/LanguageService.php — fileText()
 * @see GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13
 *
 * Found live via Playwright: the admin "Récupérer le contenu du fichier"
 * button hangs indefinitely (no HTTP response at all) whenever a .json
 * language file is selected -- which is every language's first/default
 * file (resources/js/languages/{code}.json, always listed first by
 * fileList()). .php lang files worked fine.
 *
 * Root cause: for .json files, fileText() called include($resolvedPath)
 * with no `return`. include() on a file with no <?php tag treats the raw
 * content as literal output, so the whole JSON file got echoed straight
 * into the response body mid-call; the method then implicitly returned
 * null, and the controller (which also didn't `return` this branch)
 * discarded even that -- producing a malformed response that never
 * resolved cleanly. .php files were unaffected because `return
 * include($resolvedPath)` on a `return [...]` lang file correctly
 * returns the array.
 */
class LanguageFileTextJsonReturnTest extends TestCase
{
    private LanguageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LanguageService();
    }

    public function test_json_language_file_returns_decoded_array_not_null(): void
    {
        $path = base_path('resources/js/languages/en.json');
        $this->assertFileExists($path, 'Fixture file resources/js/languages/en.json must exist for this test');

        $request = LanguageFileTextGetRequest::create('/admin/setting/language/file-text', 'POST', [
            'name' => 'en.json',
            'path' => $path,
        ]);

        $result = $this->service->fileText($request);

        $this->assertIsArray($result, 'fileText() must return a decoded array for .json files, not null');
        $this->assertNotEmpty($result, '.json language file must not decode to an empty array');

        // Cross-check against a direct decode of the same file, so this
        // test fails if the fix ever regresses to raw/garbled output.
        $expected = json_decode(file_get_contents($path), true);
        $this->assertSame($expected, $result);
    }

    public function test_php_language_file_still_returns_its_array_unaffected(): void
    {
        $path = base_path('lang/en/auth.php');
        $this->assertFileExists($path, 'Fixture file lang/en/auth.php must exist for this test');

        $request = LanguageFileTextGetRequest::create('/admin/setting/language/file-text', 'POST', [
            'name' => 'auth.php',
            'path' => $path,
        ]);

        $result = $this->service->fileText($request);

        $this->assertIsArray($result);
        $this->assertSame(include $path, $result);
    }
}
