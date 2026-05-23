<?php

namespace Tests\Feature\Security;

use App\Models\NotificationSetting;
use App\Services\FirebaseService;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Sentinel test for GOAL-HEAL-SEC-001 (Phase B.3 RED-team finding B3.2-001).
 *
 * The Firebase Admin SDK service-account JSON must NOT be reachable under
 * Laravel's public webroot. It is pinned to the non-public `firebase_private`
 * disk (storage/app/firebase/) via NotificationSetting's media collection
 * configuration, and read by FirebaseService::getAccessToken() through the
 * Spatie media path resolver rather than by parsing a public URL.
 *
 * This sentinel ASSERTS the non-public storage contract at multiple levels
 * so a future refactor cannot silently re-introduce the vulnerability:
 *   1. The committed public/file/service-account-file.json does NOT exist.
 *   2. The 'firebase_private' filesystem disk is configured and its root
 *      lives under storage/app/firebase/ (NOT under any web-served path).
 *   3. NotificationSetting::registerMediaCollections pins the
 *      'notification-file' media collection to the firebase_private disk.
 *   4. FirebaseService::getAccessToken resolves the file path via Spatie
 *      media (getFirstMedia()->getPath()) rather than parse_url() on a
 *      public asset URL.
 *   5. .gitignore prevents re-introduction of credential file shapes under
 *      public/file/.
 *
 * @see app/Models/NotificationSetting.php
 * @see app/Services/FirebaseService.php
 * @see config/filesystems.php  (firebase_private disk)
 * @see public/.htaccess        (defense-in-depth deny rule)
 * @see public/file/.htaccess   (defense-in-depth deny rule)
 * @see reports/test-e2e/goal-2026-05-23/round-1/B3.2-security-findings.json
 */
class FirebaseKeyStorageSecurityTest extends TestCase
{
    public function test_public_file_service_account_json_does_not_exist(): void
    {
        $publicPath = public_path('file/service-account-file.json');

        $this->assertFileDoesNotExist(
            $publicPath,
            "Firebase Admin SDK service-account JSON must NOT live under public/. "
            . "B3.2-001: web-accessible at /file/service-account-file.json. "
            . "Relocate to storage/app/firebase/ on the firebase_private disk."
        );
    }

    public function test_firebase_private_disk_is_configured_under_storage(): void
    {
        $diskConfig = config('filesystems.disks.firebase_private');

        $this->assertIsArray(
            $diskConfig,
            "config('filesystems.disks.firebase_private') must be defined "
            . "to host Firebase service-account JSON outside the public webroot."
        );
        $this->assertSame('local', $diskConfig['driver'] ?? null);
        $this->assertSame('private', $diskConfig['visibility'] ?? null);

        // Root must be under storage/app/ and must NOT overlap public_path.
        $root = $diskConfig['root'] ?? '';
        $this->assertNotEmpty($root, 'firebase_private disk root is empty.');
        $this->assertStringContainsString(
            DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app',
            $root,
            'firebase_private disk root must live under storage/app/.'
        );
        $this->assertStringNotContainsString(
            'public',
            basename(dirname($root)) . '/' . basename($root),
            'firebase_private disk root path must not contain "public" segment.'
        );
    }

    public function test_notification_setting_media_collection_uses_firebase_private_disk(): void
    {
        $setting = new NotificationSetting();
        $setting->registerMediaCollections();

        $collections = $setting->mediaCollections;
        $this->assertNotEmpty(
            $collections,
            'NotificationSetting must register the notification-file media collection.'
        );

        $found = false;
        foreach ($collections as $collection) {
            if ($collection->name === 'notification-file') {
                $found = true;
                $this->assertSame(
                    'firebase_private',
                    $collection->diskName,
                    "The 'notification-file' media collection must be pinned to the "
                    . "firebase_private disk (was: '{$collection->diskName}'). "
                    . "B3.2-001: pinning prevents admin-uploaded Firebase JSON from "
                    . "landing under public/storage/."
                );
            }
        }
        $this->assertTrue(
            $found,
            "NotificationSetting must register a 'notification-file' media collection."
        );
    }

    public function test_firebase_service_resolves_path_via_media_not_url_parse(): void
    {
        // Static-source-text sentinel: catches a refactor that re-introduces
        // the old parse_url('/storage') path math (which assumes a public disk).
        $source = File::get(
            base_path('app/Services/FirebaseService.php')
        );

        $this->assertStringNotContainsString(
            "ltrim(\$parsed_url['path'], '/storage')",
            $source,
            "FirebaseService must NOT parse a /storage URL to locate the "
            . "service-account JSON. Use \$media->getPath() on the Spatie "
            . "media record from the firebase_private disk instead."
        );
        $this->assertStringContainsString(
            "getFirstMedia('notification-file')",
            $source,
            "FirebaseService::getAccessToken must resolve the JSON file via "
            . "Spatie getFirstMedia('notification-file')->getPath() so the "
            . "underlying disk (firebase_private) is honored."
        );

        // FirebaseService class still resolves; ServiceAccountCredentials import intact.
        $this->assertTrue(
            class_exists(FirebaseService::class),
            'FirebaseService class must remain loadable after the refactor.'
        );
        $reflection = new ReflectionClass(FirebaseService::class);
        $this->assertTrue(
            $reflection->hasMethod('getAccessToken'),
            'FirebaseService::getAccessToken must still exist.'
        );
    }

    public function test_gitignore_blocks_credential_files_under_public_file(): void
    {
        $gitignore = File::get(base_path('.gitignore'));

        $this->assertStringContainsString(
            '/public/file/service-account-file.json',
            $gitignore,
            ".gitignore must explicitly exclude the historical leak path."
        );
        $this->assertStringContainsString(
            '/public/file/*.json',
            $gitignore,
            ".gitignore must exclude any future JSON file dropped in public/file/."
        );
        $this->assertStringContainsString(
            '/public/file/*.pem',
            $gitignore,
            ".gitignore must exclude PEM credential bundles in public/file/."
        );
        $this->assertStringContainsString(
            '/public/file/*.key',
            $gitignore,
            ".gitignore must exclude raw key files in public/file/."
        );
    }

    public function test_htaccess_denies_credential_files_in_public_file_directory(): void
    {
        $htaccess = base_path('public/file/.htaccess');
        $this->assertFileExists(
            $htaccess,
            'public/file/.htaccess (defense-in-depth deny rule) must exist.'
        );

        $content = File::get($htaccess);
        $this->assertMatchesRegularExpression(
            '/<FilesMatch\s+"\\\\\.\([^)]*json[^)]*\)\$"\s*>/i',
            $content,
            'public/file/.htaccess must deny *.json via FilesMatch.'
        );
        $this->assertMatchesRegularExpression(
            '/(Require all denied|Deny from all)/',
            $content,
            'public/file/.htaccess must contain a deny directive.'
        );
    }
}
