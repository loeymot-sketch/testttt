<?php

namespace Tests\Feature\Security;

use App\Http\Requests\PushNotificationRequest;
use App\Http\Requests\ThemeRequest;
use App\Rules\NoDangerousFileExtension;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1 V1+V3+V4 P1 bundle sentinel.
 *
 * Locks the three file-upload hardenings shipped by this heal:
 *
 *  V1  — App\Rules\NoDangerousFileExtension blocks `.pht` and other
 *        server-executable extensions that Laravel's internal
 *        shouldBlockPhpUpload does not list. Defends polyglot
 *        `shell.pht` (JPEG magic bytes + PHP body) and double-extension
 *        `shell.php.jpg` attacks regardless of `image` / `mimes:` rules.
 *
 *  V3  — PushNotificationRequest.php:37 had rule
 *        `['image', 'mimes:jpeg,png,jpg|max:5098']` which Laravel does
 *        NOT split on `|` inside an array element (cf.
 *        ValidationRuleParser::explodeExplicitRule). The `max:5098`
 *        suffix was silently dropped — 10 MB PNG was empirically
 *        accepted. Sentinel asserts the size cap is now enforced.
 *
 *  V4  — ThemeRequest.php:27-29 had 3 logo fields with no `max:` rule.
 *        Bounded only by nginx `client_max_body_size`. Sentinel asserts
 *        the 2 MB cap is now enforced on each logo field.
 *
 * Sentinel uses direct `Validator::make` against the FormRequest's
 * `rules()` array to assert validation behavior without the HTTP /
 * Sanctum / admin-seeding overhead that would obscure the actual
 * validation invariant being locked.
 *
 * If ANY of these assertions later flips, an attacker can:
 *  - upload a polyglot RCE shell that the rules don't reject (V1)
 *  - upload arbitrarily large files via PushNotification (V3)
 *  - upload arbitrarily large files via Theme upload (V4)
 */
class FileUploadHardenedSentinelTest extends TestCase
{
    /**
     * Real JPEG magic bytes — enough to satisfy `image` rule's MIME
     * detection on a fake upload. We append a marker so the body is
     * non-trivial but still recognized as JPEG by GD / finfo.
     */
    private function jpegBytes(): string
    {
        return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00H\x00H\x00\x00"
             . "\xFF\xDB\x00C\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09"
             . "<?php /* polyglot payload sentinel marker */ phpinfo(); ?>"
             . "\xFF\xD9";
    }

    // =====================================================================
    // V1 — .pht and double-extension blocked by NoDangerousFileExtension
    // =====================================================================

    public function test_v1_no_dangerous_extension_rule_blocks_pht(): void
    {
        $rule = new NoDangerousFileExtension();

        $file = UploadedFile::fake()->createWithContent('shell.pht', $this->jpegBytes());
        $this->assertFalse(
            $rule->passes('image', $file),
            'NoDangerousFileExtension must reject .pht extension regardless of MIME'
        );
    }

    public function test_v1_no_dangerous_extension_rule_blocks_double_extension(): void
    {
        $rule = new NoDangerousFileExtension();

        $file = UploadedFile::fake()->createWithContent('shell.php.jpg', $this->jpegBytes());
        $this->assertFalse(
            $rule->passes('image', $file),
            'NoDangerousFileExtension must reject double-extension shell.php.jpg'
        );
    }

    public function test_v1_no_dangerous_extension_rule_allows_legit_jpg(): void
    {
        $rule = new NoDangerousFileExtension();

        $file = UploadedFile::fake()->image('legitimate-product.jpg', 320, 240);
        $this->assertTrue(
            $rule->passes('image', $file),
            'NoDangerousFileExtension must NOT reject a legitimate .jpg upload'
        );
    }

    public function test_v1_no_dangerous_extension_rule_blocks_all_php_variants(): void
    {
        $rule = new NoDangerousFileExtension();

        foreach (['shell.php', 'shell.phar', 'shell.phtml', 'shell.phps',
                  'shell.phpt', 'shell.phtm', 'shell.inc', 'shell.module',
                  'shell.php2', 'shell.php3', 'shell.php4', 'shell.php5',
                  'shell.php6', 'shell.php7', 'shell.pl', 'shell.cgi',
                  'shell.sh', 'shell.py', 'shell.rb'] as $name) {
            $file = UploadedFile::fake()->createWithContent($name, 'x');
            $this->assertFalse(
                $rule->passes('image', $file),
                "NoDangerousFileExtension must reject {$name}"
            );
        }
    }

    public function test_v1_pushnotification_request_rejects_pht_polyglot(): void
    {
        // Full FormRequest integration: prove the Rule is wired in.
        $rules = (new PushNotificationRequest())->rules();

        $file = UploadedFile::fake()->createWithContent('shell.pht', $this->jpegBytes());
        $validator = Validator::make([
            'title'       => 'x',
            'description' => 'x',
            'branch_id'   => 1,
            'image'       => $file,
        ], $rules);

        $this->assertTrue(
            $validator->fails(),
            'PushNotificationRequest must reject .pht polyglot via NoDangerousFileExtension'
        );
    }

    public function test_v1_theme_request_rejects_pht_polyglot(): void
    {
        $rules = (new ThemeRequest())->rules();

        $file = UploadedFile::fake()->createWithContent('shell.pht', $this->jpegBytes());
        $validator = Validator::make([
            'theme_logo' => $file,
        ], $rules);

        $this->assertTrue(
            $validator->fails(),
            'ThemeRequest must reject .pht polyglot on theme_logo'
        );
    }

    // =====================================================================
    // V3 — PushNotificationRequest size limit enforced (5098 KB ~ 5 MB)
    // =====================================================================

    public function test_v3_pushnotification_rejects_oversized_png(): void
    {
        $rules = (new PushNotificationRequest())->rules();

        // 10 MB image — pre-fix this was silently accepted because the
        // `|max:5098` suffix was eaten by the parser.
        $file = UploadedFile::fake()->image('huge.png')->size(10240); // 10 MB in KB

        $validator = Validator::make([
            'title'       => 'x',
            'description' => 'x',
            'branch_id'   => 1,
            'image'       => $file,
        ], $rules);

        $this->assertTrue(
            $validator->fails(),
            'PushNotificationRequest must reject 10 MB image (size limit enforced after V3 fix)'
        );
        $this->assertArrayHasKey(
            'image',
            $validator->errors()->toArray(),
            'Failure must be attributed to the image field (size rule)'
        );
    }

    public function test_v3_pushnotification_accepts_image_under_size_cap(): void
    {
        $rules = (new PushNotificationRequest())->rules();

        // 1 MB image — well under the 5 MB cap.
        $file = UploadedFile::fake()->image('small.png')->size(1024);

        $validator = Validator::make([
            'title'       => 'x',
            'description' => 'x',
            'branch_id'   => 1,
            'image'       => $file,
        ], $rules);

        $this->assertFalse(
            $validator->fails(),
            'PushNotificationRequest must accept 1 MB legitimate PNG: '
                . json_encode($validator->errors()->toArray())
        );
    }

    // =====================================================================
    // V4 — ThemeRequest size limit enforced (2048 KB = 2 MB)
    // =====================================================================

    public function test_v4_theme_rejects_oversized_logo(): void
    {
        $rules = (new ThemeRequest())->rules();

        // 3 MB image — above the 2 MB cap added by V4.
        $file = UploadedFile::fake()->image('big-logo.png')->size(3072);

        $validator = Validator::make([
            'theme_logo' => $file,
        ], $rules);

        $this->assertTrue(
            $validator->fails(),
            'ThemeRequest must reject 3 MB logo (size limit enforced after V4 fix)'
        );
        $this->assertArrayHasKey(
            'theme_logo',
            $validator->errors()->toArray(),
            'Failure must be attributed to theme_logo (size rule)'
        );
    }

    public function test_v4_theme_size_cap_covers_all_three_fields(): void
    {
        $rules = (new ThemeRequest())->rules();

        foreach (['theme_logo', 'theme_favicon_logo', 'theme_footer_logo'] as $field) {
            $file = UploadedFile::fake()->image('big.png')->size(3072);
            $validator = Validator::make([$field => $file], $rules);

            $this->assertTrue(
                $validator->fails(),
                "ThemeRequest field {$field} must reject 3 MB upload (V4 cap)"
            );
            $this->assertArrayHasKey(
                $field,
                $validator->errors()->toArray(),
                "Failure must be attributed to {$field}"
            );
        }
    }

    public function test_v4_theme_accepts_small_logo(): void
    {
        $rules = (new ThemeRequest())->rules();

        $file = UploadedFile::fake()->image('logo.png')->size(500); // 500 KB
        $validator = Validator::make(['theme_logo' => $file], $rules);

        $this->assertFalse(
            $validator->fails(),
            'ThemeRequest must accept 500 KB legitimate logo: '
                . json_encode($validator->errors()->toArray())
        );
    }
}
