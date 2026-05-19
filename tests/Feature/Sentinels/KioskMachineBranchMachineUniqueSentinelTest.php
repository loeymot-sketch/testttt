<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [WAVE-M P3 SWEEP-1 / DATA-INTEGRITY]
 *
 * Confirms that the composite UNIQUE constraint on
 * `kiosk_machines.(branch_id, machine_id)` is installed AND enforced
 * by the database driver.
 *
 * Migration: database/migrations/2026_05_19_210000_add_unique_branch_machine_id_to_kiosk_machines.php
 *
 * Asserts:
 * 1. Schema introspection finds a UNIQUE index covering both columns
 *    (MySQL `SHOW INDEX WHERE Non_unique = 0`, SQLite `PRAGMA index_list`).
 * 2. Behavioural: a second INSERT with the same `(branch_id, machine_id)`
 *    pair raises a QueryException with SQLSTATE 23000.
 *
 * Defense-in-depth above the application-layer
 * `KioskMachineService::store()` which currently has no pre-insert
 * collision check.
 */
class KioskMachineBranchMachineUniqueSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_machines_have_database_unique_guard_for_branch_id_and_machine_id(): void
    {
        $this->assertTrue(
            Schema::hasColumn('kiosk_machines', 'branch_id'),
            'kiosk_machines must expose branch_id so the composite UNIQUE can pin a machine_id per branch.'
        );
        $this->assertTrue(
            Schema::hasColumn('kiosk_machines', 'machine_id'),
            'kiosk_machines must expose machine_id so the composite UNIQUE can pin it per branch.'
        );

        $this->assertTrue(
            $this->hasUniqueIndexCovering(['branch_id', 'machine_id']),
            'kiosk_machines must have a UNIQUE index covering (branch_id, machine_id) to '
            . 'prevent duplicate machine registration within a branch at the DB layer.'
        );
    }

    public function test_duplicate_branch_machine_pair_is_rejected_by_database(): void
    {
        $branch = Branch::factory()->create();
        $user1 = User::factory()->create(['branch_id' => $branch->id]);
        $user2 = User::factory()->create(['branch_id' => $branch->id]);

        KioskMachine::create([
            'machine_id' => 'KIOSK-SENTINEL-001',
            'branch_id'  => $branch->id,
            'user_id'    => $user1->id,
            'username'   => 'kiosk-sentinel-1',
            'password'   => bcrypt('test-pass-1'),
            'status'     => Status::ACTIVE,
            'is_login'   => Ask::NO,
        ]);

        $this->expectException(QueryException::class);

        try {
            KioskMachine::create([
                'machine_id' => 'KIOSK-SENTINEL-001', // duplicate
                'branch_id'  => $branch->id,         // same branch
                'user_id'    => $user2->id,
                'username'   => 'kiosk-sentinel-2',  // different username (avoid orthogonal collisions)
                'password'   => bcrypt('test-pass-2'),
                'status'     => Status::ACTIVE,
                'is_login'   => Ask::NO,
            ]);
        } catch (QueryException $e) {
            // SQLSTATE 23000 = Integrity Constraint Violation
            // (MySQL errno 1062 / SQLite "UNIQUE constraint failed")
            $this->assertSame('23000', $e->getCode(), 'UNIQUE violation must surface as SQLSTATE 23000.');
            throw $e;
        }
    }

    /**
     * Verify a UNIQUE index exists that covers ALL the required columns
     * (order-insensitive — composite UNIQUE is canonically unordered for
     * INSERT/UPDATE conflict detection, regardless of column order in the
     * DDL).
     *
     * @param array<int,string> $requiredColumns
     */
    private function hasUniqueIndexCovering(array $requiredColumns): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select("PRAGMA index_list('kiosk_machines')") as $index) {
                if ((int) ($index->unique ?? 0) !== 1) {
                    continue;
                }
                $columns = array_map(
                    fn ($row) => (string) $row->name,
                    DB::select("PRAGMA index_info('" . str_replace("'", "''", (string) $index->name) . "')")
                );
                if (empty(array_diff($requiredColumns, $columns))) {
                    return true;
                }
            }
            return false;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select('SHOW INDEX FROM kiosk_machines WHERE Non_unique = 0');
            $byName = [];
            foreach ($rows as $row) {
                $byName[(string) $row->Key_name][] = (string) $row->Column_name;
            }
            foreach ($byName as $columns) {
                if (empty(array_diff($requiredColumns, $columns))) {
                    return true;
                }
            }
            return false;
        }

        $this->markTestSkipped("Driver {$driver} index introspection not supported here.");
        return false;
    }
}
