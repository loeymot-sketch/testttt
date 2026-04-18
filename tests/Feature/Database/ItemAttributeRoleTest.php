<?php

namespace Tests\Feature\Database;

use App\Enums\Status;
use App\Models\ItemAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ItemAttributeRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('item_attributes', 'role')) {
            $migration = require $this->migrationPath();
            $migration->up();
        }
    }

    public function test_role_enum_values_accepted(): void
    {
        $roles = ['bread', 'meat', 'sauce', 'size', 'topping', 'drink', 'condiment'];

        foreach ($roles as $index => $role) {
            ItemAttribute::query()->create([
                'name' => "Attribute {$index}",
                'role' => $role,
                'status' => Status::ACTIVE,
            ]);
        }

        $this->assertSame($roles, ItemAttribute::query()->orderBy('id')->pluck('role')->all());
        $this->assertCount(1, ItemAttribute::query()->withRole('bread')->get());
    }

    public function test_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasColumn('item_attributes', 'role'));

        $migration = require $this->migrationPath();
        $migration->down();

        $this->assertFalse(Schema::hasColumn('item_attributes', 'role'));

        $migration = require $this->migrationPath();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('item_attributes', 'role'));
    }

    public function test_seeder_idempotent(): void
    {
        ItemAttribute::query()->create([
            'name' => 'Pain signature',
            'status' => Status::ACTIVE,
        ]);
        ItemAttribute::query()->create([
            'name' => 'Khobz special',
            'status' => Status::ACTIVE,
        ]);
        ItemAttribute::query()->create([
            'name' => 'Meat premium',
            'status' => Status::ACTIVE,
        ]);
        ItemAttribute::query()->create([
            'name' => 'Sauce maison',
            'status' => Status::ACTIVE,
        ]);
        ItemAttribute::query()->create([
            'name' => 'Unknown custom label',
            'status' => Status::ACTIVE,
        ]);

        require_once $this->seederPath();

        $seeder = new \Database\Seeders\ItemAttributeRoleSeeder();
        $seeder->run();
        $seeder->run();

        $this->assertSame('bread', ItemAttribute::query()->where('name', 'Pain signature')->value('role'));
        $this->assertSame('bread', ItemAttribute::query()->where('name', 'Khobz special')->value('role'));
        $this->assertSame('meat', ItemAttribute::query()->where('name', 'Meat premium')->value('role'));
        $this->assertSame('sauce', ItemAttribute::query()->where('name', 'Sauce maison')->value('role'));
        $this->assertNull(ItemAttribute::query()->where('name', 'Unknown custom label')->value('role'));
        $this->assertSame(4, ItemAttribute::query()->whereNotNull('role')->count());
    }

    private function migrationPath(): string
    {
        return database_path('migrations/2026_04_18_120000_add_role_to_item_attributes.php');
    }

    private function seederPath(): string
    {
        return database_path('seeders/ItemAttributeRoleSeeder.php');
    }
}
