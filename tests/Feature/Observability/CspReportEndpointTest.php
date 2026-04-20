<?php

namespace Tests\Feature\Observability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * K-9 ADR-5 — Endpoint CSP report.
 *
 * `POST /api/frontend/csp-report` doit :
 *  - accepter le payload standard navigateur (clé racine `csp-report`) ;
 *  - renvoyer 204 No Content (pas de body exploitable par le navigateur) ;
 *  - logger sur canal `observability` catégorie `csp_violation` ;
 *  - masquer les query params sensibles dans les URLs loggées ;
 *  - rester accessible sans authentification (CSP natif du navigateur
 *    envoie sans bearer token), mais throttled 20/min/IP.
 */
class CspReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0:string,1:?string,2:array}> */
    private array $loggedEntries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->loggedEntries = [];

        // Swap le canal `observability` par un logger anonyme qui capture
        // les appels sans influencer les autres canaux (laravel, security,
        // stack, etc.) pour garder l'exception handler fonctionnel.
        $capture = new class($this->loggedEntries) {
            private array $buf;
            public function __construct(array &$buf) { $this->buf = &$buf; }
            public function info(string $msg, array $ctx = []): void { $this->buf[] = ['info', $msg, $ctx]; }
            public function warning(string $msg, array $ctx = []): void { $this->buf[] = ['warning', $msg, $ctx]; }
            public function error(string $msg, array $ctx = []): void { $this->buf[] = ['error', $msg, $ctx]; }
            public function alert(string $msg, array $ctx = []): void { $this->buf[] = ['alert', $msg, $ctx]; }
            public function log(string $level, string $msg, array $ctx = []): void { $this->buf[] = [$level, $msg, $ctx]; }
            public function __call(string $m, array $args): void { $this->buf[] = [$m, (string) ($args[0] ?? ''), (array) ($args[1] ?? [])]; }
        };

        $manager = app('log');
        // Force la création du driver observability avec notre capteur.
        $manager->extend('daily', function ($app, $cfg) use ($capture, $manager) {
            // Fallback rare : garde le driver daily standard pour les autres canaux.
            return $capture;
        });
        // Approche plus directe : shared instance mocké via Log::channel()
        Log::swap(new class($manager, $capture) {
            private $realManager;
            private $capture;
            public function __construct($m, $c) { $this->realManager = $m; $this->capture = $c; }
            public function channel(?string $name = null) {
                if ($name === 'observability') return $this->capture;
                return $this->realManager->channel($name);
            }
            public function stack(array $c, ?string $n = null) { return $this->realManager->stack($c, $n); }
            public function driver(?string $n = null) { return $this->realManager->driver($n); }
            public function __call($m, $args) { return $this->realManager->{$m}(...$args); }
        });
    }

    public function test_valid_csp_report_is_accepted_and_logged_to_observability_channel(): void
    {
        $payload = ['csp-report' => [
            'document-uri'        => 'https://kiosk.foodking.local/kiosk/wizard',
            'referrer'            => '',
            'violated-directive'  => 'script-src',
            'effective-directive' => 'script-src',
            'original-policy'     => "default-src 'self'",
            'blocked-uri'         => 'https://evil.example/inject.js',
            'status-code'         => 0,
            'source-file'         => 'https://kiosk.foodking.local/js/app.js',
            'line-number'         => 42,
            'column-number'       => 13,
        ]];

        $this->postJson('/api/frontend/csp-report', $payload)
            ->assertStatus(204);

        $infoLogs = array_values(array_filter($this->loggedEntries, fn ($l) => $l[0] === 'info'));
        $this->assertNotEmpty($infoLogs, 'Attendu au moins un log info sur canal observability.');
        $this->assertSame('csp_violation', $infoLogs[0][1]);
        $this->assertSame('csp_violation', $infoLogs[0][2]['category']);
        $this->assertSame('script-src', $infoLogs[0][2]['violated_directive']);
        $this->assertSame('https://evil.example/inject.js', $infoLogs[0][2]['blocked_uri']);
    }

    public function test_malformed_payload_is_still_accepted_with_warning_log(): void
    {
        $this->postJson('/api/frontend/csp-report', ['not-a-csp-report' => 'garbage'])
            ->assertStatus(204);

        $warnings = array_values(array_filter($this->loggedEntries, fn ($l) => $l[0] === 'warning'));
        $this->assertNotEmpty($warnings);
        $this->assertSame('csp_violation.malformed', $warnings[0][1]);
        $this->assertSame('invalid', $warnings[0][2]['shape']);
    }

    public function test_document_uri_with_sensitive_query_params_is_redacted(): void
    {
        $payload = ['csp-report' => [
            'document-uri'        => 'https://kiosk/test?token=secret-abc&utm_source=slack',
            'violated-directive'  => 'img-src',
            'blocked-uri'         => 'https://tracker.example/pixel.gif',
        ]];

        $this->postJson('/api/frontend/csp-report', $payload)
            ->assertStatus(204);

        $infoLogs = array_values(array_filter($this->loggedEntries, fn ($l) => $l[0] === 'info'));
        $this->assertNotEmpty($infoLogs);
        $docUri = $infoLogs[0][2]['document_uri'];
        $this->assertStringContainsString('REDACTED', urldecode((string) $docUri),
            'Le query param `token` doit être masqué dans document_uri loggé.');
        $this->assertStringContainsString('utm_source=slack', (string) $docUri,
            'Les query params non-sensibles doivent être conservés.');
    }

    public function test_route_is_anonymous_no_auth_required(): void
    {
        $this->postJson('/api/frontend/csp-report', ['csp-report' => [
            'document-uri' => 'https://k', 'violated-directive' => 'x', 'blocked-uri' => '',
        ]])->assertStatus(204);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
