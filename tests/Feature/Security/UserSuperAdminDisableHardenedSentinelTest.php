<?php

namespace Tests\Feature\Security;

use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sentinel — GOAL-J2-HEAL-01 2026-05-24 (Phase J-ADV-7 HC-001 P0).
 *
 * Closes the hardcoded super-admin (id===1) auto-restore security back-door.
 *
 *   BEFORE this heal, app/Models/User.php had a `static::updating()` hook:
 *
 *       static::updating(function ($user) {
 *           if ($user->id === 1) {
 *               $user->status = Status::ACTIVE;
 *           }
 *       });
 *
 *   On EVERY save of user id=1 the hook forced `status` back to ACTIVE.
 *   Consequence: the super-admin could NEVER be disabled. A compromised
 *   id=1 credential remained usable after every disablement attempt —
 *   critical vector for insider attack OR account-takeover persistence,
 *   plus a denial-of-recovery for legitimate ops.
 *
 *   Fix (commit see commit body): remove the hardcoded id===1 branch.
 *   No automatic status mutation in the model lifecycle anymore.
 *   Recovery from accidental root lockout is handled out-of-band via
 *   a documented recovery procedure + dedicated seed migration, not by
 *   a silent ORM hook.
 *
 * This sentinel locks the contract from BOTH directions so a future PR
 * that re-introduces ANY hardcoded id===1 fast-path on status is caught
 * by a single CI signal:
 *
 *   01. DISABLED → DISABLED on save  (the actual back-door direction)
 *   02. ACTIVE   → INACTIVE on save  (intent preserved either way)
 *   03. File-string sentinel : `if ($user->id === 1)` is not allowed to
 *       reappear anywhere in app/Models/User.php.
 *
 * @see app/Models/User.php (booted() — id===1 fast-path removed)
 * @see CLAUDE.md §9 Auth Invariants
 */
class UserSuperAdminDisableHardenedSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * J2-HEAL-01-01: user id=1 set to INACTIVE must REMAIN INACTIVE after save.
     *
     * Reproduces the back-door from the inside: build a user pinned to id=1,
     * flip status to INACTIVE, persist, refresh, assert the status sticks.
     * Before the fix this assertion failed (status snapped back to ACTIVE).
     */
    public function test_super_admin_disable_is_not_auto_restored(): void
    {
        // Force id=1 — RefreshDatabase gives us a clean autoincrement table.
        $userId = DB::table('users')->insertGetId([
            'id'         => 1,
            'name'       => 'J2-HEAL-01 Root',
            'email'      => 'j2heal01-root@example.test',
            'phone'      => '0600000001',
            'username'   => 'j2heal01_root',
            'password'   => Hash::make('Sentinel2026!'),
            'branch_id'  => 0,
            'status'     => Status::ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $userId, 'Sentinel requires user id=1 to reproduce the back-door.');

        $user = User::query()->findOrFail(1);
        $this->assertSame(Status::ACTIVE, (int) $user->status);

        // The back-door direction: try to DISABLE the super-admin via the
        // standard model flow.
        $user->status = Status::INACTIVE;
        $user->save();

        $reloaded = User::query()->findOrFail(1);
        $this->assertSame(
            Status::INACTIVE,
            (int) $reloaded->status,
            'User id=1 status was auto-restored — the hardcoded super-admin '
            . 'un-disable back-door has been re-introduced. '
            . 'See tests/Feature/Security/UserSuperAdminDisableHardenedSentinel.php'
        );
    }

    /**
     * J2-HEAL-01-02: opposite direction — ACTIVE → INACTIVE is also honoured
     * for ANY other user (no other hardcoded id fast-path).
     *
     * Belt-and-braces: a regression that pins another magic id (id===2 etc.)
     * would also be caught.
     */
    public function test_no_other_user_id_has_auto_restore_fast_path(): void
    {
        foreach ([2, 3, 7, 42] as $id) {
            DB::table('users')->insert([
                'id'         => $id,
                'name'       => 'J2-HEAL-01 User ' . $id,
                'email'      => "j2heal01-user{$id}@example.test",
                'phone'      => '060000000' . $id,
                'username'   => 'j2heal01_u' . $id,
                'password'   => Hash::make('Sentinel2026!'),
                'branch_id'  => 0,
                'status'     => Status::ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $u = User::query()->findOrFail($id);
            $u->status = Status::INACTIVE;
            $u->save();

            $this->assertSame(
                Status::INACTIVE,
                (int) User::query()->findOrFail($id)->status,
                "User id={$id} auto-restored — a new hardcoded fast-path was added."
            );
        }
    }

    /**
     * J2-HEAL-01-03: file-string sentinel — `if ($user->id === 1)` is not
     * allowed to reappear in app/Models/User.php.
     *
     * Catches refactors that move the back-door behind a wrapper before the
     * runtime tests above can fire (e.g. someone re-adds the hook but only
     * triggers it on a different lifecycle event).
     */
    public function test_user_model_has_no_hardcoded_id_one_fast_path(): void
    {
        $path = base_path('app/Models/User.php');
        $src  = file_get_contents($path);
        $this->assertNotFalse($src, 'Could not read app/Models/User.php');

        // Match the two equivalent PHP equality forms anywhere in the file
        // (strict + loose, with or without spaces).
        $patterns = [
            '/\$user->id\s*===\s*1\b/',
            '/\$user->id\s*==\s*1\b/',
        ];

        foreach ($patterns as $pat) {
            $this->assertSame(
                0,
                preg_match($pat, $src),
                "app/Models/User.php contains a hardcoded id===1 / id==1 fast-path "
                . "matching {$pat}. This back-door was removed in GOAL-J2-HEAL-01 "
                . "(Phase J-ADV-7 HC-001 P0). Use the documented recovery "
                . "procedure + dedicated seed migration instead."
            );
        }
    }
}
