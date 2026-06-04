<?php

namespace Tests\Feature\Fiscal;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-K2-HEAL-06 2026-05-24] Phase K.7 K7-FIND-2 P2 sentinel
 *
 * Z-close did NOT write any audit_logs HMAC anchor entry — only logged
 * to file channel 'fiscal'. NF525 chain had Z-chain (z_reports.signature)
 * AND audit_logs chain but no cross-chain anchor binding the two.
 *
 * Forensic concern: a Z-close had no audit_logs row, so an audit_logs
 * walk alone could not detect Z-close existence.
 *
 * Fix: AppServiceProvider boot registers a ZReport::updated hook gated
 * by wasChanged('status') + status===STATUS_CLOSED + non-null signature
 * that writes an audit entry 'z_report.closed' with the signature,
 * prev_hash and sequence_no.
 *
 * This sentinel verifies:
 *   1. closing a Z increments audit_logs by exactly +1;
 *   2. the new row has action='z_report.closed', resource='z_report',
 *      resource_id=Z report id, branch_id=Z branch_id;
 *   3. the payload carries sequence_no + signature (the canonical
 *      cross-chain anchor data);
 *   4. opening a Z (status=OPEN, no signature yet) does NOT write any
 *      audit_logs row — the hook must not anchor a hollow event.
 *
 * Bound: this sentinel is the regression guard. Removing the hook OR
 * the gating predicates would flip these assertions and CI would fail.
 */
class ZReportCloseAuditAnchorSentinelTest extends TestCase
{
    use RefreshDatabase;

    private ZReportService $service;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        // Both secrets are required: z_report_secret for the Z signature
        // chain, audit_secret for the audit_logs HMAC chain. The hook
        // writes via AuditLogService::write() which throws if the audit
        // secret is missing or weaker than 32 chars in production. The
        // test runner is non-production so a short string is accepted by
        // assertProductionSafe(), but we use a 48-char string anyway to
        // mirror the production guard and stay self-documenting.
        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');
        Config::set('fiscal.audit_secret',    'unit-test-audit-anchor-sentinel-48-chars-padding');

        $this->service = app(ZReportService::class);
        $this->branch  = Branch::factory()->create();
    }

    public function test_close_writes_audit_logs_anchor_row(): void
    {
        // Open the Z. STATUS=OPEN, signature=null. Hook must NOT fire.
        $beforeOpen = AuditLog::query()->count();
        $open = $this->service->open($this->branch->id);
        $afterOpen = AuditLog::query()->count();

        $this->assertSame(
            $beforeOpen,
            $afterOpen,
            'Opening a Z must NOT write any audit_logs row — '
            .'the cross-chain anchor is reserved for the close event.'
        );

        // Close the Z. STATUS transitions OPEN -> CLOSED, signature non-null.
        // Hook must fire exactly once: +1 audit_logs row.
        $closed = $this->service->close($this->branch->id);

        $this->assertSame(ZReport::STATUS_CLOSED, $closed->status);
        $this->assertNotEmpty($closed->signature, 'Z close must produce a signature.');

        $afterClose = AuditLog::query()->count();
        $this->assertSame(
            $afterOpen + 1,
            $afterClose,
            'Closing a Z must write exactly ONE audit_logs row (cross-chain anchor).'
        );

        // The row must witness the close: action='z_report.closed',
        // resource='z_report', resource_id=Z id, branch_id matches.
        $anchor = AuditLog::query()->orderByDesc('id')->first();
        $this->assertNotNull($anchor);
        $this->assertSame('z_report.closed', $anchor->action);
        $this->assertSame('z_report',        $anchor->resource);
        $this->assertSame((int) $closed->id, (int) $anchor->resource_id);
        $this->assertSame((int) $this->branch->id, (int) $anchor->branch_id);

        // Payload must carry the canonical cross-chain anchor data:
        // sequence_no + signature. A forensic walker on audit_logs alone
        // can cross-reference the z_reports row from this payload.
        $payload = (array) ($anchor->payload ?? []);
        $this->assertArrayHasKey('sequence_no', $payload);
        $this->assertArrayHasKey('signature',   $payload);
        $this->assertSame((int) $closed->sequence_no, (int) $payload['sequence_no']);
        $this->assertSame((string) $closed->signature, (string) $payload['signature']);
        $this->assertArrayHasKey('z_report_id', $payload);
        $this->assertSame((int) $closed->id, (int) $payload['z_report_id']);
    }

    public function test_open_alone_does_not_write_audit_anchor(): void
    {
        // Standalone explicit assertion: a Z that is opened but never
        // closed must leave audit_logs untouched. This guards against a
        // regression where the hook misfires on every save (e.g. someone
        // removes the wasChanged('status') guard).
        $before = AuditLog::query()->count();

        $this->service->open($this->branch->id);

        $this->assertSame(
            $before,
            AuditLog::query()->count(),
            'Z open must NOT write any audit_logs row.'
        );
    }
}
