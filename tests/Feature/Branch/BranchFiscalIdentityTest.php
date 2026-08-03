<?php

namespace Tests\Feature\Branch;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchFiscalIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fillable_persists_fiscal_identity_fields(): void
    {
        $branch = Branch::factory()->create([
            'siret' => '12345678901234',
            'vat_intra' => 'FR99999999999',
            'register_id' => 'REG-1',
            'legal_footer' => 'Mentions légales court test.',
        ]);

        $fresh = $branch->fresh();
        $this->assertSame('12345678901234', $fresh->siret);
        $this->assertSame('FR99999999999', $fresh->vat_intra);
        $this->assertSame('REG-1', $fresh->register_id);
        $this->assertSame('Mentions légales court test.', $fresh->legal_footer);
    }

    public function test_migration_down_up_is_idempotent_for_fiscal_columns(): void
    {
        /** @var \Illuminate\Database\Migrations\Migration $migration */
        $migration = include dirname(__DIR__, 3).'/database/migrations/2026_04_20_210000_add_fiscal_identity_to_branches.php';

        $migration->down();
        $this->assertFalse(Schema::hasColumn('branches', 'siret'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('branches', 'siret'));
        $this->assertTrue(Schema::hasColumn('branches', 'vat_intra'));
        $this->assertTrue(Schema::hasColumn('branches', 'register_id'));
        $this->assertTrue(Schema::hasColumn('branches', 'legal_footer'));
    }

    public function test_legacy_branch_has_null_fiscal_fields_by_default(): void
    {
        $branch = Branch::factory()->create();

        $this->assertNull($branch->siret);
        $this->assertNull($branch->vat_intra);
        $this->assertNull($branch->register_id);
        $this->assertNull($branch->legal_footer);
    }
}
