<?php

namespace Tests\Feature\Auth;

use App\Enums\Ask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Compte de démonstration des stores (décision propriétaire 2026-10-15) : création, idempotence, retrait, refus staff. */
class ReviewAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_cree_un_compte_invite_client_avec_telephone_puis_est_idempotent_puis_retire(): void
    {
        $this->artisan('foodking:review-account', ['--email' => 'Revue@Lecayenne.fr'])->assertExitCode(0);

        $u = User::withoutGlobalScopes()->where('email', 'revue@lecayenne.fr')->first();
        $this->assertNotNull($u);
        $this->assertSame(Ask::YES, (int) $u->is_guest);
        $this->assertSame('0600000042', $u->phone);
        $this->assertSame(0, (int) $u->loyalty_points);
        $this->assertTrue($u->hasRole(\App\Enums\Role::CUSTOMER));

        $this->artisan('foodking:review-account', ['--email' => 'revue@lecayenne.fr'])->assertExitCode(0);
        $this->assertSame(1, User::withoutGlobalScopes()->withTrashed()->where('email', 'revue@lecayenne.fr')->count());

        $this->artisan('foodking:review-account', ['--email' => 'revue@lecayenne.fr', '--remove' => true])->assertExitCode(0);
        $this->assertNull(User::withoutGlobalScopes()->withTrashed()->where('email', 'revue@lecayenne.fr')->first());
    }

    public function test_refuse_un_compte_non_invite_et_un_email_invalide(): void
    {
        User::create([
            'name' => 'Chef', 'username' => 'chef-x', 'email' => 'staff@lecayenne.fr', 'phone' => '0600000077',
            'country_code' => '+33', 'branch_id' => 1, 'is_guest' => Ask::NO,
            'status' => \App\Enums\Status::ACTIVE, 'password' => bcrypt('x'),
        ]);

        $this->artisan('foodking:review-account', ['--email' => 'staff@lecayenne.fr'])->assertExitCode(1);
        $this->artisan('foodking:review-account', ['--email' => 'staff@lecayenne.fr', '--remove' => true])->assertExitCode(1);
        $this->assertNotNull(User::withoutGlobalScopes()->where('email', 'staff@lecayenne.fr')->first(), 'Le compte staff ne doit jamais être supprimé.');
        $this->artisan('foodking:review-account', ['--email' => 'pas-un-email'])->assertExitCode(1);
    }
}
