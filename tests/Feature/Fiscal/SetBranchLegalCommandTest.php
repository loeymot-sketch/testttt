<?php

namespace Tests\Feature\Fiscal;

use App\Console\Commands\SetBranchLegalCommand;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [HEAL-H5 2026-06-07] Covers foodking:set-branch-legal — the idempotent command
 * that sets a branch NF525 legal identity (SIRET/TVA intra/register/footer).
 *
 * Runs on sqlite :memory: (phpunit.xml) — NEVER touches the operating foodking DB.
 * Uses the factory-created branch id (sqlite autoincrement), never assumes id=1.
 */
class SetBranchLegalCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sets_legal_identity_from_null_state(): void
    {
        $branch = Branch::factory()->create([
            'siret' => null, 'vat_intra' => null, 'register_id' => null, 'legal_footer' => null,
        ]);

        $this->artisan('foodking:set-branch-legal', [
            '--branch'      => $branch->id,
            '--siret'       => '10417050100019',
            '--vat-intra'   => 'FR19104170501',
            '--register-id' => 'CAISSE-01',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $branch->refresh();
        $this->assertSame('10417050100019', $branch->siret);
        $this->assertSame('FR19104170501', $branch->vat_intra);
        $this->assertSame('CAISSE-01', $branch->register_id);
        // footer omitted + was null → default applied
        $this->assertSame(SetBranchLegalCommand::DEFAULT_LEGAL_FOOTER, $branch->legal_footer);
    }

    public function test_replaces_self_contradictory_293b_footer_when_footer_omitted(): void
    {
        $branch = Branch::factory()->create([
            'legal_footer' => 'E.DELICE SAS - TVA non applicable art.293B CGI - Merci',
        ]);

        $this->artisan('foodking:set-branch-legal', [
            '--branch' => $branch->id,
            '--siret'  => '10417050100019',
            '--vat-intra' => 'FR19104170501',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $branch->refresh();
        $this->assertSame(SetBranchLegalCommand::DEFAULT_LEGAL_FOOTER, $branch->legal_footer);
        $this->assertDoesNotMatchRegularExpression('/293B|non applicable/i', $branch->legal_footer);
    }

    public function test_default_footer_is_not_franchise_en_base_wording(): void
    {
        // Regression guard: the safe default must never carry the
        // self-contradictory "non applicable art.293B" mention.
        $this->assertDoesNotMatchRegularExpression(
            '/293B|non applicable/i',
            SetBranchLegalCommand::DEFAULT_LEGAL_FOOTER
        );
    }

    public function test_is_idempotent_end_state(): void
    {
        $branch = Branch::factory()->create([
            'legal_footer' => 'E.DELICE SAS - TVA non applicable art.293B CGI - Merci',
        ]);

        $args = [
            '--branch'    => $branch->id,
            '--siret'     => '10417050100019',
            '--vat-intra' => 'FR19104170501',
            '--no-interaction' => true,
        ];

        $this->artisan('foodking:set-branch-legal', $args)->assertExitCode(0);
        $branch->refresh();
        $after1 = $branch->only(['siret', 'vat_intra', 'register_id', 'legal_footer']);

        $this->artisan('foodking:set-branch-legal', $args)->assertExitCode(0);
        $branch->refresh();
        $after2 = $branch->only(['siret', 'vat_intra', 'register_id', 'legal_footer']);

        $this->assertSame($after1, $after2, 'Re-run must yield a byte-identical legal identity.');
    }

    public function test_preserves_a_deliberate_good_footer_when_omitted(): void
    {
        $good = 'E.DELICE SAS - TVA intracommunautaire FR19104170501 - Merci';
        $branch = Branch::factory()->create(['legal_footer' => $good]);

        $this->artisan('foodking:set-branch-legal', [
            '--branch' => $branch->id,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $branch->refresh();
        $this->assertSame($good, $branch->legal_footer, 'A non-contradictory footer must be preserved.');
    }

    public function test_applies_explicit_legal_footer_verbatim(): void
    {
        $branch = Branch::factory()->create(['legal_footer' => null]);
        $custom = 'Mon footer officiel validé owner';

        $this->artisan('foodking:set-branch-legal', [
            '--branch'       => $branch->id,
            '--legal-footer' => $custom,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $branch->refresh();
        $this->assertSame($custom, $branch->legal_footer);
    }

    public function test_rejects_invalid_siret_without_writing(): void
    {
        $branch = Branch::factory()->create(['siret' => null]);

        $this->artisan('foodking:set-branch-legal', [
            '--branch' => $branch->id,
            '--siret'  => '123',   // not 14 digits
            '--no-interaction' => true,
        ])->assertExitCode(1);

        $branch->refresh();
        $this->assertNull($branch->siret, 'Invalid SIRET must not be persisted.');
    }

    public function test_rejects_invalid_vat_intra_without_writing(): void
    {
        $branch = Branch::factory()->create(['vat_intra' => null]);

        $this->artisan('foodking:set-branch-legal', [
            '--branch'    => $branch->id,
            '--vat-intra' => 'XX123',   // not FR + 11 chars
            '--no-interaction' => true,
        ])->assertExitCode(1);

        $branch->refresh();
        $this->assertNull($branch->vat_intra, 'Invalid TVA intra must not be persisted.');
    }

    public function test_fails_gracefully_on_unknown_branch(): void
    {
        $this->artisan('foodking:set-branch-legal', [
            '--branch' => 999999,
            '--siret'  => '10417050100019',
            '--no-interaction' => true,
        ])->assertExitCode(1);
    }
}
