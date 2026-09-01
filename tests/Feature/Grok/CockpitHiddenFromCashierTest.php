<?php

namespace Tests\Feature\Grok;

use App\Libraries\AppLibrary;
use Tests\TestCase;

class CockpitHiddenFromCashierTest extends TestCase
{
    public function test_app_library_drops_cockpit_when_settings_access_is_false(): void
    {
        $menus = [
            [
                'url' => '#',
                'name' => 'Setup',
                'children' => [
                    ['url' => 'observability/system', 'name' => 'System Health'],
                    ['url' => 'site', 'name' => 'Site'],
                ],
            ],
        ];
        $permissions = [
            'settings' => ['access' => false, 'url' => 'settings'],
            'site' => ['access' => true, 'url' => 'site'],
        ];

        $out = AppLibrary::menu($menus, $permissions);
        $children = array_values($out)[0]['children'] ?? [];
        $urls = array_column($children, 'url');

        $this->assertNotContains('observability/system', $urls);
        $this->assertContains('site', $urls);
    }

    public function test_app_library_keeps_cockpit_when_settings_granted(): void
    {
        $menus = [
            ['url' => 'observability/system', 'name' => 'System Health'],
        ];
        $permissions = [
            'settings' => ['access' => true, 'url' => 'settings'],
        ];

        $out = AppLibrary::menu($menus, $permissions);
        $this->assertContains('observability/system', array_column(array_values($out), 'url'));
    }

    public function test_app_library_drops_unknown_url_once_table_is_loaded(): void
    {
        $menus = [
            ['url' => 'ecran-fantome', 'name' => 'Fantôme'],
            ['url' => 'site', 'name' => 'Site'],
        ];
        $permissions = [
            'site' => ['access' => true, 'url' => 'site'],
        ];

        $out = AppLibrary::menu($menus, $permissions);
        $urls = array_column(array_values($out), 'url');
        $this->assertNotContains('ecran-fantome', $urls);
        $this->assertContains('site', $urls);
    }

    public function test_empty_permissions_leave_menu_unfiltered(): void
    {
        $menus = [
            ['url' => 'observability/system', 'name' => 'System Health'],
        ];
        $out = AppLibrary::menu($menus, []);
        $this->assertContains('observability/system', array_column(array_values($out), 'url'));
    }

    public function test_recursive_router_matches_name_and_denies_unknown_when_hydrated(): void
    {
        $src = file_get_contents(base_path('resources/js/services/appService.js'));
        $this->assertStringContainsString('p.url === key || p.name === key', $src);
        $this->assertStringContainsString('meta.access = false', $src);
    }
}
