<?php

namespace Tests\Feature;

use Tests\TestCase;

class KioskBundleLockdownTest extends TestCase
{
    public function test_kiosk_admin_public_bundle_and_source_are_absent(): void
    {
        $this->assertFileDoesNotExist(public_path('js/kiosk-admin.js'));
        $this->assertFileDoesNotExist(public_path('js/kiosk-admin.js.LICENSE.txt'));
        $this->assertFileDoesNotExist(resource_path('js/components/frontend/kiosk/KioskAdminComponent.vue'));
    }

    public function test_legacy_non_manifest_kiosk_bundle_is_absent(): void
    {
        $this->assertFileDoesNotExist(
            public_path('js/kiosk.js'),
            'Legacy public/js/kiosk.js must not ship; current kiosk uses manifest-backed kiosk-shell/kiosk-wizard chunks.'
        );
        $this->assertFileDoesNotExist(public_path('js/kiosk.js.LICENSE.txt'));
    }

    public function test_forbidden_kiosk_asset_names_do_not_fall_through_to_spa(): void
    {
        $this->get('/js/kiosk-admin.js')->assertNotFound();
        $this->get('/js/kiosk-admin.js.LICENSE.txt')->assertNotFound();
        $this->get('/js/kiosk.js')->assertNotFound();
        $this->get('/js/kiosk.js.LICENSE.txt')->assertNotFound();
    }
}
