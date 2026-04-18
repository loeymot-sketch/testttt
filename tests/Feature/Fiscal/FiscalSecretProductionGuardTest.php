<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\ZReportService;
use App\Models\ZReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

/**
 * [POS-9-H.2.1 / F-C1]
 *
 * Production guard for fiscal secrets. The previous config shipped
 * `dev-fiscal-audit-secret-do-not-use-in-prod` as a default, and any
 * deployment that forgot to set FISCAL_AUDIT_SECRET inherited it and
 * silently signed real NF525 receipts with a publicly known key.
 *
 * Expected behavior:
 *   - APP_ENV != production: dev secrets still accepted (CI stays fast).
 *   - APP_ENV == production:
 *       * empty secret -> RuntimeException ("not configured").
 *       * known dev sentinel (e.g. shipped default) -> RuntimeException.
 *       * secret < min_secret_length -> RuntimeException.
 *       * strong secret (>= 32 chars, not in sentinels) -> OK.
 */
class FiscalSecretProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Re-assert defaults in case a sibling test mutated them.
        Config::set('fiscal.dev_sentinels', [
            'dev-fiscal-audit-secret-do-not-use-in-prod',
            'dev-fiscal-zreport-secret-do-not-use-in-prod',
            'changeme',
            'change-me',
            'test',
            'dev',
            'secret',
        ]);
        Config::set('fiscal.min_secret_length', 32);
    }

    private function forceProd(): void
    {
        app()->detectEnvironment(fn () => 'production');
    }

    private function forceLocal(): void
    {
        app()->detectEnvironment(fn () => 'testing');
    }

    // ------------- AuditLogService ----------------------------------------

    public function test_audit_log_rejects_dev_sentinel_in_production(): void
    {
        Config::set('fiscal.audit_secret', 'dev-fiscal-audit-secret-do-not-use-in-prod');
        $this->forceProd();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dev sentinel|FISCAL_AUDIT_SECRET|rotate/i');

        app(AuditLogService::class)->write([
            'branch_id' => 1, 'action' => 'order.create', 'payload' => ['id' => 1],
        ]);
    }

    public function test_audit_log_rejects_short_secret_in_production(): void
    {
        Config::set('fiscal.audit_secret', 'short-but-not-sentinel');
        $this->forceProd();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/shorter than 32/');

        app(AuditLogService::class)->write([
            'branch_id' => 1, 'action' => 'order.create', 'payload' => ['id' => 1],
        ]);
    }

    public function test_audit_log_accepts_strong_secret_in_production(): void
    {
        Config::set('fiscal.audit_secret', bin2hex(random_bytes(32)));
        $this->forceProd();

        $row = app(AuditLogService::class)->write([
            'branch_id' => 1, 'action' => 'order.create', 'payload' => ['id' => 1],
        ]);
        $this->assertNotNull($row->current_hash);
    }

    public function test_audit_log_allows_dev_sentinel_outside_production(): void
    {
        Config::set('fiscal.audit_secret', 'dev-fiscal-audit-secret-do-not-use-in-prod');
        $this->forceLocal();

        $row = app(AuditLogService::class)->write([
            'branch_id' => 1, 'action' => 'order.create', 'payload' => ['id' => 1],
        ]);
        $this->assertNotNull($row->current_hash);
    }

    // ------------- ZReportService -----------------------------------------

    public function test_z_report_rejects_dev_sentinel_in_production(): void
    {
        Config::set('fiscal.z_report_secret', 'dev-fiscal-zreport-secret-do-not-use-in-prod');
        $this->forceProd();

        // Directly test the private sign() path via reflection so we don't
        // have to stand up a full ZReport open/close pipeline here.
        $svc = app(ZReportService::class);
        $r = new \ReflectionClass($svc);
        $m = $r->getMethod('sign');
        $m->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dev sentinel|rotate/i');

        $m->invoke($svc, 1, 'prev', 1, ['total' => 100], now());
    }

    public function test_z_report_rejects_short_secret_in_production(): void
    {
        Config::set('fiscal.z_report_secret', 'too-short');
        $this->forceProd();

        $svc = app(ZReportService::class);
        $r = new \ReflectionClass($svc);
        $m = $r->getMethod('sign');
        $m->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/shorter than 32/');

        $m->invoke($svc, 1, 'prev', 1, ['total' => 100], now());
    }
}
