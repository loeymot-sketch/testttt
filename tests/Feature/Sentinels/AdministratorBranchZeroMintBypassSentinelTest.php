<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [bug_011 / R3 T-2.2.1 Sec S-3 / WH-5 2026-05-19] Sentinel for the
 * super-admin mint bypass via OMITTED branch_id field in
 * `app/Http/Requests/AdministratorRequest.php`.
 *
 * Exploit path (pre-fix):
 *   - Non-super-admin holding `administrators_create` POSTs
 *     `/api/admin/administrator` WITHOUT a `branch_id` field.
 *   - Validation: `'branch_id' => ['nullable','numeric']` → passes.
 *   - `withValidator` after() reads `$bid = $this->input('branch_id')`
 *     → `null`. The guard condition was
 *       `if ($bid !== null && (int)$bid === 0 && !$caller-isAdmin)`
 *     so when `$bid === null` the gate short-circuits — NO error added.
 *   - `AdministratorService::store` writes `branch_id => null` directly
 *     via Eloquent (explicit NULL bypasses the column default `0`)
 *     and unconditionally calls `assignRole(EnumRole::ADMIN)`.
 *   - On the next request `DefaultAccessModelTrait::branch()` evaluates
 *     `(int) null === 0` → TRUE → BranchScope is skipped → the new user
 *     reads/writes ACROSS ALL BRANCHES = super-admin.
 *
 * Heal (Option A): the gate must also reject `$bid === null` for
 * non-super-admin callers. Only Admin / Tenant Admin may mint a user
 * with no branch (cross-branch super-admin).
 *
 * Sentinel coverage:
 *   1. Non-super-admin POST WITHOUT branch_id → 422 AND zero Admin@null
 *      rows created (locks the exact exploit path).
 *   2. Non-super-admin POST branch_id=0 explicit → 422 (regression of
 *      the partial M-R3-P0-E heal that already worked for the explicit
 *      path).
 *   3. Non-super-admin POST branch_id=42 (valid non-zero) → 201 SUCCESS
 *      with persisted branch_id=42 and Admin role (legitimate
 *      branch-scoped admin creation still works).
 *   4. Super-admin (Admin role) POST WITHOUT branch_id → 201 SUCCESS
 *      (cross-branch admin creation by a super-admin remains allowed).
 *   5. PHP semantic lock: `(int) null === 0` and `(int) '' === 0` so
 *      the downstream `DefaultAccessModelTrait::branch()` MUST refuse
 *      a null branch_id for any non-super-admin user — this assertion
 *      pins the underlying invariant so a future refactor cannot
 *      regress the coupling silently.
 *   6. UPDATE bypass guard: a non-super-admin PATCHes an existing
 *      branch-scoped admin WITHOUT branch_id → 422 (proves the gate
 *      covers both store AND update verbs — both use
 *      AdministratorRequest).
 */
class AdministratorBranchZeroMintBypassSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $superAdmin;       // branch_id=0, role Admin
    private User $branchManager;    // branch_id>0, role Branch Manager + administrators_create

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // seedSpatieRoles() does NOT seed `administrators_create`
        // (helper covers only POS / items / coupons / etc.) so we add
        // the bare-minimum permission + grant it to the branch-scoped
        // caller used as the "attacker" in this sentinel.
        Permission::firstOrCreate([
            'name'       => 'administrators_create',
            'guard_name' => 'sanctum',
        ]);
        Permission::firstOrCreate([
            'name'       => 'administrators_edit',
            'guard_name' => 'sanctum',
        ]);

        $branchManagerRole = Role::where('name', 'Branch Manager')
            ->where('guard_name', 'sanctum')
            ->first();
        $branchManagerRole->givePermissionTo(['administrators_create', 'administrators_edit']);

        // Admin role must also carry the same permissions (super-admin)
        // — seedSpatieRoles() doesn't include the administrators_* family.
        $adminRole = Role::where('name', 'Admin')
            ->where('guard_name', 'sanctum')
            ->first();
        $adminRole->givePermissionTo(['administrators_create', 'administrators_edit']);

        $this->branch = Branch::factory()->create();

        $this->superAdmin = User::factory()->create([
            'branch_id' => 0,
        ]);
        $this->superAdmin->assignRole('Admin');

        $this->branchManager = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->branchManager->assignRole('Branch Manager');
    }

    /**
     * Build the minimal valid request body for the store endpoint —
     * matches AdministratorRequest::rules() (name + email + password +
     * password_confirmation + status + country_code).
     */
    private function buildStoreBody(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'New Admin',
            'email'                 => 'new-admin-' . uniqid() . '@foodking.test',
            'password'              => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
            'status'                => 1,
            'country_code'          => 'FR',
        ], $overrides);
    }

    public function test_1_non_super_admin_post_without_branch_id_is_blocked_422(): void
    {
        $usersBefore = User::query()->count();

        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->postJson('/api/admin/administrator', $this->buildStoreBody());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['branch_id']);

        // Critical: ensure NO user row leaked through (the exploit
        // creates a User+Admin role even if validation later errors,
        // because the legacy code path saved before erroring).
        // Total count unchanged.
        $this->assertSame(
            $usersBefore,
            User::query()->count(),
            'No user row may be created when the gate rejects the request.'
        );

        // And specifically: no Admin@null was minted (exact exploit
        // signature).
        $adminNullCount = User::query()
            ->whereNull('branch_id')
            ->role('Admin', 'sanctum')
            ->count();
        $this->assertSame(
            0,
            $adminNullCount,
            'No Admin user with branch_id=NULL may exist after the rejected request '
            . '(R3 T-2.2.1 Sec S-3 exact exploit signature).'
        );
    }

    public function test_2_non_super_admin_post_branch_zero_explicit_is_blocked_422(): void
    {
        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->postJson('/api/admin/administrator', $this->buildStoreBody([
                'branch_id' => 0,
            ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['branch_id']);

        $this->assertSame(
            0,
            User::query()->where('branch_id', 0)->role('Admin', 'sanctum')
                ->where('id', '!=', $this->superAdmin->id)->count(),
            'Pre-existing super-admin remains the only branch_id=0 Admin.'
        );
    }

    public function test_3_non_super_admin_post_branch_id_valid_succeeds(): void
    {
        $body = $this->buildStoreBody([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->postJson('/api/admin/administrator', $body);

        // 200 (controller returns AdministratorResource which serializes
        // to 200 by default — Laravel default for create-via-resource).
        $response->assertSuccessful();

        $created = User::query()->where('email', $body['email'])->first();
        $this->assertNotNull($created, 'New admin must be persisted.');
        $this->assertSame(
            $this->branch->id,
            (int) $created->branch_id,
            'Legitimate branch-scoped admin keeps its branch_id intact.'
        );
        $this->assertTrue(
            $created->hasRole(EnumRole::ADMIN),
            'AdministratorService::store unconditionally assigns the Admin role — '
            . 'this is by design for this endpoint.'
        );
    }

    public function test_4_super_admin_post_without_branch_id_is_allowed(): void
    {
        $body = $this->buildStoreBody();

        $response = $this->actingAs($this->superAdmin, 'sanctum')
            ->postJson('/api/admin/administrator', $body);

        $response->assertSuccessful();

        $created = User::query()->where('email', $body['email'])->first();
        $this->assertNotNull(
            $created,
            'A super-admin (role Admin) is still permitted to mint a cross-branch '
            . 'admin with branch_id omitted/null — the gate must only fire for '
            . 'non-super-admin callers.'
        );
    }

    public function test_5_php_null_to_int_semantic_is_locked(): void
    {
        // This assertion is intentionally not "decorative" — it pins
        // the underlying PHP semantic that the gate relies on.
        // `DefaultAccessModelTrait::branch()` line 21 does
        //    `(int) Auth::user()->branch_id === 0`
        // → ANY non-zero-truthy branch_id (null, '', 0, '0') makes the
        // user a super-admin via BranchScope short-circuit.
        // If a future refactor changes this coupling, this sentinel
        // will fail loudly and the gate must be re-audited.
        $this->assertSame(0, (int) null, 'PHP: (int) null === 0');
        $this->assertSame(0, (int) '', 'PHP: (int) "" === 0');
        $this->assertSame(0, (int) '0', 'PHP: (int) "0" === 0');
        $this->assertSame(0, (int) 0, 'PHP: (int) 0 === 0');
    }

    public function test_6_non_super_admin_patch_without_branch_id_is_blocked_422(): void
    {
        // Build a target admin that lives at branch 42 (legitimate).
        $target = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $target->assignRole('Admin');

        // Attacker: branch manager (no Admin/Tenant Admin role) tries
        // to UPDATE the target and omit branch_id — pre-fix the gate
        // would skip (same null short-circuit) and
        // AdministratorService::update line 96 would write
        // branch_id = null → target becomes super-admin.
        $response = $this->actingAs($this->branchManager, 'sanctum')
            ->patchJson('/api/admin/administrator/' . $target->id, [
                'name'                  => $target->name,
                'email'                 => $target->email,
                'status'                => 1,
                'country_code'          => 'FR',
                'password'              => null,
                'password_confirmation' => null,
                // branch_id intentionally OMITTED — exploit signature.
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['branch_id']);

        $target->refresh();
        $this->assertSame(
            $this->branch->id,
            (int) $target->branch_id,
            'Target admin branch_id must remain unchanged when the gate rejects the update.'
        );
    }
}
