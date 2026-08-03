<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MysqlOnly;
use Tests\TestCase;

/**
 * [P0-FIX-3 NF525 / iter15 — 2026-05-10]
 *
 * Verifies that the `z_reports_no_delete` trigger installed by
 * migration `2026_05_09_160000_add_z_reports_delete_trigger_immutability`
 * actually rejects DELETE attempts at the database layer.
 *
 * Why a dedicated MySQL-only test:
 *   - The migration is driver-conditional (`mysql`/`mariadb` only) and
 *     phpunit.xml defaults to SQLite (`:memory:`), so the trigger SQL
 *     is NEVER exercised by the rest of the fiscal suite.
 *   - The audit (iter15-C P0-03) flagged 0 test coverage of this
 *     production-only safeguard. A regression that silently dropped
 *     the migration body or changed the trigger SQL would go
 *     undetected on CI.
 *
 * Test contract:
 *   1. Skip cleanly when not on MySQL/MariaDB (default SQLite runner).
 *   2. INSERT a closed z_report row directly (bypassing service flow).
 *   3. Attempt DELETE via raw SQL → expect SQLSTATE 45000.
 *   4. Attempt DELETE via Eloquent → expect the same exception path.
 *   5. Confirm row still exists after both attempts (defence in depth).
 *
 * Pattern alignment: same shape as the audit_logs immutability tests
 * (`AuditLogImmutabilityTest`) and the existing fiscal close suite
 * (`ZReportCloseTest`).
 */
class ZReportDeleteTriggerMysqlOnlyTest extends TestCase
{
    use MysqlOnly;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip the entire test class on non-MySQL runners. Calling this
        // in setUp keeps every test method's assertion cost zero on
        // SQLite CI without each method having to re-check.
        $this->requiresMysqlDriver();

        // Defensive: make sure the trigger is actually installed in this
        // ephemeral DB. RefreshDatabase replays migrations, so the
        // 2026_05_09_160000 migration has already run — but if a future
        // refactor moves the trigger SQL out of the migration, we want
        // the test to fail fast with a meaningful message instead of a
        // confusing "DELETE silently succeeded" assertion later.
        $this->assertTriggerInstalled('z_reports_no_delete', 'z_reports');
    }

    public function test_raw_delete_on_z_reports_is_rejected_by_trigger(): void
    {
        $row = $this->insertClosedZReport();

        $caught = null;
        try {
            DB::statement('DELETE FROM z_reports WHERE id = ?', [$row->id]);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull(
            $caught,
            'Raw DELETE on z_reports must be rejected by the BEFORE-DELETE trigger.'
        );
        $this->assertStringContainsStringIgnoringCase(
            'z_reports is immutable',
            $caught->getMessage(),
            'Trigger SIGNAL message must surface to the application layer.'
        );

        // App-layer assertion: row still exists after rejection.
        $this->assertDatabaseHas('z_reports', ['id' => $row->id]);
    }

    public function test_eloquent_delete_on_z_reports_is_rejected_by_trigger(): void
    {
        $row = $this->insertClosedZReport();

        $caught = null;
        try {
            ZReport::query()->whereKey($row->id)->delete();
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertNotNull(
            $caught,
            'Eloquent DELETE on ZReport must be rejected by the trigger (no silent success).'
        );

        // Defence in depth: row must still be queryable through Eloquent.
        $this->assertNotNull(
            ZReport::query()->find($row->id),
            'Z report row must survive a rejected DELETE attempt.'
        );
    }

    public function test_trigger_is_present_in_information_schema(): void
    {
        // Direct introspection of MySQL information_schema — fails the
        // entire suite if the migration body is ever silently removed.
        $rows = DB::select(
            "SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND EVENT_OBJECT_TABLE = 'z_reports'
                AND TRIGGER_NAME = 'z_reports_no_delete'"
        );

        $this->assertCount(
            1,
            $rows,
            'Exactly one z_reports_no_delete trigger must be installed by the iter14 migration.'
        );
        $this->assertSame('DELETE', strtoupper((string) ($rows[0]->EVENT_MANIPULATION ?? '')));
        $this->assertSame('BEFORE', strtoupper((string) ($rows[0]->ACTION_TIMING ?? '')));
    }

    /**
     * Insert a minimal valid closed z_report row without going through
     * ZReportService::open()/close() — keeps the test focused on the
     * trigger contract and decoupled from sequence allocation logic.
     */
    private function insertClosedZReport(): ZReport
    {
        $branch = Branch::factory()->create();
        $now    = Carbon::now();

        return ZReport::create([
            'branch_id'         => $branch->id,
            'sequence_no'       => 1,
            'opened_at'         => $now->copy()->subHour(),
            'closed_at'         => $now,
            'opened_by'         => null,
            'closed_by'         => null,
            'total_ht'          => 0.0,
            'total_ttc'         => 0.0,
            'total_tva'         => 0.0,
            'total_by_method'   => [],
            'total_by_tax_rate' => [],
            'order_count'       => 0,
            'cancel_count'      => 0,
            'refund_count'      => 0,
            'prev_hash'         => str_repeat('0', 64),
            'signature'         => str_repeat('a', 64),
            'status'            => ZReport::STATUS_CLOSED,
        ]);
    }

    private function assertTriggerInstalled(string $triggerName, string $tableName): void
    {
        $rows = DB::select(
            "SELECT TRIGGER_NAME
               FROM information_schema.TRIGGERS
              WHERE TRIGGER_SCHEMA = DATABASE()
                AND EVENT_OBJECT_TABLE = ?
                AND TRIGGER_NAME = ?",
            [$tableName, $triggerName]
        );

        $this->assertCount(
            1,
            $rows,
            "Trigger {$triggerName} on {$tableName} must be installed by migrations before this test runs."
        );
    }
}
