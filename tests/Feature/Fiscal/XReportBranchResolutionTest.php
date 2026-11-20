<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [QA 2026-09-28 — RAPPORT_DEV_CAISSE_2026-09-24 P0-10 / triage A9 (risque n°1)]
 *
 * Défaut reproduit : la page Rapports Z rend le bouton « Rapport X » sans
 * aucun garde, mais l'action répondait 422
 * « Votre compte n'est rattaché à aucun établissement. » dès que le compte
 * n'a pas de branche épinglée (`branch_id = 0`, le cas de l'Admin par §9).
 * Un rapport fiscal légalement obligatoire était donc INATTEIGNABLE pour le
 * compte administrateur, alors que la liste Z avait déjà reçu la relaxation
 * lecture seule (Wave T R1 F1 P0 2026-05-20, ZReportController::index).
 *
 * Règle retenue (sémantique NF525 préservée — « un rapport fiscal appartient
 * toujours à une caisse », donc AUCUNE agrégation inter-branches inventée) :
 *   1. branche épinglée   → elle gagne TOUJOURS (aucun paramètre ne l'écrase) ;
 *   2. admin non épinglé + `branch_id` explicite et existant → cette branche ;
 *   3. admin non épinglé + une seule branche en base → elle, sans ambiguïté
 *      (enveloppe V1 LOCAL Le Cayenne, branche unique) ;
 *   4. sinon → 422 demandant de choisir une caisse.
 *
 * Le point 1 est un garde anti-IDOR : un employé épinglé ne doit jamais lire
 * le X d'une autre caisse en passant `?branch_id=`.
 */
class XReportBranchResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');

        $this->branch = Branch::factory()->create();
    }

    private function apiHeaders(): array
    {
        return ['x-api-key' => config('app.api_key')];
    }

    private function unpinnedAdmin(): User
    {
        $admin = User::factory()->create([
            'branch_id' => 0, // Admin non épinglé — §9 admin bypass.
            'password'  => Hash::make('pwd'),
        ]);
        $admin->givePermissionTo('pos-manage-fiscal');

        return $admin;
    }

    private function pinnedManager(Branch $branch): User
    {
        $manager = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $manager->givePermissionTo('pos-manage-fiscal');

        return $manager;
    }

    /**
     * Cas réel Le Cayenne : une seule branche existe, l'admin n'est pas
     * épinglé. Le rapport X doit être délivré sur cette caisse unique.
     */
    public function test_x_report_resolves_the_only_branch_for_an_unpinned_admin(): void
    {
        $this->assertSame(1, Branch::count(), 'Le scénario exige une branche unique.');

        $this->actingAs($this->unpinnedAdmin(), 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report');

        $response->assertStatus(200);
        $response->assertJsonPath('data.branch_id', $this->branch->id);
        $response->assertJsonStructure([
            'data' => ['branch_id', 'generated_at', 'period' => ['from', 'to'], 'totals'],
        ]);
    }

    /**
     * Plusieurs branches : l'admin désigne explicitement la caisse. Le choix
     * est explicite, donc acceptable pour une lecture fiscale.
     */
    public function test_x_report_accepts_an_explicit_branch_id_from_an_unpinned_admin(): void
    {
        $other = Branch::factory()->create();

        $this->actingAs($this->unpinnedAdmin(), 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report?branch_id=' . $other->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.branch_id', $other->id);
    }

    /**
     * Plusieurs branches et aucun choix : on refuse plutôt que de deviner.
     * Un instantané fiscal ne doit jamais porter sur une caisse arbitraire.
     */
    public function test_x_report_still_refuses_when_several_branches_exist_and_none_is_chosen(): void
    {
        Branch::factory()->create();

        $this->actingAs($this->unpinnedAdmin(), 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report');

        $response->assertStatus(422);
    }

    /**
     * Une branche inexistante doit être refusée proprement en 422, jamais en
     * 500 (l'InvalidArgumentException de XReportService fuirait une trace).
     */
    public function test_x_report_refuses_an_unknown_branch_id(): void
    {
        $this->actingAs($this->unpinnedAdmin(), 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report?branch_id=987654');

        $response->assertStatus(422);
    }

    /**
     * GARDE ANTI-IDOR : un employé épinglé sur la branche A qui demande
     * `?branch_id=B` doit recevoir SA branche, jamais B.
     */
    public function test_x_report_ignores_a_branch_id_param_for_branch_pinned_staff(): void
    {
        $other = Branch::factory()->create();

        $this->actingAs($this->pinnedManager($this->branch), 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report?branch_id=' . $other->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.branch_id', $this->branch->id);
    }

    /**
     * La permission reste le premier garde : sans `pos-manage-fiscal`, 403
     * avant toute résolution de branche.
     */
    public function test_x_report_permission_gate_runs_before_branch_resolution(): void
    {
        $operator = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $operator->assignRole('POS Operator');

        $this->actingAs($operator, 'sanctum');
        $response = $this->withHeaders($this->apiHeaders())
            ->getJson('/api/admin/fiscal/x-report');

        $response->assertStatus(403);
    }
}
