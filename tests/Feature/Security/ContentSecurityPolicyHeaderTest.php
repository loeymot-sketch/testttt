<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ContentSecurityPolicyHeader;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Sentinel tests for RED-R2 §1 P2 — CSP migration <meta> → HTTP header.
 *
 * @see app/Http/Middleware/ContentSecurityPolicyHeader.php
 * @see config/security.php
 * @see docs/runbooks/CSP_HEADER_MIGRATION.md
 */
class ContentSecurityPolicyHeaderTest extends TestCase
{
    private function runMiddleware(string $mode): \Symfony\Component\HttpFoundation\Response
    {
        config()->set('security.csp.mode', $mode);
        config()->set('security.csp.directives', "default-src 'self'; report-uri /api/frontend/csp-report;");

        return (new ContentSecurityPolicyHeader())->handle(
            Request::create('/probe', 'GET'),
            fn ($req) => response('ok')
        );
    }

    public function test_report_only_mode_sets_report_only_header(): void
    {
        $response = $this->runMiddleware('report_only');

        $this->assertTrue($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_enforce_mode_sets_enforcing_header(): void
    {
        $response = $this->runMiddleware('enforce');

        $this->assertTrue($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('report-uri /api/frontend/csp-report', $response->headers->get('Content-Security-Policy'));
    }

    public function test_disabled_mode_emits_no_csp_header(): void
    {
        $response = $this->runMiddleware('disabled');

        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_default_config_mode_is_report_only(): void
    {
        // Boot config from disk (no override) — guards against accidental flip to enforce.
        $this->assertSame('report_only', config('security.csp.mode'));
        $this->assertNotEmpty(config('security.csp.directives'));
    }

    public function test_middleware_is_registered_in_web_group(): void
    {
        $kernel = app(\App\Http\Kernel::class);
        $reflect = new \ReflectionClass($kernel);
        $prop = $reflect->getProperty('middlewareGroups');
        $prop->setAccessible(true);
        $groups = $prop->getValue($kernel);

        $this->assertArrayHasKey('web', $groups);
        $this->assertContains(
            ContentSecurityPolicyHeader::class,
            $groups['web'],
            'ContentSecurityPolicyHeader must be wired into the web middleware group.'
        );
    }

    public function test_real_web_response_carries_csp_header(): void
    {
        // Integration check — equivalent to `curl -I http://localhost:8000/login`.
        // Drives the Router pipeline (web group) so we exercise the middleware as wired
        // in app/Http/Kernel.php, not just the class in isolation.
        \Illuminate\Support\Facades\Route::middleware('web')
            ->get('/__csp_probe', fn () => response('ok'));

        config()->set('security.csp.mode', 'report_only');

        $response = $this->get('/__csp_probe');

        // CSP middleware runs on the way out regardless of response status — header MUST be set.
        $this->assertNotEmpty(
            $response->headers->get('Content-Security-Policy-Report-Only'),
            'Web responses must carry the CSP header in report_only mode.'
        );
    }
}
