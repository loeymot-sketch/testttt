<?php

namespace Tests\Feature\Seeders;

use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ComposerPermissionSeederProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_composer_permission_seeder_is_wired_into_database_seeder(): void
    {
        $source = file_get_contents((new \ReflectionClass(DatabaseSeeder::class))->getFileName());

        $this->assertStringContainsString(
            'ComposerPermissionsMinimalSeeder::class',
            $source,
            'DatabaseSeeder must install composer permissions on production seed.'
        );
    }

    public function test_composer_permission_seeder_grants_expected_catalog_permissions(): void
    {
        $this->seedSpatieRoles();
        foreach (ComposerPermissionsMinimalSeeder::ROLE_NAMES as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'sanctum']);
        }

        $this->seed(ComposerPermissionsMinimalSeeder::class);

        foreach (ComposerPermissionsMinimalSeeder::ROLE_NAMES as $roleName) {
            $role = Role::findByName($roleName, 'sanctum');
            $this->assertTrue($role->hasPermissionTo('catalog.compose', 'sanctum'), $roleName);
            $this->assertTrue($role->hasPermissionTo('catalog.publish', 'sanctum'), $roleName);
        }
    }
}
