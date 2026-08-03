<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

/**
 * [GOAL-L2.2 sentinel — Phase L Agent L2.2, 2026-05-24]
 *
 * CI guardrail for production deploy templates:
 *   - scripts/deploy/deploy.sh
 *   - scripts/deploy/nginx.conf.template
 *   - scripts/deploy/supervisor.conf.template
 *
 * ## Why this sentinel exists
 *
 * Phase L Agent L2.2 audit reviewed the production deploy templates and
 * confirmed they implement the security + reliability contract laid out
 * across Phase H.3 (queue worker flags), Phase I.3 (OPcache reset), and
 * the H-SEC-001 / nginx-deny-sensitive-files hardening lineage. The
 * templates are operator-facing — a future drift (someone removes a
 * header, loosens HSTS, drops `--max-jobs=1000`) would silently break
 * the production posture without any runtime test catching it. This
 * sentinel pins the invariants statically so any refactor that
 * regresses them is a single red CI signal.
 *
 * ## What this sentinel asserts (6 invariants)
 *
 *   1. nginx HSTS: `Strict-Transport-Security` header with `max-age >= 1y`
 *      AND `includeSubDomains`.
 *   2. nginx CSP: `Content-Security-Policy` header is declared (presence
 *      check — owner tunes content post-first-load).
 *   3. nginx WS upgrade for soketi: `location /app/` block carries the
 *      `proxy_set_header Upgrade $http_upgrade;` + `Connection "upgrade";`
 *      pair so wss:// reverse-proxy works.
 *   4. supervisor `--max-jobs=1000` flag present on the queue worker
 *      command — graceful restart cap that frees leaked memory.
 *   5. deploy.sh wires the `fiscal:verify-chain` gate AND requires the
 *      `SWEEP COMPLETE.*CHAIN OK` success string before declaring deploy
 *      OK. Both signals are checked because a future refactor that drops
 *      the grep-belt would let a tampered chain pass silently.
 *   6. deploy.sh issues an OPcache flush (php-fpm reload) AFTER nginx
 *      reload — addresses Phase I.3 RISK-02 (operator turns
 *      validate_timestamps=0 for perf, then new code is never reloaded).
 *
 * ## What this sentinel does NOT assert
 *
 *   - Does NOT exec deploy.sh — root-privileged production-only script,
 *     cannot run in CI. All checks are static string + regex against the
 *     template file contents.
 *   - Does NOT assert CSP body content (script-src hosts, etc.) — the
 *     template header documents that owner tunes CSP via DevTools after
 *     first load; pinning the host list here would create churn.
 *   - Does NOT assert TLS cipher specifics — that's certbot-territory
 *     and changes per Mozilla profile rev.
 *   - Does NOT verify the soketi.json or nginx-deny-sensitive-files.conf
 *     snippet — those are covered by Phase B.3 / H-SEC-001 sibling
 *     sentinels and the operator-side deploy checklist.
 *
 * ## Resolution note
 *
 * Task spec referenced "H.11 audit" for the queue worker flag list. The
 * actual artifact is Phase H.3 (`H3-mixed-sustained-findings.json`
 * L213-219) which explicitly verified `supervisor.conf.template:42` is
 * already correct: `queue:work redis --queue=high,default --tries=3
 * --timeout=120 --max-jobs=1000 --sleep=1 --no-interaction`. Phase H.11
 * does not exist in `reports/test-e2e/goal-2026-05-23/phase-h/`. The
 * `--max-jobs=1000` assertion below is the one we lock.
 *
 * @group sentinel
 * @group deploy
 */
class DeployTemplatesHardeningSentinelTest extends TestCase
{
    /**
     * All 6 invariants folded into one test so a single red signal points
     * at "deploy templates regressed". Per-clause assertion messages
     * distinguish exactly which invariant tripped.
     */
    public function test_deploy_templates_keep_production_hardening_invariants(): void
    {
        $deployPath = base_path('scripts/deploy/deploy.sh');
        $nginxPath = base_path('scripts/deploy/nginx.conf.template');
        $supervisorPath = base_path('scripts/deploy/supervisor.conf.template');

        $this->assertFileExists($deployPath, 'scripts/deploy/deploy.sh missing — repo skeleton broken');
        $this->assertFileExists($nginxPath, 'scripts/deploy/nginx.conf.template missing — repo skeleton broken');
        $this->assertFileExists($supervisorPath, 'scripts/deploy/supervisor.conf.template missing — repo skeleton broken');

        $deploy = (string) file_get_contents($deployPath);
        $nginx = (string) file_get_contents($nginxPath);
        $supervisor = (string) file_get_contents($supervisorPath);

        // -- Invariant 1: HSTS with 1-year max-age + includeSubDomains ---
        // The header line spans add_header Strict-Transport-Security "..."
        // — extract its quoted value, then assert both the max-age numeric
        // floor AND the includeSubDomains directive.
        if (! preg_match(
            '/add_header\s+Strict-Transport-Security\s+"([^"]+)"/i',
            $nginx,
            $hstsMatch
        )) {
            $this->fail(
                'nginx.conf.template must declare an HSTS header — '
                . '`add_header Strict-Transport-Security "max-age=31536000; '
                . 'includeSubDomains" always;`. Phase L2.2 invariant.'
            );
        }
        $hstsValue = $hstsMatch[1];
        if (! preg_match('/max-age=(\d+)/i', $hstsValue, $ageMatch)) {
            $this->fail(
                "HSTS header is missing max-age=<seconds>: '{$hstsValue}'. "
                . 'Phase L2.2 invariant — max-age >= 31536000 (1 year).'
            );
        }
        $this->assertGreaterThanOrEqual(
            31_536_000,
            (int) $ageMatch[1],
            'HSTS max-age must be >= 31536000 (1 year). Lowering invites '
            . 'downgrade attacks on operators between deploys. Found: '
            . $ageMatch[1] . '. Phase L2.2 invariant.'
        );
        $this->assertMatchesRegularExpression(
            '/includeSubDomains/i',
            $hstsValue,
            'HSTS header must carry `includeSubDomains` so admin / kiosk '
            . 'subdomains inherit the policy. Phase L2.2 invariant.'
        );

        // -- Invariant 2: CSP header declared (content is owner-tuned) ---
        $this->assertMatchesRegularExpression(
            '/add_header\s+Content-Security-Policy\s+"/i',
            $nginx,
            'nginx.conf.template must declare a Content-Security-Policy '
            . 'header. The CSP content itself is owner-tuned post-first-'
            . 'load (DevTools console pass), but the directive must be '
            . 'present at deploy time. Phase L2.2 invariant.'
        );

        // -- Invariant 3: WS upgrade headers for soketi reverse-proxy ----
        // The /app/ location is the WebSocket lane (Pusher protocol
        // client subscribes there). Without the Upgrade + Connection
        // upgrade headers, nginx will not negotiate the wss:// handshake
        // and Echo silently falls back to long-poll / disconnects. The
        // /apps/ block deliberately stays HTTP (events API), so we
        // assert the pair ONLY in /app/.
        if (! preg_match(
            '#location\s+/app/\s*\{(.+?)\}#s',
            $nginx,
            $appBlockMatch
        )) {
            $this->fail(
                'nginx.conf.template must define `location /app/ { ... }` '
                . 'block for soketi WebSocket reverse-proxy. Phase L2.2 '
                . 'invariant.'
            );
        }
        $appBlock = $appBlockMatch[1];
        $this->assertMatchesRegularExpression(
            '/proxy_set_header\s+Upgrade\s+\$http_upgrade\s*;/i',
            $appBlock,
            '`location /app/` (soketi WS lane) must set `proxy_set_header '
            . 'Upgrade $http_upgrade;` — without it wss:// negotiation '
            . 'fails and Echo silently degrades. Phase L2.2 invariant.'
        );
        $this->assertMatchesRegularExpression(
            '/proxy_set_header\s+Connection\s+"upgrade"\s*;/i',
            $appBlock,
            '`location /app/` (soketi WS lane) must set `proxy_set_header '
            . 'Connection "upgrade";` paired with the Upgrade header. '
            . 'Phase L2.2 invariant.'
        );

        // -- Invariant 4: supervisor --max-jobs=1000 flag present --------
        // The flag forces graceful worker restart every 1000 jobs to
        // bound memory growth from broadcast payload buffers. Removing
        // it would let workers leak indefinitely under sustained load.
        $this->assertStringContainsString(
            '--max-jobs=1000',
            $supervisor,
            'supervisor.conf.template queue worker command MUST carry '
            . '`--max-jobs=1000` flag for graceful restart memory bounding. '
            . 'Phase H.3 audit (H3-mixed-sustained-findings.json) verified '
            . 'this is the production-validated value. Phase L2.2 invariant.'
        );

        // -- Invariant 5: deploy.sh fiscal chain gate --------------------
        // Two signals must both be present: the artisan call AND the
        // success-string grep. A refactor that drops the grep-belt would
        // let a non-zero exit ALSO let a tampered chain pass via
        // `|| true`. Lock both.
        $this->assertStringContainsString(
            'fiscal:verify-chain',
            $deploy,
            'deploy.sh must invoke `php artisan fiscal:verify-chain` '
            . 'before declaring deploy success — NF525 chain integrity '
            . 'gate. Phase L2.2 invariant.'
        );
        $this->assertMatchesRegularExpression(
            "/SWEEP COMPLETE.*CHAIN OK/",
            $deploy,
            'deploy.sh must require the success string '
            . '`SWEEP COMPLETE ... CHAIN OK` from fiscal:verify-chain '
            . 'output. Exit code alone is not sufficient — a future '
            . 'command refactor could exit 0 with chain tamper noted in '
            . 'stderr. Phase L2.2 invariant.'
        );

        // -- Invariant 6: deploy.sh OPcache reset (Phase I.3 RISK-02) ----
        // The deploy ships new .php files via `git reset --hard` +
        // `composer install`. Without a php-fpm reload (or opcache_reset
        // RPC), the bytecode cached in shared memory stays stale until
        // the next php-fpm restart. Phase I.3 flagged this as AMBER /
        // latent because the ondrej/php default validate_timestamps=1
        // makes it self-heal in 2s — but the moment an operator flips
        // validate_timestamps=0 for perf, new code never loads. Lock
        // the reload.
        $this->assertMatchesRegularExpression(
            '/(systemctl\s+reload\s+php(8\.\d+-)?fpm|opcache_reset\s*\()/i',
            $deploy,
            'deploy.sh must flush OPcache after composer install — either '
            . '`systemctl reload php8.4-fpm` (preferred — graceful) or an '
            . 'inline `opcache_reset()` RPC. Without it, new bytecode '
            . 'will not load when validate_timestamps=0 (Phase I.3 '
            . 'RISK-02). Phase L2.2 invariant.'
        );
    }
}
