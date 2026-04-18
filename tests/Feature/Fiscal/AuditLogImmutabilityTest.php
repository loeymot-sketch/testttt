<?php

namespace Tests\Feature\Fiscal;

use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * [POS-9.4.3 / POS-GA-F-04]
 *
 * Proves the INSERT-only contract on `audit_logs`:
 *  - UPDATE blocked both at the Eloquent layer and at the raw SQL layer;
 *  - DELETE blocked both at the Eloquent layer and at the raw SQL layer.
 */
class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function seedLog(): AuditLog
    {
        return AuditLog::create([
            'branch_id'    => 1,
            'user_id'      => null,
            'action'       => 'test.seed',
            'resource'     => 'unit_test',
            'resource_id'  => null,
            'payload'      => ['k' => 'v'],
            'prev_hash'    => null,
            'current_hash' => str_repeat('a', 64),
        ]);
    }

    public function test_insert_is_allowed(): void
    {
        $log = $this->seedLog();
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'test.seed']);
    }

    public function test_update_blocked_via_eloquent(): void
    {
        $log = $this->seedLog();

        $this->expectException(RuntimeException::class);
        $log->action = 'tampered';
        $log->save();
    }

    public function test_update_blocked_via_raw_sql(): void
    {
        $log = $this->seedLog();

        try {
            DB::table('audit_logs')->where('id', $log->id)->update(['action' => 'tampered']);
            $this->fail('Raw UPDATE on audit_logs should have been rejected by the DB trigger.');
        } catch (QueryException $e) {
            // Both MySQL SIGNAL SQLSTATE and SQLite RAISE(ABORT,…) surface as QueryException.
            $this->assertStringContainsString('INSERT-only', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'action' => 'test.seed']);
    }

    public function test_delete_blocked_via_eloquent(): void
    {
        $log = $this->seedLog();

        $this->expectException(RuntimeException::class);
        $log->delete();
    }

    public function test_delete_blocked_via_raw_sql(): void
    {
        $log = $this->seedLog();

        try {
            DB::table('audit_logs')->where('id', $log->id)->delete();
            $this->fail('Raw DELETE on audit_logs should have been rejected by the DB trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('INSERT-only', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }
}
