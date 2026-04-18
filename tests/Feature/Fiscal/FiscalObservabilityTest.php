<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * [POS-9-H.3.2 / F-C7]
 *
 * The fiscal channel is the canonical observability stream for NF525
 * events. A SIEM / on-call alert-on-silence pipeline correlates on
 * `audit_log.write`, `z_report.open`, `z_report.close`. If the channel
 * is silent we must know, so we lock both the channel definition and
 * the key names here.
 *
 * We bind a spy on the fiscal channel and assert:
 *  - AuditLogService::write() emits one `audit_log.write` with the
 *    structural fields (id, branch_id, action, hash_prefix) but NEVER
 *    the payload (PII hygiene).
 *  - ZReportService::open() emits one `z_report.open`.
 *  - ZReportService::close() emits one `z_report.close` with the
 *    aggregate totals and a signature prefix (12 chars — enough for
 *    cross-ref, never the full HMAC).
 */
class FiscalObservabilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,array{level:string,message:string,context:array}> */
    private array $fiscalLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-observability-secret-xxxxxxxx');
        Config::set('fiscal.z_report_secret', 'unit-test-observability-secret-yyyyyyyy');
        $this->fiscalLogs = [];

        // [POS-9-H.3.2] Bind a spy on the `fiscal` channel so we can
        // assert the exact shape of what gets emitted.
        Log::channel('fiscal')->getLogger()->pushProcessor(function ($record) {
            $this->fiscalLogs[] = [
                'level'   => (string) $record['level_name'] ?? (string) $record->level->getName(),
                'message' => (string) $record['message'] ?? (string) $record->message,
                'context' => (array)  $record['context'] ?? (array) $record->context,
            ];
            return $record;
        });
    }

    private function findLog(string $message): ?array
    {
        foreach ($this->fiscalLogs as $l) {
            if ($l['message'] === $message) {
                return $l;
            }
        }
        return null;
    }

    public function test_fiscal_channel_is_registered(): void
    {
        $cfg = Config::get('logging.channels.fiscal');
        $this->assertIsArray($cfg, 'fiscal log channel must be declared in config/logging.php');
        $this->assertSame('daily', $cfg['driver']);
        $this->assertGreaterThanOrEqual(365, (int) ($cfg['days'] ?? 0),
            'Fiscal retention must be at least 365 days to cover NF525 disputes.');
    }

    public function test_audit_log_write_emits_breadcrumb(): void
    {
        $branch = Branch::factory()->create();

        $row = app(AuditLogService::class)->write([
            'branch_id' => $branch->id,
            'action'    => 'test.observability',
            'payload'   => ['secret_customer_email' => 'alice@example.com'],
        ]);

        $log = $this->findLog('audit_log.write');
        $this->assertNotNull($log, 'AuditLogService::write must emit audit_log.write.');
        $this->assertSame($row->id,    $log['context']['audit_log_id']);
        $this->assertSame($branch->id, $log['context']['branch_id']);
        $this->assertSame('test.observability', $log['context']['action']);
        $this->assertArrayHasKey('hash_prefix', $log['context']);
        $this->assertSame(12, strlen($log['context']['hash_prefix']));

        // Hygiene: the payload must NOT leak.
        $encoded = json_encode($log['context']);
        $this->assertStringNotContainsString('alice@example.com', $encoded,
            'Payload must never be echoed to the fiscal channel.');
        $this->assertStringNotContainsString('secret_customer_email', $encoded);
    }

    public function test_z_report_open_and_close_emit_breadcrumbs(): void
    {
        $branch = Branch::factory()->create();
        $svc    = app(ZReportService::class);

        $opened = $svc->open($branch->id, null);
        $open = $this->findLog('z_report.open');
        $this->assertNotNull($open, 'ZReportService::open must emit z_report.open.');
        $this->assertSame($opened->id,          $open['context']['z_report_id']);
        $this->assertSame($branch->id,          $open['context']['branch_id']);
        $this->assertSame($opened->sequence_no, $open['context']['sequence_no']);

        $closed = $svc->close($branch->id, null);
        $close = $this->findLog('z_report.close');
        $this->assertNotNull($close, 'ZReportService::close must emit z_report.close.');
        $this->assertSame($closed->id,           $close['context']['z_report_id']);
        $this->assertSame($branch->id,           $close['context']['branch_id']);
        $this->assertSame($closed->sequence_no,  $close['context']['sequence_no']);
        $this->assertArrayHasKey('total_ttc',    $close['context']);
        $this->assertArrayHasKey('signature_prefix', $close['context']);
        $this->assertSame(12, strlen($close['context']['signature_prefix']),
            'Only a 12-char signature prefix may be logged — full HMAC stays in DB.');
    }
}
