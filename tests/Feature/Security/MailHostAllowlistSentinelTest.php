<?php

namespace Tests\Feature\Security;

use App\Http\Requests\MailRequest;
use App\Providers\AppServiceProvider;
use App\Rules\SafeRemoteHost;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * @FK-ID FK-MAIL-HOST-ALLOWLIST-SENTINEL
 * @source GOAL ULTRA-FINAL Phase L L7.2 (2026-05-24) — L7-2-F-02 P1
 * @see app/Rules/SafeRemoteHost.php
 * @see app/Http/Requests/MailRequest.php
 * @see app/Providers/AppServiceProvider.php (MAIL_HOST boot guard)
 * @see reports/test-e2e/goal-2026-05-23/phase-l/L7.2-ssrf-findings.json
 *
 * Three layers:
 *
 *   (a) Rule-unit — SafeRemoteHost rejects every IP literal in the L7.2
 *       blocklist and accepts public mail-host shapes. Pure-PHP, no Laravel
 *       boot required.
 *
 *   (b) MailRequest validation — the rule is wired into MailRequest::rules()
 *       at the mail_host key; 422-equivalent (rule->passes()=false) for
 *       dangerous IPs, true for public hostnames + IPs.
 *
 *   (c) Boot guard — AppServiceProvider::boot() refuses to boot in
 *       production when MAIL_HOST is set to a dangerous IP. Mirrors the
 *       PosSimulationHardwareProductionGuardSentinelTest pattern (direct
 *       provider construction with config flipping, no full app boot).
 *
 * Anti-régression: if anyone removes the rule wiring, removes the boot
 * guard, or weakens the SafeRemoteHost blocklist, the matching test fails
 * immediately.
 */
class MailHostAllowlistSentinelTest extends TestCase
{
    // -------------------------------------------------------------------
    // Layer (a) — SafeRemoteHost rule unit tests
    // -------------------------------------------------------------------

    /**
     * @dataProvider dangerousIpProvider
     */
    public function test_safe_remote_host_rule_rejects_dangerous_ip(string $ip, string $reason): void
    {
        $rule = new SafeRemoteHost();
        $this->assertFalse(
            $rule->passes('mail_host', $ip),
            "SafeRemoteHost rule MUST reject {$ip} ({$reason}) — L7-2-F-02 SSRF blocklist."
        );
        $this->assertNotSame('', $rule->message(), "Rule must set a non-empty error message for {$ip}.");
    }

    /**
     * @dataProvider publicHostProvider
     */
    public function test_safe_remote_host_rule_accepts_public_host(string $host, string $reason): void
    {
        $rule = new SafeRemoteHost();
        $this->assertTrue(
            $rule->passes('mail_host', $host),
            "SafeRemoteHost rule MUST accept {$host} ({$reason})."
        );
    }

    public function test_safe_remote_host_rule_accepts_empty_string_required_handles_it(): void
    {
        // The 'required' rule is responsible for empty rejection; SafeRemoteHost
        // is a SSRF-shape gate only and must not double-fire on empty input.
        $rule = new SafeRemoteHost();
        $this->assertTrue($rule->passes('mail_host', ''));
        $this->assertTrue($rule->passes('mail_host', '   '));
    }

    public function test_safe_remote_host_rule_passes_garbage_strings(): void
    {
        // Garbage strings (not IP literals, not hostnames) pass — Mail::send
        // will fail downstream. We do not brick on unparseable junk.
        $rule = new SafeRemoteHost();
        $this->assertTrue($rule->passes('mail_host', 'not a host at all'));
        $this->assertTrue($rule->passes('mail_host', '999.999.999.999'));
    }

    // -------------------------------------------------------------------
    // Layer (b) — MailRequest::rules() wiring
    // -------------------------------------------------------------------

    public function test_mail_request_rejects_internal_metadata_ip(): void
    {
        // The full Laravel Validator path proves the rule is actually wired
        // into MailRequest::rules() at the mail_host key.
        $payload = [
            'mail_host'       => '169.254.169.254', // AWS metadata service
            'mail_port'       => '80',
            'mail_username'   => 'attacker',
            'mail_password'   => 'attacker',
            'mail_encryption' => 'none',
            'mail_from_name'  => 'Attacker',
            'mail_from_email' => 'a@a.com',
        ];

        $rules = (new MailRequest())->rules();
        $validator = Validator::make($payload, $rules);

        $this->assertTrue(
            $validator->fails(),
            'MailRequest MUST reject mail_host=169.254.169.254 (cloud metadata service). '
            . 'SafeRemoteHost rule wiring lost?'
        );
        $this->assertArrayHasKey('mail_host', $validator->errors()->toArray());
    }

    public function test_mail_request_rejects_loopback_and_rfc1918(): void
    {
        $rules = (new MailRequest())->rules();
        $base = [
            'mail_port'       => '587',
            'mail_username'   => 'u',
            'mail_password'   => 'p',
            'mail_encryption' => 'tls',
            'mail_from_name'  => 'X',
            'mail_from_email' => 'x@x.com',
        ];

        foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '0.0.0.0'] as $ip) {
            $validator = Validator::make(['mail_host' => $ip] + $base, $rules);
            $this->assertTrue(
                $validator->fails(),
                "MailRequest MUST reject mail_host={$ip} (loopback/RFC1918)."
            );
        }
    }

    public function test_mail_request_accepts_public_mail_host(): void
    {
        $rules = (new MailRequest())->rules();
        $payload = [
            'mail_host'       => 'smtp.mailgun.org',
            'mail_port'       => '587',
            'mail_username'   => 'postmaster@mg.example.com',
            'mail_password'   => 'public-host-test',
            'mail_encryption' => 'tls',
            'mail_from_name'  => 'Le Cayenne',
            'mail_from_email' => 'noreply@example.com',
        ];

        $validator = Validator::make($payload, $rules);

        $this->assertFalse(
            $validator->fails(),
            'MailRequest MUST accept smtp.mailgun.org. Errors: '
            . json_encode($validator->errors()->toArray())
        );
    }

    // -------------------------------------------------------------------
    // Layer (c) — AppServiceProvider boot guard
    // -------------------------------------------------------------------

    public function test_production_boot_refuses_dangerous_mail_host(): void
    {
        $this->primeProductionGood();
        config(['mail.mailers.smtp.host' => '169.254.169.254']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MAIL_HOST is in a forbidden IP range/');

        $provider = new AppServiceProvider($this->app);
        $provider->boot();
    }

    public function test_production_boot_passes_with_public_mail_host(): void
    {
        $this->primeProductionGood();
        config(['mail.mailers.smtp.host' => 'smtp.mailgun.org']);

        $provider = new AppServiceProvider($this->app);
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'MAIL_HOST',
                $e->getMessage(),
                'Boot guard wrongly fired for public mail host smtp.mailgun.org. Actual: ' . $e->getMessage()
            );
        }
    }

    public function test_production_boot_passes_when_mail_host_unset(): void
    {
        // Empty / null MAIL_HOST must NOT brick boot — that is a config
        // completeness concern outside the SSRF threat model. A fresh
        // install with mail not yet configured must still boot so the
        // operator can reach the admin UI to configure mail.
        $this->primeProductionGood();
        config(['mail.mailers.smtp.host' => null]);

        $provider = new AppServiceProvider($this->app);
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'MAIL_HOST',
                $e->getMessage(),
                'Boot guard wrongly fired for null MAIL_HOST. Actual: ' . $e->getMessage()
            );
        }
    }

    public function test_local_env_relaxes_mail_host_boot_guard(): void
    {
        $this->app['env'] = 'local';
        config(['mail.mailers.smtp.host' => '169.254.169.254']);

        $provider = new AppServiceProvider($this->app);
        try {
            $provider->boot();
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(
                'MAIL_HOST',
                $e->getMessage(),
                'Mail host boot guard MUST NOT fire in local env even with dangerous value.'
            );
        }
    }

    // -------------------------------------------------------------------
    // Providers
    // -------------------------------------------------------------------

    public static function dangerousIpProvider(): array
    {
        return [
            'aws-metadata'        => ['169.254.169.254', 'AWS/GCE/Azure cloud metadata service'],
            'loopback-v4'         => ['127.0.0.1', 'IPv4 loopback'],
            'loopback-v4-edge'    => ['127.255.255.255', 'IPv4 loopback broadcast'],
            'unspecified'         => ['0.0.0.0', 'unspecified / this-network'],
            'rfc1918-10'          => ['10.1.2.3', 'RFC1918 10.0.0.0/8'],
            'rfc1918-172'         => ['172.16.5.10', 'RFC1918 172.16.0.0/12'],
            'rfc1918-192'         => ['192.168.1.1', 'RFC1918 192.168.0.0/16'],
            'cgnat'               => ['100.64.0.1', 'carrier-grade NAT'],
            'multicast'           => ['224.0.0.1', 'multicast'],
            'reserved'            => ['240.0.0.1', 'reserved future use'],
            'loopback-v6'         => ['::1', 'IPv6 loopback'],
            'loopback-v6-bracket' => ['[::1]', 'IPv6 loopback in brackets'],
            'link-local-v6'       => ['fe80::1', 'IPv6 link-local'],
            'unique-local-v6'     => ['fc00::1', 'IPv6 unique-local fc00::/7'],
            'unique-local-v6-fd'  => ['fd00::1', 'IPv6 unique-local fd00 variant'],
            'unspecified-v6'      => ['::', 'IPv6 unspecified'],
        ];
    }

    public static function publicHostProvider(): array
    {
        return [
            'mailgun'      => ['smtp.mailgun.org', 'public Mailgun MX hostname'],
            'sendgrid'     => ['smtp.sendgrid.net', 'public SendGrid MX hostname'],
            'gmail'        => ['smtp.gmail.com', 'public Gmail SMTP'],
            'office365'    => ['smtp.office365.com', 'public Microsoft 365 SMTP'],
            'public-ipv4'  => ['8.8.8.8', 'public IPv4 — out of all forbidden ranges'],
            'public-ipv6'  => ['2606:4700:4700::1111', 'Cloudflare public IPv6'],
        ];
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Neutralise every prior production boot guard so the test can isolate
     * the MAIL_HOST guard. Mirrors the pattern in
     * ProductionBootGuardsCompletenessSentinelTest::primeProductionGood.
     */
    private function primeProductionGood(): void
    {
        $this->app['env'] = 'production';

        config([
            'pos.simulation_hardware'  => false,
            'payment.bypass.enabled'   => false,
            'printing.bypass.enabled'  => false,
            'app.debug'                => false,
            'idempotency.enabled'      => true,
            'loyalty.qr.secret'        => str_repeat('a', 32),
            'app.url'                  => 'https://lecayenne.example.com',
            'broadcasting.default'     => 'pusher',
            'queue.default'            => 'redis',
            'cache.default'            => 'redis',
            // Default to safe mail host so other tests in this class don't
            // trip the guard. Tests that exercise the guard explicitly
            // override this.
            'mail.mailers.smtp.host'   => 'smtp.mailgun.org',
            // [TEST-E2E-HEAL 2026-07-15] PIN Carnet valide pour que le guard DAILY_BOOK_PIN
            // (défaut 2468 → refuse boot prod) ne préempte pas le guard MAIL_HOST sous test.
            'daily_book.pin'           => '9137',
        ]);
    }
}
