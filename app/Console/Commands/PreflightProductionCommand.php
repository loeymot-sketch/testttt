<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * [W9-AUDIT PROD-4] Pre-deployment configuration gate.
 *
 * Run this command BEFORE switching the release symlink in production:
 *
 *     APP_ENV=production php artisan app:preflight-production
 *
 * Exit code 0 = all checks passed, safe to flip the symlink.
 * Exit code 1 = at least one CRITICAL check failed, DO NOT deploy.
 *
 * Checks are grouped by severity:
 *  - CRITICAL: would corrupt fiscal evidence, leak tenant data, or take the
 *    app offline. Blocks deployment.
 *  - WARNING: degraded operation but not data-corrupting. Logged, does not
 *    block.
 *
 * The checks intentionally duplicate the AppServiceProvider boot guards so
 * ops can run them WITHOUT booting the full HTTP stack (cron jobs, deploy
 * scripts, healthcheck wrappers).
 */
class PreflightProductionCommand extends Command
{
    protected $signature = 'app:preflight-production
                            {--strict : Treat warnings as errors (CI/CD gate mode)}';

    protected $description = 'Verify the runtime configuration is production-grade (NF525, multi-tenant, observability).';

    /** @var array<int, array{level: string, key: string, msg: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('=== FoodKing production preflight ===');
        $this->line('');

        $this->checkAppEnv();
        $this->checkAppDebug();
        $this->checkAppKey();
        $this->checkTimezone();
        $this->checkCacheDriver();
        $this->checkQueueConnection();
        $this->checkBroadcastDriver();
        $this->checkSessionDriver();
        $this->checkLogLevel();
        $this->checkLogChannel();
        $this->checkOpsCommandAvailability();
        $this->checkFiscalSecrets();
        $this->checkFiscalVerifyChain();
        $this->checkDatabaseReachable();
        $this->checkCacheReachable();

        return $this->report();
    }

    private function checkAppEnv(): void
    {
        $env = config('app.env');
        if ($env !== 'production') {
            $this->addFinding('WARNING', 'APP_ENV', "APP_ENV='{$env}' (expected 'production'). Continuing in --strict will fail.");
        } else {
            $this->ok('APP_ENV', 'production');
        }
    }

    private function checkAppDebug(): void
    {
        if (config('app.debug')) {
            $this->addFinding('CRITICAL', 'APP_DEBUG', 'APP_DEBUG=true exposes stack traces and PII in error pages.');
        } else {
            $this->ok('APP_DEBUG', 'false');
        }
    }

    private function checkAppKey(): void
    {
        $key = config('app.key');
        if (empty($key) || str_starts_with((string) $key, 'base64:') === false && strlen((string) $key) < 32) {
            $this->addFinding('CRITICAL', 'APP_KEY', 'APP_KEY is missing or too short. Run `php artisan key:generate`.');
        } else {
            $this->ok('APP_KEY', 'set');
        }
    }

    private function checkTimezone(): void
    {
        $tz = config('app.timezone');
        if ($tz === 'UTC') {
            $this->addFinding('WARNING', 'TIMEZONE', "TIMEZONE='UTC' shifts NF525 J-1 archive boundaries. Set TIMEZONE=Europe/Paris for FR.");
        } else {
            $this->ok('TIMEZONE', $tz);
        }
    }

    private function checkCacheDriver(): void
    {
        $driver = config('cache.default');
        if (in_array($driver, ['array', 'null'], true)) {
            $this->addFinding('CRITICAL', 'CACHE_DRIVER', "CACHE_DRIVER='{$driver}' breaks NF525 audit chain locks across workers. Use redis or memcached.");
        } else {
            $this->ok('CACHE_DRIVER', $driver);
        }
    }

    private function checkQueueConnection(): void
    {
        $driver = config('queue.default');
        if ($driver === 'sync') {
            $this->addFinding('CRITICAL', 'QUEUE_CONNECTION', "QUEUE_CONNECTION='sync' executes jobs in-request (latency, blocking). Use redis or database.");
        } else {
            $this->ok('QUEUE_CONNECTION', $driver);
        }
    }

    private function checkBroadcastDriver(): void
    {
        $driver = config('broadcasting.default');
        if (in_array($driver, [null, 'null'], true)) {
            $this->addFinding('CRITICAL', 'BROADCAST_DRIVER', "BROADCAST_DRIVER='{$driver}' disables KDS/OSS realtime. Use pusher or redis.");
        } else {
            $this->ok('BROADCAST_DRIVER', $driver);
        }
    }

    private function checkSessionDriver(): void
    {
        $driver = config('session.driver');
        if (in_array($driver, ['array'], true)) {
            $this->addFinding('CRITICAL', 'SESSION_DRIVER', "SESSION_DRIVER='array' loses sessions on every request. Use redis, database, or file.");
        } else {
            $this->ok('SESSION_DRIVER', $driver);
        }
    }

    private function checkLogLevel(): void
    {
        $level = $this->configuredLogLevel();
        if (in_array($level, ['debug', 'info'], true)) {
            $this->addFinding('WARNING', 'LOG_LEVEL', "LOG_LEVEL='{$level}' may emit PII at scale. Use 'warning' or 'notice' in prod.");
        } else {
            $this->ok('LOG_LEVEL', $level);
        }
    }

    private function configuredLogLevel(): string
    {
        $channel = (string) config('logging.default', 'stack');
        $channels = (array) config('logging.channels', []);
        $channelConfig = $channels[$channel] ?? [];

        if (($channelConfig['driver'] ?? null) === 'stack') {
            foreach ((array) ($channelConfig['channels'] ?? []) as $stackedChannel) {
                $level = $channels[$stackedChannel]['level'] ?? null;
                if (is_string($level) && $level !== '') {
                    return $level;
                }
            }
        }

        $level = $channelConfig['level'] ?? null;

        return is_string($level) && $level !== '' ? $level : 'debug';
    }

    private function checkLogChannel(): void
    {
        $channel = config('logging.default');
        if ($channel === 'single' || $channel === 'daily') {
            $this->addFinding('WARNING', 'LOG_CHANNEL', "LOG_CHANNEL='{$channel}' uses unstructured text logs. Use 'production_json' for SIEM ingestion.");
        } else {
            $this->ok('LOG_CHANNEL', $channel);
        }
    }

    private function checkOpsCommandAvailability(): void
    {
        $commands = array_keys(Artisan::all());
        $required = [
            'foodking:outbox:rescue' => 'outbox rescue',
            'foodking:outbox:retry-failed' => 'outbox retry failed',
            'foodking:fiscal:archive' => 'NF525 fiscal archive',
            'queue:work' => 'queue worker',
            'schedule:list' => 'scheduler visibility',
        ];

        foreach ($required as $signature => $label) {
            if (! in_array($signature, $commands, true)) {
                $this->addFinding('CRITICAL', strtoupper(str_replace([':', '-'], '_', $signature)), "Missing {$label} command: {$signature}.");
                continue;
            }

            $this->ok($label . ' command', $signature);
        }

        if (! file_exists(config_path('horizon.php'))) {
            $this->addFinding('WARNING', 'HORIZON_CONFIG', 'config/horizon.php missing; use supervised queue:work or add Horizon config before scale-out.');
        } else {
            $this->ok('Horizon config', 'present');
        }
    }

    private function checkFiscalSecrets(): void
    {
        $audit = (string) config('fiscal.audit_secret', '');
        $zsec = (string) config('fiscal.z_report_secret', '');
        $minLen = (int) config('fiscal.min_secret_length', 32);

        if (strlen($audit) < $minLen) {
            $this->addFinding('CRITICAL', 'FISCAL_AUDIT_SECRET', "FISCAL_AUDIT_SECRET is shorter than {$minLen} chars (NF525 evidence integrity).");
        } else {
            $this->ok('FISCAL_AUDIT_SECRET', strlen($audit) . ' chars');
        }

        if (strlen($zsec) < $minLen) {
            $this->addFinding('CRITICAL', 'FISCAL_Z_REPORT_SECRET', "FISCAL_Z_REPORT_SECRET is shorter than {$minLen} chars (NF525 Z signature integrity).");
        } else {
            $this->ok('FISCAL_Z_REPORT_SECRET', strlen($zsec) . ' chars');
        }
    }

    private function checkFiscalVerifyChain(): void
    {
        $verify = (bool) config('fiscal.verify_chain_before_archive', true);
        if (! $verify) {
            $this->addFinding('WARNING', 'FISCAL_VERIFY_CHAIN_BEFORE_ARCHIVE', 'verify_chain_before_archive=false ships archives without integrity check.');
        } else {
            $this->ok('FISCAL_VERIFY_CHAIN_BEFORE_ARCHIVE', 'true');
        }
    }

    private function checkDatabaseReachable(): void
    {
        try {
            DB::connection()->getPdo();
            $this->ok('DB connection', 'reachable (' . DB::connection()->getDriverName() . ')');
        } catch (\Throwable $e) {
            $this->addFinding('CRITICAL', 'DB', 'Database not reachable: ' . $e->getMessage());
        }
    }

    private function checkCacheReachable(): void
    {
        try {
            $key = '__preflight_' . bin2hex(random_bytes(4));
            Cache::put($key, '1', 5);
            $got = Cache::get($key);
            Cache::forget($key);
            if ($got !== '1') {
                $this->addFinding('CRITICAL', 'CACHE', 'Cache write/read round-trip failed (driver: ' . config('cache.default') . ').');
            } else {
                $this->ok('Cache round-trip', 'OK');
            }
        } catch (\Throwable $e) {
            $this->addFinding('CRITICAL', 'CACHE', 'Cache not reachable: ' . $e->getMessage());
        }
    }

    private function ok(string $key, string $value): void
    {
        $this->line("  <fg=green>OK</>     {$key}: {$value}");
    }

    private function addFinding(string $level, string $key, string $msg): void
    {
        $this->findings[] = ['level' => $level, 'key' => $key, 'msg' => $msg];
        $color = $level === 'CRITICAL' ? 'red' : 'yellow';
        $label = str_pad($level, 6, ' ', STR_PAD_RIGHT);
        $this->line("  <fg={$color}>{$label}</> {$key}: {$msg}");
    }

    private function report(): int
    {
        $criticals = array_filter($this->findings, fn ($f) => $f['level'] === 'CRITICAL');
        $warnings  = array_filter($this->findings, fn ($f) => $f['level'] === 'WARNING');

        $this->line('');
        $this->line('=== Summary ===');
        $this->line('  Critical: ' . count($criticals));
        $this->line('  Warning : ' . count($warnings));
        $this->line('');

        if (count($criticals) > 0) {
            $this->error('Preflight FAILED. Fix CRITICAL findings before deploying.');
            return self::FAILURE;
        }

        if ($this->option('strict') && count($warnings) > 0) {
            $this->error('Preflight FAILED in strict mode (warnings are errors).');
            return self::FAILURE;
        }

        $this->info('Preflight PASSED. Safe to deploy.');
        return self::SUCCESS;
    }
}
